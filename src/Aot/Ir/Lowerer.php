<?php
declare(strict_types=1);
namespace PHPJava\Aot\Ir;

/**
 * Lower an IR Module to PHP source. Emits the exact shape the
 * existing string-based compiler produces, so the IR can plug in as
 * a drop-in substitute and the contract gate passes byte-identical.
 *
 * Production architecture: Compiler::compileMethod walks bytecode
 * and *builds an IR Module*; optimisation passes mutate the Module;
 * Lowerer renders to PHP. The current string-based emitter becomes
 * a transitional fallback for opcodes the IR builder hasn't covered.
 */
final class Lowerer
{
    public function lowerModule(Module $m): string
    {
        $body = '';
        $first = true;
        foreach ($m->methods as $method) {
            if (!$first) $body .= "\n\n";
            $body .= $this->lowerMethod($method);
            $first = false;
        }
        $out = "<?php\nnamespace {$m->namespace};\n\nfinal class {$m->className}\n{\n{$body}\n}\n";
        foreach ($m->lambdaClasses as $lc) {
            $out .= "\n" . $lc['php'];
        }
        return $out;
    }

    public function lowerMethod(Method $method): string
    {
        // Per cljp's by-ref auto-detect: prepend `&` for parameters that
        // are mutated in place (e.g. iastore on the param's array). PHP
        // arrays are value-typed; without `&` the mutation silently
        // forks from the caller's array.
        $byref = \array_flip($method->byrefParamIndices);
        $renderedParams = [];
        foreach ($method->params as $i => $p) {
            $renderedParams[] = isset($byref[$i]) ? '&' . $p : $p;
        }
        $paramStr = implode(', ', $renderedParams);
        $sig = $method->isStatic
            ? "public static function {$method->name}({$paramStr})"
            : "public function {$method->name}({$paramStr})";

        // Prelude: $L initialised with all method-entry locals. JVM
        // long and double take 2 slots each (the second is unaddressable
        // continuation); other types take 1. Param-to-slot mapping
        // accounts for this — without it, `longAdd(JJ)J` ends up with
        // both args at slots [0, 1] but the bytecode reads from [0, 2].
        $slotToArg = self::slotToArgMap($method);
        $initVals = [];
        for ($i = 0; $i < $method->maxLocals; $i++) {
            if (!$method->isStatic && $i === 0) {
                $initVals[] = '$this';
            } elseif (isset($slotToArg[$i])) {
                // For byref params, alias `$L[slot]` to the original
                // `&$__aN` rather than value-copy. Without `&`, the
                // array literal `[$__a0, ...]` value-copies and the
                // signature's `&` is lost — mutations go to the local
                // $L[slot] only.
                $argIdx = $slotToArg[$i];
                $isByref = isset($byref[$argIdx]);
                $initVals[] = ($isByref ? '&' : '') . $method->params[$argIdx];
            } else {
                $initVals[] = '0';
            }
        }
        $localList = $method->maxLocals > 0 ? '[' . implode(', ', $initVals) . ']' : '[]';
        $prelude = "\$L = {$localList};\n        \$stack = []; \$sp = 0;";

        // Block layout. Two elision passes for JIT-friendly output
        // (rank-1 verified earlier: redundant gotos + dead labels
        // confuse the trace planner; spike was 2 ns/op without these
        // elisions, ~0.2 ns/op with).
        //
        //   1. Live-label set: a label is emitted only if some
        //      terminator targets it. Fall-through targets are dead.
        //   2. Redundant goto: if a Goto_'s target is the immediately
        //      next block in iteration order, skip emitting the goto
        //      (fall-through is implicit).
        $blocks = $method->blocks;
        ksort($blocks);
        $orderedPcs = array_keys($blocks);

        // Collect live targets: every terminator's referenced PCs,
        // plus any handler PC referenced by a tryProtect entry on
        // any block (catch dispatchers goto these).
        $liveLabels = [];
        foreach ($blocks as $bb) {
            if ($bb->term instanceof Goto_) {
                $liveLabels[$bb->term->targetPc] = true;
            } elseif ($bb->term instanceof CondGoto) {
                $liveLabels[$bb->term->thenPc] = true;
                // Fall-through PC (elsePc) is NOT a live label.
            } elseif ($bb->term instanceof Switch_) {
                $liveLabels[$bb->term->defaultPc] = true;
                foreach ($bb->term->cases as $tgt) {
                    $liveLabels[$tgt] = true;
                }
            }
            foreach ($bb->tryProtect as $entry) {
                $liveLabels[$entry['handlerPc']] = true;
            }
        }

        $bodyLines = [$prelude];
        foreach ($orderedPcs as $i => $pc) {
            $bb = $blocks[$pc];
            // Emit the label if it's a live branch target OR if this
            // is the entry block (PC 0). The entry-block label is
            // architecturally dead but anchors the JIT trace; per the
            // 2026-05-02 negative-results finding, removing it caused
            // 5-6× cold-start regression. Restoring keeps parity with
            // the string-path peephole's JIT-friendly emit shape.
            if (isset($liveLabels[$pc]) || $pc === 0) {
                $bodyLines[] = "L_{$pc}:";
            }
            // Open try-catch wrapper(s) covering this BB.
            if (!empty($bb->tryProtect)) {
                $bodyLines[] = "        try {";
            }
            foreach ($bb->stmts as $stmt) {
                $bodyLines[] = $this->lowerStmt($stmt);
            }
            if ($bb->term !== null) {
                $nextPc = $orderedPcs[$i + 1] ?? null;
                $skipGoto = $bb->term instanceof Goto_
                    && $bb->term->targetPc === $nextPc
                    && empty($bb->tryProtect);
                if (!$skipGoto) {
                    $termStr = $this->lowerTerminator($bb->term);
                    if ($termStr !== '') $bodyLines[] = $termStr;
                }
            }
            // Close try block + emit catch clauses.
            if (!empty($bb->tryProtect)) {
                foreach ($bb->tryProtect as $entry) {
                    $bodyLines[] = "        } catch ({$entry['classFqn']} \$__e) { \$sp = 0; \$stack[\$sp++] = \$__e; goto L_{$entry['handlerPc']}; }";
                }
            }
        }
        $bodyStr = implode("\n", $bodyLines);
        return "    // {$method->name} {$method->descriptor}\n    {$sig}\n    {\n{$bodyStr}\n    }";
    }

    /**
     * JVM slot-to-arg-index map for the prelude. Long and double take
     * 2 JVM slots each per JVM spec §2.6.1; other types take 1. The
     * unaddressable continuation slot stays at the default zero-fill
     * (the bytecode never reads it directly — `lload N` reads slots N
     * and N+1 as a single 64-bit value, but our IR Lowerer treats the
     * long as a single PHP int at slot N).
     */
    private static function slotToArgMap(Method $method): array
    {
        $thisSlot = $method->isStatic ? 0 : 1;
        $argTypes = self::parseArgTypes($method->descriptor);
        $map = [];
        $slot = $thisSlot;
        foreach ($argTypes as $argIdx => $type) {
            $map[$slot] = $argIdx;
            $slot += ($type === 'J' || $type === 'D') ? 2 : 1;
        }
        return $map;
    }

    /**
     * Extract the arg-type tags from a JVM method descriptor. Returns
     * an ordered list of single-char type codes (`B`, `C`, `D`, `F`,
     * `I`, `J`, `S`, `Z`, `L` for refs, `[` for arrays). Returns
     * 'L' for any reference type (the FQN is not needed here).
     */
    private static function parseArgTypes(string $descriptor): array
    {
        $types = [];
        $end = \strpos($descriptor, ')');
        $i = 1; // skip leading '('
        while ($i < $end) {
            $c = $descriptor[$i];
            if ($c === '[') {
                while ($descriptor[$i] === '[') $i++;
                if ($descriptor[$i] === 'L') {
                    $i = \strpos($descriptor, ';', $i) + 1;
                } else {
                    $i++;
                }
                $types[] = 'L'; // arrays are refs (1 slot)
            } elseif ($c === 'L') {
                $i = \strpos($descriptor, ';', $i) + 1;
                $types[] = 'L';
            } else {
                $types[] = $c;
                $i++;
            }
        }
        return $types;
    }

    public function lowerStmt(Stmt $s): string
    {
        if ($s instanceof StoreLocal) {
            return "        \$L[{$s->slot}] = " . $this->lowerExpr($s->value) . ";";
        }
        if ($s instanceof IincLocal) {
            return "        \$L[{$s->slot}] += {$s->delta};";
        }
        if ($s instanceof ExprStmt) {
            return "        " . $this->lowerExpr($s->expr) . ";";
        }
        if ($s instanceof StoreStaticField) {
            return "        {$s->classFqn}::\${$s->field} = " . $this->lowerExpr($s->value) . ";";
        }
        if ($s instanceof StoreField) {
            return "        " . $this->lowerExpr($s->receiver) . "->{$s->field} = " . $this->lowerExpr($s->value) . ";";
        }
        if ($s instanceof StoreArrayElement) {
            return "        \$L[{$s->slot}][" . $this->lowerExpr($s->index) . "] = " . $this->lowerExpr($s->value) . ";";
        }
        throw new \LogicException('Unhandled Stmt: ' . $s::class);
    }

    public function lowerTerminator(Terminator $t): string
    {
        if ($t instanceof Goto_) {
            return "        goto L_{$t->targetPc};";
        }
        if ($t instanceof CondGoto) {
            // The IR's CondGoto is "if cond goto thenPc else fall-through to elsePc".
            // The current emit only writes the conditional branch and lets
            // the next BB's label handle the else fall-through. We follow
            // that convention.
            return "        if (" . $this->lowerExpr($t->cond) . ") goto L_{$t->thenPc};";
        }
        if ($t instanceof Return_) {
            if ($t->value === null) return "        return;";
            return "        return " . $this->lowerExpr($t->value) . ";";
        }
        if ($t instanceof Throw_) {
            return "        throw " . $this->lowerExpr($t->value) . ";";
        }
        if ($t instanceof Switch_) {
            $key = $this->lowerExpr($t->key);
            $caseLines = '';
            foreach ($t->cases as $val => $tgt) {
                $caseLines .= "            case {$val}: goto L_{$tgt};\n";
            }
            return "        switch ({$key}) {\n{$caseLines}            default: goto L_{$t->defaultPc};\n        }";
        }
        throw new \LogicException('Unhandled Terminator: ' . $t::class);
    }

    public function lowerExpr(Expr $e): string
    {
        if ($e instanceof IntLit) {
            // PHP parses `-9223372036854775808` as `-(9223372036854775808)`;
            // the unsigned form overflows PHP_INT_MAX and the literal
            // becomes a float — `(string)PHP_INT_MIN` works in code
            // but the emitted *literal* doesn't round-trip. Emit
            // PHP_INT_MIN as `(PHP_INT_MIN)` to avoid the parser quirk.
            // PHP_INT_MAX (9223372036854775807) parses fine as int.
            if ($e->value === \PHP_INT_MIN) return '(\\PHP_INT_MIN)';
            return (string)$e->value;
        }
        if ($e instanceof FloatLit) {
            $v = $e->value;
            if (\is_nan($v)) return 'NAN';
            if ($v === \INF)  return 'INF';
            if ($v === -\INF) return '-INF';
            return (string) $v;
        }
        if ($e instanceof StringLit) return var_export($e->value, true);
        if ($e instanceof BoolLit) return $e->value ? 'true' : 'false';
        if ($e instanceof NullLit) return 'null';
        if ($e instanceof ArrayLit) {
            return '[' . implode(', ', array_map(
                fn($x) => $this->lowerExpr($x), $e->elements
            )) . ']';
        }
        if ($e instanceof LocalRead) return "\$L[{$e->slot}]";
        if ($e instanceof ParamRead) return "\$__a{$e->idx}";
        if ($e instanceof BinOp) {
            // Always parenthesise. Without explicit parens, nested BinOps
            // emit precedence-ambiguous PHP — e.g. lcmp+ifne lowers to
            // `$L[0] <=> $L[2] !== 0`, which is a parse error because
            // `<=>` is non-associative when chained against `!==`.
            // Redundant parens are harmless; they do not affect JIT
            // trace specialisation.
            return '(' . $this->lowerExpr($e->left) . " {$e->op} " . $this->lowerExpr($e->right) . ')';
        }
        if ($e instanceof UnaryOp) {
            return $e->op . $this->lowerExpr($e->operand);
        }
        if ($e instanceof StaticCall) {
            $args = array_map(fn($a) => $this->lowerExpr($a), $e->args);
            // Empty method name = function call (`\intdiv(...)`),
            // not a static-method call. Used for built-in PHP fns
            // emitted by the Builder for type conversions etc.
            if ($e->method === '') {
                return "{$e->classFqn}(" . implode(', ', $args) . ")";
            }
            // Cross-class AOT-emitted-class invokestatic: route through
            // Loader::callStatic with the exact JVM binary name. Skips
            // the autoloader's `_ → /` heuristic, which mis-resolves
            // inner classes (Outer$Inner → Outer/Inner).
            if ($e->binaryName !== null) {
                $binLit = "'" . addcslashes($e->binaryName, "'\\") . "'";
                $methodLit = "'" . addcslashes($e->method, "'\\") . "'";
                $argList = $args ? ', ' . implode(', ', $args) : '';
                return "\\PHPJava\\Aot\\Loader::callStatic({$binLit}, {$methodLit}{$argList})";
            }
            return "{$e->classFqn}::{$e->method}(" . implode(', ', $args) . ")";
        }
        if ($e instanceof StaticFieldRead) {
            return "{$e->classFqn}::\${$e->field}";
        }
        if ($e instanceof InstanceCall) {
            $args = array_map(fn($a) => $this->lowerExpr($a), $e->args);
            return $this->lowerExpr($e->receiver) . "->{$e->method}(" . implode(', ', $args) . ")";
        }
        if ($e instanceof New_) {
            $args = array_map(fn($a) => $this->lowerExpr($a), $e->args);
            // Cross-class AOT-emitted-class construction: route through
            // Loader::newInstance with the exact JVM binary name. Same
            // rationale as the StaticCall::$binaryName path — sidesteps
            // the autoloader's reverse-mangle ambiguity for inner classes.
            if ($e->binaryName !== null) {
                $binLit = "'" . addcslashes($e->binaryName, "'\\") . "'";
                $argList = $args ? ', ' . implode(', ', $args) : '';
                return "\\PHPJava\\Aot\\Loader::newInstance({$binLit}{$argList})";
            }
            return "new {$e->classFqn}(" . implode(', ', $args) . ")";
        }
        if ($e instanceof CaughtException) {
            return "\$__e";
        }
        if ($e instanceof FieldRead) {
            return $this->lowerExpr($e->receiver) . "->{$e->field}";
        }
        if ($e instanceof ArrayElementRead) {
            return "\$L[{$e->slot}][" . $this->lowerExpr($e->index) . "]";
        }
        if ($e instanceof ArrayLengthRead) {
            return "\\count(\$L[{$e->slot}])";
        }
        throw new \LogicException('Unhandled Expr: ' . $e::class);
    }
}

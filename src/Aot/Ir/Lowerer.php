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
        $paramStr = implode(', ', $method->params);
        $sig = $method->isStatic
            ? "public static function {$method->name}({$paramStr})"
            : "public function {$method->name}({$paramStr})";

        // Prelude: $L = [params followed by zeros up to maxLocals].
        $initVals = [];
        for ($i = 0; $i < $method->maxLocals; $i++) {
            $initVals[] = $i < count($method->params) ? $method->params[$i] : '0';
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
        if ($e instanceof IntLit) return (string)$e->value;
        if ($e instanceof FloatLit) return is_finite($e->value) ? (string)$e->value : 'NAN';
        if ($e instanceof StringLit) return var_export($e->value, true);
        if ($e instanceof NullLit) return 'null';
        if ($e instanceof ArrayLit) {
            return '[' . implode(', ', array_map(
                fn($x) => $this->lowerExpr($x), $e->elements
            )) . ']';
        }
        if ($e instanceof LocalRead) return "\$L[{$e->slot}]";
        if ($e instanceof ParamRead) return "\$__a{$e->idx}";
        if ($e instanceof BinOp) {
            return $this->lowerExpr($e->left) . " {$e->op} " . $this->lowerExpr($e->right);
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

<?php
declare(strict_types=1);
namespace PHPJava\Aot\Ir;

require_once __DIR__ . '/Node.php';

/**
 * Wrapper escape analysis — Phase 3 of the emit-then-prove-and-elide
 * String identity contract (CONTRACTS.md §1).
 *
 * Phase 2 emits a `String_Identity` wrapper at every
 * StringConcatFactory site and every `new String(...)` peephole, so
 * that concat results and explicit constructions carry distinct
 * per-instance identity matching Java's spec. The cost is a heap
 * allocation per emission site (rank 1 measured 2026-05-05: ~106
 * ns/op delta over raw concat under JIT-warm).
 *
 * This pass elides the allocation when the wrapper's *identity* is
 * provably never observed within the enclosing method. Java's
 * identity-observation surface is small:
 *
 *   - `System.identityHashCode(s)`
 *   - reference-equality `s == t` (lowered to PHP `===`/`!==`)
 *   - `s.intern()` (canonicalisation; behaviour differs per identity)
 *   - escape past method boundary (return / throw / store-to-field)
 *
 * Anything else — println, indexOf, length, equals, valueOf, concat
 * with another value, etc. — consumes the wrapper for its *value*.
 * In coercive mode (verified: AOT-emitted PHP omits
 * `declare(strict_types=1)`) a `Stringable` is auto-coerced to
 * `string` when passed to a `string` parameter, so the wrapper's
 * `String_Identity::__toString` returns the underlying value
 * transparently. Eliding the allocation in those positions
 * preserves correctness and restores Phase 1's perf shape.
 *
 * Algorithm:
 *
 *   Pass 1 — `collectEscapingSlots(method)`:
 *     Traverse each Stmt/Terminator marking JVM local slots whose
 *     value reaches an identity-observing position. Slot aliases
 *     (`StoreLocal($M, LocalRead($N))`) propagate via fixpoint.
 *
 *   Pass 2 — `rewriteMethod(method, escapingSlots)`:
 *     Re-traverse with a context-aware boolean flag — `observing`
 *     true at identity-observation positions, false at value-only
 *     positions. At a `New_(String_Identity, [inner], null)`:
 *       - if not observing → return `inner` (recursed).
 *       - if observing → keep the wrapper.
 *
 *   Wrapper allocations stored to non-escaping slots are also
 *   elided directly: `StoreLocal($N, New_(...))` becomes
 *   `StoreLocal($N, inner)` when slot N is not in the escaping
 *   set.
 *
 * Conservative-by-design: any unrecognised callee is treated as
 * identity-observing (keep the wrapper). The whitelist of known-
 * value-only shim targets covers the common bb-fill surface;
 * extend it as fixtures surface new patterns.
 *
 * Method-local. Inter-procedural escape (caller observes the
 * returned wrapper) is out of scope — the IR is method-shaped and
 * cross-method analysis would require a global pass over the
 * Module. The conservative branch (keep the wrapper at Return_)
 * preserves correctness for callers that DO observe.
 */
final class WrapperEscapePass
{
    private const WRAPPER_FQN = '\\PHPJava\\Aot\\Runtime\\java\\lang\\String_Identity';
    private const SYSTEM_FQN  = '\\PHPJava\\Aot\\Runtime\\java\\lang\\System';
    private const STRING_FQN  = '\\PHPJava\\Aot\\Runtime\\java\\lang\\String_';

    public function run(Module $m): Module
    {
        foreach ($m->methods as $method) {
            $escaping = $this->collectEscapingSlots($method);
            $this->rewriteMethod($method, $escaping);
        }
        return $m;
    }

    // ─── Pass 1: collect escaping slots ─────────────────────────────────────

    /**
     * Walk the method's IR, marking JVM local slots whose value
     * reaches an identity-observing position. Iterate to fixpoint
     * to handle slot aliases (`StoreLocal(M, LocalRead(N))`
     * propagates M's escape to N).
     *
     * @return array<int, true>  set of escaping slot indices
     */
    private function collectEscapingSlots(Method $method): array
    {
        $escaping = [];

        do {
            $changed = false;
            foreach ($method->blocks as $bb) {
                foreach ($bb->stmts as $s) {
                    $this->markStmt($s, $escaping, $changed);
                }
                if ($bb->term !== null) {
                    $this->markTerm($bb->term, $escaping, $changed);
                }
            }
        } while ($changed);

        return $escaping;
    }

    private function markStmt(Stmt $s, array &$escaping, bool &$changed): void
    {
        if ($s instanceof StoreLocal) {
            // Alias propagation: if the destination slot is escaping
            // and the source is a LocalRead, the source slot escapes
            // too — its value flows into an escaping slot.
            if (isset($escaping[$s->slot]) && $s->value instanceof LocalRead) {
                if (!isset($escaping[$s->value->slot])) {
                    $escaping[$s->value->slot] = true;
                    $changed = true;
                }
            }
            // Recurse into value with observing=false — only specific
            // sub-positions (call args, comparison operands) flag
            // observation.
            $this->markExpr($s->value, false, $escaping, $changed);
            return;
        }
        if ($s instanceof StoreField || $s instanceof StoreStaticField) {
            // Field stores escape: caller may observe via getfield.
            $value = $s instanceof StoreField ? $s->value : $s->value;
            $this->markExpr($value, true, $escaping, $changed);
            if ($s instanceof StoreField) {
                $this->markExpr($s->receiver, false, $escaping, $changed);
            }
            return;
        }
        if ($s instanceof StoreArrayElement) {
            // Array stores: the value escapes to the array (caller may
            // read it back via aaload). Conservative.
            $this->markExpr($s->index, false, $escaping, $changed);
            $this->markExpr($s->value, true, $escaping, $changed);
            return;
        }
        if ($s instanceof ExprStmt) {
            // Discarded — non-observing.
            $this->markExpr($s->expr, false, $escaping, $changed);
            return;
        }
        // IincLocal — int-only, unrelated.
    }

    private function markTerm(Terminator $t, array &$escaping, bool &$changed): void
    {
        if ($t instanceof Return_) {
            if ($t->value !== null) {
                $this->markExpr($t->value, true, $escaping, $changed);
            }
            return;
        }
        if ($t instanceof Throw_) {
            $this->markExpr($t->value, true, $escaping, $changed);
            return;
        }
        if ($t instanceof CondGoto) {
            $this->markExpr($t->cond, false, $escaping, $changed);
            return;
        }
        if ($t instanceof Switch_) {
            $this->markExpr($t->key, false, $escaping, $changed);
            return;
        }
    }

    /**
     * If `observing` is true, any LocalRead(N) encountered marks
     * slot N as escaping. Sub-expressions inherit the flag *unless*
     * the parent expression's structure changes the observation
     * status of a particular sub-position — handled in the
     * structural cases below.
     */
    private function markExpr(Expr $e, bool $observing, array &$escaping, bool &$changed): void
    {
        if ($e instanceof LocalRead) {
            if ($observing && !isset($escaping[$e->slot])) {
                $escaping[$e->slot] = true;
                $changed = true;
            }
            return;
        }
        if ($e instanceof BinOp) {
            // `===` and `!==` between String references = identity check.
            // `.` (string concat) = value operation.
            if ($e->op === '===' || $e->op === '!==') {
                $this->markExpr($e->left,  true, $escaping, $changed);
                $this->markExpr($e->right, true, $escaping, $changed);
            } else {
                // Concat / arithmetic / shifts / etc. — value ops,
                // never observe identity.
                $this->markExpr($e->left,  false, $escaping, $changed);
                $this->markExpr($e->right, false, $escaping, $changed);
            }
            return;
        }
        if ($e instanceof UnaryOp) {
            $this->markExpr($e->operand, false, $escaping, $changed);
            return;
        }
        if ($e instanceof StaticCall) {
            // Identity-observing static targets: System::identityHashCode,
            // String_::intern. Anything else with a known-safe FQN
            // is value-only; everything else stays conservative.
            $obs = $this->staticCallObservesIdentity($e);
            foreach ($e->args as $arg) {
                $this->markExpr($arg, $obs, $escaping, $changed);
            }
            return;
        }
        if ($e instanceof InstanceCall) {
            // The receiver is an opaque object — its identity isn't
            // observed by the call (the call invokes a method *on*
            // it, not against another reference). Args are subject
            // to per-method analysis; we whitelist known-safe (PrintStream
            // print/println, value-method receivers we lower
            // statically) and treat unknown as observing for safety.
            $argObs = !$this->instanceCallArgsAreValueOnly($e);
            $this->markExpr($e->receiver, false, $escaping, $changed);
            foreach ($e->args as $arg) {
                $this->markExpr($arg, $argObs, $escaping, $changed);
            }
            return;
        }
        if ($e instanceof InvokeCallable) {
            // Direct callable invocation — opaque body. Conservative:
            // args might be observed.
            $this->markExpr($e->callable, false, $escaping, $changed);
            foreach ($e->args as $arg) {
                $this->markExpr($arg, true, $escaping, $changed);
            }
            return;
        }
        if ($e instanceof New_) {
            // Constructor args: opaque body. Conservative.
            foreach ($e->args as $arg) {
                $this->markExpr($arg, true, $escaping, $changed);
            }
            return;
        }
        if ($e instanceof FieldRead) {
            $this->markExpr($e->receiver, false, $escaping, $changed);
            return;
        }
        if ($e instanceof ArrayLit) {
            foreach ($e->elements as $el) {
                $this->markExpr($el, true, $escaping, $changed);
            }
            return;
        }
        if ($e instanceof ArrayElementRead) {
            $this->markExpr($e->index, false, $escaping, $changed);
            return;
        }
        // Literals, ParamRead, CaughtException, StaticFieldRead,
        // ArrayLengthRead, NullLit — leaves with no nested Exprs to
        // observe.
    }

    // ─── Pass 2: rewrite wrapper allocations ────────────────────────────────

    private function rewriteMethod(Method $method, array $escaping): void
    {
        foreach ($method->blocks as $bb) {
            foreach ($bb->stmts as $i => $s) {
                $bb->stmts[$i] = $this->rewriteStmt($s, $escaping);
            }
            if ($bb->term !== null) {
                $bb->term = $this->rewriteTerm($bb->term, $escaping);
            }
        }
    }

    private function rewriteStmt(Stmt $s, array $escaping): Stmt
    {
        if ($s instanceof StoreLocal) {
            $newV = $this->rewriteExpr($s->value, !isset($escaping[$s->slot]) ? false : true, $escaping);
            // If the slot is non-escaping AND the value is a wrapper,
            // unwrap directly. Already handled by rewriteExpr's
            // observing=false branch, but clarify the StoreLocal case
            // explicitly: rewriteExpr above already passes
            // observing=false when slot is non-escaping, which causes
            // the wrapper detector inside rewriteExpr to elide.
            return $newV === $s->value ? $s : new StoreLocal($s->slot, $newV);
        }
        if ($s instanceof StoreField) {
            $newR = $this->rewriteExpr($s->receiver, false, $escaping);
            $newV = $this->rewriteExpr($s->value, true, $escaping);
            return ($newR === $s->receiver && $newV === $s->value) ? $s : new StoreField($newR, $s->field, $newV);
        }
        if ($s instanceof StoreStaticField) {
            $newV = $this->rewriteExpr($s->value, true, $escaping);
            return $newV === $s->value ? $s : new StoreStaticField($s->classFqn, $s->field, $newV);
        }
        if ($s instanceof StoreArrayElement) {
            $newI = $this->rewriteExpr($s->index, false, $escaping);
            $newV = $this->rewriteExpr($s->value, true, $escaping);
            return ($newI === $s->index && $newV === $s->value) ? $s : new StoreArrayElement($s->slot, $newI, $newV);
        }
        if ($s instanceof ExprStmt) {
            $newE = $this->rewriteExpr($s->expr, false, $escaping);
            return $newE === $s->expr ? $s : new ExprStmt($newE);
        }
        return $s;
    }

    private function rewriteTerm(Terminator $t, array $escaping): Terminator
    {
        if ($t instanceof Return_) {
            if ($t->value === null) return $t;
            $newV = $this->rewriteExpr($t->value, true, $escaping);
            return $newV === $t->value ? $t : new Return_($newV);
        }
        if ($t instanceof Throw_) {
            $newV = $this->rewriteExpr($t->value, true, $escaping);
            return $newV === $t->value ? $t : new Throw_($newV);
        }
        if ($t instanceof CondGoto) {
            $newC = $this->rewriteExpr($t->cond, false, $escaping);
            return $newC === $t->cond ? $t : new CondGoto($newC, $t->thenPc, $t->elsePc);
        }
        if ($t instanceof Switch_) {
            $newK = $this->rewriteExpr($t->key, false, $escaping);
            return $newK === $t->key ? $t : new Switch_($newK, $t->defaultPc, $t->cases);
        }
        return $t;
    }

    /**
     * Walk an Expr with an observing flag. Wrapper New_ in
     * non-observing positions unwraps to the inner expression
     * (which is itself recursively rewritten — chained wrappers
     * would unwrap end-to-end, though we don't currently emit
     * that shape).
     */
    private function rewriteExpr(Expr $e, bool $observing, array $escaping): Expr
    {
        // The detector: a wrapper allocation in a non-observing
        // context is replaced by its inner expression.
        if ($e instanceof New_
            && $e->classFqn === self::WRAPPER_FQN
            && \count($e->args) === 1
            && $e->binaryName === null
            && !$observing
        ) {
            return $this->rewriteExpr($e->args[0], false, $escaping);
        }

        if ($e instanceof BinOp) {
            if ($e->op === '===' || $e->op === '!==') {
                $L = $this->rewriteExpr($e->left,  true, $escaping);
                $R = $this->rewriteExpr($e->right, true, $escaping);
            } else {
                $L = $this->rewriteExpr($e->left,  false, $escaping);
                $R = $this->rewriteExpr($e->right, false, $escaping);
            }
            return ($L === $e->left && $R === $e->right) ? $e : new BinOp($e->op, $L, $R);
        }
        if ($e instanceof UnaryOp) {
            $V = $this->rewriteExpr($e->operand, false, $escaping);
            return $V === $e->operand ? $e : new UnaryOp($e->op, $V);
        }
        if ($e instanceof StaticCall) {
            $argObs = $this->staticCallObservesIdentity($e);
            $newArgs = [];
            $changed = false;
            foreach ($e->args as $i => $arg) {
                $newArgs[$i] = $this->rewriteExpr($arg, $argObs, $escaping);
                if ($newArgs[$i] !== $arg) $changed = true;
            }
            return $changed ? new StaticCall($e->classFqn, $e->method, $newArgs, $e->binaryName) : $e;
        }
        if ($e instanceof InstanceCall) {
            $argObs = !$this->instanceCallArgsAreValueOnly($e);
            $newR = $this->rewriteExpr($e->receiver, false, $escaping);
            $newArgs = [];
            $changed = $newR !== $e->receiver;
            foreach ($e->args as $i => $arg) {
                $newArgs[$i] = $this->rewriteExpr($arg, $argObs, $escaping);
                if ($newArgs[$i] !== $arg) $changed = true;
            }
            return $changed ? new InstanceCall($newR, $e->method, $newArgs) : $e;
        }
        if ($e instanceof New_) {
            // Non-wrapper New_ (e.g., new ArrayList, new RuntimeException).
            // Constructor args are conservative — caller might stash.
            $newArgs = [];
            $changed = false;
            foreach ($e->args as $i => $arg) {
                $newArgs[$i] = $this->rewriteExpr($arg, true, $escaping);
                if ($newArgs[$i] !== $arg) $changed = true;
            }
            return $changed ? new New_($e->classFqn, $newArgs, $e->binaryName) : $e;
        }
        if ($e instanceof InvokeCallable) {
            $newC = $this->rewriteExpr($e->callable, false, $escaping);
            $newArgs = [];
            $changed = $newC !== $e->callable;
            foreach ($e->args as $i => $arg) {
                $newArgs[$i] = $this->rewriteExpr($arg, true, $escaping);
                if ($newArgs[$i] !== $arg) $changed = true;
            }
            return $changed ? new InvokeCallable($newC, $newArgs) : $e;
        }
        if ($e instanceof FieldRead) {
            $newR = $this->rewriteExpr($e->receiver, false, $escaping);
            return $newR === $e->receiver ? $e : new FieldRead($newR, $e->field);
        }
        if ($e instanceof ArrayLit) {
            $newEls = [];
            $changed = false;
            foreach ($e->elements as $i => $el) {
                $newEls[$i] = $this->rewriteExpr($el, true, $escaping);
                if ($newEls[$i] !== $el) $changed = true;
            }
            return $changed ? new ArrayLit($newEls) : $e;
        }
        if ($e instanceof ArrayElementRead) {
            $newI = $this->rewriteExpr($e->index, false, $escaping);
            return $newI === $e->index ? $e : new ArrayElementRead($e->slot, $newI);
        }

        // Leaves: literals, LocalRead, ParamRead, CaughtException,
        // StaticFieldRead, ArrayLengthRead, NullLit.
        return $e;
    }

    // ─── Whitelist: identity observation by call target ─────────────────────

    /**
     * Whether the call observes identity of its (String-typed) args.
     * Conservative default: yes. Whitelist of known-value-only
     * static targets returns no.
     */
    private function staticCallObservesIdentity(StaticCall $e): bool
    {
        // The two known-observing targets.
        if ($e->classFqn === self::SYSTEM_FQN && $e->method === 'identityHashCode') {
            return true;
        }
        if ($e->classFqn === self::STRING_FQN && $e->method === 'intern') {
            return true;
        }

        // Known-safe value-only targets — JDK shim families that we
        // ship and know don't compare references.
        static $valueOnlyClasses = [
            self::STRING_FQN                                          => true,
            self::SYSTEM_FQN                                          => true,
            '\\PHPJava\\Aot\\Runtime\\java\\lang\\Math'               => true,
            '\\PHPJava\\Aot\\Runtime\\java\\lang\\Integer'            => true,
            '\\PHPJava\\Aot\\Runtime\\java\\lang\\Long'               => true,
            '\\PHPJava\\Aot\\Runtime\\java\\lang\\Boolean'            => true,
            '\\PHPJava\\Aot\\Runtime\\java\\lang\\Character'          => true,
            '\\PHPJava\\Aot\\Runtime\\java\\lang\\Float_'             => true,
            '\\PHPJava\\Aot\\Runtime\\java\\lang\\StringBuilder'      => true,
            '\\PHPJava\\Aot\\Runtime\\java\\util\\Objects'            => true,
            '\\PHPJava\\Aot\\Runtime\\java\\util\\Arrays'             => true,
            '\\PHPJava\\Aot\\Runtime\\java\\util\\ArrayList'          => true,
            '\\PHPJava\\Aot\\Runtime\\java\\util\\HashMap'            => true,
            '\\PHPJava\\Aot\\Runtime\\java\\util\\regex\\Pattern'     => true,
            '\\PHPJava\\Aot\\Runtime\\java\\util\\regex\\Matcher'     => true,
            // Built-in PHP fns used by the lowerer for primitive ops.
            '\\strval'                                                => true,
            '\\intval'                                                => true,
            '\\floatval'                                              => true,
            '\\boolval'                                               => true,
            '\\count'                                                 => true,
            '\\strlen'                                                => true,
            '\\strtolower'                                            => true,
            '\\strtoupper'                                            => true,
            '\\trim'                                                  => true,
            '\\str_replace'                                           => true,
            '\\mb_chr'                                                => true,
            '\\mb_ord'                                                => true,
            '\\PHPJava\\Aot\\Runtime\\jvm_z_narrow'                   => true,
            '\\PHPJava\\Aot\\Runtime\\jvm_c_to_string'                => true,
            '\\PHPJava\\Aot\\Runtime\\jvm_print_chars'                => true,
            '\\PHPJava\\Aot\\Runtime\\jvm_float_equals'               => true,
            '\\PHPJava\\Aot\\Runtime\\jvm_fcmpg'                      => true,
            '\\PHPJava\\Aot\\Runtime\\jvm_fcmpl'                      => true,
        ];
        if (isset($valueOnlyClasses[$e->classFqn])) {
            return false;
        }

        // `parent::__construct(...)` — used in inheritance super-init.
        // Args are constructor params; conservative.
        if ($e->classFqn === 'parent') return true;
        // `self::method` — recursive call within the AOT-emitted class.
        // Conservative — caller observation depends on callee's body.
        if ($e->classFqn === 'self') return true;

        return true;
    }

    /**
     * Whether instance-call args are guaranteed value-only (i.e.,
     * the call doesn't compare references).
     *
     * Conservative whitelist: PrintStream's print/println sink the
     * args to stdout; other instance calls go to opaque user code.
     */
    private function instanceCallArgsAreValueOnly(InstanceCall $e): bool
    {
        return $e->method === 'println' || $e->method === 'print';
    }
}

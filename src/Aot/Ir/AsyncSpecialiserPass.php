<?php
declare(strict_types=1);
namespace PHPJava\Aot\Ir;

require_once __DIR__ . '/Node.php';

/**
 * Async-emit specialiser. Consumes IR after InlinePass and rewrites
 * call-site patterns that don't need to engage the runtime executor.
 *
 * Primary target — the immediate-await peephole:
 *
 *     CompletableFuture::supplyAsync(s)->get()  →  ({s})()
 *     CompletableFuture::supplyAsync(s)->join() →  ({s})()
 *     CompletableFuture::runAsync(r)->get()     →  ({r})()
 *     CompletableFuture::runAsync(r)->join()    →  ({r})()
 *
 * Why this is sound under PHP cooperative scheduling:
 *   - supplyAsync schedules `s` on InlineExecutor, allocates a Future,
 *     returns the Future.
 *   - .get() blocks the calling fiber until the Future settles.
 *   - With a single carrier thread, the executor offers no parallelism
 *     benefit — `s` runs on the only physical thread either way. The
 *     executor is purely overhead (Future allocation + queue + drain).
 *   - If `s` suspends, direct invocation suspends the calling fiber,
 *     which is what `.get()` would have done after settling.
 *   - If `s` throws, both paths rethrow at the same point.
 *
 * Net win on the bench-validated path: skip ~365 ns of executor overhead
 * per supplyAsync().get() call site at compile time. Combined with the
 * may-suspend analyser, this is the path to the AOT-class concurrent
 * semantics (sub-200 ns) for the common case.
 *
 * Detection requires the StaticCall to appear as the *direct receiver*
 * of the InstanceCall — no intervening StoreLocal/ASTORE. When the
 * Future is stored to a local and used elsewhere, we can't safely
 * collapse without escape analysis (the local might be passed to
 * `allOf()` or `thenCombine()`, which DO need real Futures). The
 * single-use-no-escape extension is a fixpoint pass for v2.
 *
 * Iterates to fixpoint so chains nested under BinOp / further
 * InstanceCall / etc. all collapse in one Compiler invocation.
 */
final class AsyncSpecialiserPass
{
    /**
     * Class FQNs that route through InlineExecutor::async or
     * VirtualThreadExecutor::async. Listed with leading backslash to
     * match the ClassFqn form the IR Builder produces for shimmed
     * Java classes.
     */
    private const ASYNC_FACTORY_CLASSES = [
        '\\PHPJava\\Aot\\Runtime\\java\\util\\concurrent\\CompletableFuture',
    ];

    /**
     * Methods on the above classes that produce a Future from a
     * callable argument at position 0. The map is class → set of
     * methods. Right now both supplyAsync and runAsync take a single
     * callable; v2 may need finer-grained per-method signatures when
     * thenApplyAsync / thenComposeAsync land.
     */
    private const ASYNC_FACTORY_METHODS = [
        'supplyAsync' => true,
        'runAsync'    => true,
    ];

    /**
     * Methods on Future / CompletableFuture that block until the
     * Future settles and return the value. .get() is the primary
     * Java contract; .join() differs only in exception wrapping
     * which we don't model differently in v1.
     */
    private const AWAIT_METHODS = [
        'get'  => true,
        'join' => true,
    ];

    public function run(Module $m): Module
    {
        do {
            $changed = false;
            foreach ($m->methods as $method) {
                foreach ($method->blocks as $bb) {
                    foreach ($bb->stmts as $i => $s) {
                        $newS = $this->rewriteStmt($s, $changed);
                        if ($newS !== null) $bb->stmts[$i] = $newS;
                    }
                    if ($bb->term !== null) {
                        $newT = $this->rewriteTerm($bb->term, $changed);
                        if ($newT !== null) $bb->term = $newT;
                    }
                }
                $this->applyNoEscapeCollapse($method, $changed);
            }
        } while ($changed);
        return $m;
    }

    private function rewriteStmt(Stmt $s, bool &$changed): ?Stmt
    {
        if ($s instanceof StoreLocal) {
            $newV = $this->rewriteExpr($s->value, $changed);
            return $newV === $s->value ? null : new StoreLocal($s->slot, $newV);
        }
        if ($s instanceof ExprStmt) {
            $newE = $this->rewriteExpr($s->expr, $changed);
            return $newE === $s->expr ? null : new ExprStmt($newE);
        }
        if ($s instanceof StoreArrayElement) {
            $newI = $this->rewriteExpr($s->index, $changed);
            $newV = $this->rewriteExpr($s->value, $changed);
            if ($newI === $s->index && $newV === $s->value) return null;
            return new StoreArrayElement($s->slot, $newI, $newV);
        }
        if ($s instanceof StoreField) {
            $newR = $this->rewriteExpr($s->receiver, $changed);
            $newV = $this->rewriteExpr($s->value, $changed);
            if ($newR === $s->receiver && $newV === $s->value) return null;
            return new StoreField($newR, $s->field, $newV);
        }
        if ($s instanceof StoreStaticField) {
            $newV = $this->rewriteExpr($s->value, $changed);
            return $newV === $s->value ? null : new StoreStaticField($s->classFqn, $s->field, $newV);
        }
        return null;
    }

    private function rewriteTerm(Terminator $t, bool &$changed): ?Terminator
    {
        if ($t instanceof CondGoto) {
            $newC = $this->rewriteExpr($t->cond, $changed);
            return $newC === $t->cond ? null : new CondGoto($newC, $t->thenPc, $t->elsePc);
        }
        if ($t instanceof Return_ && $t->value !== null) {
            $newV = $this->rewriteExpr($t->value, $changed);
            return $newV === $t->value ? null : new Return_($newV);
        }
        if ($t instanceof Throw_) {
            $newV = $this->rewriteExpr($t->value, $changed);
            return $newV === $t->value ? null : new Throw_($newV);
        }
        return null;
    }

    private function rewriteExpr(Expr $e, bool &$changed): Expr
    {
        // Recurse into children first — bottom-up rewrite so
        // nested patterns collapse before we look at the wrapping
        // node.
        if ($e instanceof InstanceCall) {
            $newReceiver = $this->rewriteExpr($e->receiver, $changed);
            $newArgs = $this->rewriteArgs($e->args, $changed);
            $rewritten = ($newReceiver === $e->receiver && $newArgs === $e->args)
                ? $e
                : new InstanceCall($newReceiver, $e->method, $newArgs);

            // Peephole: rewritten is now `InstanceCall(StaticCall(<async-factory>, <factory-method>, [arg]), <await-method>, [])`
            $collapsed = $this->tryCollapseAwaitPair($rewritten);
            if ($collapsed !== null) {
                $changed = true;
                return $collapsed;
            }
            return $rewritten;
        }
        if ($e instanceof StaticCall) {
            $newArgs = $this->rewriteArgs($e->args, $changed);
            return $newArgs === $e->args
                ? $e
                : new StaticCall($e->classFqn, $e->method, $newArgs, $e->binaryName);
        }
        if ($e instanceof New_) {
            $newArgs = $this->rewriteArgs($e->args, $changed);
            return $newArgs === $e->args
                ? $e
                : new New_($e->classFqn, $newArgs, $e->binaryName);
        }
        if ($e instanceof InvokeCallable) {
            $newCallable = $this->rewriteExpr($e->callable, $changed);
            $newArgs = $this->rewriteArgs($e->args, $changed);
            if ($newCallable === $e->callable && $newArgs === $e->args) return $e;
            return new InvokeCallable($newCallable, $newArgs);
        }
        if ($e instanceof BinOp) {
            $L = $this->rewriteExpr($e->left, $changed);
            $R = $this->rewriteExpr($e->right, $changed);
            return ($L === $e->left && $R === $e->right) ? $e : new BinOp($e->op, $L, $R);
        }
        if ($e instanceof UnaryOp) {
            $V = $this->rewriteExpr($e->operand, $changed);
            return $V === $e->operand ? $e : new UnaryOp($e->op, $V);
        }
        if ($e instanceof FieldRead) {
            $newR = $this->rewriteExpr($e->receiver, $changed);
            return $newR === $e->receiver ? $e : new FieldRead($newR, $e->field);
        }
        if ($e instanceof ArrayElementRead) {
            $newI = $this->rewriteExpr($e->index, $changed);
            return $newI === $e->index ? $e : new ArrayElementRead($e->slot, $newI);
        }
        // ArrayLengthRead has no Expr children — just a local slot int.
        return $e;
    }

    /** @param Expr[] $args  @return Expr[] */
    private function rewriteArgs(array $args, bool &$changed): array
    {
        $newArgs = [];
        $any = false;
        foreach ($args as $a) {
            $na = $this->rewriteExpr($a, $changed);
            if ($na !== $a) $any = true;
            $newArgs[] = $na;
        }
        return $any ? $newArgs : $args;
    }

    /**
     * No-escape collapse — the StoreLocal extension to the inline
     * peephole. Pattern:
     *
     *   StoreLocal(slot, CompletableFuture::supplyAsync($s))
     *   ... no other access to slot ...
     *   InstanceCall(LocalRead(slot), 'get'|'join', [])
     *
     * Rewrite (sound):
     *
     *   StoreLocal(slot, $s)             // store the supplier itself, not the Future
     *   ... unchanged ...
     *   InvokeCallable(LocalRead(slot), [])   // invoke at original .get() site
     *
     * Soundness: the supplier expression is evaluated at the StoreLocal
     * site (unchanged from original — `supplyAsync($s)` evaluates `$s`
     * there too). The supplier *invocation* still happens at the .get()
     * site, preserving exception ordering. The slot's type changes
     * from Future-of-X to (callable returning X), but the analysis
     * proves no other code observes the slot, so the type change is
     * unobservable.
     *
     * Restricted to method-wide single consuming use of the slot:
     *   - exactly one StoreLocal of an async-factory call into the slot
     *   - exactly one read of the slot, in `.get()` / `.join()` position
     *   - no other reads (no escape to allOf / thenApply / etc.)
     *   - no re-store of the slot anywhere
     *
     * Multi-`.get()` is correctly rejected — Java's Future caches the
     * value across calls, but our rewrite would re-invoke the supplier
     * each time. Future extension if a fixture needs it: cache the
     * first invocation's result in another slot.
     */
    private function applyNoEscapeCollapse(Method $method, bool &$changed): void
    {
        // Pre-pass: catalogue all StoreLocal-of-async-factory and all
        // accesses (reads + stores) per slot, method-wide.

        /** @var array<int, list<array{0:BasicBlock, 1:int, 2:StoreLocal, 3:StaticCall}>> */
        $factoryStores = [];                 // slot => [ [bb, stmtIndex, storeStmt, factoryCall], ... ]
        /** @var array<int, int> */
        $totalStores = [];                   // slot => count of every StoreLocal touching it
        /** @var array<int, int> */
        $totalReads = [];                    // slot => count of every LocalRead

        foreach ($method->blocks as $bb) {
            foreach ($bb->stmts as $i => $s) {
                if ($s instanceof StoreLocal) {
                    $totalStores[$s->slot] = ($totalStores[$s->slot] ?? 0) + 1;
                    $factory = $this->matchAsyncFactory($s->value);
                    if ($factory !== null) {
                        $factoryStores[$s->slot][] = [$bb, $i, $s, $factory];
                    }
                    $this->countReads($s->value, $totalReads);
                } elseif ($s instanceof IincLocal) {
                    // iinc both reads and writes the slot
                    $totalStores[$s->slot] = ($totalStores[$s->slot] ?? 0) + 1;
                    $totalReads[$s->slot]  = ($totalReads[$s->slot]  ?? 0) + 1;
                } elseif ($s instanceof ExprStmt) {
                    $this->countReads($s->expr, $totalReads);
                } elseif ($s instanceof StoreField) {
                    $this->countReads($s->receiver, $totalReads);
                    $this->countReads($s->value, $totalReads);
                } elseif ($s instanceof StoreStaticField) {
                    $this->countReads($s->value, $totalReads);
                } elseif ($s instanceof StoreArrayElement) {
                    // StoreArrayElement reads the slot itself (the array) plus index/value exprs
                    $totalReads[$s->slot] = ($totalReads[$s->slot] ?? 0) + 1;
                    $this->countReads($s->index, $totalReads);
                    $this->countReads($s->value, $totalReads);
                }
            }
            if ($bb->term !== null) {
                if ($bb->term instanceof CondGoto) $this->countReads($bb->term->cond, $totalReads);
                elseif ($bb->term instanceof Return_ && $bb->term->value !== null) {
                    $this->countReads($bb->term->value, $totalReads);
                }
                elseif ($bb->term instanceof Throw_) $this->countReads($bb->term->value, $totalReads);
                elseif ($bb->term instanceof Switch_) $this->countReads($bb->term->key, $totalReads);
            }
        }

        // For each candidate slot: exactly 1 store of an async factory,
        // 1 store total, 1 read total. The single read must appear as
        // the receiver of a `.get()` or `.join()` InstanceCall with no
        // arguments — that's the consuming use we'll rewrite.
        foreach ($factoryStores as $slot => $stores) {
            if (\count($stores) !== 1) continue;
            if (($totalStores[$slot] ?? 0) !== 1) continue;
            if (($totalReads[$slot]  ?? 0) !== 1) continue;

            [$storeBb, $storeIdx, $storeStmt, $factory] = $stores[0];
            $supplier = $factory->args[0];

            // Locate and rewrite the consuming use. We scan every
            // method position; the read-count check above guarantees
            // there's exactly one LocalRead(slot), but it may be
            // arbitrarily nested under InstanceCall / args / ArrayLit
            // / etc. The rewrite turns its parent
            //   InstanceCall(LocalRead(slot), 'get'|'join', [])
            // into InvokeCallable(LocalRead(slot), []).
            //
            // If the only read isn't in that exact shape (e.g., the
            // slot is read as an argument to another call), we don't
            // collapse.
            $rewroteUse = false;
            foreach ($method->blocks as $bb) {
                foreach ($bb->stmts as $i => $s) {
                    $newS = $this->rewriteUseInStmt($s, $slot, $rewroteUse);
                    if ($newS !== null) $bb->stmts[$i] = $newS;
                }
                if ($bb->term !== null) {
                    $newT = $this->rewriteUseInTerm($bb->term, $slot, $rewroteUse);
                    if ($newT !== null) $bb->term = $newT;
                }
            }
            if (!$rewroteUse) continue;  // safety net; couldn't shape-match

            // Replace the StoreLocal's value with the supplier itself.
            $newStore = new StoreLocal($slot, $supplier);
            $idx = \array_search($storeStmt, $storeBb->stmts, true);
            if ($idx !== false) {
                $storeBb->stmts[$idx] = $newStore;
            }
            $changed = true;
        }
    }

    /**
     * If $expr is a StaticCall to one of the async-factory class+method
     * combinations with exactly one arg, return it. Otherwise null.
     */
    private function matchAsyncFactory(Expr $expr): ?StaticCall
    {
        if (!($expr instanceof StaticCall)) return null;
        if (!isset(self::ASYNC_FACTORY_METHODS[$expr->method])) return null;
        if (!\in_array($expr->classFqn, self::ASYNC_FACTORY_CLASSES, true)) return null;
        if (\count($expr->args) !== 1) return null;
        return $expr;
    }

    /** Tally LocalRead occurrences keyed by slot, recursively. */
    private function countReads(Expr $e, array &$counts): void
    {
        if ($e instanceof LocalRead) {
            $counts[$e->slot] = ($counts[$e->slot] ?? 0) + 1;
            return;
        }
        if ($e instanceof InstanceCall) {
            $this->countReads($e->receiver, $counts);
            foreach ($e->args as $a) $this->countReads($a, $counts);
            return;
        }
        if ($e instanceof StaticCall) {
            foreach ($e->args as $a) $this->countReads($a, $counts);
            return;
        }
        if ($e instanceof New_) {
            foreach ($e->args as $a) $this->countReads($a, $counts);
            return;
        }
        if ($e instanceof InvokeCallable) {
            $this->countReads($e->callable, $counts);
            foreach ($e->args as $a) $this->countReads($a, $counts);
            return;
        }
        if ($e instanceof BinOp) {
            $this->countReads($e->left, $counts);
            $this->countReads($e->right, $counts);
            return;
        }
        if ($e instanceof UnaryOp) {
            $this->countReads($e->operand, $counts);
            return;
        }
        if ($e instanceof FieldRead) {
            $this->countReads($e->receiver, $counts);
            return;
        }
        if ($e instanceof ArrayElementRead) {
            // The array slot itself is read implicitly by the AER
            $counts[$e->slot] = ($counts[$e->slot] ?? 0) + 1;
            $this->countReads($e->index, $counts);
            return;
        }
        if ($e instanceof ArrayLengthRead) {
            $counts[$e->slot] = ($counts[$e->slot] ?? 0) + 1;
            return;
        }
        if ($e instanceof ArrayLit) {
            foreach ($e->elements as $el) $this->countReads($el, $counts);
            return;
        }
        // Literals, ParamRead, NullLit, CaughtException, StaticFieldRead — no slot reads
    }

    /**
     * Walk a Stmt and rewrite any `InstanceCall(LocalRead($slot), 'get'|'join', [])`
     * to `InvokeCallable(LocalRead($slot), [])`. Sets $rewrote on success.
     * Returns a replacement Stmt if any child changed, else null.
     */
    private function rewriteUseInStmt(Stmt $s, int $slot, bool &$rewrote): ?Stmt
    {
        if ($s instanceof StoreLocal) {
            $newV = $this->rewriteUseInExpr($s->value, $slot, $rewrote);
            return $newV === $s->value ? null : new StoreLocal($s->slot, $newV);
        }
        if ($s instanceof ExprStmt) {
            $newE = $this->rewriteUseInExpr($s->expr, $slot, $rewrote);
            return $newE === $s->expr ? null : new ExprStmt($newE);
        }
        if ($s instanceof StoreField) {
            $newR = $this->rewriteUseInExpr($s->receiver, $slot, $rewrote);
            $newV = $this->rewriteUseInExpr($s->value, $slot, $rewrote);
            if ($newR === $s->receiver && $newV === $s->value) return null;
            return new StoreField($newR, $s->field, $newV);
        }
        if ($s instanceof StoreStaticField) {
            $newV = $this->rewriteUseInExpr($s->value, $slot, $rewrote);
            return $newV === $s->value ? null : new StoreStaticField($s->classFqn, $s->field, $newV);
        }
        if ($s instanceof StoreArrayElement) {
            $newI = $this->rewriteUseInExpr($s->index, $slot, $rewrote);
            $newV = $this->rewriteUseInExpr($s->value, $slot, $rewrote);
            if ($newI === $s->index && $newV === $s->value) return null;
            return new StoreArrayElement($s->slot, $newI, $newV);
        }
        return null;
    }

    private function rewriteUseInTerm(Terminator $t, int $slot, bool &$rewrote): ?Terminator
    {
        if ($t instanceof CondGoto) {
            $newC = $this->rewriteUseInExpr($t->cond, $slot, $rewrote);
            return $newC === $t->cond ? null : new CondGoto($newC, $t->thenPc, $t->elsePc);
        }
        if ($t instanceof Return_ && $t->value !== null) {
            $newV = $this->rewriteUseInExpr($t->value, $slot, $rewrote);
            return $newV === $t->value ? null : new Return_($newV);
        }
        if ($t instanceof Throw_) {
            $newV = $this->rewriteUseInExpr($t->value, $slot, $rewrote);
            return $newV === $t->value ? null : new Throw_($newV);
        }
        return null;
    }

    private function rewriteUseInExpr(Expr $e, int $slot, bool &$rewrote): Expr
    {
        if ($e instanceof InstanceCall) {
            // Match the consuming pattern: LocalRead(slot).get|join() with no args
            if (
                $e->receiver instanceof LocalRead
                && $e->receiver->slot === $slot
                && isset(self::AWAIT_METHODS[$e->method])
                && \count($e->args) === 0
            ) {
                $rewrote = true;
                return new InvokeCallable($e->receiver, []);
            }
            // Otherwise recurse into receiver + args (still only one
            // LocalRead(slot) anywhere by precondition; if it's not
            // in the right shape, this descent leaves it untouched)
            $newR = $this->rewriteUseInExpr($e->receiver, $slot, $rewrote);
            $newArgs = $this->rewriteUseInArgs($e->args, $slot, $rewrote);
            return ($newR === $e->receiver && $newArgs === $e->args)
                ? $e
                : new InstanceCall($newR, $e->method, $newArgs);
        }
        if ($e instanceof StaticCall) {
            $newArgs = $this->rewriteUseInArgs($e->args, $slot, $rewrote);
            return $newArgs === $e->args ? $e : new StaticCall($e->classFqn, $e->method, $newArgs, $e->binaryName);
        }
        if ($e instanceof New_) {
            $newArgs = $this->rewriteUseInArgs($e->args, $slot, $rewrote);
            return $newArgs === $e->args ? $e : new New_($e->classFqn, $newArgs, $e->binaryName);
        }
        if ($e instanceof InvokeCallable) {
            $newC = $this->rewriteUseInExpr($e->callable, $slot, $rewrote);
            $newArgs = $this->rewriteUseInArgs($e->args, $slot, $rewrote);
            return ($newC === $e->callable && $newArgs === $e->args) ? $e : new InvokeCallable($newC, $newArgs);
        }
        if ($e instanceof BinOp) {
            $L = $this->rewriteUseInExpr($e->left, $slot, $rewrote);
            $R = $this->rewriteUseInExpr($e->right, $slot, $rewrote);
            return ($L === $e->left && $R === $e->right) ? $e : new BinOp($e->op, $L, $R);
        }
        if ($e instanceof UnaryOp) {
            $V = $this->rewriteUseInExpr($e->operand, $slot, $rewrote);
            return $V === $e->operand ? $e : new UnaryOp($e->op, $V);
        }
        if ($e instanceof FieldRead) {
            $newR = $this->rewriteUseInExpr($e->receiver, $slot, $rewrote);
            return $newR === $e->receiver ? $e : new FieldRead($newR, $e->field);
        }
        if ($e instanceof ArrayElementRead) {
            $newI = $this->rewriteUseInExpr($e->index, $slot, $rewrote);
            return $newI === $e->index ? $e : new ArrayElementRead($e->slot, $newI);
        }
        if ($e instanceof ArrayLit) {
            $any = false;
            $els = [];
            foreach ($e->elements as $el) {
                $ne = $this->rewriteUseInExpr($el, $slot, $rewrote);
                if ($ne !== $el) $any = true;
                $els[] = $ne;
            }
            return $any ? new ArrayLit($els) : $e;
        }
        return $e;
    }

    /** @param Expr[] $args  @return Expr[] */
    private function rewriteUseInArgs(array $args, int $slot, bool &$rewrote): array
    {
        $newArgs = [];
        $any = false;
        foreach ($args as $a) {
            $na = $this->rewriteUseInExpr($a, $slot, $rewrote);
            if ($na !== $a) $any = true;
            $newArgs[] = $na;
        }
        return $any ? $newArgs : $args;
    }

    /**
     * Returns the collapsed InvokeCallable if the InstanceCall matches
     * the await-pair peephole; null otherwise.
     */
    private function tryCollapseAwaitPair(InstanceCall $call): ?Expr
    {
        // Must be one of the await methods (.get / .join)
        if (!isset(self::AWAIT_METHODS[$call->method])) return null;
        // Await methods take no arguments (positional) — Java's get(timeout)
        // overload would have args, and we don't collapse that yet.
        if (\count($call->args) !== 0) return null;

        // Receiver must be a static call on a known async-factory class
        $recv = $call->receiver;
        if (!($recv instanceof StaticCall)) return null;
        if (!isset(self::ASYNC_FACTORY_METHODS[$recv->method])) return null;
        if (!\in_array($recv->classFqn, self::ASYNC_FACTORY_CLASSES, true)) return null;
        // Factory takes exactly one callable arg
        if (\count($recv->args) !== 1) return null;

        // Collapse: invoke the supplier directly, no executor.
        return new InvokeCallable($recv->args[0], []);
    }
}

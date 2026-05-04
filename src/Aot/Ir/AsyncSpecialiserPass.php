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

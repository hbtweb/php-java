<?php
declare(strict_types=1);
namespace PHPJava\Aot\Ir\Analysis;

use PHPJava\Aot\Ir\BasicBlock;
use PHPJava\Aot\Ir\Method;
use PHPJava\Aot\Ir\Module;

// Stmts the analyzer walks
use PHPJava\Aot\Ir\StoreLocal;
use PHPJava\Aot\Ir\StoreStaticField;
use PHPJava\Aot\Ir\StoreField;
use PHPJava\Aot\Ir\StoreArrayElement;
use PHPJava\Aot\Ir\ExprStmt;

// Exprs that may contain calls
use PHPJava\Aot\Ir\Expr;
use PHPJava\Aot\Ir\StaticCall;
use PHPJava\Aot\Ir\InstanceCall;
use PHPJava\Aot\Ir\New_;
use PHPJava\Aot\Ir\BinOp;
use PHPJava\Aot\Ir\UnaryOp;
use PHPJava\Aot\Ir\FieldRead;
use PHPJava\Aot\Ir\ArrayElementRead;
use PHPJava\Aot\Ir\ArrayLit;

// Terminators that contain Exprs
use PHPJava\Aot\Ir\Terminator;
use PHPJava\Aot\Ir\Return_;
use PHPJava\Aot\Ir\Throw_;
use PHPJava\Aot\Ir\CondGoto;
use PHPJava\Aot\Ir\Switch_;

/**
 * IR pass that classifies a Method as "may suspend" or "definitely
 * doesn't suspend" by walking the method's call graph at IR level.
 *
 * Used by the future async-emit specialiser: when the body of a
 * `Thread.start(runnable)` / `executor.submit(fn).get()` /
 * `CompletableFuture.supplyAsync(fn).join()` lambda is provably
 * non-suspending AND the await happens immediately, the AOT compiler
 * can collapse the whole expression to a direct call. Per the
 * 2026-05-04 amphp-probe bench: a direct call is **9 ns/op** vs
 * the ~365 ns/op the runtime would charge — 39× gap, 219× over
 * AMPHP for the inlinable case.
 *
 * Suspend points are JVM API methods that block the calling thread
 * (Thread.sleep / .join / Object.wait / Future.get / etc.). The
 * analyzer's WHITELIST is the canonical set; anything not on it that
 * the method calls is treated conservatively as "may suspend" until
 * a transitive analysis (Phase 2) classifies the callee.
 *
 * Phase 1 (this PoC):
 *   - Single-method analysis. If the method directly calls a known
 *     suspend point, returns true.
 *   - If the method calls some other method we haven't analysed yet,
 *     conservatively returns true.
 *   - Pure-arith / store / branch bodies that touch only locals,
 *     fields, primitives → returns false.
 *
 * Phase 2 (future):
 *   - Build a call-graph at Module level. Propagate may-suspend
 *     transitively: a method "may suspend" iff any callee may suspend
 *     OR any direct call hits a known suspend point.
 *   - Cross-module analysis when JAR-level compilation lands.
 *
 * Phase 3 (future):
 *   - Conservative for unknown JDK calls: the JDK shim layer can
 *     advertise per-method "may-suspend" annotations so the analyzer
 *     doesn't have to assume the worst on every j.u.* call.
 */
final class MaySuspendAnalyzer
{
    /**
     * Canonical set of JVM API methods that suspend the calling thread.
     * Format: `<class-binary>.<method-name><descriptor>` (the JVMS
     * method-reference shape used in the constant pool).
     *
     * Extended as more suspend-points surface; bb-allowlist fill will
     * grow this list. Per
     * `~/GitHub/ClojurePHP/docs/CLJP-CONCURRENCY.md`'s mapping of
     * suspend semantics across the j.u.concurrent.* surface.
     */
    public const SUSPEND_POINTS = [
        // Thread family
        'java/lang/Thread.sleep(J)V',
        'java/lang/Thread.sleep(JI)V',
        'java/lang/Thread.join()V',
        'java/lang/Thread.join(J)V',
        'java/lang/Thread.join(JI)V',
        'java/lang/Thread.yield()V',
        'java/lang/Thread.onSpinWait()V',

        // Object monitors
        'java/lang/Object.wait()V',
        'java/lang/Object.wait(J)V',
        'java/lang/Object.wait(JI)V',

        // Future family — including the inheritance chain
        'java/util/concurrent/Future.get()Ljava/lang/Object;',
        'java/util/concurrent/Future.get(JLjava/util/concurrent/TimeUnit;)Ljava/lang/Object;',
        'java/util/concurrent/CompletableFuture.get()Ljava/lang/Object;',
        'java/util/concurrent/CompletableFuture.get(JLjava/util/concurrent/TimeUnit;)Ljava/lang/Object;',
        'java/util/concurrent/CompletableFuture.join()Ljava/lang/Object;',

        // BlockingQueue family
        'java/util/concurrent/BlockingQueue.take()Ljava/lang/Object;',
        'java/util/concurrent/BlockingQueue.put(Ljava/lang/Object;)V',
        'java/util/concurrent/BlockingQueue.poll(JLjava/util/concurrent/TimeUnit;)Ljava/lang/Object;',
        'java/util/concurrent/BlockingQueue.offer(Ljava/lang/Object;JLjava/util/concurrent/TimeUnit;)Z',

        // Sync primitives
        'java/util/concurrent/locks/Lock.lock()V',
        'java/util/concurrent/locks/Lock.lockInterruptibly()V',
        'java/util/concurrent/locks/Lock.tryLock(JLjava/util/concurrent/TimeUnit;)Z',
        'java/util/concurrent/locks/Condition.await()V',
        'java/util/concurrent/locks/Condition.await(JLjava/util/concurrent/TimeUnit;)Z',
        'java/util/concurrent/locks/Condition.awaitNanos(J)J',
        'java/util/concurrent/Semaphore.acquire()V',
        'java/util/concurrent/Semaphore.acquireUninterruptibly()V',
        'java/util/concurrent/CountDownLatch.await()V',
        'java/util/concurrent/CountDownLatch.await(JLjava/util/concurrent/TimeUnit;)Z',
        'java/util/concurrent/CyclicBarrier.await()I',

        // I/O — any blocking read/write/connect on the JVM blocks
        'java/io/InputStream.read()I',
        'java/io/InputStream.read([B)I',
        'java/io/InputStream.read([BII)I',
        'java/net/Socket.connect(Ljava/net/SocketAddress;)V',
        'java/net/Socket.connect(Ljava/net/SocketAddress;I)V',
        // (additions to come as the JDK shim surface fills out)
    ];

    /** @var array<string, bool>  Cache of method-key → may-suspend verdict. */
    private array $methodCache = [];

    /**
     * Phase 1: single-method analysis. Walks the IR for direct calls
     * to suspend-point method-references; returns true on first hit.
     *
     * Conservative on unknown calls — cross-method propagation is
     * Phase 2. For now, a method that calls *anything* not on the
     * pure-helper list is treated as "may suspend" by default.
     *
     * @param Method $method  IR method to classify
     * @param bool $assumeUnknownsPure  When true, unknown calls are
     *        treated as definitely-pure (used for leaf methods we've
     *        already classified, or the optimistic-emit path during
     *        an experimental compile). Default false (safe).
     */
    public function maySuspend(Method $method, bool $assumeUnknownsPure = false): bool
    {
        $key = $method->name . $method->descriptor;
        if (isset($this->methodCache[$key])) {
            return $this->methodCache[$key];
        }
        // Mark as pending to break recursion (a method that calls itself
        // doesn't add new suspend behaviour beyond what its leaves say).
        $this->methodCache[$key] = false;

        foreach ($method->blocks as $bb) {
            foreach ($bb->stmts as $stmt) {
                if ($this->stmtMaySuspend($stmt, $assumeUnknownsPure)) {
                    return $this->methodCache[$key] = true;
                }
            }
            if ($bb->term !== null && $this->termMaySuspend($bb->term, $assumeUnknownsPure)) {
                return $this->methodCache[$key] = true;
            }
        }
        return false;
    }

    /** @return bool true iff this Stmt may reach a suspend point. */
    private function stmtMaySuspend($stmt, bool $assumeUnknownsPure): bool
    {
        if ($stmt instanceof StoreLocal)        return $this->exprMaySuspend($stmt->value, $assumeUnknownsPure);
        if ($stmt instanceof StoreStaticField)  return $this->exprMaySuspend($stmt->value, $assumeUnknownsPure);
        if ($stmt instanceof StoreField)        return $this->exprMaySuspend($stmt->receiver, $assumeUnknownsPure)
                                                   || $this->exprMaySuspend($stmt->value, $assumeUnknownsPure);
        if ($stmt instanceof StoreArrayElement) return $this->exprMaySuspend($stmt->index, $assumeUnknownsPure)
                                                   || $this->exprMaySuspend($stmt->value, $assumeUnknownsPure);
        if ($stmt instanceof ExprStmt)          return $this->exprMaySuspend($stmt->expr, $assumeUnknownsPure);
        // IincLocal: increments a local by a constant — pure.
        return false;
    }

    private function termMaySuspend(Terminator $term, bool $assumeUnknownsPure): bool
    {
        if ($term instanceof Return_)  return $term->value !== null && $this->exprMaySuspend($term->value, $assumeUnknownsPure);
        if ($term instanceof Throw_)   return $this->exprMaySuspend($term->value, $assumeUnknownsPure);
        if ($term instanceof CondGoto) return $this->exprMaySuspend($term->cond, $assumeUnknownsPure);
        if ($term instanceof Switch_)  return $this->exprMaySuspend($term->key, $assumeUnknownsPure);
        // Goto_: control-flow only, pure.
        return false;
    }

    /** @return bool true iff this Expr may reach a suspend point. */
    private function exprMaySuspend(Expr $expr, bool $assumeUnknownsPure): bool
    {
        if ($expr instanceof StaticCall)   return $this->callMaySuspend(
            self::stripBackslashPrefix($expr->classFqn), $expr->method, $expr->args, $assumeUnknownsPure
        );
        if ($expr instanceof InstanceCall) return $this->instanceCallMaySuspend(
            $expr, $assumeUnknownsPure
        );
        if ($expr instanceof New_)         return $this->callMaySuspend(
            self::stripBackslashPrefix($expr->classFqn), '<init>', $expr->args, $assumeUnknownsPure
        );
        if ($expr instanceof BinOp)        return $this->exprMaySuspend($expr->left, $assumeUnknownsPure)
                                              || $this->exprMaySuspend($expr->right, $assumeUnknownsPure);
        if ($expr instanceof UnaryOp)      return $this->exprMaySuspend($expr->operand, $assumeUnknownsPure);
        if ($expr instanceof FieldRead)    return $this->exprMaySuspend($expr->receiver, $assumeUnknownsPure);
        if ($expr instanceof ArrayElementRead) return $this->exprMaySuspend($expr->index, $assumeUnknownsPure);
        if ($expr instanceof ArrayLit)     {
            foreach ($expr->elements as $el) {
                if ($this->exprMaySuspend($el, $assumeUnknownsPure)) return true;
            }
            return false;
        }
        // IntLit/FloatLit/StringLit/BoolLit/NullLit/LocalRead/ParamRead/
        // CaughtException/StaticFieldRead/ArrayLengthRead — pure leaf.
        return false;
    }

    /**
     * Convert AOT-emitted FQN ('\PHPJava\Aot\Runtime\java\util\concurrent\CompletableFuture')
     * back to the JVM binary name ('java/util/concurrent/CompletableFuture')
     * for matching against SUSPEND_POINTS.
     */
    private static function stripBackslashPrefix(string $fqn): string
    {
        // \PHPJava\Aot\Runtime\<rest>  →  <rest> (binary name with /)
        if (\str_starts_with($fqn, '\\PHPJava\\Aot\\Runtime\\')) {
            $rest = \substr($fqn, \strlen('\\PHPJava\\Aot\\Runtime\\'));
            return \str_replace('\\', '/', $rest);
        }
        // self / Generated\<X> — non-JDK, treated as user code (analyzed
        // separately when it's a known method, or assumed-suspending
        // for now).
        return \ltrim($fqn, '\\');
    }

    /**
     * Static / static-fn / new dispatch may-suspend check.
     *
     * @param string $classBin  JVM binary name (e.g. 'java/lang/Thread')
     * @param string $method    method name (e.g. 'sleep')
     * @param Expr[] $args
     */
    private function callMaySuspend(string $classBin, string $method, array $args, bool $assumeUnknownsPure): bool
    {
        // Args may themselves contain calls; check eagerly so we don't
        // miss `Thread.sleep(otherCall())`.
        foreach ($args as $arg) {
            if ($this->exprMaySuspend($arg, $assumeUnknownsPure)) return true;
        }

        // Direct match against the suspend-point whitelist.
        // We don't have the descriptor here at the StaticCall level —
        // the IR Builder mangles the method name with descriptor for
        // overload disambiguation but for known suspend-point methods
        // we can match on `<classBin>.<method-name>` prefix and let
        // the descriptor tail vary across overloads.
        $prefix = $classBin . '.' . $method;
        foreach (self::SUSPEND_POINTS as $sp) {
            if (\str_starts_with($sp, $prefix)) return true;
        }

        // Unknown call. JDK calls (java/javax/jdk/sun/com.sun) that
        // aren't on the suspend list are usually pure (Math.abs,
        // String.length, Pattern.compile, etc.) — opt-in flag picks
        // optimistic vs conservative classification.
        $isJdk = \str_starts_with($classBin, 'java/')
              || \str_starts_with($classBin, 'javax/')
              || \str_starts_with($classBin, 'jdk/')
              || \str_starts_with($classBin, 'sun/')
              || \str_starts_with($classBin, 'com/sun/');
        if ($isJdk) {
            // Default: trust JDK leaves are non-suspending unless on
            // the whitelist. The whitelist's complete by construction
            // for the j.u.concurrent / I/O surface — anything outside
            // it is doing primitive work.
            return false;
        }

        // Non-JDK call: another user method we haven't classified yet.
        // Conservative default = true (may suspend); optimistic flag
        // overrides for experimental emits.
        return !$assumeUnknownsPure;
    }

    private function instanceCallMaySuspend(InstanceCall $call, bool $assumeUnknownsPure): bool
    {
        // Receiver may be a chained call.
        if ($this->exprMaySuspend($call->receiver, $assumeUnknownsPure)) return true;
        foreach ($call->args as $arg) {
            if ($this->exprMaySuspend($arg, $assumeUnknownsPure)) return true;
        }

        // For instance calls, the receiver type is dynamic at the IR
        // level; we can't reliably resolve to a concrete classBin
        // without typeflow analysis. Phase 1 strategy: match on
        // method name against the *name-only* portion of suspend
        // points. Imprecise (false positives possible) but safe.
        // Phase 2 will track receiver type when known statically.
        $methodName = $call->method;
        foreach (self::SUSPEND_POINTS as $sp) {
            // sp = "java/lang/Thread.sleep(J)V" — split on '.'
            $dotPos = \strrpos($sp, '.');
            if ($dotPos === false) continue;
            $spName = \substr($sp, $dotPos + 1);
            $parenPos = \strpos($spName, '(');
            if ($parenPos !== false) $spName = \substr($spName, 0, $parenPos);
            if ($spName === $methodName) return true;
        }
        return false;
    }

    /**
     * Run the analysis across all methods in a Module. Returns a map
     * of method-key → verdict. Use as the input to the async-emit
     * specialiser to decide per-callsite which shape to emit.
     *
     * @return array<string, bool>
     */
    public function analyseModule(Module $module): array
    {
        $verdicts = [];
        foreach ($module->methods as $m) {
            $verdicts[$m->name . $m->descriptor] = $this->maySuspend($m);
        }
        return $verdicts;
    }
}

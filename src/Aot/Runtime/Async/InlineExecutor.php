<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\Async;

/**
 * Inline async runtime — non-suspending fast path.
 *
 * Sized to the AOT pipeline's perf class. PoC v2 measurement (rank 1,
 * `bench/amphp-probe/bench-v2.php`): ~365 ns/op for async+await
 * round-trip — 5.5× faster than AMPHP's 2,031 ns/op, sized
 * proportionally to HotSpot's per-thread context-switch cost
 * (~500 ns class).
 *
 * Three emit shapes the AOT compiler picks between per call site:
 *
 *   Tier 0 — fully inlined. When the may-suspend analyzer
 *   (`src/Aot/Ir/Analysis/MaySuspendAnalyzer.php`) proves the body
 *   is non-suspending AND surrounding context permits, the entire
 *   async/await pair compiles to a direct call. ~iadd-class — same
 *   perf class as the rest of the AOT pipeline. Doesn't enter this
 *   runtime at all.
 *
 *   InlineExecutor (this module) — non-suspending fallback. For tasks that
 *   the analyzer can't reduce to inline but still don't suspend
 *   (cross-method calls into user code that the compiler can't yet
 *   classify, etc.). Queue + drain. No Fiber.
 *
 *   VirtualThreadExecutor (sibling) — Fiber-backed runtime for tasks that
 *   genuinely suspend. ~1 µs/op; raw Fiber suspend/resume is the
 *   floor.
 *
 * InlineExecutor is single-threaded by design (PHP itself is single-threaded
 * within a fiber/coroutine context). When a task body actually needs
 * to suspend, the call should route to VirtualThreadExecutor instead — the
 * may-suspend analyzer makes the choice at compile time. InlineExecutor
 * doesn't need cancellation in the inter-thread sense (no other
 * thread to cancel from); Future-level cancellation pre-empts pending
 * tasks from running.
 *
 * What's intentionally absent compared to AMPHP:
 *   - I/O integration. InlineExecutor never blocks on I/O; tasks that do I/O
 *     should be VirtualThreadExecutor (so the I/O can be awaited via the event loop).
 *     For InlineExecutor's domain (pure-compute non-suspending callbacks),
 *     blocking I/O would be a programming error the compiler should
 *     catch via the may-suspend analysis.
 *   - Composition timeout. Java's Future.get(timeout) requires the
 *     event loop to wake; if your composition needs timeout, you're
 *     in VirtualThreadExecutor's domain.
 *
 * Public API:
 *   async($fn)             → int  Future id
 *   await($id)             → mixed value (or rethrows error)
 *   cancel($id)            → bool true if cancelled (false if already settled)
 *   isPending($id)         → bool
 *   isFulfilled($id)       → bool
 *   isRejected($id)        → bool
 *   thenApply($id, $fn)    → int  new Future id
 *   allOf(...$ids)         → int  new Future id
 *   anyOf(...$ids)         → int  new Future id
 *   reset()                → void  clear state (test helper)
 */
final class InlineExecutor
{
    public const STATE_PENDING    = 0;
    public const STATE_FULFILLED  = 1;
    public const STATE_REJECTED   = 2;
    public const STATE_CANCELLED  = 3;

    /**
     * Future state. Each entry: [state, value, dependents].
     * `dependents` is a list of (kind, ...) tuples for chained
     * Futures that need to be triggered when this Future settles.
     *
     * @var array<int, array{0:int,1:mixed,2:array<int,array>}>
     */
    private static array $futures = [];
    private static int $nextId = 0;

    /** @var array<int, callable> Pending callables (the $fn directly). */
    private static array $queue = [];
    /** @var array<int, int> Parallel: which Future id each callable settles. */
    private static array $qids = [];
    private static int $qsp = 0;
    private static int $qhead = 0;

    /**
     * Spawn $fn as an async task. Returns the Future id (int).
     */
    public static function async(callable $fn): int
    {
        $id = self::$nextId++;
        self::$futures[$id] = [self::STATE_PENDING, null, []];
        $sp = self::$qsp;
        self::$queue[$sp] = $fn;
        self::$qids[$sp] = $id;
        self::$qsp = $sp + 1;
        return $id;
    }

    /**
     * Block until Future $id settles. Drains the queue inline; routes
     * dependents via settle's chain triggers.
     *
     * @throws \Throwable  rethrows the rejection cause
     * @throws CancellationException  if the Future was cancelled
     */
    public static function await(int $id): mixed
    {
        // Drain queue until target Future settles. Other Futures may
        // settle as side-effects; that's fine, their dependents will
        // also fire as their callbacks come up in the queue.
        while (self::$futures[$id][0] === self::STATE_PENDING) {
            $h = self::$qhead;
            if ($h >= self::$qsp) {
                throw new \LogicException(
                    "InlineExecutor::await: queue empty but Future {$id} still pending. "
                    . "Likely cause: thenApply/allOf chain depends on a Future "
                    . "that was never spawned, OR a cycle in the dependency graph."
                );
            }
            $fn = self::$queue[$h];
            $cbId = self::$qids[$h];
            self::$qhead = $h + 1;

            // -1 sentinel: trigger-only callback, no Future to settle
            // (used internally for chain triggers).
            if ($cbId === -1) {
                $fn();
                continue;
            }

            // If the Future was cancelled before its callback ran,
            // skip the work entirely. Future is already in CANCELLED
            // state via cancel(); just don't run the body.
            if (self::$futures[$cbId][0] === self::STATE_CANCELLED) {
                continue;
            }

            try {
                $value = $fn();
                self::settleInternal($cbId, self::STATE_FULFILLED, $value);
            } catch (\Throwable $e) {
                self::settleInternal($cbId, self::STATE_REJECTED, $e);
            }
        }

        $f = self::$futures[$id];
        if ($f[0] === self::STATE_FULFILLED) return $f[1];
        if ($f[0] === self::STATE_REJECTED)  throw $f[1];
        // CANCELLED
        throw new CancellationException("Future {$id} was cancelled");
    }

    /**
     * Cancel a pending Future. If already settled, returns false.
     * Cancellation propagates to dependents on next await().
     */
    public static function cancel(int $id): bool
    {
        if (self::$futures[$id][0] !== self::STATE_PENDING) {
            return false;
        }
        self::settleInternal($id, self::STATE_CANCELLED, null);
        return true;
    }

    public static function isPending(int $id): bool
    {
        return self::$futures[$id][0] === self::STATE_PENDING;
    }

    public static function isFulfilled(int $id): bool
    {
        return self::$futures[$id][0] === self::STATE_FULFILLED;
    }

    public static function isRejected(int $id): bool
    {
        return self::$futures[$id][0] === self::STATE_REJECTED;
    }

    /**
     * Compose: when Future $id fulfills, apply $fn to its value to
     * produce a new Future. If $id rejects or cancels, the new Future
     * propagates that state. Java's CompletableFuture.thenApply.
     */
    public static function thenApply(int $id, callable $fn): int
    {
        $newId = self::$nextId++;
        self::$futures[$newId] = [self::STATE_PENDING, null, []];

        $f = self::$futures[$id];
        if ($f[0] === self::STATE_FULFILLED) {
            // Source already settled — schedule the apply directly.
            $value = $f[1];
            $sp = self::$qsp;
            self::$queue[$sp] = static fn() => $fn($value);
            self::$qids[$sp] = $newId;
            self::$qsp = $sp + 1;
        } elseif ($f[0] === self::STATE_REJECTED) {
            self::settleInternal($newId, self::STATE_REJECTED, $f[1]);
        } elseif ($f[0] === self::STATE_CANCELLED) {
            self::settleInternal($newId, self::STATE_CANCELLED, null);
        } else {
            // Source pending — register dependent.
            self::$futures[$id][2][] = ['then', $newId, $fn];
        }
        return $newId;
    }

    /**
     * Java's CompletableFuture.allOf — a new Future that fulfills
     * when every input Future fulfills (or rejects on first rejection).
     */
    public static function allOf(int ...$ids): int
    {
        $newId = self::$nextId++;
        self::$futures[$newId] = [self::STATE_PENDING, null, []];

        if (empty($ids)) {
            self::settleInternal($newId, self::STATE_FULFILLED, []);
            return $newId;
        }

        // Ref-cell shared across all dependents — counts how many sources
        // remain pending and accumulates results.
        $remaining = \count($ids);
        $results = \array_fill(0, $remaining, null);
        $box = ['remaining' => $remaining, 'results' => $results];

        foreach ($ids as $i => $srcId) {
            $f = self::$futures[$srcId];
            if ($f[0] === self::STATE_FULFILLED) {
                $box['results'][$i] = $f[1];
                $box['remaining']--;
                if ($box['remaining'] === 0) {
                    self::settleInternal($newId, self::STATE_FULFILLED, $box['results']);
                    return $newId;
                }
            } elseif ($f[0] === self::STATE_REJECTED) {
                self::settleInternal($newId, self::STATE_REJECTED, $f[1]);
                return $newId;
            } elseif ($f[0] === self::STATE_CANCELLED) {
                self::settleInternal($newId, self::STATE_CANCELLED, null);
                return $newId;
            } else {
                self::$futures[$srcId][2][] = ['allOf', $newId, $i, &$box];
            }
        }
        return $newId;
    }

    /**
     * Java's CompletableFuture.anyOf — a new Future that fulfills with
     * the value of the first input to fulfill (rejects on first reject).
     */
    public static function anyOf(int ...$ids): int
    {
        $newId = self::$nextId++;
        self::$futures[$newId] = [self::STATE_PENDING, null, []];

        if (empty($ids)) {
            // Java's CF.anyOf(empty) returns a never-completing Future;
            // we approximate by leaving it pending. Caller must cancel
            // or the await() would hang.
            return $newId;
        }

        foreach ($ids as $srcId) {
            $f = self::$futures[$srcId];
            if ($f[0] === self::STATE_FULFILLED) {
                self::settleInternal($newId, self::STATE_FULFILLED, $f[1]);
                return $newId;
            } elseif ($f[0] === self::STATE_REJECTED) {
                self::settleInternal($newId, self::STATE_REJECTED, $f[1]);
                return $newId;
            }
            // CANCELLED counts as "didn't win" — keep waiting on others
            if ($f[0] === self::STATE_PENDING) {
                self::$futures[$srcId][2][] = ['anyOf', $newId];
            }
        }
        // If we got here all sources were cancelled; propagate.
        if (self::$futures[$newId][0] === self::STATE_PENDING) {
            $allCancelled = true;
            foreach ($ids as $srcId) {
                if (self::$futures[$srcId][0] !== self::STATE_CANCELLED) {
                    $allCancelled = false;
                    break;
                }
            }
            if ($allCancelled) {
                self::settleInternal($newId, self::STATE_CANCELLED, null);
            }
        }
        return $newId;
    }

    /**
     * Internal settle — moves $id from PENDING to a terminal state and
     * fires dependent triggers. Direct property writes; no virtual
     * dispatch; the static-prop access cost is amortised across the
     * fire-dependents fanout.
     */
    private static function settleInternal(int $id, int $state, mixed $value): void
    {
        $f = self::$futures[$id];
        if ($f[0] !== self::STATE_PENDING) return; // already settled
        self::$futures[$id] = [$state, $value, []];

        // Fire dependents. Each dependent is a trigger callback that
        // we push to the queue with sentinel id -1 so the main drain
        // loop runs it without trying to settle a Future from it.
        foreach ($f[2] as $dep) {
            switch ($dep[0]) {
                case 'then':
                    [, $newId, $fn] = $dep;
                    if ($state === self::STATE_FULFILLED) {
                        // Schedule fn(value) → settles newId.
                        $sp = self::$qsp;
                        self::$queue[$sp] = static fn() => $fn($value);
                        self::$qids[$sp] = $newId;
                        self::$qsp = $sp + 1;
                    } else {
                        self::settleInternal($newId, $state, $value);
                    }
                    break;

                case 'allOf':
                    [, $newId, $idx, &$box] = $dep;
                    if ($state !== self::STATE_FULFILLED) {
                        // First non-fulfill propagates immediately.
                        if (self::$futures[$newId][0] === self::STATE_PENDING) {
                            self::settleInternal($newId, $state, $value);
                        }
                    } else {
                        $box['results'][$idx] = $value;
                        $box['remaining']--;
                        if ($box['remaining'] === 0
                            && self::$futures[$newId][0] === self::STATE_PENDING) {
                            self::settleInternal($newId, self::STATE_FULFILLED, $box['results']);
                        }
                    }
                    break;

                case 'anyOf':
                    [, $newId] = $dep;
                    if (self::$futures[$newId][0] === self::STATE_PENDING) {
                        if ($state !== self::STATE_CANCELLED) {
                            self::settleInternal($newId, $state, $value);
                        }
                        // Cancellation alone doesn't settle anyOf; we
                        // wait to see if another source settles first.
                    }
                    break;
            }
        }
    }

    /** Reset all runtime state. For tests + bench harnesses. */
    public static function reset(): void
    {
        self::$futures = [];
        self::$queue = [];
        self::$qids = [];
        self::$nextId = 0;
        self::$qsp = 0;
        self::$qhead = 0;
    }
}


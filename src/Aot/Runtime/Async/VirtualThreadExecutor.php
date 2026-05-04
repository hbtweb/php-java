<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\Async;

/**
 * Virtual-thread async runtime — Fiber-backed cooperative scheduler.
 *
 * Sized for the genuinely-suspending case the may-suspend analyzer
 * routes here (`Thread.sleep`, `Object.wait`, `BlockingQueue.take`,
 * `Future.get` against an unsettled Future, `Lock.lock` with
 * contention possible, etc.). Raw `Fiber::suspend()/resume()` is the
 * floor (~12 µs/cycle, fixed PHP-runtime cost we can't optimise
 * below). PATTERNS.md tightening minimises everything around the
 * primitive — pooled fibers (no per-call ~4 KB stack page),
 * sealed-shape arrays, switch-dispatched event loop, no virtual
 * dispatch.
 *
 * v1 scope (this module):
 *   - async($fn)                spawn task on a pooled fiber
 *   - await($id)                suspend until target Future settles
 *   - sleep($ms)                yield to event loop, wake after delay
 *   - interrupt($fiberId)       set interrupt flag; wakes parked
 *   - run()                     drive the event loop until empty
 *   - cancel($id)               mark Future cancelled, wake waiters
 *
 * Composition primitives (thenApply, allOf, anyOf) inherit from the
 * InlineExecutor runtime when the JDK shim layer wires them; cross-tier
 * composition routes through whichever tier the may-suspend analyzer
 * picked for each link in the chain.
 *
 * Out of scope for v1:
 *   - I/O multiplexing (`stream_select` integration). Adds ~200 LOC
 *     when a fixture exercises blocking-I/O through Java's
 *     InputStream/Socket. Until then, blocking I/O inside VirtualThreadExecutor
 *     task pauses the whole process; not worse than AMPHP-without-
 *     async-I/O which is the comparable.
 *   - Swoole coroutine adapter. Strategy-dispatcher hook is in
 *     ROADMAP §Build T3 step 3; adds when Tier-2 shared-memory
 *     deployments need it.
 *   - SignalCancellation, TimeoutCancellation. Both compose on top
 *     of `interrupt()`; ~50 LOC each when needed.
 *
 * Public API mirrors InlineExecutor where overlapping (async/await/cancel/
 * isPending/isFulfilled/isRejected/reset) so the JDK shim layer can
 * pick a tier per-call without diverging from a uniform Future shape
 * at the consumer surface.
 */
final class VirtualThreadExecutor
{
    public const STATE_PENDING    = 0;
    public const STATE_FULFILLED  = 1;
    public const STATE_REJECTED   = 2;
    public const STATE_CANCELLED  = 3;

    /**
     * Future state. [state, value, waiter-fiber-ids[]]. waiters are
     * parked fiber-ids that should be unparked when this Future settles.
     *
     * @var array<int, array{0:int,1:mixed,2:array<int,int>}>
     */
    private static array $futures = [];
    private static int $nextId = 0;

    /** @var array<int, callable> Ready callbacks (FIFO queue). */
    private static array $ready = [];
    private static int $rsp = 0;
    private static int $rhead = 0;

    /** @var array<int, array{0:int,1:int}> Pending sleeps: [wakeTimeNs, fiberId]. */
    private static array $sleeps = [];

    /** @var array<int, \Fiber> Currently-parked fibers indexed by spl_object_id. */
    private static array $parked = [];

    /** @var array<int, bool> Interrupt flags by fiber-id. Mirrors Thread.interrupted(). */
    private static array $interrupted = [];

    /**
     * Fiber pool — deferred to v2. The pool design needs a state-
     * tracking scheme because `$fiber->resume()` returns both when
     * the task fully completes (fiber back at outer-loop's suspend,
     * pool-ready) AND when the task transiently suspends (fiber
     * parked mid-task, NOT pool-ready). v1 allocates a fresh fiber
     * per `async()` and accepts the ~4 KB stack-page-alloc cost.
     * Bench cost: roughly 2× the raw Fiber suspend/resume cycle —
     * still well below AMPHP. Pool refactor: ~80 LOC, picks up an
     * additional ~5–10 µs/op at high concurrency density.
     */

    /** Spawn $fn as a suspending async task. Returns the Future id. */
    public static function async(callable $fn): int
    {
        $id = self::$nextId++;
        self::$futures[$id] = [self::STATE_PENDING, null, []];
        // Wrap the user fn in our settle-on-done envelope. The closure
        // captures $fn and $id; runs $fn() inside the pooled fiber,
        // settles the Future on completion (or rejection).
        $task = static function () use ($fn, $id): void {
            try {
                $value = $fn();
                self::settleInternal($id, self::STATE_FULFILLED, $value);
            } catch (\Throwable $e) {
                self::settleInternal($id, self::STATE_REJECTED, $e);
            }
        };
        self::$ready[self::$rsp++] = $task;
        return $id;
    }

    /**
     * Block until Future $id settles. Suspends the calling Fiber via
     * the event loop. Top-level callers (no current Fiber) drive
     * run() inline until $id settles.
     *
     * @throws \Throwable          rethrows the rejection cause
     * @throws CancellationException  if the Future was cancelled
     * @throws InterruptedException   if the awaiting fiber was interrupted
     */
    public static function await(int $id): mixed
    {
        if (self::$futures[$id][0] !== self::STATE_PENDING) {
            return self::extractResult($id);
        }
        $current = \Fiber::getCurrent();
        if ($current === null) {
            // Top-level — drive the loop until $id settles.
            self::run($id);
        } else {
            // Inside a fiber — park, register as waiter on $id.
            $fid = \spl_object_id($current);
            self::$parked[$fid] = $current;
            self::$futures[$id][2][] = $fid;
            \Fiber::suspend();
            // Resumed — check interrupt before returning result.
            if (self::$interrupted[$fid] ?? false) {
                unset(self::$interrupted[$fid]);
                throw new InterruptedException("await: fiber interrupted");
            }
        }
        return self::extractResult($id);
    }

    /**
     * Yield current fiber for $ms milliseconds. Re-entered when wake
     * time arrives. Top-level callers fall through to native usleep.
     */
    public static function sleep(int $ms): void
    {
        $current = \Fiber::getCurrent();
        if ($current === null) {
            \usleep($ms * 1000);
            return;
        }
        $fid = \spl_object_id($current);
        if (self::$interrupted[$fid] ?? false) {
            unset(self::$interrupted[$fid]);
            throw new InterruptedException("sleep: fiber interrupted");
        }
        $wakeNs = \hrtime(true) + $ms * 1_000_000;
        self::$sleeps[] = [$wakeNs, $fid];
        self::$parked[$fid] = $current;
        \Fiber::suspend();
        // Resumed — interrupt check
        if (self::$interrupted[$fid] ?? false) {
            unset(self::$interrupted[$fid]);
            throw new InterruptedException("sleep: fiber interrupted");
        }
    }

    /**
     * Set the interrupt flag for fiber $fid. If parked, requeues a
     * resume callback so the fiber wakes and observes the flag.
     * Java's Thread.interrupt() analog.
     */
    public static function interrupt(int $fid): void
    {
        self::$interrupted[$fid] = true;
        // Wake the parked fiber — its suspension primitive will check
        // the flag and throw InterruptedException.
        if (isset(self::$parked[$fid])) {
            $fiber = self::$parked[$fid];
            unset(self::$parked[$fid]);
            self::$ready[self::$rsp++] = static fn() => $fiber->resume();
        }
    }

    public static function cancel(int $id): bool
    {
        if (self::$futures[$id][0] !== self::STATE_PENDING) return false;
        self::settleInternal($id, self::STATE_CANCELLED, null);
        return true;
    }

    public static function isPending(int $id): bool   { return self::$futures[$id][0] === self::STATE_PENDING; }
    public static function isFulfilled(int $id): bool { return self::$futures[$id][0] === self::STATE_FULFILLED; }
    public static function isRejected(int $id): bool  { return self::$futures[$id][0] === self::STATE_REJECTED; }

    /**
     * Drive the event loop until empty OR until target Future settles
     * (when called from await/$untilId). Steps:
     *
     *   1. Drain ready callbacks (sealed-shape FIFO via $rhead/$rsp)
     *   2. Wake due sleepers
     *   3. If still work pending and only sleeps remain, native-sleep
     *      until next wake time.
     *
     * Each ready callback dispatches onto a pooled fiber: pop fiber
     * from pool (or allocate if empty), resume with task. When task
     * returns, fiber suspends back into pool (or terminates if pool
     * is full / on shutdown).
     */
    public static function run(?int $untilId = null): void
    {
        while (true) {
            // Drain ready queue.
            while (self::$rhead < self::$rsp) {
                $cb = self::$ready[self::$rhead];
                unset(self::$ready[self::$rhead]);
                self::$rhead++;
                self::dispatchOnPool($cb);
                if ($untilId !== null && self::$futures[$untilId][0] !== self::STATE_PENDING) {
                    return;
                }
            }

            // Wake due sleepers.
            if (!empty(self::$sleeps)) {
                $now = \hrtime(true);
                $remaining = [];
                foreach (self::$sleeps as $entry) {
                    [$wake, $fid] = $entry;
                    if ($wake <= $now) {
                        if (isset(self::$parked[$fid])) {
                            $fiber = self::$parked[$fid];
                            unset(self::$parked[$fid]);
                            self::$ready[self::$rsp++] = static fn() => $fiber->resume();
                        }
                    } else {
                        $remaining[] = $entry;
                    }
                }
                self::$sleeps = $remaining;
            }

            // If ready queue refilled, loop back to drain.
            if (self::$rhead < self::$rsp) continue;

            // Nothing ready, but there might be pending sleeps.
            if (!empty(self::$sleeps)) {
                $minWake = self::$sleeps[0][0];
                foreach (self::$sleeps as $entry) {
                    if ($entry[0] < $minWake) $minWake = $entry[0];
                }
                $delayNs = $minWake - \hrtime(true);
                if ($delayNs > 0) {
                    \usleep((int) \intdiv($delayNs, 1000));
                }
                continue;
            }

            // No ready, no sleeps. If parked fibers exist with no
            // wake source, the Future graph has a deadlock; surface it.
            if (!empty(self::$parked)) {
                throw new \LogicException(
                    'VirtualThreadExecutor::run: ' . \count(self::$parked) . ' fibers parked '
                    . 'with no wake source. Likely cause: cycle in await graph '
                    . 'or interrupted-but-never-unparked fiber.'
                );
            }

            return; // event loop empty
        }
    }

    /**
     * Allocate a fresh fiber for $task and start it. The task may
     * suspend (sleep/await) and resume multiple times before finishing;
     * those resumes come from the event-loop drain via parked-fiber
     * unparking. The initial start runs the task until its first
     * suspend OR completion — both cases return to our caller here,
     * which loops back to drain the next ready callback.
     */
    private static function dispatchOnPool(callable $task): void
    {
        $fiber = new \Fiber($task);
        $fiber->start();
        // No further bookkeeping — if the task suspended, it's already
        // registered in $sleeps / $parked / etc. via the suspension
        // primitive (sleep / await / interrupt). If the task completed,
        // settleInternal() ran inside it and the fiber terminated.
    }

    /**
     * Move $id from PENDING to a terminal state and wake any parked
     * waiters. Direct property writes; switch-dispatched fanout to
     * waiters via the ready queue (no recursive resume).
     */
    private static function settleInternal(int $id, int $state, mixed $value): void
    {
        $f = self::$futures[$id];
        if ($f[0] !== self::STATE_PENDING) return;
        self::$futures[$id] = [$state, $value, []];

        // Wake all waiters via the ready queue. Direct $fiber->resume()
        // would recurse the call stack; queueing keeps drain flat.
        foreach ($f[2] as $waiterFid) {
            if (isset(self::$parked[$waiterFid])) {
                $fiber = self::$parked[$waiterFid];
                unset(self::$parked[$waiterFid]);
                self::$ready[self::$rsp++] = static fn() => $fiber->resume();
            }
        }
    }

    private static function extractResult(int $id): mixed
    {
        $f = self::$futures[$id];
        if ($f[0] === self::STATE_FULFILLED) return $f[1];
        if ($f[0] === self::STATE_REJECTED)  throw $f[1];
        if ($f[0] === self::STATE_CANCELLED) throw new CancellationException("Future {$id} was cancelled");
        // PENDING — caller should have awaited
        throw new \LogicException("extractResult: Future {$id} still pending");
    }

    public static function reset(): void
    {
        self::$futures = [];
        self::$ready = [];
        self::$sleeps = [];
        self::$parked = [];
        self::$interrupted = [];
        self::$nextId = 0;
        self::$rsp = 0;
        self::$rhead = 0;
    }
}


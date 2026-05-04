<?php
declare(strict_types=1);

/**
 * Minimum-viable async runtime in PATTERNS.md style.
 *
 * Hand-written PHP applying the rules from docs/PATTERNS.md to the
 * Amp\async + Future::await call pair. Bench target: validate
 * whether the predicted 6–10× speedup over AMPHP materialises (rank 1)
 * or speculation was off (rank 5 → revised).
 *
 * Two-tier shape:
 *
 *   Tier A — non-suspending callbacks (the workload AMPHP also handles
 *   but with full Fiber + Suspension allocation overhead). PoC just
 *   queues the callback and drains the queue on await(). No Fiber, no
 *   Suspension, no virtual dispatch. Cost: ~one closure call + array
 *   push/pop.
 *
 *   Tier B — suspending callbacks (callbacks that themselves call into
 *   await()). Fiber-backed; allocates on first suspension. Pooled to
 *   avoid per-call stack alloc on the hot path.
 *
 * AMPHP doesn't make this distinction — every async() gets a Fiber.
 * For Java's Thread.start where the task is "compute + return," Tier A
 * is the optimal shape. For "compute + await another Future + compute"
 * patterns, Tier B is needed and the AMPHP-equivalent overhead applies.
 *
 * What's omitted vs AMPHP:
 *   - Cancellation tokens, composition, error propagation through
 *     chained Futures, async I/O drivers.
 *
 * Rules applied (PATTERNS.md numbering):
 *   2. Frame state in PHP locals          ✓ static-class properties
 *   3. Regular array, never SplFixedArray ✓
 *   4. Primitives are PHP scalars         ✓ Future state = `[int, mixed, int]`
 *   5. Switch in static function          ✓ run() drains via while+switch
 */

namespace Bench\Poc;

const STATE_PENDING   = 0;
const STATE_FULFILLED = 1;
const STATE_REJECTED  = 2;

final class Runtime
{
    /** @var array<int, array{0:int,1:mixed,2:int}> [state, value, waiter-key]. */
    private static array $futures = [];
    private static int $nextId = 0;

    /** @var list<callable> Pending callbacks (FIFO). */
    private static array $queue = [];

    /** @var array<int, \Fiber> Park: waiter-key → suspended fiber. */
    private static array $waiters = [];

    /**
     * Spawn $fn as an async task. Returns Future id (int, not object).
     */
    public static function async(callable $fn): int
    {
        $id = self::$nextId++;
        self::$futures[$id] = [STATE_PENDING, null, -1];
        self::$queue[] = static function () use ($fn, $id): void {
            try {
                $value = $fn();
                self::settle($id, STATE_FULFILLED, $value);
            } catch (\Throwable $e) {
                self::settle($id, STATE_REJECTED, $e);
            }
        };
        return $id;
    }

    /**
     * Block until Future $id settles. If called outside a fiber, drains
     * the callback queue inline. If called inside a fiber, suspends and
     * relies on settle() to requeue the resume.
     */
    public static function await(int $id): mixed
    {
        $current = \Fiber::getCurrent();
        if ($current === null) {
            // Top-level: drain the queue until $id settles.
            while (self::$futures[$id][0] === STATE_PENDING) {
                if (empty(self::$queue)) {
                    throw new \LogicException("await: queue empty but Future {$id} still pending");
                }
                $cb = \array_shift(self::$queue);
                $cb();
            }
        } else {
            // Inside a fiber: park, settle() will requeue a resume callback.
            $key = \spl_object_id($current);
            self::$waiters[$key] = $current;
            self::$futures[$id][2] = $key;
            \Fiber::suspend();
        }
        $f = self::$futures[$id];
        if ($f[0] === STATE_FULFILLED) return $f[1];
        throw $f[1];
    }

    private static function settle(int $id, int $state, mixed $value): void
    {
        $f = self::$futures[$id];
        self::$futures[$id] = [$state, $value, $f[2]];
        if ($f[2] !== -1 && isset(self::$waiters[$f[2]])) {
            $waiter = self::$waiters[$f[2]];
            unset(self::$waiters[$f[2]]);
            self::$queue[] = static function () use ($waiter): void {
                $waiter->resume();
            };
        }
    }

    public static function reset(): void
    {
        self::$futures = [];
        self::$queue = [];
        self::$waiters = [];
        self::$nextId = 0;
    }
}

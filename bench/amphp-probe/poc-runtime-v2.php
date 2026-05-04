<?php
declare(strict_types=1);

/**
 * PoC v2 — same shape as v1, three targeted optimisations:
 *
 *   1. Drop the wrapper closure. v1 stored a `fn() use ($fn, $id)` per
 *      async() — closure alloc + invocation per call. v2 stores $fn
 *      and $id in parallel arrays; the run loop does try/catch +
 *      settle inline without the wrapper.
 *
 *   2. Inline settle() into the run loop. v1 had a separate static
 *      method (~22× cost over inlined per JIT-claims battery).
 *
 *   3. SP index for queue. v1 used array_shift (O(n) plus 29 ns
 *      per call); v2 uses $qhead++ which is O(1) and ~1 ns.
 *
 * Same Tier-A-only semantics — non-suspending tasks. Tier B (real
 * Fiber path for tasks that suspend) folds in once we want a fair
 * fight with AMPHP for inter-thread sync workloads.
 */

namespace Bench\Poc\V2;

const STATE_PENDING   = 0;
const STATE_FULFILLED = 1;
const STATE_REJECTED  = 2;

final class Runtime
{
    /** @var array<int, array{0:int,1:mixed,2:int}> [state, value, waiter-key]. */
    private static array $futures = [];
    private static int $nextId = 0;

    /** @var array<int, callable> Pending callables (the $fn directly, no wrapper). */
    private static array $queue = [];
    /** @var array<int, int> Parallel: which Future id each callable settles. */
    private static array $qids = [];
    private static int $qsp = 0;
    private static int $qhead = 0;

    /** @var array<int, \Fiber> waiter-key → suspended fiber. */
    private static array $waiters = [];

    public static function async(callable $fn): int
    {
        $id = self::$nextId++;
        self::$futures[$id] = [STATE_PENDING, null, -1];
        $sp = self::$qsp;
        self::$queue[$sp] = $fn;
        self::$qids[$sp] = $id;
        self::$qsp = $sp + 1;
        return $id;
    }

    public static function await(int $id): mixed
    {
        $current = \Fiber::getCurrent();
        if ($current === null) {
            // Top-level drain. Inlined settle: no static call.
            while (self::$futures[$id][0] === STATE_PENDING) {
                $h = self::$qhead;
                if ($h >= self::$qsp) {
                    throw new \LogicException("await: queue empty but Future {$id} still pending");
                }
                $fn = self::$queue[$h];
                $cbId = self::$qids[$h];
                self::$qhead = $h + 1;
                // Inline settle: try/catch + state write here.
                try {
                    $value = $fn();
                    self::$futures[$cbId] = [STATE_FULFILLED, $value, self::$futures[$cbId][2]];
                } catch (\Throwable $e) {
                    self::$futures[$cbId] = [STATE_REJECTED, $e, self::$futures[$cbId][2]];
                }
                // Inline waiter requeue (rare on Tier A — no-op for the bench).
                $waiterKey = self::$futures[$cbId][2];
                if ($waiterKey !== -1 && isset(self::$waiters[$waiterKey])) {
                    $w = self::$waiters[$waiterKey];
                    unset(self::$waiters[$waiterKey]);
                    $sp = self::$qsp;
                    self::$queue[$sp] = static function () use ($w): void { $w->resume(); };
                    self::$qids[$sp] = -1; // sentinel: no Future to settle on this one
                    self::$qsp = $sp + 1;
                }
            }
        } else {
            $key = \spl_object_id($current);
            self::$waiters[$key] = $current;
            self::$futures[$id][2] = $key;
            \Fiber::suspend();
        }
        $f = self::$futures[$id];
        if ($f[0] === STATE_FULFILLED) return $f[1];
        throw $f[1];
    }

    public static function reset(): void
    {
        self::$futures = [];
        self::$queue = [];
        self::$qids = [];
        self::$waiters = [];
        self::$nextId = 0;
        self::$qsp = 0;
        self::$qhead = 0;
    }
}

<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;

/**
 * java.util.concurrent.ScheduledThreadPoolExecutor — concrete
 * ScheduledExecutorService routing every schedule through VTE's
 * scheduleAfter primitive.
 *
 * scheduleAtFixedRate semantics: catch-up firings when task runs
 * long. Implemented as: each firing schedules the next at
 * initialDelay + N*period (in absolute time).
 *
 * scheduleWithFixedDelay semantics: next firing is task-end + delay.
 * Implemented as: schedule the next firing from inside the task
 * after it completes.
 */
class ScheduledThreadPoolExecutor extends ThreadPoolExecutor implements ScheduledExecutorService
{
    public function __construct(int $corePoolSize = 1)
    {
        parent::__construct($corePoolSize, $corePoolSize);
    }

    public function schedule(callable $task, int $delay, TimeUnit $unit): ScheduledFuture
    {
        $delayMs = $unit->toMillis($delay);
        $scheduledAtNs = \hrtime(true) + $delayMs * 1_000_000;
        $futureId = VirtualThreadExecutor::scheduleAfter($delayMs, $task);
        return new ScheduledFutureImpl($futureId, $scheduledAtNs);
    }

    public function scheduleAtFixedRate(callable $task, int $initialDelay, int $period, TimeUnit $unit): ScheduledFuture
    {
        $initialMs = $unit->toMillis($initialDelay);
        $periodMs = $unit->toMillis($period);
        $scheduledAtNs = \hrtime(true) + $initialMs * 1_000_000;

        // Periodic loop: a single async fiber that sleeps the initial
        // delay, runs the task, then loops sleeping for $periodMs each
        // iteration. Cancellation: the future-id can be cancelled via
        // VirtualThreadExecutor::cancel which interrupts the sleep.
        $futureId = VirtualThreadExecutor::async(static function () use ($task, $initialMs, $periodMs) {
            VirtualThreadExecutor::sleep($initialMs);
            $nextFiringNs = \hrtime(true);
            while (true) {
                $task();
                $nextFiringNs += $periodMs * 1_000_000;
                $delayNs = $nextFiringNs - \hrtime(true);
                if ($delayNs > 0) {
                    VirtualThreadExecutor::sleep((int) \intdiv($delayNs, 1_000_000));
                }
                // catch-up: if task ran longer than period, no sleep —
                // fire again immediately.
            }
        });
        return new ScheduledFutureImpl($futureId, $scheduledAtNs);
    }

    public function scheduleWithFixedDelay(callable $task, int $initialDelay, int $delay, TimeUnit $unit): ScheduledFuture
    {
        $initialMs = $unit->toMillis($initialDelay);
        $delayMs = $unit->toMillis($delay);
        $scheduledAtNs = \hrtime(true) + $initialMs * 1_000_000;

        $futureId = VirtualThreadExecutor::async(static function () use ($task, $initialMs, $delayMs) {
            VirtualThreadExecutor::sleep($initialMs);
            while (true) {
                $task();
                VirtualThreadExecutor::sleep($delayMs);
            }
        });
        return new ScheduledFutureImpl($futureId, $scheduledAtNs);
    }
}

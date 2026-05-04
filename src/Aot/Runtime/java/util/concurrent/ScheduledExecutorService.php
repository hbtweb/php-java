<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.ScheduledExecutorService — extends
 * ExecutorService with delayed and periodic task scheduling.
 *
 * Three forms:
 *   - schedule(task, delay, unit) — single-shot delayed
 *   - scheduleAtFixedRate(task, initialDelay, period, unit) —
 *     periodic; next firing at initialDelay + N*period regardless
 *     of task duration. Catch-up firings if task runs long.
 *   - scheduleWithFixedDelay(task, initialDelay, delay, unit) —
 *     periodic; next firing at task-end + delay. No catch-up.
 *
 * Implementation: ScheduledThreadPoolExecutor.
 */
interface ScheduledExecutorService extends ExecutorService
{
    public function schedule(callable $task, int $delay, TimeUnit $unit): ScheduledFuture;
    public function scheduleAtFixedRate(callable $task, int $initialDelay, int $period, TimeUnit $unit): ScheduledFuture;
    public function scheduleWithFixedDelay(callable $task, int $initialDelay, int $delay, TimeUnit $unit): ScheduledFuture;
}

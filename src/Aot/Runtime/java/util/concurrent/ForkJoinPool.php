<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;

/**
 * java.util.concurrent.ForkJoinPool — work-stealing executor for
 * divide-and-conquer parallelism.
 *
 * Java's reference impl uses per-worker double-ended queues with
 * work-stealing. Under PHP cooperative scheduling there's exactly
 * one carrier thread (the PHP process), so work-stealing has no
 * parallel-execution benefit. This shim aliases ThreadPoolExecutor
 * semantics — ForkJoinTask.fork submits to VTE, ForkJoinTask.join
 * blocks on the resulting future.
 *
 * The compute-heavy semantics (CPU-bound divide-and-conquer)
 * collapse on a single carrier thread; the I/O semantics (sleep,
 * wait, yield) work identically because they all route through the
 * same VTE event loop.
 */
class ForkJoinPool extends ThreadPoolExecutor
{
    private static ?self $commonPool = null;

    public function __construct(int $parallelism = 0)
    {
        parent::__construct(\max($parallelism, 1), \PHP_INT_MAX);
    }

    /** Java 7+: ForkJoinPool.commonPool() — shared singleton. */
    public static function commonPool(): self
    {
        return self::$commonPool ??= new self();
    }

    public function getParallelism(): int { return $this->getCorePoolSize(); }

    /** Java 8+: invoke (ForkJoinTask) — submit + wait. */
    public function invoke(ForkJoinTask $task): mixed
    {
        $this->submitTask($task);
        return $task->join();
    }

    public function submitTask(ForkJoinTask $task): ForkJoinTask
    {
        $task->_setPool($this);
        $task->_schedule();
        return $task;
    }

    /** Helper: invoke commonPool tasks without explicit pool reference. */
    public static function invokeAllCommon(array $tasks): array
    {
        $pool = self::commonPool();
        foreach ($tasks as $t) $pool->submitTask($t);
        $results = [];
        foreach ($tasks as $t) $results[] = $t->join();
        return $results;
    }
}

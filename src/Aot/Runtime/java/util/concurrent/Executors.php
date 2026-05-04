<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.Executors — static factories for
 * ExecutorService instances. All factories return ThreadPoolExecutor
 * variants with different sizing parameters.
 *
 * Under PHP cooperative scheduling all "thread pools" share one
 * carrier thread (the PHP process). Pool sizing is observable as
 * backpressure / submit-rejection rather than as parallel-execution
 * width. Factories preserve Java's API signatures so user code
 * compiles unchanged; the perf-class differences between pool
 * variants do not apply.
 */
final class Executors
{
    private function __construct() {}

    public static function newFixedThreadPool(int $nThreads): ExecutorService
    {
        return new ThreadPoolExecutor($nThreads, $nThreads);
    }

    public static function newCachedThreadPool(): ExecutorService
    {
        return new ThreadPoolExecutor(0, \PHP_INT_MAX, 60_000);
    }

    public static function newSingleThreadExecutor(): ExecutorService
    {
        return new ThreadPoolExecutor(1, 1);
    }

    /** Java 21+: virtual-thread-per-task. PHP Fibers are virtual threads. */
    public static function newVirtualThreadPerTaskExecutor(): ExecutorService
    {
        return new ThreadPoolExecutor(0, \PHP_INT_MAX);
    }

    public static function newWorkStealingPool(int $parallelism = 0): ExecutorService
    {
        // Cooperative scheduling provides no parallelism; this returns
        // a regular ThreadPoolExecutor. Work-stealing semantics are
        // observably equivalent on a single carrier thread.
        return new ThreadPoolExecutor($parallelism ?: 1, \PHP_INT_MAX);
    }

    public static function newThreadPerTaskExecutor(?ThreadFactory $threadFactory = null): ExecutorService
    {
        return new ThreadPoolExecutor(0, \PHP_INT_MAX, 60_000, null, $threadFactory);
    }

    public static function defaultThreadFactory(): ThreadFactory
    {
        return new DefaultThreadFactory();
    }

    /** Java: callable(Runnable, T) — adapter from Runnable + result to Callable<T>. */
    public static function callable(callable $task, mixed $result = null): callable
    {
        return static function () use ($task, $result) {
            $task();
            return $result;
        };
    }

    public static function newScheduledThreadPool(int $corePoolSize): ScheduledExecutorService
    {
        return new ScheduledThreadPoolExecutor($corePoolSize);
    }

    public static function newSingleThreadScheduledExecutor(): ScheduledExecutorService
    {
        return new ScheduledThreadPoolExecutor(1);
    }
}

<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.ExecutorService — Executor with lifecycle
 * management. Adds shutdown, awaitTermination, submit (returning
 * Future), invokeAll, invokeAny.
 *
 * Implementations:
 *   - ThreadPoolExecutor (this module) — VTE-backed cooperative
 *     scheduler with a fiber pool.
 *   - ForkJoinPool — work-stealing variant for divide-and-conquer
 *     parallelism. Same VTE backing.
 *
 * Static factories live in Executors:
 *   - newCachedThreadPool, newFixedThreadPool, newSingleThreadExecutor,
 *   - newScheduledThreadPool, newWorkStealingPool, newVirtualThreadPerTaskExecutor.
 */
interface ExecutorService extends Executor
{
    public function shutdown(): void;
    public function shutdownNow(): array;
    public function isShutdown(): bool;
    public function isTerminated(): bool;
    public function awaitTermination(int $timeoutMillis): bool;
    public function submit(callable $task): Future;
    public function invokeAll(array $tasks): array;
    public function invokeAny(array $tasks): mixed;
    public function close(): void;
}

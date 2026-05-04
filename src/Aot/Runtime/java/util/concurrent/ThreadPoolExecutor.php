<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;

/**
 * java.util.concurrent.ThreadPoolExecutor — concrete ExecutorService
 * with configurable pool sizing. Java's reference implementation
 * has corePoolSize, maximumPoolSize, keepAliveTime, work queue, and
 * rejection policy.
 *
 * v1 implementation: routes every submit through VTE. The "pool"
 * concept doesn't constrain concurrency in PHP's single-threaded
 * cooperative model — there's only one carrier thread (the PHP
 * process), and all virtual threads (Fibers) run on it. Pool
 * sizing is only relevant if we want to throttle the number of
 * pending fibers (backpressure) or to bound memory usage.
 *
 * Constructor params accepted but most are observably no-ops in
 * the cooperative single-thread model. corePoolSize / maxPoolSize
 * are documented limits only; any submit always proceeds because
 * VTE queues unboundedly. Throttling can be added if a fixture
 * needs it (~30 LOC: track inflight count, BlockingQueue-style
 * park on excess submits).
 */
class ThreadPoolExecutor implements ExecutorService
{
    private bool $shutdown = false;
    private bool $terminated = false;
    /** @var int[] Pending future ids (track for awaitTermination). */
    private array $pending = [];

    public function __construct(
        private int $corePoolSize = 1,
        private int $maximumPoolSize = \PHP_INT_MAX,
        private int $keepAliveTimeMillis = 60_000,
        private ?BlockingQueue $workQueue = null,
        private ?ThreadFactory $threadFactory = null
    ) {}

    public function execute(callable $command): void
    {
        if ($this->shutdown) {
            throw new \PHPJava\Packages\java\lang\IllegalStateException('Executor shut down');
        }
        $this->pending[] = VirtualThreadExecutor::async($command);
    }

    public function submit(callable $task): Future
    {
        if ($this->shutdown) {
            throw new \PHPJava\Packages\java\lang\IllegalStateException('Executor shut down');
        }
        $id = VirtualThreadExecutor::async($task);
        $this->pending[] = $id;
        return new ConcreteFuture(ConcreteFuture::BACKEND_VTE, $id);
    }

    public function invokeAll(array $tasks): array
    {
        $futures = [];
        foreach ($tasks as $task) {
            $futures[] = $this->submit($task);
        }
        // Block until every future is done
        foreach ($futures as $f) {
            try { $f->get(); } catch (\Throwable) { /* preserve future state */ }
        }
        return $futures;
    }

    public function invokeAny(array $tasks): mixed
    {
        if (empty($tasks)) {
            throw new \PHPJava\Packages\java\lang\IllegalArgumentException('tasks empty');
        }
        $futures = [];
        foreach ($tasks as $task) {
            $futures[] = $this->submit($task);
        }
        $lastException = null;
        foreach ($futures as $f) {
            try {
                $value = $f->get();
                // Cancel rest
                foreach ($futures as $other) {
                    if ($other !== $f) $other->cancel(true);
                }
                return $value;
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }
        throw new ExecutionException(
            'All tasks failed; last cause: ' . ($lastException?->getMessage() ?? 'none'),
            0,
            $lastException
        );
    }

    public function shutdown(): void { $this->shutdown = true; }

    public function shutdownNow(): array
    {
        $this->shutdown = true;
        // Cancel all pending — return list of cancelled tasks (Runnables).
        // We don't have task references, so return empty list.
        foreach ($this->pending as $id) {
            VirtualThreadExecutor::cancel($id);
        }
        return [];
    }

    public function isShutdown(): bool { return $this->shutdown; }
    public function isTerminated(): bool { return $this->terminated; }

    public function awaitTermination(int $timeoutMillis): bool
    {
        if (!$this->shutdown) return false;
        // Drive VTE event loop until all pending are done. Timeout
        // not honoured in v1 (VTE has no timer wakeup yet that we
        // expose); blocks indefinitely instead.
        VirtualThreadExecutor::run();
        $this->terminated = true;
        return true;
    }

    public function close(): void
    {
        $this->shutdown();
        $this->awaitTermination(\PHP_INT_MAX);
    }

    public function getCorePoolSize(): int { return $this->corePoolSize; }
    public function getMaximumPoolSize(): int { return $this->maximumPoolSize; }
    public function getKeepAliveTime(): int { return $this->keepAliveTimeMillis; }
    public function getQueue(): ?BlockingQueue { return $this->workQueue; }
}

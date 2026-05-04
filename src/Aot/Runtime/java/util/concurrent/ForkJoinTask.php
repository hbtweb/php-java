<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;

/**
 * java.util.concurrent.ForkJoinTask — base class for
 * recursively-decomposable parallel tasks. Subclasses:
 *   - RecursiveTask<V>   (returns a value)
 *   - RecursiveAction    (returns void)
 *
 * Workflow: invoke compute() on subclass, which fork()s sub-tasks
 * and join()s them back. Each fork submits to the ForkJoinPool;
 * each join blocks the calling fiber on the sub-task's future.
 *
 * Single-carrier-thread caveat: the parallel-execution benefit
 * doesn't materialise; sub-tasks all run on the same fiber-scheduler.
 * What works correctly: structured fork/join semantics, exception
 * propagation, cancellation. What doesn't: actual speedup. For
 * latency-bounded production deployments, swap to Swoole-coroutine
 * dispatcher (already a planned alternative path in VTE strategy
 * dispatcher).
 */
abstract class ForkJoinTask
{
    private ?int $futureId = null;
    private ?ForkJoinPool $pool = null;
    private bool $done = false;
    private mixed $result = null;
    private ?\Throwable $exception = null;

    /** Subclass implements the recursive computation. */
    abstract public function compute(): mixed;

    /** Java: fork() — schedule asynchronously; returns this for chaining. */
    public function fork(): static
    {
        $pool = $this->pool ?? ForkJoinPool::commonPool();
        $pool->submitTask($this);
        return $this;
    }

    /** Java: join() — wait for completion; returns the result. */
    public function join(): mixed
    {
        if ($this->futureId === null) {
            // Not yet forked — run inline (Java's spec: invoke if
            // not yet started returns the computed result directly)
            $this->_schedule();
        }
        $value = VirtualThreadExecutor::await($this->futureId);
        if ($this->exception !== null) throw $this->exception;
        return $value;
    }

    public function get(): mixed { return $this->join(); }
    public function isDone(): bool { return $this->done; }
    public function isCompletedAbnormally(): bool { return $this->exception !== null; }
    public function isCancelled(): bool { return false; } // not modelled in v1
    public function getException(): ?\Throwable { return $this->exception; }

    public function cancel(bool $mayInterruptIfRunning = false): bool
    {
        if ($this->futureId === null) return false;
        return VirtualThreadExecutor::cancel($this->futureId);
    }

    /** Java: invoke() — fork + join in one call. */
    public function invoke(): mixed { return $this->fork()->join(); }

    /** Internal — set the owning pool. */
    public function _setPool(ForkJoinPool $pool): void { $this->pool = $pool; }

    /** Internal — schedule the task on VTE. */
    public function _schedule(): void
    {
        if ($this->futureId !== null) return; // already scheduled
        $self = $this;
        $this->futureId = VirtualThreadExecutor::async(static function () use ($self) {
            try {
                $self->result = $self->compute();
                $self->done = true;
                return $self->result;
            } catch (\Throwable $e) {
                $self->exception = $e;
                $self->done = true;
                throw $e;
            }
        });
    }

    /** Java 8+: getRawResult() — protected accessor for the result. */
    public function getRawResult(): mixed { return $this->result; }
}

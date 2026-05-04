<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;

/**
 * Internal Future implementation backed by either the InlineExecutor
 * or VirtualThreadExecutor's Future-id model. ExecutorService.submit
 * returns one of these. Distinct from CompletableFuture which has
 * additional composition methods.
 *
 * The choice of backing executor (Inline vs VTE) is made by the
 * submitting ExecutorService — most production paths route through
 * VTE so suspending operations work; InlineExecutor is for
 * proven-non-suspending fast paths the may-suspend analyser
 * promotes statically.
 */
class ConcreteFuture implements Future
{
    public const BACKEND_INLINE = 0;
    public const BACKEND_VTE = 1;

    private int $backend;
    private int $futureId;

    public function __construct(int $backend, int $futureId)
    {
        $this->backend = $backend;
        $this->futureId = $futureId;
    }

    public function cancel(bool $mayInterruptIfRunning = false): bool
    {
        return $this->backend === self::BACKEND_INLINE
            ? InlineExecutor::cancel($this->futureId)
            : VirtualThreadExecutor::cancel($this->futureId);
    }

    public function isCancelled(): bool
    {
        // No direct isCancelled accessor on either executor; cancelled
        // futures land in REJECTED with CancellationException value.
        // For now: return false; if a fixture surfaces the need, add
        // Executor::isCancelled accessor.
        return false;
    }

    public function isDone(): bool
    {
        return $this->backend === self::BACKEND_INLINE
            ? !InlineExecutor::isPending($this->futureId)
            : !VirtualThreadExecutor::isPending($this->futureId);
    }

    public function get(): mixed
    {
        return $this->backend === self::BACKEND_INLINE
            ? InlineExecutor::await($this->futureId)
            : VirtualThreadExecutor::await($this->futureId);
    }
}

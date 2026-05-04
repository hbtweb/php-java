<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.Future — represents the eventual result of
 * an asynchronous computation. Implementations: ConcreteFuture
 * (this module's wrapper around InlineExecutor / VTE Future ids),
 * CompletableFuture (Java 8+ composition surface).
 */
interface Future
{
    public function cancel(bool $mayInterruptIfRunning = false): bool;
    public function isCancelled(): bool;
    public function isDone(): bool;
    public function get(): mixed;
}

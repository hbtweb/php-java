<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.Executor — base interface for objects that
 * execute Runnables. Decouples task submission from task execution
 * (threading, queueing, scheduling). Implementations: any
 * ExecutorService, plus ad-hoc inline executors.
 */
interface Executor
{
    public function execute(callable $command): void;
}

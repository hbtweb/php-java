<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\java\lang\Thread;

/**
 * java.util.concurrent.ThreadFactory — produces new Thread instances
 * on demand. Used by ThreadPoolExecutor and Executors factories
 * that customise thread naming, daemon-ness, priority, etc.
 *
 * Default implementation matches Java's defaultThreadFactory:
 * threads are non-daemon, named pool-N-thread-M.
 */
interface ThreadFactory
{
    public function newThread(callable $runnable): Thread;
}

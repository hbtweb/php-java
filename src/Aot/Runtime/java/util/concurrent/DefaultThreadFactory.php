<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\java\lang\Thread;

/**
 * Default implementation of ThreadFactory. Threads are named
 * pool-N-thread-M (matching JDK reference).
 */
class DefaultThreadFactory implements ThreadFactory
{
    private static int $poolNumber = 0;
    private int $myPool;
    private int $threadNumber = 0;

    public function __construct()
    {
        self::$poolNumber++;
        $this->myPool = self::$poolNumber;
    }

    public function newThread(callable $runnable): Thread
    {
        $this->threadNumber++;
        return new Thread($runnable, "pool-{$this->myPool}-thread-{$this->threadNumber}");
    }
}

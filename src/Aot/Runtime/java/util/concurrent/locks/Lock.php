<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\locks;

/**
 * java.util.concurrent.locks.Lock — interface implemented by
 * ReentrantLock and ReentrantReadWriteLock's read/write views.
 */
interface Lock
{
    public function lock(): void;
    public function lockInterruptibly(): void;
    public function tryLock(): bool;
    public function unlock(): void;
}

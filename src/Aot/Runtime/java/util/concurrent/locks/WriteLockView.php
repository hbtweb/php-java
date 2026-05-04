<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\locks;

class WriteLockView implements Lock
{
    public function __construct(private ReentrantReadWriteLock $rwlock) {}
    public function lock(): void { $this->rwlock->_writeLock(); }
    public function lockInterruptibly(): void { $this->rwlock->_writeLock(); }
    public function tryLock(): bool { return $this->rwlock->_writeTryLock(); }
    public function unlock(): void { $this->rwlock->_writeUnlock(); }
}

<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\locks;

class ReadLockView implements Lock
{
    public function __construct(private ReentrantReadWriteLock $rwlock) {}
    public function lock(): void { $this->rwlock->_readLock(); }
    public function lockInterruptibly(): void { $this->rwlock->_readLock(); }
    public function tryLock(): bool { return $this->rwlock->_readTryLock(); }
    public function unlock(): void { $this->rwlock->_readUnlock(); }
}

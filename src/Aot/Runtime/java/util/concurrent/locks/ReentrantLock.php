<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\locks;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;
use PHPJava\Aot\Runtime\Async\InterruptedException;

/**
 * java.util.concurrent.locks.ReentrantLock — mutex with reentrancy.
 *
 * Single-process PHP semantics:
 *   - When called outside a Fiber: lock/unlock track holder + count
 *     in a property. Same-process always means same "thread"; lock
 *     never blocks because there's no contender.
 *   - Inside a Fiber: holder is the Fiber's spl_object_id. If another
 *     Fiber tries to lock(), it parks via VirtualThreadExecutor and
 *     gets unparked on unlock(). True cooperative mutex.
 *
 * Reentrancy: the holder Fiber can acquire the lock multiple times;
 * unlock decrements the count, releases when it hits zero. Matches
 * j.u.c.locks.ReentrantLock semantics.
 *
 * Fairness: Java's ReentrantLock(true) gives FIFO acquisition. We
 * implement FIFO by appending to a waiter queue; ReentrantLock(false)
 * gets the same FIFO behaviour (no perf reason to skip it on a
 * cooperative scheduler).
 */
class ReentrantLock
{
    private ?int $holderFid = null;
    private int $holdCount = 0;
    /** @var \Fiber[] FIFO of fibers waiting to acquire. */
    private array $waiters = [];
    private bool $fair;

    public function __construct(bool $fair = false)
    {
        $this->fair = $fair;
    }

    public function lock(): void
    {
        $current = \Fiber::getCurrent();
        if ($current === null) {
            // Top-level call — no fiber to track. Treat as same
            // virtual thread; reentrancy via null-holder counter.
            $this->holderFid ??= 0;
            $this->holdCount++;
            return;
        }
        $fid = \spl_object_id($current);
        if ($this->holderFid === null || $this->holderFid === $fid) {
            $this->holderFid = $fid;
            $this->holdCount++;
            return;
        }
        // Contended — enqueue and park; unlock() resumes the next waiter.
        while ($this->holderFid !== null && $this->holderFid !== $fid) {
            $this->waiters[] = $current;
            \Fiber::suspend();
        }
        $this->holderFid = $fid;
        $this->holdCount = 1;
    }

    public function lockInterruptibly(): void
    {
        $current = \Fiber::getCurrent();
        if ($current === null) {
            $this->lock();
            return;
        }
        $fid = \spl_object_id($current);
        if ($this->holderFid === null || $this->holderFid === $fid) {
            $this->holderFid = $fid;
            $this->holdCount++;
            return;
        }
        while ($this->holderFid !== null && $this->holderFid !== $fid) {
            $this->waiters[] = $current;
            \Fiber::suspend();
            // interrupt-throwing on resume requires VTE integration; defer
            // to v2 when bb-fill exercises the interrupt pattern through
            // locks.
        }
        $this->holderFid = $fid;
        $this->holdCount = 1;
    }

    public function tryLock(): bool
    {
        $current = \Fiber::getCurrent();
        $fid = $current !== null ? \spl_object_id($current) : 0;
        if ($this->holderFid === null) {
            $this->holderFid = $fid;
            $this->holdCount = 1;
            return true;
        }
        if ($this->holderFid === $fid) {
            $this->holdCount++;
            return true;
        }
        return false;
    }

    public function unlock(): void
    {
        $current = \Fiber::getCurrent();
        $fid = $current !== null ? \spl_object_id($current) : 0;
        if ($this->holderFid !== $fid) {
            throw new \PHPJava\Packages\java\lang\IllegalMonitorStateException(
                'Current fiber does not hold this lock'
            );
        }
        $this->holdCount--;
        if ($this->holdCount === 0) {
            $this->holderFid = null;
            // Wake the next waiter (FIFO). Resume runs in-line under
            // cooperative scheduling — the waiter's lock() loop sees
            // holderFid==null and acquires; if subsequent contended
            // waiters exist they re-park via the loop body.
            if (!empty($this->waiters)) {
                $next = \array_shift($this->waiters);
                if (!$next->isTerminated()) $next->resume();
            }
        }
    }

    public function isHeldByCurrentThread(): bool
    {
        $current = \Fiber::getCurrent();
        $fid = $current !== null ? \spl_object_id($current) : 0;
        return $this->holderFid === $fid;
    }

    public function isLocked(): bool { return $this->holderFid !== null; }
    public function getHoldCount(): int
    {
        $current = \Fiber::getCurrent();
        $fid = $current !== null ? \spl_object_id($current) : 0;
        return $this->holderFid === $fid ? $this->holdCount : 0;
    }
    public function isFair(): bool { return $this->fair; }
    public function hasQueuedThreads(): bool { return !empty($this->waiters); }
    public function getQueueLength(): int { return \count($this->waiters); }

    /** Java: lock.newCondition() — returns a Condition bound to this lock. */
    public function newCondition(): Condition
    {
        return new Condition($this);
    }

    /** Internal — used by Condition to release the lock fully on await(). */
    public function _drainHoldsForCondition(): int
    {
        $current = \Fiber::getCurrent();
        $fid = $current !== null ? \spl_object_id($current) : 0;
        if ($this->holderFid !== $fid) {
            throw new \PHPJava\Packages\java\lang\IllegalMonitorStateException(
                'Cannot await on Condition without holding the lock'
            );
        }
        $saved = $this->holdCount;
        $this->holdCount = 0;
        $this->holderFid = null;
        return $saved;
    }

    /** Internal — used by Condition to re-acquire the lock at requested depth. */
    public function _restoreHoldsAfterCondition(int $count): void
    {
        $this->lock();
        $this->holdCount = $count;
    }
}

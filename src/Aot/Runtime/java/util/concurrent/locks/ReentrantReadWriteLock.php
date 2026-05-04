<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\locks;

/**
 * java.util.concurrent.locks.ReentrantReadWriteLock — paired
 * read/write locks with reentrancy. Multiple readers can hold
 * the read lock concurrently; writers have exclusive access.
 *
 * Single-process cooperative-scheduling semantics:
 *   - Read lock: tracked by reader-fiber-id → hold-count map.
 *     A new reader proceeds immediately UNLESS a writer holds.
 *   - Write lock: classic mutex. Waits for both reader-count==0
 *     AND no other writer holding.
 *   - A writer can downgrade to a reader (acquire read while
 *     holding write); per Java spec.
 *   - A reader cannot upgrade to writer without releasing
 *     all read holds first; per Java spec (deadlock risk
 *     otherwise).
 *
 * Fairness: same as ReentrantLock — FIFO via waiter queue. No
 * starvation under cooperative scheduling because each yield
 * point gives waiters a chance.
 */
class ReentrantReadWriteLock implements ReadWriteLock
{
    /** @var array<int, int> fiber-id → hold-count for active readers. */
    private array $readHolds = [];
    private ?int $writeHolderFid = null;
    private int $writeHoldCount = 0;
    /** @var \Fiber[] FIFO of fibers waiting on either lock. */
    private array $waiters = [];
    private bool $fair;

    private ReadLockView $readView;
    private WriteLockView $writeView;

    public function __construct(bool $fair = false)
    {
        $this->fair = $fair;
        $this->readView = new ReadLockView($this);
        $this->writeView = new WriteLockView($this);
    }

    public function readLock(): Lock { return $this->readView; }
    public function writeLock(): Lock { return $this->writeView; }
    public function isFair(): bool { return $this->fair; }
    public function getReadHoldCount(): int
    {
        return $this->readHolds[$this->currentFid()] ?? 0;
    }
    public function getWriteHoldCount(): int
    {
        return $this->writeHolderFid === $this->currentFid() ? $this->writeHoldCount : 0;
    }
    public function getReadLockCount(): int
    {
        return \array_sum($this->readHolds);
    }

    // ── friend operations called by view classes ───────────────────────

    private function currentFid(): int
    {
        $current = \Fiber::getCurrent();
        return $current !== null ? \spl_object_id($current) : 0;
    }

    public function _readLock(): void
    {
        $fid = $this->currentFid();
        // Reader-already-holds: increment.
        if (isset($this->readHolds[$fid])) {
            $this->readHolds[$fid]++;
            return;
        }
        // Writer holds (and isn't us): wait. Downgrade case (we hold
        // write): proceed immediately.
        while ($this->writeHolderFid !== null && $this->writeHolderFid !== $fid) {
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException(
                    'ReentrantReadWriteLock.readLock: writer holds and no fiber to park.'
                );
            }
            $this->waiters[] = $current;
            \Fiber::suspend();
        }
        $this->readHolds[$fid] = 1;
    }

    public function _readTryLock(): bool
    {
        $fid = $this->currentFid();
        if (isset($this->readHolds[$fid])) {
            $this->readHolds[$fid]++;
            return true;
        }
        if ($this->writeHolderFid !== null && $this->writeHolderFid !== $fid) {
            return false;
        }
        $this->readHolds[$fid] = 1;
        return true;
    }

    public function _readUnlock(): void
    {
        $fid = $this->currentFid();
        if (!isset($this->readHolds[$fid])) {
            throw new \PHPJava\Packages\java\lang\IllegalMonitorStateException(
                'Current fiber does not hold the read lock'
            );
        }
        $this->readHolds[$fid]--;
        if ($this->readHolds[$fid] === 0) {
            unset($this->readHolds[$fid]);
            // Wake one waiter so writers can proceed when readers drain.
            if (empty($this->readHolds) && !empty($this->waiters)) {
                $next = \array_shift($this->waiters);
                if (!$next->isTerminated()) $next->resume();
            }
        }
    }

    public function _writeLock(): void
    {
        $fid = $this->currentFid();
        if ($this->writeHolderFid === $fid) {
            $this->writeHoldCount++;
            return;
        }
        // Wait until: no other writer AND no other readers.
        while ($this->writeHolderFid !== null
            || !empty(\array_diff_key($this->readHolds, [$fid => true]))) {
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException(
                    'ReentrantReadWriteLock.writeLock: contended and no fiber to park.'
                );
            }
            $this->waiters[] = $current;
            \Fiber::suspend();
        }
        $this->writeHolderFid = $fid;
        $this->writeHoldCount = 1;
    }

    public function _writeTryLock(): bool
    {
        $fid = $this->currentFid();
        if ($this->writeHolderFid === $fid) {
            $this->writeHoldCount++;
            return true;
        }
        if ($this->writeHolderFid !== null) return false;
        if (!empty(\array_diff_key($this->readHolds, [$fid => true]))) return false;
        $this->writeHolderFid = $fid;
        $this->writeHoldCount = 1;
        return true;
    }

    public function _writeUnlock(): void
    {
        $fid = $this->currentFid();
        if ($this->writeHolderFid !== $fid) {
            throw new \PHPJava\Packages\java\lang\IllegalMonitorStateException(
                'Current fiber does not hold the write lock'
            );
        }
        $this->writeHoldCount--;
        if ($this->writeHoldCount === 0) {
            $this->writeHolderFid = null;
            $waiters = $this->waiters;
            $this->waiters = [];
            foreach ($waiters as $fiber) {
                if (!$fiber->isTerminated()) $fiber->resume();
            }
        }
    }
}


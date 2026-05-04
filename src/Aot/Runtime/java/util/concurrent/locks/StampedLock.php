<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\locks;

/**
 * java.util.concurrent.locks.StampedLock — non-reentrant capability-
 * based lock. Three modes:
 *   - Write lock: exclusive, returns a stamp.
 *   - Read lock: pessimistic, returns a stamp.
 *   - Optimistic read: returns a stamp WITHOUT acquiring; caller
 *     reads, then calls validate(stamp). If validate returns true,
 *     no writer arrived between tryOptimisticRead() and validate();
 *     reader's view is consistent. If false, fall back to pessimistic
 *     read.
 *
 * The optimistic-read mode is StampedLock's distinguishing feature
 * over RWLock — gives reader-mostly workloads a true lock-free
 * fast path. Java's reference impl uses a 64-bit stamp encoding
 * (state version + lock mode). We use a simpler split: separate
 * write-version counter incremented on every write-lock-release.
 *
 * NOT REENTRANT (per Java spec). A holder of write-lock must NOT
 * call write-lock again — would deadlock.
 *
 * Cooperative-scheduling implementation:
 *   - Write lock: full mutex against readers AND writers. Parks
 *     fibers on contention; resume FIFO from unlock.
 *   - Pessimistic read lock: parked only when a writer holds.
 *     Multiple readers concurrent.
 *   - Optimistic read: tryOptimisticRead() returns the current
 *     write-version stamp WITHOUT acquiring anything. validate()
 *     compares against current write-version.
 */
class StampedLock
{
    /**
     * Stamp encoding (Java spec): non-zero stamp means "valid lock
     * held in mode X." We encode the mode in the lower bits and
     * the version in the upper bits.
     *   - WRITE_BIT (1): set when write lock is held
     *   - READ_BITS (2-7): reader count (max 63 readers)
     *   - VERSION (8+): write-version counter
     *
     * 0 stamp = "lock acquisition failed" (Java convention).
     */
    private const WRITE_BIT = 1;
    private const READ_BITS_SHIFT = 1;
    private const READ_BITS_MASK = 0b111111 << self::READ_BITS_SHIFT;
    private const VERSION_SHIFT = 8;

    /** Starts at 1 so the very first tryOptimisticRead() returns a non-zero stamp. */
    private int $writeVersion = 1;
    private int $readerCount = 0;
    private bool $writerHolds = false;
    /** @var \Fiber[] */
    private array $waiters = [];

    public function __construct() {}

    /** Java: writeLock() — blocks until exclusive access. Returns stamp. */
    public function writeLock(): int
    {
        while ($this->writerHolds || $this->readerCount > 0) {
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException('StampedLock.writeLock: contended and no fiber to park.');
            }
            $this->waiters[] = $current;
            \Fiber::suspend();
        }
        $this->writerHolds = true;
        $this->writeVersion++;
        return $this->encodeWriteStamp();
    }

    public function tryWriteLock(): int
    {
        if ($this->writerHolds || $this->readerCount > 0) return 0;
        $this->writerHolds = true;
        $this->writeVersion++;
        return $this->encodeWriteStamp();
    }

    public function unlockWrite(int $stamp): void
    {
        if (!$this->writerHolds || $stamp !== $this->encodeWriteStamp()) {
            throw new \PHPJava\Packages\java\lang\IllegalMonitorStateException(
                'StampedLock.unlockWrite: invalid stamp or no write lock held'
            );
        }
        $this->writerHolds = false;
        $this->wakeAll();
    }

    /** Java: readLock() — pessimistic; multiple readers concurrent. Returns stamp. */
    public function readLock(): int
    {
        while ($this->writerHolds) {
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException('StampedLock.readLock: writer holds and no fiber to park.');
            }
            $this->waiters[] = $current;
            \Fiber::suspend();
        }
        $this->readerCount++;
        return $this->encodeReadStamp();
    }

    public function tryReadLock(): int
    {
        if ($this->writerHolds) return 0;
        $this->readerCount++;
        return $this->encodeReadStamp();
    }

    public function unlockRead(int $stamp): void
    {
        if ($this->readerCount <= 0) {
            throw new \PHPJava\Packages\java\lang\IllegalMonitorStateException(
                'StampedLock.unlockRead: no read lock held'
            );
        }
        $this->readerCount--;
        if ($this->readerCount === 0) $this->wakeAll();
    }

    /**
     * Java: tryOptimisticRead() — returns a stamp without acquiring
     * any lock. Stamp is non-zero iff no writer currently holds.
     * Caller MUST then call validate(stamp) after reading state.
     */
    public function tryOptimisticRead(): int
    {
        if ($this->writerHolds) return 0;
        return $this->writeVersion << self::VERSION_SHIFT;
    }

    /**
     * Java: validate(stamp) — true if no writer wrote since the
     * stamp was issued. Optimistic-read path is consistent only if
     * this returns true.
     */
    public function validate(int $stamp): bool
    {
        if ($stamp === 0) return false;
        // Acquire a memory ordering boundary — under cooperative
        // scheduling, no actual memory barrier is needed (no other
        // physical thread can have written), but matching Java's
        // contract is important for portability of the analyzer.
        $stampedVersion = $stamp >> self::VERSION_SHIFT;
        return $stampedVersion === $this->writeVersion && !$this->writerHolds;
    }

    /** Java: tryConvertToWriteLock(stamp) — upgrade reader to writer. */
    public function tryConvertToWriteLock(int $stamp): int
    {
        if ($stamp === 0) return 0;
        $isWrite = ($stamp & self::WRITE_BIT) !== 0;
        if ($isWrite) return $stamp; // already write
        // Optimistic stamp upgrade: only succeeds if no readers besides us
        // and no writer.
        if ($this->writerHolds) return 0;
        if ($this->readerCount > 1) return 0;
        // We may be holding a read; if so, drop it.
        if ($this->readerCount === 1) {
            $this->readerCount = 0;
        }
        $this->writerHolds = true;
        $this->writeVersion++;
        return $this->encodeWriteStamp();
    }

    public function tryConvertToReadLock(int $stamp): int
    {
        if ($stamp === 0) return 0;
        $isWrite = ($stamp & self::WRITE_BIT) !== 0;
        if (!$isWrite) {
            // Already read or optimistic — re-encode if optimistic
            if (($stamp & self::READ_BITS_MASK) === 0) {
                // Was optimistic — promote to pessimistic
                if ($this->writerHolds) return 0;
                $this->readerCount++;
                return $this->encodeReadStamp();
            }
            return $stamp;
        }
        // Was write — drop write, take read
        $this->writerHolds = false;
        $this->readerCount = 1;
        $this->wakeAll();
        return $this->encodeReadStamp();
    }

    public function isWriteLocked(): bool { return $this->writerHolds; }
    public function isReadLocked(): bool  { return $this->readerCount > 0; }
    public function getReadLockCount(): int { return $this->readerCount; }

    private function encodeWriteStamp(): int
    {
        return ($this->writeVersion << self::VERSION_SHIFT) | self::WRITE_BIT;
    }

    private function encodeReadStamp(): int
    {
        return ($this->writeVersion << self::VERSION_SHIFT) | (1 << self::READ_BITS_SHIFT);
    }

    private function wakeAll(): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $f) {
            if (!$f->isTerminated()) $f->resume();
        }
    }
}

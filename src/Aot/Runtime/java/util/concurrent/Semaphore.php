<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.Semaphore — counting semaphore. Tracks N
 * permits; acquire decrements (blocks if zero), release increments.
 *
 * Single-fiber path: just track count; never blocks because no other
 * fiber races us.
 * Cross-fiber path: cooperative park on acquire when count is zero;
 * release wakes waiters in FIFO order (fair) or undefined (unfair).
 */
class Semaphore
{
    private int $permits;
    private bool $fair;
    /** @var \Fiber[] FIFO of fibers waiting on acquire. */
    private array $waiters = [];

    public function __construct(int $permits, bool $fair = false)
    {
        $this->permits = $permits;
        $this->fair = $fair;
    }

    public function acquire(int $permitsToTake = 1): void
    {
        while ($this->permits < $permitsToTake) {
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException(
                    'Semaphore.acquire(): not enough permits and no fiber to park. Deadlock.'
                );
            }
            $this->waiters[] = $current;
            \Fiber::suspend();
        }
        $this->permits -= $permitsToTake;
    }

    public function acquireUninterruptibly(int $permitsToTake = 1): void
    {
        $this->acquire($permitsToTake); // no interrupt model in v1
    }

    public function tryAcquire(int $permitsToTake = 1): bool
    {
        if ($this->permits >= $permitsToTake) {
            $this->permits -= $permitsToTake;
            return true;
        }
        return false;
    }

    public function release(int $permitsToReturn = 1): void
    {
        $this->permits += $permitsToReturn;
        // Wake waiters (FIFO if fair). Take the snapshot so wake order
        // doesn't shift as resumes re-enter waiter list.
        $toWake = $this->waiters;
        $this->waiters = [];
        foreach ($toWake as $fiber) {
            if (!$fiber->isTerminated()) $fiber->resume();
        }
    }

    public function availablePermits(): int { return $this->permits; }
    public function drainPermits(): int
    {
        $taken = $this->permits;
        $this->permits = 0;
        return $taken;
    }
    public function isFair(): bool { return $this->fair; }
    public function hasQueuedThreads(): bool { return !empty($this->waiters); }
    public function getQueueLength(): int { return \count($this->waiters); }

    public function __toString(): string
    {
        return self::class . "[Permits = {$this->permits}]";
    }
}

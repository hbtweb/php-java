<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\locks;

/**
 * java.util.concurrent.locks.Condition — companion to a Lock for the
 * await/signal/signalAll wait-notify pattern. Replaces Object.wait()
 * /notify()/notifyAll() in modern JDK code.
 *
 * Semantics: must hold the associated Lock to call await/signal.
 * await() releases the lock fully (drops all reentrant holds), parks
 * the fiber, and re-acquires the lock when signalled — restoring the
 * original hold count.
 *
 * Spurious wakeups: not modelled. Single-process cooperative scheduling
 * means every wake is a real wake (we never resume a fiber except in
 * response to an explicit signal). Defensive `while (predicate)` loops
 * in user code remain correct.
 */
class Condition
{
    private ReentrantLock $lock;
    /** @var \Fiber[] FIFO of fibers parked on this condition. */
    private array $waiters = [];

    public function __construct(ReentrantLock $lock)
    {
        $this->lock = $lock;
    }

    /**
     * Java: condition.await() — release lock, park, re-acquire on signal.
     */
    public function await(): void
    {
        $current = \Fiber::getCurrent();
        if ($current === null) {
            throw new \LogicException(
                'Condition.await(): no fiber to park. Cooperative scheduling requires a fiber context.'
            );
        }
        $savedHolds = $this->lock->_drainHoldsForCondition();
        $this->waiters[] = $current;
        \Fiber::suspend();
        $this->lock->_restoreHoldsAfterCondition($savedHolds);
    }

    public function awaitUninterruptibly(): void { $this->await(); }

    /** Java: signal() — wake one waiter (FIFO). */
    public function signal(): void
    {
        if (!$this->lock->isHeldByCurrentThread()) {
            throw new \PHPJava\Packages\java\lang\IllegalMonitorStateException(
                'Cannot signal without holding the lock'
            );
        }
        if (empty($this->waiters)) return;
        $fiber = \array_shift($this->waiters);
        if (!$fiber->isTerminated()) $fiber->resume();
    }

    /** Java: signalAll() — wake all waiters. */
    public function signalAll(): void
    {
        if (!$this->lock->isHeldByCurrentThread()) {
            throw new \PHPJava\Packages\java\lang\IllegalMonitorStateException(
                'Cannot signal without holding the lock'
            );
        }
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $fiber) {
            if (!$fiber->isTerminated()) $fiber->resume();
        }
    }

    public function getWaitQueueLength(): int { return \count($this->waiters); }
    public function hasWaiters(): bool { return !empty($this->waiters); }
}

<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;

/**
 * java.util.concurrent.CountDownLatch — single-shot countdown sync.
 * Threads await the latch; latch counts down via countDown(); when
 * count reaches zero, all awaiters are released.
 *
 * Common usage: "wait for N initialization tasks to complete before
 * proceeding," "fan out N work items, fan in when all done."
 *
 * Single-shot — once the count reaches zero, await() returns
 * immediately for all subsequent callers. Cannot be reset (use
 * CyclicBarrier for reusable).
 */
class CountDownLatch
{
    private int $count;
    /** @var \Fiber[] Fibers parked on await(). */
    private array $waiters = [];

    public function __construct(int $count)
    {
        if ($count < 0) {
            throw new \PHPJava\Packages\java\lang\IllegalArgumentException('count < 0');
        }
        $this->count = $count;
    }

    public function getCount(): int { return $this->count; }

    public function countDown(): void
    {
        if ($this->count === 0) return;
        $this->count--;
        if ($this->count === 0) {
            // Wake all waiters
            $waiters = $this->waiters;
            $this->waiters = [];
            foreach ($waiters as $fiber) {
                if (!$fiber->isTerminated()) $fiber->resume();
            }
        }
    }

    public function await(): void
    {
        if ($this->count === 0) return;
        $current = \Fiber::getCurrent();
        if ($current === null) {
            // Top-level — drive VTE event loop until count reaches zero.
            // Bounds check: if a single VTE::run() makes no progress on
            // count, all fibers are parked on something else and the
            // latch will never drop. Throw to surface broken usage.
            while ($this->count > 0) {
                $before = $this->count;
                VirtualThreadExecutor::run();
                if ($this->count === $before) {
                    throw new \LogicException(
                        'CountDownLatch.await(): no fiber decremented the latch. Deadlock.'
                    );
                }
            }
            return;
        }
        $this->waiters[] = $current;
        \Fiber::suspend();
    }

    public function __toString(): string
    {
        return self::class . "[Count = {$this->count}]";
    }
}

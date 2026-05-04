<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;

/**
 * java.util.concurrent.DelayQueue — unbounded blocking queue of
 * Delayed elements; take() returns only elements whose delay has
 * expired.
 *
 * Implementation: SplPriorityQueue keyed by negative remaining-delay
 * (smallest delay first). take() peeks the head; if delay remains,
 * sleeps until the head's scheduled time, then re-checks. Other
 * take()ers also re-check when an offer arrives — so a smaller-delay
 * insertion can preempt a longer-delay sleeper.
 *
 * Common usage: scheduled task queues, retry-with-backoff, time-
 * window expiry.
 */
class DelayQueue implements BlockingQueue
{
    /** @var \SplPriorityQueue */
    private \SplPriorityQueue $heap;
    private int $insertionSeq = 0;
    /** @var \Fiber[] */
    private array $takeWaiters = [];

    public function __construct()
    {
        $this->heap = new \SplPriorityQueue();
        $this->heap->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
    }

    public function size(): int { return \count($this->heap); }
    public function isEmpty(): bool { return \count($this->heap) === 0; }
    public function remainingCapacity(): int { return \PHP_INT_MAX; }

    public function add(mixed $element): bool
    {
        if (!($element instanceof Delayed)) {
            throw new \PHPJava\Packages\java\lang\IllegalArgumentException(
                'DelayQueue requires Delayed elements'
            );
        }
        return $this->offer($element);
    }

    public function offer(mixed $element): bool
    {
        if (!($element instanceof Delayed)) {
            throw new \PHPJava\Packages\java\lang\IllegalArgumentException(
                'DelayQueue requires Delayed elements'
            );
        }
        // Use negative delay so smaller delay sorts to the top.
        // Tie-break by insertion sequence (FIFO at same delay).
        $delayNs = $element->getDelay(TimeUnit::$NANOSECONDS);
        $priority = [-$delayNs, -(++$this->insertionSeq)];
        $this->heap->insert($element, $priority);
        $this->wakeOneTake();
        return true;
    }

    public function put(mixed $element): void { $this->offer($element); }

    public function take(): mixed
    {
        while (true) {
            if (\count($this->heap) === 0) {
                $this->parkTake();
                continue;
            }
            $clone = clone $this->heap;
            $head = $clone->top();
            $remainingNs = $head->getDelay(TimeUnit::$NANOSECONDS);
            if ($remainingNs <= 0) {
                return $this->heap->extract();
            }
            // Wait until head's delay expires (or until another offer
            // wakes us if it's smaller-delay).
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException('DelayQueue.take: head not ready and no fiber to park.');
            }
            // Schedule a wake-up for the head's deadline.
            $remainingMs = (int) \intdiv($remainingNs, 1_000_000);
            VirtualThreadExecutor::scheduleAfter($remainingMs, function () use ($current) {
                if (!$current->isTerminated()) $current->resume();
            });
            $this->takeWaiters[] = $current;
            \Fiber::suspend();
            // On resume, re-check head
        }
    }

    public function poll(): mixed
    {
        if (\count($this->heap) === 0) return null;
        $clone = clone $this->heap;
        $head = $clone->top();
        if ($head->getDelay(TimeUnit::$NANOSECONDS) > 0) return null;
        return $this->heap->extract();
    }

    public function peek(): mixed
    {
        if (\count($this->heap) === 0) return null;
        $clone = clone $this->heap;
        return $clone->top();
    }

    public function remove(mixed $element = null): bool
    {
        if ($element === null) {
            if (\count($this->heap) === 0) {
                throw new \PHPJava\Aot\Runtime\java\util\NoSuchElementException();
            }
            $this->heap->extract();
            return true;
        }
        // O(n) — scan + rebuild
        $items = [];
        $found = false;
        while (\count($this->heap) > 0) {
            $top = $this->heap->extract();
            if (!$found && ($top === $element || $top == $element)) {
                $found = true;
                continue;
            }
            $items[] = $top;
        }
        foreach ($items as $i) $this->offer($i);
        return $found;
    }

    public function contains(mixed $element): bool
    {
        $clone = clone $this->heap;
        while (\count($clone) > 0) {
            $v = $clone->extract();
            if ($v === $element || $v == $element) return true;
        }
        return false;
    }

    public function clear(): void { $this->heap = new \SplPriorityQueue(); }

    public function drainTo(array &$collection, int $maxElements = \PHP_INT_MAX): int
    {
        $drained = 0;
        while (\count($this->heap) > 0 && $drained < $maxElements) {
            $clone = clone $this->heap;
            if ($clone->top()->getDelay(TimeUnit::$NANOSECONDS) > 0) break;
            $collection[] = $this->heap->extract();
            $drained++;
        }
        return $drained;
    }

    private function parkTake(): void
    {
        $current = \Fiber::getCurrent();
        if ($current === null) {
            throw new \LogicException('DelayQueue.take: empty and no fiber to park.');
        }
        $this->takeWaiters[] = $current;
        \Fiber::suspend();
    }

    private function wakeOneTake(): void
    {
        while (!empty($this->takeWaiters)) {
            $f = \array_shift($this->takeWaiters);
            if (!$f->isTerminated()) { $f->resume(); return; }
        }
    }
}

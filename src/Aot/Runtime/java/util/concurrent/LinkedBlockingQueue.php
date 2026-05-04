<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.LinkedBlockingQueue — optionally-bounded
 * FIFO blocking queue backed by a linked structure.
 *
 * Cooperative-scheduling implementation:
 *   - put() blocks via Fiber::suspend when at capacity; take blocks
 *     via Fiber::suspend when empty. Wakes are FIFO.
 *   - Backing storage: \SplDoublyLinkedList (constant-time head/tail
 *     ops, the JVM's Linked* contract).
 *   - Single fiber + no other fibers in flight: put/take never park.
 *
 * Capacity:
 *   - Default Integer.MAX_VALUE (effectively unbounded — put never
 *     blocks unless OOM, matching Java's default constructor).
 *   - Bounded: pass capacity to constructor; put blocks at capacity.
 */
class LinkedBlockingQueue implements BlockingQueue
{
    private \SplDoublyLinkedList $items;
    private int $capacity;
    /** @var \Fiber[] FIFO of fibers parked on put (capacity full). */
    private array $putWaiters = [];
    /** @var \Fiber[] FIFO of fibers parked on take (queue empty). */
    private array $takeWaiters = [];

    public function __construct(int $capacity = \PHP_INT_MAX)
    {
        if ($capacity <= 0) {
            throw new \PHPJava\Packages\java\lang\IllegalArgumentException('capacity <= 0');
        }
        $this->capacity = $capacity;
        $this->items = new \SplDoublyLinkedList();
    }

    public function size(): int { return \count($this->items); }
    public function isEmpty(): bool { return $this->items->isEmpty(); }
    public function remainingCapacity(): int { return $this->capacity - \count($this->items); }

    /** Java: add() — throws IllegalStateException on full. */
    public function add(mixed $element): bool
    {
        if (!$this->offer($element)) {
            throw new \PHPJava\Packages\java\lang\IllegalStateException('Queue full');
        }
        return true;
    }

    /** Java: offer() — returns false on full, never blocks. */
    public function offer(mixed $element): bool
    {
        if (\count($this->items) >= $this->capacity) return false;
        $this->items->push($element);
        $this->wakeOneTake();
        return true;
    }

    /** Java: put() — blocks until space available. */
    public function put(mixed $element): void
    {
        while (\count($this->items) >= $this->capacity) {
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException('LinkedBlockingQueue.put: full and no fiber to park.');
            }
            $this->putWaiters[] = $current;
            \Fiber::suspend();
        }
        $this->items->push($element);
        $this->wakeOneTake();
    }

    /** Java: take() — blocks until element available. */
    public function take(): mixed
    {
        while ($this->items->isEmpty()) {
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException('LinkedBlockingQueue.take: empty and no fiber to park.');
            }
            $this->takeWaiters[] = $current;
            \Fiber::suspend();
        }
        $head = $this->items->shift();
        $this->wakeOnePut();
        return $head;
    }

    /** Java: poll() — returns null on empty, never blocks. */
    public function poll(): mixed
    {
        if ($this->items->isEmpty()) return null;
        $head = $this->items->shift();
        $this->wakeOnePut();
        return $head;
    }

    public function peek(): mixed
    {
        return $this->items->isEmpty() ? null : $this->items->bottom();
    }

    public function remove(mixed $element = null): bool
    {
        if ($element === null) {
            // Remove head
            if ($this->items->isEmpty()) {
                throw new \PHPJava\Aot\Runtime\java\util\NoSuchElementException();
            }
            $this->items->shift();
            $this->wakeOnePut();
            return true;
        }
        for ($i = 0; $i < \count($this->items); $i++) {
            if ($this->items[$i] === $element || $this->items[$i] == $element) {
                $this->items->offsetUnset($i);
                $this->wakeOnePut();
                return true;
            }
        }
        return false;
    }

    public function contains(mixed $element): bool
    {
        foreach ($this->items as $item) {
            if ($item === $element || $item == $element) return true;
        }
        return false;
    }

    public function clear(): void
    {
        $this->items = new \SplDoublyLinkedList();
        $this->wakeAllPut();
    }

    public function drainTo(array &$collection, int $maxElements = \PHP_INT_MAX): int
    {
        $count = 0;
        while (!$this->items->isEmpty() && $count < $maxElements) {
            $collection[] = $this->items->shift();
            $count++;
        }
        if ($count > 0) $this->wakeAllPut();
        return $count;
    }

    public function iterator(): \Iterator
    {
        return $this->items;
    }

    public function toArray(): array
    {
        $a = [];
        foreach ($this->items as $item) $a[] = $item;
        return $a;
    }

    // ── waiter management ────────────────────────────────────────────

    private function wakeOneTake(): void
    {
        while (!empty($this->takeWaiters)) {
            $f = \array_shift($this->takeWaiters);
            if (!$f->isTerminated()) { $f->resume(); return; }
        }
    }

    private function wakeOnePut(): void
    {
        while (!empty($this->putWaiters)) {
            $f = \array_shift($this->putWaiters);
            if (!$f->isTerminated()) { $f->resume(); return; }
        }
    }

    private function wakeAllPut(): void
    {
        $waiters = $this->putWaiters;
        $this->putWaiters = [];
        foreach ($waiters as $f) {
            if (!$f->isTerminated()) $f->resume();
        }
    }
}

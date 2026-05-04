<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.LinkedBlockingDeque — optionally-bounded
 * blocking double-ended queue. Combines LinkedBlockingQueue's
 * blocking semantics with ConcurrentLinkedDeque's both-ends API.
 *
 * Cooperative-scheduling implementation: separate waiter queues
 * for each blocking operation (putFirst / putLast / takeFirst /
 * takeLast). FIFO wake order within each waiter queue.
 */
class LinkedBlockingDeque implements BlockingQueue
{
    private \SplDoublyLinkedList $items;
    private int $capacity;
    /** @var \Fiber[] */
    private array $putWaiters = [];
    /** @var \Fiber[] */
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

    // ── End-specific operations ──────────────────────────────────────

    public function offerFirst(mixed $value): bool
    {
        if (\count($this->items) >= $this->capacity) return false;
        $this->items->unshift($value);
        $this->wakeOneTake();
        return true;
    }

    public function offerLast(mixed $value): bool
    {
        if (\count($this->items) >= $this->capacity) return false;
        $this->items->push($value);
        $this->wakeOneTake();
        return true;
    }

    public function putFirst(mixed $value): void
    {
        while (\count($this->items) >= $this->capacity) $this->parkPut();
        $this->items->unshift($value);
        $this->wakeOneTake();
    }

    public function putLast(mixed $value): void
    {
        while (\count($this->items) >= $this->capacity) $this->parkPut();
        $this->items->push($value);
        $this->wakeOneTake();
    }

    public function takeFirst(): mixed
    {
        while ($this->items->isEmpty()) $this->parkTake();
        $head = $this->items->shift();
        $this->wakeOnePut();
        return $head;
    }

    public function takeLast(): mixed
    {
        while ($this->items->isEmpty()) $this->parkTake();
        $tail = $this->items->pop();
        $this->wakeOnePut();
        return $tail;
    }

    public function pollFirst(): mixed
    {
        if ($this->items->isEmpty()) return null;
        $head = $this->items->shift();
        $this->wakeOnePut();
        return $head;
    }

    public function pollLast(): mixed
    {
        if ($this->items->isEmpty()) return null;
        $tail = $this->items->pop();
        $this->wakeOnePut();
        return $tail;
    }

    public function peekFirst(): mixed
    {
        return $this->items->isEmpty() ? null : $this->items->bottom();
    }

    public function peekLast(): mixed
    {
        return $this->items->isEmpty() ? null : $this->items->top();
    }

    // ── BlockingQueue interface (default end) ───────────────────────

    public function add(mixed $element): bool
    {
        if (!$this->offerLast($element)) {
            throw new \PHPJava\Packages\java\lang\IllegalStateException('Deque full');
        }
        return true;
    }

    public function offer(mixed $element): bool { return $this->offerLast($element); }
    public function put(mixed $element): void { $this->putLast($element); }
    public function take(): mixed { return $this->takeFirst(); }
    public function poll(): mixed { return $this->pollFirst(); }
    public function peek(): mixed { return $this->peekFirst(); }

    public function remove(mixed $element = null): bool
    {
        if ($element === null) {
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
        foreach ($this->items as $v) {
            if ($v === $element || $v == $element) return true;
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

    private function parkPut(): void
    {
        $current = \Fiber::getCurrent();
        if ($current === null) {
            throw new \LogicException('LinkedBlockingDeque: full and no fiber to park.');
        }
        $this->putWaiters[] = $current;
        \Fiber::suspend();
    }

    private function parkTake(): void
    {
        $current = \Fiber::getCurrent();
        if ($current === null) {
            throw new \LogicException('LinkedBlockingDeque: empty and no fiber to park.');
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

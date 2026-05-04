<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.ConcurrentLinkedQueue — unbounded thread-safe
 * FIFO queue. Java's reference impl is lock-free (Michael & Scott
 * algorithm with CAS). Under PHP cooperative scheduling, plain
 * non-locking ops on \SplDoublyLinkedList suffice — no fiber can
 * interleave between read and write unless we yield, and this class
 * never yields.
 *
 * Distinct from LinkedBlockingQueue: this is non-blocking only.
 * poll() on empty returns null (not blocks); offer() always returns
 * true (no capacity).
 */
class ConcurrentLinkedQueue implements \Countable, \IteratorAggregate
{
    private \SplDoublyLinkedList $items;

    public function __construct(iterable $initial = [])
    {
        $this->items = new \SplDoublyLinkedList();
        foreach ($initial as $v) $this->items->push($v);
    }

    public function add(mixed $element): bool { return $this->offer($element); }

    public function offer(mixed $element): bool
    {
        $this->items->push($element);
        return true;
    }

    public function poll(): mixed
    {
        return $this->items->isEmpty() ? null : $this->items->shift();
    }

    public function peek(): mixed
    {
        return $this->items->isEmpty() ? null : $this->items->bottom();
    }

    public function element(): mixed
    {
        if ($this->items->isEmpty()) {
            throw new \PHPJava\Aot\Runtime\java\util\NoSuchElementException();
        }
        return $this->items->bottom();
    }

    public function remove(mixed $value = null): mixed
    {
        if ($value === null) {
            if ($this->items->isEmpty()) {
                throw new \PHPJava\Aot\Runtime\java\util\NoSuchElementException();
            }
            return $this->items->shift();
        }
        for ($i = 0; $i < \count($this->items); $i++) {
            if ($this->items[$i] === $value || $this->items[$i] == $value) {
                $this->items->offsetUnset($i);
                return true;
            }
        }
        return false;
    }

    public function contains(mixed $value): bool
    {
        foreach ($this->items as $v) {
            if ($v === $value || $v == $value) return true;
        }
        return false;
    }

    public function size(): int { return \count($this->items); }
    public function count(): int { return \count($this->items); }
    public function isEmpty(): bool { return $this->items->isEmpty(); }
    public function clear(): void { $this->items = new \SplDoublyLinkedList(); }

    public function toArray(): array
    {
        $a = [];
        foreach ($this->items as $v) $a[] = $v;
        return $a;
    }

    public function getIterator(): \Iterator
    {
        return $this->items;
    }
}

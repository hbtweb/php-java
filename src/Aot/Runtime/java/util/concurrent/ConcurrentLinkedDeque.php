<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.ConcurrentLinkedDeque — double-ended queue
 * variant of ConcurrentLinkedQueue. Supports addFirst / addLast /
 * removeFirst / removeLast / pollFirst / pollLast / peekFirst /
 * peekLast.
 *
 * Same single-threaded-PHP simplification as ConcurrentLinkedQueue:
 * SplDoublyLinkedList wrapped without explicit locking.
 */
class ConcurrentLinkedDeque implements \Countable, \IteratorAggregate
{
    private \SplDoublyLinkedList $items;

    public function __construct(iterable $initial = [])
    {
        $this->items = new \SplDoublyLinkedList();
        foreach ($initial as $v) $this->items->push($v);
    }

    public function addFirst(mixed $value): void { $this->items->unshift($value); }
    public function addLast(mixed $value): void  { $this->items->push($value); }
    public function offerFirst(mixed $value): bool { $this->items->unshift($value); return true; }
    public function offerLast(mixed $value): bool  { $this->items->push($value); return true; }

    public function removeFirst(): mixed
    {
        if ($this->items->isEmpty()) {
            throw new \PHPJava\Aot\Runtime\java\util\NoSuchElementException();
        }
        return $this->items->shift();
    }

    public function removeLast(): mixed
    {
        if ($this->items->isEmpty()) {
            throw new \PHPJava\Aot\Runtime\java\util\NoSuchElementException();
        }
        return $this->items->pop();
    }

    public function pollFirst(): mixed { return $this->items->isEmpty() ? null : $this->items->shift(); }
    public function pollLast(): mixed  { return $this->items->isEmpty() ? null : $this->items->pop(); }

    public function peekFirst(): mixed
    {
        return $this->items->isEmpty() ? null : $this->items->bottom();
    }

    public function peekLast(): mixed
    {
        return $this->items->isEmpty() ? null : $this->items->top();
    }

    // Queue methods (alias to last-end add, first-end remove)
    public function add(mixed $value): bool   { return $this->offerLast($value); }
    public function offer(mixed $value): bool { return $this->offerLast($value); }
    public function poll(): mixed             { return $this->pollFirst(); }
    public function peek(): mixed             { return $this->peekFirst(); }
    public function remove(mixed $value = null): mixed
    {
        if ($value === null) return $this->removeFirst();
        for ($i = 0; $i < \count($this->items); $i++) {
            if ($this->items[$i] === $value || $this->items[$i] == $value) {
                $this->items->offsetUnset($i);
                return true;
            }
        }
        return false;
    }

    // Stack methods (push/pop, head-end)
    public function push(mixed $value): void { $this->addFirst($value); }
    public function pop(): mixed             { return $this->removeFirst(); }

    public function contains(mixed $value): bool
    {
        foreach ($this->items as $v) {
            if ($v === $value || $v == $value) return true;
        }
        return false;
    }

    public function size(): int    { return \count($this->items); }
    public function count(): int   { return \count($this->items); }
    public function isEmpty(): bool { return $this->items->isEmpty(); }
    public function clear(): void  { $this->items = new \SplDoublyLinkedList(); }

    public function toArray(): array
    {
        $a = [];
        foreach ($this->items as $v) $a[] = $v;
        return $a;
    }

    public function getIterator(): \Iterator { return $this->items; }
}

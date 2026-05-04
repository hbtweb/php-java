<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.CopyOnWriteArraySet — thread-safe set backed
 * by a CopyOnWriteArrayList. add() is addIfAbsent semantics.
 *
 * Trade-off: O(n) contains/add/remove. Suitable for small sets with
 * heavy read traffic. Java's reference impl is exactly this — an
 * ArraySet view over CopyOnWriteArrayList — so no behavioural delta.
 */
class CopyOnWriteArraySet implements \Countable, \IteratorAggregate
{
    private CopyOnWriteArrayList $items;

    public function __construct(iterable $initial = [])
    {
        $this->items = new CopyOnWriteArrayList();
        foreach ($initial as $v) $this->items->addIfAbsent($v);
    }

    public function add(mixed $value): bool { return $this->items->addIfAbsent($value); }

    public function addAll(iterable $values): bool
    {
        $changed = false;
        foreach ($values as $v) {
            if ($this->items->addIfAbsent($v)) $changed = true;
        }
        return $changed;
    }

    public function remove(mixed $value): bool
    {
        return $this->items->remove(null, $value) === true;
    }

    public function contains(mixed $value): bool { return $this->items->contains($value); }
    public function size(): int                  { return $this->items->size(); }
    public function count(): int                 { return $this->items->size(); }
    public function isEmpty(): bool              { return $this->items->isEmpty(); }
    public function clear(): void                { $this->items->clear(); }
    public function toArray(): array             { return $this->items->toArray(); }

    public function forEach(callable $action): void { $this->items->forEach($action); }
    public function getIterator(): \Iterator { return $this->items->iterator(); }
}

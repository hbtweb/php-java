<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.CopyOnWriteArrayList — thread-safe variant
 * of ArrayList where mutations create a fresh underlying array.
 * Reads are lock-free (the snapshot at the time of read is iterated
 * without synchronisation); writes are O(n) due to the copy.
 *
 * Single-threaded PHP cooperative scheduling: the COW invariant is
 * trivially satisfied because no write can interleave with a read
 * unless we yield. Iterators capture the array reference at
 * iterator-creation time, matching Java's snapshot-iterator
 * semantics.
 */
class CopyOnWriteArrayList implements \Countable, \IteratorAggregate
{
    /** @var list<mixed> */
    private array $items = [];

    public function __construct(array $initial = [])
    {
        $this->items = \array_values($initial);
    }

    public function size(): int { return \count($this->items); }
    public function count(): int { return \count($this->items); }
    public function isEmpty(): bool { return empty($this->items); }

    public function get(int $index): mixed
    {
        if ($index < 0 || $index >= \count($this->items)) {
            throw new \PHPJava\Packages\java\lang\ArrayIndexOutOfBoundsException("Index: {$index}");
        }
        return $this->items[$index];
    }

    public function set(int $index, mixed $value): mixed
    {
        if ($index < 0 || $index >= \count($this->items)) {
            throw new \PHPJava\Packages\java\lang\ArrayIndexOutOfBoundsException("Index: {$index}");
        }
        $copy = $this->items;
        $old = $copy[$index];
        $copy[$index] = $value;
        $this->items = $copy;
        return $old;
    }

    public function add(mixed $value): bool
    {
        $copy = $this->items;
        $copy[] = $value;
        $this->items = $copy;
        return true;
    }

    public function addIfAbsent(mixed $value): bool
    {
        if ($this->contains($value)) return false;
        return $this->add($value);
    }

    public function addAll(iterable $values): bool
    {
        $changed = false;
        $copy = $this->items;
        foreach ($values as $v) {
            $copy[] = $v;
            $changed = true;
        }
        $this->items = $copy;
        return $changed;
    }

    public function remove(int|null $index = null, mixed $byValue = null): mixed
    {
        if ($index !== null) {
            if ($index < 0 || $index >= \count($this->items)) {
                throw new \PHPJava\Packages\java\lang\ArrayIndexOutOfBoundsException("Index: {$index}");
            }
            $copy = $this->items;
            $removed = \array_splice($copy, $index, 1);
            $this->items = $copy;
            return $removed[0];
        }
        // Remove by value
        foreach ($this->items as $i => $v) {
            if ($v === $byValue || $v == $byValue) {
                $copy = $this->items;
                \array_splice($copy, $i, 1);
                $this->items = $copy;
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

    public function indexOf(mixed $value): int
    {
        foreach ($this->items as $i => $v) {
            if ($v === $value || $v == $value) return $i;
        }
        return -1;
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function toArray(): array { return $this->items; }

    public function iterator(): \Iterator
    {
        // Snapshot — capture array by value at iterator creation
        $snapshot = $this->items;
        return new \ArrayIterator($snapshot);
    }

    public function getIterator(): \Iterator { return $this->iterator(); }

    public function forEach(callable $action): void
    {
        $snapshot = $this->items;
        foreach ($snapshot as $v) $action($v);
    }
}

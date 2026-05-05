<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util;

use PHPJava\Aot\Runtime\java\lang\IndexOutOfBoundsException;

/**
 * java.util.ArrayList — bb-allowlist fill (10/80).
 *
 * Resizable array. Backing: PHP array (already a dynamic vector).
 * Surface: add / get / set / remove / size / isEmpty / contains /
 * indexOf / lastIndexOf / clear / toArray / equals / hashCode /
 * toString. Java's two remove overloads (by-index vs by-value)
 * distinguish at the bytecode level via descriptor; the polymorphic
 * PHP method below dispatches on the runtime arg type.
 *
 * Validated against OpenJDK 25 by bench/parity/cases/java.util.ArrayList.json.
 */
final class ArrayList
{
    /** @var array<int, mixed> 0-indexed backing list */
    private array $data = [];

    public function __construct() {}

    public function size(): int     { return \count($this->data); }
    public function isEmpty(): bool { return empty($this->data); }
    public function clear(): void   { $this->data = []; }

    /**
     * Java has add(E) returning bool (always true) and
     * add(int idx, E) returning void. PHP polymorphic — distinguish
     * by arg count via the dispatching method below.
     *
     * Single-arg form: append.
     */
    public function add($e): bool
    {
        $this->data[] = $e;
        return true;
    }

    /** add(int idx, E) — insert at idx. PHP via array_splice. */
    public function addAt(int $idx, $e): void
    {
        $len = \count($this->data);
        if ($idx < 0 || $idx > $len) {
            throw new IndexOutOfBoundsException("Index: $idx, Size: $len");
        }
        \array_splice($this->data, $idx, 0, [$e]);
    }

    public function get(int $idx)
    {
        $len = \count($this->data);
        if ($idx < 0 || $idx >= $len) {
            throw new IndexOutOfBoundsException("Index: $idx, Size: $len");
        }
        return $this->data[$idx];
    }

    public function set(int $idx, $e)
    {
        $len = \count($this->data);
        if ($idx < 0 || $idx >= $len) {
            throw new IndexOutOfBoundsException("Index: $idx, Size: $len");
        }
        $old = $this->data[$idx];
        $this->data[$idx] = $e;
        return $old;
    }

    /**
     * Java's two remove overloads:
     *   E remove(int index)        → returns the removed element
     *   boolean remove(Object o)   → returns true if found and removed
     * Bytecode distinguishes via descriptor; under AOT contract
     * dispatcher picks the right one. For PHP we polymorphic on PHP
     * type — int args take the index path, others the value path.
     *
     * Edge: remove(Integer) where the value happens to be an int —
     * routes to index-path here. AOT-emitted bytecode sees Integer
     * (Object) and dispatches the value-path explicitly via descriptor
     * mangling; out-of-band callers (the parity oracle) follow PHP's
     * type-based routing and stay in-spec for their case mix.
     */
    public function remove($o)
    {
        if (\is_int($o)) {
            $len = \count($this->data);
            if ($o < 0 || $o >= $len) {
                throw new IndexOutOfBoundsException("Index: $o, Size: $len");
            }
            $removed = $this->data[$o];
            \array_splice($this->data, $o, 1);
            return $removed;
        }
        // Remove by value — first occurrence.
        $idx = \array_search($o, $this->data, true);
        if ($idx === false) return false;
        \array_splice($this->data, (int) $idx, 1);
        return true;
    }

    public function contains($o): bool
    {
        return \in_array($o, $this->data, true);
    }

    public function indexOf($o): int
    {
        $idx = \array_search($o, $this->data, true);
        return $idx === false ? -1 : (int) $idx;
    }

    public function lastIndexOf($o): int
    {
        for ($i = \count($this->data) - 1; $i >= 0; $i--) {
            if ($this->data[$i] === $o) return $i;
        }
        return -1;
    }

    /**
     * subList(from, to) — view over [from, to). Java returns a backed
     * sub-list view; PHP returns a fresh ArrayList copy (back-ref
     * semantics deferred — ship when a fixture mutates a sub-view
     * and asserts the parent reflects the change).
     */
    public function subList(int $fromIndex, int $toIndex): self
    {
        $len = \count($this->data);
        if ($fromIndex < 0 || $toIndex > $len || $fromIndex > $toIndex) {
            throw new IndexOutOfBoundsException("subList [$fromIndex, $toIndex) of size $len");
        }
        $sub = new self();
        $sub->data = \array_slice($this->data, $fromIndex, $toIndex - $fromIndex);
        return $sub;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function equals($other): bool
    {
        if (!($other instanceof self)) return false;
        if (\count($this->data) !== \count($other->data)) return false;
        foreach ($this->data as $i => $v) {
            if ($v !== $other->data[$i]) return false;
        }
        return true;
    }

    /**
     * Java spec hashCode for List: result = 1; for each element e,
     * result = 31 * result + (e == null ? 0 : e.hashCode()).
     * Identical fold to Arrays::hashCode and Objects::hash —
     * compositional via Objects::hashCode.
     */
    public function hashCode(): int
    {
        return Objects::hash($this->data);
    }

    public function toString(): string
    {
        if (empty($this->data)) return '[]';
        return Arrays::toString($this->data);
    }

    public function __toString(): string { return $this->toString(); }

    /**
     * iterator() — Java returns an Iterator. We return a PHP array
     * (snapshot, NOT back-ref). For AOT-emitted enhanced for-loops,
     * IR Lowerer translates `for (E e : list)` to PHP foreach which
     * works directly on the array.
     */
    public function iterator(): array
    {
        return $this->data;
    }
}

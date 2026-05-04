<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\atomic;

/**
 * java.util.concurrent.atomic.AtomicReference — atomic object
 * reference. Identity-compare semantics on `compareAndSet` (same as
 * Java; uses `===` in PHP which is reference-identity for objects,
 * value-identity for primitives — matches Java's `==` on reference
 * types).
 *
 * See AtomicInteger for the cooperative-scheduling rationale.
 *
 * @template V
 */
class AtomicReference
{
    /** @var V|null */
    private mixed $value;

    /** @param V|null $initialValue */
    public function __construct(mixed $initialValue = null)
    {
        $this->value = $initialValue;
    }

    /** @return V|null */
    public function get(): mixed { return $this->value; }

    /** @param V|null $newValue */
    public function set(mixed $newValue): void { $this->value = $newValue; }

    public function lazySet(mixed $newValue): void { $this->value = $newValue; }

    /**
     * @param V|null $newValue
     * @return V|null
     */
    public function getAndSet(mixed $newValue): mixed
    {
        $old = $this->value;
        $this->value = $newValue;
        return $old;
    }

    /**
     * Java semantics: identity compare via `==`. PHP `===` is the
     * right match — reference-identity for objects, value-identity
     * for scalars.
     */
    public function compareAndSet(mixed $expect, mixed $update): bool
    {
        if ($this->value === $expect) {
            $this->value = $update;
            return true;
        }
        return false;
    }

    public function weakCompareAndSet(mixed $expect, mixed $update): bool
    {
        return $this->compareAndSet($expect, $update);
    }

    public function compareAndExchange(mixed $expect, mixed $update): mixed
    {
        $current = $this->value;
        if ($current === $expect) $this->value = $update;
        return $current;
    }

    /** @param callable(V|null): (V|null) $fn */
    public function getAndUpdate(callable $fn): mixed
    {
        $old = $this->value;
        $this->value = $fn($old);
        return $old;
    }

    public function updateAndGet(callable $fn): mixed
    {
        $this->value = $fn($this->value);
        return $this->value;
    }

    public function getAndAccumulate(mixed $x, callable $fn): mixed
    {
        $old = $this->value;
        $this->value = $fn($old, $x);
        return $old;
    }

    public function accumulateAndGet(mixed $x, callable $fn): mixed
    {
        $this->value = $fn($this->value, $x);
        return $this->value;
    }

    public function __toString(): string
    {
        if ($this->value === null) return 'null';
        if (\is_object($this->value)) return $this->value::class . '@' . \spl_object_id($this->value);
        return (string) $this->value;
    }
}

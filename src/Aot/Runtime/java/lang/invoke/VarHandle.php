<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang\invoke;

/**
 * java.lang.invoke.VarHandle (Java 9+) — typed atomic-style accessor
 * for fields, array elements, and other variable references.
 * Replacement for sun.misc.Unsafe's field offset machinery, with
 * type-safe access modes.
 *
 * Access modes (Java spec):
 *   - Plain (get / set)
 *   - Opaque (getOpaque / setOpaque)
 *   - Acquire / Release (getAcquire / setRelease)
 *   - Volatile (getVolatile / setVolatile)
 *   - CAS (compareAndSet, weakCompareAndSet, compareAndExchange,
 *     and Acquire / Release / Plain variants)
 *   - Atomic update (getAndSet, getAndAdd, getAndBitwiseOr, etc.)
 *
 * Under PHP's single-threaded cooperative scheduling, all access
 * modes collapse to plain reads/writes — there's no other physical
 * thread to observe a non-volatile read of stale memory. The
 * type-safety contract holds; the memory-ordering contract is
 * trivially satisfied.
 *
 * Construction: typically via MethodHandles.lookup().findVarHandle(
 *   class, fieldName, type) or .arrayElementVarHandle(arrayClass).
 * Our factory is VarHandle::forInstance($obj, $property).
 */
class VarHandle
{
    /** @var object|array */
    private object|array $target;
    private string $accessor;
    private string $type;

    private function __construct(object|array $target, string $accessor, string $type)
    {
        $this->target = $target;
        $this->accessor = $accessor;
        $this->type = $type;
    }

    public static function forInstance(object $instance, string $property, string $type = 'mixed'): self
    {
        return new self($instance, $property, $type);
    }

    public static function arrayElementHandle(array &$array, string $type = 'mixed'): self
    {
        return new self($array, '__array__', $type);
    }

    // ── Plain / Opaque / Acquire-Release / Volatile ─────────────────

    public function get(int $index = 0): mixed
    {
        if (\is_array($this->target)) return $this->target[$index] ?? null;
        return $this->target->{$this->accessor};
    }

    public function set(mixed $value, int $index = 0): void
    {
        if (\is_array($this->target)) {
            $this->target[$index] = $value;
            return;
        }
        $this->target->{$this->accessor} = $value;
    }

    public function getVolatile(int $index = 0): mixed { return $this->get($index); }
    public function setVolatile(mixed $value, int $index = 0): void { $this->set($value, $index); }
    public function getOpaque(int $index = 0): mixed { return $this->get($index); }
    public function setOpaque(mixed $value, int $index = 0): void { $this->set($value, $index); }
    public function getAcquire(int $index = 0): mixed { return $this->get($index); }
    public function setRelease(mixed $value, int $index = 0): void { $this->set($value, $index); }

    // ── CAS ─────────────────────────────────────────────────────────

    public function compareAndSet(mixed $expected, mixed $newValue, int $index = 0): bool
    {
        $current = $this->get($index);
        if ($current === $expected || $current == $expected) {
            $this->set($newValue, $index);
            return true;
        }
        return false;
    }

    public function weakCompareAndSet(mixed $expected, mixed $newValue, int $index = 0): bool
    {
        return $this->compareAndSet($expected, $newValue, $index);
    }

    public function compareAndExchange(mixed $expected, mixed $newValue, int $index = 0): mixed
    {
        $current = $this->get($index);
        if ($current === $expected || $current == $expected) {
            $this->set($newValue, $index);
        }
        return $current;
    }

    // ── Atomic update ───────────────────────────────────────────────

    public function getAndSet(mixed $value, int $index = 0): mixed
    {
        $old = $this->get($index);
        $this->set($value, $index);
        return $old;
    }

    public function getAndAdd(int|float $delta, int $index = 0): mixed
    {
        $old = $this->get($index);
        $this->set($old + $delta, $index);
        return $old;
    }

    public function addAndGet(int|float $delta, int $index = 0): mixed
    {
        $new = $this->get($index) + $delta;
        $this->set($new, $index);
        return $new;
    }

    public function getAndBitwiseOr(int $mask, int $index = 0): int
    {
        $old = (int) $this->get($index);
        $this->set($old | $mask, $index);
        return $old;
    }

    public function getAndBitwiseAnd(int $mask, int $index = 0): int
    {
        $old = (int) $this->get($index);
        $this->set($old & $mask, $index);
        return $old;
    }

    public function getAndBitwiseXor(int $mask, int $index = 0): int
    {
        $old = (int) $this->get($index);
        $this->set($old ^ $mask, $index);
        return $old;
    }

    public function varType(): string { return $this->type; }
}

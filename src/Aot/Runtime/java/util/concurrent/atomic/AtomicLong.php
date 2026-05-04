<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\atomic;

/**
 * java.util.concurrent.atomic.AtomicLong — same as AtomicInteger but
 * for `long`. PHP int is 64-bit on 64-bit hosts so the underlying
 * storage and ops are identical; difference is only the Java surface
 * type. See AtomicInteger for the cooperative-scheduling rationale.
 *
 * Long overflow semantics: PHP int auto-promotes to float on overflow.
 * For Java-correct wrap, the AOT pipeline emits jvm_l* helpers
 * (src/Aot/Runtime/bootstrap.php) on long arithmetic — but those are
 * for IR-emitted bytecode arithmetic, not for these shim methods.
 * Direct Atomic*.addAndGet calls from Java code that expect long-wrap
 * could see PHP-float-promotion at the boundary; the audit's T1 fix
 * doesn't reach here yet. Refine if a fixture surfaces it.
 */
class AtomicLong
{
    private int $value;

    public function __construct(int $initialValue = 0)
    {
        $this->value = $initialValue;
    }

    public function get(): int { return $this->value; }
    public function set(int $newValue): void { $this->value = $newValue; }
    public function lazySet(int $newValue): void { $this->value = $newValue; }

    public function getAndSet(int $newValue): int
    {
        $old = $this->value;
        $this->value = $newValue;
        return $old;
    }

    public function compareAndSet(int $expect, int $update): bool
    {
        if ($this->value === $expect) {
            $this->value = $update;
            return true;
        }
        return false;
    }

    public function weakCompareAndSet(int $expect, int $update): bool
    {
        return $this->compareAndSet($expect, $update);
    }

    public function compareAndExchange(int $expect, int $update): int
    {
        $current = $this->value;
        if ($current === $expect) $this->value = $update;
        return $current;
    }

    public function getAndIncrement(): int { return $this->value++; }
    public function getAndDecrement(): int { return $this->value--; }
    public function incrementAndGet(): int { return ++$this->value; }
    public function decrementAndGet(): int { return --$this->value; }

    public function getAndAdd(int $delta): int
    {
        $old = $this->value;
        $this->value += $delta;
        return $old;
    }

    public function addAndGet(int $delta): int
    {
        $this->value += $delta;
        return $this->value;
    }

    public function getAndUpdate(callable $fn): int
    {
        $old = $this->value;
        $this->value = $fn($old);
        return $old;
    }

    public function updateAndGet(callable $fn): int
    {
        $this->value = $fn($this->value);
        return $this->value;
    }

    public function getAndAccumulate(int $x, callable $fn): int
    {
        $old = $this->value;
        $this->value = $fn($old, $x);
        return $old;
    }

    public function accumulateAndGet(int $x, callable $fn): int
    {
        $this->value = $fn($this->value, $x);
        return $this->value;
    }

    public function intValue(): int { return (int) $this->value; }
    public function longValue(): int { return $this->value; }
    public function floatValue(): float { return (float) $this->value; }
    public function doubleValue(): float { return (float) $this->value; }

    public function __toString(): string { return (string) $this->value; }
}

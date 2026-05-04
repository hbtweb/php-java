<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\atomic;

/**
 * java.util.concurrent.atomic.AtomicInteger — Java's atomic int.
 *
 * On JVM, `AtomicInteger.compareAndSet` uses `Unsafe.compareAndSwapInt`
 * for lock-free atomic CAS across OS threads. PHP is single-threaded
 * (cooperative scheduling within one process), so naive
 * read-compare-write produces the same observable behaviour — no
 * other code path can interleave between the read and the write
 * without yielding the Fiber, and yields are explicit (sleep/await/etc.).
 *
 * For Swoole-multi-process deployments, a future strategy-dispatcher
 * variant routes through Swoole\Atomic for cross-process semantics.
 * Not built here yet (ROADMAP §Build T3 Tier-2 shared-memory case).
 */
class AtomicInteger
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

    public function intValue(): int { return $this->value; }
    public function longValue(): int { return $this->value; }
    public function floatValue(): float { return (float) $this->value; }
    public function doubleValue(): float { return (float) $this->value; }

    public function __toString(): string { return (string) $this->value; }
}

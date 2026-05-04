<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.ArrayBlockingQueue — fixed-capacity FIFO
 * blocking queue backed by a circular array. Unlike
 * LinkedBlockingQueue it is always bounded.
 *
 * Cooperative-scheduling implementation: same waiter-park pattern
 * as LinkedBlockingQueue, but circular-array storage. When perf
 * matters more than the linked-vs-array distinction (rare in
 * Java code), this is the typical pick.
 *
 * Backing storage: PHP array indexed by takeIndex / putIndex
 * (mod capacity). PHP arrays are hashtables, so we lose the
 * cache-locality advantage Java's array gets — but the
 * AOT-compiler's escape analysis can lift this to a packed
 * sealed-shape array when the may-suspend analyser proves
 * single-fiber access.
 */
class ArrayBlockingQueue implements BlockingQueue
{
    /** @var array<int, mixed> */
    private array $items;
    private int $takeIndex = 0;
    private int $putIndex = 0;
    private int $count = 0;
    private int $capacity;
    private bool $fair;
    /** @var \Fiber[] */
    private array $putWaiters = [];
    /** @var \Fiber[] */
    private array $takeWaiters = [];

    public function __construct(int $capacity, bool $fair = false)
    {
        if ($capacity <= 0) {
            throw new \PHPJava\Packages\java\lang\IllegalArgumentException('capacity <= 0');
        }
        $this->capacity = $capacity;
        $this->fair = $fair;
        $this->items = \array_fill(0, $capacity, null);
    }

    public function size(): int { return $this->count; }
    public function isEmpty(): bool { return $this->count === 0; }
    public function remainingCapacity(): int { return $this->capacity - $this->count; }

    public function add(mixed $element): bool
    {
        if (!$this->offer($element)) {
            throw new \PHPJava\Packages\java\lang\IllegalStateException('Queue full');
        }
        return true;
    }

    public function offer(mixed $element): bool
    {
        if ($this->count >= $this->capacity) return false;
        $this->items[$this->putIndex] = $element;
        $this->putIndex = ($this->putIndex + 1) % $this->capacity;
        $this->count++;
        $this->wakeOneTake();
        return true;
    }

    public function put(mixed $element): void
    {
        while ($this->count >= $this->capacity) {
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException('ArrayBlockingQueue.put: full and no fiber to park.');
            }
            $this->putWaiters[] = $current;
            \Fiber::suspend();
        }
        $this->items[$this->putIndex] = $element;
        $this->putIndex = ($this->putIndex + 1) % $this->capacity;
        $this->count++;
        $this->wakeOneTake();
    }

    public function take(): mixed
    {
        while ($this->count === 0) {
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException('ArrayBlockingQueue.take: empty and no fiber to park.');
            }
            $this->takeWaiters[] = $current;
            \Fiber::suspend();
        }
        $value = $this->items[$this->takeIndex];
        $this->items[$this->takeIndex] = null;
        $this->takeIndex = ($this->takeIndex + 1) % $this->capacity;
        $this->count--;
        $this->wakeOnePut();
        return $value;
    }

    public function poll(): mixed
    {
        if ($this->count === 0) return null;
        $value = $this->items[$this->takeIndex];
        $this->items[$this->takeIndex] = null;
        $this->takeIndex = ($this->takeIndex + 1) % $this->capacity;
        $this->count--;
        $this->wakeOnePut();
        return $value;
    }

    public function peek(): mixed
    {
        return $this->count === 0 ? null : $this->items[$this->takeIndex];
    }

    public function remove(mixed $element = null): bool
    {
        if ($element === null) {
            if ($this->count === 0) {
                throw new \PHPJava\Aot\Runtime\java\util\NoSuchElementException();
            }
            $this->poll();
            return true;
        }
        // Linear scan
        for ($i = 0, $idx = $this->takeIndex; $i < $this->count; $i++, $idx = ($idx + 1) % $this->capacity) {
            if ($this->items[$idx] === $element || $this->items[$idx] == $element) {
                $this->removeAt($idx);
                return true;
            }
        }
        return false;
    }

    public function contains(mixed $element): bool
    {
        for ($i = 0, $idx = $this->takeIndex; $i < $this->count; $i++, $idx = ($idx + 1) % $this->capacity) {
            if ($this->items[$idx] === $element || $this->items[$idx] == $element) return true;
        }
        return false;
    }

    public function clear(): void
    {
        $this->items = \array_fill(0, $this->capacity, null);
        $this->takeIndex = 0;
        $this->putIndex = 0;
        $this->count = 0;
        $this->wakeAllPut();
    }

    public function drainTo(array &$collection, int $maxElements = \PHP_INT_MAX): int
    {
        $drained = 0;
        while ($this->count > 0 && $drained < $maxElements) {
            $collection[] = $this->items[$this->takeIndex];
            $this->items[$this->takeIndex] = null;
            $this->takeIndex = ($this->takeIndex + 1) % $this->capacity;
            $this->count--;
            $drained++;
        }
        if ($drained > 0) $this->wakeAllPut();
        return $drained;
    }

    public function isFair(): bool { return $this->fair; }

    private function removeAt(int $idx): void
    {
        // Shift subsequent elements down to fill the gap. O(n) — same
        // as Java's ABQ which also linear-shifts.
        $next = ($idx + 1) % $this->capacity;
        while ($next !== $this->putIndex) {
            $this->items[$idx] = $this->items[$next];
            $idx = $next;
            $next = ($idx + 1) % $this->capacity;
        }
        $this->items[$idx] = null;
        $this->putIndex = $idx;
        $this->count--;
        $this->wakeOnePut();
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

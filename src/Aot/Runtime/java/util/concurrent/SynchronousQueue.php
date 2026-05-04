<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.SynchronousQueue — zero-capacity rendezvous
 * queue. Every put MUST be paired with a take; either side blocks
 * until its counterpart appears.
 *
 * Common usage: hand-off between producer and consumer threads
 * with no buffering. Useful when consumer is slow and you don't
 * want backpressure to accumulate.
 *
 * Cooperative-scheduling implementation:
 *   - put with no waiting take: park the putter, value held in
 *     handover-cell
 *   - take with no waiting put: park the taker, then take the
 *     handover-cell from the putter that arrives next
 */
class SynchronousQueue implements BlockingQueue
{
    /**
     * Pending puts: each entry is [\Fiber $f, mixed $value]. The
     * value is held until a taker resumes the putter.
     * @var array<int, array{0: \Fiber, 1: mixed}>
     */
    private array $putters = [];
    /**
     * Pending takes: each entry is the parked \Fiber. Value is
     * delivered to the fiber's $directDelivery slot when a put arrives.
     * @var array<int, \Fiber>
     */
    private array $takers = [];
    /**
     * When a put resumes a parked taker, the value is stashed here
     * keyed by the taker's spl_object_id; the taker reads after
     * resume.
     * @var array<int, mixed>
     */
    private array $directDelivery = [];
    private bool $fair;

    public function __construct(bool $fair = false)
    {
        $this->fair = $fair;
    }

    public function size(): int { return 0; }              // always 0 — no buffer
    public function isEmpty(): bool { return true; }       // always empty
    public function remainingCapacity(): int { return 0; } // always full from offer's perspective
    public function peek(): mixed { return null; }         // never holds elements

    public function add(mixed $element): bool
    {
        if (!$this->offer($element)) {
            throw new \PHPJava\Packages\java\lang\IllegalStateException('Queue full');
        }
        return true;
    }

    /** Java: offer() — non-blocking. Succeeds only if a taker is waiting. */
    public function offer(mixed $element): bool
    {
        if (empty($this->takers)) return false;
        $taker = \array_shift($this->takers);
        $this->directDelivery[\spl_object_id($taker)] = $element;
        if (!$taker->isTerminated()) $taker->resume();
        return true;
    }

    /** Java: put() — blocks until a taker matches. */
    public function put(mixed $element): void
    {
        // If there's a waiting taker, hand off directly.
        if (!empty($this->takers)) {
            $taker = \array_shift($this->takers);
            $this->directDelivery[\spl_object_id($taker)] = $element;
            if (!$taker->isTerminated()) $taker->resume();
            return;
        }
        // No taker — park, holding the value until take arrives.
        $current = \Fiber::getCurrent();
        if ($current === null) {
            throw new \LogicException('SynchronousQueue.put: no taker and no fiber to park.');
        }
        $this->putters[\spl_object_id($current)] = [$current, $element];
        \Fiber::suspend();
    }

    /** Java: take() — blocks until a putter matches. */
    public function take(): mixed
    {
        // If there's a waiting putter, take its value and resume it.
        if (!empty($this->putters)) {
            $key = \array_key_first($this->putters);
            [$putter, $value] = $this->putters[$key];
            unset($this->putters[$key]);
            if (!$putter->isTerminated()) $putter->resume();
            return $value;
        }
        // No putter — park, take the directDelivery value on resume.
        $current = \Fiber::getCurrent();
        if ($current === null) {
            throw new \LogicException('SynchronousQueue.take: no putter and no fiber to park.');
        }
        $myKey = \spl_object_id($current);
        $this->takers[$myKey] = $current;
        \Fiber::suspend();
        // Resumed — value waits for us in directDelivery
        $value = $this->directDelivery[$myKey] ?? null;
        unset($this->directDelivery[$myKey]);
        return $value;
    }

    /** Java: poll() — non-blocking, returns null if no putter. */
    public function poll(): mixed
    {
        if (empty($this->putters)) return null;
        $key = \array_key_first($this->putters);
        [$putter, $value] = $this->putters[$key];
        unset($this->putters[$key]);
        if (!$putter->isTerminated()) $putter->resume();
        return $value;
    }

    public function remove(mixed $element = null): bool { return false; }
    public function contains(mixed $element): bool { return false; }
    public function clear(): void {}

    public function drainTo(array &$collection, int $maxElements = \PHP_INT_MAX): int
    {
        $drained = 0;
        while (!empty($this->putters) && $drained < $maxElements) {
            $key = \array_key_first($this->putters);
            [$putter, $value] = $this->putters[$key];
            unset($this->putters[$key]);
            $collection[] = $value;
            $drained++;
            if (!$putter->isTerminated()) $putter->resume();
        }
        return $drained;
    }

    public function isFair(): bool { return $this->fair; }
    public function hasWaitingConsumer(): bool { return !empty($this->takers); }
    public function getWaitingConsumerCount(): int { return \count($this->takers); }
}

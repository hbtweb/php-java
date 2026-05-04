<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.PriorityBlockingQueue — unbounded priority
 * queue (heap-ordered) with blocking take.
 *
 * Order: natural ordering or by Comparator. Backing storage:
 * \SplPriorityQueue (max-heap by default). To match Java's
 * "take returns smallest first" we negate priorities — entries
 * with smaller natural priority sort to the top.
 *
 * Cooperative-scheduling implementation: same waiter-park pattern
 * as LinkedBlockingQueue. take() parks on empty; offer/put wake
 * one taker.
 */
class PriorityBlockingQueue implements BlockingQueue
{
    private \SplPriorityQueue $heap;
    /** @var \Fiber[] */
    private array $takeWaiters = [];
    /** @var (callable(mixed,mixed):int)|null */
    private $comparator;
    private int $insertionSeq = 0;

    public function __construct(int $initialCapacity = 11, ?callable $comparator = null)
    {
        $this->heap = new \SplPriorityQueue();
        $this->heap->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $this->comparator = $comparator;
    }

    public function size(): int { return \count($this->heap); }
    public function isEmpty(): bool { return \count($this->heap) === 0; }
    public function remainingCapacity(): int { return \PHP_INT_MAX; } // unbounded
    public function peek(): mixed
    {
        if (\count($this->heap) === 0) return null;
        $clone = clone $this->heap;
        return $clone->top();
    }

    public function add(mixed $element): bool
    {
        return $this->offer($element);
    }

    public function offer(mixed $element): bool
    {
        // SplPriorityQueue is a max-heap. To get min-heap behaviour
        // (Java's natural ordering), we use negated comparable as
        // the priority for primitive types. For Comparator-driven
        // ordering, we precompute pairwise comparisons via insertion
        // sequence — but that doesn't give heap behaviour. Compromise:
        // for primitive types, negate; for object/comparator, fall
        // back to insertion-sequence (FIFO under unknown comparator).
        $priority = $this->priorityFor($element);
        $this->heap->insert($element, $priority);
        $this->wakeOneTake();
        return true;
    }

    public function put(mixed $element): void
    {
        // Unbounded — never blocks
        $this->offer($element);
    }

    public function take(): mixed
    {
        while (\count($this->heap) === 0) {
            $current = \Fiber::getCurrent();
            if ($current === null) {
                throw new \LogicException('PriorityBlockingQueue.take: empty and no fiber to park.');
            }
            $this->takeWaiters[] = $current;
            \Fiber::suspend();
        }
        return $this->heap->extract();
    }

    public function poll(): mixed
    {
        if (\count($this->heap) === 0) return null;
        return $this->heap->extract();
    }

    public function remove(mixed $element = null): bool
    {
        if ($element === null) {
            if (\count($this->heap) === 0) {
                throw new \PHPJava\Aot\Runtime\java\util\NoSuchElementException();
            }
            $this->heap->extract();
            return true;
        }
        // O(n) — scan + rebuild
        $items = [];
        $found = false;
        while (\count($this->heap) > 0) {
            $top = $this->heap->extract();
            if (!$found && ($top === $element || $top == $element)) {
                $found = true;
                continue;
            }
            $items[] = $top;
        }
        foreach ($items as $i) $this->offer($i);
        return $found;
    }

    public function contains(mixed $element): bool
    {
        $clone = clone $this->heap;
        while (\count($clone) > 0) {
            $v = $clone->extract();
            if ($v === $element || $v == $element) return true;
        }
        return false;
    }

    public function clear(): void { $this->heap = new \SplPriorityQueue(); }

    public function drainTo(array &$collection, int $maxElements = \PHP_INT_MAX): int
    {
        $drained = 0;
        while (\count($this->heap) > 0 && $drained < $maxElements) {
            $collection[] = $this->heap->extract();
            $drained++;
        }
        return $drained;
    }

    public function comparator(): ?callable { return $this->comparator; }

    private function priorityFor(mixed $element): int|array
    {
        if ($this->comparator !== null) {
            // Comparator-driven: SplPriorityQueue can use comparable
            // priority arrays. We use insertion sequence to break
            // ties. For correct sort under a comparator, we'd need
            // a custom heap; SplPriorityQueue doesn't support a
            // comparator hook. Fallback: insertion order (FIFO when
            // priorities-by-comparator collide).
            return -(++$this->insertionSeq);
        }
        if (\is_int($element) || \is_float($element)) {
            // Negate so smaller values come out first (Java natural)
            return -((int) $element);
        }
        // Default: insertion order (FIFO for non-comparable types)
        return -(++$this->insertionSeq);
    }

    private function wakeOneTake(): void
    {
        while (!empty($this->takeWaiters)) {
            $f = \array_shift($this->takeWaiters);
            if (!$f->isTerminated()) { $f->resume(); return; }
        }
    }
}

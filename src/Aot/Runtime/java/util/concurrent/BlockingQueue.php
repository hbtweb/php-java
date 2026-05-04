<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.BlockingQueue — Queue with blocking
 * insertion (put) and removal (take) operations. Implementations:
 * LinkedBlockingQueue (optional capacity), ArrayBlockingQueue
 * (fixed capacity), SynchronousQueue (zero-capacity rendezvous),
 * PriorityBlockingQueue (heap-ordered).
 *
 * Methods follow Java's four-quadrant convention:
 *
 *                  | Throws    | Special   | Blocks    |
 *   ---------------+-----------+-----------+-----------+
 *   Insert         | add       | offer     | put       |
 *   Remove         | remove    | poll      | take      |
 *   Examine        | element   | peek      | n/a       |
 */
interface BlockingQueue
{
    public function add(mixed $element): bool;
    public function offer(mixed $element): bool;
    public function put(mixed $element): void;
    public function take(): mixed;
    public function poll(): mixed;
    public function peek(): mixed;
    public function size(): int;
    public function isEmpty(): bool;
    public function remainingCapacity(): int;
    public function remove(mixed $element = null): bool;
    public function contains(mixed $element): bool;
    public function clear(): void;
    public function drainTo(array &$collection, int $maxElements = \PHP_INT_MAX): int;
}

<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;

/**
 * Internal ScheduledFuture implementation backed by VTE's
 * scheduleAfter timer. Tracks the originally-scheduled time so
 * getDelay() can report remaining time.
 */
class ScheduledFutureImpl implements ScheduledFuture
{
    public function __construct(
        private int $futureId,
        private int $scheduledAtNs,
    ) {}

    public function cancel(bool $mayInterruptIfRunning = false): bool
    {
        return VirtualThreadExecutor::cancel($this->futureId);
    }

    public function isCancelled(): bool { return false; }
    public function isDone(): bool { return !VirtualThreadExecutor::isPending($this->futureId); }
    public function get(): mixed { return VirtualThreadExecutor::await($this->futureId); }

    public function getDelay(TimeUnit $unit): int
    {
        $remainingNs = $this->scheduledAtNs - \hrtime(true);
        if ($remainingNs <= 0) return 0;
        return $unit->convert(\intdiv($remainingNs, 1_000_000), TimeUnit::$MILLISECONDS);
    }

    public function compareTo(Delayed $other): int
    {
        $a = $this->getDelay(TimeUnit::$NANOSECONDS);
        $b = $other->getDelay(TimeUnit::$NANOSECONDS);
        return $a <=> $b;
    }
}

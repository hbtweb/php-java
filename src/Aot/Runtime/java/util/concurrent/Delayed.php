<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.Delayed — element with a scheduled delay.
 * Used by DelayQueue and ScheduledFuture: take() blocks until the
 * head element's delay reaches zero or below.
 */
interface Delayed
{
    /** Returns the remaining delay in the given TimeUnit. */
    public function getDelay(TimeUnit $unit): int;

    /** Comparable<Delayed> — natural ordering by remaining delay. */
    public function compareTo(Delayed $other): int;
}

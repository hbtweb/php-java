<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.ScheduledFuture — Future + Delayed.
 * Returned by ScheduledExecutorService.schedule and friends.
 */
interface ScheduledFuture extends Future, Delayed
{
}

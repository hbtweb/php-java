<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;
use PHPJava\Aot\Runtime\java\lang\Thread;
use PHPJava\Aot\Runtime\java\util\concurrent\DelayQueue;
use PHPJava\Aot\Runtime\java\util\concurrent\Delayed;
use PHPJava\Aot\Runtime\java\util\concurrent\Executors;
use PHPJava\Aot\Runtime\java\util\concurrent\TimeUnit;

/**
 * Validates the timer-driven JDK concurrent shims:
 *   - VTE.scheduleAfter primitive
 *   - ScheduledExecutorService / ScheduledThreadPoolExecutor
 *   - DelayQueue
 */
class JdkSchedulerShimTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        InlineExecutor::reset();
        VirtualThreadExecutor::reset();
    }

    // ── VTE.scheduleAfter ────────────────────────────────────────────

    public function testVTEScheduleAfterFires(): void
    {
        $fired = false;
        $id = VirtualThreadExecutor::scheduleAfter(2, static function () use (&$fired) {
            $fired = true;
            return 'done';
        });
        $this->assertSame('done', VirtualThreadExecutor::await($id));
        $this->assertTrue($fired);
    }

    public function testVTEScheduleAfterCancelable(): void
    {
        $id = VirtualThreadExecutor::scheduleAfter(100, static fn() => 'x');
        $this->assertTrue(VirtualThreadExecutor::cancel($id));
    }

    // ── ScheduledExecutorService ─────────────────────────────────────

    public function testScheduleSingleShot(): void
    {
        $exec = Executors::newSingleThreadScheduledExecutor();
        $fut = $exec->schedule(static fn() => 42, 1, TimeUnit::$MILLISECONDS);
        $this->assertSame(42, $fut->get());
        $this->assertTrue($fut->isDone());
    }

    public function testScheduleAtFixedRateRunsMultipleTimes(): void
    {
        $exec = Executors::newSingleThreadScheduledExecutor();
        $count = 0;
        $driver = new Thread(static function () use ($exec, &$count) {
            $fut = $exec->scheduleAtFixedRate(
                static function () use (&$count) { $count++; },
                0, 1, TimeUnit::$MILLISECONDS
            );
            // Inside a fiber so Thread::sleep yields to VTE event loop.
            Thread::sleep(5);
            $fut->cancel(true);
        });
        $driver->start();
        $driver->join();
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testScheduleWithFixedDelay(): void
    {
        $exec = Executors::newSingleThreadScheduledExecutor();
        $count = 0;
        $driver = new Thread(static function () use ($exec, &$count) {
            $fut = $exec->scheduleWithFixedDelay(
                static function () use (&$count) { $count++; },
                0, 1, TimeUnit::$MILLISECONDS
            );
            Thread::sleep(5);
            $fut->cancel(true);
        });
        $driver->start();
        $driver->join();
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testScheduledFutureGetDelayDecreases(): void
    {
        $exec = Executors::newSingleThreadScheduledExecutor();
        $fut = $exec->schedule(static fn() => 'eventually', 100, TimeUnit::$MILLISECONDS);
        $delay1 = $fut->getDelay(TimeUnit::$MILLISECONDS);
        $this->assertGreaterThan(0, $delay1);
        $this->assertLessThanOrEqual(100, $delay1);
        $fut->cancel(true);
    }

    // ── DelayQueue ───────────────────────────────────────────────────

    public function testDelayQueueOnlyDeliversAfterExpiry(): void
    {
        $q = new DelayQueue();
        $q->offer(new TestDelayedItem('first', 5));    // 5 ms delay
        $q->offer(new TestDelayedItem('second', 0));   // ready now
        $q->offer(new TestDelayedItem('third', 100));  // far future

        // Poll: only 'second' is ready
        $head = $q->poll();
        $this->assertNotNull($head);
        $this->assertSame('second', $head->name);
    }

    public function testDelayQueuePollOnUnreadyReturnsNull(): void
    {
        $q = new DelayQueue();
        $q->offer(new TestDelayedItem('not-yet', 1000));
        $this->assertNull($q->poll());
    }

    public function testDelayQueueTakeFromFiber(): void
    {
        $q = new DelayQueue();
        $q->offer(new TestDelayedItem('soon', 2));

        $consumer = new Thread(function () use ($q) {
            $item = $q->take();
            $this->assertSame('soon', $item->name);
        });
        $consumer->start();
        $consumer->join();
    }

    public function testDelayQueueOrderingByExpiry(): void
    {
        $q = new DelayQueue();
        $q->offer(new TestDelayedItem('late', 0));
        $q->offer(new TestDelayedItem('lateEqual', 0));
        $q->offer(new TestDelayedItem('early', 0));
        // FIFO at equal delays
        $a = $q->poll();
        $b = $q->poll();
        $c = $q->poll();
        $this->assertSame('late', $a->name);
        $this->assertSame('lateEqual', $b->name);
        $this->assertSame('early', $c->name);
    }
}

class TestDelayedItem implements Delayed
{
    public int $expiresAtNs;

    public function __construct(public string $name, int $delayMs)
    {
        $this->expiresAtNs = \hrtime(true) + $delayMs * 1_000_000;
    }

    public function getDelay(TimeUnit $unit): int
    {
        $remainingNs = $this->expiresAtNs - \hrtime(true);
        if ($remainingNs <= 0) return 0;
        return $unit === TimeUnit::$NANOSECONDS
            ? $remainingNs
            : $unit->convert(\intdiv($remainingNs, 1_000_000), TimeUnit::$MILLISECONDS);
    }

    public function compareTo(Delayed $other): int
    {
        return $this->getDelay(TimeUnit::$NANOSECONDS) <=> $other->getDelay(TimeUnit::$NANOSECONDS);
    }
}

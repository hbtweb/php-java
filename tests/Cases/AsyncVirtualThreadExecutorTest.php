<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;
use PHPJava\Aot\Runtime\Async\CancellationException;
use PHPJava\Aot\Runtime\Async\InterruptedException;

/**
 * Validates the VirtualThreadExecutor Fiber-backed async runtime — for the
 * genuinely-suspending case the may-suspend analyzer routes here.
 * Bench-scope: ~1 µs/op tuned (raw Fiber suspend/resume is the
 * floor; PATTERNS.md tightening minimises everything around it).
 *
 * Workloads vs InlineExecutor:
 *   - InlineExecutor: spawn N pure-compute callbacks, await all in batch.
 *     No fibers. ~365 ns/op.
 *   - VirtualThreadExecutor: spawn callbacks that may sleep / wait / yield. Each
 *     gets a pooled fiber. ~1-12 µs depending on suspend frequency.
 *
 * The composition primitives (thenApply / allOf / anyOf) are inherited
 * from InlineExecutor when JDK shim wires them; cross-tier composition belongs
 * to the shim layer (T3 step 5).
 */
class AsyncVirtualThreadExecutorTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        VirtualThreadExecutor::reset();
    }

    public function testSimpleAsyncAwait(): void
    {
        $id = VirtualThreadExecutor::async(static fn() => 42);
        $this->assertSame(42, VirtualThreadExecutor::await($id));
    }

    public function testAwaitRethrowsRejection(): void
    {
        $id = VirtualThreadExecutor::async(static function () {
            throw new \RuntimeException('boom');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        VirtualThreadExecutor::await($id);
    }

    public function testIsPendingBeforeAwait(): void
    {
        $id = VirtualThreadExecutor::async(static fn() => 1);
        $this->assertTrue(VirtualThreadExecutor::isPending($id));
        VirtualThreadExecutor::await($id);
        $this->assertTrue(VirtualThreadExecutor::isFulfilled($id));
    }

    public function testCancelPendingFuture(): void
    {
        $id = VirtualThreadExecutor::async(static fn() => 99);
        $this->assertTrue(VirtualThreadExecutor::cancel($id));
        $this->expectException(CancellationException::class);
        VirtualThreadExecutor::await($id);
    }

    public function testSleepInsideAsyncTask(): void
    {
        // Sleep should yield to event loop so other async tasks can run.
        $log = [];
        $a = VirtualThreadExecutor::async(static function () use (&$log) {
            $log[] = 'a-start';
            VirtualThreadExecutor::sleep(5);
            $log[] = 'a-end';
            return 'a';
        });
        $b = VirtualThreadExecutor::async(static function () use (&$log) {
            $log[] = 'b-start';
            $log[] = 'b-end';
            return 'b';
        });
        $this->assertSame('a', VirtualThreadExecutor::await($a));
        $this->assertSame('b', VirtualThreadExecutor::await($b));
        // Order should show interleaving: a-start, b-start (during a's sleep),
        // b-end (b runs to completion), a-end (after sleep).
        $this->assertSame(['a-start', 'b-start', 'b-end', 'a-end'], $log);
    }

    public function testSleepRespectsTiming(): void
    {
        $t0 = \hrtime(true);
        $id = VirtualThreadExecutor::async(static function () {
            VirtualThreadExecutor::sleep(20);
            return 'done';
        });
        $r = VirtualThreadExecutor::await($id);
        $elapsedMs = (\hrtime(true) - $t0) / 1_000_000;
        $this->assertSame('done', $r);
        // Sleep was 20ms; allow generous slack for scheduling jitter.
        $this->assertGreaterThan(15, $elapsedMs, 'should have slept at least 15ms');
        $this->assertLessThan(100, $elapsedMs, 'should not have slept much more than 20ms');
    }

    public function testAwaitChainAcrossFibers(): void
    {
        // Inner fiber waits on outer fiber; settle order matters.
        $inner = VirtualThreadExecutor::async(static fn() => 10);
        $outer = VirtualThreadExecutor::async(static function () use ($inner) {
            return VirtualThreadExecutor::await($inner) + 5;
        });
        $this->assertSame(15, VirtualThreadExecutor::await($outer));
    }

    public function testInterruptDuringSleep(): void
    {
        // A task that sleeps; we interrupt it mid-flight.
        $taskFiberId = null;
        $id = VirtualThreadExecutor::async(static function () use (&$taskFiberId) {
            $taskFiberId = \spl_object_id(\Fiber::getCurrent());
            VirtualThreadExecutor::sleep(10000); // long sleep — would dominate test runtime
            return 'unreached';
        });
        // Drive the loop briefly so the task actually starts and parks.
        // We do this by spawning another task that immediately interrupts
        // the first one once it's known to be sleeping.
        $interruptor = VirtualThreadExecutor::async(static function () use (&$taskFiberId) {
            // Yield once so the sleeping task gets to register its sleep first
            VirtualThreadExecutor::sleep(1);
            if ($taskFiberId !== null) {
                VirtualThreadExecutor::interrupt($taskFiberId);
            }
            return null;
        });
        $this->expectException(InterruptedException::class);
        VirtualThreadExecutor::await($id);
    }

    public function testCancelAfterSettleReturnsFalse(): void
    {
        $id = VirtualThreadExecutor::async(static fn() => 7);
        VirtualThreadExecutor::await($id);
        $this->assertFalse(VirtualThreadExecutor::cancel($id));
    }

    public function testFiberPoolReusesAcrossTasks(): void
    {
        // Spawn many tasks; verify they all complete. Pool reuse is
        // implicit but observable: if the pool worked, no fatal alloc
        // failures and total time stays bounded.
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = VirtualThreadExecutor::async(static fn() => $i);
        }
        $results = [];
        foreach ($ids as $id) {
            $results[] = VirtualThreadExecutor::await($id);
        }
        $this->assertCount(100, $results);
    }

    public function testRunStopsOnTargetSettlement(): void
    {
        // run($until) returns when target Future settles, even if other
        // queued work remains. Verify by leaving an unresolved task.
        $a = VirtualThreadExecutor::async(static fn() => 1);
        $b = VirtualThreadExecutor::async(static function () {
            VirtualThreadExecutor::sleep(1000);
            return 'b';
        });
        // Await $a — should return without waiting for $b's long sleep.
        $t0 = \hrtime(true);
        $this->assertSame(1, VirtualThreadExecutor::await($a));
        $elapsedMs = (\hrtime(true) - $t0) / 1_000_000;
        $this->assertLessThan(50, $elapsedMs, 'await($a) should not wait for $b\'s sleep');
        // $b is still pending.
        $this->assertTrue(VirtualThreadExecutor::isPending($b));
        // Cancel $b so the loop doesn't have a dangling sleeper.
        VirtualThreadExecutor::cancel($b);
    }

    public function testCancelWakesParkedAwaiters(): void
    {
        // Two tasks: the second awaits the first. Cancel the first;
        // the second should observe cancellation through await's
        // CancellationException.
        $a = VirtualThreadExecutor::async(static function () {
            VirtualThreadExecutor::sleep(10);
            return 1;
        });
        $b = VirtualThreadExecutor::async(static function () use ($a) {
            return VirtualThreadExecutor::await($a) + 1;
        });
        VirtualThreadExecutor::cancel($a);
        // $b's await($a) should throw CancellationException, propagating
        // to $b's settle as a rejection with that exception.
        try {
            VirtualThreadExecutor::await($b);
            $this->fail('Expected CancellationException to propagate');
        } catch (CancellationException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }
    }
}

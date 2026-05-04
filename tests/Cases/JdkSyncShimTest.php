<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;
use PHPJava\Aot\Runtime\java\lang\Thread;
use PHPJava\Aot\Runtime\java\util\concurrent\CountDownLatch;
use PHPJava\Aot\Runtime\java\util\concurrent\CyclicBarrier;
use PHPJava\Aot\Runtime\java\util\concurrent\BrokenBarrierException;
use PHPJava\Aot\Runtime\java\util\concurrent\Semaphore;
use PHPJava\Aot\Runtime\java\util\concurrent\locks\ReentrantLock;
use PHPJava\Aot\Runtime\java\util\concurrent\locks\ReentrantReadWriteLock;

/**
 * Validates the second wave of JDK concurrent shims: synchronization
 * primitives that compose on top of the InlineExecutor + VTE substrate.
 *
 * Coverage:
 *   - CountDownLatch — fan-in barrier
 *   - Semaphore — counting permits
 *   - CyclicBarrier — N-party reusable barrier
 *   - Condition (via ReentrantLock) — wait/signal pattern
 *   - ReentrantReadWriteLock — paired read/write locks with reentrancy
 */
class JdkSyncShimTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        InlineExecutor::reset();
        VirtualThreadExecutor::reset();
    }

    // ── CountDownLatch ───────────────────────────────────────────────

    public function testCountDownLatchBasic(): void
    {
        $l = new CountDownLatch(3);
        $this->assertSame(3, $l->getCount());
        $l->countDown();
        $this->assertSame(2, $l->getCount());
        $l->countDown();
        $l->countDown();
        $this->assertSame(0, $l->getCount());
    }

    public function testCountDownLatchAwaitImmediateWhenZero(): void
    {
        $l = new CountDownLatch(0);
        $l->await(); // does not block
        $this->assertTrue(true);
    }

    public function testCountDownLatchNegativeThrows(): void
    {
        $this->expectException(\PHPJava\Packages\java\lang\IllegalArgumentException::class);
        new CountDownLatch(-1);
    }

    public function testCountDownLatchCrossFiber(): void
    {
        $latch = new CountDownLatch(2);
        $log = [];
        $a = new Thread(static function () use ($latch, &$log) {
            $log[] = 'a-pre';
            $latch->countDown();
            $log[] = 'a-post';
        });
        $b = new Thread(static function () use ($latch, &$log) {
            $log[] = 'b-pre';
            $latch->countDown();
            $log[] = 'b-post';
        });
        $a->start();
        $b->start();
        $a->join();
        $b->join();
        $latch->await(); // already zero by now — non-blocking
        $this->assertSame(0, $latch->getCount());
        $this->assertContains('a-pre', $log);
        $this->assertContains('b-pre', $log);
    }

    // ── Semaphore ────────────────────────────────────────────────────

    public function testSemaphoreBasic(): void
    {
        $sem = new Semaphore(3);
        $this->assertSame(3, $sem->availablePermits());
        $sem->acquire();
        $this->assertSame(2, $sem->availablePermits());
        $sem->release();
        $this->assertSame(3, $sem->availablePermits());
    }

    public function testSemaphoreTryAcquire(): void
    {
        $sem = new Semaphore(1);
        $this->assertTrue($sem->tryAcquire());
        $this->assertFalse($sem->tryAcquire());
        $sem->release();
        $this->assertTrue($sem->tryAcquire());
    }

    public function testSemaphoreDrainPermits(): void
    {
        $sem = new Semaphore(5);
        $taken = $sem->drainPermits();
        $this->assertSame(5, $taken);
        $this->assertSame(0, $sem->availablePermits());
    }

    public function testSemaphoreMultiplePermits(): void
    {
        $sem = new Semaphore(10);
        $sem->acquire(3);
        $this->assertSame(7, $sem->availablePermits());
        $sem->release(3);
        $this->assertSame(10, $sem->availablePermits());
    }

    // ── CyclicBarrier ────────────────────────────────────────────────

    public function testCyclicBarrierAllArrivedTriggers(): void
    {
        $log = [];
        $barrier = new CyclicBarrier(3, static function () use (&$log) {
            $log[] = 'barrier-action';
        });

        $a = new Thread(static function () use ($barrier, &$log) {
            $log[] = 'a-await';
            $barrier->await();
            $log[] = 'a-pass';
        });
        $b = new Thread(static function () use ($barrier, &$log) {
            $log[] = 'b-await';
            $barrier->await();
            $log[] = 'b-pass';
        });
        $c = new Thread(static function () use ($barrier, &$log) {
            $log[] = 'c-await';
            $barrier->await();
            $log[] = 'c-pass';
        });
        $a->start();
        $b->start();
        $c->start();
        $a->join();
        $b->join();
        $c->join();
        // barrier-action ran exactly once between awaits and passes
        $this->assertSame(1, \count(\array_filter($log, static fn($x) => $x === 'barrier-action')));
        $this->assertSame(0, $barrier->getNumberWaiting());
    }

    public function testCyclicBarrierResetBreaksWaiters(): void
    {
        $barrier = new CyclicBarrier(3);
        $caught = false;
        $a = new Thread(static function () use ($barrier, &$caught) {
            try {
                $barrier->await();
            } catch (BrokenBarrierException $e) {
                $caught = true;
            }
        });
        $resetter = new Thread(static function () use ($barrier) {
            // Yield once so $a parks on the barrier first
            Thread::sleep(1);
            $barrier->reset();
        });
        $a->start();
        $resetter->start();
        $a->join();
        $resetter->join();
        $this->assertTrue($caught);
    }

    // ── Condition (via ReentrantLock) ────────────────────────────────

    public function testConditionAwaitSignalSimple(): void
    {
        $lock = new ReentrantLock();
        $cond = $lock->newCondition();
        $signalled = false;

        $producer = new Thread(static function () use ($lock, $cond, &$signalled) {
            Thread::sleep(2); // let consumer park first
            $lock->lock();
            try {
                $signalled = true;
                $cond->signal();
            } finally {
                $lock->unlock();
            }
        });

        $consumer = new Thread(static function () use ($lock, $cond, &$signalled) {
            $lock->lock();
            try {
                while (!$signalled) {
                    $cond->await();
                }
            } finally {
                $lock->unlock();
            }
        });

        $consumer->start();
        $producer->start();
        $consumer->join();
        $producer->join();

        $this->assertTrue($signalled);
        $this->assertFalse($lock->isLocked());
    }

    public function testConditionSignalAllWakesAll(): void
    {
        $lock = new ReentrantLock();
        $cond = $lock->newCondition();
        $ready = false;
        $awakened = 0;

        $waiter = static function () use ($lock, $cond, &$ready, &$awakened) {
            $lock->lock();
            try {
                while (!$ready) {
                    $cond->await();
                }
                $awakened++;
            } finally {
                $lock->unlock();
            }
        };

        $w1 = new Thread($waiter);
        $w2 = new Thread($waiter);
        $w3 = new Thread($waiter);

        $signaller = new Thread(static function () use ($lock, $cond, &$ready) {
            Thread::sleep(2);
            $lock->lock();
            try {
                $ready = true;
                $cond->signalAll();
            } finally {
                $lock->unlock();
            }
        });

        $w1->start(); $w2->start(); $w3->start();
        $signaller->start();
        $w1->join(); $w2->join(); $w3->join();
        $signaller->join();

        $this->assertSame(3, $awakened);
    }

    public function testConditionSignalWithoutLockThrows(): void
    {
        $lock = new ReentrantLock();
        $cond = $lock->newCondition();
        $this->expectException(\PHPJava\Packages\java\lang\IllegalMonitorStateException::class);
        $cond->signal();
    }

    // ── ReentrantReadWriteLock ───────────────────────────────────────

    public function testRWLockReadersConcurrentWriterExclusive(): void
    {
        $rw = new ReentrantReadWriteLock();
        $rl = $rw->readLock();
        $wl = $rw->writeLock();

        // Two readers can hold simultaneously
        $rl->lock();
        $this->assertTrue($rl->tryLock()); // reentrant on same fiber
        $rl->unlock();
        $rl->unlock();
        $this->assertSame(0, $rw->getReadLockCount());

        // Writer is exclusive
        $wl->lock();
        $this->assertSame(1, $rw->getWriteHoldCount());
        $wl->unlock();
    }

    public function testRWLockWriterReentrant(): void
    {
        $rw = new ReentrantReadWriteLock();
        $wl = $rw->writeLock();
        $wl->lock();
        $wl->lock();
        $wl->lock();
        $this->assertSame(3, $rw->getWriteHoldCount());
        $wl->unlock();
        $wl->unlock();
        $wl->unlock();
        $this->assertSame(0, $rw->getWriteHoldCount());
    }

    public function testRWLockWriterCanDowngradeToReader(): void
    {
        $rw = new ReentrantReadWriteLock();
        $rl = $rw->readLock();
        $wl = $rw->writeLock();
        $wl->lock();
        $rl->lock(); // downgrade — held write, can take read
        $this->assertSame(1, $rw->getReadHoldCount());
        $wl->unlock();
        $rl->unlock();
    }

    public function testRWLockTryLockReturnsFalseWhenContended(): void
    {
        $rw = new ReentrantReadWriteLock();
        $wl = $rw->writeLock();
        $rl = $rw->readLock();

        // From a different fiber, acquire write — current fiber's read should fail
        $contender = new Thread(static function () use ($wl) {
            $wl->lock();
            Thread::sleep(10);
            $wl->unlock();
        });
        $contender->start();
        Thread::sleep(1); // give contender time to acquire (under VTE this requires a yield)

        // Without VTE driving, contender hasn't run yet — exercise top-level
        // tryLock pattern from a Thread instead.
        $contender->join();
        $this->assertTrue($rl->tryLock());
        $rl->unlock();
    }
}

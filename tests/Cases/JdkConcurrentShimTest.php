<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;
use PHPJava\Aot\Runtime\Async\CancellationException;

use PHPJava\Aot\Runtime\java\lang\Thread;
use PHPJava\Aot\Runtime\java\util\concurrent\CompletableFuture;
use PHPJava\Aot\Runtime\java\util\concurrent\atomic\AtomicInteger;
use PHPJava\Aot\Runtime\java\util\concurrent\atomic\AtomicLong;
use PHPJava\Aot\Runtime\java\util\concurrent\atomic\AtomicReference;
use PHPJava\Aot\Runtime\java\util\concurrent\locks\ReentrantLock;

/**
 * Validates the JDK concurrent shim layer wired against the
 * InlineExecutor + VirtualThreadExecutor + Unsafe runtime substrate.
 * Each test exercises a Java API surface from PHP-direct calls; the
 * AOT-compiled-bytecode-using-these-APIs path is downstream
 * (bb-allowlist fill).
 */
class JdkConcurrentShimTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        InlineExecutor::reset();
        VirtualThreadExecutor::reset();
    }

    // ── AtomicInteger ────────────────────────────────────────────────

    public function testAtomicIntegerBasic(): void
    {
        $a = new AtomicInteger(10);
        $this->assertSame(10, $a->get());
        $a->set(20);
        $this->assertSame(20, $a->get());
        $this->assertSame(20, $a->getAndSet(30));
        $this->assertSame(30, $a->get());
    }

    public function testAtomicIntegerCAS(): void
    {
        $a = new AtomicInteger(5);
        $this->assertTrue($a->compareAndSet(5, 7));
        $this->assertSame(7, $a->get());
        $this->assertFalse($a->compareAndSet(99, 100));
        $this->assertSame(7, $a->get());
    }

    public function testAtomicIntegerIncrement(): void
    {
        $a = new AtomicInteger(0);
        $this->assertSame(0, $a->getAndIncrement());
        $this->assertSame(1, $a->get());
        $this->assertSame(2, $a->incrementAndGet());
        $this->assertSame(2, $a->get());
        $this->assertSame(2, $a->getAndAdd(5));
        $this->assertSame(7, $a->get());
        $this->assertSame(10, $a->addAndGet(3));
    }

    public function testAtomicIntegerUpdaters(): void
    {
        $a = new AtomicInteger(10);
        $this->assertSame(10, $a->getAndUpdate(static fn(int $v) => $v * 2));
        $this->assertSame(20, $a->get());
        $this->assertSame(40, $a->updateAndGet(static fn(int $v) => $v * 2));
        // getAndAccumulate returns OLD value, sets fn(old, x)
        $this->assertSame(40, $a->getAndAccumulate(5, static fn(int $a, int $b) => $a + $b));
        $this->assertSame(45, $a->get());
    }

    // ── AtomicLong ───────────────────────────────────────────────────

    public function testAtomicLongHandlesLargeValues(): void
    {
        $a = new AtomicLong(\PHP_INT_MAX - 10);
        $a->addAndGet(5);
        $this->assertSame(\PHP_INT_MAX - 5, $a->get());
    }

    // ── AtomicReference ──────────────────────────────────────────────

    public function testAtomicReferenceIdentityCompare(): void
    {
        $obj1 = new \stdClass();
        $obj2 = new \stdClass();
        $a = new AtomicReference($obj1);
        $this->assertSame($obj1, $a->get());
        $this->assertTrue($a->compareAndSet($obj1, $obj2));
        $this->assertSame($obj2, $a->get());
        // Distinct-but-equal-shape object should NOT match (identity, not equality)
        $obj1Clone = new \stdClass();
        $a->set($obj1);
        $this->assertFalse($a->compareAndSet($obj1Clone, $obj2));
        $this->assertSame($obj1, $a->get());
    }

    // ── ReentrantLock ────────────────────────────────────────────────

    public function testReentrantLockBasic(): void
    {
        $lock = new ReentrantLock();
        $this->assertFalse($lock->isLocked());
        $lock->lock();
        $this->assertTrue($lock->isLocked());
        $this->assertTrue($lock->isHeldByCurrentThread());
        $this->assertSame(1, $lock->getHoldCount());
        $lock->unlock();
        $this->assertFalse($lock->isLocked());
    }

    public function testReentrantLockReentrancy(): void
    {
        $lock = new ReentrantLock();
        $lock->lock();
        $lock->lock();
        $lock->lock();
        $this->assertSame(3, $lock->getHoldCount());
        $lock->unlock();
        $lock->unlock();
        $this->assertTrue($lock->isLocked());
        $lock->unlock();
        $this->assertFalse($lock->isLocked());
    }

    public function testReentrantLockTryLock(): void
    {
        $lock = new ReentrantLock();
        $this->assertTrue($lock->tryLock());
        $this->assertTrue($lock->tryLock()); // reentrant — same holder
        $this->assertSame(2, $lock->getHoldCount());
        $lock->unlock();
        $lock->unlock();
    }

    // ── CompletableFuture — InlineExecutor-backed path ──────────────

    public function testCompletableFutureSupplyAsync(): void
    {
        $cf = CompletableFuture::supplyAsync(static fn() => 42);
        $this->assertSame(42, $cf->get());
        $this->assertTrue($cf->isDone());
    }

    public function testCompletableFutureCompletedFuture(): void
    {
        $cf = CompletableFuture::completedFuture(99);
        $this->assertSame(99, $cf->get());
        $this->assertTrue($cf->isDone());
    }

    public function testCompletableFutureFailedFuture(): void
    {
        $cf = CompletableFuture::failedFuture(new \RuntimeException('boom'));
        $this->assertTrue($cf->isCompletedExceptionally());
        $this->expectException(\RuntimeException::class);
        $cf->get();
    }

    public function testCompletableFutureThenApply(): void
    {
        $cf = CompletableFuture::supplyAsync(static fn() => 10)
            ->thenApply(static fn(int $v) => $v * 2);
        $this->assertSame(20, $cf->get());
    }

    public function testCompletableFutureThenCombine(): void
    {
        $a = CompletableFuture::supplyAsync(static fn() => 5);
        $b = CompletableFuture::supplyAsync(static fn() => 7);
        $combined = $a->thenCombine($b, static fn(int $x, int $y) => $x + $y);
        $this->assertSame(12, $combined->get());
    }

    public function testCompletableFutureExceptionally(): void
    {
        $cf = CompletableFuture::supplyAsync(static function () {
            throw new \LogicException('oops');
        })->exceptionally(static fn(\Throwable $e) => 'recovered: ' . $e->getMessage());
        $this->assertSame('recovered: oops', $cf->get());
    }

    public function testCompletableFutureAllOf(): void
    {
        $a = CompletableFuture::supplyAsync(static fn() => 1);
        $b = CompletableFuture::supplyAsync(static fn() => 2);
        $c = CompletableFuture::supplyAsync(static fn() => 3);
        $all = CompletableFuture::allOf($a, $b, $c);
        $all->get(); // triggers drain
        $this->assertTrue($a->isDone());
        $this->assertTrue($b->isDone());
        $this->assertTrue($c->isDone());
    }

    public function testCompletableFutureManualComplete(): void
    {
        $cf = new CompletableFuture();
        $this->assertFalse($cf->isDone());
        $this->assertTrue($cf->complete(123));
        $this->assertTrue($cf->isDone());
        $this->assertSame(123, $cf->get());
    }

    // ── Thread — VirtualThreadExecutor-backed ────────────────────────

    public function testThreadStartJoin(): void
    {
        $log = [];
        $t = new Thread(static function () use (&$log) {
            $log[] = 'ran';
        });
        $t->start();
        $t->join();
        $this->assertSame(['ran'], $log);
    }

    public function testThreadSleepInsideTask(): void
    {
        $log = [];
        $a = new Thread(static function () use (&$log) {
            $log[] = 'a-start';
            Thread::sleep(5);
            $log[] = 'a-end';
        });
        $b = new Thread(static function () use (&$log) {
            $log[] = 'b-start';
            $log[] = 'b-end';
        });
        $a->start();
        $b->start();
        $a->join();
        $b->join();
        // Interleaved: a yields to b during sleep
        $this->assertSame(['a-start', 'b-start', 'b-end', 'a-end'], $log);
    }

    public function testThreadDoubleStartThrows(): void
    {
        $t = new Thread(static fn() => null);
        $t->start();
        $t->join();
        $this->expectException(\PHPJava\Aot\Runtime\java\lang\IllegalThreadStateException::class);
        $t->start();
    }
}

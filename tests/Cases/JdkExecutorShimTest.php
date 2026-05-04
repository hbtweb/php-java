<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;
use PHPJava\Aot\Runtime\java\lang\Thread;
use PHPJava\Aot\Runtime\java\util\concurrent\CopyOnWriteArrayList;
use PHPJava\Aot\Runtime\java\util\concurrent\Executors;
use PHPJava\Aot\Runtime\java\util\concurrent\ExecutionException;
use PHPJava\Aot\Runtime\java\util\concurrent\ThreadPoolExecutor;
use PHPJava\Aot\Runtime\java\util\concurrent\TimeUnit;

/**
 * Validates the ExecutorService family + TimeUnit + CopyOnWriteArrayList.
 *
 * Coverage:
 *   - Executors factories (fixed, cached, single, virtualThreadPerTask)
 *   - ThreadPoolExecutor execute / submit / invokeAll / invokeAny
 *   - ExecutorService lifecycle (shutdown / awaitTermination)
 *   - TimeUnit conversions (toMillis / toNanos / convert)
 *   - CopyOnWriteArrayList add / get / set / remove / iterate
 */
class JdkExecutorShimTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        InlineExecutor::reset();
        VirtualThreadExecutor::reset();
    }

    // ── Executors factories ──────────────────────────────────────────

    public function testExecutorsFactoriesReturnExecutorService(): void
    {
        $a = Executors::newFixedThreadPool(4);
        $b = Executors::newCachedThreadPool();
        $c = Executors::newSingleThreadExecutor();
        $d = Executors::newVirtualThreadPerTaskExecutor();
        foreach ([$a, $b, $c, $d] as $exec) {
            $this->assertInstanceOf(ThreadPoolExecutor::class, $exec);
        }
        $this->assertSame(4, $a->getCorePoolSize());
        $this->assertSame(4, $a->getMaximumPoolSize());
        $this->assertSame(1, $c->getCorePoolSize());
    }

    // ── ThreadPoolExecutor ───────────────────────────────────────────

    public function testTPESubmitReturnsFuture(): void
    {
        $exec = Executors::newCachedThreadPool();
        $future = $exec->submit(static fn() => 42);
        $this->assertSame(42, $future->get());
        $this->assertTrue($future->isDone());
    }

    public function testTPEExecuteRunsTask(): void
    {
        $exec = Executors::newSingleThreadExecutor();
        $ran = false;
        $exec->execute(static function () use (&$ran) { $ran = true; });
        $exec->shutdown();
        $exec->awaitTermination(1000);
        $this->assertTrue($ran);
    }

    public function testTPEInvokeAll(): void
    {
        $exec = Executors::newCachedThreadPool();
        $tasks = [
            static fn() => 1,
            static fn() => 2,
            static fn() => 3,
        ];
        $futures = $exec->invokeAll($tasks);
        $this->assertCount(3, $futures);
        $this->assertSame(1, $futures[0]->get());
        $this->assertSame(2, $futures[1]->get());
        $this->assertSame(3, $futures[2]->get());
    }

    public function testTPEInvokeAnyReturnsFirstSuccess(): void
    {
        $exec = Executors::newCachedThreadPool();
        $tasks = [
            static fn() => 'first',
            static fn() => 'second',
        ];
        $result = $exec->invokeAny($tasks);
        $this->assertContains($result, ['first', 'second']);
    }

    public function testTPEInvokeAnyAllFailingThrows(): void
    {
        $exec = Executors::newCachedThreadPool();
        $tasks = [
            static fn() => throw new \RuntimeException('a'),
            static fn() => throw new \RuntimeException('b'),
        ];
        $this->expectException(ExecutionException::class);
        $exec->invokeAny($tasks);
    }

    public function testTPESubmitAfterShutdownThrows(): void
    {
        $exec = Executors::newCachedThreadPool();
        $exec->shutdown();
        $this->assertTrue($exec->isShutdown());
        $this->expectException(\PHPJava\Packages\java\lang\IllegalStateException::class);
        $exec->submit(static fn() => 'x');
    }

    // ── TimeUnit ─────────────────────────────────────────────────────

    public function testTimeUnitConversions(): void
    {
        $this->assertSame(1_000_000_000, TimeUnit::$SECONDS->toNanos(1));
        $this->assertSame(1_000, TimeUnit::$SECONDS->toMillis(1));
        $this->assertSame(60_000, TimeUnit::$MINUTES->toMillis(1));
        $this->assertSame(60, TimeUnit::$MINUTES->toSeconds(1));
        $this->assertSame(3_600, TimeUnit::$HOURS->toSeconds(1));
        $this->assertSame(86_400, TimeUnit::$DAYS->toSeconds(1));
    }

    public function testTimeUnitConvert(): void
    {
        // Convert 5000 ms to seconds = 5
        $this->assertSame(5, TimeUnit::$SECONDS->convert(5000, TimeUnit::$MILLISECONDS));
        // Convert 2 hours to minutes = 120
        $this->assertSame(120, TimeUnit::$MINUTES->convert(2, TimeUnit::$HOURS));
    }

    public function testTimeUnitValueOf(): void
    {
        $this->assertSame(TimeUnit::$SECONDS, TimeUnit::valueOf('SECONDS'));
        $this->assertSame(TimeUnit::$MILLISECONDS, TimeUnit::valueOf('MILLISECONDS'));
    }

    public function testTimeUnitValueOfInvalidThrows(): void
    {
        $this->expectException(\PHPJava\Packages\java\lang\IllegalArgumentException::class);
        TimeUnit::valueOf('NONESUCH');
    }

    // ── CopyOnWriteArrayList ─────────────────────────────────────────

    public function testCOWListBasic(): void
    {
        $l = new CopyOnWriteArrayList();
        $this->assertTrue($l->isEmpty());
        $l->add('a');
        $l->add('b');
        $l->add('c');
        $this->assertSame(3, $l->size());
        $this->assertSame('a', $l->get(0));
        $this->assertSame('c', $l->get(2));
    }

    public function testCOWListSetReturnsOld(): void
    {
        $l = new CopyOnWriteArrayList(['x', 'y', 'z']);
        $this->assertSame('y', $l->set(1, 'replaced'));
        $this->assertSame('replaced', $l->get(1));
    }

    public function testCOWListRemoveByIndexAndByValue(): void
    {
        $l = new CopyOnWriteArrayList(['a', 'b', 'c']);
        $this->assertSame('b', $l->remove(1));
        $this->assertSame(2, $l->size());
        $this->assertSame('a', $l->get(0));
        $this->assertSame('c', $l->get(1));

        $l->add('d');
        $this->assertTrue($l->remove(null, 'a'));
        $this->assertSame(['c', 'd'], $l->toArray());
    }

    public function testCOWListAddIfAbsent(): void
    {
        $l = new CopyOnWriteArrayList();
        $this->assertTrue($l->addIfAbsent('x'));
        $this->assertFalse($l->addIfAbsent('x'));
        $this->assertSame(1, $l->size());
    }

    public function testCOWListIteratorIsSnapshot(): void
    {
        $l = new CopyOnWriteArrayList(['a', 'b', 'c']);
        $iter = $l->iterator();
        $l->add('d'); // mutation after iterator creation
        $collected = [];
        foreach ($iter as $v) $collected[] = $v;
        $this->assertSame(['a', 'b', 'c'], $collected); // snapshot doesn't see 'd'
    }
}

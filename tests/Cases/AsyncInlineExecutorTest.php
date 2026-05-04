<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\Async\CancellationException;

/**
 * Validates the Inline async runtime — the non-suspending fast path
 * for ROADMAP §Build T3. Sized to the AOT pipeline's perf class
 * (~150-365 ns/op per the bench-amphp-probe series; the JDK shim
 * layer wires `CompletableFuture` etc. against this when bb-allowlist
 * non-stub fill exercises concurrency primitives).
 */
class AsyncInlineExecutorTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        InlineExecutor::reset();
    }

    public function testSimpleAsyncAwait(): void
    {
        $id = InlineExecutor::async(static fn() => 42);
        $this->assertSame(42, InlineExecutor::await($id));
    }

    public function testAwaitRethrowsRejection(): void
    {
        $id = InlineExecutor::async(static function () {
            throw new \RuntimeException('boom');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        InlineExecutor::await($id);
    }

    public function testIsPendingBeforeAwait(): void
    {
        $id = InlineExecutor::async(static fn() => 1);
        $this->assertTrue(InlineExecutor::isPending($id));
        InlineExecutor::await($id);
        $this->assertFalse(InlineExecutor::isPending($id));
        $this->assertTrue(InlineExecutor::isFulfilled($id));
    }

    public function testCancelPendingFuture(): void
    {
        $id = InlineExecutor::async(static fn() => 99);
        $this->assertTrue(InlineExecutor::cancel($id));
        $this->expectException(CancellationException::class);
        InlineExecutor::await($id);
    }

    public function testCancelAfterSettleReturnsFalse(): void
    {
        $id = InlineExecutor::async(static fn() => 7);
        InlineExecutor::await($id);
        $this->assertFalse(InlineExecutor::cancel($id), 'cancel of already-settled returns false');
    }

    public function testThenApplyOnFulfilled(): void
    {
        $a = InlineExecutor::async(static fn() => 10);
        $b = InlineExecutor::thenApply($a, static fn(int $v) => $v * 2);
        $this->assertSame(20, InlineExecutor::await($b));
    }

    public function testThenApplyChain(): void
    {
        $a = InlineExecutor::async(static fn() => 1);
        $b = InlineExecutor::thenApply($a, static fn(int $v) => $v + 10);
        $c = InlineExecutor::thenApply($b, static fn(int $v) => $v * 3);
        $this->assertSame(33, InlineExecutor::await($c));
    }

    public function testThenApplyPropagatesRejection(): void
    {
        $a = InlineExecutor::async(static function () {
            throw new \LogicException('source-error');
        });
        $b = InlineExecutor::thenApply($a, static fn($v) => $v + 1);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('source-error');
        InlineExecutor::await($b);
    }

    public function testThenApplyAfterSourceFulfilled(): void
    {
        // Trigger source to settle first, then attach thenApply
        $a = InlineExecutor::async(static fn() => 5);
        InlineExecutor::await($a);
        $this->assertTrue(InlineExecutor::isFulfilled($a));
        $b = InlineExecutor::thenApply($a, static fn(int $v) => $v + 100);
        $this->assertSame(105, InlineExecutor::await($b));
    }

    public function testAllOfSuccess(): void
    {
        $ids = [
            InlineExecutor::async(static fn() => 1),
            InlineExecutor::async(static fn() => 2),
            InlineExecutor::async(static fn() => 3),
        ];
        $combined = InlineExecutor::allOf(...$ids);
        $this->assertSame([1, 2, 3], InlineExecutor::await($combined));
    }

    public function testAllOfPropagatesFirstRejection(): void
    {
        $a = InlineExecutor::async(static fn() => 1);
        $b = InlineExecutor::async(static function () {
            throw new \DomainException('mid-failure');
        });
        $c = InlineExecutor::async(static fn() => 3);
        $combined = InlineExecutor::allOf($a, $b, $c);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('mid-failure');
        InlineExecutor::await($combined);
    }

    public function testAllOfEmptyResolvesImmediately(): void
    {
        $combined = InlineExecutor::allOf();
        $this->assertSame([], InlineExecutor::await($combined));
    }

    public function testAnyOfFirstFulfilled(): void
    {
        $ids = [
            InlineExecutor::async(static fn() => 'first'),
            InlineExecutor::async(static fn() => 'second'),
        ];
        $first = InlineExecutor::anyOf(...$ids);
        // Drain order is FIFO; first task runs first.
        $this->assertSame('first', InlineExecutor::await($first));
    }

    public function testAnyOfPropagatesFirstRejection(): void
    {
        $a = InlineExecutor::async(static function () {
            throw new \RuntimeException('first-fail');
        });
        $b = InlineExecutor::async(static fn() => 'b');
        $first = InlineExecutor::anyOf($a, $b);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('first-fail');
        InlineExecutor::await($first);
    }

    public function testCascadingThenApplyAndAllOf(): void
    {
        $a = InlineExecutor::async(static fn() => 2);
        $b = InlineExecutor::thenApply($a, static fn(int $v) => $v * 10);   // 20
        $c = InlineExecutor::async(static fn() => 5);
        $d = InlineExecutor::thenApply($c, static fn(int $v) => $v + 1);    // 6
        $combined = InlineExecutor::allOf($b, $d);
        $this->assertSame([20, 6], InlineExecutor::await($combined));
    }
}

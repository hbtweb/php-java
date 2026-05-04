<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Runtime\Async\TierA;
use PHPJava\Aot\Runtime\Async\CancellationException;

/**
 * Validates the Tier A async runtime — the non-suspending fast path
 * for ROADMAP §Build T3. Sized to the AOT pipeline's perf class
 * (~150-365 ns/op per the bench-amphp-probe series; the JDK shim
 * layer wires `CompletableFuture` etc. against this when bb-allowlist
 * non-stub fill exercises concurrency primitives).
 */
class AsyncTierATest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        TierA::reset();
    }

    public function testSimpleAsyncAwait(): void
    {
        $id = TierA::async(static fn() => 42);
        $this->assertSame(42, TierA::await($id));
    }

    public function testAwaitRethrowsRejection(): void
    {
        $id = TierA::async(static function () {
            throw new \RuntimeException('boom');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        TierA::await($id);
    }

    public function testIsPendingBeforeAwait(): void
    {
        $id = TierA::async(static fn() => 1);
        $this->assertTrue(TierA::isPending($id));
        TierA::await($id);
        $this->assertFalse(TierA::isPending($id));
        $this->assertTrue(TierA::isFulfilled($id));
    }

    public function testCancelPendingFuture(): void
    {
        $id = TierA::async(static fn() => 99);
        $this->assertTrue(TierA::cancel($id));
        $this->expectException(CancellationException::class);
        TierA::await($id);
    }

    public function testCancelAfterSettleReturnsFalse(): void
    {
        $id = TierA::async(static fn() => 7);
        TierA::await($id);
        $this->assertFalse(TierA::cancel($id), 'cancel of already-settled returns false');
    }

    public function testThenApplyOnFulfilled(): void
    {
        $a = TierA::async(static fn() => 10);
        $b = TierA::thenApply($a, static fn(int $v) => $v * 2);
        $this->assertSame(20, TierA::await($b));
    }

    public function testThenApplyChain(): void
    {
        $a = TierA::async(static fn() => 1);
        $b = TierA::thenApply($a, static fn(int $v) => $v + 10);
        $c = TierA::thenApply($b, static fn(int $v) => $v * 3);
        $this->assertSame(33, TierA::await($c));
    }

    public function testThenApplyPropagatesRejection(): void
    {
        $a = TierA::async(static function () {
            throw new \LogicException('source-error');
        });
        $b = TierA::thenApply($a, static fn($v) => $v + 1);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('source-error');
        TierA::await($b);
    }

    public function testThenApplyAfterSourceFulfilled(): void
    {
        // Trigger source to settle first, then attach thenApply
        $a = TierA::async(static fn() => 5);
        TierA::await($a);
        $this->assertTrue(TierA::isFulfilled($a));
        $b = TierA::thenApply($a, static fn(int $v) => $v + 100);
        $this->assertSame(105, TierA::await($b));
    }

    public function testAllOfSuccess(): void
    {
        $ids = [
            TierA::async(static fn() => 1),
            TierA::async(static fn() => 2),
            TierA::async(static fn() => 3),
        ];
        $combined = TierA::allOf(...$ids);
        $this->assertSame([1, 2, 3], TierA::await($combined));
    }

    public function testAllOfPropagatesFirstRejection(): void
    {
        $a = TierA::async(static fn() => 1);
        $b = TierA::async(static function () {
            throw new \DomainException('mid-failure');
        });
        $c = TierA::async(static fn() => 3);
        $combined = TierA::allOf($a, $b, $c);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('mid-failure');
        TierA::await($combined);
    }

    public function testAllOfEmptyResolvesImmediately(): void
    {
        $combined = TierA::allOf();
        $this->assertSame([], TierA::await($combined));
    }

    public function testAnyOfFirstFulfilled(): void
    {
        $ids = [
            TierA::async(static fn() => 'first'),
            TierA::async(static fn() => 'second'),
        ];
        $first = TierA::anyOf(...$ids);
        // Drain order is FIFO; first task runs first.
        $this->assertSame('first', TierA::await($first));
    }

    public function testAnyOfPropagatesFirstRejection(): void
    {
        $a = TierA::async(static function () {
            throw new \RuntimeException('first-fail');
        });
        $b = TierA::async(static fn() => 'b');
        $first = TierA::anyOf($a, $b);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('first-fail');
        TierA::await($first);
    }

    public function testCascadingThenApplyAndAllOf(): void
    {
        $a = TierA::async(static fn() => 2);
        $b = TierA::thenApply($a, static fn(int $v) => $v * 10);   // 20
        $c = TierA::async(static fn() => 5);
        $d = TierA::thenApply($c, static fn(int $v) => $v + 1);    // 6
        $combined = TierA::allOf($b, $d);
        $this->assertSame([20, 6], TierA::await($combined));
    }
}

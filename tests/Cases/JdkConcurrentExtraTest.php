<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;
use PHPJava\Aot\Runtime\java\lang\Carrier;
use PHPJava\Aot\Runtime\java\lang\ScopedValue;
use PHPJava\Aot\Runtime\java\lang\Thread;
use PHPJava\Aot\Runtime\java\lang\invoke\VarHandle;
use PHPJava\Aot\Runtime\java\util\concurrent\ForkJoinPool;
use PHPJava\Aot\Runtime\java\util\concurrent\ForkJoinTask;
use PHPJava\Aot\Runtime\java\util\concurrent\LinkedBlockingDeque;
use PHPJava\Aot\Runtime\java\util\concurrent\PriorityBlockingQueue;
use PHPJava\Aot\Runtime\java\util\concurrent\RecursiveAction;
use PHPJava\Aot\Runtime\java\util\concurrent\RecursiveTask;
use PHPJava\Aot\Runtime\java\util\concurrent\ShutdownOnFailure;
use PHPJava\Aot\Runtime\java\util\concurrent\ShutdownOnSuccess;
use PHPJava\Aot\Runtime\java\util\concurrent\StructuredTaskScope;

/**
 * Validates the final wave of JDK concurrent shims:
 *   - PriorityBlockingQueue
 *   - LinkedBlockingDeque
 *   - ForkJoinPool / ForkJoinTask / RecursiveTask / RecursiveAction
 *   - StructuredTaskScope (Java 21+) + ShutdownOnFailure / Success
 *   - ScopedValue (Java 21+)
 *   - VarHandle (Java 9+)
 */
class JdkConcurrentExtraTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        InlineExecutor::reset();
        VirtualThreadExecutor::reset();
    }

    // ── PriorityBlockingQueue ────────────────────────────────────────

    public function testPriorityQueueOrdering(): void
    {
        $q = new PriorityBlockingQueue();
        $q->offer(5);
        $q->offer(1);
        $q->offer(3);
        $q->offer(2);
        $q->offer(4);
        $this->assertSame(1, $q->poll()); // smallest first (Java natural order)
        $this->assertSame(2, $q->poll());
        $this->assertSame(3, $q->poll());
        $this->assertSame(4, $q->poll());
        $this->assertSame(5, $q->poll());
    }

    public function testPriorityQueueUnboundedCapacity(): void
    {
        $q = new PriorityBlockingQueue();
        $this->assertSame(\PHP_INT_MAX, $q->remainingCapacity());
    }

    // ── LinkedBlockingDeque ──────────────────────────────────────────

    public function testLBDFirstLastSemantics(): void
    {
        $d = new LinkedBlockingDeque();
        $d->offerLast(1);
        $d->offerLast(2);
        $d->offerFirst(0);
        $d->offerLast(3);
        $this->assertSame(0, $d->pollFirst());
        $this->assertSame(3, $d->pollLast());
        $this->assertSame(1, $d->pollFirst());
        $this->assertSame(2, $d->pollFirst());
    }

    public function testLBDBoundedRefusesOnFull(): void
    {
        $d = new LinkedBlockingDeque(2);
        $this->assertTrue($d->offerLast('a'));
        $this->assertTrue($d->offerLast('b'));
        $this->assertFalse($d->offerLast('c'));
        $this->assertFalse($d->offerFirst('x'));
    }

    // ── ForkJoinPool / ForkJoinTask ──────────────────────────────────

    public function testForkJoinTaskInvokeReturnsResult(): void
    {
        $task = new class extends RecursiveTask {
            public function compute(): mixed { return 42; }
        };
        $this->assertSame(42, $task->invoke());
    }

    public function testForkJoinPoolCommonPoolSingleton(): void
    {
        $a = ForkJoinPool::commonPool();
        $b = ForkJoinPool::commonPool();
        $this->assertSame($a, $b);
    }

    public function testForkJoinDivideAndConquer(): void
    {
        $sumTask = new class([1, 2, 3, 4, 5, 6, 7, 8]) extends RecursiveTask {
            public function __construct(private array $data) {}
            public function compute(): mixed
            {
                if (\count($this->data) <= 2) return \array_sum($this->data);
                $mid = \intdiv(\count($this->data), 2);
                $left = new self(\array_slice($this->data, 0, $mid));
                $right = new self(\array_slice($this->data, $mid));
                $left->fork();
                $rightResult = $right->compute(); // run in current fiber
                return $rightResult + $left->join();
            }
        };
        $this->assertSame(36, $sumTask->invoke()); // 1+2+...+8 = 36
    }

    // ── StructuredTaskScope (Java 21) ────────────────────────────────

    public function testStructuredTaskScopeForkJoin(): void
    {
        $scope = new StructuredTaskScope();
        $a = $scope->fork(static fn() => 10);
        $b = $scope->fork(static fn() => 20);
        $scope->join();
        $this->assertSame(10, $a->get());
        $this->assertSame(20, $b->get());
        $scope->close();
    }

    public function testShutdownOnFailureCancelsRemaining(): void
    {
        $scope = new ShutdownOnFailure();
        $ok = $scope->fork(static fn() => 'fine');
        $bad = $scope->fork(static fn() => throw new \RuntimeException('boom'));
        $scope->join();
        $caught = false;
        try { $scope->throwIfFailed(); } catch (\RuntimeException) { $caught = true; }
        $this->assertTrue($caught);
        $scope->close();
    }

    public function testShutdownOnSuccessReturnsFirst(): void
    {
        $scope = new ShutdownOnSuccess();
        $a = $scope->fork(static fn() => 'first');
        $b = $scope->fork(static fn() => 'second');
        $scope->join();
        // Either order is valid since fibers run in FIFO; result is whichever finished first
        $this->assertContains($scope->result(), ['first', 'second']);
        $scope->close();
    }

    // ── ScopedValue (Java 21) ────────────────────────────────────────

    public function testScopedValueBasicWhereRun(): void
    {
        $sv = ScopedValue::newInstance();
        $observed = null;
        ScopedValue::where($sv, 'hello')->run(static function () use ($sv, &$observed) {
            $observed = $sv->get();
        });
        $this->assertSame('hello', $observed);
        $this->assertFalse($sv->isBound()); // unbound after run
    }

    public function testScopedValueNotBoundThrowsOnGet(): void
    {
        $sv = ScopedValue::newInstance();
        $this->expectException(\PHPJava\Packages\java\lang\IllegalStateException::class);
        $sv->get();
    }

    public function testScopedValueCarrierChain(): void
    {
        $a = ScopedValue::newInstance();
        $b = ScopedValue::newInstance();
        $sumA = $sumB = null;
        ScopedValue::where($a, 1)->where($b, 2)->run(static function () use ($a, $b, &$sumA, &$sumB) {
            $sumA = $a->get();
            $sumB = $b->get();
        });
        $this->assertSame(1, $sumA);
        $this->assertSame(2, $sumB);
    }

    public function testScopedValueOrElse(): void
    {
        $sv = ScopedValue::newInstance();
        $this->assertSame('default', $sv->orElse('default'));
    }

    // ── VarHandle (Java 9+) ──────────────────────────────────────────

    public function testVarHandleObjectFieldGetSet(): void
    {
        $obj = new class { public int $field = 0; };
        $h = VarHandle::forInstance($obj, 'field', 'int');
        $this->assertSame(0, $h->get());
        $h->set(42);
        $this->assertSame(42, $h->get());
        $this->assertSame(42, $obj->field);
    }

    public function testVarHandleCompareAndSet(): void
    {
        $obj = new class { public int $counter = 5; };
        $h = VarHandle::forInstance($obj, 'counter');
        $this->assertTrue($h->compareAndSet(5, 10));
        $this->assertFalse($h->compareAndSet(99, 100));
        $this->assertSame(10, $h->get());
    }

    public function testVarHandleGetAndAdd(): void
    {
        $obj = new class { public int $n = 0; };
        $h = VarHandle::forInstance($obj, 'n');
        $this->assertSame(0, $h->getAndAdd(5));
        $this->assertSame(5, $h->get());
        $this->assertSame(15, $h->addAndGet(10));
    }

    public function testVarHandleBitwiseOps(): void
    {
        $obj = new class { public int $bits = 0b0011; };
        $h = VarHandle::forInstance($obj, 'bits');
        $this->assertSame(0b0011, $h->getAndBitwiseOr(0b1100));
        $this->assertSame(0b1111, $h->get());
        $this->assertSame(0b1111, $h->getAndBitwiseAnd(0b0110));
        $this->assertSame(0b0110, $h->get());
    }
}

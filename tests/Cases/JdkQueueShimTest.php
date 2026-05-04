<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;
use PHPJava\Aot\Runtime\java\lang\Thread;
use PHPJava\Aot\Runtime\java\util\concurrent\ArrayBlockingQueue;
use PHPJava\Aot\Runtime\java\util\concurrent\ConcurrentHashMap;
use PHPJava\Aot\Runtime\java\util\concurrent\LinkedBlockingQueue;
use PHPJava\Aot\Runtime\java\util\concurrent\SynchronousQueue;

/**
 * Validates the third wave of JDK concurrent shims:
 *   - LinkedBlockingQueue  (optional capacity, FIFO)
 *   - ArrayBlockingQueue   (fixed capacity, FIFO, circular buffer)
 *   - SynchronousQueue     (zero-capacity rendezvous)
 *   - ConcurrentHashMap    (single-mutex; observably equivalent
 *                          for PHP-AOT workloads)
 */
class JdkQueueShimTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        InlineExecutor::reset();
        VirtualThreadExecutor::reset();
    }

    // ── LinkedBlockingQueue ──────────────────────────────────────────

    public function testLBQBasicOfferPoll(): void
    {
        $q = new LinkedBlockingQueue();
        $this->assertTrue($q->isEmpty());
        $q->offer('a');
        $q->offer('b');
        $q->offer('c');
        $this->assertSame(3, $q->size());
        $this->assertSame('a', $q->poll());
        $this->assertSame('b', $q->poll());
        $this->assertSame('c', $q->poll());
        $this->assertNull($q->poll());
    }

    public function testLBQBoundedOfferReturnsFalseWhenFull(): void
    {
        $q = new LinkedBlockingQueue(2);
        $this->assertTrue($q->offer('a'));
        $this->assertTrue($q->offer('b'));
        $this->assertFalse($q->offer('c'));
        $this->assertSame(2, $q->size());
    }

    public function testLBQAddThrowsWhenFull(): void
    {
        $q = new LinkedBlockingQueue(1);
        $q->add('a');
        $this->expectException(\PHPJava\Packages\java\lang\IllegalStateException::class);
        $q->add('b');
    }

    public function testLBQTakeBlocksUntilOffer(): void
    {
        $q = new LinkedBlockingQueue();
        $log = [];

        $consumer = new Thread(static function () use ($q, &$log) {
            $log[] = 'consumer-pre-take';
            $value = $q->take();
            $log[] = "consumer-got-{$value}";
        });
        $producer = new Thread(static function () use ($q, &$log) {
            Thread::sleep(2);
            $log[] = 'producer-put';
            $q->put('hello');
        });

        $consumer->start();
        $producer->start();
        $consumer->join();
        $producer->join();

        $this->assertSame(
            ['consumer-pre-take', 'producer-put', 'consumer-got-hello'],
            $log
        );
    }

    public function testLBQDrainTo(): void
    {
        $q = new LinkedBlockingQueue();
        $q->put(1); $q->put(2); $q->put(3);
        $sink = [];
        $count = $q->drainTo($sink);
        $this->assertSame(3, $count);
        $this->assertSame([1, 2, 3], $sink);
        $this->assertTrue($q->isEmpty());
    }

    // ── ArrayBlockingQueue ───────────────────────────────────────────

    public function testABQCircularBuffer(): void
    {
        $q = new ArrayBlockingQueue(3);
        $q->offer(1); $q->offer(2); $q->offer(3);
        $this->assertSame(0, $q->remainingCapacity());
        $this->assertSame(1, $q->poll()); // wraps
        $q->offer(4);                     // wraps to slot 0
        $this->assertSame(2, $q->poll());
        $this->assertSame(3, $q->poll());
        $this->assertSame(4, $q->poll());
        $this->assertNull($q->poll());
    }

    public function testABQPutBlocksUntilTake(): void
    {
        $q = new ArrayBlockingQueue(1);
        $q->put('first');
        $log = [];
        $producer = new Thread(static function () use ($q, &$log) {
            $log[] = 'producer-pre-put';
            $q->put('second');
            $log[] = 'producer-put-returned';
        });
        $consumer = new Thread(static function () use ($q, &$log) {
            Thread::sleep(2);
            $log[] = 'consumer-take';
            $q->take();
        });
        $producer->start();
        $consumer->start();
        $producer->join();
        $consumer->join();
        $this->assertSame(
            ['producer-pre-put', 'consumer-take', 'producer-put-returned'],
            $log
        );
        $this->assertSame('second', $q->poll());
    }

    public function testABQContains(): void
    {
        $q = new ArrayBlockingQueue(5);
        $q->offer('a'); $q->offer('b'); $q->offer('c');
        $this->assertTrue($q->contains('b'));
        $this->assertFalse($q->contains('z'));
    }

    // ── SynchronousQueue ─────────────────────────────────────────────

    public function testSynchronousQueueOfferReturnsFalseWithoutTaker(): void
    {
        $q = new SynchronousQueue();
        $this->assertFalse($q->offer('value'));
    }

    public function testSynchronousQueueRendezvous(): void
    {
        $q = new SynchronousQueue();
        $log = [];
        $consumer = new Thread(static function () use ($q, &$log) {
            $log[] = 'consumer-take';
            $value = $q->take();
            $log[] = "consumer-got-{$value}";
        });
        $producer = new Thread(static function () use ($q, &$log) {
            Thread::sleep(2);
            $log[] = 'producer-put';
            $q->put('handed-off');
        });

        $consumer->start();
        $producer->start();
        $consumer->join();
        $producer->join();

        $this->assertSame(
            ['consumer-take', 'producer-put', 'consumer-got-handed-off'],
            $log
        );
    }

    public function testSynchronousQueueAlwaysEmpty(): void
    {
        $q = new SynchronousQueue();
        $this->assertSame(0, $q->size());
        $this->assertTrue($q->isEmpty());
        $this->assertNull($q->peek());
    }

    // ── ConcurrentHashMap ────────────────────────────────────────────

    public function testCHMBasic(): void
    {
        $m = new ConcurrentHashMap();
        $this->assertTrue($m->isEmpty());
        $this->assertNull($m->put('a', 1));
        $this->assertNull($m->put('b', 2));
        $this->assertSame(1, $m->get('a'));
        $this->assertSame(2, $m->get('b'));
        $this->assertSame(2, $m->size());
    }

    public function testCHMPutIfAbsent(): void
    {
        $m = new ConcurrentHashMap();
        $this->assertNull($m->putIfAbsent('k', 'v1'));
        $this->assertSame('v1', $m->putIfAbsent('k', 'v2'));
        $this->assertSame('v1', $m->get('k'));
    }

    public function testCHMComputeIfAbsent(): void
    {
        $m = new ConcurrentHashMap();
        $val = $m->computeIfAbsent('k', static fn($k) => "v-for-{$k}");
        $this->assertSame('v-for-k', $val);
        $val2 = $m->computeIfAbsent('k', static fn($k) => 'should-not-be-called');
        $this->assertSame('v-for-k', $val2);
    }

    public function testCHMMerge(): void
    {
        $m = new ConcurrentHashMap();
        $m->merge('counter', 1, static fn($a, $b) => $a + $b);
        $m->merge('counter', 5, static fn($a, $b) => $a + $b);
        $m->merge('counter', 3, static fn($a, $b) => $a + $b);
        $this->assertSame(9, $m->get('counter'));
    }

    public function testCHMRejectsNullValue(): void
    {
        $m = new ConcurrentHashMap();
        $this->expectException(\PHPJava\Packages\java\lang\NullPointerException::class);
        $m->put('k', null);
    }

    public function testCHMRemoveConditional(): void
    {
        $m = new ConcurrentHashMap();
        $m->put('k', 'v1');
        $this->assertFalse($m->remove('k', 'wrong'));
        $this->assertTrue($m->remove('k', 'v1'));
        $this->assertNull($m->get('k'));
    }

    public function testCHMReplaceConditional(): void
    {
        $m = new ConcurrentHashMap();
        $m->put('k', 'v1');
        $this->assertFalse($m->replace('k', 'v2', 'wrong'));
        $this->assertTrue($m->replace('k', 'v2', 'v1'));
        $this->assertSame('v2', $m->get('k'));
    }
}

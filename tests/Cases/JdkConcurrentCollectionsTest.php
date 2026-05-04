<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;
use PHPJava\Aot\Runtime\java\lang\Thread;
use PHPJava\Aot\Runtime\java\util\concurrent\ConcurrentLinkedDeque;
use PHPJava\Aot\Runtime\java\util\concurrent\ConcurrentLinkedQueue;
use PHPJava\Aot\Runtime\java\util\concurrent\CopyOnWriteArraySet;
use PHPJava\Aot\Runtime\java\util\concurrent\Phaser;
use PHPJava\Aot\Runtime\java\util\concurrent\locks\StampedLock;

/**
 * Validates the fifth wave of JDK concurrent shims:
 *   - ConcurrentLinkedQueue, ConcurrentLinkedDeque
 *   - CopyOnWriteArraySet
 *   - StampedLock (with optimistic-read fast path)
 *   - Phaser (dynamic-party barrier)
 */
class JdkConcurrentCollectionsTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        InlineExecutor::reset();
        VirtualThreadExecutor::reset();
    }

    // ── ConcurrentLinkedQueue ────────────────────────────────────────

    public function testCLQOfferPoll(): void
    {
        $q = new ConcurrentLinkedQueue();
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

    public function testCLQContainsRemove(): void
    {
        $q = new ConcurrentLinkedQueue([1, 2, 3, 4]);
        $this->assertTrue($q->contains(3));
        $this->assertTrue($q->remove(2));
        $this->assertSame(3, $q->size());
        $this->assertFalse($q->contains(2));
    }

    // ── ConcurrentLinkedDeque ────────────────────────────────────────

    public function testCLDFirstLast(): void
    {
        $d = new ConcurrentLinkedDeque();
        $d->addLast('mid');
        $d->addFirst('first');
        $d->addLast('last');
        $this->assertSame('first', $d->peekFirst());
        $this->assertSame('last', $d->peekLast());
        $this->assertSame('first', $d->pollFirst());
        $this->assertSame('last', $d->pollLast());
        $this->assertSame('mid', $d->pollFirst());
    }

    public function testCLDStackSemantics(): void
    {
        $d = new ConcurrentLinkedDeque();
        $d->push(1);
        $d->push(2);
        $d->push(3);
        $this->assertSame(3, $d->pop());
        $this->assertSame(2, $d->pop());
        $this->assertSame(1, $d->pop());
    }

    public function testCLDPollFromEmptyReturnsNull(): void
    {
        $d = new ConcurrentLinkedDeque();
        $this->assertNull($d->pollFirst());
        $this->assertNull($d->pollLast());
    }

    // ── CopyOnWriteArraySet ──────────────────────────────────────────

    public function testCOWSetUniqueness(): void
    {
        $s = new CopyOnWriteArraySet();
        $this->assertTrue($s->add('a'));
        $this->assertTrue($s->add('b'));
        $this->assertFalse($s->add('a')); // duplicate
        $this->assertSame(2, $s->size());
    }

    public function testCOWSetRemove(): void
    {
        $s = new CopyOnWriteArraySet(['a', 'b', 'c']);
        $this->assertSame(3, $s->size());
        $this->assertTrue($s->remove('b'));
        $this->assertFalse($s->remove('z'));
        $this->assertSame(2, $s->size());
    }

    // ── StampedLock ──────────────────────────────────────────────────

    public function testStampedLockWriteThenRead(): void
    {
        $lock = new StampedLock();
        $w = $lock->writeLock();
        $this->assertNotSame(0, $w);
        $this->assertTrue($lock->isWriteLocked());
        $lock->unlockWrite($w);
        $this->assertFalse($lock->isWriteLocked());

        $r1 = $lock->readLock();
        $r2 = $lock->readLock();
        $this->assertSame(2, $lock->getReadLockCount());
        $lock->unlockRead($r1);
        $lock->unlockRead($r2);
    }

    public function testStampedLockOptimisticReadValidatesUntilWrite(): void
    {
        $lock = new StampedLock();
        $stamp = $lock->tryOptimisticRead();
        $this->assertNotSame(0, $stamp);
        $this->assertTrue($lock->validate($stamp));

        // Acquire + release write — invalidates the optimistic stamp
        $w = $lock->writeLock();
        $lock->unlockWrite($w);
        $this->assertFalse($lock->validate($stamp));
    }

    public function testStampedLockTryWriteWhenContendedReturnsZero(): void
    {
        $lock = new StampedLock();
        $w = $lock->writeLock();
        $this->assertSame(0, $lock->tryWriteLock());
        $this->assertSame(0, $lock->tryReadLock());
        $lock->unlockWrite($w);
        $this->assertNotSame(0, $lock->tryReadLock());
    }

    public function testStampedLockUnlockWithBadStampThrows(): void
    {
        $lock = new StampedLock();
        $this->expectException(\PHPJava\Packages\java\lang\IllegalMonitorStateException::class);
        $lock->unlockWrite(99999);
    }

    // ── Phaser ───────────────────────────────────────────────────────

    public function testPhaserInitialPhaseZero(): void
    {
        $p = new Phaser(3);
        $this->assertSame(0, $p->getPhase());
        $this->assertSame(3, $p->getRegisteredParties());
    }

    public function testPhaserDynamicRegister(): void
    {
        $p = new Phaser();
        $p->register();
        $p->register();
        $p->bulkRegister(3);
        $this->assertSame(5, $p->getRegisteredParties());
    }

    public function testPhaserArriveAndAwaitAcrossThreads(): void
    {
        $p = new Phaser(3);
        $log = [];

        $worker = static function (string $id) use ($p, &$log) {
            $phase = $p->arriveAndAwaitAdvance();
            $log[] = "{$id}-after-phase-0";
        };

        $a = new Thread(static fn() => $worker('a'));
        $b = new Thread(static fn() => $worker('b'));
        $c = new Thread(static fn() => $worker('c'));
        $a->start(); $b->start(); $c->start();
        $a->join();  $b->join();  $c->join();

        $this->assertSame(3, \count($log));
        $this->assertSame(1, $p->getPhase());
    }

    public function testPhaserArriveAndDeregisterTerminatesWhenZeroParties(): void
    {
        $p = new Phaser(1);
        $p->arriveAndDeregister();
        $this->assertTrue($p->isTerminated());
    }
}

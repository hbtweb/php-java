<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates the minimum-viable `sun.misc.Unsafe` shim
 * (`src/Aot/Runtime/sun/misc/Unsafe.php`) shipped under
 * ROADMAP §Build #7. The shim is the load-bearing dependency for
 * `java.util.concurrent.atomic.*` and `ConcurrentHashMap` (per
 * `docs/CLOJURE-BOOT-ANALYSIS.md` §"JDK-internal hot paths"); without
 * it, neither family can be implemented.
 *
 * These tests exercise the shim directly (no AOT-compiled bytecode
 * yet). They validate the offset-mapping + CAS + atomic-add semantics
 * are correct, so when AtomicInteger and ConcurrentHashMap get filled
 * (bb-allowlist work) the substrate they sit on is rank-1 verified.
 */
class UnsafeShimTest extends \PHPUnit\Framework\TestCase
{
    public function testGetUnsafeReturnsSingleton(): void
    {
        $a = \PHPJava\Aot\Runtime\sun\misc\Unsafe::getUnsafe();
        $b = \PHPJava\Aot\Runtime\sun\misc\Unsafe::getUnsafe();
        $this->assertSame($a, $b);
    }

    public function testObjectFieldOffsetReturnsDistinctValues(): void
    {
        $u = \PHPJava\Aot\Runtime\sun\misc\Unsafe::getUnsafe();
        $f1 = (object) ['name' => 'foo'];
        $f2 = (object) ['name' => 'bar'];
        $o1 = $u->objectFieldOffset($f1);
        $o2 = $u->objectFieldOffset($f2);
        $this->assertNotSame($o1, $o2);
    }

    public function testGetIntPutIntRoundTrip(): void
    {
        $u = \PHPJava\Aot\Runtime\sun\misc\Unsafe::getUnsafe();
        $field = (object) ['name' => 'value'];
        $offset = $u->objectFieldOffset($field);

        $obj = (object) ['value' => 42];
        $this->assertSame(42, $u->getInt($obj, $offset));

        $u->putInt($obj, $offset, 100);
        $this->assertSame(100, $u->getInt($obj, $offset));
        $this->assertSame(100, $obj->value);
    }

    public function testCompareAndSwapIntSucceedsOnMatch(): void
    {
        $u = \PHPJava\Aot\Runtime\sun\misc\Unsafe::getUnsafe();
        $field = (object) ['name' => 'value'];
        $offset = $u->objectFieldOffset($field);
        $obj = (object) ['value' => 10];

        $this->assertTrue($u->compareAndSwapInt($obj, $offset, 10, 20));
        $this->assertSame(20, $obj->value);
    }

    public function testCompareAndSwapIntFailsOnMismatch(): void
    {
        $u = \PHPJava\Aot\Runtime\sun\misc\Unsafe::getUnsafe();
        $field = (object) ['name' => 'value'];
        $offset = $u->objectFieldOffset($field);
        $obj = (object) ['value' => 10];

        $this->assertFalse($u->compareAndSwapInt($obj, $offset, 99, 20));
        $this->assertSame(10, $obj->value); // unchanged
    }

    public function testGetAndAddIntReturnsOldValue(): void
    {
        $u = \PHPJava\Aot\Runtime\sun\misc\Unsafe::getUnsafe();
        $field = (object) ['name' => 'counter'];
        $offset = $u->objectFieldOffset($field);
        $obj = (object) ['counter' => 5];

        $this->assertSame(5, $u->getAndAddInt($obj, $offset, 3));
        $this->assertSame(8, $obj->counter);
    }

    public function testGetAndSetObjectReturnsOldReference(): void
    {
        $u = \PHPJava\Aot\Runtime\sun\misc\Unsafe::getUnsafe();
        $field = (object) ['name' => 'ref'];
        $offset = $u->objectFieldOffset($field);
        $oldRef = (object) ['x' => 1];
        $newRef = (object) ['x' => 2];
        $obj = (object) ['ref' => $oldRef];

        $returned = $u->getAndSetObject($obj, $offset, $newRef);
        $this->assertSame($oldRef, $returned);
        $this->assertSame($newRef, $obj->ref);
    }

    public function testCompareAndSwapObjectIdentityCompare(): void
    {
        $u = \PHPJava\Aot\Runtime\sun\misc\Unsafe::getUnsafe();
        $field = (object) ['name' => 'ref'];
        $offset = $u->objectFieldOffset($field);
        $a = (object) ['x' => 1];
        $b = (object) ['x' => 2];
        $clone_a = (object) ['x' => 1]; // same shape but distinct object

        $obj = (object) ['ref' => $a];

        // CAS uses identity (===), not equality. Distinct-but-equal-shape
        // object should not match.
        $this->assertFalse($u->compareAndSwapObject($obj, $offset, $clone_a, $b));
        $this->assertSame($a, $obj->ref);

        // Same identity should match.
        $this->assertTrue($u->compareAndSwapObject($obj, $offset, $a, $b));
        $this->assertSame($b, $obj->ref);
    }

    public function testFencesAreNoOpsButCallable(): void
    {
        $u = \PHPJava\Aot\Runtime\sun\misc\Unsafe::getUnsafe();
        // No assertion — these are no-ops on single-threaded PHP. Just
        // verify they're callable without exception.
        $u->loadFence();
        $u->storeFence();
        $u->fullFence();
        $this->expectNotToPerformAssertions();
    }
}

<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\sun\misc;

/**
 * Minimum-viable `sun.misc.Unsafe` shim.
 *
 * Used by `java.util.concurrent.atomic.*` (Atomic{Integer,Long,Reference})
 * and the internals of `ConcurrentHashMap` for compare-and-swap operations.
 * Without it, neither family can be implemented; per
 * `docs/CLOJURE-BOOT-ANALYSIS.md` §"JDK-internal hot paths", `Unsafe`
 * is the load-bearing dependency for the entire `j.u.concurrent.*`
 * surface that Clojure boot transitively touches.
 *
 * PHP is effectively single-threaded (Fibers + Swoole coroutines
 * cooperatively schedule; OS threads via `parallel` extension are
 * niche). The CAS primitives here don't need real atomicity — a
 * naive read-compare-write produces the same observable behaviour.
 * For Swoole-multi-process deployments, the swap-step would route
 * through Swoole atomics; not built here yet (see ROADMAP §Build #7
 * + §Build T3 concurrency adapter).
 *
 * Field-offset semantics: Java `Unsafe` operates on
 * `(Object obj, long offset)`. The offset is a byte address into the
 * object's memory layout. PHP has no equivalent — properties are
 * name-keyed strings. We synthesise offsets: `objectFieldOffset(Field)`
 * registers a name→counter mapping and returns the counter; subsequent
 * `getInt`/`putInt`/`compareAndSwapInt` look up the name and do
 * standard property access.
 *
 * NOT IMPLEMENTED here (caller hits NotImplementedException):
 * - allocateInstance (raw uninitialised allocation — meaningless in PHP)
 * - direct off-heap memory allocation (allocateMemory, freeMemory) —
 *   wrap PHP FFI when a workload surfaces
 * - park / unpark (thread parking) — Fiber/Swoole coroutine bridge
 * - all reflective fence/cleanup ops (storeFence, fullFence, etc.) —
 *   no-ops on single-threaded PHP, but no fixture exercises them
 *
 * Per CONTRACTS.md §1 + §11: this shim treats raw PHP scalars as
 * canonical (no Int_/Long_ wrappers); `getInt` returns PHP int,
 * `getObject` returns PHP object, etc.
 */
class Unsafe
{
    /** @var self|null  Singleton instance returned by getUnsafe(). */
    private static ?self $instance = null;

    /**
     * Synthetic offset → field-name table. Java code stores the offset
     * in a static final long and re-uses it for every CAS / get / put.
     * The offset is opaque from the Java side (just a long); this
     * mapping converts it back to a PHP property name on access.
     *
     * @var array<int, string>
     */
    private static array $fieldNames = [];

    /** @var int  Next free synthetic offset. Starts at 16 to leave room
     *            for "header" offsets that some Java libs hardcode. */
    private static int $nextOffset = 16;

    public static function getUnsafe(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Register a Field's offset. `$field` is a PHPJava shim of
     * `java.lang.reflect.Field`; we read its name and assign a
     * synthetic offset.
     *
     * @param object $field  duck-typed: any object exposing a `name`
     *        property OR a `getName()` method.
     */
    public function objectFieldOffset($field): int
    {
        $name = $this->fieldName($field);
        $offset = self::$nextOffset++;
        self::$fieldNames[$offset] = $name;
        return $offset;
    }

    public function staticFieldOffset($field): int
    {
        return $this->objectFieldOffset($field);
    }

    public function staticFieldBase($field): ?object
    {
        // Java returns the Class object; consumers index against base+offset.
        // For our shim the static-vs-instance distinction is encoded in the
        // call site, so returning null is fine (callers don't dereference).
        return null;
    }

    private function fieldName($field): string
    {
        if (\is_object($field)) {
            if (\property_exists($field, 'name')) {
                return $field->name;
            }
            if (\method_exists($field, 'getName')) {
                return $field->getName();
            }
        }
        throw new \PHPJava\Exceptions\NotImplementedException(
            'Unsafe field-offset registration: pass a Field-shaped object'
        );
    }

    // ── int field access ──────────────────────────────────────────────

    public function getInt($obj, int $offset): int
    {
        return $obj->{self::$fieldNames[$offset]};
    }

    public function putInt($obj, int $offset, int $value): void
    {
        $obj->{self::$fieldNames[$offset]} = $value;
    }

    public function getIntVolatile($obj, int $offset): int
    {
        // Single-threaded — volatile is a no-op.
        return $this->getInt($obj, $offset);
    }

    public function putIntVolatile($obj, int $offset, int $value): void
    {
        $this->putInt($obj, $offset, $value);
    }

    /**
     * Compare-and-swap on int field. Java's CAS compares the current
     * value to `$expected`; if equal, sets to `$update` and returns
     * true. Single-threaded PHP needs no real atomicity.
     */
    public function compareAndSwapInt($obj, int $offset, int $expected, int $update): bool
    {
        $name = self::$fieldNames[$offset];
        if ($obj->{$name} === $expected) {
            $obj->{$name} = $update;
            return true;
        }
        return false;
    }

    public function getAndAddInt($obj, int $offset, int $delta): int
    {
        $name = self::$fieldNames[$offset];
        $old = $obj->{$name};
        $obj->{$name} = $old + $delta;
        return $old;
    }

    public function getAndSetInt($obj, int $offset, int $value): int
    {
        $name = self::$fieldNames[$offset];
        $old = $obj->{$name};
        $obj->{$name} = $value;
        return $old;
    }

    // ── long field access (PHP int = 64-bit on most hosts) ────────────

    public function getLong($obj, int $offset): int
    {
        return $obj->{self::$fieldNames[$offset]};
    }

    public function putLong($obj, int $offset, int $value): void
    {
        $obj->{self::$fieldNames[$offset]} = $value;
    }

    public function getLongVolatile($obj, int $offset): int
    {
        return $this->getLong($obj, $offset);
    }

    public function putLongVolatile($obj, int $offset, int $value): void
    {
        $this->putLong($obj, $offset, $value);
    }

    public function compareAndSwapLong($obj, int $offset, int $expected, int $update): bool
    {
        return $this->compareAndSwapInt($obj, $offset, $expected, $update);
    }

    public function getAndAddLong($obj, int $offset, int $delta): int
    {
        return $this->getAndAddInt($obj, $offset, $delta);
    }

    // ── object reference field access ─────────────────────────────────

    public function getObject($obj, int $offset)
    {
        return $obj->{self::$fieldNames[$offset]};
    }

    public function putObject($obj, int $offset, $value): void
    {
        $obj->{self::$fieldNames[$offset]} = $value;
    }

    public function getObjectVolatile($obj, int $offset)
    {
        return $this->getObject($obj, $offset);
    }

    public function putObjectVolatile($obj, int $offset, $value): void
    {
        $this->putObject($obj, $offset, $value);
    }

    public function compareAndSwapObject($obj, int $offset, $expected, $update): bool
    {
        $name = self::$fieldNames[$offset];
        if ($obj->{$name} === $expected) {
            $obj->{$name} = $update;
            return true;
        }
        return false;
    }

    public function getAndSetObject($obj, int $offset, $value)
    {
        $name = self::$fieldNames[$offset];
        $old = $obj->{$name};
        $obj->{$name} = $value;
        return $old;
    }

    // ── boolean ────────────────────────────────────────────────────────

    public function getBoolean($obj, int $offset): bool
    {
        return (bool) $obj->{self::$fieldNames[$offset]};
    }

    public function putBoolean($obj, int $offset, bool $value): void
    {
        $obj->{self::$fieldNames[$offset]} = $value;
    }

    // ── memory fences (no-op on single-threaded PHP) ──────────────────

    public function loadFence(): void {}
    public function storeFence(): void {}
    public function fullFence(): void {}
}

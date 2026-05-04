<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\jdk\internal\misc;

/**
 * Java 9+ moved most of `sun.misc.Unsafe` to `jdk.internal.misc.Unsafe`.
 * Both still exist and many libraries reference the older `sun.misc`
 * path. This is a thin alias of the `sun.misc` shim — same singleton,
 * same offset table, same semantics. Lets `getUnsafe()` work from
 * either path.
 *
 * The Java-21 distinction (`sun.misc.Unsafe` deprecated for removal,
 * `jdk.internal.misc.Unsafe` the canonical path) doesn't matter for
 * a shim: both call sites land on the same single-threaded
 * read-modify-write logic.
 */
class Unsafe extends \PHPJava\Aot\Runtime\sun\misc\Unsafe
{
    public static function getUnsafe(): self
    {
        // Singleton via the parent class's storage; cast for type-shape.
        $base = parent::getUnsafe();
        if ($base instanceof self) return $base;
        // First call from this class: allocate a self-instance and
        // re-seed the parent's singleton slot via reflection.
        $instance = new self();
        $r = new \ReflectionClass(\PHPJava\Aot\Runtime\sun\misc\Unsafe::class);
        $p = $r->getProperty('instance');
        $p->setAccessible(true);
        $p->setValue(null, $instance);
        return $instance;
    }
}

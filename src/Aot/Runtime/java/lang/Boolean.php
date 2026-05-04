<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.Boolean — bb-allowlist fill (replacing Path C stub).
 *
 * Static surface only. Instance methods (booleanValue, instance
 * equals/toString/hashCode/compareTo, describeConstable) stay as
 * NotImplementedException stubs because the IR Builder is expected
 * to lower wrapped-Boolean operations inline per BOXING.md
 * (b.booleanValue() → identity, b.equals(o) → ===, etc.). If a
 * fixture ever surfaces an unlowered instance call, fix the lowerer
 * — don't add the body here.
 *
 * Validated against OpenJDK 25 by bench/parity/cases/java.lang.Boolean.json.
 */
final class Boolean
{
    // Java's Boolean.TRUE / FALSE are pre-allocated wrapper instances.
    // Per CONTRACTS.md §1, PHP bool is the runtime representation;
    // accesses to Boolean.TRUE / FALSE land as IR-lowered BoolLit at
    // compile time. These fields are populated to PHP bool so any
    // unlowered fall-through gets the right primitive.
    public static $TRUE  = true;
    public static $FALSE = false;
    public static $TYPE  = null;  // java.lang.Class<Boolean> — meta only

    /**
     * Java: case-insensitive match for "true"; everything else (including
     * null, "yes", "1", numeric strings) returns false. PHP needs the
     * explicit case-fold and null-guard — strtolower(null) is illegal in
     * PHP 8.4.
     */
    public static function parseBoolean($s): bool
    {
        if ($s === null) return false;
        return \strtolower((string) $s) === 'true';
    }

    /**
     * Java has Boolean.valueOf(boolean) → Boolean and Boolean.valueOf(String) → Boolean.
     * Both return wrappers; we return the unwrapped PHP bool per the
     * BOXING contract — the IR lowerer is expected to elide the wrap.
     */
    public static function valueOf($v): bool
    {
        if (\is_bool($v)) return $v;
        if (\is_string($v)) return self::parseBoolean($v);
        return (bool) $v;
    }

    public static function toString(bool $b): string
    {
        return $b ? 'true' : 'false';
    }

    /**
     * Java's Boolean.hashCode constants: true → 1231, false → 1237.
     * Both are prime; the choice predates 1.5 and is now spec-locked.
     */
    public static function hashCode(bool $b): int
    {
        return $b ? 1231 : 1237;
    }

    /**
     * Java: a == b ? 0 : (a ? 1 : -1). True sorts AFTER false.
     */
    public static function compare(bool $a, bool $b): int
    {
        if ($a === $b) return 0;
        return $a ? 1 : -1;
    }

    public static function logicalAnd(bool $a, bool $b): bool { return $a && $b; }
    public static function logicalOr(bool $a, bool $b): bool  { return $a || $b; }
    public static function logicalXor(bool $a, bool $b): bool { return $a !== $b; }

    /**
     * Java: System.getProperty(name).equalsIgnoreCase("true"). PHP has
     * no equivalent of JVM system properties; the closest analog is
     * getenv. Behaviour aligns when env var matches "true"
     * (case-insensitive). Comparison against JDK from the parity
     * oracle requires the JVM to be invoked with -D<name>=<val>;
     * deferred from the oracle case battery.
     */
    public static function getBoolean(string $name): bool
    {
        $v = \getenv($name);
        return \is_string($v) && \strtolower($v) === 'true';
    }

    // ── instance ports kept as NIE stubs ──
    // The IR Builder lowers wrapped-Boolean operations inline per
    // BOXING.md. If any of these get reached at runtime, the lowerer
    // missed a call site — fix the lowerer, not these.

    public function __construct($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function booleanValue()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function equals($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function compareTo($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function describeConstable()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }
}

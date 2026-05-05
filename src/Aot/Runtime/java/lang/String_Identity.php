<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.String identity-preserving wrapper.
 *
 * Per CONTRACTS.md §1, Java String values carry as raw PHP strings
 * for the value-equal common case. But Java's spec says
 * `new String(s)` allocates a distinct object — observable via
 * `==`, `System.identityHashCode`, `IdentityHashMap`, synchronized
 * blocks, etc. The bare PHP string can't carry per-instance
 * identity (PHP scalars have no heap identity).
 *
 * This wrapper bridges: explicit `new String(s)` sites in bytecode
 * AOT-compile to `new String_Identity(s)` instead of identity-folding
 * to the value. PHP's `===` on objects gives reference equality;
 * `spl_object_id` gives a unique identity per instance — both
 * preserve Java's identity contract.
 *
 * Methods unwrap to the underlying PHP string for value-based
 * operations (.equals, .charAt, .length, etc.) — matching Java's
 * semantics where instance identity doesn't override value identity
 * for those calls. The `intern()` method canonicalises to the
 * underlying PHP string (which is the project's "interned pool"
 * since equal-value strings collapse into one PHP scalar).
 *
 * **Future**: WrapperEscapeAnalysis pass elides allocations whose
 * wrapper isn't observed in the method's IR (≡ HotSpot's stack-
 * allocation / scalar-replacement). Per CONTRACTS.md §"semantic
 * elision", wrappers are emitted as the Java semantics demands;
 * the compiler proves locally when erasure is safe.
 */
final class String_Identity implements \Stringable
{
    public readonly string $value;

    public function __construct($value = '')
    {
        // Permissive constructor — `new String(String)` may pass a
        // String_Identity wrapper or a plain PHP string. Cast covers
        // both via __toString.
        $this->value = $value === null ? '' : (string) $value;
    }

    public function __toString(): string { return $this->value; }

    /** Java: returns char count. AOT-contract: UTF-16 code unit count. */
    public function length(): int { return String_::length($this->value); }

    public function isEmpty(): int { return $this->value === '' ? 1 : 0; }

    public function charAt(int $i): int { return String_::charAt($this->value, $i); }

    /**
     * Value-based equality. Compares against another String_Identity,
     * a plain PHP string, or null. Mirrors Java's String.equals which
     * is value-based regardless of wrapping.
     */
    public function equals($other): int
    {
        if ($other === null) return 0;
        if ($other instanceof self) return $this->value === $other->value ? 1 : 0;
        if (\is_string($other))     return $this->value === $other ? 1 : 0;
        return 0;
    }

    public function hashCode(): int { return String_::hashCode($this->value); }

    /**
     * Java: if a string equal-by-value is in the pool, return that
     * canonical reference; else add this and return. Under our model,
     * the "pool" is the PHP-string value space — equal-value strings
     * collapse. intern() returns the underlying PHP string, which
     * subsequent identityHashCode calls on the same value will
     * produce the same hash for. AOT-emitted code calling intern on
     * a wrapper "canonicalises out of the wrapper."
     */
    public function intern(): string { return $this->value; }

    public function compareTo($other): int
    {
        $o = $other instanceof self ? $other->value : (string) $other;
        return \strcmp($this->value, $o);
    }

    public function indexOf($needle, int $fromIndex = 0): int
    {
        return String_::indexOf($this->value, $needle, $fromIndex);
    }

    public function lastIndexOf($needle): int { return String_::lastIndexOf($this->value, $needle); }

    public function substring(int $beginIndex, ?int $endIndex = null): string
    {
        return String_::substring($this->value, $beginIndex, $endIndex);
    }

    public function trim(): string  { return \trim($this->value); }
    public function strip(): string { return \trim($this->value); }

    public function startsWith(string $prefix): int { return String_::startsWith($this->value, $prefix); }
    public function endsWith(string $suffix): int   { return String_::endsWith($this->value, $suffix); }
    public function contains(string $sub): int      { return String_::contains($this->value, $sub); }

    public function replace($target, $repl): string { return String_::replace($this->value, $target, $repl); }
    public function toLowerCase(): string { return \strtolower($this->value); }
    public function toUpperCase(): string { return \strtoupper($this->value); }
    public function concat(string $t): string { return $this->value . $t; }
    public function toString(): string { return $this->value; }
}

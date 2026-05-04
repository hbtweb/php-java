<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util;

use PHPJava\Aot\Runtime\java\lang\NullPointerException;

/**
 * java.util.Objects — bb-allowlist fill.
 *
 * Surface: isNull, nonNull, equals, hashCode (for null + String + int +
 * bool — Double / object hashCode deferred), toString, requireNonNull.
 *
 * Dispatch: hashCode(Object) routes by PHP type — Java's Object dispatch
 * happens at the call site (`s.hashCode()` calls String.hashCode for a
 * String receiver). The PHP shim has no virtual dispatch on a primitive,
 * so we type-switch on the input.
 *
 * Deferred (no bb-allowlist hits yet, throws on call): deepEquals, hash
 * (variadic — driver doesn't yet pass Object[] for `Object...` overloads),
 * compare(T, T, Comparator), requireNonNullElse / ElseGet,
 * checkIndex / checkFromToIndex / checkFromIndexSize.
 *
 * Validated against OpenJDK 25 by bench/parity/cases/java.util.Objects.json.
 */
final class Objects
{
    public static function isNull($a): bool
    {
        return $a === null;
    }

    public static function nonNull($a): bool
    {
        return $a !== null;
    }

    /**
     * Java: (a == b) || (a != null && a.equals(b)). For PHP primitives
     * (string, int, bool, float), `===` is the operational equivalent of
     * .equals() — same value-equality semantics. Object .equals() is
     * deferred until a fixture surfaces an unlowered Object dispatch
     * through this method.
     */
    public static function equals($a, $b): bool
    {
        if ($a === $b) return true;
        if ($a === null || $b === null) return false;
        // Both non-null, not ===. For primitives, that's the answer.
        // Differing types or differing values → false.
        return false;
    }

    /**
     * Java's Objects.hashCode(o) returns 0 for null, else o.hashCode().
     * Dispatch on PHP type since AOT-runtime carries primitives raw:
     *   null   → 0
     *   string → String.hashCode (polynomial sum, masked to int32)
     *   int    → Integer.hashCode(i) = i (truncated to int32 by caller)
     *   bool   → 1231 / 1237
     *   float  → DEFERRED (Double.hashCode requires doubleToLongBits)
     *   object → DEFERRED (no universal Object.hashCode in PHP)
     */
    public static function hashCode($a): int
    {
        if ($a === null) return 0;
        if (\is_string($a)) {
            return \PHPJava\Aot\Runtime\java\lang\String_::hashCode($a);
        }
        if (\is_int($a)) {
            // PHP int is 64-bit signed — same as Java long. Auto-boxing
            // by the JVM resolves to Long → Long.hashCode():
            //   (int)(v ^ (v >>> 32))
            // For positive values that fit in 32 bits, the upper word
            // is 0 and the result is the value itself (== Integer.hashCode).
            // For negatives and large positives, the XOR-fold matters:
            // Long.hashCode(-1L) = 0, not -1.
            $shifted = ($a >> 32) & 0xFFFFFFFF;     // unsigned shift right 32
            $xored   = ($a ^ $shifted) & 0xFFFFFFFF;  // low 32 bits
            // Narrow to signed int32: high-bit set → negative.
            return $xored >= 0x80000000 ? $xored - 0x100000000 : $xored;
        }
        if (\is_bool($a)) {
            return \PHPJava\Aot\Runtime\java\lang\Boolean::hashCode($a);
        }
        // Double/Float and Object dispatch land here. Surfaces when a
        // fixture exercises them — implement at that point.
        throw new \PHPJava\Exceptions\NotImplementedException(
            'Objects.hashCode for ' . \gettype($a) . ' — extend the shim'
        );
    }

    /**
     * Java: Arrays.hashCode(Object[]). Polynomial 31-rolling hash over
     * the elements' .hashCode() values. Empty array → 1; null array →
     * 0 (Java null check). Each element: null → 0, else → its hashCode
     * via the type-dispatched Objects.hashCode above.
     *
     * Bytecode-side: `Objects.hash(a, b, c)` is sugar for
     * `Objects.hash(new Object[]{a, b, c})`. AOT-emitted code passes a
     * single PHP array — same shape javac compiles to. The varargs
     * desugaring happens at compile time, not runtime.
     */
    public static function hash($values): int
    {
        if ($values === null) return 0;
        $result = 1;
        foreach ($values as $v) {
            $eltHash = ($v === null) ? 0 : self::hashCode($v);
            $result = (int) ((31 * $result + $eltHash) & 0xFFFFFFFF);
            // Narrow back to signed int32.
            if ($result >= 0x80000000) $result -= 0x100000000;
        }
        return $result;
    }

    public static function toString($a, ?string $defaultValue = null): string
    {
        if ($a === null) return $defaultValue ?? 'null';
        if (\is_bool($a)) return $a ? 'true' : 'false';
        return (string) $a;
    }

    /**
     * Java: throws NullPointerException(message) if a is null; else
     * returns a. Two overloads: 1-arg (no message) and 2-arg (with
     * message). PHP's optional second param folds them together.
     */
    public static function requireNonNull($a, ?string $message = null)
    {
        if ($a === null) {
            throw new NullPointerException($message ?? '');
        }
        return $a;
    }
}

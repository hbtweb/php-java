<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util;

use PHPJava\Aot\Runtime\java\lang\IndexOutOfBoundsException;
use PHPJava\Aot\Runtime\java\lang\IllegalArgumentException;

/**
 * java.util.Arrays — bb-allowlist fill (8/80).
 *
 * Compositional: hashCode delegates to Objects::hash which dispatches
 * element-wise to Objects::hashCode → String_::hashCode etc. — this is
 * the first shim that creates a depth-≥3 chain in the profile, the
 * pattern PHP JIT can't trace through (per the M1 / 3.59× finding).
 *
 * Per AOT contract Java arrays carry as PHP arrays. PHP can't overload
 * by element type, so each method handles arrays-of-anything; element
 * type is inherited from the caller's bytecode at the boundary.
 *
 * Surface:
 *   - copyOf, copyOfRange (slice + zero-pad)
 *   - equals (element-wise via PHP ===)
 *   - hashCode (Objects::hash polynomial fold)
 *   - fill (fill / fill-range)
 *   - toString (Java-style "[a, b, c]")
 *   - binarySearch (sorted-array dispatch)
 *
 * Deferred: sort (mutation, hard to parity-test); asList (needs List
 * interface); stream (Stream is its own beast); deepEquals /
 * deepHashCode / deepToString (recursive — extend when nested-array
 * fixtures surface); parallelSort.
 *
 * Validated against OpenJDK 25 by bench/parity/cases/java.util.Arrays.json.
 */
final class Arrays
{
    /**
     * Element-wise equality. Per Java spec, two arrays are equal if
     * same length and corresponding elements are equal. PHP's `===`
     * on arrays does ordered element-by-element comparison — matches
     * Java for primitive arrays. NaN edge: Java's arrays-equals
     * treats NaN == NaN as TRUE (intentional symmetry-of-equals);
     * PHP `===` follows IEEE NaN != NaN. Special-cased below.
     */
    public static function equals(?array $a, ?array $b): bool
    {
        if ($a === null) return $b === null;
        if ($b === null) return false;
        if (\count($a) !== \count($b)) return false;
        foreach ($a as $k => $v) {
            $w = $b[$k] ?? null;
            if ($v === $w) continue;
            // NaN-NaN special case for floats — Java treats as equal.
            if (\is_float($v) && \is_float($w) && \is_nan($v) && \is_nan($w)) continue;
            return false;
        }
        return true;
    }

    /**
     * Polynomial hash matching Java's Arrays.hashCode for primitive
     * arrays. Reuses Objects::hash, which iterates the same fold. For
     * Object[] elements, Objects::hashCode dispatches by type;
     * primitive elements (int, long, etc.) all carry as PHP int and
     * collapse to Long.hashCode's bit-fold per the AOT contract
     * (PHP int IS Java long).
     */
    public static function hashCode(?array $a): int
    {
        return Objects::hash($a);
    }

    /**
     * copyOf(arr, newLength). New length less than existing length =
     * truncate; greater = pad with default value (0 for primitives,
     * null for object arrays). PHP can't distinguish element types
     * here — pad with 0 since the most-common case is primitive
     * resize (HashMap rehash, ArrayList grow, etc.).
     */
    public static function copyOf(array $a, int $newLength): array
    {
        if ($newLength < 0) {
            throw new \PHPJava\Aot\Runtime\java\lang\NegativeArraySizeException("$newLength");
        }
        $len = \count($a);
        if ($newLength <= $len) return \array_slice($a, 0, $newLength);
        $out = $a;
        for ($i = $len; $i < $newLength; $i++) $out[] = null;
        return $out;
    }

    /**
     * copyOfRange(arr, from, to) — copy [from, to). Caller's `to` may
     * exceed array length; pad with 0 for the overflow.
     */
    public static function copyOfRange(array $a, int $from, int $to): array
    {
        $len = \count($a);
        if ($from < 0 || $from > $len) {
            throw new \PHPJava\Aot\Runtime\java\lang\ArrayIndexOutOfBoundsException("$from");
        }
        if ($from > $to) {
            throw new IllegalArgumentException("$from > $to");
        }
        $copyLen = $to - $from;
        $available = \min($copyLen, $len - $from);
        $out = $available > 0 ? \array_slice($a, $from, $available) : [];
        for ($i = $available; $i < $copyLen; $i++) $out[] = null;
        return $out;
    }

    /**
     * fill(arr, val) — set every element to val. Java mutates in
     * place; PHP arrays are value-typed so we return the modified
     * copy and rely on caller's by-ref signature (per IR Builder's
     * by-ref auto-detect).
     */
    public static function fill(array &$a, $val): void
    {
        $len = \count($a);
        for ($i = 0; $i < $len; $i++) $a[$i] = $val;
    }

    public static function fillRange(array &$a, int $from, int $to, $val): void
    {
        $len = \count($a);
        if ($from < 0 || $to > $len || $from > $to) {
            throw new IndexOutOfBoundsException("fillRange [$from, $to) of length $len");
        }
        for ($i = $from; $i < $to; $i++) $a[$i] = $val;
    }

    /**
     * Java-style "[a, b, c]" rendering. Bool → "true"/"false";
     * floats with NaN/Infinity in Java's emitted spelling.
     */
    public static function toString(?array $a): string
    {
        if ($a === null) return 'null';
        if (\count($a) === 0) return '[]';
        $parts = [];
        foreach ($a as $v) $parts[] = self::elementToString($v);
        return '[' . \implode(', ', $parts) . ']';
    }

    private static function elementToString($v): string
    {
        if ($v === null) return 'null';
        if ($v === true) return 'true';
        if ($v === false) return 'false';
        if (\is_float($v)) {
            if (\is_nan($v))      return 'NaN';
            if ($v === \INF)      return 'Infinity';
            if ($v === -\INF)     return '-Infinity';
            // Java prints integer floats with trailing ".0" (1.0 not 1).
            if ((float) (int) $v === $v && \abs($v) < 1e16) {
                return ((string) (int) $v) . '.0';
            }
            return (string) $v;
        }
        return (string) $v;
    }

    /**
     * In-place ascending sort. Java's Arrays.sort takes a primitive
     * array and mutates it; PHP sort() does the same on PHP arrays
     * with re-indexing. For numeric arrays the order is identical.
     * For mixed-type / object arrays, Java's sort uses natural-order
     * comparator; PHP defaults to comparing by value coercion. Stick
     * to homogeneous-element-type usage to match.
     *
     * No parity battery — through-reflection by-reference dispatch is
     * fiddly to drive cleanly. The implementation is delegation to a
     * well-tested PHP builtin; correctness via direct PHPUnit if a
     * fixture surfaces a divergence.
     */
    public static function sort(array &$a): void
    {
        \sort($a);
    }

    public static function sortRange(array &$a, int $fromIndex, int $toIndex): void
    {
        $len = \count($a);
        if ($fromIndex < 0 || $toIndex > $len || $fromIndex > $toIndex) {
            throw new IndexOutOfBoundsException("sortRange [$fromIndex, $toIndex) of length $len");
        }
        $slice = \array_slice($a, $fromIndex, $toIndex - $fromIndex);
        \sort($slice);
        for ($i = 0; $i < \count($slice); $i++) $a[$fromIndex + $i] = $slice[$i];
    }

    /**
     * binarySearch(arr, key) — returns idx ≥ 0 if found, else
     * -(insertionPoint + 1). Array assumed sorted ascending.
     */
    public static function binarySearch(array $a, $key): int
    {
        $lo = 0;
        $hi = \count($a) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $cmp = $a[$mid] <=> $key;
            if ($cmp < 0) $lo = $mid + 1;
            elseif ($cmp > 0) $hi = $mid - 1;
            else return $mid;
        }
        return -($lo + 1);
    }
}

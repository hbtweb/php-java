<?php

declare(strict_types=1);

namespace PHPJava\Aot\Runtime;

use PHPJava\Aot\Runtime\java\lang\StringIndexOutOfBoundsException;

/**
 * UTF-16 index semantics over PHP byte strings.
 *
 * The AOT contract is "a PHP string IS a Java String" (CONTRACTS.md §1),
 * which is right for storage and interop and wrong for indexing: PHP
 * indexes bytes, Java indexes UTF-16 code units. Every index-taking
 * String operation has to bridge that, and before this class each one
 * bridged it separately — `String_::length` had one byte-walk,
 * `String_::charAt` had another, `Integer::parseInt`'s 4-arg form had
 * its own index arithmetic, and `indexOf` / `substring` / `hashCode`
 * did not bridge it at all.
 *
 * ## Why memoisation rather than a compiler pass
 *
 * Measured 2026-07-24 via `bench/string-index-probe.php`, PHP 8.5.8
 * with tracing JIT, 5-run medians, per charAt call at n=4096:
 *
 *   V0 walk-per-call (what shipped)        39324 ns ASCII / 43738 ns non-ASCII
 *   V2 ASCII fast path, hoisted check         24 ns ASCII / 39683 ns non-ASCII
 *   V3 decode hoisted by the compiler          25 ns ASCII /    38 ns non-ASCII
 *   V4 decode memoised in the shim             39 ns ASCII /    28 ns non-ASCII
 *
 * V0 is quadratic — it rescans from byte 0 on every access, so the
 * canonical `for (i=0; i<s.length(); i++) s.charAt(i)` is O(n^2), twice
 * over, since `length()` rescanned too.
 *
 * V4 is O(1) per access because PHP caches a zend_string's hash, so
 * `$memo[$s]` does not re-hash the string on each lookup. That matters
 * for the design: the memo reaches the same complexity class as
 * hoisting the decode in the IR (V3) while living entirely in the shim.
 * The compiler pass was planned and is not being built — it would buy
 * nothing over this.
 *
 * The ASCII fast path survives as a refinement, not as the mechanism:
 * direct byte indexing is ~1.6x the memo on ASCII (24 vs 39 ns) and
 * avoids materialising a units array for the common case, but on its
 * own it does nothing for non-ASCII (1.10x, measured).
 *
 * ## Encoding forms handled
 *
 *   1-byte UTF-8 (ASCII)        -> 1 code unit
 *   2-byte UTF-8 (U+0080..07FF) -> 1 code unit
 *   3-byte UTF-8 (U+0800..FFFF) -> 1 code unit; also the form a Java
 *                                  class file's Modified UTF-8 (CESU-8)
 *                                  uses for each half of a surrogate
 *                                  pair, and each half IS one unit
 *   4-byte UTF-8 (U+10000+)     -> 2 code units (surrogate pair)
 */
final class Utf16
{
    /**
     * Entries per memo before eviction. Matches the order of magnitude
     * of the Compiler's own LRU caches. Long-running daemons must not
     * grow a memo without bound.
     */
    private const MEMO_MAX = 1024;

    /** @var array<string,bool> */
    private static array $asciiMemo = [];

    /** @var array<string,array{0:list<int>,1:list<int>}> units + byte offsets */
    private static array $decodeMemo = [];

    /**
     * Insertion-ordered eviction: drop the older half when full. Not a
     * true LRU — a hit doesn't refresh position — but the access pattern
     * here is "same few strings, many times", which insertion order
     * already serves, and refreshing on every hit would cost more than
     * it saves.
     *
     * @param array<string,mixed> $memo
     */
    private static function remember(array &$memo, string $key, $value): void
    {
        if (\count($memo) >= self::MEMO_MAX) {
            $memo = \array_slice($memo, \intdiv(self::MEMO_MAX, 2), null, true);
        }
        $memo[$key] = $value;
    }

    /** Drop all memo state. For tests and long-running-process resets. */
    public static function clearMemo(): void
    {
        self::$asciiMemo  = [];
        self::$decodeMemo = [];
    }

    /** @return array{ascii:int,decode:int} */
    public static function memoStats(): array
    {
        return ['ascii' => \count(self::$asciiMemo), 'decode' => \count(self::$decodeMemo)];
    }

    /**
     * True when byte index == UTF-16 index for this string, which makes
     * every operation below a direct byte access. PCRE scans at C speed,
     * far cheaper than a PHP-level loop, and the verdict is memoised so
     * a loop pays for it once.
     */
    public static function isAscii(string $s): bool
    {
        if (isset(self::$asciiMemo[$s])) {
            return self::$asciiMemo[$s];
        }
        $r = !\preg_match('/[\x80-\xFF]/', $s);
        self::remember(self::$asciiMemo, $s, $r);
        return $r;
    }

    /**
     * Decode to [code units, byte offsets]. `offsets` has one more entry
     * than `units`; offsets[k] is the byte at which unit k starts and
     * offsets[count] is strlen($s), so a substring is a plain substr.
     *
     * Both halves of a surrogate pair report the byte offset of the
     * whole 4-byte sequence: a substring that splits a pair would need
     * to emit a lone surrogate, which is not representable in UTF-8.
     * Splitting a pair therefore yields the full character rather than
     * half of one. Java allows the split; we do not reproduce it.
     *
     * @return array{0:list<int>,1:list<int>}
     */
    private static function decode(string $s): array
    {
        if (isset(self::$decodeMemo[$s])) {
            return self::$decodeMemo[$s];
        }

        $units   = [];
        $offsets = [];
        $n       = \strlen($s);
        for ($i = 0; $i < $n;) {
            $b = \ord($s[$i]);
            if ($b < 0x80) {
                $offsets[] = $i;
                $units[]   = $b;
                $i += 1;
            } elseif ($b < 0xE0) {
                $offsets[] = $i;
                $units[]   = (($b & 0x1F) << 6) | (\ord($s[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($b < 0xF0) {
                $offsets[] = $i;
                $units[]   = (($b & 0x0F) << 12)
                           | ((\ord($s[$i + 1]) & 0x3F) << 6)
                           | (\ord($s[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $cp = (($b & 0x07) << 18)
                    | ((\ord($s[$i + 1]) & 0x3F) << 12)
                    | ((\ord($s[$i + 2]) & 0x3F) << 6)
                    | (\ord($s[$i + 3]) & 0x3F);
                $cp -= 0x10000;
                $offsets[] = $i;
                $units[]   = 0xD800 | ($cp >> 10);
                $offsets[] = $i;
                $units[]   = 0xDC00 | ($cp & 0x3FF);
                $i += 4;
            }
        }
        $offsets[] = $n;

        $pair = [$units, $offsets];
        self::remember(self::$decodeMemo, $s, $pair);
        return $pair;
    }

    /**
     * Encode a Unicode code point to its UTF-8 bytes. Used to turn an
     * int needle (which Java's indexOf(int) treats as a code point, not
     * a UTF-16 unit) into something matchable against a PHP byte string.
     */
    public static function codePointToUtf8(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12))
                 . \chr(0x80 | (($cp >> 6) & 0x3F))
                 . \chr(0x80 | ($cp & 0x3F));
        }
        return \chr(0xF0 | ($cp >> 18))
             . \chr(0x80 | (($cp >> 12) & 0x3F))
             . \chr(0x80 | (($cp >> 6) & 0x3F))
             . \chr(0x80 | ($cp & 0x3F));
    }

    /**
     * Build a string from an array of UTF-16 code units, pairing
     * adjacent high+low surrogates into their supplementary code point.
     * The inverse of units(); backs String.valueOf(char[]) and
     * StringBuilder. A lone surrogate is emitted as its (CESU-8) form.
     *
     * @param list<int> $units
     */
    public static function unitsToString(array $units): string
    {
        $out = '';
        $n   = \count($units);
        for ($i = 0; $i < $n; $i++) {
            $u = $units[$i] & 0xFFFF;
            if ($u >= 0xD800 && $u <= 0xDBFF && $i + 1 < $n) {
                $lo = $units[$i + 1] & 0xFFFF;
                if ($lo >= 0xDC00 && $lo <= 0xDFFF) {
                    $out .= self::codePointToUtf8(0x10000 + (($u - 0xD800) << 10) + ($lo - 0xDC00));
                    $i++;
                    continue;
                }
            }
            $out .= self::codePointToUtf8($u);
        }
        return $out;
    }

    /** @return list<int> UTF-16 code units. */
    public static function units(string $s): array
    {
        if ($s === '') {
            return [];
        }
        if (self::isAscii($s)) {
            return \array_values(\unpack('C*', $s));
        }
        return self::decode($s)[0];
    }

    /** Java String.length() — UTF-16 code unit count. */
    public static function length(string $s): int
    {
        if ($s === '') {
            return 0;
        }
        if (self::isAscii($s)) {
            return \strlen($s);
        }
        return \count(self::decode($s)[0]);
    }

    /** Java String.charAt(i). */
    public static function charAt(string $s, int $i): int
    {
        if (self::isAscii($s)) {
            if ($i < 0 || $i >= \strlen($s)) {
                throw new StringIndexOutOfBoundsException("String index out of range: {$i}");
            }
            return \ord($s[$i]);
        }
        $units = self::decode($s)[0];
        if ($i < 0 || $i >= \count($units)) {
            throw new StringIndexOutOfBoundsException("String index out of range: {$i}");
        }
        return $units[$i];
    }

    /** Byte offset at which UTF-16 unit $i starts; strlen($s) when $i == length. */
    public static function byteOffset(string $s, int $i): int
    {
        if (self::isAscii($s)) {
            return $i;
        }
        $offsets = self::decode($s)[1];
        return $offsets[$i] ?? \strlen($s);
    }

    /** Java String.substring, indexed in UTF-16 units. */
    public static function substring(string $s, int $begin, ?int $end = null): string
    {
        $len = self::length($s);
        $end = $end ?? $len;
        if ($begin < 0 || $end > $len || $begin > $end) {
            throw new StringIndexOutOfBoundsException(
                "begin {$begin}, end {$end}, length {$len}"
            );
        }
        if (self::isAscii($s)) {
            return \substr($s, $begin, $end - $begin);
        }
        $from = self::byteOffset($s, $begin);
        $to   = self::byteOffset($s, $end);
        return \substr($s, $from, $to - $from);
    }

    /** Convert a byte offset back to a UTF-16 unit index. */
    public static function unitIndexOfByte(string $s, int $byte): int
    {
        if (self::isAscii($s)) {
            return $byte;
        }
        $offsets = self::decode($s)[1];
        // offsets is ascending; a linear scan is fine at the sizes that
        // reach here, and binary search would need care around the
        // duplicated offsets of a surrogate pair.
        foreach ($offsets as $unit => $off) {
            if ($off >= $byte) {
                return $unit;
            }
        }
        return \count($offsets) - 1;
    }

    /**
     * Java String.hashCode — s[0]*31^(n-1) + ... over UTF-16 code
     * units, not over bytes. Result is a signed 32-bit int.
     */
    public static function hashCode(string $s): int
    {
        $h = 0;
        if (self::isAscii($s)) {
            for ($i = 0, $n = \strlen($s); $i < $n; $i++) {
                $h = (($h * 31) + \ord($s[$i])) & 0xFFFFFFFF;
            }
        } else {
            foreach (self::decode($s)[0] as $u) {
                $h = (($h * 31) + $u) & 0xFFFFFFFF;
            }
        }
        return ($h & 0x80000000) ? $h - 0x100000000 : $h;
    }

    /**
     * Objects.checkFromToIndex over UTF-16 units — the bound Java's
     * CharSequence-taking parse methods apply.
     */
    public static function checkFromToIndex(string $s, int $from, int $to): void
    {
        $len = self::length($s);
        if ($from < 0 || $from > $to || $to > $len) {
            throw new java\lang\IndexOutOfBoundsException(
                "Range [{$from}, {$to}) out of bounds for length {$len}"
            );
        }
    }
}

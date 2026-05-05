<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.Long — bb-allowlist fill (5/80).
 *
 * PHP int IS a Java long natively (both 64-bit signed two's
 * complement), so most arithmetic ops are identity-at-the-contract.
 * Bit ops need 64-bit cascades; the SWAR-with-multiply popcount
 * trick overflows PHP int (since 0x0101010101010101 × 0xFF reaches
 * 2^64), so bitCount uses a byte-sum fold instead.
 *
 * Validated against OpenJDK 25 by bench/parity/cases/java.lang.Long.json.
 */
final class Long
{
    public const MIN_VALUE = \PHP_INT_MIN;
    public const MAX_VALUE = \PHP_INT_MAX;
    public const SIZE      = 64;
    public const BYTES     = 8;

    public static function parseLong(string $s, int $radix = 10): int
    {
        $s = \trim($s);
        if ($s === '' || !\preg_match('/^-?[0-9a-zA-Z]+$/', $s)) {
            throw new NumberFormatException("For input string: \"{$s}\"");
        }
        $n = \intval($s, $radix);
        // Round-trip to catch invalid digits for the radix. For radix
        // 10, PHP's strval is reliable. For other radixes, base_convert
        // back — but `-$n` overflows for PHP_INT_MIN, so special-case.
        if ($radix === 10) {
            $rt = (string) $n;
        } elseif ($n === \PHP_INT_MIN) {
            // -PHP_INT_MIN overflows PHP int; trust intval here.
            return $n;
        } else {
            $rt = $n < 0
                ? '-' . \base_convert((string) -$n, 10, $radix)
                : \base_convert((string) $n, 10, $radix);
        }
        if (\strtolower($rt) !== \strtolower($s)) {
            throw new NumberFormatException("For input string: \"{$s}\"");
        }
        return $n;
    }

    public static function valueOf($v): int
    {
        if (\is_string($v)) return self::parseLong($v);
        return (int) $v;
    }

    public static function toString(int $v, int $radix = 10): string
    {
        if ($radix === 10) return (string) $v;
        return $v < 0
            ? '-' . \base_convert((string) -$v, 10, $radix)
            : \base_convert((string) $v, 10, $radix);
    }

    /** Java emits the unsigned bit pattern (no negative sign). PHP's
     * decbin on a negative PHP int already produces the 64-bit
     * unsigned-style binary string, matching Java's behaviour. */
    public static function toBinaryString(int $v): string { return \decbin($v); }

    /** Same as toBinaryString — PHP's dechex on negative PHP ints
     * produces the unsigned 16-hex-digit form Java's spec requires. */
    public static function toHexString(int $v): string { return \dechex($v); }

    public static function toOctalString(int $v): string { return \decoct($v); }

    public static function max(int $a, int $b): int { return $a >= $b ? $a : $b; }
    public static function min(int $a, int $b): int { return $a <= $b ? $a : $b; }
    public static function sum(int $a, int $b): int { return $a + $b; }
    public static function compare(int $a, int $b): int { return $a <=> $b; }
    public static function signum(int $v): int { return $v <=> 0; }

    /** Java: (int)(v ^ (v >>> 32)). Unsigned shift via mask. */
    public static function hashCode(int $v): int
    {
        $shifted = ($v >> 32) & 0xFFFFFFFF;
        $xored   = ($v ^ $shifted) & 0xFFFFFFFF;
        return $xored >= 0x80000000 ? $xored - 0x100000000 : $xored;
    }

    /**
     * 64-bit popcount. Standard SWAR nibble-fold then byte-sum (the
     * multiply-by-0x0101010101010101 variant overflows PHP int).
     */
    public static function bitCount(int $v): int
    {
        $v = $v - (($v >> 1) & 0x5555555555555555);
        $v = ($v & 0x3333333333333333) + (($v >> 2) & 0x3333333333333333);
        $v = ($v + ($v >> 4)) & 0x0F0F0F0F0F0F0F0F;
        return ($v & 0xFF)
             + (($v >>  8) & 0xFF)
             + (($v >> 16) & 0xFF)
             + (($v >> 24) & 0xFF)
             + (($v >> 32) & 0xFF)
             + (($v >> 40) & 0xFF)
             + (($v >> 48) & 0xFF)
             + (($v >> 56) & 0xFF);
    }

    /**
     * Count zero bits ahead of the highest 1-bit. 64 if v is 0;
     * 0 if the sign bit is set. Cascade-narrowed in halves.
     */
    public static function numberOfLeadingZeros(int $v): int
    {
        if ($v === 0) return 64;
        if ($v < 0)   return 0;
        $n = 0;
        if ($v <= 0x00000000FFFFFFFF) { $n += 32; $v <<= 32; }
        if ($v <= 0x0000FFFFFFFFFFFF) { $n += 16; $v <<= 16; }
        if ($v <= 0x00FFFFFFFFFFFFFF) { $n +=  8; $v <<=  8; }
        if ($v <= 0x0FFFFFFFFFFFFFFF) { $n +=  4; $v <<=  4; }
        if ($v <= 0x3FFFFFFFFFFFFFFF) { $n +=  2; $v <<=  2; }
        if ($v <= 0x7FFFFFFFFFFFFFFF) { $n +=  1; }
        return $n;
    }

    /**
     * Count zero bits before the lowest 1-bit. v & -v isolates that
     * bit (handling PHP_INT_MIN correctly: -PHP_INT_MIN overflows
     * back to PHP_INT_MIN, AND with v gives PHP_INT_MIN). Cascade
     * dispatches on the isolated bit's position.
     */
    public static function numberOfTrailingZeros(int $v): int
    {
        if ($v === 0) return 64;
        // v & -v isolates the lowest set bit. For PHP_INT_MIN, -v
        // wraps to PHP_INT_MIN so $bit = PHP_INT_MIN — which IS the
        // lowest 1-bit (the sign bit, bit 63). Special-case to skip
        // the negative-mask cascade complications.
        if ($v === \PHP_INT_MIN) return 63;
        $bit = $v & -$v;  // positive now
        $n = 0;
        if (($bit & 0x00000000FFFFFFFF) === 0) { $n += 32; $bit >>= 32; }
        if (($bit & 0x000000000000FFFF) === 0) { $n += 16; $bit >>= 16; }
        if (($bit & 0x00000000000000FF) === 0) { $n +=  8; $bit >>=  8; }
        if (($bit & 0x000000000000000F) === 0) { $n +=  4; $bit >>=  4; }
        if (($bit & 0x0000000000000003) === 0) { $n +=  2; $bit >>=  2; }
        if (($bit & 0x0000000000000001) === 0) { $n +=  1; }
        return $n;
    }

    public static function highestOneBit(int $v): int
    {
        if ($v === 0) return 0;
        if ($v < 0)   return \PHP_INT_MIN;  // sign bit IS the highest
        $v |= ($v >> 1);
        $v |= ($v >> 2);
        $v |= ($v >> 4);
        $v |= ($v >> 8);
        $v |= ($v >> 16);
        $v |= ($v >> 32);
        return $v - ($v >> 1);  // both shifts on positive — agree
    }

    public static function lowestOneBit(int $v): int
    {
        // v & -v. PHP unary - on PHP_INT_MIN wraps to PHP_INT_MIN —
        // matches Java's behaviour for lowestOneBit(Long.MIN_VALUE).
        return $v & -$v;
    }

    /** 64-bit bit reversal via SWAR. Mask constants stay within
     *  signed-int64 (top byte ≤ 0x55, so all positive literals). */
    public static function reverse(int $v): int
    {
        $v = ((($v >>  1) & 0x5555555555555555) | (($v & 0x5555555555555555) <<  1));
        $v = ((($v >>  2) & 0x3333333333333333) | (($v & 0x3333333333333333) <<  2));
        $v = ((($v >>  4) & 0x0F0F0F0F0F0F0F0F) | (($v & 0x0F0F0F0F0F0F0F0F) <<  4));
        $v = ((($v >>  8) & 0x00FF00FF00FF00FF) | (($v & 0x00FF00FF00FF00FF) <<  8));
        $v = ((($v >> 16) & 0x0000FFFF0000FFFF) | (($v & 0x0000FFFF0000FFFF) << 16));
        // Final 32-bit halve-and-swap. Use logical right shift via mask.
        $hi = ($v >> 32) & 0xFFFFFFFF;
        $lo = $v & 0xFFFFFFFF;
        // Combine: hi-half goes low, lo-half goes high. PHP int is
        // 64-bit; left shift by 32 stays in range when the shifted
        // value's high bit lands at bit 63 (and that's the sign bit
        // for a 64-bit two's-complement int — Java's view too).
        return ($lo << 32) | $hi;
    }

    /**
     * Swap byte order. Build the result via 8 byte-extractions and
     * shifts — avoid 0xFF000000_00000000-style mask literals (they
     * exceed PHP_INT_MAX as positive literals).
     */
    public static function reverseBytes(int $v): int
    {
        $b0 = $v & 0xFF;
        $b1 = ($v >>  8) & 0xFF;
        $b2 = ($v >> 16) & 0xFF;
        $b3 = ($v >> 24) & 0xFF;
        $b4 = ($v >> 32) & 0xFF;
        $b5 = ($v >> 40) & 0xFF;
        $b6 = ($v >> 48) & 0xFF;
        $b7 = ($v >> 56) & 0xFF;
        return ($b0 << 56) | ($b1 << 48) | ($b2 << 40) | ($b3 << 32)
             | ($b4 << 24) | ($b5 << 16) | ($b6 <<  8) |  $b7;
    }
}

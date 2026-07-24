<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.Double — bb-allowlist fill.
 *
 * PHP float IS a Java double: both are IEEE-754 binary64. This makes
 * the wrapper an identity-at-the-contract shim for every value op —
 * no precision loss, unlike Float_ (Java single-precision has no PHP
 * counterpart). The only real work is the surface where PHP's loose
 * numeric coercion diverges from Java's strict grammar:
 *
 *   - parseDouble rejects non-numeric strings with NumberFormatException;
 *     PHP's (float) cast would silently return 0.0 for "true"/"abc".
 *   - compare orders -0.0 < 0.0 and NaN above +Infinity via the
 *     doubleToLongBits total order; PHP's <=> collapses signed zero.
 *   - toString is Java's shortest-round-trip decimal (the canonical home;
 *     String.valueOf(double) delegates here, per the JDK).
 *
 * Validated against OpenJDK 25 by bench/parity/cases/java.lang.Double.json.
 */
final class Double
{
    public const MIN_VALUE          = 4.9e-324;         // smallest positive subnormal
    public const MAX_VALUE          = 1.7976931348623157e308;
    public const MIN_NORMAL         = 2.2250738585072014e-308;
    public const POSITIVE_INFINITY  = \INF;
    public const NEGATIVE_INFINITY  = -\INF;
    public const NaN                = \NAN;
    public const SIZE               = 64;
    public const BYTES              = 8;
    public const MAX_EXPONENT       = 1023;
    public const MIN_EXPONENT       = -1022;

    public static function isNaN(float $v): bool      { return \is_nan($v); }
    public static function isInfinite(float $v): bool { return \is_infinite($v); }
    public static function isFinite(float $v): bool   { return \is_finite($v); }

    /**
     * Java's total ordering on double: -0.0 < 0.0, and NaN is greater
     * than every other value (including +Infinity), with NaN == NaN.
     * The doubleToLongBits comparison realises exactly that order —
     * PHP's <=> would report -0.0 == 0.0 and mis-order NaN.
     */
    public static function compare(float $a, float $b): int
    {
        if ($a < $b) return -1;
        if ($a > $b) return 1;
        // Equal under < / > : either genuinely equal, or a signed-zero /
        // NaN pair that < and > both call false. Fall back to the bit order.
        $ab = self::doubleToLongBits($a);
        $bb = self::doubleToLongBits($b);
        return $ab === $bb ? 0 : ($ab < $bb ? -1 : 1);
    }

    /** Math.max semantics: NaN propagates; max(+0.0,-0.0) = +0.0. */
    public static function max(float $a, float $b): float
    {
        if (\is_nan($a) || \is_nan($b)) return \NAN;
        if ($a === 0.0 && $b === 0.0) {
            // Both zero (any sign). Prefer +0.0 if either operand is +0.0.
            return (self::doubleToRawLongBits($a) === 0 || self::doubleToRawLongBits($b) === 0)
                ? 0.0 : -0.0;
        }
        return $a >= $b ? $a : $b;
    }

    /** Math.min semantics: NaN propagates; min(+0.0,-0.0) = -0.0. */
    public static function min(float $a, float $b): float
    {
        if (\is_nan($a) || \is_nan($b)) return \NAN;
        if ($a === 0.0 && $b === 0.0) {
            // Both zero. Prefer -0.0 if either operand is -0.0 (its raw
            // bits are the sign-bit-only pattern, i.e. PHP_INT_MIN).
            return (self::doubleToRawLongBits($a) === \PHP_INT_MIN || self::doubleToRawLongBits($b) === \PHP_INT_MIN)
                ? -0.0 : 0.0;
        }
        return $a <= $b ? $a : $b;
    }

    public static function sum(float $a, float $b): float { return $a + $b; }

    /**
     * hashCode(double): bits = doubleToLongBits(v); (int)(bits ^ (bits >>> 32)).
     * Folds to a signed 32-bit int, matching Integer's i32 wrap.
     */
    public static function hashCode(float $v): int
    {
        $bits    = self::doubleToLongBits($v);
        $shifted = ($bits >> 32) & 0xFFFFFFFF;   // unsigned >>> 32
        $x       = ($bits ^ $shifted) & 0xFFFFFFFF;
        return $x >= 0x80000000 ? $x - 0x100000000 : $x;
    }

    /**
     * Canonical Java shortest-round-trip decimal. String.valueOf(double)
     * and String_::floatToString both delegate here (the JDK routes
     * String.valueOf(double) -> Double.toString), so the algorithm lives
     * in exactly one place.
     */
    public static function toString(float $v): string
    {
        if (\is_nan($v))  return 'NaN';
        if ($v === \INF)  return 'Infinity';
        if ($v === -\INF) return '-Infinity';
        // Signed zero: distinguish -0.0 by the sign bit (1/0.0 would raise
        // DivisionByZeroError in PHP 8).
        if ($v === 0.0) return (\pack('E', $v)[0] === "\x80") ? '-0.0' : '0.0';
        // Integer-valued finites: Java always renders a trailing ".0".
        if ($v === \floor($v) && \abs($v) < 1e16) {
            return \sprintf('%.1f', $v);
        }
        // Double.MIN_VALUE is a documented JDK quirk: Java emits "4.9E-324"
        // though the shorter "5.0E-324" also round-trips. PHP's dtoa picks
        // the shorter form, so pin the two subnormal extremes to Java's.
        if ($v === self::MIN_VALUE)  return '4.9E-324';
        if ($v === -self::MIN_VALUE) return '-4.9E-324';
        // Shortest decimal that round-trips. precision=-1 selects the same
        // digit set as Java's algorithm; PHP writes "E+308" where Java
        // writes "E308", so strip the plus.
        $old = \ini_set('precision', '-1');
        try {
            return \str_replace('E+', 'E', (string) $v);
        } finally {
            \ini_set('precision', $old);
        }
    }

    /**
     * Java's hexadecimal-floating-point string: sign, "0x", leading
     * significand digit (1 for normal, 0 for subnormal/zero), the 52-bit
     * fraction as trimmed hex, then "p" and the unbiased binary exponent.
     */
    public static function toHexString(float $v): string
    {
        if (\is_nan($v))  return 'NaN';
        if ($v === \INF)  return 'Infinity';
        if ($v === -\INF) return '-Infinity';

        $bits = self::doubleToRawLongBits($v);
        $sign = ($bits < 0) ? '-' : '';               // bit 63 set => negative
        $exp  = ($bits >> 52) & 0x7FF;                 // 11 exponent bits
        $mant = $bits & 0x000FFFFFFFFFFFFF;            // 52 fraction bits

        if ($exp === 0 && $mant === 0) {
            return $sign . '0x0.0p0';
        }
        // 52 fraction bits -> 13 hex digits, then trim trailing zeros but
        // keep at least one digit after the point.
        $frac = \rtrim(\str_pad(\dechex($mant), 13, '0', \STR_PAD_LEFT), '0');
        if ($frac === '') $frac = '0';

        if ($exp === 0) {
            // Subnormal: leading 0, fixed exponent of the minimum normal.
            return $sign . '0x0.' . $frac . 'p-1022';
        }
        return $sign . '0x1.' . $frac . 'p' . ($exp - 1023);
    }

    /**
     * Java's floating-point literal grammar (the fpRegex from the
     * Double.valueOf javadoc). Whitespace and an optional f/F/d/D suffix
     * are permitted; anything else is a NumberFormatException, where PHP's
     * (float) cast would silently coerce to 0.0.
     */
    private const FP_REGEX =
        '/^[\x00-\x20]*[+-]?(NaN|Infinity|'
        . '((((\d+)(\.)?(\d*)([eE][+-]?\d+)?)|'
        . '(\.(\d+)([eE][+-]?\d+)?)|'
        . '(((0[xX][0-9a-fA-F]+(\.)?)|(0[xX][0-9a-fA-F]*(\.)[0-9a-fA-F]+))[pP][+-]?\d+))'
        . '[fFdD]?))[\x00-\x20]*$/';

    public static function parseDouble(?string $s): float
    {
        // Java overload resolution binds a null literal to parseDouble(String),
        // which then NPEs on the null receiver — not a NumberFormatException.
        if ($s === null) {
            throw new NullPointerException('Cannot parse null as double');
        }
        if (\preg_match(self::FP_REGEX, $s) !== 1) {
            throw new NumberFormatException("For input string: \"{$s}\"");
        }
        $t = \trim($s, "\x00..\x20");
        // NaN / Infinity keywords (with optional sign).
        $sign = 1.0;
        if ($t !== '' && ($t[0] === '+' || $t[0] === '-')) {
            if ($t[0] === '-') $sign = -1.0;
            $t = \substr($t, 1);
        }
        if ($t === 'NaN')      return \NAN;
        if ($t === 'Infinity') return $sign * \INF;
        // Hex float (0x...p...): PHP's (float) cast can't parse it. No
        // corpus coverage yet; decode via the significand/exponent split.
        if (\preg_match('/^0[xX]/', $t)) {
            return $sign * self::parseHexFloat($t);
        }
        // Decimal: strip the optional f/F/d/D suffix, then cast. PHP float
        // parsing of a validated decimal literal matches Java's rounding.
        $t = \rtrim($t, 'fFdD');
        return $sign * (float) $t;
    }

    /** Decode a validated 0x…p… hex-float significand into a double. */
    private static function parseHexFloat(string $t): float
    {
        $t = \rtrim($t, 'fFdD');
        if (!\preg_match('/^0[xX]([0-9a-fA-F]*)(?:\.([0-9a-fA-F]*))?[pP]([+-]?\d+)$/', $t, $m)) {
            throw new NumberFormatException("For input string: \"{$t}\"");
        }
        [$intHex, $fracHex, $exp] = [$m[1], $m[2] ?? '', (int) $m[3]];
        $val = $intHex === '' ? 0.0 : (float) \hexdec($intHex);
        if ($fracHex !== '') {
            $scale = 1.0;
            $len   = \strlen($fracHex);
            for ($i = 0; $i < $len; $i++) {
                $scale /= 16.0;
                $val += \hexdec($fracHex[$i]) * $scale;
            }
        }
        return $val * (2.0 ** $exp);
    }

    public static function valueOf($v): float
    {
        // A null (or String) argument selects valueOf(String) in Java, which
        // delegates to parseDouble — so null NPEs rather than coercing to 0.0.
        return (\is_string($v) || $v === null) ? self::parseDouble($v) : (float) $v;
    }

    /**
     * IEEE-754 bit pattern as a Java signed long. pack('E') is big-endian
     * binary64; unpack('J') reads it back unsigned-64, which PHP wraps to
     * the same signed value Java's long carries. doubleToLongBits collapses
     * every NaN to the canonical quiet-NaN pattern.
     */
    public static function doubleToLongBits(float $v): int
    {
        if (\is_nan($v)) return 0x7FF8000000000000;
        return self::doubleToRawLongBits($v);
    }

    /** As doubleToLongBits but preserves a NaN's own bit pattern. */
    public static function doubleToRawLongBits(float $v): int
    {
        return \unpack('J', \pack('E', $v))[1];
    }

    /** Inverse of doubleToRawLongBits: reinterpret a 64-bit pattern. */
    public static function longBitsToDouble(int $bits): float
    {
        return \unpack('E', \pack('J', $bits))[1];
    }
}

<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.Character — bb-allowlist fill (6/80).
 *
 * Per CONTRACTS.md, Java char carries as PHP int at AOT runtime
 * (UTF-16 code unit, range 0..0xFFFF). All static methods take
 * int and return int / bool / string accordingly.
 *
 * ASCII range (0..127) implemented directly. Beyond ASCII, methods
 * delegate to IntlChar (PHP intl extension) when available, falling
 * back to false / identity for graceful degradation. Most bb-allowlist
 * traffic is ASCII; broader Unicode coverage lands when a fixture
 * exercises it.
 *
 * Validated against OpenJDK 25 by bench/parity/cases/java.lang.Character.json.
 */
final class Character
{
    public const MIN_VALUE         = 0;
    public const MAX_VALUE         = 0xFFFF;
    public const SIZE              = 16;
    public const BYTES             = 2;
    public const MIN_RADIX         = 2;
    public const MAX_RADIX         = 36;
    public const MIN_HIGH_SURROGATE = 0xD800;
    public const MAX_HIGH_SURROGATE = 0xDBFF;
    public const MIN_LOW_SURROGATE  = 0xDC00;
    public const MAX_LOW_SURROGATE  = 0xDFFF;
    public const MIN_SUPPLEMENTARY_CODE_POINT = 0x10000;

    public static function isDigit(int $ch): bool
    {
        if ($ch >= 0x30 && $ch <= 0x39) return true;
        return \class_exists('IntlChar', false) ? \IntlChar::isdigit($ch) : false;
    }

    public static function isLetter(int $ch): bool
    {
        if (($ch >= 0x41 && $ch <= 0x5A) || ($ch >= 0x61 && $ch <= 0x7A)) return true;
        return \class_exists('IntlChar', false) ? \IntlChar::isalpha($ch) : false;
    }

    public static function isLetterOrDigit(int $ch): bool
    {
        return self::isLetter($ch) || self::isDigit($ch);
    }

    /**
     * Java's isWhitespace ≠ Unicode isSpaceChar — explicitly excludes
     * non-break-space (0xA0). For ASCII range, the canonical list is:
     * \t (9), \n (10), \v (11), \f (12), \r (13), space (32),
     * file-separator family (28..31).
     */
    public static function isWhitespace(int $ch): bool
    {
        if ($ch === 0x20 || ($ch >= 0x09 && $ch <= 0x0D)) return true;
        if ($ch >= 0x1C && $ch <= 0x1F) return true;
        if ($ch < 0x80) return false;
        // Java's spec exception: 0xA0 (non-break-space) is NOT whitespace.
        if ($ch === 0xA0) return false;
        return \class_exists('IntlChar', false) ? \IntlChar::isspace($ch) : false;
    }

    public static function isUpperCase(int $ch): bool
    {
        if ($ch >= 0x41 && $ch <= 0x5A) return true;
        return \class_exists('IntlChar', false) ? \IntlChar::isupper($ch) : false;
    }

    public static function isLowerCase(int $ch): bool
    {
        if ($ch >= 0x61 && $ch <= 0x7A) return true;
        return \class_exists('IntlChar', false) ? \IntlChar::islower($ch) : false;
    }

    public static function isAlphabetic(int $ch): bool
    {
        // Java's isAlphabetic ≈ Unicode Alphabetic property.
        // For ASCII it matches isLetter.
        return self::isLetter($ch);
    }

    public static function toUpperCase(int $ch): int
    {
        if ($ch >= 0x61 && $ch <= 0x7A) return $ch - 32;
        return \class_exists('IntlChar', false) ? \IntlChar::toupper($ch) : $ch;
    }

    public static function toLowerCase(int $ch): int
    {
        if ($ch >= 0x41 && $ch <= 0x5A) return $ch + 32;
        return \class_exists('IntlChar', false) ? \IntlChar::tolower($ch) : $ch;
    }

    /**
     * Returns the digit value of `ch` in the given radix, or -1 if
     * the char is not a valid digit.
     */
    public static function digit(int $ch, int $radix): int
    {
        if ($radix < self::MIN_RADIX || $radix > self::MAX_RADIX) return -1;
        $v = -1;
        if ($ch >= 0x30 && $ch <= 0x39) $v = $ch - 0x30;
        elseif ($ch >= 0x61 && $ch <= 0x7A) $v = $ch - 0x61 + 10;
        elseif ($ch >= 0x41 && $ch <= 0x5A) $v = $ch - 0x41 + 10;
        return ($v < 0 || $v >= $radix) ? -1 : $v;
    }

    /**
     * Returns the char that represents the given digit in the given
     * radix, or 0 (NUL) if the args are invalid.
     */
    public static function forDigit(int $digit, int $radix): int
    {
        if ($radix < self::MIN_RADIX || $radix > self::MAX_RADIX) return 0;
        if ($digit < 0 || $digit >= $radix) return 0;
        return $digit < 10 ? 0x30 + $digit : 0x61 + $digit - 10;
    }

    public static function compare(int $a, int $b): int
    {
        // Unlike Integer.compare's sign-only result, Character.compare
        // returns the raw difference: spec says `(int)x - (int)y` per
        // the JDK implementation. For chars in 0..0xFFFF the subtraction
        // fits int range; PHP int is 64-bit so no overflow concern.
        return $a - $b;
    }

    public static function hashCode(int $ch): int
    {
        return $ch;
    }

    public static function isHighSurrogate(int $ch): bool
    {
        return $ch >= self::MIN_HIGH_SURROGATE && $ch <= self::MAX_HIGH_SURROGATE;
    }

    public static function isLowSurrogate(int $ch): bool
    {
        return $ch >= self::MIN_LOW_SURROGATE && $ch <= self::MAX_LOW_SURROGATE;
    }

    public static function isSurrogate(int $ch): bool
    {
        return $ch >= self::MIN_HIGH_SURROGATE && $ch <= self::MAX_LOW_SURROGATE;
    }

    public static function isSurrogatePair(int $high, int $low): bool
    {
        return self::isHighSurrogate($high) && self::isLowSurrogate($low);
    }

    public static function toCodePoint(int $high, int $low): int
    {
        return (($high - self::MIN_HIGH_SURROGATE) << 10)
             + ($low - self::MIN_LOW_SURROGATE)
             + self::MIN_SUPPLEMENTARY_CODE_POINT;
    }

    /**
     * Java Character.toString(char) returns a String whose single
     * character is the char. PHP equivalent uses mb_chr for UTF-8
     * encoding of the code unit.
     */
    public static function toString(int $ch): string
    {
        return \mb_chr($ch, 'UTF-8') ?: '';
    }

    public static function valueOf(int $ch): int
    {
        return $ch;  // wrapper-removed per BOXING.md
    }
}

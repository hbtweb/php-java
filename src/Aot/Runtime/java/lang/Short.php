<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.Short — bb-allowlist fill.
 *
 * A short is carried as a PHP int (already sign-extended) under the AOT
 * scalar contract. Parsing mirrors the JDK exactly: Integer does the
 * digit-walk and int-range guard, then Short range-checks to [-32768,
 * 32767]. Reusing Integer::parseInt / ::decode keeps one parser, matching
 * the real java.lang.Short which delegates to Integer the same way.
 *
 * Validated against OpenJDK 25 by bench/parity/cases/java.lang.Short.json.
 */
final class Short
{
    public const MIN_VALUE = -32768;
    public const MAX_VALUE = 32767;
    public const SIZE      = 16;
    public const BYTES     = 2;

    public static function parseShort(?string $s, int $radix = 10): int
    {
        $i = Integer::parseInt($s, $radix);
        if ($i < self::MIN_VALUE || $i > self::MAX_VALUE) {
            throw new NumberFormatException(
                "Value out of range. Value:\"{$s}\" Radix:{$radix}"
            );
        }
        return $i;
    }

    /** valueOf(short), valueOf(String), valueOf(String, radix). */
    public static function valueOf($v, ?int $radix = null): int
    {
        if ($v === null || \is_string($v)) {
            return self::parseShort($v, $radix ?? 10);
        }
        return (int) $v;
    }

    public static function decode(?string $nm): int
    {
        $i = Integer::decode($nm);
        if ($i < self::MIN_VALUE || $i > self::MAX_VALUE) {
            throw new NumberFormatException("Value {$i} out of range from input {$nm}");
        }
        return $i;
    }

    public static function toString(int $v): string { return (string) $v; }

    public static function hashCode(int $v): int { return $v; }

    /** Java returns the difference (can't overflow int at 16-bit width),
     *  not the -1/0/1 sign Integer.compare gives. */
    public static function compare(int $a, int $b): int { return $a - $b; }

    /** Unsigned order: difference of the zero-extended 16-bit values. */
    public static function compareUnsigned(int $a, int $b): int
    {
        return ($a & 0xFFFF) - ($b & 0xFFFF);
    }

    /** Swap the two bytes, then sign-extend back into the short range. */
    public static function reverseBytes(int $v): int
    {
        $r = ((($v & 0xFF) << 8) | (($v >> 8) & 0xFF)) & 0xFFFF;
        return ($r & 0x8000) ? ($r - 0x10000) : $r;
    }

    /** Zero-extend to the unsigned value; PHP int holds both exactly. */
    public static function toUnsignedInt(int $v): int  { return $v & 0xFFFF; }
    public static function toUnsignedLong(int $v): int { return $v & 0xFFFF; }
}

<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.Byte — bb-allowlist fill.
 *
 * A byte is carried as a PHP int (already sign-extended) under the AOT
 * scalar contract. As with Short, parsing delegates to Integer for the
 * digit-walk and int-range guard, then range-checks to [-128, 127] —
 * exactly how java.lang.Byte layers on Integer. No reverseBytes: a single
 * byte has nothing to swap (the JDK omits it too).
 *
 * Validated against OpenJDK 25 by bench/parity/cases/java.lang.Byte.json.
 */
final class Byte
{
    public const MIN_VALUE = -128;
    public const MAX_VALUE = 127;
    public const SIZE      = 8;
    public const BYTES     = 1;

    public static function parseByte(?string $s, int $radix = 10): int
    {
        $i = Integer::parseInt($s, $radix);
        if ($i < self::MIN_VALUE || $i > self::MAX_VALUE) {
            throw new NumberFormatException(
                "Value out of range. Value:\"{$s}\" Radix:{$radix}"
            );
        }
        return $i;
    }

    /** valueOf(byte), valueOf(String), valueOf(String, radix). */
    public static function valueOf($v, ?int $radix = null): int
    {
        if ($v === null || \is_string($v)) {
            return self::parseByte($v, $radix ?? 10);
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

    /** Java returns the difference (can't overflow int at 8-bit width),
     *  not the -1/0/1 sign Integer.compare gives. */
    public static function compare(int $a, int $b): int { return $a - $b; }

    /** Unsigned order: difference of the zero-extended 8-bit values. */
    public static function compareUnsigned(int $a, int $b): int
    {
        return ($a & 0xFF) - ($b & 0xFF);
    }

    /** Zero-extend to the unsigned value; PHP int holds both exactly. */
    public static function toUnsignedInt(int $v): int  { return $v & 0xFF; }
    public static function toUnsignedLong(int $v): int { return $v & 0xFF; }
}

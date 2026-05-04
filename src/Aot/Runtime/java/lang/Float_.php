<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.Float — Path C placeholder for the AOT shim shape.
 *
 * Underscored simple name because PHP 8 reserves `float`
 * case-insensitively as a class name. Builder::classFqn rewrites
 * `java/lang/Float` to this. Per BOXING.md, primitive float values
 * carry as PHP float at AOT runtime; wrapper instances never exist.
 * Method bodies fill in when bb-allowlist exercises them.
 */
final class Float_
{
    public const MIN_VALUE         = 1.4e-45;
    public const MAX_VALUE         = 3.4028235e38;
    public const POSITIVE_INFINITY = \INF;
    public const NEGATIVE_INFINITY = -\INF;
    public const NaN               = \NAN;
    public const SIZE              = 32;
    public const BYTES             = 4;

    public static function isNaN(float $v): bool      { return \is_nan($v); }
    public static function isInfinite(float $v): bool { return \is_infinite($v); }
    public static function isFinite(float $v): bool   { return \is_finite($v); }
    public static function compare(float $a, float $b): int
    {
        if (\is_nan($a)) return \is_nan($b) ? 0 : 1;
        if (\is_nan($b)) return -1;
        return $a <=> $b;
    }
    public static function max(float $a, float $b): float
    {
        if (\is_nan($a) || \is_nan($b)) return \NAN;
        return $a >= $b ? $a : $b;
    }
    public static function min(float $a, float $b): float
    {
        if (\is_nan($a) || \is_nan($b)) return \NAN;
        return $a <= $b ? $a : $b;
    }
    public static function sum(float $a, float $b): float { return $a + $b; }
    public static function valueOf($v): float
    {
        return \is_string($v) ? (float) $v : (float) $v;
    }
    public static function parseFloat(string $s): float { return (float) \trim($s); }
    public static function toString(float $v): string
    {
        if (\is_nan($v))                return 'NaN';
        if ($v === \INF)                return 'Infinity';
        if ($v === -\INF)               return '-Infinity';
        return (string) $v;
    }
}

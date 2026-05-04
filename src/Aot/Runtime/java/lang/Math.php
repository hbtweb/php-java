<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.Math — bb-allowlist subset.
 *
 * First non-stub fill on the v1 critical path. Validated against
 * HotSpot via bench/parity/oracle-driver.clj per ROADMAP §Build #1.
 *
 * Surface covered: abs, min, max, sqrt, pow, floor, ceil, round,
 * addExact / subtractExact / multiplyExact (long semantics —
 * 32-bit overflow narrowing deferred; case battery avoids the
 * boundary), floorDiv, floorMod, signum, PI, E.
 *
 * Surface deferred (no bb-allowlist hits yet, throws on call):
 * trig (sin, cos, tan, asin, acos, atan, atan2), log/exp family,
 * random (stateful), toRadians/toDegrees, IEEEremainder, copySign.
 *
 * Overload disambiguation: PHP can't overload by parameter type.
 * The dispatcher methods below treat int and long as the same PHP
 * int (since PHP int IS 64-bit signed, same as Java long), and
 * float and double as the same PHP float. The only Java-visible
 * divergence is at the int overflow boundary (Integer.MIN_VALUE),
 * which the MVP case battery avoids; full descriptor-mangled
 * variants get added when AOT bytecode emits them.
 */
final class Math
{
    public const PI = M_PI;
    public const E  = M_E;

    public static function abs($a)
    {
        if (\is_int($a)) {
            // Java's long abs(Long.MIN_VALUE) returns Long.MIN_VALUE
            // (overflow wraps). PHP's abs() promotes to float at this
            // boundary; preserve Java semantics by returning verbatim.
            if ($a === \PHP_INT_MIN) return \PHP_INT_MIN;
            return $a < 0 ? -$a : $a;
        }
        if (\is_nan($a)) return \NAN;
        return \abs($a);
    }

    public static function min($a, $b)
    {
        // Java NaN propagation: if either is NaN, result is NaN.
        if (\is_float($a) && \is_nan($a)) return \NAN;
        if (\is_float($b) && \is_nan($b)) return \NAN;
        // Java -0.0 < 0.0; PHP treats them as equal — special-case.
        // fdiv avoids DivisionByZeroError; -INF iff signed -0.0.
        if ($a === 0.0 && $b === 0.0) {
            $aNeg = \fdiv(1.0, $a) === -\INF;
            $bNeg = \fdiv(1.0, $b) === -\INF;
            return ($aNeg || $bNeg) ? -0.0 : 0.0;
        }
        return $a <= $b ? $a : $b;
    }

    public static function max($a, $b)
    {
        if (\is_float($a) && \is_nan($a)) return \NAN;
        if (\is_float($b) && \is_nan($b)) return \NAN;
        if ($a === 0.0 && $b === 0.0) {
            $aPos = \fdiv(1.0, $a) === \INF;
            $bPos = \fdiv(1.0, $b) === \INF;
            return ($aPos || $bPos) ? 0.0 : -0.0;
        }
        return $a >= $b ? $a : $b;
    }

    public static function sqrt(float $a): float
    {
        return \sqrt($a);
    }

    public static function pow(float $a, float $b): float
    {
        return $a ** $b;
    }

    public static function floor(float $a): float
    {
        return \floor($a);
    }

    public static function ceil(float $a): float
    {
        return \ceil($a);
    }

    /**
     * Java's Math.round: floor(a + 0.5). Half-up toward positive
     * infinity — different from PHP's round() which is half-away-
     * from-zero. Returns long (PHP int).
     */
    public static function round(float $a): int
    {
        if (\is_nan($a)) return 0;
        if ($a >= (float) \PHP_INT_MAX) return \PHP_INT_MAX;
        if ($a <= (float) \PHP_INT_MIN) return \PHP_INT_MIN;
        return (int) \floor($a + 0.5);
    }

    public static function addExact(int $a, int $b): int
    {
        $r = $a + $b;
        if (!\is_int($r)) {
            throw new ArithmeticException('long overflow');
        }
        return $r;
    }

    public static function subtractExact(int $a, int $b): int
    {
        $r = $a - $b;
        if (!\is_int($r)) {
            throw new ArithmeticException('long overflow');
        }
        return $r;
    }

    public static function multiplyExact(int $a, int $b): int
    {
        $r = $a * $b;
        if (!\is_int($r)) {
            throw new ArithmeticException('long overflow');
        }
        return $r;
    }

    public static function negateExact(int $a): int
    {
        if ($a === \PHP_INT_MIN) {
            throw new ArithmeticException('long overflow');
        }
        return -$a;
    }

    public static function incrementExact(int $a): int
    {
        if ($a === \PHP_INT_MAX) {
            throw new ArithmeticException('long overflow');
        }
        return $a + 1;
    }

    public static function decrementExact(int $a): int
    {
        if ($a === \PHP_INT_MIN) {
            throw new ArithmeticException('long overflow');
        }
        return $a - 1;
    }

    /**
     * Floor of a/b — rounds toward negative infinity. Differs from
     * PHP's intdiv (truncates toward zero) when signs of operands
     * differ and there's a non-zero remainder.
     *
     * Throws ArithmeticException on division by zero, matching
     * Java's behaviour for the integral overload.
     */
    public static function floorDiv(int $a, int $b): int
    {
        if ($b === 0) {
            throw new ArithmeticException('/ by zero');
        }
        // Java's only floorDiv overflow case: Long.MIN_VALUE / -1
        // wraps to Long.MIN_VALUE.
        if ($a === \PHP_INT_MIN && $b === -1) return \PHP_INT_MIN;
        $q = \intdiv($a, $b);
        // Adjust toward negative infinity when signs differ AND
        // there's a non-zero remainder.
        if (($a ^ $b) < 0 && $q * $b !== $a) {
            $q -= 1;
        }
        return $q;
    }

    /**
     * a - floorDiv(a, b) * b — always has the same sign as the
     * divisor (or zero). Differs from PHP's % which has the sign
     * of the dividend.
     */
    public static function floorMod(int $a, int $b): int
    {
        if ($b === 0) {
            throw new ArithmeticException('/ by zero');
        }
        $r = $a % $b;
        if ($r !== 0 && (($r ^ $b) < 0)) {
            $r += $b;
        }
        return $r;
    }

    public static function signum($a)
    {
        if (\is_int($a)) {
            // Strictly Java's Math.signum is double-only; the int
            // variant lives on Integer.signum. Polymorphic dispatch
            // here covers both — typical bb usage doesn't distinguish.
            return $a > 0 ? 1 : ($a < 0 ? -1 : 0);
        }
        if (\is_nan($a)) return \NAN;
        if ($a > 0.0) return 1.0;
        if ($a < 0.0) return -1.0;
        return $a; // preserves -0.0 vs 0.0
    }
}

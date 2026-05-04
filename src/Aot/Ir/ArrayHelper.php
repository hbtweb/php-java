<?php
declare(strict_types=1);
namespace PHPJava\Aot\Ir;

/**
 * Java-array semantics adapter. The IR Builder emits StaticCall
 * expressions targeting these helpers instead of inlining the
 * `(object){'v' => phpArray}` wrapper machinery. This keeps the IR
 * shape clean (every array op is a single Expr/Stmt instead of a
 * multi-line wrapper-property-access pattern); JIT-side, the helper
 * methods become trivially-inlinable `final static` calls.
 *
 * If escape analysis (Tier 1c-δ) later determines an array is
 * local-only, the IR rewriter can replace these StaticCall'd helpers
 * with direct PHP-array operations, eliminating the indirection.
 */
final class ArrayHelper
{
    public static function newPrimArray(int $size): \stdClass
    {
        return (object)['v' => \array_fill(0, $size, 0)];
    }

    public static function newRefArray(int $size): \stdClass
    {
        return (object)['v' => \array_fill(0, $size, null)];
    }

    /**
     * Polymorphic over array shape: accepts both raw PHP arrays (the
     * IR Builder's escape-analysis-friendly default for newarray /
     * anewarray / multianewarray, per `bootstrap.php`'s
     * `jvm_multianewarray`) and `(object){v=>...}` wrappers (the
     * legacy string-path emitter shape, plus `newPrimArray`/`newRefArray`
     * outputs above).
     *
     * Polymorphism here is a one-time runtime branch; the resulting
     * trace is shape-stable per call site, so JIT specialises cleanly.
     */
    public static function len($arr): int
    {
        return \is_array($arr) ? \count($arr) : \count($arr->v);
    }

    public static function get($arr, int $i): mixed
    {
        return \is_array($arr) ? $arr[$i] : $arr->v[$i];
    }

    public static function set(&$arr, int $i, mixed $v): void
    {
        if (\is_array($arr)) {
            $arr[$i] = $v;
        } else {
            $arr->v[$i] = $v;
        }
    }
}

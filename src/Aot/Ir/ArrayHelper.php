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

    public static function len(\stdClass $arr): int
    {
        return \count($arr->v);
    }

    public static function get(\stdClass $arr, int $i): mixed
    {
        return $arr->v[$i];
    }

    public static function set(\stdClass $arr, int $i, mixed $v): void
    {
        $arr->v[$i] = $v;
    }
}

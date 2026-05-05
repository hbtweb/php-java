<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime;

use PHPJava\Aot\Runtime\java\lang\String_Identity;

/**
 * Process-global String literal pool — Phase 2 of the
 * emit-then-prove-and-elide contract for String identity
 * (CONTRACTS.md §1).
 *
 * The pool holds a canonical `String_Identity` wrapper per *value*.
 * Identity-observation sites (System.identityHashCode on a raw
 * string, String.intern on either form) consult the pool so that
 * raw PHP strings — which have no per-instance identity — surface
 * a stable canonical identity through the wrapper's
 * `spl_object_id`. Concat results and explicit `new String(...)`
 * sites still allocate FRESH wrappers (Phase 1 + the IR Builder
 * StringConcatFactory wrap), so identity divergence between
 * "constructed" and "literal" strings is preserved.
 *
 * Java-faithful tracing:
 * - `te + st` → fresh wrapper W1, distinct identity.
 * - `"test"` literal → routes through pool on identity-observation
 *   only; on first such observation, the pool allocates W_lit.
 * - `test.intern()` (where test is W1) → pool-canonicalise: if
 *   pool already has "test" (e.g., literal observed first), return
 *   that canonical; else register W1 as canonical and return it.
 *
 * Phase 3 (deferred) — `WrapperEscapeAnalysis` IR pass — will
 * elide the concat wrapper at allocation sites where the wrapper's
 * identity is provably not observed within the method, restoring
 * the perf characteristics of unconditional elision while keeping
 * the contract honest.
 */
final class StringPool
{
    /** @var array<string, String_Identity> */
    private static array $pool = [];

    /**
     * Get-or-create the canonical wrapper for the given value.
     * The first observation of a value allocates; subsequent
     * observations return the same instance.
     */
    public static function intern(string $value): String_Identity
    {
        if (!isset(self::$pool[$value])) {
            self::$pool[$value] = new String_Identity($value);
        }
        return self::$pool[$value];
    }

    /**
     * Pool-canonicalise an existing wrapper. If the pool already
     * has a canonical entry for the wrapper's value, return that
     * (caller's `$w` is *not* registered). Otherwise register `$w`
     * as the canonical entry and return it.
     *
     * This is the operational core of Java's `String.intern()` —
     * the spec says "if the pool contains a string equal-by-value
     * to this, return the pool reference; otherwise add this and
     * return it."
     */
    public static function internWrapper(String_Identity $w): String_Identity
    {
        $value = $w->value;
        if (!isset(self::$pool[$value])) {
            self::$pool[$value] = $w;
        }
        return self::$pool[$value];
    }

    /**
     * Test-only: clear pool state. Not used by AOT emission;
     * provided for harness scenarios where deterministic pool
     * state across runs matters.
     */
    public static function reset(): void
    {
        self::$pool = [];
    }
}

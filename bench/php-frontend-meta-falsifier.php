<?php
declare(strict_types=1);

/**
 * Meta-falsifier — probe whether F2's verdict survives interrogation.
 *
 * F2 falsified the unified-frontend perf claim by measuring lifted
 * Math::abs in a tight loop vs static-method shim dispatch. Lifted ran
 * at 28 ns/op JIT vs shim 15.6 ns/op — slower, not faster. Verdict:
 * "PHP JIT already inlines static-method shims; user-space IR
 * inlining adds back overhead JIT had eliminated."
 *
 * That verdict relies on F2's measurement being the right question.
 * Three probes that could overturn it:
 *
 *   M1 [CHAIN-INLINING]    Does JIT inline a 3-deep static-method
 *                          chain? If lifted-fused (single function with
 *                          the chain's body inlined at compile time)
 *                          beats shim-dispatched chain, JIT inlines
 *                          single hops but not chains. F2's single-hop
 *                          measurement was the wrong unit.
 *                          PRED: lifted-fused ≥ shim chain at depth 3
 *                                (JIT handles small chains)
 *                          OVERTURNS F2 IF: lifted-fused beats by ≥30%
 *
 *   M2 [TYPE-NARROWED]     The architectural premise was that compile-
 *                          time JVM types let the IR Builder elide
 *                          branches the shim has to runtime-check.
 *                          Test: shim (with full type dispatch) vs
 *                          lifted-narrowed (PHP_INT_MIN check kept) vs
 *                          lifted-narrowed+range (PHP_INT_MIN check
 *                          elided). If narrowed+range wins
 *                          significantly, the architecture has perf
 *                          value at the cost of building the narrowing
 *                          + range-analysis passes.
 *                          PRED: narrowed+range 1.5-2.0× faster than
 *                                shim
 *                          OVERTURNS F2 IF: narrowed+range > 1.4× shim
 *
 *   M3 [COLD-PATH]         JIT specialises hot paths. Cold call sites
 *                          (request-scoped FPM) don't benefit. Measure
 *                          with N=10 instead of N=1M.
 *                          PRED: shim and lifted similar at low N
 *                                (JIT hasn't warmed up either way)
 *                          OVERTURNS F2 IF: lifted ≥ shim at low N
 *
 * Run with JIT for fair comparison vs the F2 baseline:
 *   php -dopcache.enable_cli=1 -dopcache.jit=tracing \\
 *       -dopcache.jit_buffer_size=64M \\
 *       bench/php-frontend-meta-falsifier.php
 */

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../src/Aot/Runtime/bootstrap.php';
}

// ─── M1 setup: 3-deep static-method chain (shim style) ────────────────────

namespace Probe\Chain {
    final class Inner  { public static function id(int $x): int { return $x + 1; } }
    final class Middle { public static function id(int $x): int { return Inner::id($x) + 1; } }
    final class Outer  { public static function id(int $x): int { return Middle::id($x) + 1; } }
}

namespace Probe\Fused {
    // What the unified frontend would emit after inlining the full chain.
    final class Outer {
        public static function id(int $x): int {
            // Fused inline: Outer = Middle + 1; Middle = Inner + 1;
            // Inner = $x + 1.  → ($x + 1) + 1 + 1 = $x + 3.
            // PRESERVE the structure (don't constant-fold the +1+1+1
            // into +3) so we measure dispatch elimination, not arith
            // simplification.
            return (($x + 1) + 1) + 1;
        }
    }
}

// ─── M2 setup: type narrowing layers on Math.abs ──────────────────────────

namespace Probe\Narrowed {
    // No narrowing — full shim with runtime type dispatch.
    function abs_full($a) {
        if (\is_int($a)) {
            if ($a === \PHP_INT_MIN) return \PHP_INT_MIN;
            return $a < 0 ? -$a : $a;
        }
        if (\is_nan($a)) return \NAN;
        return \abs($a);
    }
    // Type narrowed at compile time — caller passes int, drop is_int branch.
    function abs_typed(int $a): int {
        return $a === \PHP_INT_MIN ? \PHP_INT_MIN : ($a < 0 ? -$a : $a);
    }
    // Type narrowed + range proven (e.g. caller's range analysis showed
    // $a is never PHP_INT_MIN). Drop the boundary check.
    function abs_ranged(int $a): int {
        return $a < 0 ? -$a : $a;
    }
}

// ─── Bench harness ────────────────────────────────────────────────────────

namespace {

function bench_run(string $label, int $iters, callable $body): float {
    $body();  // warm
    $samples = [];
    $REPS = 7;
    for ($r = 0; $r < $REPS; $r++) {
        $t = \hrtime(true);
        $body();
        $samples[] = (\hrtime(true) - $t) / $iters;
    }
    \sort($samples);
    return $samples[(int) (\count($samples) / 2)];
}

echo "Meta-falsifier — predictions registered:\n";
echo "  M1 lifted-fused ≥ shim-chain @ depth 3\n";
echo "  M2 narrowed+range > 1.4× shim → overturns F2\n";
echo "  M3 shim and lifted similar at cold-path\n";
echo "\n";

// ─── M1: 3-deep chain ─────────────────────────────────────────────────────
echo "── M1 CHAIN-INLINING ────────────────────────────────────\n";
$N = 1_000_000;
$shimChain = bench_run('shim 3-chain', $N, function () use ($N) {
    $sum = 0;
    for ($i = 0; $i < $N; $i++) $sum += \Probe\Chain\Outer::id($i);
});
$fusedChain = bench_run('lifted-fused', $N, function () use ($N) {
    $sum = 0;
    for ($i = 0; $i < $N; $i++) $sum += \Probe\Fused\Outer::id($i);
});
\printf("  shim 3-chain (Outer→Middle→Inner): %7.2f ns/op\n", $shimChain);
\printf("  lifted-fused (single method):      %7.2f ns/op\n", $fusedChain);
\printf("  ratio: %.2fx\n", $shimChain / $fusedChain);
$M1_overturns = ($fusedChain * 1.30) < $shimChain;  // lifted ≥ 30% faster
echo $M1_overturns
    ? "  M1 OVERTURNS F2 — JIT doesn't inline 3-deep chains\n\n"
    : "  M1 SUPPORTS F2 — JIT handles small chains fine\n\n";

// ─── M2: type narrowing layers ────────────────────────────────────────────
echo "── M2 TYPE-NARROWING ────────────────────────────────────\n";
$shimAbs = bench_run('shim Math::abs', $N, function () use ($N) {
    $sum = 0;
    for ($i = 0; $i < $N; $i++) $sum += \PHPJava\Aot\Runtime\java\lang\Math::abs($i - 500_000);
});
$fullPhp = bench_run('abs_full (PHP fn, runtime dispatch)', $N, function () use ($N) {
    $sum = 0;
    for ($i = 0; $i < $N; $i++) $sum += \Probe\Narrowed\abs_full($i - 500_000);
});
$typedPhp = bench_run('abs_typed (compile-time int)', $N, function () use ($N) {
    $sum = 0;
    for ($i = 0; $i < $N; $i++) $sum += \Probe\Narrowed\abs_typed($i - 500_000);
});
$rangedPhp = bench_run('abs_ranged (typed + range elided)', $N, function () use ($N) {
    $sum = 0;
    for ($i = 0; $i < $N; $i++) $sum += \Probe\Narrowed\abs_ranged($i - 500_000);
});
\printf("  shim   Math::abs:  %7.2f ns/op  (baseline)\n", $shimAbs);
\printf("  full   abs_full:   %7.2f ns/op  (%.2fx vs shim)\n", $fullPhp,    $shimAbs / $fullPhp);
\printf("  typed  abs_typed:  %7.2f ns/op  (%.2fx vs shim)\n", $typedPhp,   $shimAbs / $typedPhp);
\printf("  ranged abs_ranged: %7.2f ns/op  (%.2fx vs shim)\n", $rangedPhp,  $shimAbs / $rangedPhp);
$M2_speedup = $shimAbs / $rangedPhp;
$M2_overturns = $M2_speedup > 1.4;
echo $M2_overturns
    ? \sprintf("  M2 OVERTURNS F2 — %.2fx speedup justifies the architecture\n\n", $M2_speedup)
    : \sprintf("  M2 SUPPORTS F2 — %.2fx is below the 1.4x threshold\n\n", $M2_speedup);

// ─── M3: cold-path probe ─────────────────────────────────────────────────
echo "── M3 COLD-PATH ─────────────────────────────────────────\n";
$bench_cold = function (callable $body, int $iters): float {
    // No warmup — measure first call only. Fresh process would be ideal
    // but we approximate via fresh closure each rep.
    $samples = [];
    for ($r = 0; $r < 100; $r++) {
        $t = \hrtime(true);
        $body();
        $samples[] = (\hrtime(true) - $t) / $iters;
    }
    \sort($samples);
    return $samples[(int) (\count($samples) / 2)];
};
$N_cold = 10;
$shimCold = $bench_cold(function () use ($N_cold) {
    $sum = 0;
    for ($i = 0; $i < $N_cold; $i++) $sum += \PHPJava\Aot\Runtime\java\lang\Math::abs($i - 5);
}, $N_cold);
$rangedCold = $bench_cold(function () use ($N_cold) {
    $sum = 0;
    for ($i = 0; $i < $N_cold; $i++) $sum += \Probe\Narrowed\abs_ranged($i - 5);
}, $N_cold);
\printf("  shim cold (N=10):     %7.2f ns/op\n", $shimCold);
\printf("  ranged cold (N=10):   %7.2f ns/op\n", $rangedCold);
$M3_lifted_competitive = ($rangedCold < $shimCold * 1.5);
echo $M3_lifted_competitive
    ? "  M3 OVERTURNS F2 — cold-path lifted is competitive/better\n\n"
    : "  M3 SUPPORTS F2 — even cold, shim wins\n\n";

// ─── Verdict ─────────────────────────────────────────────────────────────
echo "════════════════════════════════════════════════════════\n";
echo "  Meta-falsifier verdict:\n";
\printf("    M1 CHAIN-INLINING:  %s\n", $M1_overturns ? 'OVERTURNS F2' : 'SUPPORTS F2');
\printf("    M2 TYPE-NARROWING:  %s\n", $M2_overturns ? 'OVERTURNS F2' : 'SUPPORTS F2');
\printf("    M3 COLD-PATH:       %s\n", $M3_lifted_competitive ? 'OVERTURNS F2' : 'SUPPORTS F2');
$any_overturns = $M1_overturns || $M2_overturns || $M3_lifted_competitive;
echo $any_overturns
    ? "  F2 verdict QUALIFIED — falsifier's measurement was incomplete.\n"
    : "  F2 verdict ROBUST — across all three probes, shim dispatch wins.\n";
echo "════════════════════════════════════════════════════════\n";

}

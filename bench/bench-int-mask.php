<?php
// Microbench: cost of the JVM 32-bit signed-int overflow mask.
//
// Per CONTRACTS.md §1, int arith results need `(v << 32) >> 32` to
// preserve JVM 32-bit overflow semantics on PHP's 64-bit ints. This
// bench measures the per-iadd cost of that mask in isolation, so the
// budget claim ("1 ns extra per op") is rank-1 verified.
//
// Two functions, identical except for the mask. Each runs a 1000-iter
// int-sum loop using PHP `goto` to mirror the AOT-emitted control
// flow. The bench harness calls each 100,000 times so the JIT trace
// stabilises.
//
// Run:  php -d opcache.enable_cli=1 -d opcache.jit=tracing \
//           -d opcache.jit_buffer_size=1024M bench/bench-int-mask.php
//
// Reference numbers (Kali WSL, PHP 8.4.20, 5-run median):
//   unmasked: ~1.73 ns/iadd
//   masked:   ~1.93 ns/iadd
//   delta:    ~0.20 ns/iadd  (~12% overhead)
declare(strict_types=1);

function unmasked(): int {
    $L = [0, 0];
    L_0:
    if ($L[1] >= 1000) goto L_END;
    $L[0] = $L[0] + $L[1];
    $L[1] += 1;
    goto L_0;
    L_END:
    return $L[0];
}

function masked(): int {
    $L = [0, 0];
    L_0:
    if ($L[1] >= 1000) goto L_END;
    $L[0] = $L[0] + $L[1] << 32 >> 32;   // (sum << 32) >> 32 — 32-bit signed
    $L[1] += 1;
    goto L_0;
    L_END:
    return $L[0];
}

function bench(string $name, callable $fn, int $iters): float {
    $fn(); // warm
    $t0 = microtime(true);
    for ($i = 0; $i < $iters; $i++) $fn();
    $elapsed = (microtime(true) - $t0) * 1e9;
    $perCall = $elapsed / $iters;
    $perOp = $perCall / 1000;
    printf("  %-10s %10.1f ns/call  %5.3f ns/iadd   (%d calls)\n",
        $name, $perCall, $perOp, $iters);
    return $perOp;
}

echo "=== iadd-1k: unmasked vs JVM 32-bit-masked ===\n";
$iters = 100000;
$u = bench('unmasked', 'unmasked', $iters);
$m = bench('masked',   'masked',   $iters);
printf("  delta:     %5.3f ns/iadd  (%.1f%% overhead)\n",
    $m - $u, ($m - $u) / $u * 100);
echo "\n";

// Sanity — same in-range result; overflow case differs.
echo "  unmasked(): " . unmasked() . "  (in-range — both should be 499500)\n";
echo "  masked():   " . masked() . "\n";

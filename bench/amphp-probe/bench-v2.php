<?php
declare(strict_types=1);

/**
 * Compare PoC v1 vs v2 vs the absolute floor (bare $fn() call).
 * Three workloads — same as bench-poc.php's Workload 1/2/3.
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/poc-runtime.php';
require __DIR__ . '/poc-runtime-v2.php';

const N = 100_000;
const REPS = 5;

function bench(string $label, callable $fn): array
{
    $samples = [];
    for ($r = 0; $r < REPS; $r++) {
        $t0 = \hrtime(true);
        $fn();
        $samples[] = (\hrtime(true) - $t0) / N;
    }
    \sort($samples);
    return ['label' => $label, 'median' => $samples[(int) (REPS / 2)]];
}

// 1. Bare floor.
$bare = bench('bare $fn() — floor', function () {
    $fn = static fn() => 42;
    for ($i = 0; $i < N; $i++) $fn();
});

// 2. AMPHP for reference.
$amp = bench('Amp\\async + await (return-value)', function () {
    for ($i = 0; $i < N; $i++) \Amp\async(static fn() => 42)->await();
});

// 3. PoC v1.
$pocV1 = bench('PoC v1 async + await', function () {
    \Bench\Poc\Runtime::reset();
    for ($i = 0; $i < N; $i++) {
        $id = \Bench\Poc\Runtime::async(static fn() => 42);
        \Bench\Poc\Runtime::await($id);
    }
});

// 4. PoC v2 — closure-wrapper-free, sp-queue, inline settle.
$pocV2 = bench('PoC v2 async + await', function () {
    \Bench\Poc\V2\Runtime::reset();
    for ($i = 0; $i < N; $i++) {
        $id = \Bench\Poc\V2\Runtime::async(static fn() => 42);
        \Bench\Poc\V2\Runtime::await($id);
    }
});

// 5. Bulk 1000 in flight — v2.
$bulkN = 1000;
$bulkReps = 5;
$v2BulkSamples = [];
for ($r = 0; $r < $bulkReps; $r++) {
    \Bench\Poc\V2\Runtime::reset();
    $t0 = \hrtime(true);
    $ids = [];
    for ($i = 0; $i < $bulkN; $i++) $ids[] = \Bench\Poc\V2\Runtime::async(static fn() => 42);
    foreach ($ids as $id) \Bench\Poc\V2\Runtime::await($id);
    $v2BulkSamples[] = (\hrtime(true) - $t0) / $bulkN;
}
\sort($v2BulkSamples);

$ampBulkSamples = [];
for ($r = 0; $r < $bulkReps; $r++) {
    $t0 = \hrtime(true);
    $futures = [];
    for ($i = 0; $i < $bulkN; $i++) $futures[] = \Amp\async(static fn() => 42);
    foreach ($futures as $f) $f->await();
    $ampBulkSamples[] = (\hrtime(true) - $t0) / $bulkN;
}
\sort($ampBulkSamples);

echo "PHP " . \PHP_VERSION . " | iters=" . N . " | bulkN={$bulkN} | reps=" . REPS;
echo " | jit=" . (\ini_get('opcache.jit') ?: 'off') . "\n";
echo \str_repeat('─', 80) . "\n";
foreach ([$bare, $amp, $pocV1, $pocV2] as $r) {
    \printf("  %-44s  %7.0f ns/op\n", $r['label'], $r['median']);
}
echo "\n";
echo "  Bulk 1000 in flight\n";
\printf("    Amp: %7.0f ns/op\n", $ampBulkSamples[(int) ($bulkReps / 2)]);
\printf("    v2:  %7.0f ns/op\n", $v2BulkSamples[(int) ($bulkReps / 2)]);
echo \str_repeat('─', 80) . "\n";
\printf("v2 overhead vs bare floor: %.0f ns\n", $pocV2['median'] - $bare['median']);
\printf("v1 → v2 speedup: %.2fx\n", $pocV1['median'] / $pocV2['median']);
\printf("v2 vs Amp:  %.2fx\n", $pocV2['median'] / $amp['median']);

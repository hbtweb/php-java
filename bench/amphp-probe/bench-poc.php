<?php
declare(strict_types=1);

/**
 * Three-way bench: raw Fiber vs AMPHP vs PATTERNS.md-style PoC.
 *
 * Same workloads as bench.php; adds the PoC runtime as a third column.
 * Tests whether the predicted 6–10× speedup over AMPHP materialises.
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/poc-runtime.php';

use Bench\Poc\Runtime;

const ITERS = 10_000;
const REPS = 5;

function bench(string $label, callable $fn): array
{
    $samples = [];
    for ($r = 0; $r < REPS; $r++) {
        $t0 = \hrtime(true);
        $fn();
        $samples[] = (\hrtime(true) - $t0) / ITERS;
    }
    \sort($samples);
    return [
        'label'  => $label,
        'median' => $samples[(int) (REPS / 2)],
        'min'    => $samples[0],
        'max'    => $samples[REPS - 1],
    ];
}

function fmt(array $r): string
{
    return \sprintf(
        '%-46s  median=%8.0f ns/op',
        $r['label'], $r['median']
    );
}

// ── 1. Empty spawn-and-await ───────────────────────────────────────────

$rawEmpty = bench('raw Fiber empty spawn+join', function () {
    for ($i = 0; $i < ITERS; $i++) {
        $f = new Fiber(function () {});
        $f->start();
    }
});

$ampEmpty = bench('Amp\\async empty spawn+await', function () {
    for ($i = 0; $i < ITERS; $i++) {
        \Amp\async(function () {})->await();
    }
});

$pocEmpty = bench('PoC Runtime::async empty spawn+await', function () {
    for ($i = 0; $i < ITERS; $i++) {
        $id = Runtime::async(static fn() => null);
        Runtime::await($id);
    }
});

// ── 2. Spawn+await with a return value ────────────────────────────────

$rawReturn = bench('raw Fiber return-value spawn+join', function () {
    for ($i = 0; $i < ITERS; $i++) {
        $f = new Fiber(static fn() => 42);
        $f->start();
        $f->getReturn();
    }
});

$ampReturn = bench('Amp\\async return-value spawn+await', function () {
    for ($i = 0; $i < ITERS; $i++) {
        \Amp\async(static fn() => 42)->await();
    }
});

$pocReturn = bench('PoC Runtime::async return-value spawn+await', function () {
    for ($i = 0; $i < ITERS; $i++) {
        $id = Runtime::async(static fn() => 42);
        Runtime::await($id);
    }
});

// ── 3. 1000-in-flight bulk ────────────────────────────────────────────

$bulkN = 1000;
$bulkReps = 5;

$rawBulkSamples = [];
for ($r = 0; $r < $bulkReps; $r++) {
    $t0 = \hrtime(true);
    $fibers = [];
    for ($i = 0; $i < $bulkN; $i++) {
        $f = new Fiber(static fn() => 42);
        $f->start();
        $fibers[] = $f;
    }
    $rawBulkSamples[] = (\hrtime(true) - $t0) / $bulkN;
}
\sort($rawBulkSamples);

$ampBulkSamples = [];
for ($r = 0; $r < $bulkReps; $r++) {
    $t0 = \hrtime(true);
    $futures = [];
    for ($i = 0; $i < $bulkN; $i++) {
        $futures[] = \Amp\async(static fn() => 42);
    }
    foreach ($futures as $fut) {
        $fut->await();
    }
    $ampBulkSamples[] = (\hrtime(true) - $t0) / $bulkN;
}
\sort($ampBulkSamples);

$pocBulkSamples = [];
for ($r = 0; $r < $bulkReps; $r++) {
    Runtime::reset();
    $t0 = \hrtime(true);
    $ids = [];
    for ($i = 0; $i < $bulkN; $i++) {
        $ids[] = Runtime::async(static fn() => 42);
    }
    foreach ($ids as $id) {
        Runtime::await($id);
    }
    $pocBulkSamples[] = (\hrtime(true) - $t0) / $bulkN;
}
\sort($pocBulkSamples);

// ── Report ─────────────────────────────────────────────────────────────

echo "PHP " . \PHP_VERSION . " | iters=" . ITERS . " | bulkN={$bulkN} | reps={$bulkReps}\n";
echo "opcache.jit=" . (\ini_get('opcache.jit') ?: 'off') . "\n";
echo \str_repeat('─', 80) . "\n";

echo "Workload 1: empty spawn+await\n";
echo "  " . fmt($rawEmpty) . "\n";
echo "  " . fmt($ampEmpty) . "\n";
echo "  " . fmt($pocEmpty) . "\n";
\printf("  → PoC vs Amp: %.2fx   PoC vs raw: %.2fx\n",
    $pocEmpty['median'] / $ampEmpty['median'],
    $pocEmpty['median'] / $rawEmpty['median']);
echo "\n";

echo "Workload 2: return-value spawn+await\n";
echo "  " . fmt($rawReturn) . "\n";
echo "  " . fmt($ampReturn) . "\n";
echo "  " . fmt($pocReturn) . "\n";
\printf("  → PoC vs Amp: %.2fx   PoC vs raw: %.2fx\n",
    $pocReturn['median'] / $ampReturn['median'],
    $pocReturn['median'] / $rawReturn['median']);
echo "\n";

echo "Workload 3: 1000-in-flight bulk\n";
\printf("  raw Fiber 1000 spawn (no await)            median=%8.0f ns/op\n", $rawBulkSamples[(int)($bulkReps / 2)]);
\printf("  Amp\\async 1000 spawn+await in flight       median=%8.0f ns/op\n", $ampBulkSamples[(int)($bulkReps / 2)]);
\printf("  PoC  1000 spawn+await in flight             median=%8.0f ns/op\n", $pocBulkSamples[(int)($bulkReps / 2)]);
\printf("  → PoC vs Amp: %.2fx\n",
    $pocBulkSamples[(int)($bulkReps / 2)] / $ampBulkSamples[(int)($bulkReps / 2)]);

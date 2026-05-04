<?php
declare(strict_types=1);

/**
 * AMPHP/Revolt vs raw PHP Fiber — does AMPHP's Future/EventLoop layer
 * add enough overhead to justify forking it for PHPJava's
 * Thread/CompletableFuture surface?
 *
 * Workloads:
 *   1. Empty fiber spawn-and-join — minimum context-switch cost
 *   2. Sleep-zero yield round-trip — cooperative reschedule
 *   3. 1000-fiber bulk spawn — throughput ceiling
 *   4. Future composition chain (5-deep) — CompletableFuture analog
 *
 * Run:  php bench/amphp-probe/bench.php
 *
 * Each variant runs N iters, reports median + p99 of per-op cost.
 * 5 outer reps for noise reduction.
 */

require __DIR__ . '/vendor/autoload.php';

const ITERS = 10_000;
const REPS = 5;

function bench(string $label, callable $fn): array
{
    $samples = [];
    for ($r = 0; $r < REPS; $r++) {
        $t0 = \hrtime(true);
        $fn();
        $samples[] = (\hrtime(true) - $t0) / ITERS; // ns/op
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
        '%-44s  median=%8.0f ns/op  min=%8.0f  max=%8.0f',
        $r['label'], $r['median'], $r['min'], $r['max']
    );
}

// ── 1. Empty fiber spawn-and-join ─────────────────────────────────────

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

// ── 2. One-yield round trip (suspend/resume cycle) ────────────────────

$rawYield = bench('raw Fiber suspend+resume cycle', function () {
    for ($i = 0; $i < ITERS; $i++) {
        $f = new Fiber(function () { Fiber::suspend(); });
        $f->start();
        $f->resume();
    }
});

$ampYield = bench('Amp\\async with delay(0) reschedule', function () {
    for ($i = 0; $i < ITERS; $i++) {
        \Amp\async(function () { \Amp\delay(0); })->await();
    }
});

// ── 3. Bulk-spawn throughput (smaller iter count — many fibers in flight) ─

$bulkN = 1000;
$bulkReps = 5;

$rawBulkSamples = [];
for ($r = 0; $r < $bulkReps; $r++) {
    $t0 = \hrtime(true);
    $fibers = [];
    for ($i = 0; $i < $bulkN; $i++) {
        $f = new Fiber(function () { Fiber::suspend(); });
        $f->start();
        $fibers[] = $f;
    }
    foreach ($fibers as $f) $f->resume();
    $rawBulkSamples[] = (\hrtime(true) - $t0) / $bulkN;
}
\sort($rawBulkSamples);
$rawBulk = [
    'label'  => 'raw Fiber 1000 spawn+resume in flight',
    'median' => $rawBulkSamples[(int) ($bulkReps / 2)],
    'min'    => $rawBulkSamples[0],
    'max'    => $rawBulkSamples[$bulkReps - 1],
];

$ampBulkSamples = [];
for ($r = 0; $r < $bulkReps; $r++) {
    $t0 = \hrtime(true);
    $futures = [];
    for ($i = 0; $i < $bulkN; $i++) {
        $futures[] = \Amp\async(function () { \Amp\delay(0); });
    }
    foreach ($futures as $fut) $fut->await();
    $ampBulkSamples[] = (\hrtime(true) - $t0) / $bulkN;
}
\sort($ampBulkSamples);
$ampBulk = [
    'label'  => 'Amp\\async 1000 spawn+await in flight',
    'median' => $ampBulkSamples[(int) ($bulkReps / 2)],
    'min'    => $ampBulkSamples[0],
    'max'    => $ampBulkSamples[$bulkReps - 1],
];

// ── 4. Future composition (5-deep chain) — CompletableFuture analog ───

$ampChain = bench('Amp\\Future 5-deep chain (then-style)', function () {
    for ($i = 0; $i < ITERS; $i++) {
        $f = \Amp\async(fn() => 1);
        // Future doesn't have a native then; we emulate with async wrapping.
        $f = \Amp\async(fn() => $f->await() + 1);
        $f = \Amp\async(fn() => $f->await() + 1);
        $f = \Amp\async(fn() => $f->await() + 1);
        $f = \Amp\async(fn() => $f->await() + 1);
        $f->await();
    }
});

// Raw equivalent: 5 nested fibers awaiting each other.
$rawChain = bench('raw Fiber 5-deep chain (manual)', function () {
    for ($i = 0; $i < ITERS; $i++) {
        $result = 0;
        $f1 = new Fiber(function () use (&$result) { $result = 1; Fiber::suspend(); });
        $f1->start();
        $f1->resume();
        $f2 = new Fiber(function () use (&$result) { $result += 1; Fiber::suspend(); });
        $f2->start();
        $f2->resume();
        $f3 = new Fiber(function () use (&$result) { $result += 1; Fiber::suspend(); });
        $f3->start();
        $f3->resume();
        $f4 = new Fiber(function () use (&$result) { $result += 1; Fiber::suspend(); });
        $f4->start();
        $f4->resume();
        $f5 = new Fiber(function () use (&$result) { $result += 1; Fiber::suspend(); });
        $f5->start();
        $f5->resume();
    }
});

// ── Report ─────────────────────────────────────────────────────────────

echo "PHP " . \PHP_VERSION . " | iters=" . ITERS . " | reps=" . REPS . "\n";
echo "opcache.jit=" . (\ini_get('opcache.jit') ?: 'off') . "\n";
echo \str_repeat('─', 80) . "\n";
echo fmt($rawEmpty) . "\n";
echo fmt($ampEmpty) . "\n";
$ratio1 = $ampEmpty['median'] / $rawEmpty['median'];
\printf("    → Amp/raw ratio: %.2fx\n", $ratio1);
echo "\n";
echo fmt($rawYield) . "\n";
echo fmt($ampYield) . "\n";
$ratio2 = $ampYield['median'] / $rawYield['median'];
\printf("    → Amp/raw ratio: %.2fx\n", $ratio2);
echo "\n";
echo fmt($rawBulk) . "\n";
echo fmt($ampBulk) . "\n";
$ratio3 = $ampBulk['median'] / $rawBulk['median'];
\printf("    → Amp/raw ratio: %.2fx\n", $ratio3);
echo "\n";
echo fmt($rawChain) . "\n";
echo fmt($ampChain) . "\n";
$ratio4 = $ampChain['median'] / $rawChain['median'];
\printf("    → Amp/raw ratio: %.2fx\n", $ratio4);
echo "\n";

echo \str_repeat('─', 80) . "\n";
\printf(
    "Mean Amp/raw ratio: %.2fx (workload-mean)\n",
    ($ratio1 + $ratio2 + $ratio3 + $ratio4) / 4
);

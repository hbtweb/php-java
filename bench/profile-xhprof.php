<?php
// Run BenchAdd::sum1k under xhprof and dump the top cost centers.
//
// Usage:
//   php -d extension=xhprof.so profile-xhprof.php
//
// Output: ranked list by exclusive wall time, plus inclusive %, calls,
// and a roll-up by file basename so we can see what fraction of the
// 5.22 us/op is in each architectural layer.

if (!extension_loaded('xhprof')) {
    fwrite(STDERR, "xhprof extension not loaded\n");
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Core\JavaClass;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([[ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures']]);
$cls = JavaClass::load('BenchAdd');

// Warm up so class-load cost doesn't pollute.
$cls->getInvoker()->getStatic()->getMethods()->call('sum1k');

xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);

// Run sum1k 10 times — that's 10 × 8000 = 80k bytecode ops.
for ($i = 0; $i < 10; $i++) {
    $cls->getInvoker()->getStatic()->getMethods()->call('sum1k');
}

$prof = xhprof_disable();

// --------------------------------------------------------------------------
// Aggregate exclusive cost per callee.
$exclusive = [];   // callee => [wt, cpu, mu, ct]
$inclusive_calls = [];  // callee => count
foreach ($prof as $key => $m) {
    [$caller, $callee] = explode('==>', $key, 2) + [null, null];
    if (!isset($exclusive[$callee])) {
        $exclusive[$callee] = ['wt' => 0, 'cpu' => 0, 'mu' => 0, 'ct' => 0];
    }
    $exclusive[$callee]['wt']  += $m['wt']  ?? 0;
    $exclusive[$callee]['cpu'] += $m['cpu'] ?? 0;
    $exclusive[$callee]['mu']  += $m['mu']  ?? 0;
    $exclusive[$callee]['ct']  += $m['ct']  ?? 0;
}

// xhprof reports inclusive timings per call edge; converting to "self
// time per function" requires subtracting the callees' inclusive cost.
// For first-pass attribution, the inclusive view is informative enough
// — we just sort by total wt and show top entries.
uasort($exclusive, fn($a, $b) => $b['wt'] <=> $a['wt']);

$total_wt = array_sum(array_column($exclusive, 'wt'));

echo "PHPJava sum1k profile (10 runs = ~80k JVM bytecode ops)\n";
echo str_repeat('=', 90) . "\n";
echo sprintf("%-58s %10s %10s %8s %5s\n", "function", "wt(us)", "calls", "us/call", "%");
echo str_repeat('-', 90) . "\n";

$shown = 0;
foreach ($exclusive as $fn => $m) {
    if ($shown++ >= 30) break;
    $pct = $total_wt > 0 ? ($m['wt'] / $total_wt * 100) : 0;
    $us_per_call = $m['ct'] > 0 ? ($m['wt'] / $m['ct']) : 0;
    echo sprintf(
        "%-58s %10d %10d %8.2f %4.1f%%\n",
        substr($fn, 0, 58),
        $m['wt'],
        $m['ct'],
        $us_per_call,
        $pct
    );
}

echo "\n";

// --------------------------------------------------------------------------
// Roll up by namespace top-level — Kernel\Mnemonics\* vs JVM\Invoker\*
// vs Packages\* vs Kernel\Core\* etc.
$buckets = [];
foreach ($exclusive as $fn => $m) {
    $bucket = preg_match('/^([A-Za-z_]+\\\\[A-Za-z_]+\\\\[A-Za-z_]+)/', $fn, $mm)
        ? $mm[1]
        : (str_contains($fn, '::') ? explode('::', $fn)[0] : $fn);
    if (!isset($buckets[$bucket])) {
        $buckets[$bucket] = ['wt' => 0, 'ct' => 0];
    }
    $buckets[$bucket]['wt'] += $m['wt'];
    $buckets[$bucket]['ct'] += $m['ct'];
}
uasort($buckets, fn($a, $b) => $b['wt'] <=> $a['wt']);

echo "Roll-up by namespace prefix\n";
echo str_repeat('=', 90) . "\n";
echo sprintf("%-58s %10s %10s %5s\n", "namespace", "wt(us)", "calls", "%");
echo str_repeat('-', 90) . "\n";
$rolled = 0;
foreach ($buckets as $bucket => $m) {
    if ($rolled++ >= 20) break;
    $pct = $total_wt > 0 ? ($m['wt'] / $total_wt * 100) : 0;
    echo sprintf(
        "%-58s %10d %10d %4.1f%%\n",
        substr($bucket, 0, 58),
        $m['wt'],
        $m['ct'],
        $pct
    );
}

echo "\nTotal wall time (sum of all functions): " . number_format($total_wt) . " us\n";
echo "Per-bytecode op estimate: " . number_format($total_wt / 80000, 2) . " us/op (approx)\n";

<?php
// AOT bench — measure the AOT-compiled PHP path. Counterpart to
// bench-cli.php (which measures the upstream interpreter).
//
// Usage:
//   php bench/bench-aot.php
//   php -d opcache.enable_cli=1 -d opcache.jit=tracing \
//       -d opcache.jit_buffer_size=1024M bench/bench-aot.php
//
// **Important: use a JIT buffer of at least 1024M.** This script loads
// the AOT compiler (PHPJava parser + Aot\Compiler) before benching;
// PHP's tracing JIT will speculatively trace the compiler code and
// compete with the bench loop for trace-cache space. With 256M buffer
// (the PHP default-ish setting), iadd-1k drops from 0.18 ns/op (real)
// to 1.6 ns/op (cache-thrashed). Empirically verified 2026-05-03.
//
// Reports both ns/iter and ns/op (per JVM bytecode) for each fixture
// so regressions vs the prior measured numbers (BenchAdd: 0.18 ns/op
// JIT post-stack-erasure, BenchInvoke: 0.4 ns/op post-inlining)
// surface immediately.
//
// Workload per iter:
//   BenchAdd::sum1k     — 1000-iter int loop, ~8 bytecodes/iter = 8000 ops
//   BenchInvoke::callLoop — 100-iter loop with invokestatic, ~10 bytecodes/iter
//                            + 100 invokestatic dispatches = ~1100 ops
//   HelloWorld::main      — string + System.out.println + sum-loop, ~40 ops

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Runtime/bootstrap.php';

use PHPJava\Aot\Compiler;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

// AOT-compile each fixture, write to disk, then require_once all in
// one batch. Empirical (probe-bisect.php): interleaving compile+write
// +require ($outDir/X.php is loaded before $outDir/Y.php is written)
// causes opcache to invalidate JIT traces because PHPJava's compiler
// machinery loads heavy classes between requires. Two-phase ordering
// keeps the JIT trace cache stable for the bench loop.
//
// Skip the write if content is identical: avoids mtime churn that
// would invalidate opcache between bench runs.
$compiler = new Compiler();
$outDir = __DIR__ . '/aot-out';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$fixtures = ['BenchAdd', 'BenchArray', 'BenchEmpty', 'BenchInvoke', 'HelloWorld'];
foreach ($fixtures as $cls) {
    $path = "{$outDir}/{$cls}.php";
    $emitted = $compiler->compileClass($cls);
    if (!is_file($path) || file_get_contents($path) !== $emitted) {
        file_put_contents($path, $emitted);
    }
}
foreach ($fixtures as $cls) {
    require_once "{$outDir}/{$cls}.php";
}

function bench_loop(string $label, int $iters, int $opsPerIter, callable $body): array {
    // Warm-up phase: PHP's tracing JIT needs ~hundreds of iterations
    // to fully optimise a hot path. One pre-iter (the prior shape) hit
    // cold-start at 5 ns/op when warm was 0.8. Run 200 warmup iters
    // before timing — if there's a startup gap, this absorbs it.
    for ($i = 0; $i < 200; $i++) $body();
    $start = microtime(true);
    for ($i = 0; $i < $iters; $i++) $body();
    $total_ns = (microtime(true) - $start) * 1e9;
    $ns_per_iter = $total_ns / $iters;
    return [
        'label'        => $label,
        'iters'        => $iters,
        'ops-per-iter' => $opsPerIter,
        'total-ms'     => $total_ns / 1e6,
        'ns-per-iter'  => $ns_per_iter,
        'ns-per-op'    => $ns_per_iter / $opsPerIter,
    ];
}

// IMPORTANT: closures must reference the AOT'd classes by *literal* FQN.
// PHP's tracing JIT specialises over literal class names; variable names
// (`$cls::method()`) defeat the trace. Earlier draft used variables and
// measured 4-5× slower than spike-fast-interp.php's compiler-emitted
// figure (rank-1 verified 2026-05-02).

$results = [
    'php-version'      => PHP_VERSION,
    'opcache-enabled'  => (bool) ini_get('opcache.enable_cli'),
    'opcache-jit'      => ini_get('opcache.jit'),
    'opcache-jit-buffer' => ini_get('opcache.jit_buffer_size'),

    // Pure method dispatch — return; one bytecode op.
    'empty-method' => bench_loop('empty-method', 100000, 1,
        fn() => \PHPJava\Aot\Generated\BenchEmpty::noop()),

    // 9006 bytecodes/call (matches spike's measurement); per-op cost
    // is the headline AOT number. 10k iters total to amortise PHP CLI
    // startup + opcache warmup; otherwise the first ~3 calls run
    // slower than the rest and pollute the per-iter mean.
    'iadd-1k' => bench_loop('iadd-1k', 10000, 9006,
        fn() => \PHPJava\Aot\Generated\BenchAdd::sum1k()),

    // 100 invokestatic + 100-iter int loop overhead. ~1100 ops.
    'invoke-100' => bench_loop('invoke-100', 10000, 1100,
        fn() => \PHPJava\Aot\Generated\BenchInvoke::callLoop()),

    // sumArray — int[10] init + 10-iter sum-loop = ~70 ops; tests
    // escape-analysis-aware array emit (raw PHP array, no wrapper).
    'array-loop' => bench_loop('array-loop', 10000, 70,
        fn() => \PHPJava\Aot\Generated\BenchArray::sumArray()),

    // HelloWorld via subprocess so we don't pollute output. Gross top-
    // line including print I/O — rough, not the headline number.
    // (skipped — measured separately by aot-helloworld.php)
];

if (in_array('--json', $argv ?? [], true)) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

echo str_pad('label', 22) . str_pad('total', 12) . str_pad('ns/iter', 14) . str_pad('ns/op', 12) . "\n";
echo str_repeat('-', 60), "\n";
foreach ($results as $k => $v) {
    if (!is_array($v)) {
        echo str_pad($k, 22) . $v . "\n";
        continue;
    }
    echo str_pad($k, 22)
        . str_pad(sprintf('%.2f ms', $v['total-ms']), 12)
        . str_pad(sprintf('%.0f ns', $v['ns-per-iter']), 14)
        . str_pad(sprintf('%.2f ns', $v['ns-per-op']), 12)
        . "\n";
}

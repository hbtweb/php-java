<?php
// Bench compile-with-cache vs cold-compile. Per the 2026-05-03 profile,
// parsed-JCC work is 83% of total compile time; caching gives up to
// 65× on repeat compiles of the same bytes.

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Compiler;

$bytes = file_get_contents(__DIR__ . '/fixtures/BenchAdd.class');

const N = 1000;
const W = 50;

function timeIt(string $label, callable $body, int $w, int $n): float {
    for ($i = 0; $i < $w; $i++) $body();
    $start = microtime(true);
    for ($i = 0; $i < $n; $i++) $body();
    $ns = (microtime(true) - $start) * 1e9 / $n;
    printf("%-30s %8.0f ns/op  (%.2f µs)\n", $label, $ns, $ns / 1000);
    return $ns;
}

echo "PHP " . PHP_VERSION . " | jit=" . (ini_get('opcache.jit') ?: 'off')
   . " | buffer=" . ini_get('opcache.jit_buffer_size') . "\n\n";

// Cold path: clear cache between calls so each goes through full parse+build+lower
$cold_ns = timeIt('compileBytes (cold, no cache)', function() use ($bytes) {
    Compiler::clearCompileCache();
    (new Compiler())->compileBytes('BenchAdd', $bytes);
}, W, N);

// Warm path: cache stays populated; repeat calls hit cache
Compiler::clearCompileCache();
(new Compiler())->compileBytes('BenchAdd', $bytes); // prime
$warm_ns = timeIt('compileBytes (cache hit)', function() use ($bytes) {
    (new Compiler())->compileBytes('BenchAdd', $bytes);
}, W, N);

$speedup = $cold_ns / $warm_ns;
printf("\nCache speedup: %.0fx\n", $speedup);
echo "Compile rate (cold): " . sprintf('%.0f', 1e9 / $cold_ns) . " classes/sec\n";
echo "Compile rate (warm): " . sprintf('%.0f', 1e9 / $warm_ns) . " classes/sec\n";
echo "\nFor Clojure boot (~600 classes per CLOJURE-BOOT-ANALYSIS.md):\n";
echo "  Cold compile:   " . sprintf('%.0f ms', 600 * $cold_ns / 1e6) . "\n";
echo "  Cache replay:   " . sprintf('%.0f ms', 600 * $warm_ns / 1e6) . "\n";

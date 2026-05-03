<?php
// Lean head-to-head: load ONLY the two emitted PHP files, no
// Builder/Lowerer/PHPJava-parser machinery. Tests whether the IR
// path's emit is genuinely as fast as the string path's, isolating
// from JIT-cache-pollution effects.

require_once __DIR__ . '/aot-out/BenchInvoke.php';     // string path
require_once '/tmp/BenchInvokeIRInlined.php';           // IR path

const N = 50000;
const W = 1000;

function lean(string $label, callable $body): float {
    for ($i = 0; $i < W; $i++) $body();
    $start = microtime(true);
    for ($i = 0; $i < N; $i++) $body();
    $ns_per = (microtime(true) - $start) * 1e9 / N;
    printf("%-30s %8.0f ns/call  %5.2f ns/op\n", $label, $ns_per, $ns_per / 1100);
    return $ns_per;
}

echo "PHP " . PHP_VERSION . " | opcache-jit=" . (ini_get('opcache.jit') ?: 'off')
    . " | buffer=" . ini_get('opcache.jit_buffer_size') . "\n\n";

echo "=== alternating, 5 rounds ===\n";
$str_total = 0; $ir_total = 0;
for ($r = 1; $r <= 5; $r++) {
    $str_total += lean("string-path #{$r}", fn() => \PHPJava\Aot\Generated\BenchInvoke::callLoop());
    $ir_total  += lean("ir-path #{$r}    ", fn() => \PHPJava\Aot\Ir\Generated\BenchInvokeIRInlined::callLoop());
}
$ratio = $ir_total / $str_total;
echo "\n  IR/string ratio: " . sprintf('%.2fx', $ratio) . "\n";
echo "  Verdict: " . ($ratio < 1.2 ? "PARITY" : ($ratio < 1.5 ? "ACCEPTABLE" : "DIVERGENT")) . "\n";

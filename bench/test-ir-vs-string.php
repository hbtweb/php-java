<?php
// Head-to-head: IR-path-emitted BenchAdd vs string-path-emitted BenchAdd,
// same harness, same PHP process. Identifies whether the slowdown
// is in the emitted code shape or in the bench harness around it.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/aot-out/BenchAdd.php';        // string path
require_once '/tmp/BenchAddIRBuilt.php';                // IR path (must run test-ir-bench.php first)

$N = 10000;
$W = 200;

function bench(string $label, callable $body, int $w, int $n): void {
    for ($i = 0; $i < $w; $i++) $body();
    $start = microtime(true);
    for ($i = 0; $i < $n; $i++) $body();
    $ns_per = (microtime(true) - $start) * 1e9 / $n;
    printf("%-20s %8.0f ns/call  %5.2f ns/op\n", $label, $ns_per, $ns_per / 9006);
}

echo "=== 5 runs each, alternating ===\n";
for ($r = 1; $r <= 5; $r++) {
    bench("string-path #{$r}", fn() => \PHPJava\Aot\Generated\BenchAdd::sum1k(), $W, $N);
    bench("ir-path #{$r}    ", fn() => \PHPJava\Aot\Ir\Generated\BenchAddIRBuilt::sum1k(), $W, $N);
}

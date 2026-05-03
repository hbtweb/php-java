<?php
// Verify BenchInvoke::callLoop() AOT-compiles to working PHP. The Java:
//
//   for (int i = 0; i < 100; i++) n = noop(n);   noop(x) { return x + 1; }
//
// expected result: callLoop() returns 100.
//
// Tests the invokestatic path — the simplest case (same-class self::).

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Compiler;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

$compiler = new Compiler();
$emitted = $compiler->compileClass('BenchInvoke');

$outDir = __DIR__ . '/aot-out';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$outPath = $outDir . '/BenchInvoke.php';
file_put_contents($outPath, $emitted);

echo "=== Emitted PHP for BenchInvoke ===\n";
echo $emitted;
echo "\n=== Result ===\n";

require_once $outPath;
$class = '\\PHPJava\\Aot\\Generated\\BenchInvoke';
$result = $class::callLoop();
echo "BenchInvoke::callLoop() = {$result}\n";
echo "Expected:                 100\n";
echo $result === 100 ? "PASS\n" : "FAIL\n";
exit($result === 100 ? 0 : 1);

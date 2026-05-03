<?php
// Functional test of LambdaMetafactory via BenchLambda.
// run() returns 42 (no-capture lambda).
// withCapture(7) returns 107 (lambda capturing `base`, returning base + 100).

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Compiler;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([[ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures']]);

$compiler = new Compiler();
$emitted = $compiler->compileClass('BenchLambda');

$outDir = __DIR__ . '/aot-out';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$outPath = $outDir . '/BenchLambda.php';
file_put_contents($outPath, $emitted);

echo "=== Emitted PHP for BenchLambda ===\n";
echo $emitted;
echo "\n=== Result ===\n";

require_once $outPath;
$cls = '\\PHPJava\\Aot\\Generated\\BenchLambda';
$noCapture = $cls::run();
$withCapture = $cls::withCapture(7);
echo "BenchLambda::run()         = {$noCapture}    (expected 42)\n";
echo "BenchLambda::withCapture(7) = {$withCapture}   (expected 107)\n";
$pass = $noCapture === 42 && $withCapture === 107;
echo $pass ? "PASS\n" : "FAIL\n";
exit($pass ? 0 : 1);

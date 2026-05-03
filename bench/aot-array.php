<?php
// Functional test of array opcodes via BenchArray::sumArray().
// Builds int[10] = {0..9}, sums them, returns 45.

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Compiler;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([[ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures']]);

$compiler = new Compiler();
$emitted = $compiler->compileClass('BenchArray');

$outDir = __DIR__ . '/aot-out';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$outPath = $outDir . '/BenchArray.php';
file_put_contents($outPath, $emitted);

echo "=== Emitted PHP for BenchArray ===\n";
echo $emitted;
echo "\n=== Result ===\n";

require_once $outPath;
$result = \PHPJava\Aot\Generated\BenchArray::sumArray();
echo "BenchArray::sumArray() = {$result}\n";
echo "Expected:                45\n";
echo $result === 45 ? "PASS\n" : "FAIL\n";
exit($result === 45 ? 0 : 1);

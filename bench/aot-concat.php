<?php
// Functional test of INVOKEDYNAMIC + StringConcatFactory.makeConcatWithConstants.
// Java 9+ string concat compiles to invokedynamic; the AOT compiler
// recognises the bootstrap method and emits direct PHP string concat.

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Compiler;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([[ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures']]);

$compiler = new Compiler();
$emitted = $compiler->compileClass('BenchConcat');

$outDir = __DIR__ . '/aot-out';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$outPath = $outDir . '/BenchConcat.php';
file_put_contents($outPath, $emitted);

echo "=== Emitted PHP for BenchConcat ===\n";
echo $emitted;
echo "\n=== Result ===\n";

require_once $outPath;
$result = \PHPJava\Aot\Generated\BenchConcat::greet('alice', 5);
$expected = 'hello alice! count=5';
echo "BenchConcat::greet('alice', 5) = " . var_export($result, true) . "\n";
echo "Expected:                       " . var_export($expected, true) . "\n";
echo $result === $expected ? "PASS\n" : "FAIL\n";
exit($result === $expected ? 0 : 1);

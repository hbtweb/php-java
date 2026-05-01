<?php
// Run the real AOT compiler on BenchAdd.class — validates that the
// architectural claim ("interpreter and AOT are the same compiler with
// different consumers, walking PHPJava's parsed bytecode") holds in code,
// not just in conversation.
//
// Steps:
//   1. PHPJava parses BenchAdd.class as it normally would
//   2. Aot\Compiler walks the parsed structure and emits PHP
//   3. We require_once the emitted PHP and call sum1k()
//   4. Verify the result == 499500 (matches HotSpot, matches interpreter)
//   5. Print the result and the emitted PHP for inspection

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Compiler;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

$compiler = new Compiler();
$emitted = $compiler->compileClass('BenchAdd');

$outDir = __DIR__ . '/aot-out';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$outPath = $outDir . '/BenchAdd.php';
file_put_contents($outPath, $emitted);

echo "=== Emitted PHP for BenchAdd ===\n";
echo $emitted;
echo "\n=== Result ===\n";

require_once $outPath;
$class = '\\PHPJava\\Aot\\Generated\\BenchAdd';
$result = $class::sum1k();
echo "BenchAdd::sum1k() = {$result}\n";
echo "Expected:           499500\n";
echo $result === 499500 ? "PASS\n" : "FAIL\n";

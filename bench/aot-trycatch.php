<?php
// Functional test of exception tables via BenchTryCatch::run().
// Throws RuntimeException, catches it, returns 42.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Runtime/bootstrap.php';

use PHPJava\Aot\Compiler;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([[ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures']]);

$compiler = new Compiler();
$emitted = $compiler->compileClass('BenchTryCatch');

$outDir = __DIR__ . '/aot-out';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$outPath = $outDir . '/BenchTryCatch.php';
file_put_contents($outPath, $emitted);

echo "=== Emitted PHP for BenchTryCatch ===\n";
echo $emitted;
echo "\n=== Result ===\n";

require_once $outPath;
$result = \PHPJava\Aot\Generated\BenchTryCatch::run();
echo "BenchTryCatch::run() = {$result}\n";
echo "Expected:              42\n";
echo $result === 42 ? "PASS\n" : "FAIL\n";
exit($result === 42 ? 0 : 1);

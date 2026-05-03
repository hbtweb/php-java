<?php
// Functional test of `Compiler::compileBytes()` — the defineClass(byte[])
// path. Reads BenchAdd.class as raw bytes from disk (rather than via
// the ClassResolver), passes the bytes directly to the AOT compiler,
// runs the result.
//
// This is the path for runtime class synthesis: custom ClassLoader,
// CGLIB-style proxies, Clojure's anonymous functions, mocking frameworks.

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Compiler;

$classBytes = file_get_contents(__DIR__ . '/fixtures/BenchAdd.class');
echo "Loaded ", strlen($classBytes), " bytes from BenchAdd.class\n";

$compiler = new Compiler();
$emitted = $compiler->compileBytes('BenchAddFromBytes', $classBytes);

$outDir = __DIR__ . '/aot-out';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$outPath = $outDir . '/BenchAddFromBytes.php';
file_put_contents($outPath, $emitted);

echo "=== Emitted PHP ===\n";
echo $emitted;
echo "\n=== Result ===\n";

require_once $outPath;
$class = '\\PHPJava\\Aot\\Generated\\BenchAddFromBytes';
$result = $class::sum1k();
echo "BenchAddFromBytes::sum1k() = {$result}\n";
echo "Expected:                    499500\n";
echo $result === 499500 ? "PASS\n" : "FAIL\n";
exit($result === 499500 ? 0 : 1);

<?php
// Bench compile-time of the IR Builder and Lowerer. If compilation
// happens at runtime (defineClass(byte[]), hot-recompilation,
// Truffle-shape partial eval), this is hot-path perf, not build-time.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Ir/Node.php';
require_once __DIR__ . '/../src/Aot/Ir/Lowerer.php';
require_once __DIR__ . '/../src/Aot/Ir/Builder.php';
require_once __DIR__ . '/../src/Aot/Ir/ArrayHelper.php';

use PHPJava\Aot\Ir\Builder;
use PHPJava\Aot\Ir\Lowerer;
use PHPJava\Aot\Ir\Module;
use PHPJava\Core\JavaCompiledClass;
use PHPJava\Core\Stream\Reader\InlineReader;
use PHPJava\Kernel\Attributes\CodeAttribute;
use PHPJava\Kernel\Resolvers\AttributionResolver;

// Pre-load class bytes (don't bench file I/O).
$bytes = file_get_contents(__DIR__ . '/fixtures/BenchAdd.class');

const N = 1000;
const W = 100;

// ── Just JCC parse (PHPJava's existing parser) ──────────────────
function timeJccParse(string $bytes): float {
    for ($i = 0; $i < W; $i++) new JavaCompiledClass(new InlineReader('B', $bytes));
    $start = microtime(true);
    for ($i = 0; $i < N; $i++) new JavaCompiledClass(new InlineReader('B', $bytes));
    return (microtime(true) - $start) * 1e9 / N;
}

// ── JCC parse + IR build + Lower ────────────────────────────────
function timeFullCompile(string $bytes): float {
    $do = function() use ($bytes) {
        $jcc = new JavaCompiledClass(new InlineReader('B', $bytes));
        $builder = new Builder();
        $methods = [];
        foreach ($jcc->getDefinedMethods() as $m) {
            $name = $jcc->getConstantPool()->getEntries()[$m->getNameIndex()]->getString();
            if ($name === '<init>') continue;
            $desc = $jcc->getConstantPool()->getEntries()[$m->getDescriptorIndex()]->getString();
            $codeAttr = AttributionResolver::resolve($m->getAttributes(), CodeAttribute::class);
            $methods[] = $builder->buildMethod($jcc, $name, $desc, $codeAttr->getCode(), 'BenchAdd', $codeAttr->getExceptionTables());
        }
        $module = new Module('Demo', 'Demo', $methods);
        return (new Lowerer())->lowerModule($module);
    };
    for ($i = 0; $i < W; $i++) $do();
    $start = microtime(true);
    for ($i = 0; $i < N; $i++) $do();
    return (microtime(true) - $start) * 1e9 / N;
}

// ── Just Build+Lower (caller pre-parsed JCC) ────────────────────
function timeBuildLower(JavaCompiledClass $jcc, string $bytes): float {
    $methodInfo = null;
    foreach ($jcc->getDefinedMethods() as $m) {
        $name = $jcc->getConstantPool()->getEntries()[$m->getNameIndex()]->getString();
        if ($name === 'sum1k') {
            $methodInfo = ['m' => $m, 'name' => $name];
            break;
        }
    }
    $desc = $jcc->getConstantPool()->getEntries()[$methodInfo['m']->getDescriptorIndex()]->getString();
    $codeAttr = AttributionResolver::resolve($methodInfo['m']->getAttributes(), CodeAttribute::class);
    $code = $codeAttr->getCode();
    $exTables = $codeAttr->getExceptionTables();

    $do = function() use ($jcc, $methodInfo, $desc, $code, $exTables) {
        $builder = new Builder();
        $method = $builder->buildMethod($jcc, $methodInfo['name'], $desc, $code, 'BenchAdd', $exTables);
        $module = new Module('Demo', 'Demo', [$method]);
        return (new Lowerer())->lowerModule($module);
    };
    for ($i = 0; $i < W; $i++) $do();
    $start = microtime(true);
    for ($i = 0; $i < N; $i++) $do();
    return (microtime(true) - $start) * 1e9 / N;
}

echo "PHP " . PHP_VERSION . " | jit=" . (ini_get('opcache.jit') ?: 'off')
   . " | buffer=" . ini_get('opcache.jit_buffer_size') . "\n\n";

$jcc_ns = timeJccParse($bytes);
echo "JCC parse only:         " . sprintf('%8.0f ns/op  (%.0f µs)', $jcc_ns, $jcc_ns / 1000) . "\n";

$jcc = new JavaCompiledClass(new InlineReader('B', $bytes));
$bl_ns = timeBuildLower($jcc, $bytes);
echo "IR build + lower:       " . sprintf('%8.0f ns/op  (%.0f µs)', $bl_ns, $bl_ns / 1000) . "\n";

$full_ns = timeFullCompile($bytes);
echo "Full compile:           " . sprintf('%8.0f ns/op  (%.0f µs)', $full_ns, $full_ns / 1000) . "\n";

echo "\n";
echo "What this means at scale:\n";
$per_class_us = $full_ns / 1000;
$classes_per_sec = 1e9 / $full_ns;
echo "  Compile rate:         " . sprintf('%.0f', $classes_per_sec) . " classes/sec\n";
echo "  Time for 1000 classes: " . sprintf('%.1f', $per_class_us) . " µs/class × 1000 = " . sprintf('%.1f ms', $per_class_us) . "\n";
echo "  Time for Clojure boot (~600 classes per CLOJURE-BOOT-ANALYSIS.md): "
   . sprintf('%.0f ms', 600 * $per_class_us / 1000) . "\n";

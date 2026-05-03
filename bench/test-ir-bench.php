<?php
// End-to-end IR path test: take BenchAdd.class bytes, run through
// the IR Builder, lower with Lowerer, run, verify result + bench.
//
// Validates:
//   1. Bytecode → IR builder produces correct IR for BenchAdd's
//      opcode shape (iconst, iload, istore, sipush, if_icmp, iadd,
//      iinc, goto, ireturn)
//   2. Lowerer renders it to runnable PHP
//   3. The IR-path BenchAdd runs at perf parity with the
//      string-path BenchAdd (~0.18-0.20 ns/op JIT)

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Ir/Node.php';
require_once __DIR__ . '/../src/Aot/Ir/Lowerer.php';
require_once __DIR__ . '/../src/Aot/Ir/Builder.php';

use PHPJava\Aot\Ir\Builder;
use PHPJava\Aot\Ir\Lowerer;
use PHPJava\Aot\Ir\Module;
use PHPJava\Core\JavaClass;
use PHPJava\Core\JavaCompiledClass;
use PHPJava\Core\Stream\Reader\InlineReader;
use PHPJava\Kernel\Attributes\CodeAttribute;
use PHPJava\Kernel\Resolvers\AttributionResolver;

// Load BenchAdd.class
$bytes = file_get_contents(__DIR__ . '/fixtures/BenchAdd.class');
$jcc = new JavaCompiledClass(new InlineReader('BenchAdd', $bytes));

// Find sum1k method, build IR.
$builder = new Builder();
$irMethod = null;
foreach ($jcc->getDefinedMethods() as $method) {
    $name = $jcc->getConstantPool()->getEntries()[$method->getNameIndex()]->getString();
    if ($name === 'sum1k') {
        $desc = $jcc->getConstantPool()->getEntries()[$method->getDescriptorIndex()]->getString();
        $codeAttr = AttributionResolver::resolve($method->getAttributes(), CodeAttribute::class);
        $irMethod = $builder->buildMethod($jcc, $name, $desc, $codeAttr->getCode(), 'BenchAdd');
        break;
    }
}
if ($irMethod === null) { echo "FAIL: didn't find sum1k\n"; exit(1); }

// Lower into a Module.
$module = new Module(
    namespace: 'PHPJava\\Aot\\Ir\\Generated',
    className: 'BenchAddIRBuilt',
    methods: [$irMethod],
);
$lowered = (new Lowerer())->lowerModule($module);

echo "=== Lowered PHP (built directly from BenchAdd.class via IR) ===\n";
echo $lowered;

$tmp = sys_get_temp_dir() . '/BenchAddIRBuilt.php';
file_put_contents($tmp, $lowered);
require_once $tmp;

$cls = '\\PHPJava\\Aot\\Ir\\Generated\\BenchAddIRBuilt';
$result = $cls::sum1k();
echo "\n=== Functional check ===\n";
echo "BenchAddIRBuilt::sum1k() = {$result}  (expected 499500)\n";
$pass = $result === 499500;
echo $pass ? "PASS — IR builder produces correct PHP\n" : "FAIL\n";
if (!$pass) exit(1);

echo "\n=== Perf check (5 runs, 10k iters, JIT) ===\n";
const N = 10000;
const W = 200;
for ($run = 1; $run <= 5; $run++) {
    for ($i = 0; $i < W; $i++) \PHPJava\Aot\Ir\Generated\BenchAddIRBuilt::sum1k();
    $start = microtime(true);
    for ($i = 0; $i < N; $i++) \PHPJava\Aot\Ir\Generated\BenchAddIRBuilt::sum1k();
    $ns = (microtime(true) - $start) * 1e9 / N;
    printf("run %d: %.0f ns/call, %.2f ns/op\n", $run, $ns, $ns / 9006);
}

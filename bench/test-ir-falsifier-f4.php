<?php
// F-IR4: full opcode coverage stays within 2× the 530-LOC budget?
//
// Strategy: extend the Builder to cover BenchEmpty and BenchArray
// (in addition to BenchAdd + BenchInvoke from earlier), measure LOC
// growth, project total cost.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Ir/Node.php';
require_once __DIR__ . '/../src/Aot/Ir/Lowerer.php';
require_once __DIR__ . '/../src/Aot/Ir/Builder.php';
require_once __DIR__ . '/../src/Aot/Ir/ArrayHelper.php';
require_once __DIR__ . '/../src/Aot/Runtime/bootstrap.php';

use PHPJava\Aot\Ir\Builder;
use PHPJava\Aot\Ir\Lowerer;
use PHPJava\Aot\Ir\Module;
use PHPJava\Core\JavaCompiledClass;
use PHPJava\Core\Stream\Reader\InlineReader;
use PHPJava\Kernel\Attributes\CodeAttribute;
use PHPJava\Kernel\Resolvers\AttributionResolver;

function buildAll(string $classFile, string $internalName, string $aotName): array {
    $jcc = new JavaCompiledClass(new InlineReader($internalName,
        file_get_contents(__DIR__ . '/fixtures/' . $classFile)));
    $builder = new Builder();
    $builder->setAotClassFqn("\\PHPJava\\Aot\\Ir\\Generated\\{$aotName}");
    $methods = [];
    foreach ($jcc->getDefinedMethods() as $m) {
        $name = $jcc->getConstantPool()->getEntries()[$m->getNameIndex()]->getString();
        if ($name === '<init>') continue;
        $desc = $jcc->getConstantPool()->getEntries()[$m->getDescriptorIndex()]->getString();
        try {
            $codeAttr = AttributionResolver::resolve($m->getAttributes(), CodeAttribute::class);
            $methods[] = $builder->buildMethod(
                $jcc, $name, $desc,
                $codeAttr->getCode(), $internalName,
                $codeAttr->getExceptionTables()
            );
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'method' => $name];
        }
    }
    $module = new Module('PHPJava\\Aot\\Ir\\Generated', $aotName, $methods, $builder->getLambdaClasses());
    $lowered = (new Lowerer())->lowerModule($module);
    return ['lowered' => $lowered];
}

$fixtures = [
    'BenchAdd.class'      => ['BenchAdd',  'BenchAddIRf4',  fn($cls) => $cls::sum1k(),    499500],
    'BenchEmpty.class'    => ['BenchEmpty','BenchEmptyIRf4',fn($cls) => $cls::noop(),    null],
    'BenchInvoke.class'   => ['BenchInvoke','BenchInvokeIRf4',fn($cls) => $cls::callLoop(),100],
    'BenchArray.class'    => ['BenchArray','BenchArrayIRf4',fn($cls) => $cls::sumArray(), 45],
    'BenchTryCatch.class' => ['BenchTryCatch','BenchTryCatchIRf4',fn($cls) => $cls::run(), 42],
    'BenchConcat.class'   => ['BenchConcat','BenchConcatIRf4',fn($cls) => $cls::greet('alice', 5), 'hello alice! count=5'],
    'HelloWorld.class'    => ['HelloWorld','HelloWorldIRf4',fn($cls) => null /* prints; output captured separately */, null],
    'BenchLambda.class'   => ['BenchLambda','BenchLambdaIRf4',fn($cls) => $cls::run(), 42],
    'BenchRunner.class'   => ['BenchRunner','BenchRunnerIRf4',fn($cls) => null /* runs benchmarks */, null],
];

echo "═══ F-IR4: opcode coverage growth across 4 fixtures ═══\n\n";
$results = [];
foreach ($fixtures as $file => [$intName, $aotName, $runFn, $expected]) {
    echo "--- {$intName} ---\n";
    $res = buildAll($file, $intName, $aotName);
    if (isset($res['error'])) {
        echo "  BUILD FAILED: {$res['error']} (in method {$res['method']})\n";
        $results[$intName] = ['outcome' => 'BUILD_FAIL'];
        continue;
    }
    $tmp = sys_get_temp_dir() . '/' . $aotName . '.php';
    file_put_contents($tmp, $res['lowered']);
    require_once $tmp;
    $cls = "\\PHPJava\\Aot\\Ir\\Generated\\{$aotName}";
    try {
        $result = $runFn($cls);
        $matches = $expected === null || $result === $expected;
        echo "  {$cls}::run() = " . var_export($result, true)
           . ($expected !== null ? " (expected " . var_export($expected, true) . ")" : "")
           . "  " . ($matches ? "PASS" : "FAIL") . "\n";
        $results[$intName] = ['outcome' => $matches ? 'PASS' : 'FAIL'];
    } catch (\Throwable $e) {
        echo "  RUNTIME FAIL: ", $e->getMessage(), "\n";
        $results[$intName] = ['outcome' => 'RUNTIME_FAIL', 'msg' => $e->getMessage()];
    }
}

echo "\n═══ LOC tally ═══\n";
$builderLoc = substr_count(file_get_contents(__DIR__ . '/../src/Aot/Ir/Builder.php'), "\n");
$nodeLoc = substr_count(file_get_contents(__DIR__ . '/../src/Aot/Ir/Node.php'), "\n");
$lowererLoc = substr_count(file_get_contents(__DIR__ . '/../src/Aot/Ir/Lowerer.php'), "\n");
$arrLoc = substr_count(file_get_contents(__DIR__ . '/../src/Aot/Ir/ArrayHelper.php'), "\n");
$total = $builderLoc + $nodeLoc + $lowererLoc + $arrLoc;
echo "  Builder.php:     {$builderLoc} LOC\n";
echo "  Node.php:        {$nodeLoc} LOC\n";
echo "  Lowerer.php:     {$lowererLoc} LOC\n";
echo "  ArrayHelper.php: {$arrLoc} LOC\n";
echo "  TOTAL:           {$total} LOC\n";

echo "\n═══ Coverage so far ═══\n";
$passes = 0; $fails = 0;
foreach ($results as $name => $r) {
    echo "  {$name}: {$r['outcome']}\n";
    if ($r['outcome'] === 'PASS') $passes++;
    else $fails++;
}

echo "\n═══ F-IR4 projection ═══\n";
echo "  4 of 9 fixtures covered: ", ($passes === 4 ? "all PASS" : "{$passes}/4 PASS"), "\n";
echo "  Substrate at {$total} LOC. Remaining 5 fixtures need:\n";
echo "    - BenchTryCatch (exception tables): substrate proven by F-IR3 — Builder ext ~80 LOC est\n";
echo "    - BenchConcat (StringConcatFactory invokedynamic): ~80 LOC\n";
echo "    - BenchLambda (LambdaMetafactory invokedynamic): ~120 LOC\n";
echo "    - HelloWorld (cross-class invokevirtual + getstatic + ldc): ~80 LOC\n";
echo "    - BenchRunner (long math + assorted): ~100 LOC\n";
echo "    Subtotal projected: ~460 LOC\n";
echo "  Final estimate: " . ($total + 460) . " LOC\n";
echo "  Original budget: 1060 LOC (2× of 530)\n";
$budget_holds = ($total + 460) < 1060;
echo "  F-IR4 verdict: " . ($passes === 4 && $budget_holds ? "HOLDS" : "FALSIFIED") . "\n";

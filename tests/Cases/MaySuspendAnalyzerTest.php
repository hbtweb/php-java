<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Compiler;
use PHPJava\Aot\Ir\Analysis\MaySuspendAnalyzer;
use PHPJava\Aot\Ir\Module;
use PHPJava\Core\JavaCompiledClass;
use PHPJava\Core\Stream\Reader\InlineReader;

/**
 * Validates the MaySuspendAnalyzer IR pass that's the load-bearing
 * piece of ROADMAP §Build T3's "compile-time async-emit specialiser"
 * variant. Per the bench-amphp-probe measurement series:
 *
 *   - direct call:      9 ns/op
 *   - PoC v2 runtime: 365 ns/op (39× over direct)
 *   - AMPHP:         2031 ns/op (220× over direct)
 *
 * The 39× gap is what compile-time may-suspend analysis unlocks: when
 * a Future-returning lambda is provably non-suspending AND the await
 * is immediate, the compiler emits a direct call instead of routing
 * through async/await machinery. This test validates the analyzer
 * correctly classifies methods on real AOT-compiled bytecode.
 */
class MaySuspendAnalyzerTest extends Base
{
    protected $fixtures = [
        'MaySuspendFixture',
    ];

    /** Build the IR Module for the fixture so we can ask the analyzer. */
    private function moduleFor(string $name): Module
    {
        $bytes = \file_get_contents(__DIR__ . '/caches/' . $name . '.class');
        $this->assertNotFalse($bytes);
        $jcc = new JavaCompiledClass(new InlineReader($name, $bytes));

        // Use Compiler's tryBuildIrMethod via reflection so we exercise
        // the production IR-build path. The production path is
        // private-and-internal; mock-driving it in tests is the lightest
        // way to exercise the analyzer against real bytecode without
        // shipping a public IR-building API just for tests.
        $compiler = new Compiler();
        $rc = new \ReflectionClass($compiler);

        $method = $rc->getMethod('compileFromGenericClass');
        $method->setAccessible(true);
        // Drive the AOT compile so the irBuilder field is populated;
        // we'll then read back the methods from it.
        $method->invoke($compiler, $jcc, $name);

        $irBuilder = $rc->getProperty('irBuilder');
        $irBuilder->setAccessible(true);
        $builder = $irBuilder->getValue($compiler);
        $this->assertNotNull($builder, 'irBuilder should be initialised after compile');

        // Re-build per method: tryBuildIrMethod is what produces the
        // Method IR; we'll iterate methods and re-run for each, then
        // assemble a Module.
        $tryBuild = $rc->getMethod('tryBuildIrMethod');
        $tryBuild->setAccessible(true);
        $module = new Module(
            namespace: 'PHPJava\\Aot\\Generated',
            className: $name,
        );
        foreach ($jcc->getDefinedMethods() as $m) {
            $codeAttr = null;
            foreach ($m->getAttributes() as $a) {
                $data = $a->getAttributeData();
                if ($data instanceof \PHPJava\Kernel\Attributes\CodeAttribute) {
                    $codeAttr = $data;
                    break;
                }
            }
            if ($codeAttr === null) continue;
            $isStatic = ($m->getAccessFlag() & \PHPJava\Kernel\Maps\MethodAccessFlag::ACC_STATIC) !== 0;
            $methodName = '';
            $descriptor = '';
            $cp = $jcc->getConstantPool();
            $methodName = $cp[$m->getNameIndex()]->getString();
            $descriptor = $cp[$m->getDescriptorIndex()]->getString();
            $irMethod = $tryBuild->invoke(
                $compiler, $jcc, $name, $methodName, $descriptor,
                $codeAttr->getCode(), $codeAttr->getExceptionTables(),
                $isStatic
            );
            if ($irMethod !== null) {
                $module->methods[] = $irMethod;
            }
        }
        return $module;
    }

    public function testPureMethodIsNonSuspending(): void
    {
        $module = $this->moduleFor('MaySuspendFixture');
        $analyzer = new MaySuspendAnalyzer();
        $verdicts = $analyzer->analyseModule($module);

        // pure(int,int)int — no calls, just arithmetic.
        $key = $this->findKey($verdicts, 'pure');
        $this->assertNotNull($key, 'pure method should be in IR');
        $this->assertFalse($verdicts[$key], 'pure() must classify as non-suspending');
    }

    public function testBranchyComputeIsNonSuspending(): void
    {
        $module = $this->moduleFor('MaySuspendFixture');
        $analyzer = new MaySuspendAnalyzer();
        $verdicts = $analyzer->analyseModule($module);

        $key = $this->findKey($verdicts, 'branchy');
        $this->assertNotNull($key);
        $this->assertFalse($verdicts[$key], 'branchy() arithmetic+branches must classify as non-suspending');
    }

    public function testThreadSleepIsSuspending(): void
    {
        $module = $this->moduleFor('MaySuspendFixture');
        $analyzer = new MaySuspendAnalyzer();
        $verdicts = $analyzer->analyseModule($module);

        $key = $this->findKey($verdicts, 'suspendingSleep');
        $this->assertNotNull($key);
        $this->assertTrue($verdicts[$key], 'suspendingSleep() calls Thread.sleep — must classify as suspending');
    }

    public function testTransitiveCallIsConservativeSuspending(): void
    {
        $module = $this->moduleFor('MaySuspendFixture');
        $analyzer = new MaySuspendAnalyzer();
        $verdicts = $analyzer->analyseModule($module);

        // callsSuspending() calls suspendingSleep() which calls Thread.sleep.
        // Phase 1 analyzer is single-method; the receiver-type-inference
        // for in-class invokestatic IS resolvable, so the analyzer treats
        // the call to `self::suspendingSleep` as an unknown user call →
        // conservative-true. Phase 2 with transitive call-graph lifts
        // this to precise-true.
        $key = $this->findKey($verdicts, 'callsSuspending');
        $this->assertNotNull($key);
        $this->assertTrue(
            $verdicts[$key],
            'callsSuspending() — transitive: even Phase 1 conservative path returns true'
        );
    }

    /** @param array<string,bool> $verdicts */
    private function findKey(array $verdicts, string $methodNamePrefix): ?string
    {
        foreach (\array_keys($verdicts) as $k) {
            if (\str_starts_with($k, $methodNamePrefix)) {
                return $k;
            }
        }
        return null;
    }
}

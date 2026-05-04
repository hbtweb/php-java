<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

class OutputDebugTraceTest extends Base
{
    protected $fixtures = [
        'OutputDebugTraceTest',
    ];

    public function testCallMain()
    {
        // Exercises the legacy interp's bytecode-trace debug output
        // (->debug() dumps PC/opcode/mnemonic/operands/locals as the
        // interpreter steps through the bytecode). No AOT analog —
        // AOT-emitted PHP runs as native PHP, not as a stepped JVM
        // interpretation. Slated for removal in Phase D (interpreter
        // delete) per docs/LAYERS.md.
        $this->markTestSkipped('Interp-only debug trace; no AOT analog (Phase D delete)');
        $calculatedValue = static::$initiatedJavaClasses['OutputDebugTraceTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call(
                'main',
                ['Hello', ' ', 'World']
            );

        ob_start();
        static::$initiatedJavaClasses['OutputDebugTraceTest']->debug();
        $result = ob_get_clean();
        $this->assertEquals(
            file_get_contents(__DIR__ . '/templates/DebugTraceTest.txt'),
            $result
        );
    }
}

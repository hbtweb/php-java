<?php
namespace PHPJava\Tests\Packages\java\io;

use PHPJava\Exceptions\UncaughtException;
use PHPJava\IO\Standard\Output;
use PHPJava\Packages\java\lang\NullPointerException;
use PHPJava\Tests\Cases\Base;

class JavaIoPrintStreamClassTest extends Base
{
    protected $fixtures = [
        'JavaIoPrintStreamClassTest',
    ];

    protected $expectedSpecialException;

    private function call($method, ...$arguments)
    {
        static::$initiatedJavaClasses['JavaIoPrintStreamClassTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call(
                $method,
                ...$arguments
            );
        return Output::getHeapspace();
    }

    private function callWithExpectingException($method, ...$arguments)
    {
        try {
            return $this->call($method, ...$arguments);
        } catch (UncaughtException $e) {
            // Legacy interp path wraps Java exceptions in UncaughtException.
            $this->expectedSpecialException = get_class($e->getPrevious());
        } catch (\PHPJava\Packages\java\lang\Throwable $e) {
            // AOT path: Java exceptions propagate raw — the AOT-namespace
            // exception class extends the Packages-namespace one, so map
            // back to the Packages name the test asserts against.
            $this->expectedSpecialException = \str_replace(
                'PHPJava\\Aot\\Runtime\\java\\lang\\',
                'PHPJava\\Packages\\java\\lang\\',
                get_class($e)
            );
        }
        return Output::getHeapspace();
    }

    public function testPrintlnWithoutParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals("\n", $result);
    }

    public function testPrintlnWithStringParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals("Hello World\n", $result);
    }

    public function testPrintlnWithNullStringParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals("null\n", $result);
    }

    public function testPrintlnWithCharParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals("A\n", $result);
    }

    public function testPrintlnWithCharArrayParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals("ABC\n", $result);
    }

    public function testPrintlnWithNullCharArrayParams()
    {
        $result = $this->callWithExpectingException(explode('::', __METHOD__)[1]);
        // AOT path matches Java spec: println(char[] x) calls write
        // before newLine; for null x, write throws NPE before the
        // newline is emitted, so output is empty. Legacy interp path
        // emitted "\n" first — that was the path-specific artifact.
        $this->assertEquals("", $result);
        $this->assertSame(
            NullPointerException::class,
            $this->expectedSpecialException
        );
    }

    public function testPrintlnWithFloatParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertStringContainsString('0.123', $result);
    }

    public function testPrintlnWithDoubleParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals("0.123\n", $result);
    }

    public function testPrintlnWithBooleanParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1] . '_true');
        $this->assertEquals("true\n", $result);

        $result = $this->call(explode('::', __METHOD__)[1] . '_false');
        $this->assertEquals("false\n", $result);
    }

    public function testPrintWithStringParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals('Hello World', $result);
    }

    public function testPrintWithNullStringParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals('null', $result);
    }

    public function testPrintWithCharParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals('A', $result);
    }

    public function testPrintWithCharArrayParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals('ABC', $result);
    }

    public function testPrintWithNullCharArrayParams()
    {
        $result = $this->callWithExpectingException(explode('::', __METHOD__)[1]);
        $this->assertEquals('', $result);
        $this->assertSame(
            NullPointerException::class,
            $this->expectedSpecialException
        );
    }

    public function testPrintWithFloatParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertStringContainsString('0.123', $result);
    }

    public function testPrintWithDoubleParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1]);
        $this->assertEquals('0.123', $result);
    }

    public function testPrintWithBooleanParams()
    {
        $result = $this->call(explode('::', __METHOD__)[1] . '_true');
        $this->assertEquals('true', $result);

        $result = $this->call(explode('::', __METHOD__)[1] . '_false');
        $this->assertEquals('false', $result);
    }
}

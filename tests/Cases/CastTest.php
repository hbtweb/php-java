<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Kernel\Types\Double_;
use PHPJava\Kernel\Types\Float_;
use PHPJava\Kernel\Types\Int_;
use PHPJava\Kernel\Types\Long_;

class CastTest extends Base
{
    protected $fixtures = [
        'CastTest',
    ];

    /**
     * After CONTRACTS.md §1 + the #12 wrapper-removal slices, method
     * return values flow as raw PHP scalars (int / float / string /
     * object). Tests assert on the value, not the wrapper instance.
     * The narrowing semantics (i2b, i2c, i2s) are exercised at the
     * bit-pattern level: e.g. `(char) 123` returns the int 123, which
     * is the codepoint of '{' — char-rendering is the caller's job.
     */
    public function testIntToShort()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testIntToShort', new Int_(1234));

        $this->assertSame(1234, $result);
    }

    public function testIntToDouble()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testIntToDouble', new Int_(1234));

        $this->assertSame(1234.0, $result);
    }

    public function testIntToFloat()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testIntToFloat', new Int_(1234));

        $this->assertSame(1234.0, $result);
    }

    public function testIntToByte()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testIntToByte', new Int_(123));

        $this->assertSame(123, $result);
    }

    public function testIntToChar()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testIntToChar', new Int_(123));

        // 123 = codepoint of '{'; char-rendering is the caller's job
        $this->assertSame(123, $result);
    }

    public function testLongToDouble()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testLongToDouble', new Long_(1234));

        $this->assertSame(1234.0, $result);
    }

    public function testLongToFloat()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testLongToFloat', new Long_(1234));

        $this->assertSame(1234.0, $result);
    }

    public function testLongToInt()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testLongToInt', new Long_(1234));

        $this->assertSame(1234, $result);
    }

    public function testDoubleToFloat()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testDoubleToFloat', new Double_(1234));

        $this->assertSame(1234.0, $result);
    }

    public function testDoubleToInt()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testDoubleToInt', new Double_(1234));

        $this->assertSame(1234, $result);
    }

    public function testDoubleToLong()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testDoubleToLong', new Double_(1234));

        $this->assertSame(1234, $result);
    }

    public function testFloatToDouble()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testFloatToDouble', new Float_(1234));

        $this->assertSame(1234.0, $result);
    }

    public function testFloatToInt()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testFloatToInt', new Float_(1234));

        $this->assertSame(1234, $result);
    }

    public function testFloatToLong()
    {
        $result = static::$initiatedJavaClasses['CastTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call('testFloatToLong', new Float_(1234));

        $this->assertSame(1234, $result);
    }
}

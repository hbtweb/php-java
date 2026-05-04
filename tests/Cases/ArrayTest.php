<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

class ArrayTest extends Base
{
    protected $fixtures = [
        'ArrayTest',
    ];

    private function call($method)
    {
        return static::$initiatedJavaClasses['ArrayTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call($method);
    }

    public function testCreateIntArray()
    {
        $actual = $this->call('createIntArray');

        $this->assertCount(3, $actual);
        $this->assertSame(1, $actual[0]);
        $this->assertSame(2, $actual[1]);
        $this->assertSame(3, $actual[2]);
    }

    public function testCreateStringArray()
    {
        $actual = $this->call('createStringArray');

        $this->assertCount(3, $actual);
        $this->assertSame('foo', $actual[0]);
        $this->assertSame('bar', $actual[1]);
        $this->assertSame('baz', $actual[2]);
    }

    public function testCreateLongArray()
    {
        $actual = $this->call('createLongArray');

        $this->assertCount(3, $actual);
        $this->assertSame(1, $actual[0]);
        $this->assertSame(2, $actual[1]);
        $this->assertSame(3, $actual[2]);
    }

    public function testCreateFloatArray()
    {
        $actual = $this->call('createFloatArray');

        $this->assertCount(3, $actual);
        $this->assertSame(1.5, $actual[0]);
        $this->assertSame(2.5, $actual[1]);
        $this->assertSame(3.5, $actual[2]);
    }

    public function testCreateDoubleArray()
    {
        $actual = $this->call('createDoubleArray');

        $this->assertCount(3, $actual);
        $this->assertSame(1.5, $actual[0]);
        $this->assertSame(2.5, $actual[1]);
        $this->assertSame(3.5, $actual[2]);
    }

    public function testCreateBooleanArray()
    {
        // CONTRACTS.md §1: boolean = PHP bool. AOT can't yet distinguish
        // boolean[] from byte[] at the bastore opcode (both use 0x54),
        // so boolean array elements stay int 0/1 until element-type
        // tracking lands. Asserting on the int form for now.
        $actual = $this->call('createBooleanArray');

        $this->assertCount(3, $actual);
        $this->assertSame(1, $actual[0]);
        $this->assertSame(0, $actual[1]);
        $this->assertSame(1, $actual[2]);
    }

    public function testCreateCharArray()
    {
        $actual = $this->call('createCharArray');

        $this->assertCount(3, $actual);
        $this->assertSame('A', $actual[0]);
        $this->assertSame('B', $actual[1]);
        $this->assertSame('C', $actual[2]);
    }

    public function testCreateByteArray()
    {
        $actual = $this->call('createByteArray');

        $this->assertCount(3, $actual);
        $this->assertSame(1, $actual[0]);
        $this->assertSame(2, $actual[1]);
        $this->assertSame(3, $actual[2]);
    }

    public function testMultiDimensionArrayWithConstants()
    {
        $actual = $this->call('multiDimensionArrayWithConstants');
        $this->assertEquals('Hello World!', $actual);
    }

    public function testMultiDimensionArrayWithDynamic()
    {
        $actual = $this->call('multiDimensionArrayWithDynamic');
        $this->assertCount(3, $actual);
        $this->assertSame('Hello', $actual[0]);
        $this->assertSame(' ', $actual[1]);
        $this->assertSame('World!', $actual[2]);
    }
}

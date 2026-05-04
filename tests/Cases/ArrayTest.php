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
        // CONTRACTS.md §1: boolean = PHP bool. The IR Builder now
        // tracks newarray T_BOOLEAN slots and narrows bastore writes
        // to bool, distinguishing boolean[] from byte[] (both share
        // the bastore opcode).
        $actual = $this->call('createBooleanArray');

        $this->assertCount(3, $actual);
        $this->assertSame(true,  $actual[0]);
        $this->assertSame(false, $actual[1]);
        $this->assertSame(true,  $actual[2]);
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

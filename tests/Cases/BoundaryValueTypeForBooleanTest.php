<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

class BoundaryValueTypeForBooleanTest extends Base
{
    use \PHPJava\Tests\Helpers\GetField;
    use \PHPJava\Tests\Helpers\AssertionHelpers\AssertFields;

    protected $fixtures = [
        'BoundaryValueTypeForBooleanTest',
    ];

    public function testStaticB0()
    {
        $this->assertStaticField(
            'true',
            'b0'
        );
    }

    public function testStaticB1()
    {
        $this->assertStaticField(
            'false',
            'b1'
        );
    }

    public function testDynamicB0()
    {
        $this->assertDynamicField(
            'true',
            'b0'
        );
    }

    public function testDynamicB1()
    {
        $this->assertDynamicField(
            'false',
            'b1'
        );
    }

    public function testStaticArrayBooleans()
    {
        // Per CONTRACTS.md §1: boolean = PHP bool. Asserting raw bool
        // (not '(string)' coercion which would be '1'/''); the AOT
        // path stores boolean[] elements as PHP bool via the IR
        // Builder's Z-narrow at bastore + the boolean-array slot
        // tracker.
        $array = $this->getStaticField('s_a_b');
        $this->assertCount(2, $array);

        $this->assertSame(true,  $array[0]);
        $this->assertSame(false, $array[1]);
    }

    public function testDynamicArrayBooleans()
    {
        $array = $this->getDynamicField('d_a_b');
        $this->assertCount(2, $array);

        $this->assertSame(true,  $array[0]);
        $this->assertSame(false, $array[1]);
    }

    public function testStaticMultiDimensionArrayBooleans()
    {
        $array = $this->getStaticField('s_ma_b');
        $this->assertCount(2, $array);
        $this->assertCount(2, $array[0]);
        $this->assertCount(2, $array[1]);

        $this->assertSame(true,  $array[0][0]);
        $this->assertSame(false, $array[0][1]);

        $this->assertSame(false, $array[1][0]);
        $this->assertSame(true,  $array[1][1]);
    }

    public function testDynamicMultiDimensionArrayBooleans()
    {
        $array = $this->getDynamicField('d_ma_b');
        $this->assertCount(2, $array);
        $this->assertCount(2, $array[0]);
        $this->assertCount(2, $array[1]);

        $this->assertSame(true,  $array[0][0]);
        $this->assertSame(false, $array[0][1]);

        $this->assertSame(false, $array[1][0]);
        $this->assertSame(true,  $array[1][1]);
    }
}

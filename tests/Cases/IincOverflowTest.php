<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates JVM `iinc` opcode wraps modulo 2^32 with sign extension,
 * matching Java int semantics. Surfaced by the 2026-05-04 audit as
 * ROADMAP gap T5.
 *
 * Pre-fix: src/Aot/Ir/Lowerer.php:209 emitted `$L[N] += delta;`
 * without any 32-bit mask. PHP int is 64-bit, so a local at
 * Integer.MAX_VALUE incremented to 2^31 instead of wrapping to
 * Integer.MIN_VALUE.
 */
class IincOverflowTest extends Base
{
    protected $fixtures = [
        'IincOverflowTest',
    ];

    private function call(string $name)
    {
        return static::$initiatedJavaClasses['IincOverflowTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call($name);
    }

    /** Integer.MAX_VALUE + 1 = Integer.MIN_VALUE. */
    public function testIncrementMaxInt(): void
    {
        $this->assertSame(-2147483648, $this->call('incrementMaxInt'));
    }

    public function testDecrementMinInt(): void
    {
        $this->assertSame(2147483647, $this->call('decrementMinInt'));
    }

    /** (MAX-100) + 200 wraps past MAX. */
    public function testWideIincPositive(): void
    {
        // (2^31-1 - 100) + 200 = 2^31 + 99 → wraps to -2^31 + 99 = -2147483549
        $this->assertSame(-2147483549, $this->call('wideIincPositive'));
    }

    public function testWideIincNegative(): void
    {
        // (-2^31 + 100) - 200 = -2^31 - 100 → wraps to 2^31 - 100 = 2147483548
        $this->assertSame(2147483548, $this->call('wideIincNegative'));
    }
}

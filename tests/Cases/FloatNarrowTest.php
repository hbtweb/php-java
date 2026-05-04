<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates Java `float` narrowing at the JVM contract boundary —
 * d2f / i2f / l2f conversions, putfield/putstatic on F fields, and
 * fastore on float[]. Surfaced by the 2026-05-04 audit as ROADMAP
 * gap T2.
 *
 * PHP `float` is binary64; Java `float` is binary32. Without explicit
 * narrowing, write-then-read round-trips on float fields keep the
 * 64-bit precision PHP doesn't intend Java to see.
 *
 * Per-arithmetic-op narrowing (after fadd/fmul/etc.) is NOT done —
 * documented limitation under CONTRACTS.md §1. This test exercises
 * only the boundary cases.
 */
class FloatNarrowTest extends Base
{
    protected $fixtures = [
        'FloatNarrowTest',
    ];

    private function call(string $name, ...$args)
    {
        return static::$initiatedJavaClasses['FloatNarrowTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call($name, ...$args);
    }

    /** Narrowed (float) cast of (0.1 + 0.2) — ~0.30000001192093. */
    public function testCastDoubleToFloat(): void
    {
        $expected = \unpack('f', \pack('f', 0.1 + 0.2))[1];
        $actual = $this->call('castDoubleToFloat');
        $this->assertSame($expected, $actual);
    }

    public function testRoundTripField(): void
    {
        $expected = \unpack('f', \pack('f', 1.0 / 3.0))[1];
        $actual = $this->call('roundTripField');
        $this->assertSame($expected, $actual);
    }

    public function testRoundTripArray(): void
    {
        $expected = \unpack('f', \pack('f', 1.0 / 3.0))[1];
        $actual = $this->call('roundTripArray');
        $this->assertSame($expected, $actual);
    }

    /** 2^24 + 1 narrowed to float — Java rounds to 16777216.0f. */
    public function testIntToFloatLargeMagnitude(): void
    {
        $this->assertSame(16777216.0, $this->call('intToFloatLargeMagnitude'));
    }

    public function testLongToFloatLargeMagnitude(): void
    {
        $this->assertSame(16777216.0, $this->call('longToFloatLargeMagnitude'));
    }
}

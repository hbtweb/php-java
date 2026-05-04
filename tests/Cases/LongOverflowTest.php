<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates Java long arithmetic wraps modulo 2^64 (two's complement)
 * rather than promoting to PHP float. Surfaced by the 2026-05-04
 * AOT correctness audit as gap T1 in ROADMAP §Next-work hierarchy.
 *
 * Pre-fix: ladd/lsub/lmul/ldiv/lrem/lneg used PHP native operators
 * directly, which silently promote to float on int overflow. This
 * test fails on master and passes after the jvm_l*-helper landing.
 */
class LongOverflowTest extends Base
{
    protected $fixtures = [
        'LongOverflowTest',
    ];

    private function call(string $name, ...$args)
    {
        return static::$initiatedJavaClasses['LongOverflowTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call($name, ...$args);
    }

    public function testLongMaxPlusOneWrapsToMin(): void
    {
        // Long.MAX_VALUE + 1 wraps to Long.MIN_VALUE in Java
        $this->assertSame(\PHP_INT_MIN, $this->call('maxPlusOne'));
    }

    public function testLongMinMinusOneWrapsToMax(): void
    {
        // Long.MIN_VALUE - 1 wraps to Long.MAX_VALUE in Java
        $this->assertSame(\PHP_INT_MAX, $this->call('minMinusOne'));
    }

    public function testLongMulOverflowWrapsToZero(): void
    {
        // (1<<32) * (1<<32) = 2^64, wraps to 0
        $this->assertSame(0, $this->call('mulOverflow'));
    }

    public function testLongNegMinIsMin(): void
    {
        // -Long.MIN_VALUE wraps back to Long.MIN_VALUE (because +2^63 doesn't fit)
        $this->assertSame(\PHP_INT_MIN, $this->call('negMin'));
    }

    public function testLongDivMinByMinusOneWrapsToMin(): void
    {
        // Long.MIN_VALUE / -1 wraps to Long.MIN_VALUE (would-be +2^63)
        $this->assertSame(\PHP_INT_MIN, $this->call('divMinByMinusOne'));
    }

    public function testLongRemMinByMinusOneIsZero(): void
    {
        // Java: MIN_VALUE % -1 = 0 (even though MIN_VALUE / -1 overflows)
        $this->assertSame(0, $this->call('remMinByMinusOne'));
    }
}

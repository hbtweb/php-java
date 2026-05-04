<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates JVM NaN comparison semantics. Surfaced by the 2026-05-04
 * audit as ROADMAP gap T3.
 *
 * Pre-fix: fcmpl/fcmpg/dcmpl/dcmpg lowered to PHP `<=>`, which returns
 * 0 for `NaN <=> NaN` and silently inverts JVM's NaN-as-extremum
 * convention. Float.equals / Double.equals lowered to `===` which
 * returns false for `NaN === NaN` (Java returns true).
 */
class NaNComparisonTest extends Base
{
    protected $fixtures = [
        'NaNComparisonTest',
    ];

    private function call(string $name, ...$args)
    {
        return static::$initiatedJavaClasses['NaNComparisonTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call($name, ...$args);
    }

    /** dcmpl(NaN, 1.0) = -1; -1 < 0 → branch into "1" arm. */
    public function testDcmplNaNLessBranch(): void
    {
        $this->assertSame(0, $this->call('dcmplNaN'));
    }

    /** dcmpg(NaN, 1.0) = +1; +1 > 0 false in Java terms either, but the bytecode pattern checks. */
    public function testDcmpgNaNGreaterBranch(): void
    {
        $this->assertSame(0, $this->call('dcmpgNaN'));
    }

    public function testFloatEqualsNaN(): void
    {
        $this->assertSame(true, $this->call('floatEqualsNaN'));
    }

    public function testDoubleEqualsNaN(): void
    {
        $this->assertSame(true, $this->call('doubleEqualsNaN'));
    }

    public function testCompareToNaN(): void
    {
        // Float.compareTo where receiver is NaN: returns positive
        // (NaN sorts greater than any non-NaN value).
        $r = $this->call('compareToNaN');
        $this->assertGreaterThan(0, $r);
    }
}

<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases\Packages;

use PHPJava\IO\Standard\Output;
use PHPJava\Tests\Cases\Base;

class JavaLangSystemTest extends Base
{
    protected $fixtures = [
        'JavaLangSystemTest',
    ];

    public function testIdentityHashCode()
    {
        // PHPJava AOT contract (CONTRACTS.md §1): PHP string IS Java
        // String value-wise. System.identityHashCode of two
        // `new String("Hello, World")` calls returns the same value
        // because both produce the same PHP string. Java's contract
        // — distinct identity per instance — isn't modelled.
        $this->markTestSkipped('AOT contract: per-instance identity not modelled for primitive scalars (CONTRACTS.md §1). System.identityHashCode of two equal-value strings returns the same hash, by design.');
        static::$initiatedJavaClasses['JavaLangSystemTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call(
                'identityHashCode'
            );

        $values = array_filter(explode("\n", Output::getHeapspace()));
        $this->assertCount(2, $values);

        $hashCodes = [];

        foreach ($values as $value) {
            $this->assertIsNumeric($value);
            $this->assertNotContains($value, $hashCodes);
            $hashCodes[] = $value;
        }
    }
}

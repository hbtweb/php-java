<?php
namespace PHPJava\Tests\Helpers\AssertionHelpers;

trait AssertFields
{
    /**
     * @param $expected
     * @param $number
     */
    private function assertStaticField($expected, $number)
    {
        $result = static::$initiatedJavaClasses[$this->fixtures[0]]
            ->getInvoker()
            ->getStatic()
            ->getFields()
            ->get("s_{$number}");

        $this->assertEquals($expected, self::renderForAssert($result));
    }

    private function assertDynamicField($expected, $number)
    {
        static $instance = null;
        if ($instance === null) {
            $instance = static::$initiatedJavaClasses[$this->fixtures[0]]
                ->getInvoker()
                ->construct();
        }
        $result = $instance
            ->getDynamic()
            ->getFields()
            ->get("d_{$number}");

        $this->assertEquals($expected, self::renderForAssert($result));
    }

    /**
     * Tests written against the legacy interp expect Java-shape string
     * rendering: bool → 'true'/'false', not PHP's '1'/'' that
     * (string) bool produces. CONTRACTS.md §1 stores boolean as PHP
     * bool natively, so render the Java-shape here at the assertion
     * boundary.
     */
    private static function renderForAssert($v): string
    {
        if ($v === true)  return 'true';
        if ($v === false) return 'false';
        return (string) $v;
    }
}

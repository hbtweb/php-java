<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Types;

use Brick\Math\BigDecimal;

class Double_ extends Type implements PrimitiveValueInterface
{
    const DEFAULT_VALUE = '0';

    protected $nameInJava = 'double';
    protected $nameInPHP = 'float';

    const MIN = '4.9E-324';
    const MAX = '1.7976931348623157E308';

    public static function isValid($value): bool
    {
        // PHP 8 abs() is strictly typed (int|float); reject non-numeric
        // strings up front (e.g. "NaN" defaults from constant pool),
        // and cast numeric strings to float for the abs() call.
        if (!is_numeric($value)) {
            return false;
        }
        if (!is_numeric((string) abs((float) $value))) {
            return false;
        }

        $value = BigDecimal::of($value)->abs();

        return $value->isEqualTo('0') || (
            $value->isGreaterThan(static::MIN) &&
            $value->isLessThan(static::MAX)
        );
    }

    protected static function filter($value)
    {
        return (string) $value;
    }
}

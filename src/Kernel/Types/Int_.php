<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Types;

class Int_ extends Type implements PrimitiveValueInterface
{
    const DEFAULT_VALUE = 0;

    protected $nameInJava = 'int';
    protected $nameInPHP = 'integer';

    const MIN = -2147483648;
    const MAX = 2147483647;

    public static function isValid($value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }
        if (ctype_alpha($value) && strlen((string) $value) === 1) {
            $value = ord($value);
        }
        // After the single-char-alpha shortcut, $value should be numeric
        // for valid int representations. Non-numeric strings become 0
        // via PHP shift coercion otherwise — masking real validation
        // failures. Reject up front. PHP 8 also requires abs() input to
        // be int|float.
        if (!is_numeric($value)) {
            return false;
        }

        $value = ($value << 32) >> 32;
        if (!ctype_digit((string) abs($value))) {
            return false;
        }

        return $value >= static::MIN && $value <= static::MAX;
    }

    protected static function filter($value)
    {
        if (ctype_alpha($value) && strlen((string) $value) === 1) {
            return ord($value);
        }

        return ($value << 32) >> 32;
    }
}

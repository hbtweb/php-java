<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Types;

use PHPJava\Exceptions\TypeException;

abstract class Type
{
    const DEFAULT_VALUE = null;

    /**
     * @var null|self
     */
    protected $value;

    /**
     * @var string
     */
    protected $nameInJava;

    /**
     * @var string
     */
    protected $nameInPHP;

    /**
     * @param null|mixed $value
     * @throws TypeException
     */
    public function __construct($value = null)
    {
        if (!($value instanceof self) &&
            $value !== null &&
            !static::isValid($value)
        ) {
            throw new TypeException(
                '"' . ((string) $value) . '" is not expected in ' . get_class($this) . '.'
            );
        }

        $this->value = static::filter(
            ($value instanceof self)
                ? $value->getValue()
                : $value
        );
    }

    public function __debugInfo()
    {
        return [
            'value' => $this->value,
        ];
    }

    /**
     * @param null|mixed $value
     * @throws TypeException
     */
    public static function get($value = null): self
    {
        // Cache is keyed by subclass — a single $instantiated would be
        // shared across every Type subclass since PHP `static` in a
        // method is per-function, not per-class. Without the
        // static::class key, Char_::get(32767) and Short_::get(32767)
        // return the same instance (whichever subclass cached first
        // wins). #12 wrapper-removal exposed this by changing which
        // subclass populates the cache first.
        static $instantiated = [];
        $bucket = static::class;
        if ($value === null) {
            if (static::DEFAULT_VALUE === null) {
                throw new TypeException('The type has not default value.');
            }
            return static::get(
                static::DEFAULT_VALUE
            );
        }
        if (is_object($value)) {
            if ($value instanceof static) {
                return $value;
            }
            $identity = spl_object_hash($value);
        } else {
            $identity = (string) $value;
        }
        return $instantiated[$bucket][$identity]
            = $instantiated[$bucket][$identity] ?? new static($value);
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getTypeNameInJava(): string
    {
        return $this->nameInJava;
    }

    public function getTypeNameInPHP(): string
    {
        return $this->nameInPHP;
    }

    public function __toString(): string
    {
        return (string) $this->getValue();
    }

    public static function isValid($value): bool
    {
        throw new TypeException('Not implemented type validation.');
    }

    protected static function filter($value)
    {
        return $value;
    }
}

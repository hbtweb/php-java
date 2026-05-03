<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Maps;

class Map
{
    public function getName(int $value): ?string
    {
        // H3 — getName is hot-path on the interpreter (once per opcode).
        // Cache the flipped value→name table per class instead of a
        // ReflectionClass + getConstants + array_search per call. All
        // Map subclasses have unique const values (verified), so
        // array_flip is well-defined.
        static $cache = [];
        $class = static::class;
        if (!isset($cache[$class])) {
            try {
                $cache[$class] = array_flip(
                    (new \ReflectionClass($this))->getConstants()
                );
            } catch (\ReflectionException $e) {
                $cache[$class] = [];
            }
        }
        return $cache[$class][$value] ?? null;
    }

    public function getValue(string $name)
    {
        try {
            $reflectionClass = new \ReflectionClass($this);
            return $reflectionClass->getConstant($name);
        } catch (\ReflectionException $e) {
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function getValues(): array
    {
        try {
            $reflectionClass = new \ReflectionClass($this);
            return $reflectionClass->getConstants();
        } catch (\ReflectionException $e) {
        }
        return [];
    }
}

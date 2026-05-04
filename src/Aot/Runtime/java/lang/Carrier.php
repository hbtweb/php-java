<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.ScopedValue.Carrier (Java 21+) — fluent intermediate
 * returned by ScopedValue::where(). Holds a list of (ScopedValue,
 * value) bindings; .run(fn) installs them, runs the closure, and
 * removes them.
 *
 * Carrier supports chaining via where(): multiple scoped-values can
 * be bound in a single .run() block.
 */
class Carrier
{
    /** @var list<array{0: ScopedValue, 1: mixed}> */
    private array $bindings;

    public function __construct(array $binding)
    {
        $this->bindings = [$binding];
    }

    public function where(ScopedValue $scope, mixed $value): self
    {
        $this->bindings[] = [$scope, $value];
        return $this;
    }

    public function run(callable $action): void
    {
        foreach ($this->bindings as [$scope, $value]) {
            $scope->_pushBinding($value);
        }
        try {
            $action();
        } finally {
            foreach (\array_reverse($this->bindings) as [$scope, $value]) {
                $scope->_popBinding();
            }
        }
    }

    public function call(callable $action): mixed
    {
        foreach ($this->bindings as [$scope, $value]) {
            $scope->_pushBinding($value);
        }
        try {
            return $action();
        } finally {
            foreach (\array_reverse($this->bindings) as [$scope, $value]) {
                $scope->_popBinding();
            }
        }
    }
}

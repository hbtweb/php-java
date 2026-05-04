<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.ScopedValue (Java 21 preview, 22+ stable) — immutable
 * thread-local-style value bound only within a where(...).run(...)
 * block. Replaces ThreadLocal for use cases that don't need
 * mutability across the lifetime of a thread.
 *
 * Java's reference impl integrates with virtual threads; PHP
 * Fibers ARE virtual threads in Java's terminology. We track
 * the binding stack per-fiber via a static map.
 *
 * Usage:
 *   $scope = ScopedValue::newInstance();
 *   ScopedValue::where($scope, 'value')->run(function() use ($scope) {
 *       $scope->get(); // → 'value'
 *   });
 *   // Outside the run() block, $scope->get() throws or returns default.
 */
class ScopedValue
{
    /** @var array<int, list<mixed>> per-fiber binding stack indexed by spl_object_id(ScopedValue). */
    private static array $bindingsPerFiber = [];

    public static function newInstance(): self
    {
        return new self();
    }

    /** Java 21: ScopedValue.where(scope, value) — returns a Carrier. */
    public static function where(self $scope, mixed $value): Carrier
    {
        return new Carrier([$scope, $value]);
    }

    public function get(): mixed
    {
        $fiberId = $this->fiberId();
        $stack = self::$bindingsPerFiber[$fiberId][\spl_object_id($this)] ?? null;
        if ($stack === null || empty($stack)) {
            throw new \PHPJava\Packages\java\lang\IllegalStateException(
                'ScopedValue not bound in current scope'
            );
        }
        return \end($stack);
    }

    public function isBound(): bool
    {
        $fiberId = $this->fiberId();
        $stack = self::$bindingsPerFiber[$fiberId][\spl_object_id($this)] ?? null;
        return !empty($stack);
    }

    public function orElse(mixed $other): mixed
    {
        return $this->isBound() ? $this->get() : $other;
    }

    private function fiberId(): int
    {
        $current = \Fiber::getCurrent();
        return $current !== null ? \spl_object_id($current) : 0;
    }

    /** Internal: push a binding for this scope. */
    public function _pushBinding(mixed $value): void
    {
        $fiberId = $this->fiberId();
        self::$bindingsPerFiber[$fiberId][\spl_object_id($this)][] = $value;
    }

    /** Internal: pop the most recent binding. */
    public function _popBinding(): void
    {
        $fiberId = $this->fiberId();
        \array_pop(self::$bindingsPerFiber[$fiberId][\spl_object_id($this)]);
    }
}

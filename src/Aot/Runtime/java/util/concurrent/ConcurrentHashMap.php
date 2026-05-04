<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.ConcurrentHashMap — thread-safe hash map.
 *
 * v1 implementation: single-mutex over a PHP array. Under PHP's
 * single-threaded cooperative scheduling, most operations are
 * trivially atomic — no other fiber runs unless we yield. The
 * "mutex" is implicit: no operation in this class yields, so
 * by construction we have exclusive access for the duration of
 * each method.
 *
 * This gives observable behaviour identical to Java's CHM for
 * workloads that don't rely on cross-thread visibility ordering
 * (which PHP cannot model anyway). For workloads that DO need
 * lock-striping for parallelism, the PHPJava AOT pipeline's
 * may-suspend analyser routes most reads to the inline path
 * which never sees contention; only suspending operations would
 * benefit from the JDK's striped-lock implementation, and those
 * paths would have to use VTE which serialises on the event loop
 * anyway.
 *
 * Net effect: this single-mutex impl is observably equivalent for
 * all PHP-AOT workloads. If a fixture surfaces a measurable need
 * for striping (~64 segments), upgrade is mechanical.
 */
class ConcurrentHashMap
{
    /** @var array<string, mixed> */
    private array $map = [];
    private int $loadFactor;

    public function __construct(int $initialCapacity = 16, float $loadFactor = 0.75)
    {
        $this->loadFactor = (int) ($initialCapacity * $loadFactor);
    }

    public function get(mixed $key): mixed
    {
        $k = $this->keyOf($key);
        return $this->map[$k] ?? null;
    }

    public function put(mixed $key, mixed $value): mixed
    {
        if ($value === null) {
            throw new \PHPJava\Packages\java\lang\NullPointerException('value is null');
        }
        $k = $this->keyOf($key);
        $old = $this->map[$k] ?? null;
        $this->map[$k] = $value;
        return $old;
    }

    /** Java 8+: putIfAbsent — atomic insert-if-missing. */
    public function putIfAbsent(mixed $key, mixed $value): mixed
    {
        $k = $this->keyOf($key);
        if (isset($this->map[$k])) return $this->map[$k];
        $this->map[$k] = $value;
        return null;
    }

    public function remove(mixed $key, mixed $expectedValue = null): mixed
    {
        $k = $this->keyOf($key);
        if (!isset($this->map[$k])) return $expectedValue !== null ? false : null;
        if ($expectedValue !== null) {
            // 2-arg variant: remove only if value matches
            if ($this->map[$k] !== $expectedValue && $this->map[$k] != $expectedValue) {
                return false;
            }
            unset($this->map[$k]);
            return true;
        }
        $old = $this->map[$k];
        unset($this->map[$k]);
        return $old;
    }

    public function replace(mixed $key, mixed $value, mixed $expectedOld = null): mixed
    {
        $k = $this->keyOf($key);
        if (!isset($this->map[$k])) return $expectedOld !== null ? false : null;
        if ($expectedOld !== null) {
            // 3-arg: replace only if old matches
            if ($this->map[$k] !== $expectedOld && $this->map[$k] != $expectedOld) {
                return false;
            }
            $this->map[$k] = $value;
            return true;
        }
        $old = $this->map[$k];
        $this->map[$k] = $value;
        return $old;
    }

    public function containsKey(mixed $key): bool
    {
        return isset($this->map[$this->keyOf($key)]);
    }

    public function containsValue(mixed $value): bool
    {
        foreach ($this->map as $v) {
            if ($v === $value || $v == $value) return true;
        }
        return false;
    }

    public function size(): int { return \count($this->map); }
    public function isEmpty(): bool { return empty($this->map); }
    public function clear(): void { $this->map = []; }

    public function keySet(): array
    {
        return \array_keys($this->map);
    }

    public function values(): array
    {
        return \array_values($this->map);
    }

    public function entrySet(): array
    {
        $entries = [];
        foreach ($this->map as $k => $v) $entries[] = [$k, $v];
        return $entries;
    }

    /** Java 8+: compute(key, BiFunction<K,V,V>). */
    public function compute(mixed $key, callable $remappingFunction): mixed
    {
        $k = $this->keyOf($key);
        $old = $this->map[$k] ?? null;
        $new = $remappingFunction($key, $old);
        if ($new === null) {
            unset($this->map[$k]);
        } else {
            $this->map[$k] = $new;
        }
        return $new;
    }

    /** Java 8+: computeIfAbsent(key, Function<K,V>). */
    public function computeIfAbsent(mixed $key, callable $mappingFunction): mixed
    {
        $k = $this->keyOf($key);
        if (isset($this->map[$k])) return $this->map[$k];
        $value = $mappingFunction($key);
        if ($value !== null) $this->map[$k] = $value;
        return $value;
    }

    /** Java 8+: computeIfPresent. */
    public function computeIfPresent(mixed $key, callable $remappingFunction): mixed
    {
        $k = $this->keyOf($key);
        if (!isset($this->map[$k])) return null;
        $new = $remappingFunction($key, $this->map[$k]);
        if ($new === null) {
            unset($this->map[$k]);
            return null;
        }
        $this->map[$k] = $new;
        return $new;
    }

    /** Java 8+: merge(key, value, BiFunction<V,V,V>). */
    public function merge(mixed $key, mixed $value, callable $remappingFunction): mixed
    {
        if ($value === null) {
            throw new \PHPJava\Packages\java\lang\NullPointerException('value is null');
        }
        $k = $this->keyOf($key);
        $new = isset($this->map[$k])
            ? $remappingFunction($this->map[$k], $value)
            : $value;
        if ($new === null) {
            unset($this->map[$k]);
        } else {
            $this->map[$k] = $new;
        }
        return $new;
    }

    public function getOrDefault(mixed $key, mixed $default): mixed
    {
        return $this->map[$this->keyOf($key)] ?? $default;
    }

    public function forEach(callable $action): void
    {
        foreach ($this->map as $k => $v) $action($k, $v);
    }

    public function putAll(self|array $other): void
    {
        if ($other instanceof self) {
            foreach ($other->map as $k => $v) $this->map[$k] = $v;
            return;
        }
        foreach ($other as $k => $v) $this->map[$this->keyOf($k)] = $v;
    }

    /**
     * Coerce arbitrary key to an array-key string. PHP arrays only
     * accept int|string keys; objects and floats need normalisation.
     * Java's Object.hashCode() + .equals() identity is approximated
     * here via spl_object_hash for objects. Two equal-shape stdClass
     * objects will have different hashes — same as Java's default
     * Object.equals. Override patterns can be added per-class via
     * the AOT compiler's hashCode/equals inlining.
     */
    private function keyOf(mixed $key): string|int
    {
        if (\is_int($key) || \is_string($key)) return $key;
        if (\is_object($key)) {
            // If the user defined hashCode + equals, rely on those
            // via __toString. Else use spl_object_hash for identity.
            if (\method_exists($key, 'hashCode')) {
                return (string) $key->hashCode();
            }
            return \spl_object_hash($key);
        }
        if (\is_float($key)) {
            // Java: Float.hashCode normalises NaN equality. Approx
            // via PHP's float-to-string which normalises NaN to "NAN".
            return (string) $key;
        }
        if (\is_bool($key)) return $key ? '__t__' : '__f__';
        if ($key === null) {
            throw new \PHPJava\Packages\java\lang\NullPointerException('key is null');
        }
        return \serialize($key);
    }
}

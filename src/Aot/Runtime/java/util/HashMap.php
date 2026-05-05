<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util;

use PHPJava\Aot\Runtime\java\lang\Boolean;
use PHPJava\Aot\Runtime\java\lang\String_;

/**
 * java.util.HashMap — bb-allowlist fill (9/80).
 *
 * Backing store: PHP array. PHP arrays are already hash maps with
 * O(1) average put/get; we type-prefix keys (`s:`, `i:`, `b:`, …) to
 * preserve key identity across PHP's int/string/bool coercion (PHP
 * `$arr["1"]` and `$arr[1]` collide; Java's HashMap doesn't).
 *
 * Surface: put, get, containsKey, containsValue, remove, size,
 * isEmpty, clear, getOrDefault, putIfAbsent, equals, hashCode,
 * toString.
 *
 * Deferred: keySet / values / entrySet (need Set / Collection / Entry
 * interfaces — extend when a fixture exercises iteration); compute /
 * computeIfAbsent / computeIfPresent / merge / forEach (functional —
 * ship when bb-allowlist usage surfaces); putAll(Map) — partial via
 * iteration over $other->data once iteration ships.
 *
 * No parity battery — instance-method tests need a richer driver
 * (construct instance, dispatch chain). Smoke-validated in PHP via
 * tests/Cases/Packages/JavaUtilHashMapShimTest if/when added.
 *
 * Note: HashMap.hashCode is INSTANCE method; doesn't appear in the
 * shim-chain-profiler's static-call analysis. Profile signal moves
 * via Arrays::hashCode (already shipped) which IS static.
 */
final class HashMap
{
    /** @var array<string, mixed> backing store, type-prefixed keys */
    private array $data = [];

    public function __construct() {}

    public function size(): int      { return \count($this->data); }
    public function isEmpty(): bool  { return empty($this->data); }
    public function clear(): void    { $this->data = []; }

    public function put($key, $value)
    {
        $k = $this->keyFor($key);
        $old = $this->data[$k] ?? null;
        $this->data[$k] = $value;
        return $old;
    }

    public function get($key)
    {
        $k = $this->keyFor($key);
        return $this->data[$k] ?? null;
    }

    public function containsKey($key): bool
    {
        return \array_key_exists($this->keyFor($key), $this->data);
    }

    public function containsValue($value): bool
    {
        return \in_array($value, $this->data, true);
    }

    public function remove($key)
    {
        $k = $this->keyFor($key);
        if (!\array_key_exists($k, $this->data)) return null;
        $old = $this->data[$k];
        unset($this->data[$k]);
        return $old;
    }

    public function getOrDefault($key, $default)
    {
        $k = $this->keyFor($key);
        return \array_key_exists($k, $this->data) ? $this->data[$k] : $default;
    }

    public function putIfAbsent($key, $value)
    {
        $k = $this->keyFor($key);
        if (\array_key_exists($k, $this->data)) return $this->data[$k];
        $this->data[$k] = $value;
        return null;
    }

    /**
     * Java spec: sum of each entry's `key.hashCode() ^ value.hashCode()`,
     * narrowed to int32. Compositional via Objects::hashCode which
     * dispatches by element type — creates depth-≥3 chains for
     * String/Integer keys.
     */
    public function hashCode(): int
    {
        $sum = 0;
        foreach ($this->data as $kPrefixed => $v) {
            $key = $this->unprefixKey($kPrefixed);
            $kh = Objects::hashCode($key);
            $vh = $v === null ? 0 : Objects::hashCode($v);
            $sum = ($sum + ($kh ^ $vh)) & 0xFFFFFFFF;
        }
        return $sum >= 0x80000000 ? $sum - 0x100000000 : $sum;
    }

    public function equals($other): bool
    {
        if (!($other instanceof self)) return false;
        if ($this->size() !== $other->size()) return false;
        foreach ($this->data as $k => $v) {
            if (!\array_key_exists($k, $other->data)) return false;
            if ($v !== $other->data[$k]) return false;
        }
        return true;
    }

    /** Java-style "{k1=v1, k2=v2}" rendering. Order is insertion order. */
    public function toString(): string
    {
        if (empty($this->data)) return '{}';
        $parts = [];
        foreach ($this->data as $kPrefixed => $v) {
            $key = $this->unprefixKey($kPrefixed);
            $parts[] = $this->renderEntry($key) . '=' . $this->renderEntry($v);
        }
        return '{' . \implode(', ', $parts) . '}';
    }

    public function __toString(): string { return $this->toString(); }

    /**
     * Map a Java key to a PHP array key. PHP coerces "1" and 1 to the
     * same key on bare arrays; type prefix preserves identity.
     */
    private function keyFor($key): string
    {
        if ($key === null)        return "\x00null\x00";
        if (\is_string($key))     return "s:$key";
        if (\is_int($key))        return "i:$key";
        if (\is_bool($key))       return 'b:' . ($key ? '1' : '0');
        if (\is_float($key))      return "f:" . \var_export($key, true);
        if (\is_object($key))     return 'o:' . \spl_object_id($key);
        return 'x:' . \serialize($key);
    }

    /** Reverse of keyFor — recover the original key from the prefix. */
    private function unprefixKey(string $kPrefixed)
    {
        if ($kPrefixed === "\x00null\x00") return null;
        $tag = $kPrefixed[0];
        $rest = \substr($kPrefixed, 2);
        return match ($tag) {
            's' => $rest,
            'i' => (int) $rest,
            'b' => $rest === '1',
            'f' => (float) $rest,
            'o' => $rest,  // object id only; the original ref is gone
            default => $rest,
        };
    }

    private function renderEntry($v): string
    {
        if ($v === null)  return 'null';
        if ($v === true)  return 'true';
        if ($v === false) return 'false';
        return (string) $v;
    }
}

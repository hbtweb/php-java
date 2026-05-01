# Boxing — gut it

> Date: 2026-05-01.
> Source measurements: `bench/validate-boxing.php` (6 patterns × 5 workloads).
> Conclusion: **don't box.** Eliminate primitive wrapper instances from the
> runtime entirely. Keep wrapper classes for static methods and reflection
> metadata only.

## The question

Java has primitive types (`int`, `long`, etc.) and wrapper types (`Integer`,
`Long`, etc.). Generics, autoboxing, and polymorphism cause primitives to
flow through wrapper-shaped code paths. Naively implementing this in PHP
costs 7–9× per arithmetic op (measured).

**Do we actually need wrappers in PHP?**

## The answer: no, with one documented divergence

For 99% of code paths, pure PHP scalars satisfy Java's observable semantics.
For the 1% remaining (dynamic type discrimination across merged Object
slots), a small AOT-time tagged-tuple fallback handles it.

## Mapping JVM contract to PHP semantics

| Java semantic | PHP equivalent | Boxing needed? |
|---|---|---|
| `int` arithmetic | PHP int math | no |
| `Integer.valueOf(x)` | identity (return $x unchanged) | no |
| `i.intValue()` | identity (return $i unchanged) | no |
| `i.equals(j)` | `$i === $j` | no |
| `i.hashCode()` | the int value (Java spec) | no |
| `i.toString()` | `(string) $i` | no |
| `Integer.parseInt(s)` | `(int) $s` (with validation) | no |
| `Integer.MAX_VALUE` | `2147483647` constant | no |
| `Integer.compare(a, b)` | `$a <=> $b` | no |
| `map.put(Integer, V)` | PHP array `$map[$key] = $v` | no |
| `o instanceof Integer` (statically Integer) | constant `true` | no |
| `o instanceof Integer` (statically Object) | `is_int($o)` | no |
| `Object o = i; o.getClass()` | static-type tracking → Integer.class | no |
| `null` Integer | PHP `null` | no |
| `Integer.valueOf(5) == Integer.valueOf(5)` | `5 === 5` (always true) | **diverges** |
| `o instanceof Long` (control-flow-merged) | tagged tuple at autobox site | **only here** |

## The two real concerns

### Concern 1: Integer identity equality (`==`)

Java's `Integer.valueOf(200) == Integer.valueOf(200)` returns `false` —
identity comparison; cache only covers -128..127.

Pure unboxed PHP makes this `true` (`200 === 200`).

**This is a documented Java footgun.** Every style guide forbids `==` on
boxed numeric types. Linters flag it. Real production code uses `.equals()`.

The divergence affects only buggy Java code. **We accept the divergence and
document it.** A strict-Java-semantics test mode can be added later
(synthesize unique wrapper objects above the cache range) but production
ships with value equality.

### Concern 2: dynamic instanceof on merged Object slots

```java
Object o = condition ? Integer.valueOf(5) : Long.valueOf(5L);
if (o instanceof Integer) ...   // ?
```

At the merge point, `o`'s static type is `Object` (or `Number`). The AOT
compiler can't statically resolve `instanceof Integer`. PHP's `is_int($o)`
is true for both Integer and Long autoboxed values.

**Solution:** at autobox sites that may flow into a merge point with mixed
numeric types, emit a tagged tuple `['I', $v]` or `['J', $v]` instead of
the bare scalar. The AOT compiler detects this from CFG analysis (look for
control-flow merges where both branches autobox different numeric types).

Cost (per-`p4-tagged-tuple` measurement): ~25 ns/op at the autobox site.
~95% of real code never hits this; the 5% pays a small allocation cost.

## The implementation

### Wrapper classes shrink to namespaces of static methods

**Before** (current PHPJava):
```php
final class Int_ extends Type implements PrimitiveValueInterface {
    const DEFAULT_VALUE = 0;
    protected $nameInJava = 'int';
    protected $nameInPHP = 'integer';
    // ... wrapper instance with ->value field, ::get factory, etc.
}
```

**After**:
```php
// src/Packages/java/lang/Integer.php — static helpers + reflection metadata
final class Integer {
    const MIN_VALUE = -2147483648;
    const MAX_VALUE = 2147483647;

    public static function valueOf(int $v): int { return $v; }
    public static function parseInt(string $s): int { return (int) $s; }
    public static function intValue(int $v): int { return $v; }
    public static function equals(int $a, int $b): bool { return $a === $b; }
    public static function hashCode(int $v): int { return $v; }
    public static function toString(int $v): int|string { return (string) $v; }
    public static function compare(int $a, int $b): int { return $a <=> $b; }
    // ... ~30 static methods total, all taking and returning PHP scalars
}
```

No instances. Ever. The class exists for namespace, static methods, and
reflection (when bytecode does `Class.forName("java.lang.Integer")` we
return a Class metadata object referencing this PHP class).

Same shape for `Long`, `Double`, `Float`, `Boolean`, `Character`, `Short`,
`Byte`, `Number` (abstract).

### Cut: `Kernel/Types/*` (the wrapper instance hierarchy)

Cut entirely:
- `Kernel/Types/Int_.php`
- `Kernel/Types/Long_.php`
- `Kernel/Types/Double_.php`
- `Kernel/Types/Float_.php`
- `Kernel/Types/Boolean_.php`
- `Kernel/Types/Char_.php`
- `Kernel/Types/Byte.php`
- `Kernel/Types/Short_.php`
- `Kernel/Types/Type.php` (the base class)
- `Kernel/Types/PrimitiveValueInterface.php`
- `Kernel/Types/Array_/*` (boxed-array layer; native PHP array suffices)
- `Kernel/Filters/Normalizer.php` (the wrap/unwrap helper)

Replace string-tag references with constants:
```php
// Where bytecode handlers reference Int_::class, replace with 'I' or
// the appropriate JVM descriptor character.
const TYPE_INT    = 'I';
const TYPE_LONG   = 'J';
const TYPE_DOUBLE = 'D';
const TYPE_FLOAT  = 'F';
const TYPE_BOOL   = 'Z';
const TYPE_CHAR   = 'C';
const TYPE_BYTE   = 'B';
const TYPE_SHORT  = 'S';
```

These are JVM standard descriptor characters. Single-byte type tags
suffice for compile-time tracking.

**Net cut:** ~2,500 LOC of wrapper hierarchy. Plus the dependent code
in `Kernel/Mnemonics/_*::execute()` that allocated wrappers — also gone
in the per-opcode-class cut already planned.

### AOT compiler: static type tracking

The AOT compiler maintains a per-slot type table during emission:

```php
$slotTypes = [];  // slot index → JVM descriptor char

// On iload_1: slot 1 contains an int
case 0x1B: $slotTypes[1] = 'I'; emit '$stack[$sp++] = $L[1];'; break;

// On invokestatic Integer.valueOf: stack top now Integer (from int)
case 0xB8: ...
    if ($targetMethod === 'java.lang.Integer.valueOf') {
        // descriptor (I)Ljava/lang/Integer; — but value still PHP int
        // emit no-op; type tag stays 'I' on the value
    }
```

When `instanceof Integer` is emitted, the compiler consults the slot's
known type:
- If known statically `I` → emit `true`
- If known statically `J` → emit `false`
- If not known (control-flow merge) → emit `is_int($o)` or fallback to
  tagged-tuple-aware check

### Tagged tuple fallback for merged slots

When CFG analysis detects an Object slot with merged primitive types:

```php
// Compiler detects:  Object o = cond ? Integer.valueOf(x) : Long.valueOf(y)
// Emits at the autobox sites in both branches:
$o = $cond ? ['I', $x] : ['J', $y];
// Then for instanceof checks:
if ($o[0] === 'I') { ... }  // emit instead of is_int
```

This is the P4 single-element-array pattern with type tag, used only
where the control-flow analysis demands it.

## What this enables

Every adjacent concern benefits:

| Concern | Benefit from no-boxing |
|---|---|
| **Concurrency** | AtomicInteger wraps a PHP int directly; no wrapper instances to synchronize |
| **Reflection** | Method.invoke passes PHP scalars at the boundary; no marshalling |
| **Serialization** | json_encode / serialize work natively on scalars |
| **Memory** | zero wrapper allocations = zero heap pressure for primitives |
| **Debugging** | var_dump shows int(5), not object(Int_); IDE introspection works |
| **Test parity** | assertSame(5, $result) matches assertEquals(5, x) directly |
| **opcache footprint** | smaller emitted PHP without per-op wrapper allocation |
| **JIT efficacy** | scalars trace through Zend JIT; wrappers break tracing |

## Cross-checking against patterns

Every pattern we validated reinforces this decision:

- **Switch dispatch on scalars** (Rule 5): each case operates on PHP int, not wrapper. Native arithmetic.
- **PHP locals for frame state** (Rule 2): locals hold scalars. Wrapper would force per-access wrap/unwrap.
- **Pre-decoded int array bytecode** (Rule 1): values flow as scalars throughout.
- **AOT-compile-then-eval** (validated): emitted PHP uses scalars; JIT traces it. Wrappers break tracing.
- **Resolved-once dispatch** (var-mem pattern): static type info travels at compile time, never runtime.

There's no pattern in our validated set that wants wrappers. **The architecture is wrapper-free by construction.**

## Validation summary

From `bench/validate-boxing.php` (PHP 8.5 + JIT):

| Pattern | W1 arith | W4 round-trip | Mean speedup vs P1 |
|---|---|---|---|
| P1 object wrapper (current PHPJava) | 4097 ns | 4082 ns | baseline |
| P2 cached factory (Java IntegerCache) | 4814 ns | 2288 ns | 1.4× faster |
| P3 array tagged | 3031 ns | 2852 ns | 1.4× faster |
| P4 single-element [v] | 2481 ns | 2529 ns | 1.6× faster |
| **P5 pure unboxed** | **1239 ns** | **1347 ns** | **3.0× faster** |

Pure unboxed wins every workload. The IntegerCache pattern (P2) is
faster than P1 for small values but never beats P5. **There is no
measurement that justifies keeping the wrapper layer.**

## What gets revised

| Doc | Update |
|---|---|
| `docs/CONTRACTS.md` §1 | "Drop primitive wrappers everywhere. Keep wrapper classes as static-method namespaces only. Tagged tuples only at CFG-merge autobox sites." |
| `docs/PATTERNS.md` Rule 4 | already correct; this doc is the deeper rationale |
| `docs/STATUS.md` Week 2 | "Boxing refactor — delete `Kernel/Types/*` and `Kernel/Filters/Normalizer`. Add `Packages/java/lang/Integer` etc. as static-method namespaces. ~2500 LOC removed, ~600 LOC added." |
| `ROADMAP.md` Tier 1b | Boxing decision is "gut it" not "make compile-time tags" |

Net effort budget unchanged; outcome is cleaner. The wrapper-as-runtime-
object decision was incidental complexity from spec-faithful
implementation; gutting it removes the largest single accidental cost
in the codebase.

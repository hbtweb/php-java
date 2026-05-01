# Bottleneck audit + type emulation

> Date: 2026-05-01.
> Source: `bench/validate-remaining.php` + earlier benches.
> Comprehensive enumeration of measured costs and the type-emulation
> question. Companion to `docs/PATTERNS.md`, `docs/BOXING.md`, `docs/CONTRACTS.md`.

This document catalogs every bottleneck I can identify, with measurements
where possible. It also addresses the type-emulation question: how to
represent Java's numeric tower, objects, and class metadata in PHP without
rebuilding the JVM's object model.

## Bottleneck audit (PHP 8.5 + opcache + JIT)

Each row is a class of work that real Java code triggers. Measured cost
in ns per operation; verdicts based on whether the cost dominates real
workloads.

### A. Hot-path costs (already validated in earlier benches)

| Bottleneck | Cost | Verdict | Source |
|---|---|---|---|
| Per-bytecode-op dispatch | 9–22 ns (switch) / 70 ns (closure) | switch wins; rule 5 | `validate-hotloop.php` |
| Operand stack push/pop | 9 ns (regular array) | regular array wins; rule 3 | `validate-datastructures.php` |
| Frame state (`$stack`, `$sp`, `$L`, `$pc`) | 11 ns (locals) vs 143 (array frame) | locals win 13×; rule 2 | `validate-datastructures.php` |
| Bytecode read | 5 ns/byte (int array) vs 14 (string+ord) | pre-decode wins; rule 1 | `validate-datastructures.php` |
| Primitive boxing | 1× scalar / 7–9× wrapped | gut it; see `BOXING.md` | `validate-boxing.php` |
| Per-method call setup (current PHPJava) | ~95 µs/call | dominant; M1 fopen+fwrite | `bench/profile-c930e2c.md` |

### B. String operations

| Operation | Cost | Notes |
|---|---|---|
| `strlen()` | 11 ns | ≈ String.length() |
| `strpos()` | 24 ns | ≈ String.indexOf() |
| `substr()` | 24 ns | ≈ String.substring() |
| `str_replace()` | 67 ns | ≈ String.replace() |
| `.` concat | 30 ns | per-concat — Java 9+ indy String concat is ~similar |
| `sprintf()` | 9 ns | when format simple |
| `strcmp()` | 19 ns | ≈ String.compareTo() |
| `crc32()` | 26 ns | ≈ String.hashCode() (Java's is more complex but ~same cost class) |
| `mb_strlen()` UTF-8 | 34 ns | 3× strlen — UTF-aware ops cost more |
| `explode()` | 133 ns | ≈ String.split() |

**Verdict:** PHP's string ops are fast. Java String API maps to PHP
string functions naturally. **Not a bottleneck for typical code.**

The one consideration: Java strings are UTF-16; PHP strings are bytes.
For correct semantics on non-ASCII data, use mbstring functions (3×
slower but correct). For ASCII-only paths, byte ops are fine.

### C. Method invocation (cross-method dispatch)

| Pattern | Cost |
|---|---|
| Direct static call `Class::method()` | 11 ns |
| Instance method `$obj->method()` | 13 ns |
| Final instance method | 14 ns |
| `call_user_func_array()` | 83 ns |
| `ReflectionMethod::invoke()` (instance) | 40 ns |
| `ReflectionMethod::invoke()` (static) | 34 ns |
| Chained invoker `$inv->call(...)` | 56 ns |

**Verdict:**
- Direct calls: fast. ~11–14 ns.
- Reflection: moderate (34–40 ns). Acceptable for non-hot paths.
- **Avoid `call_user_func_array`** — 83 ns (6× direct).
- The chained invoker pattern (current PHPJava: `getInvoker()->getStatic()->getMethods()->call()`) costs 56 ns (5× direct) **per Java method call**. After the rewrite, this becomes one direct PHP method call (`$invoker->call($name, ...$args)`) at ~13 ns.

**Bottleneck:** the chained invoker is a hot-path tax. The contract refactor in `CONTRACTS.md` §3 collapses it to one method call.

### D. Exception throw + catch

| Pattern | Cost |
|---|---|
| `throw new Exception('msg')` + catch | 163 ns |
| `throw $preallocated` + catch | 34 ns |
| `try { ... } catch (...)` (no throw) | 8 ns |
| `if (...) ...` pre-check | 9 ns |

**Verdict:**
- New exception allocation dominates: 163 ns/throw.
- Pre-allocated exceptions: 34 ns. **5× cheaper.**
- Try-block overhead alone: ~8 ns. Cheap.

**Bottleneck:** Java code that throws frequently in normal operation
(parsers, validators) pays 163 ns/throw. **Pre-allocate common
exceptions** (NumberFormatException, NullPointerException, IllegalArgumentException)
and reuse instances when stack traces aren't needed.

For Java code that uses exceptions for control flow (rare in modern
code), the throw cost dominates. There's no clean fix; recommend the
JVM's general advice — don't use exceptions for non-exceptional flow.

### E. Numeric arithmetic + overflow

| Pattern | Cost |
|---|---|
| Raw PHP int `+` | 8 ns |
| With Java 32-bit overflow mask + sign extend | 9 ns |
| With PHP_INT_MAX overflow check | 8 ns |
| GMP add (when available) | (~50 ns from past measurements) |
| bcmath add | 70 ns |

**Surprise finding:** Java-correct int overflow handling is essentially
free.

```php
// Java int + with correct overflow:
$r = (($a + $b) & 0xFFFFFFFF);
if ($r & 0x80000000) $r -= 0x100000000;
// 1 ns slower than raw $a + $b
```

**Verdict:** preserving Java int overflow semantics costs 1 ns/op. We
can default to Java-correct semantics without significant penalty.

For long arithmetic on 64-bit PHP, no masking needed (Java long IS
64-bit signed, same as PHP int on 64-bit systems).

For BigInteger: GMP or PHP-int-with-explicit-multiprecision. ~50 ns/op
either way. Acceptable for code that uses BigInteger rarely (most code).

For BigDecimal: bcmath at 70 ns/op is the practical floor in PHP.
Java BigDecimal is similar. **Not a regression.**

### F. Static field access

| Pattern | Cost |
|---|---|
| `Class::CONST` | 8 ns |
| `Class::$staticProp` read | 9 ns |
| `Class::$staticProp` write | 12 ns |
| `$GLOBALS['key']` read | 11 ns |
| `$GLOBALS['key']` write | 12 ns |

**Verdict:** All fast. Class statics are the natural shape for Java
static fields. cljp's `$GLOBALS` pattern costs ~3 ns more per access
than class statics — not a perf reason to choose one over the other.

### G. Array allocation

| Pattern | Cost |
|---|---|
| `[]` empty | 8 ns |
| Array literal `[0,0,0,0,0]` | 8 ns |
| `array_fill(0, 100, 0)` (Java `new int[100]`) | 108 ns |
| `new SplFixedArray(100)` | 162 ns |
| `str_repeat("\x00", 100)` (byte buffer) | 9 ns |
| `range(0, 99)` | 63 ns |

**Verdict:**
- Empty array creation: free (8 ns).
- 100-element zero-fill: 108 ns. Linear in size.
- SplFixedArray: 1.5× slower than array_fill. **Drop SplFixedArray everywhere.**
- For byte-array equivalents (`byte[]`): `str_repeat("\x00", N)` at ~9 ns is essentially free. Use this for `byte[]` with `ord()` to read.

**Bottleneck:** allocating many large arrays (e.g., I/O buffers) costs
~1 ns per element. `byte[]` arrays should use PHP strings, not arrays
of ints — 10× faster.

### H. Type checks

| Pattern | Cost |
|---|---|
| `instanceof Class` | 14 ns |
| `instanceof Class` (false) | 11 ns |
| `is_int($x)` | 11 ns |
| `is_string($x)` | 11 ns |
| `is_array($x)` | 10 ns |
| `gettype($x) === 'integer'` | 12 ns |
| `get_class($obj) === '...'` | 15 ns |

**Verdict:** All ~10–15 ns. **Type checks are cheap.** Java's
`instanceof` and PHP's native checks are equivalent in cost.

The boxing-mitigation strategy (BOXING.md) emits `is_int($o)` for
`instanceof Integer` against an Object slot — 11 ns, same as
`instanceof`. No perf cost to the PHP-native approach.

### I. System clock / ID

| Pattern | Cost |
|---|---|
| `microtime(true)` | 28 ns |
| `hrtime(true)` | 29 ns |
| `microtime()` (string form) | 142 ns |
| `random_int(0, 100)` | 244 ns |
| `mt_rand(0, 100)` | 17 ns |
| `spl_object_id($obj)` | 12 ns |

**Verdict:** clock fast (28–29 ns). `System.currentTimeMillis()` →
`(int) (microtime(true) * 1000)` ~30 ns total. `Object.identityHashCode()`
→ `spl_object_id()` ~12 ns.

**Bottleneck:** `random_int` is slow (244 ns). It's cryptographically
secure; for non-crypto use cases (Java `Math.random()`), use
`mt_rand` (17 ns) — 14× faster.

## Other bottlenecks (not directly measured but predictable)

### J. Class initialization (`<clinit>`)

Runs once per class, can be expensive. If `clojure.lang.RT.<clinit>`
loads many classes transitively, this can hit hundreds of milliseconds
on first access.

**Mitigation:**
- Eager AOT compilation: `<clinit>` is just regular PHP code; opcache
  caches the parsed/JIT'd version
- Lazy loading: don't run `<clinit>` until first reference
- Class init guard: track per-class "initialized" boolean

Not a steady-state bottleneck; one-time startup cost. Acceptable as long
as it's amortized.

### K. Constant pool resolution

Every method call references the constant pool by index for the target
class + name + descriptor. Triple indirection per call.

**Mitigation:** at AOT time, resolve constant pool references to direct
PHP names. The emitted PHP calls `Foo::bar(...)` directly rather than
`$cp[5]->resolveAndCall(...)`.

**Effect:** zero constant-pool resolution cost in AOT'd code. Interpreter
fallback pays per-call resolution but the resolved name can be cached.

### L. Virtual method dispatch

INVOKEVIRTUAL needs to find the most-derived implementation for a class
hierarchy. PHP method dispatch handles this natively (PHP follows the
dynamic type's method table).

**Mitigation:** map Java's class hierarchy onto PHP's class hierarchy.
PHP virtual dispatch IS Java virtual dispatch. ~13 ns/call (table D).

For interfaces: PHP interfaces work; emitted method call uses native
dispatch. No special handling needed.

### M. invokedynamic + bootstrap methods

The first call to an indy site invokes a bootstrap method that returns
a CallSite. Subsequent calls go through the CallSite.

**Mitigation:**
- Cache the CallSite per indy site (one-time bootstrap cost)
- For lambda metafactory specifically: synthesize a PHP closure at
  bootstrap time
- For StringConcatFactory: synthesize a PHP function that does the concat

**Cost:** first call: ~1–10 µs (bootstrap method execution). Subsequent
calls: ~13 ns (just a function call through cached CallSite).

### N. Exception table search

When an exception is thrown, walk the method's exception table to find
a matching handler.

**Mitigation:** at AOT time, exception tables become PHP try/catch
blocks. Native PHP exception machinery handles the search.

**Cost:** PHP exception throw = ~163 ns (with new alloc) or 34 ns (pre-alloc).
Same cost class as Java; not a regression.

### O. Synchronization (`MONITORENTER` / `MONITOREXIT`)

Java's `synchronized` block.

**Mitigation:**
- Single-threaded PHP: no-op (one Fiber, no contention)
- Swoole: `Swoole\Mutex` per Java object, lazy-allocated
- Multi-process: file locks (slow but correct)

**Cost:** single-threaded: 0 ns (no-op). Swoole mutex: ~50 ns/lock pair.

### P. Reflection-heavy frameworks

Spring, Hibernate, Jackson use heavy reflection. ReflectionMethod::invoke
is 34 ns/call vs 13 ns direct (3× cost). Acceptable for framework code
that's not in tight loops.

**Mitigation:** AOT specialization where possible. For frameworks
beyond what we'd typically run on shared hosting, accept the overhead.

## The type emulation question

You asked: how to handle Java's type system in PHP — emulating types via
classes, pure globals, the numeric tower. Three sub-questions.

### Q1. Should Java types have PHP wrapper classes?

**No, not as runtime instances.** See `docs/BOXING.md`. Wrapper instances
nowhere; wrapper classes exist only as namespaces of static methods and
reflection metadata.

| Java type | PHP runtime representation | PHP class exists? |
|---|---|---|
| `boolean` | PHP `bool` | `Boolean` class for static methods (`Boolean::valueOf`, `Boolean::parseBoolean`) |
| `int` | PHP `int` | `Integer` class same |
| `long` | PHP `int` (64-bit on most systems) | `Long` class same |
| `short`, `byte` | PHP `int` (range-checked at narrowing) | `Short`, `Byte` classes same |
| `char` | PHP `string` (1 char) | `Character` class same |
| `float`, `double` | PHP `float` | `Float`, `Double` classes same |
| `String` | PHP `string` | `\PHPJava\Packages\java\lang\String_` class for static helpers |
| `BigInteger` | GMP `\GMP` (when available) or PHP `\PHPJava\BigInt` (fallback) | real class with instances |
| `BigDecimal` | `\PHPJava\BigDec` (wraps bcmath or array-of-digits) | real class with instances |
| `Object` | PHP `object` | hierarchy starts here |
| user types | PHP `class` | direct map |

The principle: **primitives stay primitives; arbitrary-precision and
user types are real PHP classes.**

### Q2. What about pure globals (cljp pattern)?

cljp uses `$GLOBALS['ns\name'] = closure` for redefinable Vars. Measured
~11 ns/access vs ~9 ns for static class properties — 25% slower but
gives runtime redefinability.

**For PHPJava, no.** Java doesn't have runtime redefinability natively
(see `docs/MODEL.md`). Static class properties are ~3 ns faster per
access and IDE-friendly. **Use `Class::$staticProp` for Java static
fields.**

### Q3. The numeric tower

Java has int, long, short, byte, float, double, char, boolean — primitives.
Plus their wrappers. Plus BigInteger (arbitrary int) and BigDecimal
(arbitrary decimal).

**Mapping:**

```
Java primitive  →  PHP type           Range / precision

boolean         →  bool               same
byte            →  int (range -128..127, masked at narrow)
short           →  int (range, masked)
char            →  string (1 char) OR int (UTF-16 codepoint, configurable)
int             →  int (Java 32-bit, PHP 64-bit, optional mask)
long            →  int (both 64-bit on modern hosts)
float           →  float (Java 32-bit IEEE 754, PHP 64-bit)
double          →  float (both 64-bit IEEE 754)
```

**Overflow handling:** measured 1 ns to mask back to 32-bit Java semantics.
**Default to Java-correct overflow** in AOT-emitted code.

**Float precision:** PHP float is 64-bit; Java float is 32-bit. Loading
a Java float into PHP float is exact; storing back is lossy at 32-bit
boundaries. AOT can emit explicit narrowing where the descriptor demands.

**BigInteger / BigDecimal:**

```
Java                →  PHP

BigInteger          →  GMP (extension; widely available)
                       Fallback: \PHPJava\Numeric\BigInt (PHP class wrapping
                       array of digits or string)
                       Cost: ~50 ns/op via GMP

BigDecimal          →  bcmath (extension; widely available)
                       Fallback: \PHPJava\Numeric\BigDec (PHP class)
                       Cost: ~70 ns/op via bcmath
```

These are real PHP classes (or GMP resources) with instances. They
**are** the wrappers Java code expects. No special handling needed —
they map directly.

**Capability detection at startup:**

```php
if (extension_loaded('gmp')) {
    \PHPJava\Boot::useGmpBigInt();
} else {
    \PHPJava\Boot::usePhpBigIntFallback();  // 5-10× slower but always works
}
```

`docs/CONTRACTS.md` should list these capability tiers explicitly.

## Recommendations summary

| Surface | Hot-path approach |
|---|---|
| Per-op dispatch | switch in static function (10 ns) |
| Operand stack | regular PHP array (9 ns/op) |
| Frame state | PHP locals (11 ns for 4 ops) |
| Primitives | PHP scalars; no wrappers |
| String ops | native PHP functions (~10-30 ns) |
| Method calls | direct (`Class::m()` or `$obj->m()`) — 11-13 ns |
| Exceptions | pre-allocate common ones (5× cheaper) |
| Int overflow | mask + sign extend (1 ns extra; Java-correct) |
| BigInteger | GMP if available; PHP fallback (~50-100 ns/op) |
| BigDecimal | bcmath; ~70 ns/op |
| Static fields | `Class::$prop` not `$GLOBALS` |
| Array creation | `[]` for empty (8 ns); `array_fill` for fixed-size (108 ns/100); `str_repeat` for byte[] (9 ns) |
| Type checks | native `is_*` and `instanceof` — all ~10-15 ns |
| Reflection | OK for non-hot paths; ~34 ns; cache method handles when used repeatedly |
| invokedynamic | cache CallSite per site after bootstrap |
| Synchronization | no-op single-threaded; Swoole mutex when concurrent |

| Surface | Avoid |
|---|---|
| `call_user_func_array` | 6× direct call cost; use direct dispatch |
| `SplFixedArray` | universally slower than regular array |
| `stdClass` for hot-path state | 2× cost of typed object |
| Frame-state-as-object | 13× cost of locals |
| Per-iter `new` | breaks JIT trace |
| `random_int` for non-crypto | use `mt_rand` (14× faster) |
| String UTF-16 emulation | use PHP byte strings + mbstring at semantic boundaries |
| Wrapper instances at runtime | gut entirely (BOXING.md) |

## What's NOT a bottleneck (but I half-expected to be)

- **String operations:** PHP string ops are 10-30 ns. Java String API
  maps cleanly. Not a concern.
- **Type checks:** native PHP type checks are ~11 ns. Faster than I
  feared.
- **Static field access:** ~9 ns. Essentially free.
- **Int overflow handling:** 1 ns extra. Free.
- **PHP method dispatch:** direct calls 11-13 ns. JIT'd to near-direct.
- **Class init triggers:** one-time; opcache amortizes.

## What IS a bottleneck (and how each is handled)

- **Wrapper boxing:** 7-9× per arithmetic op → **gut it** (BOXING.md).
- **Per-method call setup (current PHPJava):** ~95 µs/call → **rewrite
  per PATTERNS.md** (collapse to one direct call ~13 ns).
- **Per-opcode dispatch (current PHPJava):** ~5.2 µs/op → **switch in
  static function** ~10 ns.
- **Exception with new alloc:** 163 ns/throw → **pre-allocate** common
  exceptions for ~5× speedup.
- **Reflection-heavy code:** 34 ns per invoke → cache MethodHandles.

All have known mitigations. None are deal-breakers.

## What this means for the project scope

The bottleneck audit confirms the existing scope is right:

- The 3-week refactor (per `docs/STATUS.md`) addresses the dominant
  bottlenecks.
- The boxing gut (per `docs/BOXING.md`) is the highest-ROI change.
- The pattern rules (per `docs/PATTERNS.md`) prevent regression.
- Type emulation maps cleanly: primitives to PHP scalars, BigInteger/
  BigDecimal to GMP/bcmath wrappers, everything else to direct PHP
  classes.

There are no surprise bottlenecks that change the architecture. Every
remaining concern has a known mitigation; most are bounded by the work
already in the roadmap.

# JVM ↔ PHP/Zend platform delta

> Date: 2026-05-01.
> Comprehensive analysis of platform, semantic, and API differences
> between the JVM and PHP/Zend, plus version-evolution timelines for
> both. Companion to `docs/CONTRACTS.md` and `docs/GAP-JDK.md`.

This document catalogs the gaps PHPJava is bridging — what the JVM
provides that PHP doesn't, what PHP provides that JVM doesn't, and
how the deltas evolve over their respective version histories. Where
relevant, it indicates how PHPJava handles each delta.

---

## 1. Platform comparison

| Axis | JVM | PHP/Zend |
|---|---|---|
| Execution model | bytecode interpreter + tiered JIT | tree-walking interpreter (Zend opcodes via opcache; JIT optional in 8.0+) |
| Process model | long-running daemon, accumulates state | request-per-process traditional; Swoole/AMPHP/RoadRunner make long-running viable |
| Memory model | heap allocation, sophisticated GC (G1, ZGC, Shenandoah) | refcount + cycle collector |
| Threading | real OS threads, java.util.concurrent, atomics, locks, virtual threads (21+) | single-threaded per request; Fibers (8.1+) for cooperative concurrency; Swoole for OS-level coroutines |
| Reference types | strong / soft / weak / phantom; explicit GC interaction | refcount only; PHP 7.4+ adds `WeakReference` and `WeakMap` |
| Compilation unit | `.class` file (per class, structured binary) | `.php` file (source); opcache caches parsed Zend opcodes |
| Standard library | OpenJDK: thousands of classes (`java.*`, `javax.*`, `jdk.*`) | core + extensions: thousands of functions, hundreds of classes |
| Module system | Java 9+: explicit exports/requires (`module-info.class`) | namespaces + Composer for dependency management |
| Versioning | language version, class file version, runtime API version | language version + per-extension versioning |
| Native interop | JNI, Foreign Linker (Java 22+) | FFI (PHP 7.4+), C extensions (zend_module) |
| Verification | bytecode verifier validates type safety at load time | no equivalent; trust input |
| Hot reload | Java agents + Instrumentation API | manual file replace; opcache.revalidate_freq |

---

## 2. Type system delta

### Static vs dynamic

| | JVM | PHP |
|---|---|---|
| Static typing | yes; checked by `javac` and JVM verifier | optional; type hints checked at runtime |
| Type inference | local-var only (`var`, Java 10+) | `phpstan`/`psalm` infer at static-analysis time |
| Generics | type erasure (compile-time only; runtime is raw) | none in language; phpstan-style annotations only |
| Variance | covariant arrays (broken; ArrayStoreException), invariant generics + bounded wildcards | no generics → no variance |
| Type juggling | no — strict at runtime | yes (`'5' == 5`, etc.) |
| Nullability | reference types nullable; primitives not | typed parameters nullable via `?T` since 7.1; properties since 7.4 |

### Primitive types

| JVM type | Bytes | PHP equivalent | PHP cost |
|---|---|---|---|
| `boolean` | 1 (4 in stack frames) | `bool` | matches |
| `byte` | 1 | `int` (range -128..127) | needs masking at narrowing |
| `short` | 2 | `int` (range -32768..32767) | needs masking |
| `char` | 2 (UTF-16 unit) | `string` (1 char) OR `int` | configurable |
| `int` | 4 | `int` (PHP int is 64-bit on most hosts) | overflow mask costs 1 ns |
| `long` | 8 | `int` (matches on 64-bit hosts) | matches |
| `float` | 4 (IEEE 754 binary32) | `float` (PHP `float` is 64-bit) | precision lost on assignment to Java float field |
| `double` | 8 (IEEE 754 binary64) | `float` | matches |

PHPJava maps Java primitives to PHP scalars per `docs/CONTRACTS.md` §1.

### Reference types

JVM has `Object` as universal supertype. PHP has no equivalent — every
type (int, string, object, array, etc.) is its own kind, joined only
by `mixed`. The AOT compiler tracks Object-slot usage statically.

### Boxing

JVM: `Integer`, `Long`, `Double` etc. wrap primitives for use in
generics / Object slots. Cache for -128..127 (Integer.valueOf).

PHP equivalent: pure unboxing. See `docs/BOXING.md`. Wrapper *classes*
exist as namespaces of static methods + reflection metadata; never
instantiated.

---

## 3. Memory and GC delta

| Concern | JVM | PHP |
|---|---|---|
| Allocation | heap via `new`; stack-allocated locals; escape analysis at JIT time | refcount alloc; no stack vs heap distinction at language level |
| Reclamation | generational GC; concurrent collectors (G1, ZGC, Shenandoah) | refcount + cycle collector |
| Reference strength | strong, soft, weak, phantom + finalize() | strong (refcount default); WeakReference (7.4+); WeakMap (8.0+) |
| Manual control | `System.gc()` (advisory); `Runtime.totalMemory()` etc. | `gc_collect_cycles()` (advisory); `memory_get_usage()` |
| Out-of-memory | `OutOfMemoryError` with class hierarchy | fatal error; `memory_limit` ini directive |
| Off-heap | direct ByteBuffer, Foreign Memory API (22+) | FFI memory allocation |
| Finalization | `finalize()` deprecated; `Cleaner` since 9 | `__destruct()` (more deterministic than Java's finalize) |

PHPJava maps Java's reference types onto PHP equivalents:
- Strong → refcount default
- WeakReference → PHP `\WeakReference` (7.4+)
- WeakHashMap → PHP `\WeakMap` (8.0+)
- SoftReference → manual registry + memory pressure check
- PhantomReference → destructor + queue

---

## 4. Concurrency delta

### What JVM provides natively

- Real OS threads via `java.lang.Thread`
- Synchronized blocks + monitors per Object
- `java.util.concurrent.atomic.*` — CAS, fetch-and-add, etc.
- `java.util.concurrent.locks.*` — ReentrantLock, ReadWriteLock
- `java.util.concurrent.*` — ExecutorService, ConcurrentHashMap, etc.
- Virtual threads (Java 21+) — millions of cheap threads
- Memory model with happens-before semantics
- Volatile and acquire/release fence semantics
- `MethodHandle.VarHandle` for low-level memory access

### What PHP provides

| PHP feature | Available since | Notes |
|---|---|---|
| Single-threaded per request | always | the default |
| `pcntl_fork` | 4.x | process-level concurrency, not threads |
| `pthreads` extension | 5.3+ | deprecated as of PHP 7.2 |
| `parallel` extension | 7.2+ | replaces pthreads |
| Fibers | 8.1+ | cooperative concurrency, single-threaded |
| Swoole extension | varies | OS-level coroutines + atomics |
| AMPHP libraries | varies | Fiber-based async |

PHPJava's concurrency strategy (per `docs/CONTRACTS.md` §8):
- `Thread.start()` → spawn Fiber (PHP 8.1+) or Swoole coroutine
- `synchronized` → mutex (Swoole or PHP-locked simulation)
- `AtomicReference` → Swoole atomic when present, simulated CAS otherwise
- `volatile` → no-op single-threaded; barrier on Swoole

---

## 5. Class system delta

### Visibility

| JVM | PHP | Map |
|---|---|---|
| public | public | matches |
| protected | protected | matches |
| private | private | matches |
| package-private (default) | (no equivalent) | emit as protected; document trust boundary |

### Modifiers

| JVM | PHP | Map |
|---|---|---|
| `final class` | `final class` (5.0+) | matches |
| `abstract class` | `abstract class` | matches |
| `final method` | `final` keyword | matches |
| `abstract method` | `abstract` (in abstract class) | matches |
| `static method` | `static` | matches |
| `synchronized method` | (no equivalent) | wrap in synchronized block emulation |
| `native method` | (no equivalent) | bind to PHP shim implementation |
| `strictfp` | (no equivalent) | n/a (PHP doesn't have float strictness modes) |
| `transient` field | (no equivalent) | n/a (PHP serialization differs) |
| `volatile` field | (no equivalent) | wrap with atomic accessors when relevant |

### Inheritance shape

Both JVM and PHP have:
- Single inheritance from concrete classes
- Multiple interface implementation
- Abstract classes
- Interfaces with default methods (PHP 8.0+ for default methods? actually PHP doesn't quite have this — interfaces only declare; default impl needs trait or abstract class)

JVM has but PHP doesn't:
- Sealed classes (Java 17+) — PHP has no equivalent; emit as `final` + documented restriction
- Records (Java 16+) — PHP 8.2+ has `readonly` classes which are similar; not identical

PHP has but JVM doesn't:
- Traits — for code reuse without inheritance; we don't translate Java to traits
- Magic methods (`__get`, `__set`, `__call`, `__invoke`, `__toString`) — useful for shim impls

---

## 6. Reflection delta

### What JVM provides

| Class | Purpose |
|---|---|
| `java.lang.Class` | class metadata |
| `java.lang.reflect.Method` | method invocation |
| `java.lang.reflect.Field` | field access (with `setAccessible`) |
| `java.lang.reflect.Constructor` | constructor invocation |
| `java.lang.reflect.Modifier` | modifier flags |
| `java.lang.reflect.Proxy` | dynamic proxy creation |
| `java.lang.reflect.Array` | array creation/access via reflection |
| `java.lang.invoke.MethodHandle` | low-level invocation |
| `java.lang.invoke.VarHandle` | atomic memory access |
| `java.lang.invoke.MethodHandles.Lookup` | private/package access |

### What PHP provides

| Class | Purpose |
|---|---|
| `ReflectionClass` | class metadata |
| `ReflectionMethod` | method invocation (with `setAccessible`) |
| `ReflectionProperty` | property access |
| `ReflectionFunction` | function invocation |
| `ReflectionParameter` | parameter info |
| `ReflectionType` (PHP 7.0+) | type info |
| `ReflectionAttribute` (PHP 8.0+) | attribute reading |
| `ReflectionEnum` (PHP 8.1+) | enum metadata |

PHP reflection covers most of JVM's surface. PHPJava implements
`java.lang.reflect.*` as PHP shims that delegate to PHP reflection
where possible.

Cost (measured): `ReflectionMethod::invoke` is ~34-40 ns on PHP 8.5
JIT — 3× slower than direct call but acceptable.

---

## 7. Exception handling delta

### Hierarchy

JVM:
```
Throwable
├── Error (system errors — typically fatal)
│   ├── OutOfMemoryError
│   ├── StackOverflowError
│   └── ...
└── Exception (recoverable)
    ├── RuntimeException (unchecked)
    └── (checked exceptions)
```

PHP (8.0+):
```
Throwable (interface)
├── Error
│   ├── TypeError
│   ├── ValueError
│   ├── ArithmeticError
│   └── ...
└── Exception
    ├── RuntimeException
    └── ...
```

**Key differences:**

| Feature | JVM | PHP |
|---|---|---|
| Checked exceptions | yes — must declare or catch | no — all exceptions are runtime |
| `try-finally` | yes | yes |
| `try-with-resources` | yes (Java 7+) | no (use destructor or manual cleanup) |
| Multi-catch | yes (Java 7+) | yes (PHP 8.0+) |
| Exception chaining | `getCause()` | constructor's `$previous` parameter |
| Custom subclassing | yes | yes |

PHPJava maps `java.lang.Throwable` hierarchy to PHP `\Throwable` so
PHP's catch clauses match Java's. Checked-exception declarations are
informational only at runtime.

---

## 8. String handling delta

### Internal representation

| | JVM | PHP |
|---|---|---|
| Encoding | UTF-16 internally | byte sequence (no encoding declaration in language) |
| Mutability | immutable (`String`); `StringBuilder`/`StringBuffer` for mutable | strings are immutable in semantics (operations return new) |
| Length | char count (UTF-16 units, possibly surrogate pairs) | byte count |
| Index | UTF-16 char index | byte index |
| Concatenation | `+` operator (Java 9+ uses `StringConcatFactory` indy) | `.` operator (compile-time fast) |
| Comparison | `equals()` for value, `==` for identity | `===` for value+type, `==` for loose |
| Interning | `String.intern()` returns canonical instance | no native intern; use array as cache |

### PHP correctness with non-ASCII

PHP strings are bytes. For correct multi-byte semantics:
- `mbstring` extension for UTF-8 length/substring/regex
- `intl` extension for locale-aware operations

PHPJava maps:
- `String.length()` → `strlen($s)` for ASCII-fast path; `mb_strlen($s, 'UTF-8')` when needed
- `String.charAt(i)` → with UTF-16 simulation cost; or document as "UTF-8 byte at i"
- `String.indexOf(s)` → `strpos($haystack, $needle)`
- `String.substring(a, b)` → `substr($s, $a, $b - $a)`
- `String.equals(other)` → `$a === $b`

The full `String` API has ~70 methods. Most map cleanly; a few (UTF-16
codepoint operations) need explicit emulation.

---

## 9. Numeric / arithmetic delta

### Integer overflow

Empirically verified 2026-05-01 (PHP 8.5.2, OpenJDK 25):

| | JVM | PHP |
|---|---|---|
| `int` overflow | **wraps** (32-bit two's complement) — verified `Integer.MAX_VALUE + 1 = Integer.MIN_VALUE` | n/a (PHP int is 64-bit) |
| `long` overflow | **wraps** (64-bit two's complement) — verified `Long.MAX_VALUE + 1 = Long.MIN_VALUE` | **promotes to float** — verified `PHP_INT_MAX + 1` is `double 9.22e18` |
| `Math.addExact` | throws ArithmeticException — verified | manual check needed |
| Division by zero (int) | ArithmeticException — verified | **`DivisionByZeroError`** — verified PHP 8+ throws |
| Modulo with negative dividend | sign of dividend (`-7 % 3 = -1`) — verified | **same** — verified PHP `-7 % 3 = -1` |
| `Math.floorMod(-7, 3)` | non-negative result `2` — verified | n/a (use bcmath or manual) |
| NaN equality | `NaN == NaN` is `false`; `Double.NaN.equals(NaN)` is `true` — verified | `NaN == NaN` is `false`; `NaN === NaN` is `false` — verified (PHP `===` matches Java `==`, NOT Java `.equals()`) |

**Key divergence:** PHP int overflow promotes to float; Java int wraps.
For Java semantics, AOT emits `& 0xFFFFFFFF` mask + sign extend
(measured 1 ns extra; see `bench/validate-remaining.php`).

**Verified Integer cache range:** `Integer.valueOf(127) == Integer.valueOf(127)` is `true`; `valueOf(128) == valueOf(128)` is `false`. Cache is exactly -128..127 inclusive. `Integer.hashCode()` returns the int value directly.

PHPJava emits the 32-bit mask + sign-extend for Java int correctness.
Cost: ~1 ns per arithmetic op (measured in `bench/validate-remaining.php`).

### Floating point

| | JVM | PHP |
|---|---|---|
| `float` | IEEE 754 binary32 | IEEE 754 binary64 (PHP `float` is double-precision) |
| `double` | IEEE 754 binary64 | IEEE 754 binary64 |
| NaN | `Float.NaN`, `Double.NaN`; equality bizarre (`NaN != NaN`) | `NAN`; same equality semantics |
| Infinity | `Double.POSITIVE_INFINITY` etc. | `INF` / `-INF` |
| Strict math | `strictfp` was a thing (deprecated Java 17) | n/a |
| `Math.*` | full library | core PHP `abs`, `sqrt`, `pow`, `floor`, etc. + ext-math |

Java float assignment requires narrowing from PHP's 64-bit float to
32-bit. AOT emits explicit narrowing where Java semantics demand.

### Big number support

| | JVM | PHP |
|---|---|---|
| `BigInteger` | core stdlib (`java.math.BigInteger`) | GMP extension (widely available); fallback PHP class |
| `BigDecimal` | core stdlib (`java.math.BigDecimal`) | bcmath extension; fallback PHP class |

---

## 10. Method dispatch delta

### Invocation types

JVM has 5 INVOKE* bytecodes:

| Bytecode | What it does | PHP equivalent |
|---|---|---|
| `INVOKESTATIC` | static method | `Class::method()` |
| `INVOKESPECIAL` | constructor / private / super | direct call (no virtual lookup) |
| `INVOKEVIRTUAL` | instance method, virtual dispatch | `$obj->method()` |
| `INVOKEINTERFACE` | interface method | `$obj->method()` |
| `INVOKEDYNAMIC` | bootstrap-method-resolved call site | bootstrap then cached call |

PHP method dispatch matches Java's virtual semantics for instance and
interface calls. INVOKESPECIAL needs explicit non-virtual emission.
INVOKEDYNAMIC requires bootstrap method runtime.

### Call performance (PHP 8.5 + JIT)

| Pattern | ns/op |
|---|---|
| Direct static call `Class::method()` | 11 |
| Instance method `$obj->method()` | 13 |
| Final method | 14 |
| `call_user_func_array()` | 83 |
| `ReflectionMethod::invoke()` | 34 |

Direct dispatch is fast; reflection acceptable for non-hot paths;
`call_user_func_array` should be avoided in hot loops.

---

## 11. Generics delta

### JVM generics

- Type erasure: `List<Integer>` becomes `List` at runtime
- Bridge methods for covariant overrides
- Bounded wildcards: `List<? extends Number>`, `List<? super Integer>`
- Generic methods: `<T> T identity(T x)`
- Generic type witness: `Class<T>` token

### PHP generics

- None in language
- Phpstan/psalm/phan annotations: `@template T`, `@param array<int, T>`
- Static-analysis-only

### How PHPJava handles it

Type erasure means **runtime sees `List`, not `List<Integer>`**. The
PHPJava shim for `ArrayList<T>` is just `ArrayList` — no generic
parameters. The `<T>` exists only in the bytecode's generic signature
attribute, used by reflection-based libraries.

For shim implementations, type information is informational. Java code
expecting `List<Integer>` actually got a List with Integer values; the
runtime check happens at `add()` (autoboxing) and `get()` (autounboxing).

---

## 12. Module system delta

### Java modules (Java 9+)

- `module-info.java` declares exports + requires
- Classes not in exported packages are inaccessible across modules
- `java.base`, `java.desktop`, `java.sql`, etc. are modules

### PHP equivalent

None at language level. Composer + namespaces fill the gap loosely.

PHPJava's approach:
- Parse `module-info.class` per `docs/GAP-JDK.md`
- Module access enforcement: deferred (out of scope; modules are
  a deployment concern, not a runtime concern)
- Modular JARs load with their classes; module-level access checks
  are not enforced

---

## 13. Native interop delta

### JVM

- JNI: C/C++ functions callable from Java
- Foreign Function & Memory API (Java 22+): pure-Java FFI
- `Unsafe`: low-level memory access (sun.misc, deprecated)
- VarHandle: typed atomic memory operations

### PHP

- FFI extension (PHP 7.4+): pure-PHP FFI to C libraries
- C extensions (zend_module): compile against PHP for native access
- POSIX functions via ext-posix
- Sockets via ext-sockets

PHPJava:
- `Unsafe` shim: PHP-level emulation (when no FFI), or FFI-backed (when available)
- JNI: out of scope (we don't run actual JNI native libraries)
- `Foreign Linker`: when both Java code uses it and PHP FFI is available, can map. Niche.

---

## 14. Standard library mapping (selective)

### `java.lang.*` core types

| Java | PHP target |
|---|---|
| `Object` | `\PHPJava\Packages\java\lang\Object_` (or just any PHP object) |
| `String` | PHP string + `\PHPJava\Packages\java\lang\String_` static helpers |
| `Class<T>` | `\PHPJava\Packages\java\lang\Class_` (reflection facade) |
| `Integer`, `Long`, `Double`, `Float`, `Boolean`, `Character`, `Byte`, `Short` | static-method namespaces (no instances) |
| `Number` (abstract) | static-method namespace |
| `Math`, `StrictMath` | static helpers wrapping PHP `abs`, `sqrt`, `pow`, etc. |
| `System` | static helpers: `currentTimeMillis()`, `nanoTime()`, `getProperty()` |
| `Thread` | wrapper around Fiber/Swoole coroutine |
| `Throwable`, `Exception`, `Error`, all subclasses | extend `\Throwable`/`\Exception`/`\Error` so PHP catch matches |
| `StringBuilder`, `StringBuffer` | shim with array-based building |

### `java.util.*` collections

| Java | PHP target |
|---|---|
| `List`, `ArrayList`, `LinkedList` | shim wrapping PHP indexed array |
| `Map`, `HashMap`, `LinkedHashMap` | shim wrapping PHP assoc array |
| `Set`, `HashSet`, `TreeSet` | shim wrapping PHP array (keys as values) |
| `Iterator`, `Iterable` | wrap PHP `Iterator` |
| `Collection` operations | helper methods on shims |
| `Optional` | thin class wrapping nullable value |
| `Stream` | shim with lazy evaluation; `array_map`/`array_filter` underneath where feasible |

Per `docs/CONTRACTS.md` §1, collection shims expose both the
JVM-shaped surface (ArrayAccess, Countable, etc.) and a public
underlying-array property for PHP-side hot paths.

### `java.io.*` / `java.nio.*`

| Java | PHP target |
|---|---|
| `InputStream`, `OutputStream` | thin wrapper around `fopen`/`fread`/`fwrite` |
| `Reader`, `Writer` (UTF-decoding) | wrapper with explicit charset |
| `BufferedReader`, `BufferedWriter` | wrapper with buffering |
| `File`, `Path` | wrapper around PHP filesystem functions |
| `Files.read*` / `Files.write*` | `file_get_contents` / `file_put_contents` |
| `Channel` (NIO) | direct file operations |

### `java.util.regex.Pattern`

| Java | PHP target |
|---|---|
| `Pattern.compile(regex)` | wrapper that translates Java regex → PCRE |
| `Matcher` | wrapper around `preg_match*` results |
| `Pattern.matches`, `Matcher.find`, `Matcher.group` | direct PCRE calls |

Java regex syntax is mostly PCRE-compatible. Edge cases:
- `(?<name>...)` named groups: same syntax in both
- Lookbehind: PCRE supports variable-length (8.4+); Java 16+ also supports
- `\p{...}` Unicode classes: both support
- Possessive quantifiers: both support

### `java.util.concurrent.*`

| Java | PHP target |
|---|---|
| `AtomicInteger`/`Long`/`Boolean`/`Reference` | wrapper using Swoole atomics or PHP-locked CAS |
| `ConcurrentHashMap` | wrapper using PHP array + lock |
| `CountDownLatch` | Fiber synchronization |
| `Semaphore`, `Lock` | Swoole or PHP-level lock |
| `Future`, `CompletableFuture` | Fiber-based async |
| `ExecutorService` | thin pool of Fibers/coroutines |

This is the largest stdlib chunk to implement (per the historical
stress corpus in `docs/CLOJURE-BOOT-ANALYSIS.md`, ~50 classes from
`java.util.concurrent.*` are pulled in by a large JVM program).

---

## 14b. Modern Java features and their bytecode shape (rank 1, verified 2026-05-01)

Decompiled with `javap -c` on JDK 25 with `--release 21`:

### String concat (Java 9+)

```java
String x = "x=" + a + ",y=" + b;
```

Compiles to single `invokedynamic` call with `StringConcatFactory.makeConcatWithConstants` bootstrap. **No `StringBuilder` chain.** AOT must implement this bootstrap method (synthesise PHP closure that does the concat).

### Lambdas (Java 8+)

```java
Function<Integer,Integer> f = x -> x + 1;
```

Compiles to `invokedynamic` with `LambdaMetafactory.metafactory` bootstrap that returns a `CallSite` whose target is a `Function` instance. The synthesised `apply()` calls a private `lambda$lambdaTest$0` static method holding the lambda body. AOT must implement the bootstrap (synthesise PHP closure).

### Method references (Java 8+)

```java
Function<String,Integer> f = String::length;
```

Same `invokedynamic + LambdaMetafactory.metafactory` shape as lambdas. The lambda body method is replaced by a direct method reference.

### Records (Java 16+)

```java
public record Point(int x, int y) {}
```

Compiled `Point.class`:
- `extends java.lang.Record`
- `final` class
- Auto-generated `equals()`, `hashCode()`, `toString()` use `invokedynamic ObjectMethods.bootstrap`
- `Record` attribute lists components

AOT must implement `java.lang.Record` as a real class with `ObjectMethods.bootstrap` invokedynamic support.

### Sealed classes (Java 17+)

```java
public sealed interface Shape permits Circle, Square {}
```

Compiled class file has a `PermittedSubclasses` attribute. AOT must parse this attribute (currently `LOAD_ATTRIBUTES` excludes it).

### Pattern matching for switch (Java 21+)

```java
return switch (s) {
    case Circle c -> "circle:" + c.r();
    case Square sq -> "square:" + sq.s();
};
```

Compiles to `invokedynamic` with `SwitchBootstraps.typeSwitch` bootstrap (returns int index based on type). Followed by `lookupswitch` for arms, `MatchException` for default. **Adds two new bootstrap methods PHPJava must implement: `typeSwitch` and `enumSwitch`.**

### Verified bootstrap methods needed

For Java 8 → 21 modern code, AOT must implement these `invokedynamic` bootstrap methods:

| Bootstrap | Java since | What it does |
|---|---|---|
| `LambdaMetafactory.metafactory` | 8 | synthesise functional interface impl |
| `LambdaMetafactory.altMetafactory` | 8 | metafactory variant for serialisable lambdas etc. |
| `StringConcatFactory.makeConcatWithConstants` | 9 | string concat |
| `ObjectMethods.bootstrap` | 16 | record `equals`/`hashCode`/`toString` |
| `SwitchBootstraps.typeSwitch` | 21 | type-based pattern switch |
| `SwitchBootstraps.enumSwitch` | 21 | enum pattern switch |
| `ConstantBootstraps.*` | 11 | dynamic constants (CONSTANT_Dynamic) |

These are the load-bearing capability gaps for modern Java code.

## 15. PHP version evolution (relevant features)

Verified 2026-05-01 by probing each PHP version installed locally
(7.0–8.5):

| Version | Year | Relevant additions | Verification |
|---|---|---|---|
| 7.0 | 2015 | scalar type hints, return types, null coalesce, throwable hierarchy | opcache only on this host |
| 7.1 | 2016 | nullable types `?T`, void return, multi-catch | |
| 7.2 | 2017 | object type, sodium | |
| 7.3 | 2018 | JSON_THROW_ON_ERROR | |
| 7.4 | 2019 | typed properties, arrow functions, **FFI**, **WeakReference**, **opcache.preload**, null coalescing assignment | WeakReference, FFI present (verified) |
| 8.0 | 2020 | **JIT (opcache.jit)**, named args, attributes, match expression, constructor promotion, union types, nullsafe `?->`, **WeakMap**, throw expression | WeakMap, FFI, JIT-capable (verified) |
| 8.1 | 2021 | **Fibers**, readonly properties, enums, **first-class callable syntax**, never type, intersection types, `final` class const | Fiber, first-class-callable, enum, readonly all present (verified) |
| 8.2 | 2022 | readonly classes, DNF types (union+intersection), `true`/`false` types, `null` standalone type, sensitive parameter | |
| 8.3 | 2023 | typed class constants, dynamic class const fetch, `json_validate` | |
| 8.4 | 2024 | **property hooks** (computed properties), asymmetric visibility, `new ExprWithoutParens()`, lazy objects, deprecation attributes | property hooks + asymmetric visibility (verified) |
| 8.5 | 2025 | **pipe operator `\|>`**, attribute targets, closure improvements (1.7-2× faster on dispatch) | pipe operator (verified `"hello" \|> strtoupper(...)` → "HELLO"); closure improvements (measured in `bench/validate-hotloop.php`) |

For PHPJava's purposes:
- **Minimum PHP version: 8.1** (Fibers needed for Thread emulation)
- **Recommended: 8.4 or 8.5** (modern JIT + closure improvements)
- **opcache.preload** (7.4+) caches compiled PHP at boot — useful for AOT-cached files
- **Fibers** (8.1+) provide cooperative concurrency for `Thread.start()`

---

### PHP version-feature probe (rank 1, this host)

```
PHP 7.0.33  | opcache
PHP 7.1.33  | opcache
PHP 7.2.34  | opcache
PHP 7.3.33  | opcache
PHP 7.4.33  | WeakReference, FFI, opcache
PHP 8.0.30  | WeakReference, WeakMap, FFI, opcache, JIT-capable
PHP 8.1.34  | + Fiber, first-class-callable, enum, readonly
PHP 8.2.30  | (same as 8.1 + DNF types, readonly classes — type-system only)
PHP 8.3.30  | (same + typed class constants — type-system only)
PHP 8.4.20  | + property hooks, asymmetric visibility
PHP 8.5.2   | + pipe operator, closure improvements
```

## 16. JVM version evolution (relevant features)

| Version | Year | Class file | Relevant additions |
|---|---|---|---|
| 1.0–6 | 1996–2006 | 45–50 | foundation; pre-modern semantics |
| 7 | 2011 | 51 | **`invokedynamic`** — pluggable dispatch; try-with-resources; multi-catch; `MethodHandle`/`VarHandle` |
| 8 | 2014 | 52 | lambdas + `LambdaMetafactory`; default methods on interfaces; Stream API; `java.time`; **LTS** |
| 9 | 2017 | 53 | **modules** (`module-info`); `StringConcatFactory` for `+`; `var-handles`; JShell |
| 10 | 2018 | 54 | `var` for local vars |
| 11 | 2018 | 55 | new HTTP client; lambda var; **LTS** |
| 12 | 2019 | 56 | switch expressions (preview) |
| 13 | 2019 | 57 | text blocks (preview) |
| 14 | 2020 | 58 | switch expressions (final); records (preview) |
| 15 | 2020 | 59 | sealed classes (preview); hidden classes |
| 16 | 2021 | 60 | **records** (final); pattern matching for instanceof (final) |
| 17 | 2021 | 61 | **sealed classes** (final); pattern matching for switch (preview); **LTS** |
| 18 | 2022 | 62 | UTF-8 default for source files |
| 19 | 2022 | 63 | virtual threads (preview); structured concurrency (incubator) |
| 20 | 2023 | 64 | scoped values (preview) |
| 21 | 2023 | 65 | **virtual threads (final)**; pattern matching for switch (final); record patterns (final); sequenced collections; **LTS** |
| 22 | 2024 | 66 | **Foreign Function & Memory API (final)**; unnamed variables (final) |
| 23 | 2024 | 67 | string templates removed; primitive types in patterns (preview) |
| 24 | 2025 | 68 | stream gatherers (final); class-file API (final); sync virtual threads |
| 25 | 2025 | 69 | compact source files (final); module imports (final); scoped values (final); flexible constructor bodies (final); **LTS** |

PHPJava's ceiling per `docs/GAP-JDK.md`:
- **Class file version 63 (Java 19) declared in `SDKVersionResolver`**
- Practical ceiling: Java 8 + partial Java 9–11
- Target for refactor: Java 21 LTS (class file 65)

### What each JVM version adds that PHPJava cares about

| Version | What we need |
|---|---|
| 7 | `invokedynamic` opcode + bootstrap method invocation |
| 7 | `CONSTANT_MethodHandle`, `CONSTANT_MethodType`, `CONSTANT_InvokeDynamic` constants |
| 8 | `LambdaMetafactory` + lambda synthesis |
| 8 | Default methods on interfaces |
| 9 | `CONSTANT_Module`, `CONSTANT_Package` constants |
| 9 | `StringConcatFactory.makeConcatWithConstants` (replaces `+` with indy) |
| 11 | `CONSTANT_Dynamic` constant tag |
| 11 | `NestHost`, `NestMembers` attributes (private cross-access) |
| 16 | `Record` attribute (records) |
| 17 | `PermittedSubclasses` attribute (sealed) |
| 21 | `Thread.ofVirtual()`, sequenced collection interfaces |
| 25 | `ScopedValue` runtime support (if we get that far) |

---

## 17. Implications for PHPJava

### Where the JVM contract is preservable cleanly

Most cases. PHP's dynamic typing accommodates Java's reference
semantics; PHP arrays handle Java's collection types; PHP exception
hierarchy maps to Java's. The mappings in §14 work for typical code.

### Where PHPJava must emulate

- **`invokedynamic` + bootstrap methods** — non-trivial implementation
- **Lambda metafactory** — synthesise PHP closures at bootstrap
- **`StringConcatFactory`** — PHP string concat at bootstrap site
- **`AtomicReference` and friends** — Swoole atomics or simulated CAS
- **Virtual threads** — Fibers (PHP 8.1+) or Swoole coroutines
- **`defineClass(byte[])`** — runtime class loading from bytes
- **Reflection + `setAccessible`** — PHP reflection mostly covers this
- **GC reference types** — `WeakReference` / `WeakMap` for weak/soft

### Where PHPJava diverges (acceptable)

- **`Integer ==` identity** — value equality only (Java footgun; documented)
- **Java float precision** — PHP float is 64-bit (precision loss when narrowing to Java float)
- **Module access enforcement** — deferred; classes load, module isolation not enforced
- **Bytecode verifier** — bypassed; trust the input
- **Checked exceptions** — informational only; not enforced at runtime
- **`finalize()` timing** — uses PHP destructors (more deterministic than Java's GC-driven finalize)

### Where PHPJava is BETTER than the JVM by accident

- **Deterministic destruction** — PHP's `__destruct` runs at end of scope, not at GC time
- **Native int 64-bit** — no `long` overflow on most arithmetic
- **No checked exceptions** — Java's checked-exception ceremony goes away
- **Native dynamic types** — `is_int`, `is_string` etc. cheap (~11 ns); JVM equivalent (`instanceof`) similar but with class-resolution overhead

### Where PHPJava is LIMITED

- **Concurrency** — no real OS threads in pure PHP; Swoole/AMPHP fill gaps
- **GC** — PHP's refcount + cycle collector is simpler than JVM's generational/concurrent collectors; OK for our use cases
- **JIT sophistication** — Zend's tracing JIT is more limited than HotSpot/C2/Graal; our AOT-emit-PHP strategy works around this
- **Memory layout control** — no equivalent to JVM's compact class/object headers; per-object overhead higher
- **Off-heap memory** — only via FFI; not on shared hosting

---

## 18. Version compatibility matrix

PHPJava target combinations (PHP version × JVM target):

| PHP | JVM target | Status |
|---|---|---|
| 7.4+ | Java 8 | minimum viable; lacks Fibers (no virtual threads) |
| 8.1+ | Java 8 | recommended floor; Fibers available |
| 8.1+ | Java 11 LTS | recommended; covers most modern Java |
| 8.4+ | Java 17 LTS | recommended target; modern records/sealed |
| 8.4+ | Java 21 LTS | aspirational target; includes virtual threads |
| 8.5+ | Java 21 LTS | optimal; closure improvements + JIT maturity |
| 8.5+ | Java 25 LTS | future stretch (Tier 3 in roadmap) |

**Recommended baseline for the project:** PHP 8.4 minimum, Java 21 LTS
target. This matches `docs/GAP-JDK.md`'s recommended target.

---

## Files referenced

- `docs/CONTRACTS.md` — what we lock in
- `docs/PATTERNS.md` — measured PHP patterns
- `docs/BOXING.md` — value representation
- `docs/BOTTLENECKS.md` — what costs what
- `docs/GAP-JDK.md` — version-by-version JVM gap
- `docs/CLOJURE-BOOT-ANALYSIS.md` — historical JDK stress trace
- `bench/validate-*.php` — measurement harnesses

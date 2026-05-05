# Contracts

> Date: 2026-05-01.
> Tier 0 keystone per `docs/MODEL.md`. Locks the interface boundaries
> and value-representation decisions that all Tier 1 implementation
> strategies must satisfy.

This document is normative. PRs that violate these contracts must
either update this doc first (with rationale and a measured impact
analysis) or be rejected.

## 1. Value representation — locked

### Decision: native PHP scalars on the operand stack

| Java type | PHP representation | Notes |
|---|---|---|
| `boolean` | PHP `bool` | true/false; serialised as 1/0 only when entering byte/int Java fields |
| `byte` | PHP `int` (8-bit range) | sign-extended on widening; truncated on narrowing |
| `char` | PHP `string` (1 character; multi-byte aware via mbstring at boundary) | NOT `int`. `String.charAt()` returns a single-character string. |
| `short` | PHP `int` | range-checked at narrowing |
| `int` | PHP `int` | 64-bit on most modern systems; range-checked at narrowing/casting |
| `long` | PHP `int` (when ≤ PHP_INT_MAX); GMP `\GMP` (when overflow) | full 64-bit Java semantics; PHP `int` is 64-bit on 64-bit hosts |
| `float` | PHP `float` (IEEE 754 binary64 — Java's `float` is binary32) | accept the precision delta for now; document |
| `double` | PHP `float` | identical to Java's binary64 |
| `null` | PHP `null` | |
| `Object` reference | PHP `object` | actual class is a PHP class implementing the JVM-shaped interface |
| `String` | PHP `string` | Java strings are UTF-16; PHP strings are bytes. `mbstring` used for length / char-at semantics; encoding declared per call site |
| `T[]` (array) | PHP indexed `array` | length tracked separately for primitive arrays where Java semantics require fixed length |
| Generic Java collections | idiomatic PHP class implementing `ArrayAccess`, `Countable`, `Iterator` **+ public underlying-array property** | `java.util.HashMap`, `ArrayList`, etc. — see Collection contract below |

**Collection shim contract** (refined per `bench/PATTERN-VALIDATION.md` §4):
Java collection shims provide two access paths:

1. **JVM-shaped surface** — `ArrayAccess`, `Countable`, `IteratorAggregate`
   for bytecode-running and AOT'd-code use. ~2× slower per op than raw
   array access; matches Java semantics for INVOKEVIRTUAL targets.
2. **Direct underlying-array property** (e.g. `$collection->a`) — for
   PHP-side hot loops. Essentially free (~27 ns vs 28 ns raw under JIT).

Both expose the same data; the choice is which interface the caller
goes through. PHP-side users default to `->a`; bytecode interop goes
through ArrayAccess. Pattern from `mesh/coll.php`'s `Coll`.

### Eliminated entirely: the `Int_` / `Long_` / `Double_` / `Boolean_` wrapper objects

**See `docs/BOXING.md` for full rationale.** Summary: the wrapper instances
exist *nowhere* at runtime. PHP scalars travel through every layer.

The wrapper *classes* (`\PHPJava\Packages\java\lang\Integer`, etc.) exist
only as namespaces of static methods (`valueOf`, `parseInt`, `toString`,
etc.) and as reflection metadata (Class object for `Class.forName`).
**They are never instantiated.**

Calls that look like wrapper-method invocation lower at AOT time:
- `Integer.valueOf(x)` → `$x` (no-op)
- `i.intValue()` → `$i` (no-op)
- `i.equals(j)` → `$i === $j`
- `i.hashCode()` → `$i` (Integer's hashCode IS the int value per JVMS)
- `i.toString()` → `(string) $i`

`instanceof` checks resolve via static type tracking from the AOT
compiler's slot-type table:
- Statically known type → constant `true`/`false`
- Statically `Object` (single primitive) → `is_int($o)` / `is_float($o)` / etc.
- Control-flow-merged across primitive types → tagged tuple `['I', $v]` at
  autobox sites only (rare; ~5% of code paths, ~25 ns/op cost)

### Documented divergence — being progressively removed

`Integer.valueOf(200) == Integer.valueOf(200)` returns `false` in Java
(identity). Returns `true` in PHPJava (value equality) when wrappers
elide. Affects only Java code that uses `==` on boxed types (every
style guide forbids this).

**As of 2026-05-05** (per Apollo's directive: "we should never have
this kind of contractual drift"), the value-representation contract
shifts from **unconditional elision** to **emit-then-prove-and-elide**.
Java semantics is the ground truth; the compiler proves locally when
elision is safe.

Phased migration:

  - **Phase 1 (shipped)** — `new String(s)` allocates a
    `String_Identity` wrapper preserving Java identity semantics.
    System.identityHashCode / `==` / IdentityHashMap distinguish
    wrapper instances. See `src/Aot/Runtime/java/lang/String_Identity.php`.
    Closes 2 of 4 contract-divergence tests
    (testIntern, testIdentityHashCode); 2 remain (testNotInterned,
    testNotInternedAfterLiteral) pending Phase 2.
  - **Phase 2 (deferred)** — wrap StringConcatFactory results +
    real intern-pool implementation (literal LDC routes through pool;
    intern() registers wrappers; subsequent literal access returns
    canonical entry). Mid-sized substrate work; closes the remaining
    2 tests. **Spike attempted in commit 828b4ba; reverted because
    naive concat-wrap broke testIntern (intern semantics depend on
    pool update). Proper fix: full intern pool.**
  - **Phase 3 (deferred)** — `WrapperEscapeAnalysis` IR pass.
    Per-method dataflow: prove the wrapper isn't observed (no
    identityHashCode, no `==` against another instance, no escape
    past method boundary, no IdentityHashMap use). When safe, elide
    the allocation — emit just the inner value. Restores the
    perf characteristics of unconditional elision while preserving
    correctness for cases where identity IS observed.
  - **Phase 4 (deferred)** — extend Phase 1-3 to the other primitive
    wrappers (Integer, Long, Double, Float, Boolean, Character).
    Same model; same rationale.

Until Phase 3, allocations occur for every `new String(...)` site —
perf cost bounded to that surface. Common code paths (literals,
concat, valueOf) still produce raw PHP scalars.

The earlier "boxing-elimination" framing measured 5-9× speedup —
that's preserved for the elided cases. The new contract makes
elision a *local optimisation* rather than a *global semantic
sacrifice*.

### Eliminated: tagged-string conventions for non-string values

cljp uses `\x00`-prefixed tagged strings for symbols/keywords/chars.
**PHPJava does not.** Java's `char` is a single-character PHP string;
Java's `String` is a plain PHP string; Java has no symbol/keyword
concept. If Clojure code running on PHPJava needs symbols, that's
provided by `clojure.lang.Symbol` (a Java class running on PHPJava),
not by PHPJava's value layer.

### Rationale (rank 1, measured 2026-05-01)

- P1 (the dominant user) expects `var_dump`, `print_r`, IDE inspection
  of Java objects to look like normal PHP. Wrappers would surprise.
- **Measured cost of boxing primitives** (`bench/PATTERN-VALIDATION.md` §3):
  - Raw scalar `$a + $b`: 19–29 ns/op
  - Boxed via `new Int_($v)`: 140–150 ns/op (**~5–7× slower**)
  - Boxed via `Int_::get($v)`: 169–240 ns/op (**~6–9× slower**)
  - JIT cannot eliminate the allocation
- AOT-emitted code can use Zend's native arithmetic opcodes directly
  if scalars are unwrapped; with wrappers, JIT can't trace.
- **The value-rep refactor is the single highest-ROI optimisation in the
  codebase** — 5–9× speedup per arithmetic op for ~50 files of refactor.

## 2. ClassLoader contract

```php
interface ClassLoaderInterface {
    /**
     * Resolve a class by FQ name, returning a JavaClass-shaped object.
     * Throws ClassNotFoundException if not found.
     */
    public function loadClass(string $fqn): JavaClassInterface;

    /**
     * Define a class from raw bytes. Used by Clojure's DynamicClassLoader,
     * Java agents, lambda metafactory output. Required for capability T5.
     */
    public function defineClass(string $fqn, string $bytes): JavaClassInterface;

    /**
     * Add a classpath entry. Existing PHPJava ClassResolver pattern;
     * resource types: FILE (directory), JAR (archive), BYTES (in-memory),
     * SHIM (curated PHP class in Packages/java/*).
     */
    public function addClasspath(string $resourceType, $value): void;

    /**
     * Whether a class is loaded (for dependency tracking, Class.forName checks).
     */
    public function isLoaded(string $fqn): bool;
}
```

Existing `JavaClass::load` becomes the default implementation. The
single static method becomes an instance method on a default loader
that lives in process state. This permits parent-classloader chains
(needed for Clojure's per-namespace classloader pattern) and supports
runtime registration of new classpath entries.

## 3. Invoker contract

```php
interface InvokerInterface {
    /**
     * Invoke a method by name with arguments. Returns the result
     * (PHP-native, per §1).
     */
    public function call(string $methodName, ...$args);

    /**
     * Whether this invoker has a method by this name.
     */
    public function has(string $methodName): bool;

    /**
     * Method-list introspection (for reflection support).
     * Returns descriptors keyed by name+signature.
     */
    public function methods(): array;

    /**
     * The strategy this invoker implements — for diagnostics, profiling,
     * tooling decisions.
     */
    public function strategy(): InvokerStrategy;
}

enum InvokerStrategy: string {
    case Interpret  = 'interpret';   // bytecode walk per call
    case AotEager   = 'aot-eager';   // pre-compiled, cached file
    case AotLazy    = 'aot-lazy';    // compiled on first encounter
    case PhpShim    = 'php-shim';    // hand-written native PHP (Packages/java/*)
}
```

The four strategies are the cache-TTL variants from `docs/MODEL.md`,
plus the existing PHP-shim path. A class's invoker is chosen by the
classloader at load time; the caller never knows which strategy is
active.

The existing `Core/JVM/Invoker/JavaClass*MethodInvoker` and `PHPClass*MethodInvoker`
satisfy this with minor cleanup. New: a `JavaClassAotMethodInvoker`
that loads the AOT-compiled PHP file (or uses the lazy-AOT path) and
dispatches through it.

## 4. NativeMethod contract

```php
interface NativeMethodInterface {
    /**
     * Bind a Java native method to a PHP implementation.
     * Used to expose curated Packages/java/* shims to bytecode-running code.
     */
    public function bind(string $jvmDescriptor, callable $phpImpl): void;

    /**
     * Resolve a native method invocation to its PHP implementation.
     */
    public function resolve(string $jvmDescriptor): ?callable;
}
```

This is the bridge between the bytecode interpreter / AOT'd code and
the curated `Packages/java/*` shim layer. When `INVOKEVIRTUAL` (or
its AOT'd equivalent) hits a method backed by a PHP shim, dispatch
goes through this interface.

## 5. Classloader-strategy decision rules

The default classloader picks per-class:

```
function chooseStrategy(string $fqn, ?BytecodeSource $source): InvokerStrategy {
    // 1. Curated shim — highest priority. java.lang.String, java.io.File etc.
    if (isShim($fqn)) return InvokerStrategy::PhpShim;

    // 2. Eager AOT — PHP file exists on disk and is fresher than .class
    if (eagerAotCacheHit($fqn, $source)) return InvokerStrategy::AotEager;

    // 3. Lazy AOT — long-running process and AOT-on-load enabled
    if (longRunning() && aotOnLoadEnabled()) {
        compileAndCache($fqn, $source);
        return InvokerStrategy::AotLazy;
    }

    // 4. Interpret — fallback, always works
    return InvokerStrategy::Interpret;
}
```

Detection of "long-running":
- Swoole / OpenSwoole present → yes
- AMPHP / Revolt present → yes
- `php_sapi_name() === 'cli'` and uptime > N seconds → yes (heuristic)
- Otherwise (FPM, CGI) → no

User can override via env var `PHPJAVA_AOT_MODE = eager | lazy | interpret`.

## 5.5. Data-structure rules (rank 1, from `docs/PATTERNS.md`)

Five mechanical rules for hot-path code. Justified by measurements;
PRs that violate these need their own measurements.

1. **Bytecode is a pre-decoded int array.** `unpack('C*', $code)` once
   at class load. Index by `$pc` thereafter.
2. **Frame state is PHP locals**, never frame objects or state arrays.
   `$stack`, `$sp`, `$L`, `$pc` are scalars or arrays in the
   function's local scope.
3. **Operand stack is a regular PHP array**. Not SplFixedArray (loses
   by 7×), not a wrapper class. Indexed by `$sp` (a local).
4. **Primitives are PHP scalars** on the operand stack. No
   `Int_`/`Long_`/`Double_`/`Boolean_` wrappers — they cost 7–9× per
   arithmetic op. Wrap only at autoboxing sites (statically
   determinable from method descriptors).
5. **Dispatch is switch in a static function** (interpreter) or
   inlined PHP via eval (AOT). Both are static-function PHP that JIT
   can trace. No closure tables, no eval'd dispatch closures, no
   threaded code.

## 6. Method dispatch — type matrix

The four JVM call types map to PHP as follows:

| JVM op | Target | PHP emission |
|---|---|---|
| `INVOKESTATIC` | static method, class-resolved | `\Class::method(...)` |
| `INVOKESPECIAL` | constructor / private / super call | direct method call (no virtual lookup) |
| `INVOKEVIRTUAL` | instance method, virtual lookup | `$obj->method(...)` (PHP's virtual dispatch matches) |
| `INVOKEINTERFACE` | interface method | `$obj->method(...)` |
| `INVOKEDYNAMIC` | bootstrap method invocation | one-time bootstrap to a CallSite, then call through it |

PHP method dispatch already handles single inheritance + interfaces
correctly. INVOKESPECIAL needs explicit non-virtual emission (rare;
mostly for `super.foo()` and constructor chains).

INVOKEDYNAMIC is the load-bearing capability gap (T1). Lambda
metafactory + StringConcatFactory cover ~99% of indy use; arbitrary
bootstrap methods are deferred.

## 7. Exception model

| JVM | PHP |
|---|---|
| `java.lang.Throwable` chain | PHP `\Throwable` chain |
| Exception table (try/catch ranges) | PHP `try` / `catch` blocks at AOT emission time |
| `athrow` opcode | `throw new ...` (or rethrow) |
| Checked vs unchecked | not enforced at runtime; checking is a `javac` thing |

Class hierarchy must reflect that `java.lang.Throwable extends \Throwable`
or equivalent so PHP catch clauses match. The shim layer for
`java.lang.*` exception classes already exists in `Packages/java/lang/*Exception.php`
and `*Error.php`.

## 8. Threading model

| JVM | PHPJava |
|---|---|
| `java.lang.Thread` | wrapper around Swoole coroutine (when present) or Fiber (PHP 8.1+) |
| `Thread.start()` | spawn coroutine / fiber |
| `Thread.join()` | wait on coroutine / fiber result |
| `Thread.ofVirtual()` (Java 21+) | always virtual on PHPJava (Fibers are virtual by nature) |
| `synchronized` block | mutex / lock primitive (Swoole `Mutex` or pure-PHP recursive lock) |
| `volatile` | no-op on PHP (single-threaded) unless Swoole multi-process |
| `java.util.concurrent.atomic.*` | Swoole atomics if present, PHP-locked simulated CAS otherwise |

## 9. GC model

PHP's refcount + cycle collector handles 99% of Java GC semantics.
Specific deltas:

| Java | PHP equivalent | Implementation |
|---|---|---|
| Strong reference | refcount | free |
| `WeakReference` | `\WeakReference` | thin wrapper |
| `SoftReference` | `\WeakReference` + memory-pressure check | shim (no native soft refs in PHP) |
| `PhantomReference` | destructor + queue | shim |
| `Object.finalize()` | `__destruct()` | trivial; deprecated in Java anyway |
| `WeakHashMap` | `\WeakMap` | wrapper |

We do not emulate Java's GC — we use PHP's. This means the timing of
finalisation may differ; documentation will note this.

## 10. Capability surfaces — minimum bar

Every Tier 1 strategy must satisfy at least these capabilities for a
class to be considered "supported":

- Class loading (parse + verify trust)
- Static field access + initialisation (`<clinit>`)
- Instance field access
- Method invocation (all 5 types above)
- Exception throw + catch (with class hierarchy match)
- Constructor (`<init>`) chains
- Array creation, indexed access, length, primitive vs reference distinction
- Reflection: `Class.getName`, `getMethods`, `getMethod`, `getDeclaredFields`, `Method.invoke`, `Field.get/set`

Beyond that, capabilities tier up per `docs/MODEL.md`'s T1–T5 list.

## 11. Test contract

A test is **contract-level** if it validates observable behaviour that
must hold across all invoker strategies. A test is **implementation-level**
if it validates a particular strategy's internals.

The contract test harness must be able to run any test against any
invoker strategy by switching the classloader configuration. Failing
tests under one strategy and passing under another indicates either:

- A bug in the failing strategy (action: fix)
- A spec divergence between strategies (action: lock spec in this doc)

Concrete test layout:

```
test/
├── contract/                    Tests that must pass against all strategies
│   ├── ArithmeticContract.php
│   ├── DispatchContract.php
│   ├── ExceptionContract.php
│   └── ...
├── interp/                      Tests specific to the interpreter
├── aot/                         Tests specific to AOT
├── shim/                        Tests for the curated Packages/java/* layer
└── parity/                      Tests against a JVM oracle (real javac+java)
```

## 12. Decisions deferred

These remain open and are NOT locked by this document:

- **License posture for OpenJDK source reading.** Default: re-implement
  from spec. Specific complex cases (e.g. `String.format` parser) may
  benefit from referencing OpenJDK in the abstract — explicit case-by-case
  decision required, never copy.
- **Verifier strictness.** PHPJava currently trusts class files. We may
  add a trust-aware verifier for `defineClass(byte[])` from untrusted
  sources later; for now, trust the input.
- **Module system depth.** `module-info.class` parsing only (T1). Module
  enforcement at runtime is deferred — Java-21+ code that compiles to
  a modular JAR will load, but module access checks are not enforced.

## 13. Document maintenance

This doc is the spec. Changes:

- Adding a new contract → propose in PR + reasoning + impact analysis
- Changing a value-rep decision → measure impact across all four invoker
  strategies before commit
- Removing a capability from the minimum bar → strong justification
  required; affects what we mean by "JVM-compatible"

The roadmap (`ROADMAP.md`) tracks *progress* against this spec. The
model (`docs/MODEL.md`) explains the *why*. This document defines
the *what*.

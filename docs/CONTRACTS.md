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

### Identity contract — emit-then-prove-and-elide

**As of 2026-05-05** (per Apollo's directive: "we should never have
this kind of contractual drift"), the value-representation contract
shifts from **unconditional elision** to **emit-then-prove-and-elide**.
Java semantics is the ground truth; the compiler proves locally when
elision is safe.

#### What's measured vs unmeasured

Compliance with Java's identity contract is *evidence-tracked* per
type. "Suite green" is not "compliant" — the suite is a sample of
the spec, not the spec itself. Per CLAUDE.md ladder rank-1: a claim
holds when it has measurement backing, not when no test has
falsified it.

| Type | Value semantics | Identity semantics |
|---|---|---|
| `String` | rank-1 (suite + 419 parity cases) | rank-1 (Phase 1-3 shipped; testIntern / testNotInterned / testNotInternedAfterLiteral / testIdentityHashCode green; identity preserved at allocation sites; pool-canonicalised on intern; Phase 3 elides non-observed allocations) |
| `Integer` / `Long` / `Short` / `Byte` | rank-1 (parity batteries 57+64+0+0 cases — Short/Byte not yet probed) | **unmeasured** — no test or parity case probes `valueOf` cache identity, `new Integer(x)` allocation freshness, `==` on boxed values, identityHashCode of boxed primitive, autobox-cache range crossing. Phase 4 = build the parity battery, measure divergence, close measured divergences. |
| `Boolean` / `Character` | rank-1 (29+52 parity) | **unmeasured** — `Boolean.TRUE / FALSE` constant identity, Character cache for [0,127), no probes. |
| `Float` / `Double` | partial (parity probes value methods only) | **unmeasured** — Java 9+ removed Float/Double caching (always fresh); no probes. |
| `Object` references (user classes) | n/a | rank-1 via PHP's native object identity (`spl_object_id` matches `===`). |

**Rank-1 known non-compliance (just discovered 2026-05-05):**
`new Integer(5)` etc. emit `new \PHPJava\Aot\Runtime\java\lang\Integer(5)`
in AOT output, but the Integer shim has no constructor or instance
methods. Calling `.intValue()` on the result returns the wrapper
object, not an int → "Object of class … could not be converted to
string" at runtime. Affects all primitive wrappers except String.
Pre-Phase-4 fix: extend the `new+dup+invokespecial<init>` peephole
at `src/Aot/Ir/Builder.php:1349` so `new <PrimitiveWrapper>(x)`
elides to `x` (matching the existing `Integer.valueOf(x) → x`
elision). Phase 4 then replaces this elision with allocation-of-
identity-wrapper as the primitive-identity battery exposes
divergences.

#### Phased migration

  - **Phase 1 (shipped 2026-05-05)** — `new String(s)` allocates a
    `String_Identity` wrapper preserving Java identity semantics.
    System.identityHashCode / `==` / IdentityHashMap distinguish
    wrapper instances. See `src/Aot/Runtime/java/lang/String_Identity.php`.
    Closes testIntern + testIdentityHashCode; 2 remain
    (testNotInterned, testNotInternedAfterLiteral) pending Phase 2.
  - **Phase 2 (shipped 2026-05-05)** — process-global string pool
    (`src/Aot/Runtime/StringPool.php`); StringConcatFactory output
    wrapped in fresh `String_Identity` (IR Builder); `String.intern`
    pool-canonicalises wrapper receivers and registers raw-string
    receivers; `System.identityHashCode` of a raw string routes
    through pool's lazy-allocated canonical wrapper. Concat results
    have distinct per-instance identity; `intern()` round-trips so
    identityHashCode of the canonicalised concat matches the
    literal; literals have a stable canonical identity allocated
    on first observation. **Closes the remaining 4 of 4 String
    contract-divergence tests** — suite 0E / 0F / 2S
    (KotlinTest + OutputDebugTraceTest the only skips). Parity
    419/419 holds. LDC of String literals stays as raw PHP string
    (no wrapping at LDC time) — raw-string transparency works
    because AOT-emitted PHP is in coercive mode and
    `String_Identity` implements `Stringable`.
  - **Phase 3 (shipped 2026-05-05)** —
    `src/Aot/Ir/WrapperEscapePass.php`: method-local IR transform.
    Two-pass: collect slots whose value reaches an
    identity-observing position; rewrite wrapper allocations in
    non-observing positions to their inner expression. Wired into
    `Compiler.php` after InlinePass and AsyncSpecialiserPass.
    Identity-observing whitelist:
    System.identityHashCode / String_::intern / `===` / `!==` /
    Return_ / Throw_ / StoreField / StoreStaticField. Value-only
    whitelist: shipped JDK shim classes, BinOp `.`,
    PrintStream.print/println, built-in PHP fns. Conservative
    default — unrecognised callees keep the wrapper. Rank-1 perf
    measurement: post-elision raw concat 40 ns/op vs Phase-2-kept
    wrapper 179 ns/op (~4.5× saving per elided site). Suite +
    parity unchanged after wiring.
  - **Phase 4 (compliance-driven; not started)** — extend the
    emit-then-prove-and-elide model to primitive wrappers
    (Integer / Long / Double / Float / Boolean / Character /
    Short / Byte), under measurement-driven cadence:

      1. Build a primitive-wrapper-identity parity battery
         (~60-100 cases). Probe `valueOf` cache range identity
         (`Integer.valueOf(127) == Integer.valueOf(127)` true,
         `Integer.valueOf(128) == Integer.valueOf(128)` false),
         `new <Wrapper>(x)` always-fresh identity,
         `Boolean.TRUE / FALSE` constant identity,
         `Character.valueOf` cache for [0,127),
         `System.identityHashCode` of boxed primitives,
         autobox-cache range crossing,
         `==` between mixed-source boxed values.
      2. Run battery against current PHPJava — generate rank-1
         evidence of how non-compliant the implementation is.
      3. Build the substrate to close measured divergences:
         `<Wrapper>_Identity` classes, peephole rewrites for
         `new <Wrapper>(...)`, escape-pass FQN extension,
         `valueOf`-cache-range identity for cached values,
         lowering changes for instance-method calls
         (`intValue` / `equals` / etc.) when receiver is a
         wrapper.
      4. Re-run battery — confirm 0 divergences. Update this
         table's "unmeasured" cells to rank-1.

     **Doctrine note**: Phase 4 is NOT an unconditional reversal
     of the existing "wrappers eliminated entirely" model in
     `docs/BOXING.md`. Value semantics (arithmetic, equals,
     toString, hashCode) stay PHP-scalar-native — that's the
     measured 5-9× win. Identity preservation is layered on top
     for the (much smaller) set of allocation sites that observe
     it. Phase 3's escape-pass machinery extends to primitive
     wrappers as part of step 3 above.

Until Phase 3 ran, allocations occurred for every `new String(...)`
site **and every StringConcatFactory call site** — perf cost bounded
to those two surfaces. Phase 3's escape-analysis pass now elides the
allocation when the wrapper's identity is provably never observed
within the method.

The earlier "boxing-elimination" framing measured 5-9× speedup —
that's preserved for the elided cases (post-Phase-3, the common
internal-concat pattern emits raw PHP concat). The new contract
makes elision a *local optimisation backed by measurement* rather
than a *global semantic sacrifice*.

### Eliminated: tagged-string conventions for non-string values

cljp uses `\x00`-prefixed tagged strings for symbols/keywords/chars.
**PHPJava does not.** Java's `char` is a single-character PHP string;
Java's `String` is a plain PHP string; Java has no symbol/keyword
concept. CLJP owns symbols and keywords directly in its runtime; if a
Java-library interop boundary needs a symbol-like value, the CLJP bridge
chooses an explicit adapter shape. PHPJava's value layer does not encode
Clojure values.

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

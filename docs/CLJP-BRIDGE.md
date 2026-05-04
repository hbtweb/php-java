# cljp interop bridge

> Design sketch for connecting AOT'd Java code (this fork) with cljp
> ("ClojurePHP") at runtime. Both produce PHP that runs on Zend; the
> bridge marshals values at the language boundary.
>
> Companion to `docs/MODEL.md` (peers on Zend), `docs/ADJACENT-SHAPES.md`
> (cljp landscape), `docs/LAYERS.md` (what survives, what cuts).
>
> Status: descriptive design. Implementation deferred until Phase B
> (receiver-shape unification) lands — the easy direction depends on
> AOT receivers being plain PHP-native classes.

## Setup

| | |
|---|---|
| **cljp** | Clojure-to-PHP compiler. Clojure source → PHP source. ~8.4 kloc of Clojure that compiles to PHP. Lives in another repo. Output: PHP files providing PersistentVector, PersistentHashMap, Atom, Var, IFn impls, etc. as PHP classes. |
| **This fork's AOT** | JVM-bytecode-to-PHP compiler. Java source (or any JVM-language compiled to bytecode) → PHP source. Output: `\PHPJava\Aot\Generated\<X>` PHP classes per CONTRACTS.md §1 raw-scalar contract. |
| **Stated design** | Per `docs/MODEL.md`: *peers on Zend, bridge marshals at language boundary.* Not nested. Both produce PHP classes; values cross at function-call boundaries. |

## Three interop directions

### D1 — cljp Clojure code calls AOT'd Java code

**Path:** Clojure does `(.method java-instance args)` → cljp emits
`$java_instance->method($args)` → AOT receiver is
`\PHPJava\Aot\Generated\<X>` → PHP-native dispatch → AOT method runs.

**Cost:** Free, post-Phase B. Once receivers are PHP-native, calling a
Java method from cljp is just a PHP method call — no marshalling, no
bridge code. Both sides agree on the same object model: PHP classes
with normal methods.

**Status:** Unblocked by Phase B (receiver-shape unification). Until
then, cljp would need to know about PHPJava's `JavaClass` / Invoker
plumbing — which is exactly the indirection Phase B removes.

### D2 — AOT'd Java code calls cljp Clojure functions

**Path:** Java code does `IFn fn = ...; fn.invoke(args);` → AOT compiles
to `$fn->invoke($args)` → cljp's `clojure.lang.IFn` is a PHP class with
an `invoke()` method → runs.

**Cost:** Free *if* cljp implements `clojure.lang.*` classes with method
names matching the JVM-spec interfaces. PHPJava's `ClassResolver` needs
to know that `clojure.lang.IFn` resolves to cljp's PHP namespace, not
to a stub or empty class in `Packages/`. ~30 LOC of bridge: namespace
mapping rule.

**Status:** Needs the ClassResolver namespace-mapping resource type
described below. No architectural blockers.

### D3 — Shared values across the boundary

**Path:** Pass a Java map to cljp / pass a Clojure persistent vector to
Java. Both sides accept PHP-native arrays / objects per CONTRACTS.md §1.

| Direction | Mechanism |
|---|---|
| AOT → cljp | AOT-produced array → PHP array → cljp wraps as a Clojure seq via its existing seq-over-array primitives. |
| cljp → AOT | cljp `PersistentVector` → PHP object with `count()` and `offsetGet()` (`ArrayAccess` + `Countable`) → Java code can iterate via `for (Object x : vec)` (which compiles to `Iterable.iterator()` etc — needs adapter). |

**Cost:** Free for primitives (int, float, string, bool, null all marshal
identically). Collections need a thin adapter both ways: ~50–100 LOC
per collection family (Vector / Map / Set / Seq).

## The single mechanism that makes all three work: ClassResolver namespace mapping

PHPJava's `src/Kernel/Resolvers/ClassResolver.php` already supports
multiple resource types:

```
RESOURCE_TYPE_FILE      .class file on disk
RESOURCE_TYPE_JAR       inside a JAR
RESOURCE_TYPE_BUILT_IN  src/Packages/java/* PHP shim
```

**Add a fourth:**

```
RESOURCE_TYPE_PHP_NAMESPACE   maps a JVM binary class name to an
                              external PHP namespace, skipping AOT
                              compilation entirely
```

Usage:

```php
ClassResolver::add([
    [
        ClassResolver::RESOURCE_TYPE_PHP_NAMESPACE,
        'clojure/lang/'  => 'CljPHP\\Runtime\\Lang\\',
    ],
]);
```

Effect: when AOT-emitted Java code does
`new clojure.lang.PersistentVector()`, the AOT compiler resolves
`clojure/lang/PersistentVector` via `ClassResolver`. With this
mapping, the resolver returns the FQN
`\CljPHP\Runtime\Lang\PersistentVector` and AOT emits a direct
`new` against it — no AOT compilation, no `Packages/` stub.

**Implementation surface (~30 LOC):**

1. New constant in `ClassResolver`: `RESOURCE_TYPE_PHP_NAMESPACE`.
2. New branch in `Aot/Compiler.php` `classFqn()` — when resolving a
   class binary name, check namespace-mapping rules first, return the
   external FQN if matched, otherwise fall through to the existing
   `\PHPJava\Aot\Generated\` / `\PHPJava\Aot\Runtime\` resolution.
3. New branch in `Loader::loadClass()` — skip AOT compilation when the
   class is namespace-mapped (cljp owns those classes; we don't compile
   them; runtime calls dispatch directly to the cljp-provided PHP).

## What's NOT needed (anti-context)

These are tempting paths that should be avoided:

- **Don't generate cljp glue code via the legacy `Compiler/Lang/Assembler/`.**
  cljp produces PHP, not bytecode. The legacy Assembler produces .class
  files, the wrong direction. (Per `docs/LAYERS.md` Phase E, that
  subsystem is on the cut list and not load-bearing for cljp interop.)

- **Don't try to compile cljp's Clojure source through PHPJava.** cljp
  is its own pipeline. We don't need to understand or emit Clojure;
  cljp produces PHP that we just call.

- **Don't reimplement `clojure.lang.*` in `Packages/java/`.** The
  `Packages/` stubs are for JDK classes. cljp's runtime classes are
  *cljp's* surface, not the JDK's. Keep them in cljp's namespace and
  use ClassResolver mapping to bridge.

- **Don't try to share `$GLOBALS` between PHPJava and cljp.** Per
  `docs/MODEL.md`: *no shared $GLOBALS runtime with cljp. They're peers
  on Zend; bridge marshals at language boundary.* The bridge is the
  ClassResolver mapping + value-shape compatibility per CONTRACTS.md §1
  — not runtime unification.

## Performance — the load-bearing claim

Per ROADMAP probe Q3.3: *Can a cljp `defn` call into AOT'd Java code at
< 5 µs?*

With Phase B + ClassResolver mapping:
- cljp → AOT call: PHP-native method dispatch on a PHP object. Per
  `docs/PATTERNS.md` measurements: ~22 ns/call empty method. Well
  under 5 µs.
- AOT → cljp call: PHP-native method dispatch on cljp's IFn class.
  Same cost.

The 5 µs target is comfortably cleared. The bridge is essentially free
because both sides target Zend natively.

## Sequencing

| Step | What | Blocked by | Cost |
|---|---|---|---|
| 1 | ClassResolver `RESOURCE_TYPE_PHP_NAMESPACE` + matching branches in `Compiler.classFqn()` and `Loader::loadClass()` | Nothing | ~30 LOC |
| 2 | Smoke probe: AOT'd Java instantiates a `\CljPHP\Runtime\Lang\PersistentVector` (mock or stub) and calls a method on it | Step 1 | hours |
| 3 | Phase B (receiver-shape unification) | LAYERS.md Phase A | sessions |
| 4 | Direction D1 smoke: cljp Clojure calls an AOT'd Java method | Phase B + Step 1 | hours |
| 5 | Per-collection adapters (PersistentVector ↔ Java array, PersistentHashMap ↔ HashMap, etc.) — only for collections cljp/Java code actually exchanges | Step 4 | ~50–100 LOC each as surfaced |
| 6 | Q3.3 measurement: cljp `defn` ↔ AOT'd Java < 5 µs | Step 4 | bench harness |

Steps 1–2 can happen in parallel with Phase B work since they're
isolated. Steps 3–4 are gated.

## Bigger picture: bidirectional IR pipeline

`Aot/Ir` (per `docs/LAYERS.md`) is the natural choke point for any
language-pair conversation that has either PHP or JVM as a side. cljp
interop is one specific instance of this:

- cljp uses its own Clojure → PHP pipeline (independent of our IR)
- We use Aot/Ir for JVM-bytecode → PHP
- The bridge is value-shape compatibility (CONTRACTS.md §1) +
  ClassResolver mapping at runtime — not IR-level integration

Future capabilities (per `docs/LAYERS.md` "bidirectional IR pipeline"):
PHP source → JVM bytecode via Aot/Ir would let PHP code be packaged
as `.jar` files runnable on real HotSpot/Graal — closing the bridge in
yet another direction. cljp's PHP output could in principle go through
that same path to produce `.jar`s, but cljp's output is high-level PHP,
not JVM-bytecode-friendly; this would need a separate "cljp PHP → IR"
builder. Not a priority; documenting the shape only.

## Checklist for cljp project (for cross-team coordination)

If cljp wants to be PHPJava-AOT-interop-friendly:

1. Implement `clojure.lang.*` classes (IFn, Var, Atom, PersistentVector,
   PersistentHashMap, Symbol, Keyword, …) as PHP classes in a stable
   namespace (e.g. `\CljPHP\Runtime\Lang\`).
2. Method names match the JVM-spec interfaces (`invoke`, `apply`,
   `count`, `nth`, `seq`, `cons`, etc. — same as JVM Clojure).
3. Constructor signatures match JVM Clojure where it makes sense
   (especially for `new clojure.lang.PersistentVector(args)` patterns
   AOT'd Java code might emit).
4. Collections implement PHP `\ArrayAccess`, `\Countable`, `\IteratorAggregate`
   so `for (Object x : vec)` compiled by AOT can iterate via PHP-native
   `foreach` semantics through the iterator adapter.

Steps 1–3 are likely already cljp's design. Step 4 is the Java-iteration
edge case and may need an adapter per collection family.

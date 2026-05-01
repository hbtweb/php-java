# PHPJava patterns — what the data says

> Date: 2026-05-01.
> All measurements rank 1, run on PHP 8.4.20 and 8.5.2 with and without
> opcache+JIT. Source: `bench/validate-patterns.php`,
> `bench/validate-hotloop.php`, `bench/validate-datastructures.php`,
> `bench/spike-fast-interp.php`. Numbers are ns/op unless stated.

This document is the empirical companion to `docs/CONTRACTS.md`. The
contract says *what*; this says *why those choices and not others*. PRs
that propose alternative shapes need to either fit one of the validated
patterns below or come with their own measurement.

## The five rules

1. **Bytecode is a pre-decoded int array.** `unpack('C*', $code)` once at
   class load. Index by `$pc` thereafter.
2. **Frame state is PHP locals**, not arrays-of-state or frame objects.
   `$stack`, `$sp`, `$L`, `$pc` live in the function's local scope.
3. **Operand stack is a regular PHP array.** Not SplFixedArray, not a
   wrapper. Indexed by `$sp` (a local).
4. **Primitives are PHP scalars** on the operand stack. No `Int_`/`Long_`/
   `Double_`/`Boolean_` wrappers. Wrap only at autoboxing sites
   (statically determinable from method descriptors).
5. **Dispatch is switch-in-a-static-function** (interpreter) or
   inlined-and-eval'd PHP (AOT). Both are static-function PHP that
   JIT can trace.

Apply these five and you have the fastest, simplest, most traceable
PHPJava code possible. Everything else is JVM-contract implementation.

## Rule 1 — pre-decoded int array bytecode

PHP 8.5 + JIT, 5 byte reads:

| Pattern | ns/byte |
|---|---|
| **Pre-decoded int array `$ints[$pc++]`** | **5.3** |
| `ord($string[$pc++])` | 13.7 |
| `unpack('C5', $string)` per method call | 32.0 |

Pre-decode once; subsequent reads are direct hashtable lookups. The
`ord()`-on-string path looks faster (no upfront unpack) but loses 2.6×
under JIT — string-byte access has more overhead than int-array
indexing.

Current PHPJava uses `BinaryReader` over `php://memory` streams (the M1
cost). Replace with `unpack('C*', $code)` at parse time, store as int
array on the `MethodInfo` object.

## Rule 2 — frame state in PHP locals

PHP 8.5 + JIT, 4 field operations per iteration:

| Pattern | ns |
|---|---|
| **Locals only** (`$stack`, `$sp`, `$L`, `$pc` as PHP locals) | **11.4** |
| Array `$f['sp']++` | 143.5 |
| Typed object `$f->sp++` | 145.7 |
| `stdClass` `$f->sp++` | 297.8 |

Locals beat every encapsulation by **5–13×**. The reason is that JIT
specialises locals heavily: `$sp` becomes a CPU register. Frame
objects defeat this — every property access goes through Zend's object
machinery.

Current PHPJava puts state into a `DependencyInjectionProvider` rebuilt
per iteration. Cut entirely. State lives in the function's locals.

## Rule 3 — regular array, never SplFixedArray

PHP 8.5 + JIT, 4 push/pop ops:

| Pattern | ns |
|---|---|
| **Regular array `$stack[$sp++]`** | **9.0** |
| SplFixedArray `$stack[$sp++]` | 68.9 |
| `array_fill(0, 16, 0)` then index | 75.7 |

SplFixedArray loses by 7×. The conventional wisdom that "fixed arrays
are faster because they don't hash" is wrong on modern PHP. Zend's
hashtable for indexed arrays is hand-tuned beyond what the
fixed-array path achieves. Same finding on lookups (regular wins by
2×) and iteration (regular wins by 4×).

**Drop SplFixedArray from any plans.** It's a perf trap.

## Rule 4 — no primitive wrappers

PHP 8.5 + JIT, single `$a + $b`:

| Pattern | ns | vs raw |
|---|---|---|
| **Raw `$a + $b`** | **18.8** | 1× |
| `(new Int_(1))->value + (new Int_(2))->value` | 140.4 | **7.5×** |
| `Int_::get(1)->value + Int_::get(2)->value` | 169.4 | **9.0×** |

This is the single largest accidental cost in the current PHPJava
codebase. Every arithmetic operation pays a 7–9× tax for boxing.

**The current `Int_::get()` static-factory pattern is the worst variant
measured.** It allocates a fresh object that escapes the local scope,
then accesses `->value`, then discards. JIT cannot eliminate the
allocation because the object's lifetime crosses the operation
boundary.

Wrap only at autoboxing sites (when the method descriptor is
`(Ljava/lang/Integer;)V` rather than `(I)V`). Statically determinable.

## Rule 5 — switch in static function, or inlined PHP

PHP 8.5 + JIT, 5000 ops/call × 2000 calls:

| Pattern | ns/op |
|---|---|
| **Hand-unrolled inline (= idiomatic AOT)** | **2.5** |
| **Eval-built unrolled function (= naive AOT)** | **3.7** |
| **Switch in static function** | **9.3** |
| Eval-built switch closure | 44.3 |
| Closure-array table | 36.8 |
| Threaded code (closure chain) | 39.3 |

Three observations:

- **Eval-built function ≈ hand-written.** AOT-compile-then-eval matches
  hand-written PHP at runtime. Confirms the AOT architecture.
- **Switch beats closures by ~4×.** Even with PHP 8.5's closure
  improvements, switch dispatch in static functions is ~4× faster.
  JIT traces switch into compare-and-branch native; closures retain a
  use-frame setup cost.
- **eval'd switch closures are *worse* than static-function switch.**
  Don't put dispatch into eval'd closures — JIT can't trace through
  them as well.

For the interpreter fallback, the dispatch loop should be a switch in
a static function (or method). For AOT, the emitted PHP is the
function itself.

## Cross-version sanity (PHP 8.4 vs 8.5)

PHP 8.5 made closures faster but switch and inline still win:

| Pattern | 8.4 + JIT | 8.5 + JIT |
|---|---|---|
| Inline | 2.5 | 2.6 |
| AOT (eval-built) | 3.8 | 3.7 |
| Switch | 10.2 | 9.3 |
| Closures | 64.0 | 36.8 |

Closures got 1.7× faster on 8.5; switch and AOT essentially unchanged.
The relative ordering is preserved. **Optimise for switch + AOT and
both versions perform.**

## What this implies for the codebase

| Current PHPJava element | Decision |
|---|---|
| `Kernel/Mnemonics/_*::execute()` — 200 opcode classes | **cut.** Replaced by switch cases in interpreter, by emit functions in AOT. ~10kloc removed. |
| `Compiler/Emulator/Mnemonics/_*` — parallel type-tracking tree | **repurpose.** The structural-emission pattern is what AOT needs. ~10kloc rebadged. |
| `Compiler/Lang/Assembler/` — PHP-syntax → bytecode | **cut.** Educational artifact, not on critical path. ~5kloc gone. |
| `Compiler/Builder/` — programmatic class file builder | **keep but freeze.** Useful for tests. Don't extend. |
| `Kernel/Types/Int_`, `Long_`, `Double_`, `Boolean_`, `Char_` | **cut as runtime objects.** Keep as compile-time type tags (constants in a `Type` enum) for descriptor parsing. Stop using them on the operand stack. |
| `Kernel/Filters/Normalizer.php` | **shrink.** Keep only the autobox-site logic. ~80% gone. |
| `Core/JVM/Stream/BinaryReader` | **keep for class file parse.** Replace with int-array indexing for in-method bytecode walks. |
| `OperationCache` | **cut.** Per-call allocation we don't need. |
| `DependencyInjectionProvider` | **cut.** Frame state lives in PHP locals. |
| `JavaMethodCallable::call` | **rewrite.** From a 100-line dispatch loop with per-iter alloc + setter chain → a switch over int array with locals for state. ~30 lines. |
| `Core/JVM/Invoker/InvokerInterface` and impls | **clean.** The polymorphism boundary is right; tighten the contract per CONTRACTS.md §3. |
| `Packages/java/*` shim layer | **keep, fill out.** Per CONTRACTS.md §1, expose both ArrayAccess (JVM surface) and underlying-array property (PHP hot-path). |
| `Kernel/Resolvers/ClassResolver` | **keep, extend.** Add `RESOURCE_TYPE_BYTES` for `defineClass(byte[])` and `RESOURCE_TYPE_AOT` for cached AOT files. |

Net: about **20kloc removed**, ~5kloc added. The codebase shrinks
substantially. Most of the removal is the per-opcode class tree (200
× ~30 LOC × 2 trees) and the Compiler/Lang/Assembler subsystem.

## Public API — same surface, different internals

```php
// Unchanged:
ClassResolver::add([[ClassResolver::RESOURCE_TYPE_FILE, '/path/to/classes']]);
$cls = JavaClass::load('com.example.Foo');
$result = $cls->getInvoker()->getStatic()->getMethods()->call('bar', $arg1, $arg2);
```

The user-facing API stays. Internal changes:

- `JavaClass::load` returns a `JavaCompiledClass` (interp), `JavaAotClass`
  (compiled), or `JavaSimpleClass` (shim) per the classloader strategy.
- The invoker returned by `getInvoker()` is one of three implementations
  behind `InvokerInterface`. Caller doesn't know which.
- `call($name, ...$args)` accepts PHP scalars natively; returns PHP
  scalars natively. No more `Int_`/`Char_` wrapping at the boundary.

This means **existing callers continue to work**, but the values they
get back are idiomatic PHP types instead of wrappers. That's a soft
break for any caller that did `instanceof Int_` checks (likely zero in
the wild — these wrappers were internal).

## Why the original PHPJava chose differently

The upstream project (php-java/php-java, 2018–2021) optimised for a
different goal: **be a JVM simulator readable as a tour of the JVMS**.

That goal rewards:

- **One class per opcode** — each opcode has a Wikipedia-style
  reference page in code; you can navigate to `_iadd.php` and see
  exactly what `iadd` does
- **Wrapper classes per JVM type** — `Int_` vs `Long_` vs `Char_`
  literally maps to the JVMS's typed-slot model
- **`BinaryReader` over the bytecode stream** — the bytecode is a
  stream in the spec
- **Stack frames as objects** — frames are objects in the spec
- **No optimisation pressure** — the project shipped HelloWorld and
  some demos; nobody booted Clojure on it

It's a **reference implementation in PHP**. We're doing something
different: **a translator that emits PHP for Zend to run.** Same parser,
same class file structures, different runtime philosophy.

The original choices made sense for the original goal. We can keep the
parser they wrote (it's solid) and rebuild the runtime layer for our
goal. **The code we're cutting was the right shape for "simulate the
JVM"; it's the wrong shape for "translate to PHP".**

## What to subtract — full audit

Every removal is justified by a measurement above:

| Subtraction | Justification |
|---|---|
| Per-opcode class tree | switch + locals beats class-per-opcode by ~600× per op |
| `Int_`/`Long_`/etc wrappers on the stack | 7–9× per arithmetic op (Rule 4) |
| `BinaryReader` for in-method bytecode | 2.6× per byte read (Rule 1) |
| `DependencyInjectionProvider` | locals beat any frame encapsulation by 5–13× (Rule 2) |
| `setParameters` / `setConstantPool` chain | three method calls per opcode dispatch we don't need |
| `microtime()` per iteration | sample once outside the loop |
| `is_callable($trigger)` × 2.25/op | hoist boolean flag once |
| `class_exists($fullName)` per opcode | gone with switch dispatch |
| `OperationCache` per method call | one less allocation per call |
| `fopen('php://memory')` per method call | gone with int array (Rule 1) |

## Total work

The five rules + the cut list = **~3 weeks of focused work** to a
re-architected PHPJava that runs at 9–10 ns/op interpreter and 3–5
ns/op AOT. That's:

- Week 1: rewrite `JavaMethodCallable::call` as switch over int array
  with PHP-locals frame. Expand AOT compiler to full opcode coverage.
  Cut the per-opcode class tree.
- Week 2: cut `Int_`/`Long_`/etc. boxing. Update Normalizer to wrap
  only at autobox sites. Update test suite expectations. Most of the
  49 errors / 48 failures resolve naturally.
- Week 3: classloader integration of three strategies (interpret /
  AOT-eager / AOT-lazy / shim). Long-running soak test in Swoole.
  Lambda metafactory if time.

End: a substantially simpler, dramatically faster PHPJava. The model
in `docs/MODEL.md` and the contract in `docs/CONTRACTS.md` lock the
shape; the rules here lock the implementation choices.

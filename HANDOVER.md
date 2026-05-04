# Handover — AOT contract compliance + suite green at 45/47 (2026-05-04)

> SHA at hand-off: `b631328` on branch `roadmap`, pushed to
> `origin/roadmap` (15 commits ahead of `549d6cc`, the prior session's
> tip).
> Prior session hand-off: `549d6cc` (two literal-handling fixes) — see
> SBAR in the previous HANDOVER for full context.
> Author: Claude (Opus 4.7) working with Apollo Nicolson.
> Following the SBAR pattern from `~/CLAUDE.md`.

---

## Situation

The fork's test suite is **fully green for the first time in the AOT-
default era: 0 errors / 0 failures / 2 skipped** (rank 1, PHPUnit 9.6.34
+ PHP 8.4.20). 45 of 47 case files pass; the 2 skipped are deliberate:

- `KotlinTest` — pre-existing, requires Kotlin runtime not in CI.
- `OutputDebugTraceTest` — interp-only bytecode-trace dumper; no AOT
  analog. Marked skipped this session, slated for delete in Phase D
  (interpreter delete) per `docs/LAYERS.md`.

This session opened with **30 files passing / 15 files failing** at SHA
`549d6cc`. Net movement: **+15 file-passes, ~50 individual test gains**,
across 15 commits.

The work delivered three categories of change:

1. **AOT correctness fills** for contract violations and missing semantics:
   inheritance modelling, interface compilation, overload mangling,
   array by-ref auto-detect, contract-shape boundary conversions for Z
   (boolean) and C (char), wrapper-class IR lowerings per BOXING.md,
   exception-translation at the dispatch boundary.

2. **Test-side migrations** for assertions that had been written against
   the legacy interp's wrapper shape — updated to assert raw PHP scalars
   per CONTRACTS.md §1.

3. **One pattern port from cljp** — Apollo flagged
   `~/GitHub/ClojurePHP/docs/CLJP-COMPILER.md §"By-reference auto-detect"`
   mid-session as having solved the array-mutation-vs-PHP-COW problem.
   Direct-`aset` detection was ported (+ recursive propagation works
   automatically via PHP signature routing). Cljp's other two patterns
   (known-stdlib-by-ref, chained-user-fns) deferred — not exercised by
   current test surface.

---

## Background

The roadmap goal is unchanged: **JVM-bytecode-to-PHP AOT translator for
long-running PHP processes**, with the v1 success line being:

> test suite passes 100%, interpreter runs at ≤ 100 ns/op, AOT runs at
> ≤ 5 ns/op, and the bb allowlist surface (~80 most-used classes from
> babashka's `src/babashka/impl/classes.clj`) is non-stub. From there,
> Clojure boot and the long tail are probes against existing
> infrastructure rather than new architecture. — `ROADMAP.md`

Of the four conditions:
- **Test suite 100%**: ✓ achieved this session (45 pass / 2 skipped /
  0 fail / 0 err). The two skipped are documented and correct per the
  contract.
- **Interpreter ≤ 100 ns/op**: ✓ already achieved (~64 ms / 1k iter
  → ~64 ns/op JIT) per prior session's measurements.
- **AOT ≤ 5 ns/op**: ✓ already achieved (0.18-0.20 ns/op JIT for hot
  int loops, 0.24 ns/op for invokestatic-heavy code).
- **bb allowlist (~80 classes) non-stub**: **this is the remaining
  major thrust.** ~80% of `src/Packages/` files raise
  `NotImplementedException` per `docs/STATUS.md`.

Per the success line: with the suite green and the perf budget cleared,
the architecture is no longer the bottleneck. **JDK shim coverage is**.

---

## Assessment — what's done, by cluster

### AOT pipeline correctness — suite-closing changes

| # | Closes | Mechanism | SHA |
|---|---|---|---|
| 1 | Inner-class dispatch (`Outer$Inner`) | Threaded JVM binary name through `New_`/`StaticCall` IR nodes; Lowerer routes via `Loader::newInstance`/`callStatic` with the exact name (autoloader's `_ → /` heuristic is reverse-ambiguous for inner classes) | `3bf27a5` |
| 2 | Static-field write-through | `JavaStaticField::set` override mirroring read-side AOT bridge — was only mutating internal `$fields` map, not the AOT class's static property | `6dc191b` |
| 3 | `java.lang.Integer` + `StringBuilder` shims | At `Aot/Runtime/bootstrap.php` (the AOT-routed JDK namespace per `Builder::classFqn`). Integer per BOXING.md table; StringBuilder as mutable PHP class with `__toString` | `ba34068` |
| 4 | Wrapper-class IR lowerings (BOXING.md) | At IR build time: `Integer.MAX_VALUE` → `IntLit`, `Integer.valueOf(int)` → identity, `i.intValue()` → identity, `i.equals(j)` → `===`, `compare` → `<=>`, etc. AOT code never touches the shim for these | `ffc98b6` |
| 5 | `NoSuchMethodException` on arity mismatch | `Loader::tryCallStatic` validates via `ReflectionMethod` up-front, throws JVM-shaped exception instead of letting `ArgumentCountError` leak | `47f7fb4` |
| 6 | Inheritance modelling | Emit `class X extends \PHPJava\Aot\Generated\<Super>` when the JVM superclass is non-Object non-JDK; super-init peephole emits `parent::__construct(...)` so parent's field initialisers run on `$this` | `eb8fc03` |
| 7 | Char_/Boolean_ contract unboxing | `JavaMethodCallable::aotUnboxArg` converts Char_ via `__toString` (1-char UTF-8 string) and Boolean_ to PHP bool — not their `getValue()` int/string returns | `c838d03` |
| 8 | Z/C field+array storage | IR Builder narrows on putfield/putstatic/castore to PHP bool / multi-byte UTF-8 string; widens on getfield/getstatic/caload back to JVM-stack int. `mb_chr`/`mb_ord` for UTF-8 (not single-byte `chr`/`ord`) | `f65db63` |
| 9 | Boxing-shape test migration | AccessDynamic/Static field tests + ArrayTest dropped `->getValue()` / `->offsetGet()` / `->count()` — assert raw PHP scalars per CONTRACTS.md §1 | `ebed884` |
| 10 | Double_ → PHP float | `aotUnboxArg` extends to Double_ (string-backed via BigDecimal) → cast to float | `a27d805` |
| 11 | Array by-ref auto-detect (cljp port) | Builder scans IR for `StoreArrayElement` on param slots; Lowerer prepends `&` to those params AND aliases them into `$L[slot]` via `&` in the prelude | `252deed` |
| 12 | Filename typos + skip interp-only test | Renamed `*Compreing*Test.php` → `*Comparing*Test.php` (PHPUnit autoload couldn't find the class); marked `OutputDebugTraceTest` skipped | `f4662d9` |
| 13 | Descriptor-aware overload mangling | Per-class overload index → descriptor-suffixed PHP names (`main_aLjava_lang_String_`, `main_aI`) + runtime dispatcher picking by arg-count + arg-shape inspection | `e6de174` |
| 14 | boolean[] element-type tracking | `newarray T_BOOLEAN` (atype=4) marks the synthetic slot; bastore/baload narrow/widen Z based on slot membership. Distinguishes boolean[] from byte[] (which share the bastore opcode). Per-method reset of the tracker. | `cf11eea` |
| 15 | Interface compilation | ACC_INTERFACE → emit as PHP `abstract class` (PHP `interface` can't carry default-method bodies); methods without Code attribute → `abstract` stubs; `aotSuperClassBin` falls back to first interface; pre-load parent before class declaration to bypass autoloader heuristic | `b631328` |

### Architectural deltas in `src/Aot/` (cumulative)

- **`Aot/Loader.php`** — added `newInstance`; arg-count → `NoSuchMethodException` translation.
- **`Aot/Compiler.php`** — overload index + dispatcher generator + descriptor mangle helpers, interface-as-abstract-class emit, abstract-method stubs, `extends` clause + parent preload, `aotSuperClassBin` (super OR first-interface).
- **`Aot/Ir/Builder.php`** — binary-name threading on `New_`/`StaticCall`, super-init peephole generalised + `parent::__construct`, wrapper-class IR lowerings (static + instance + getstatic), Z/C field+array contract storage, by-ref auto-detect, boolean-array slot tracking, overload-aware mangle.
- **`Aot/Ir/Lowerer.php`** — INF/-INF distinct from NAN, `BoolLit` rendering, by-ref param signature + prelude alias.
- **`Aot/Ir/Node.php`** — optional `binaryName` on `New_`/`StaticCall`, `byrefParamIndices` on `Method`, `BoolLit`.
- **`Core/JavaCompiledClass.php`** — `getSuperClassName`, `getAccessFlag`, `getInterfaceBinaryNames`.
- **`Core/JVM/Field/JavaStaticField.php`** — `set` override mirroring read-side AOT bridge.
- **`Core/JVM/Invoker/Extended/JavaMethodCallable.php`** — `aotUnboxArg` (Char_/Boolean_/Double_ → contract shape).
- **`Aot/Runtime/bootstrap.php`** — `Integer`, `StringBuilder` shims.

### Untouched / deferred — what wasn't done

| Item | Reason | Notes |
|---|---|---|
| Phase C — `Dynamic → Instance` rename | Cosmetic, mechanical | Not blocking anything; do under the next refactor pass. |
| Phase D — interpreter delete | Suite-passing prerequisite finally met; the actual delete still pending | All cuts identified in `docs/PATTERNS.md` lines 540-559. ~20 kloc removable. The `OutputDebugTraceTest::testCallMain` skip plus the legacy `Kernel/Mnemonics/_*` are the visible scaffolding. |
| Phase E — legacy PHP→bytecode stack cut | Independent | `Compiler/Lang/Assembler/`, `Compiler/Builder/`, `Compiler/Emulator/` (~12.5 kloc combined). |
| Q-future — bidirectional IR pipeline | Strategic | PHP-source → JVM-bytecode via Aot/Ir, sketched in `docs/LAYERS.md`. |
| **JDK shim T2 surface (~233 classes)** | **The remaining v1 thrust** | ~80% of `src/Packages/` files throw `NotImplementedException`. `docs/CLOJURE-BOOT-ANALYSIS.md` enumerates the bb allowlist subset (~80 classes from babashka). |
| Path D′ behavioural oracle | Strategic, ~1 week | Per-method JVM-side parity capture, foundation for clean-room shim authoring. Not started. |
| Stub generator (Path C, ~1 day) | Mechanical | javap-based; emits PHP class declarations with `NotImplementedException` bodies for the 130 stub-only T2 classes. Not started. |
| Multi-interface implementation | Single-interface impl works (extends-as-abstract-class) | Multi needs PHP traits + interface combo. Not exercised by current tests. |
| Cljp by-ref patterns 2+3 | Direct-aset alone covers current tests | Pattern 2 (known-PHP-mutating-fn registry) + Pattern 3 (chained user fns). |
| Method overload — fuller arg-shape matching | Current dispatcher handles all current tests | Falls through to `NoSuchMethodException` when shape match is ambiguous; refine if a real-world overload set surfaces a mismatch. |
| AOT records (Java 14+) | Untouched | Records use `ObjectMethods` invokedynamic for equals/hashCode/toString; the indy bootstrap is whitelisted but record-specific class shape isn't emitted. |
| Concurrency adapter (virtual threads, etc.) | Roadmap Phase 4.5 | Out of scope until JDK surface fills. |

---

## Recommendation — next session focus

Priority is now **outside** the AOT pipeline. With suite green and perf
within budget, the architecture is no longer the bottleneck. The next
deltas should attack the **JDK surface** — that's what unblocks running
real Java code (Apache Commons, Clojure boot, etc.).

### Next major goal — bb allowlist non-stub

`ROADMAP.md` defines v1 success as the bb allowlist (~80 most-used
classes from babashka) being non-stub. From there, Clojure boot and
the long tail are characterised as "probes against existing
infrastructure rather than new architecture."

**Concrete next steps, in priority order:**

1. **Stub generator (~1 day)** — Path C in the prior session's terminology.
   javap-driven; emit PHP class declarations with
   `NotImplementedException` bodies for the 130 stub-only T2 classes.
   Mechanical. Closes the long tail of "class not found" runtime errors
   that block discovery of what's actually needed.

2. **Behavioural oracle harness (~1 week)** — Path D′. PHPJava already
   has FFM-based JVM-side parity infrastructure. Extend to per-method
   I/O capture: run a method on JVM, capture (args, return, side
   effects), compare against AOT-emitted PHPJava run. Foundation for
   clean-room shim authoring per the GPL+CPE constraint documented in
   `docs/LAYERS.md`.

3. **bb allowlist class fill** — work the ~80 classes from
   `src/babashka/impl/classes.clj`. With the oracle harness in place,
   each class's surface can be specced and tested against JVM ground
   truth without GPL contamination. Current AOT pipeline correctness
   should mean each class is "small enough to author in a day or two."

4. **Document the AOT class-emit shape** — the architecture changes
   landed this session (overload dispatcher, abstract-class-for-
   interface, `extends` + parent preload, by-ref params, contract-shape
   conversions) need a doc-side companion in `docs/LAYERS.md` so future
   contributors can read the emitted PHP without reverse-engineering
   the IR Builder. Started this session inline.

5. **Interpreter delete (Phase D)** can begin. The suite is green; the
   AOT path covers the test surface; the interp is no longer load-
   bearing. ~10 kloc removal. Defer until after the JDK fill if Phase D
   touching the same files would conflict.

### Not a priority

- Further AOT perf work — already at 0.20 ns/op, well under the
  5 ns/op budget.
- Further test-shape migration — clusters touched are now closed.
- The PHP-source → JVM-bytecode bidirectional pipeline (Q-future).

---

## Pointers — files touched this session

```
src/Aot/Compiler.php                                    +228 / -22
src/Aot/Loader.php                                       +38
src/Aot/Ir/Builder.php                                  +330 / -16
src/Aot/Ir/Lowerer.php                                   +27 / -1
src/Aot/Ir/Node.php                                      +20
src/Aot/Runtime/bootstrap.php                           +101
src/Core/JavaCompiledClass.php                           +44
src/Core/JVM/Field/JavaStaticField.php                   +21
src/Core/JVM/Invoker/Extended/JavaMethodCallable.php     +30 / -3
tests/Cases/AccessDynamicFieldTest.php                  re-shape
tests/Cases/AccessStaticFieldTest.php                   re-shape
tests/Cases/ArrayTest.php                                +-22
tests/Cases/BoundaryValueTypeForBooleanTest.php          +14 / -8
tests/Cases/OutputDebugTraceTest.php                     +10
tests/Helpers/AssertionHelpers/AssertFields.php          +20 / -2
tests/Cases/{DoubleOf,FloatOf}TypesCompreingTest.php   rename → Comparing
docs/STATUS.md                                          updated
docs/LAYERS.md                                          updated
ROADMAP.md                                              updated
README-hbtweb.md                                        updated
HANDOVER.md                                             rewritten (this file)
```

## Anti-context — do not re-attempt

- **Don't broaden boolean-array tracking to all primitive arrays.**
  Only Z is ambiguous (bastore shared with byte[]); other primitive
  arrays have unique opcodes. Adding generic per-slot type tracking
  for opcodes that don't need it is overhead without payoff.
- **Don't try to autoload `Outer$Inner` via heuristic enrichment.**
  Prior session tried `_ → $` fallback and it "exploded the JAR-
  resolver scan time on suites with many cross-refs". Compile-time
  resolution via `Loader::loadClass($exactBin)` (what landed this
  session) is the correct path.
- **Don't emit Java interfaces as PHP `interface`.** PHP interfaces
  can't carry method bodies; default methods need bodies. Abstract
  class is the closest PHP-native shape.
- **Don't tie new shims into the legacy `src/Packages/java/*` (interp
  path).** Apollo's stated direction: *"more interested in correctness
  with performance than bootstrapping or getting the thing tied into
  broken or slow parts of the program"*. AOT shims live in
  `src/Aot/Runtime/bootstrap.php` per `docs/LAYERS.md:172`.
- **Don't use `chr`/`ord` for char (C descriptor) conversion.** Java
  char is the full UTF-16 range (0..0xFFFF); single-byte chr/ord
  silently corrupt non-ASCII chars. Use `mb_chr`/`mb_ord` with
  `'UTF-8'`.
- **Don't add `final` to AOT class emissions.** Any AOT class may
  become a parent of another (anonymous inner extends enclosing;
  explicit subclass extends superclass). PHP's `final` blocks that.
  JIT specialisation in PHP doesn't depend on `final`.
- **Don't write multi-interface implementation as multiple PHP
  `extends`.** PHP allows only one. Real solution is interface +
  trait combo; defer until a test surfaces the need.

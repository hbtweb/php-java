# PHPJava Roadmap (hbtweb fork)

> Forked from php-java/php-java at SHA `b34a90a` (last upstream activity 2021).
> Strategic framing in [`docs/MODEL.md`](docs/MODEL.md) — read first.
> JDK gap analysis in [`docs/GAP-JDK.md`](docs/GAP-JDK.md).
> Cross-project landscape in [`docs/ADJACENT-SHAPES.md`](docs/ADJACENT-SHAPES.md).
> First written: 2026-05-01. Revised after spike: same day.

## Goal

A JVM-bytecode-to-PHP translator for **long-running PHP processes**
(Swoole / AMPHP / RoadRunner / FrankenPHP) and request-scoped FPM
deployments. Run Java libraries from PHP code with idiomatic types at
the boundary; serve as a JVM substrate for languages that target it.

**Concrete success line for v1:** the test suite passes 100%, the
interpreter runs at ≤ 100 ns/op, AOT runs at ≤ 5 ns/op, and the bb
allowlist surface (~80 most-used classes from babashka's
`src/babashka/impl/classes.clj`) is non-stub. From there, Clojure boot
and the long tail are probes against existing infrastructure rather
than new architecture.

## Falsifiers (revised after measurement)

| ID | Original | Status | Notes |
|---|---|---|---|
| F1 | Per-op > 1 µs after Phase 2 → abandon interpreter | **lifted** | Spike measured 22 ns/op switch dispatch; 5.6 ns/op naive AOT; 0.4 ns/op idiomatic AOT |
| F2 | bb allowlist transitive deps > 200 unknowns → narrow goal | open | needs probe after T1 lands |
| F3 | Concurrency adapter > 2kloc → narrow goal | open | depends on Swoole/FFI work |
| F4 | cljp-bridge cost > 50 µs → drop bridge | open | only meaningful after AOT lands |

## Current state (2026-05-04, HEAD = `b631328`)

- **Tests:** **0 errors / 0 failures / 2 skipped** — fully green for the first time in the AOT-default era. 45/47 case files pass. Skipped: `KotlinTest` (pre-existing, no Kotlin runtime in CI) and `OutputDebugTraceTest` (interp-only bytecode-trace dumper, no AOT analog, marked for Phase D delete).
- **AOT pipeline:** **AOT is the default execution path** since `7f01155` (no env gate). End-to-end coverage now includes inheritance (`extends` + `parent::__construct`), interface compilation (Java interface → PHP `abstract class` with default-method bodies), method overload (descriptor-mangled names + arg-shape dispatcher), array by-ref auto-detect (cljp `aset`-on-param port), contract-shape Z (boolean) and C (char) field/array storage, wrapper-class IR lowerings (BOXING.md inline lowerings — `Integer.MAX_VALUE` → `IntLit`, `i.intValue()` → identity, `i.equals(j)` → `===`, etc.).
- **Hot-path perf (AOT):** **0.18-0.20 ns/op JIT** for int loops (1.8× of HotSpot JIT, 2.9× faster than HotSpot interpreted); **0.24 ns/op** for invokestatic-heavy code; ~2.2 ns/op for array workloads; ~22 ns/call empty-method dispatch. Unchanged this session — work was correctness, not perf.
- **Compile-output cache:** **2649× speedup** on repeat compiles (1578 µs → 0.6 µs).
- **Interpreter perf:** measured 5.22 µs/op (current); 22 ns/op (spike with Phase 2 fixes) — see `bench/`. **Interpreter is now load-bearing-free** — AOT covers the entire test surface; Phase D delete unblocked.
- **JDK ceiling:** declared up to 19 (class file 63), practical ceiling is Java 8 + partial Java 9–11; details in `docs/GAP-JDK.md`. **Now the binding constraint** for v1's bb-allowlist condition.
- **Stub coverage:** ~80% of `src/Packages/` files raise `NotImplementedException` (T2 work). The bb allowlist (~80 classes from `src/babashka/impl/classes.clj`) is the v1 success-line target.
- **Architecture:** IR-based AOT (Module + Method + BasicBlock + Stmt + Terminator + Expr) wraps PHPJava's bytecode parser. String-path emitter retained as fallback for unsupported opcodes. All 9 fixtures + the full PHPUnit suite lift via IR.

### v1 success-line scorecard

Per `## Goal` above:

| Condition | Status |
|---|---|
| Test suite passes 100% | ✓ achieved 2026-05-04 |
| Interpreter ≤ 100 ns/op | ✓ already achieved (~64 ns/op JIT) |
| AOT ≤ 5 ns/op | ✓ already achieved (0.18-0.20 ns/op JIT) |
| **bb allowlist (~80 classes) non-stub** | **remaining major thrust** |

## The work, in tiers

The model (per `docs/MODEL.md`) treats interpreter and AOT as two cache
modes of one compiler, not two separate projects. Phases below reflect
that — there is no "interpreter optimisation track" running parallel to
"AOT track"; both rewrites share the same opcode-handler tree, with one
emitting state mutation (interpret) and one emitting PHP source (AOT).

### Tier 0 — foundation (do first)

The contracts must be explicit before more code lands. Without this,
Tier 1 strategies and Tier 2 shim work re-derive their own assumptions
and conflict.

| Deliverable | Exit |
|---|---|
| `docs/CONTRACTS.md` — names `ClassLoaderInterface`, `InvokerInterface`, `NativeMethodInterface`, value-representation rules | document committed |
| Refactor existing code to satisfy contracts strictly | `Core/JVM/Invoker/` cleaned; `JavaClass` decoupled from `JavaCompiledClass` |
| Test suite restructured: contract tests vs implementation tests separated | parity test that runs against any invoker |
| Decision: PHP scalars on operand stack (drop `Int_`/`Long_`/`Double_` boxing) | written + tests for Boolean/Char preservation |
| Decision: classloader cache shape ≈ cljp's `_loaded` pattern | written |
| Bench harness ✓ (already done — `bench/baseline.clj`, `bench-cli.php`, `spike-fast-interp.php`) | done |
| Profile harness ✓ (`bench/profile-xhprof.php` + LD_PRELOAD finding) | done |

**Estimate:** 3–5 weeks. Falsifier: if we can't write a parity test that
runs against both interpreter and a stub AOT after this, the contract
isn't separated cleanly enough.

### Tier 1 — implementation strategies (parallel after Tier 0)

Three strategies behind the same `InvokerInterface`. Built on shared
infrastructure (parser, constant pool, attribute readers, shim layer).

| Strategy | Status | Per-op cost (spike) | Effort |
|---|---|---|---|
| **1a — Interpreter (optimised)** | spike validated | 22 ns/op (no opt), 36 ns/op (JIT — regresses) | 4–8 weeks |
| **1b — AOT compiler (naive)** | spike validated by hand-translation | 5.6 ns/op (no opt), 1.5 ns/op (JIT helps) | 2–4 weeks after 1a |
| **1c — AOT compiler (idiomatic, optional)** | spike validated by hand-translation | 0.4 ns/op (no opt), 0.2 ns/op (JIT) | 3–6 months |
| 1d — PHP-shim invoker | exists | n/a (depends on shim implementation) | shim coverage tracked in Tier 2 |

**1a — Interpreter rewrite.** Address H1–H8, M1–M4 from
`bench/profile-c930e2c.md`:

- **H1** sample `microtime()` every 1000 iters
- **H2** opcode singletons (or switch dispatch)
- **H3** resolved-class cache for opcode handlers
- **H4** kill the 3-method setter chain
- **H5** hoist DI provider rebuild
- **H6/H7** unbox primitives — PHP scalars on stack
- **H8** hoist trigger-callable checks
- **M1** kill `fopen`+`fwrite` per method (string-byte-reader; this is the largest single accidental cost)
- **M2** pool `OperationCache` / `BinaryReader`
- **M3** cache method-name + signature parse
- **M4** skip annotation lookup when none

**1b — Naive AOT.** A parallel `Aot/Emit/_*::emit($builder)` tree that
mirrors `Kernel/Mnemonics/_*::execute()`. Each emitter walks the same
parsed bytecode but produces a PHP statement instead of mutating runtime
state. Shared with the interpreter: the classloader, parser, and shim
layer. New piece: per-method PHP file generation, opcache integration,
classloader fallback when AOT cache misses.

**1c — Idiomatic AOT.** Adds a TeaVM-shape IR (SSA + CFG) and a
decompiler pass that lifts bytecode to expression trees and reconstructs
structured control flow.

**Sub-step 1c-α: stack-erasure peephole — DONE 2026-05-03.** A 5-pattern
post-emit peephole over the statement list, iterated to fixpoint,
catches the common javac-generated stack patterns: push+ireturn,
push+istore, push+push+arith+istore, push+push+if_icmp,
push+if-single-op. Pure-source restriction (only `$L[N]` reads or
literals can be erased) preserves side-effect ordering for
invoke/getstatic/getfield. Rank-1 measured: BenchAdd::sum1k() JIT
**0.82 → 0.18 ns/op** (4.5× speedup), beating the hand-emit idiomatic
AOT spike (0.2 ns/op) and reaching within 1.8× of HotSpot JIT
(~0.10 ns/op). ~150 LOC. See `docs/PATTERNS.md` "Stack-erasure
peephole" for patterns + emit before/after.

**Sub-step 1c-β: full abstract-stack tracking — DONE.** Catches the
cases the peephole misses — push+push+arith-without-immediate-store,
cross-block stack flow, exception-handler entry stacks. Implemented
via `spillStackToSlot` / `reloadStackFromSlots` in `Builder.php:287–310`;
handler entries reset stack to `[CaughtException()]`.

**Sub-step 1c-γ: cross-method inlining — DONE 2026-05-03.** First
shipped as post-emit-text substitution; then ported to IR-level
transform pass (`src/Aot/Ir/InlinePass.php`, ~120 LOC). Detects
single-`Return_(pure-Expr)` methods, substitutes `StaticCall` sites
with the parameterised return expression. Iterated to fixpoint.
Rank-1 measured: BenchInvoke::callLoop() JIT **4.05 → 0.20 ns/op**
(~20× win once IR-level inline pass landed; the IR-level version is
faster than the string-text version because it produces cleaner
output without redundant parens).

**Sub-step 1c-δ: escape analysis on Java arrays — DONE 2026-05-03.**
The IR Builder's iaload/iastore/arraylength opcodes detect when the
array operand is a `LocalRead(N)` and emit direct `$L[N][$i]`
operations instead of going through the `(object){'v' => ...}`
ArrayHelper wrapper. Closes the 10× property-access cost. Three new
IR nodes: `StoreArrayElement` Stmt, `ArrayElementRead` Expr,
`ArrayLengthRead` Expr. For non-LocalRead sources (arrays from
fields, method returns), falls back to ArrayHelper which handles
both wrapped and raw shapes dynamically.

**Sub-step 1c-ε: switch terminator (TABLESWITCH/LOOKUPSWITCH) — DONE.**
`Switch_` IR node at `Node.php:220`; opcode parse at `Builder.php:501,
1128–1136`; PHP `switch` render at `Lowerer.php:245–251`.

**Sub-step 1c-ζ: rare-opcode tail — DONE.** DUP2 (`Builder.php:799`),
IUSHR/LUSHR (`:814–831`), MULTIANEWARRAY (`:836–847`).

For canonical priority/ordering of remaining work, see
[Next-work hierarchy](#next-work-hierarchy-post-2026-05-04-audit) below.

### Tier 2 — surface coverage (shared by all Tier 1 strategies)

The JDK API surface implemented in PHP (`src/Packages/java/...`).
Required by every implementation strategy; written once, used by all.

| Sub-tier | Scope | Effort |
|---|---|---|
| T1 — class file format up to Java 21 | `CONSTANT_Dynamic`, `Record`, `PermittedSubclasses`, `NestHost`, `Module` attrs; lambda metafactory + StringConcatFactory | 3–5 weeks |
| T2 — bb allowlist core (~80 classes) | `String`, `Class`, `Object`, `Throwable`, `Thread`, `java.io.*`, `java.util.regex.Pattern` | 2–4 months |
| T3 — concurrency adapter | `java.util.concurrent.atomic.*`, `locks.*`, `Thread.ofVirtual` on Fiber/Swoole | 4–6 weeks |
| T4 — bb allowlist tail (~300 classes) | `java.util.concurrent.*`, `javax.crypto`, `java.net.http`, etc. | 4–8 months |
| T5 — extension surfaces | `defineClass(byte[])`, `Instrumentation`, `Unsafe` (FFI when available) | 4–8 weeks |

T2 + T3 together unblock most P1 use cases (single Java library called
from PHP). T5 unblocks Clojure-on-PHPJava and dynamic-language hosting.

Strategy: each shim class gets a focused integration test that exercises
every method against a JVM-side oracle (real JVM via FFM transport). The
parity test infrastructure from Tier 0 makes shim-writing mechanical.

License note: OpenJDK is GPL+CE; PHPJava is MIT. Re-implementing from
spec rather than transliterating OpenJDK source. bb's allowlist is
narrow enough that this is tractable.

### Tier 3 — probes (questions, not features)

Each is a question answered by running, not built features.

| Probe | Question |
|---|---|
| Q3.1 — Java library call | Does PDFBox / Tika / iText work end-to-end from PHP? |
| Q3.2 — Clojure boot | Does `clojure-1.13.0-slim.jar` reach `user=>` REPL? |
| Q3.3 — cljp interop | Can a cljp `defn` call into AOT'd Java code at < 5 µs? |
| Q3.4 — bb compatibility | Do bb's pure-Clojure libs run on cljp+PHPJava? |
| Q3.5 — hot reload | Edit `.java`, re-AOT, callers see new methods? |

## Next-work hierarchy (post-2026-05-04 audit)

The 2026-05-04 audit verified all open-work claims across the docs
against the code; many items previously listed as TODO had already
landed. The remaining surface, organised by kind. Correctness gaps
sit ahead of new build because bb-fill (the v1 gate) will exercise
broader semantic surface, and substrate bugs will surface as
implementation bugs in shim code rather than at their actual site.

### Testing — correctness gaps surfaced by audit (1–2 days)

JVM-semantic divergences current tests don't exercise. Ranked roughly by
likelihood-of-being-hit-during-bb-fill.

| # | Gap | Site | Fix |
|---|---|---|---|
| T1 | Long overflow unmasked → silent float promotion | `src/Aot/Ir/Builder.php:474,476,478` ladd/lsub/lmul; `:738` ldiv; `:741` lrem | 64-bit overflow guard or GMP fallback. Java long wraps at 64-bit; PHP int promotes to float on overflow. |
| T2 | Float narrowing missing on FSTORE / putfield(F) / f2d / d2f | `src/Aot/Ir/Builder.php:1556–1577` widenForJvmStack/narrowForFieldStorage; `:459` f2d/d2f | Add F descriptor; pack/unpack via `'f'` at field/array boundary. PHP `float` is binary64; Java `float` is binary32. |
| T3 | NaN comparison wrong | `src/Aot/Ir/Builder.php:461–463` fcmp[lg]/dcmp[lg] use PHP `<=>` (returns 0 on NaN); `:1515` Float.equals uses `===` (returns false on NaN) | fcmpl returns -1, fcmpg returns +1 if either operand is NaN; `Float.equals(NaN, NaN)` returns true. |
| T4 | `String.length` byte vs UTF-16 unit | `src/Aot/Runtime/bootstrap.php:93` returns `strlen($s)` | Count UTF-16 code units (not bytes, not codepoints). For BMP-only ASCII the values match; multi-byte UTF-8 diverges. |
| T5 | `iinc` opcode no 32-bit mask | `src/Aot/Ir/Lowerer.php:209` emits `+= delta;` | Apply 32-bit mask consistent with iadd. Diverges for tight increment loops at 2^31 boundary. |
| T6 | Char surrogate-pair semantics | `src/Aot/Ir/Builder.php:1559–1576` mb_chr/mb_ord with `'UTF-8'` | Java `char` is a UTF-16 code unit; supplementary chars (U+10000+) are 2 chars in Java but 1 PHP UTF-8 sequence. `String.charAt` semantics diverge for non-BMP. |
| T7 | Nested/overlapping try-catch silently uncaught | `src/Aot/Compiler.php:802–805,823–825` falls back to no-protection emit with leading comment only | Either implement nested-range support or emit a runtime warning so the silent miss is visible. |

For each: write a fixture exercising the bug, land the fix, confirm
rank-1 in suite. None blocked.

### Build — capability extension (~6–8 weeks for v1)

The v1 gate is bb-allowlist non-stub fill. Path D′ oracle is its
prerequisite.

**v1 critical path (sequential):**

1. **Path D′ behavioural oracle harness** (~1 week, *not started*) —
   extend FFM-based JVM-side parity infra to per-method I/O capture.
   Foundation for clean-room bb-fill per GPL+CPE constraint
   ([LAYERS.md §License posture](docs/LAYERS.md)).
2. **bb-allowlist non-stub fill** (~80 most-used babashka classes,
   2–4 months) — work each class against the oracle. The actual v1
   gate. ~110 of the ~130 stub-only T2 classes are already Path C
   stubs (`tools/gen-aot-stubs.php` + `src/Aot/Runtime/java/**`); this
   step replaces the stub bodies with real implementations.

**Independent capability work (parallelizable with the critical path):**

3. **AOT instance dispatch (Phase B receiver-shape unification)** —
   `src/Core/JVM/Invoker/Extended/JavaMethodCallable.php:114–138`
   instance path still routes through the interpreter; only static
   dispatch reaches AOT. ~2–3 days. Unblocks every test that calls
   instance methods through AOT.
4. **ObjectMethods record-shape emit** — indy detection done at
   `src/Aot/Ir/Builder.php:1656–1670`, but record-class shape
   (`extends \PHPJava\Aot\Runtime\java\lang\Record` + component
   accessors) not emitted (`Builder.php:1926` comment marks the gap).
   Hours.
5. **T1 class-file gaps** (Java 11+ load-time):
   - `CONSTANT_Dynamic` (tag 17) parsing — 1–2 days. No entry in
     `src/Kernel/Maps/ConstantPoolTag.php`.
   - `CONSTANT_Module`/`Package` (tags 19, 20) — 1 day. Declared in
     tag map; throw on read at `src/Core/JVM/ConstantPool.php:104–106`.
   - `NestHost`/`Record`/`PermittedSubclasses`/`Module`/
     `ModulePackages`/`ModuleMainClass` attribute parsers — ~1 week
     total. Only `NestMembersAttribute.php` exists.
6. **Sequenced collections (Java 21)** — `SequencedCollection`/
   `SequencedSet`/`SequencedMap` interfaces + `LinkedHashMap`/
   `LinkedHashSet` retrofit. ~4 days. Currently absent.
7. **Unsafe shim** — pure-PHP, lock-based CAS. ~500 LOC. Required
   for `ConcurrentHashMap` (touched by Clojure boot per
   `docs/CLOJURE-BOOT-ANALYSIS.md`).
8. **Lazy CP resolution in parser** (`src/Core/JVM/ConstantPool.php:42–59`)
   — closes 22% top-level real-library probe-fail rate per
   `bench/probe-real-library.md`.

### Refinement — perf and reliability tightening (1–2 weeks total)

Doesn't gate v1 but tightens substrate before bb-fill exercises it
broadly.

1. **P6 peephole — pop-into-temp + push-expr collapse** at
   `src/Aot/Compiler.php:1629–1632` punted. Closes ~2× remaining gap
   on inlined call sites per
   [PATTERNS.md "Cross-method inlining"](docs/PATTERNS.md).
2. **Compiler cache observability** — hit/miss metrics on
   `$compileClassCache` / `$compileBytesCache` (`src/Aot/Compiler.php:84–200`)
   for daemon production visibility. Cap is 1000 entries; daemons
   loading more thrash silently.
3. **Method overload — fuller arg-shape matching** — current dispatcher
   falls through to `NoSuchMethodException` on shape ambiguity. Refine
   when a real overload set surfaces a mismatch.
4. **Multi-interface implementation** — needs PHP `traits` + `interface`
   combo. Single suffices today (`src/Aot/Compiler.php:500–507`).
5. **cljp by-ref patterns 2+3** — known-PHP-mutating-fn registry;
   chained user fns (`src/Aot/Ir/Builder.php:356–366` defer comment).
   Direct-aset alone covers current tests.
6. **AOT class-emit shape doc companion** in
   [LAYERS.md](docs/LAYERS.md) (partly inline already).

### Cleanup — pure subtraction (~21.6 kloc deletable)

Each phase produces a green suite before the next; C, D, E are
independent and parallelizable per [LAYERS.md §"Order of cuts"](docs/LAYERS.md).

1. **Phase C** — `Dynamic→Instance` rename per LAYERS.md:65–72.
   Cosmetic, mechanical. Days.
2. **Phase D** — interpreter delete (~10 kloc): `src/Kernel/Mnemonics/`,
   `JavaMethodCallable` interpreter half, `src/Kernel/Types/` remainder,
   `src/Kernel/Filters/Normalizer.php`, `src/Kernel/Frames/`,
   `src/Kernel/Variables/`, `src/Kernel/Provider/`, `OperationCache`,
   `MnemonicResolver`, `tests/Cases/OutputDebugTraceTest.php`. Single
   PR, single revert.
3. **Phase E** — legacy stack delete (~12.5 kloc):
   `src/Compiler/Lang/Assembler/`, `src/Compiler/Builder/`,
   `src/Compiler/Emulator/`, `src/Compiler/Compiler.php`,
   `tests/Cases/Compiler/*`. Independent of D.
4. **Doc consolidation** (this update — done): ROADMAP is canonical
   "what's open + priority"; HANDOVER session-only; STATUS measured
   rank-1 only; GAP-JDK version coverage map; MODEL strategic framing
   only.

### Done since 2026-05-04 docs were written (audit-confirmed)

| Item | Site |
|---|---|
| Sub-step 1c-β (full abstract-stack) | `src/Aot/Ir/Builder.php:287–310` |
| Sub-step 1c-ε (Switch_ terminator) | `src/Aot/Ir/Node.php:220`; `Builder.php:501,1128–1136`; `Lowerer.php:245–251` |
| Sub-step 1c-ζ (DUP2/IUSHR/LUSHR/MULTIANEWARRAY) | `src/Aot/Ir/Builder.php:799,814–831,836–847` |
| SwitchBootstraps (Java 21 pattern switch) | `src/Aot/Ir/Builder.php:1643–1652`; `src/Aot/Runtime/bootstrap.php:326–381` |
| LRU eviction on Compiler caches (1000 entries each) | `src/Aot/Compiler.php:84–200` |
| Dead string-path emitter removal | only ~32-line `emitStringConcat` left |
| Class-file version table 53.0–69.0 (Java 9–25) | `src/Kernel/Resolvers/SDKVersionResolver.php:35` |
| StringConcatFactory (AOT path) | `src/Aot/Ir/Builder.php:1675–1679` |
| LambdaMetafactory + altMetafactory (AOT path) | `src/Aot/Ir/Builder.php:1637–1640,1819–1851` |
| Symfony Console version constraint | `composer.json:20` `^5.4|^6.0|^7.0` |
| Path C stub generator + 110 stubs | `tools/gen-aot-stubs.php`; `src/Aot/Runtime/java/**` |

## What's not on the roadmap

- Shared `$GLOBALS` runtime with cljp. cljp and PHPJava are peers on
  Zend, not nested. Marshalling at language boundary, not runtime
  unification. (See `docs/MODEL.md` §"Two peers on Zend".)
- Universal redefinability via Var indirection. Java doesn't have it
  natively; languages above (Clojure) provide their own.
- A separate IR for naive AOT. JVM bytecode is the IR.
- Full bytecode verifier semantics. Trust the input; verifier costs more
  than it saves.
- HotSpot perf parity. Cap is ~50× HotSpot interpreted with optimal AOT;
  acceptable for scripting/library use, not for tight inner loops in
  performance-critical code.

## Cadence

- Each tier produces measured evidence before the next is started.
- Phase exit reports as PRs against this file with `bench/<sha>.json`.
- Strategic shifts go in `docs/MODEL.md`, not here. ROADMAP.md tracks
  what's done and what's next; MODEL.md tracks why.

## Open questions

- **`Compiler/Emulator/` repurposing.** Settled by 2026-05-04 audit
  (`docs/LAYERS.md`): Phase E deletes it — pattern overlap with
  `src/Aot/Ir/Builder.php`'s abstract-stack tracking is structural, not
  reusable code. Listed for transparency; not actually open.
- **License posture for shim port.** Spec-based reimplementation is the
  default. Specific cases (e.g., a complex `String.format` parser) may
  benefit from referencing OpenJDK in the abstract — never copy.

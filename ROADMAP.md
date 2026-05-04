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

**Sub-step 1c-β: full abstract-stack tracking.** Catches the cases the
peephole misses — push+push+arith-without-immediate-store, cross-block
stack flow, exception-handler entry stacks. Estimated 4–8h on top of
1c-α. Probably ~1.5× additional headroom (per spike's hand-emit
idiomatic at 0.2 ns/op vs our 0.18 — the peephole already absorbed
most of the value).

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

**Sub-step 1c-β: full abstract-stack tracking — REMAINING.** Catches
the cases the peephole + IR-bake-in misses — push+push+arith-
without-immediate-store, cross-block stack flow, non-empty stack at
exception-handler entry. Estimated 4–8h on top of 1c-α. Perf
headroom probably ~1.5× (per spike's hand-emit idiomatic at 0.2 ns/op
vs our 0.18). **Coverage impact much larger than perf impact:** the
2026-05-03 commons-lang3 probe (`bench/probe-real-library.md`) found
this single issue accounts for **95% of remaining IR fallbacks** —
landing 1c-β lifts production-bytecode IR coverage from 86.5% to ~99%.

**Sub-step 1c-ε: switch terminator (TABLESWITCH/LOOKUPSWITCH).** New
`Switch` Terminator IR node + Lowerer match/switch rendering.
Currently 19 fallbacks on commons-lang3 (4% of remaining). ~hours.

**Sub-step 1c-ζ: rare-opcode tail.** DUP2 / LUSHR / IUSHR /
MULTIANEWARRAY — 5 fallbacks total on commons-lang3, 1% of remaining.
~hour total.

### Work plan (ordered, post-2026-05-03 probe)

Tractable in ~1–3 sessions. Skip soak test (#7, 24h elapsed time),
boxing refactor (#11/#12, 1–2wks), Tier 2 shim (#13, months).

1. **Sub-step 1c-β** — full abstract-stack tracking. Architecture +
   95% of remaining probe fallbacks.
2. **Sub-step 1c-ε** — switch terminator. Closes IR coverage to ~99%
   when combined with 1c-β.
3. **Lazy CP resolution in PHPJava parser** — fixes the 22% top-level
   probe-fail rate (`ClassNotFoundException` during parse). Real
   architectural cleanup; unblocks running real-library probes
   without bespoke classpath plumbing.
4. **Sub-step 1c-ζ** — DUP2/LUSHR/IUSHR/MULTIANEWARRAY tail.
5. **AOT classloader integration into `JavaClass::load`** — wires
   eager/lazy AOT strategies per CONTRACTS.md §3 + §5. Production
   reachability: AOT currently only via direct `Compiler::compileBytes`.
6. **LRU eviction on Compiler caches** — daemon-safety prereq;
   currently `compileBytes/compileClass` static caches grow unbounded.
7. **ObjectMethods bootstrap** — Java records' equals/hashCode/toString.
8. **SwitchBootstraps** — Java 21+ pattern switch.
9. Symfony Console version mismatch — minutes.
10. Remove dead string-path emitter — **deferred**. After 1+2+4
    landed, the IR path covers 100% of commons-lang3. But "provably
    redundant" needs broader rank-1 evidence (PDFBox / Tika / a real
    multi-JAR probe) before removing the 1500-line fallback. Rolling
    back accidental gaps would be a real cost; the fallback's
    defensive value still exceeds its maintenance cost. Re-evaluate
    after Tier 3 probe Q3.1 (real-Java-library end-to-end run).

Reference: `bench/probe-real-library.md` is the rank-1 evidence
driving this ordering.

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

- **Symfony Console version.** PHPJava uses `symfony/console: ^5.2`;
  current 6.x and 7.x require `int` return from `Command::execute()`.
  Cosmetic but visible. Tier 0 housekeeping.
- **`Compiler/Emulator/` repurposing.** The existing parallel mnemonics
  tree at `Compiler/Emulator/Mnemonics/` does abstract type tracking at
  build time. Tier 1b's emit pass shares 90% of that pattern. Possibly
  rename / repurpose rather than create a third parallel tree.
- **License posture for shim port.** Spec-based reimplementation is the
  default. Specific cases (e.g., a complex `String.format` parser) may
  benefit from referencing OpenJDK in the abstract — never copy.

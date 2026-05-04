# PHPJava — hbtweb fork

> Active branch: `roadmap` — the strategic-rework branch, not yet merged
> to master. Upstream PHPJava (php-java/php-java) at `b34a90a` (2021).

This fork takes the original PHPJava project (a JVM bytecode interpreter
in PHP) and pursues a different goal: **a JVM-bytecode-to-PHP translator
for long-running PHP processes**, with selectable cache strategies (eager
AOT / lazy AOT / interpret-fallback) sharing one compiler.

The architectural model is in [`docs/MODEL.md`](docs/MODEL.md). Read it
first.

## What's working (2026-05-04)

- ✓ **PHPUnit suite green: 45 pass / 2 skipped / 0 failures / 0 errors**
  (47 case files; skipped are `KotlinTest` and `OutputDebugTraceTest`,
  both deliberate per `docs/STATUS.md`). Three of four v1 success-line
  conditions met (only the bb allowlist remains).
- ✓ **AOT is the default execution path** — no env gate, since `7f01155`.
  `JavaMethodCallable::call` routes through `Loader::tryCallStatic` for
  static dispatch; instance dispatch via Phase B receiver-shape
  unification.
- ✓ **AOT pipeline coverage** (this session's additions in **bold**):
  invokestatic / virtual / special / interface · exception tables ·
  INVOKEDYNAMIC for StringConcatFactory + LambdaMetafactory +
  ObjectMethods + SwitchBootstraps · `defineClass(byte[])` · `IndyRegistry`
  for unknown bootstraps · **inheritance (`extends` + `parent::__construct`
  chain)** · **interface compilation (Java interface → PHP `abstract
  class` with default-method bodies)** · **method overload (descriptor-
  mangled names + arg-shape runtime dispatcher)** · **array by-ref
  auto-detect (cljp-port: `aset` on a param emits `&$__aN` + `&` alias
  in the `$L` prelude)** · **contract-shape Z (boolean) and C (char)
  field/array storage with `mb_chr`/`mb_ord` UTF-8 round-trip** ·
  **wrapper-class IR lowerings per BOXING.md (`Integer.MAX_VALUE` →
  `IntLit`, `i.intValue()` → identity, `i.equals(j)` → `===`, etc.)**.
- ✓ **AOT-compiled hot-path perf**: `BenchAdd::sum1k` at 0.18-0.20 ns/op
  JIT — within 1.8× of HotSpot JIT (~0.10 ns/op), 2.9× faster than
  HotSpot interpreted (0.52 ns/op). 22,000× faster than upstream
  PHPJava interpreter.
- ✓ **IR substrate**: Module + Method + BasicBlock + Stmt + Terminator
  + Expr (`src/Aot/Ir/`). Stack-erasure baked in via abstract-stack
  tracking at build time. Cross-method inlining as IR transform pass.
  Escape analysis on Java arrays.
- ✓ **Compile-output cache: 2649× speedup** on repeat compiles
  (1578 µs cold → 0.6 µs warm).
- ✓ Architectural contracts spec ([`docs/CONTRACTS.md`](docs/CONTRACTS.md))
  + AOT raw-scalar contract enforced end-to-end this session.

## What's next

The architecture is no longer the bottleneck. Three of four v1
success-line conditions are met (suite green, interp ≤ 100 ns/op,
AOT ≤ 5 ns/op). **The remaining v1 thrust is JDK shim coverage** —
specifically the bb allowlist (~80 most-used classes from babashka's
`src/babashka/impl/classes.clj`).

**JDK surface fill (the v1-binding work):**
1. **Stub generator (~1 day)** — javap-driven; emit PHP class
   declarations with `NotImplementedException` bodies for the 130
   stub-only T2 classes. Closes "class not found" runtime errors.
2. **Behavioural oracle harness (~1 week)** — extend PHPJava's FFM-
   based JVM-side parity infrastructure to per-method I/O capture.
   Foundation for clean-room shim authoring per the GPL+CPE constraint
   in `docs/LAYERS.md`.
3. **bb allowlist class fill** — work the ~80 classes one at a time
   against the oracle. Each should be "small enough to author in a
   day or two" given AOT correctness.

**Cleanup (Phase D — interpreter delete):**
4. ~10 kloc removal of `Kernel/Mnemonics/_*`, related interp dispatch.
   Suite is green; AOT covers everything; interp is no longer
   load-bearing. The `OutputDebugTraceTest` skip is the only test-
   surface trace.

**Cleanup (Phase E — legacy stack cut):**
5. ~12.5 kloc removal of `Compiler/Lang/Assembler/`,
   `Compiler/Builder/`, `Compiler/Emulator/`. Independent of D.

**End-state milestone:** **Clojure boot on PHPJava AOT, in a Swoole
daemon, sustained.** The AOT pipeline is correctness-complete for this;
what's left is shim-layer fill.

## Document map

Read in this order if landing fresh on the repo:

**Strategic / why:**
- [`docs/MODEL.md`](docs/MODEL.md) — architectural model (the keystone)
- [`docs/ADJACENT-SHAPES.md`](docs/ADJACENT-SHAPES.md) — TeaVM, bb, cljp, where we sit

**Specs / what (normative):**
- [`docs/CONTRACTS.md`](docs/CONTRACTS.md) — interface boundaries, value-rep, dispatch decisions
- [`docs/PATTERNS.md`](docs/PATTERNS.md) — the five measured rules + what to subtract
- [`docs/BOXING.md`](docs/BOXING.md) — wrappers gutted; rationale + JVM-contract mapping

**Empirical / measured:**
- [`docs/STATUS.md`](docs/STATUS.md) — where we are right now (decisions, measurements)
- [`docs/BOTTLENECKS.md`](docs/BOTTLENECKS.md) — every measured cost + type emulation guidance
- [`docs/JVM-PHP-DELTA.md`](docs/JVM-PHP-DELTA.md) — platform/semantic/API differences (rank-1 verified, with PHP and Java behaviors probed locally)
- [`docs/GAP-JDK.md`](docs/GAP-JDK.md) — Java 19 → 21 → 25 deltas, prioritised work list
- [`docs/CLOJURE-BOOT-ANALYSIS.md`](docs/CLOJURE-BOOT-ANALYSIS.md) — empirical Clojure class-load trace
- [`bench/README.md`](bench/README.md) — benchmark methodology + LD_PRELOAD finding
- [`bench/profile-c930e2c.md`](bench/profile-c930e2c.md) — xhprof profile attribution
- [`bench/PATTERN-VALIDATION.md`](bench/PATTERN-VALIDATION.md) — dispatch/array/boxing measurements

**Operational / next:**
- [`ROADMAP.md`](ROADMAP.md) — tier ordering, exit criteria
- [`HANDOVER.md`](HANDOVER.md) — what was done in the rework session, what's next

**Measurement harnesses (in `bench/`):**

*Original (Tier-0 validation):*
- `validate-patterns.php` — dispatch shape comparison
- `validate-hotloop.php` — tight-loop variants (interp vs AOT)
- `validate-datastructures.php` — array vs SplFixedArray vs frame object
- `validate-boxing.php` — 6 boxing patterns × 5 workloads
- `validate-remaining.php` — strings, exceptions, numeric, static fields
- `spike-fast-interp.php` — interpreter vs AOT comparison

*AOT pipeline (Tier 1b — naive AOT):*
- `aot-compile.php`, `aot-invoke.php`, `aot-helloworld.php`,
  `aot-array.php`, `aot-trycatch.php`, `aot-concat.php`,
  `aot-lambda.php`, `aot-definebytes.php` — per-fixture functional verification
- `bench-aot.php` — rank-1 perf (use `-d opcache.jit_buffer_size=1024M`)
- `contract.php` — drift gate (snapshot+diff over 9 fixtures)

*Zend JIT capability profile:*
- `jit-claims.php` — 7 tier-4 optimisation tests (CSE/LICM/inlining/EA/...)
- `jit-claims-extended.php` — 12 more (loop unroll, devirt, branch pred, ...)
- `jit-claims-ext.php` — 17 PHP-extension overhead measurements (BCMath, GMP, mbstring, regex, hash, openssl, sodium, zlib)

*IR migration validation:*
- `test-ir-roundtrip.php` — hand-built IR roundtrip
- `test-ir-bench.php` — bytecode → IR → PHP end-to-end
- `test-ir-vs-string.php` — head-to-head IR-path vs string-path
- `test-ir-falsifier.php`, `-f3.php`, `-f4.php` — F-IR1 through F-IR4
- `test-flat-vs-oop.php` — F-IR5 (flat-array IR PoC; falsified)
- `test-ir-compile-speed.php` — compile-time profile (parse vs IR)
- `test-ir-profile.php` — phase breakdown (build vs lower)
- `test-compile-cache.php` — compile-output cache 2649× speedup verification
- `test-phpparser-poc.php` — alternative-IR PoC (PhpParser AST manipulation)

## Original PHPJava docs

The upstream README and docs (`README.md`, `docs/compiler/`, `docs/jvm-lang/`,
`README-ja.md`) cover the original project's design — the JVM bytecode
interpreter and the PHP-syntax-to-bytecode-compiler features. Those
remain as-is for reference.

## Contributing

This fork is in early architectural-rework phase. Before opening PRs:

1. Read `docs/MODEL.md` and `docs/CONTRACTS.md`. These are normative.
2. Check `docs/STATUS.md` for current state and ranked next steps.
3. PRs that violate contracts should update `docs/CONTRACTS.md` first
   with rationale + impact analysis.

## Branding note

The "PHPJava" name remains for now. The strategic ambition (long-running
deployments, AOT compilation, multi-language JVM substrate) is meaningfully
different from upstream's "fun JVM in PHP" demo. A name change may follow
once the architecture stabilises.

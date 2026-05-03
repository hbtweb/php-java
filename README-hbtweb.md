# PHPJava — hbtweb fork

> Active branch: `roadmap` — the strategic-rework branch, not yet merged
> to master. Upstream PHPJava (php-java/php-java) at `b34a90a` (2021).

This fork takes the original PHPJava project (a JVM bytecode interpreter
in PHP) and pursues a different goal: **a JVM-bytecode-to-PHP translator
for long-running PHP processes**, with selectable cache strategies (eager
AOT / lazy AOT / interpret-fallback) sharing one compiler.

The architectural model is in [`docs/MODEL.md`](docs/MODEL.md). Read it
first.

## What's working (2026-05-03)

- ✓ Test suite unblocked on JDK 25 (`javac --release 11`)
- ✓ Bench harness via FFM (calls libphp directly from JVM)
- ✓ Profile harness via xhprof (LD_PRELOAD trick documented)
- ✓ Real AOT compiler — walks PHPJava's parsed bytecode, emits PHP, runs
- ✓ **9 fixtures lift through the AOT pipeline end-to-end:** BenchAdd
  (int loop), BenchInvoke (invokestatic), HelloWorld (cross-class
  invokevirtual + getstatic + ldc), BenchArray (newarray + iastore +
  iaload), BenchTryCatch (new + invokespecial<init> + athrow + exception
  table), BenchConcat (StringConcatFactory invokedynamic), BenchLambda
  (LambdaMetafactory invokedynamic + synthetic class generation),
  BenchAddFromBytes (defineClass(byte[])), BenchRunner.
- ✓ **AOT-compiled `BenchAdd::sum1k` at 0.18-0.20 ns/op JIT** — within
  1.8× of HotSpot JIT (~0.10 ns/op), 2.9× faster than HotSpot
  interpreted (0.52 ns/op). 22,000× faster than upstream PHPJava
  interpreter.
- ✓ **IR substrate**: Module + Method + BasicBlock + Stmt + Terminator
  + Expr (`src/Aot/Ir/`). All 9 fixtures lift through the IR with zero
  string-path fallbacks. Stack-erasure baked in via abstract-stack
  tracking at build time. Cross-method inlining as IR transform pass.
  Escape analysis on Java arrays (LocalRead-source detection in
  iaload/iastore — closes the measured 10× property-access cost).
- ✓ **Compile-output cache: 2649× speedup** on repeat compiles
  (1578 µs cold → 0.6 µs warm). For Clojure-boot equivalent (~600
  classes per `docs/CLOJURE-BOOT-ANALYSIS.md`): cold ~947 ms, cache
  replay ~0 ms. Makes runtime AOT viable.
- ✓ Architectural contracts spec ([`docs/CONTRACTS.md`](docs/CONTRACTS.md))

## What's next

**Capability fill (parallelisable):**
1. ObjectMethods bootstrap (Java records' `equals`/`hashCode`/`toString`)
2. SwitchBootstraps (Java 21+ pattern switch)
3. 233-class T2 JDK shim layer (months, can run in parallel)

**Production hardening:**
4. LRU eviction for Compiler caches (long-running daemons)
5. Long-running Swoole soak test (24h sustained load)
6. AOT classloader integration into `JavaClass::load` per CONTRACTS.md §5

**Optional perf squeeze (within 1.8× of HotSpot JIT already):**
7. Constant folding + DCE at IR level — small wins
8. Full abstract-stack tracking for non-empty BB-entry stacks

**Cleanup:**
9. Remove dead string-path emitter code now that IR is the default

End-state milestone: **Run a real Java library (e.g. PDFBox, iText)
AOT-compiled, callable from PHP, in a Swoole daemon for 24h without
memory growth.** The AOT pipeline is feature-complete for this; what's
left is shim-layer fill and production-hardening.

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

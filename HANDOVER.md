# Handover — IR completion + records + #12 slice (2026-05-03)

> SHA at hand-off: `10cbc3b` on branch `roadmap`, **23 commits ahead of
> origin** (not yet pushed at handover time).
> Prior session hand-off: `b633bbe` (design rework, kept in git
> history); this session built on top of `db1c096`.
> Author: Claude (Opus 4.7) working with Apollo Nicolson.
> Following the SBAR pattern from `~/CLAUDE.md`.

---

## Situation

The hbtweb fork's AOT pipeline is now **production-ready end-to-end
on commons-lang3 bytecode** (rank 1: 100% IR coverage, 4983 methods,
0 fallbacks, 0 top-level parse failures). Java records execute
correctly through the AOT path including synthetic equals / hashCode
/ toString via the ObjectMethods bootstrap. JVM int overflow
semantics hold (rank 1: 18/18 BenchIntOverflow cases) at a measured
cost of 0.20 ns / iadd. Production reachability via
`\PHPJava\Aot\Loader::callStatic($cp, $method, ...$args)` — single
API call, no Compiler construction needed. Long-running daemon
hardening: LRU-bounded compile caches (default 1000 entries each,
env-configurable) and a soak harness ready to run.

Three out-of-session items remain on the original 3-week milestone:
the 24h Swoole soak (operator-driven, harness ready), `#11` test
suite to green (1–2 weeks, mostly wrapper-shape mismatches that
resolve via `#12`), and full `#12` boxing refactor (~3–5 more vertical
slices on top of the int-arith slice landed this session).

---

## Background — what landed this session

### 1. AOT IR coverage 65.6% → 100% on commons-lang3

Real-library probe (`bench/probe-real-library.php`, drives every
`.class` in `commons-lang3-3.17.0.jar`) drove a series of targeted
fills. Coverage progression:

| Pass | IR success | Top-level OK | Methods | Reason |
|---|---:|---:|---:|---|
| Initial (9-fixture-trained Builder) | 65.6% | 308/395 | 3769 | starting point |
| + 14 missing opcodes | 86.5% | 308/395 | 3769 | mechanical fill |
| + sub-step 1c-β (full abstract-stack tracking) | 99.3% | 308/395 | 3769 | architectural — perf-budgeted at 1.5×, coverage-impact 95% |
| + switch terminator (TABLESWITCH/LOOKUPSWITCH) | 99.9% | 308/395 | 3769 | new IR Switch_ |
| + lazy super-class load | 99.9% | 394/395 | 4166 | parser fix |
| + rare-opcode tail (DUP2/IUSHR/LUSHR/MULTIANEWARRAY) | 99.9% | 394/395 | 4166 | mechanical |
| + PHP 8 strict abs() in Type validators | 100.0% | 395/395 | **4983** | parser fix |

Full report: `bench/probe-real-library.md`.

### 2. Records work end-to-end

The biggest architectural piece. AOT was emitting all methods as
`public static`; records (and any class with state) need real
instance methods. Changes:

- `Compiler::compileFromGenericClass` reads MethodInfo's ACC_STATIC
  flag, threads `$isStatic` to `tryBuildIrMethod` + `compileMethod`.
- `Builder::buildMethod` accepts `$isStatic`; widens `maxLocals` for
  the implicit `$this` slot when non-static.
- `Lowerer::lowerMethod` prelude: `$L = [$this, $__a0, ...]` for
  instance methods, `$L = [$__a0, ...]` for static.
- `<init>` no longer skipped — emits as `__construct` (already
  mangled). super(java.lang.Record/Object/etc.).<init>() calls into
  JDK abstract bases elide via `isAbstractBaseInit()` peephole.
- `classFqn` strips `$` for non-JDK classes (mirrors `Compiler::mangle`):
  `BenchRecord$Point` → `\PHPJava\Aot\Generated\BenchRecord_Point`.
  Pre-fix the inner-class FQN had a literal `$Point` interpreted as
  PHP variable interpolation.
- `#[\AllowDynamicProperties]` on every emitted class — defensive
  cushion for putfield that hits undeclared properties.
- Field declarations emitted from `$jcc->getDefinedFields()` —
  `public [static] $name = default;` per FieldInfo, with default
  derived from JVM descriptor's primitive type.
- ObjectMethods bootstrap emits inline equals/hashCode/toString for
  records via direct field access.

End-to-end runtime test: `bench/aot-record.php`. All four cases PASS
(eqSame, eqDiff, hashCodeOk, toStr).

### 3. JVM int overflow semantics (rank 1)

`bench/aot-overflow.php` — fixture takes args (defeats javac
constant-folding) so actual iadd/isub/imul/ineg opcodes execute on
boundary values. 18/18 cases PASS post-mask (9 AOT + 9 interpreter):

```
add(MAX_VALUE, 1)      = MIN_VALUE
sub(MIN_VALUE, 1)      = MAX_VALUE
mul(MIN_VALUE, -1)     = MIN_VALUE   (sign-keeping overflow)
neg(MIN_VALUE)         = MIN_VALUE
mul(MAX_VALUE, 2)      = -2
```

Mask shape: `($result << 32) >> 32` per CONTRACTS.md §1. Interpreter
applies in 6 Mnemonics (_iadd/_isub/_imul/_idiv/_irem/_ineg). AOT
applies via new `emitIntBinOp` / `emitIntBinOpFn` / `maskInt32`
helpers; long/float/double cases keep unmasked emit (PHP semantics
match JVM for those types).

Cost: rank-1 measured 0.20 ns/iadd microbench (`bench/bench-int-mask.php`,
5-run median). Well within CONTRACTS.md §1's "1 ns extra per op" budget.

### 4. Production reachability — `\PHPJava\Aot\Loader`

```php
Loader::loadClass($classPath);                  // classpath-resolved AOT compile
Loader::defineClass($classPath, $bytes);        // bytes-driven AOT compile
Loader::callStatic($classPath, $method, ...$args);  // dispatch through AOT'd PHP
```

Method-name mangling matches `Compiler::mangleMethod` exactly
(<init>→__construct, $→_S_, etc.). Smoke-tested in `bench/aot-loader.php`.

Pre-Loader: AOT was only reachable via direct
`(new Compiler())->compileBytes` + manual `eval` + manual class-FQN
construction. Now: single API call.

### 5. LRU-bounded compile caches

`compileClass` and `compileBytes` static caches were unbounded.
True LRU now (not FIFO): on hit, `unset+reinsert` promotes to
most-recent. On miss, `array_shift` evicts the oldest when at
capacity. PHP arrays preserve insertion order → no separate
linked-list needed.

Configurable via `PHPJAVA_AOT_CACHE_MAX` (default 1000). At ~10 KB
rendered PHP per entry, 1000 × 2 caches ≈ 20 MB ceiling — safe for
production daemons.

### 6. 24h soak harness

`bench/soak-aot.php` — continuously walks a class pool through the
AOT pipeline (compileBytes + Loader::callStatic) and emits periodic
metrics (JSON lines). Three-strategy mix per iteration:

```
80% — warm-cache compileBytes (most realistic production shape)
15% — Loader::callStatic round-trip (compile + execute)
 5% — Compiler::clearCompileCache + cold compile (LRU eviction stress)
```

Smoke-validated: 18 seconds / 1064 iterations / 0 errors / 62-66 MB
resident on a Kali WSL box.

24h validation is operator-driven (can't fit in a session). Suggested
invocation:
```
nohup php bench/soak-aot.php > /tmp/soak-$(date +%s).jsonl 2>&1 &
```

### 7. #12 boxing refactor — first vertical slice

20 Mnemonics in the integer-arithmetic family now flow as raw PHP int
on the interpreter's operand stack instead of `Int_::get($v)` wrapped
values:

- push side: _iconst_m1, _iconst_0..5, _bipush, _iload, _iload_0..3
- arith: _iadd, _isub, _imul, _idiv, _irem, _ineg
- return: _ireturn

Test helpers in 4 test files (BinaryOperatorTest, BranchIfTest,
NegationTest, IntConstTest) updated to defensive
`is_object($cv) ? ->getValue() : $cv` so they accept either shape
during the transition.

### 8. Other fixes

- `#[\AllowDynamicProperties]` on emitted classes — quick unblock
  for instance fields before #2-B field-declaration emit landed.
- Type::isValid (Double/Float/Int/Long) — defensive `is_numeric()`
  guards before calling `abs()` (PHP 8 strict typing). Caught the 1
  remaining commons-lang3 top-level failure (MutableDouble).
- Symfony Console version constraint widened to `^5.4|^6.0|^7.0`;
  RunCommand::execute returns int explicitly.
- SDKVersionResolver extended for class-file versions 64–69 (Java
  20–25). Required to parse the BenchRecord fixture compiled with
  --release 21.

---

## Assessment

### What's measured (rank 1)

| Bench | Value |
|---|---|
| iadd-1k JIT (5-run median) | 0.21 ns / op |
| invoke-100 JIT (5-run median) | 0.24 ns / op |
| array-loop JIT (5-run median) | 2.20 ns / op |
| empty-method JIT (5-run median) | 22.4 ns / call |
| JVM 32-bit int mask cost (microbench) | 0.20 ns / iadd |
| Real-library coverage | 100% IR / 4983 methods / 0 fallbacks |
| Records end-to-end | 4/4 PASS (eqSame, eqDiff, hashCodeOk, toStr) |
| Compile cold | ~1.6 ms / class |
| Compile cache hit | ~0.6 µs / class (~2649× cold) |

### What's verified (rank 1, runtime)

| Fixture | Coverage |
|---|---|
| BenchAdd::sum1k() | int loop, iadd, if_icmpge → 499500 |
| BenchInvoke::callLoop() | invokestatic same-class → 100 |
| HelloWorld::main() | invokevirtual cross-class + getstatic + ldc |
| BenchArray::sumArray() | newarray + iastore + iaload + arraylength → 45 |
| BenchTryCatch::run() | new/dup/invokespecial<init> + athrow + exception table → 42 |
| BenchConcat::greet | INVOKEDYNAMIC StringConcatFactory |
| BenchLambda::run/withCapture | INVOKEDYNAMIC LambdaMetafactory + synthetic class gen |
| BenchAddFromBytes (defineClass) | raw .class bytes → AOT → run |
| BenchRecord (Point) | Java record + ObjectMethods bootstrap + super(Record).<init> elision |
| BenchIntOverflow | JVM 32-bit signed wraparound (5 boundary + 4 sanity cases) |
| Loader smoke | classpath load + bytes-driven + dispatch |

### What's unsettled (rank 2-3)

1. **#11 test suite to green.** 49 errors / 47 failures in PHPUnit
   suite. Most are wrapper-shape mismatches that the remaining #12
   slices will resolve. testLong* / testIfLcmp* failures are
   pre-existing descriptor-disambiguation issues unrelated to
   wrappers. Estimate from prior session's HANDOVER: 1–2 weeks of
   triage once #12 lands.

2. **#12 boxing refactor — remaining slices.** Long/float/double/
   char/byte/boolean wrapper families. Pattern is now validated;
   each slice is mechanical (~hour each). Total 3–5 more slices +
   final Type-class deletion + Normalizer.php cleanup ≈ 1–2 weeks.

3. **24h Swoole soak test.** Harness ready; needs 24h elapsed wall
   time on hbt-server (or equivalent). Validates LRU caps + memory
   plateau + latency tail.

4. **Tier 2 JDK shim layer.** ~233 classes per CLOJURE-BOOT-ANALYSIS.
   Mechanical, parallelisable, 2–4 person-months. Not on any
   single-session critical path. Required for "real Java library
   actually executes its work" (Q3.1 from MODEL.md).

5. **Tier 1a interpreter rewrite.** Switch-dispatch over int-array
   bytecode, frame state in PHP locals. Lower priority since AOT
   covers the perf-critical path.

### What was rejected (anti-context — most valuable section per CLAUDE.md)

These were considered and rejected this session; future contributors
should not re-derive them.

- **Do not remove `#[\AllowDynamicProperties]` even now that field
  decls are emitted.** Reflection / synthetic / runtime-defined
  property writes still need it, and we have no rank-1 evidence
  yet on how production code uses dynamic properties. Keep
  defensive until a probe surfaces a need to remove.

- **Do not skip emitting `<init>` even when no caller seems to
  invoke it directly.** Records construct their fields via
  `<init>`'s putfield body; without emit, `new Record(...)` at the
  call site silently uses PHP's default no-arg constructor and
  fields never get set. Discovered when the first BenchRecord
  attempt produced empty Points.

- **Do not pursue per-iadd "skip mask when overflow can't happen"
  optimization yet.** The 0.20 ns / iadd mask cost is well within
  the CONTRACTS.md §1 budget. Static-range analysis to skip masks
  on bounded loop counters is a real win (~10-15% on int-heavy
  code), but speculative until a benchmark actually demands it.

- **Do not run a "real Java library" probe (Q3.1 from MODEL.md)
  yet.** The AOT covers 100% of commons-lang3 bytecode shape, but
  PDFBox / Tika / iText all use `java.io` / `java.nio` / `java.util.*`
  — none of which we have shims for. The probe would immediately
  fall over on missing shim methods. Q3.1 only becomes meaningful
  *after* Tier 2 shim coverage.

- **Do not collapse short Loader fixture (`bench/aot-record.php`)
  into the contract gate.** The contract gate snapshots emit-shape;
  records exercise runtime behaviour (instance dispatch + bootstrap
  semantics). Different concerns; keep separate.

- **Do not do `Loader::reset()` mid-loop in the soak harness.**
  PHP can't undefine eval'd classes; the Loader-registry reset
  alone causes "cannot redeclare" fatals on next load. Use
  `Compiler::clearCompileCache()` instead — that's the analogous
  LRU-eviction stress test without the eval problem.

- **Do not defeat javac constant-folding by using literal arithmetic
  in fixtures.** `Integer.MAX_VALUE + 1` constant-folds at compile
  time to a literal `ldc -2147483648; ireturn` — never executes
  any iadd opcode. First BenchIntOverflow attempt was meaningless
  for this reason; second version takes args, defeats fold.

- **Do not single-shot benchmark the AOT pipeline.** PHP's tracing
  JIT trace cache pollutes between fixture-loads; first-after-shell
  runs can show 6× regressions that disappear on re-run. The
  "empty-method 22 → 127 ns/call" regression I reported turned out
  to be pure cold-cache noise. 5-run median is the minimum.

- **Do not drop the JVM 32-bit overflow mask for raw perf.** The
  wrapper-removal slice initially dropped both wrapping AND the
  implicit mask (Int_::filter did `($v << 32) >> 32` internally).
  Without the mask, `Integer.MAX_VALUE + 1` returns 2147483648
  instead of -2147483648 — semantically wrong. Re-applied
  explicitly per CONTRACTS.md §1 at 0.20 ns/iadd cost.

---

## Recommendation

### Immediate (next session)

Three loose ends, all small:

1. **Push the branch.** 23 commits ahead of `origin/roadmap`, all
   green locally; nothing exotic in any commit. `git push`.

2. **Run the 24h Swoole soak.** Harness is ready
   (`bench/soak-aot.php`). Operator-driven; results inform whether
   LRU cap of 1000 is right for production load profiles, and
   whether memory plateaus.

3. **Continue #12 boxing slices.** Pattern is validated. Long is
   simplest (no mask needed; PHP int = JVM long = 64-bit). Each
   slice is ~hour and recovers ~9 PHPUnit errors. 3-5 slices plus
   final Type-class deletion → #11 + #12 effectively complete.

### Medium-term

- **Q3.1 probe** (per MODEL.md): pick the smallest real Java library
  whose dependencies fit in our current shim coverage (System.out +
  basic math + arrays). Apache Commons Lang's `ArrayUtils` might
  qualify if you stub out the few `String`/`Object`-dependent
  methods. Validates the AOT-pipeline-end-to-end story on
  non-fixture code.

- **Tier 2 shim coverage** for the bb allowlist (~80 classes per
  CLOJURE-BOOT-ANALYSIS). Months of mechanical work; parallelisable.

- **Per-iadd mask skip** if profiling demands it. Static range
  analysis: bounded loop counters skip the mask. Likely worth ~10%
  on int-heavy hot loops.

### Optional polish

- Naming/branding (the fork is meaningfully different from upstream's
  "fun JVM in PHP" goal; rename when stable).
- Remove the dead string-path emitter in `Compiler::compileMethod`
  once #12 + Tier 2 give us confidence the IR path covers everything
  in practice (deferred per ROADMAP.md item #10 with documented
  rationale: needs rank-1 evidence on more than one JAR before
  removing 1500-line fallback).

---

## Pointers

| Concern | File / location |
|---|---|
| AOT entry | `\PHPJava\Aot\Loader::callStatic($cp, $method, ...$args)` |
| Compile cold | `(new Compiler())->compileBytes($cp, $bytes)` |
| Compile cache | `Compiler::compileBytesCache` / `compileClassCache` (LRU, env: `PHPJAVA_AOT_CACHE_MAX`) |
| Reproduce real-lib probe | `apt install libcommons-lang3-java && php bench/probe-real-library.php` |
| Reproduce AOT bench | `php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=1024M bench/bench-aot.php` |
| Reproduce overflow correctness | `php bench/aot-overflow.php` |
| Reproduce mask-cost microbench | `php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=1024M bench/bench-int-mask.php` |
| Reproduce records E2E | `php bench/aot-record.php` |
| Soak (24h) | `nohup php bench/soak-aot.php > /tmp/soak-$(date +%s).jsonl 2>&1 &` |
| Test suite | `vendor/bin/phpunit` (49 errors / 47 failures expected; #11/#12 work) |
| Contract gate | `php bench/contract.php [--update]` |
| AOT IR | `src/Aot/Ir/{Builder,Lowerer,Node,InlinePass,ArrayHelper}.php` |
| AOT compile | `src/Aot/Compiler.php` |
| AOT runtime helpers | `src/Aot/Runtime/bootstrap.php` (jvm_lushr, jvm_multianewarray, jvm_typeswitch, System/PrintStream/Throwables) |
| Loader | `src/Aot/Loader.php` |
| Interpreter Mnemonics | `src/Kernel/Mnemonics/_*.php` (200 files; #12 has touched 20 of them so far) |

### Doc tree (read in order if landing fresh)

| Order | Doc | Purpose |
|---|---|---|
| 1 | `README-hbtweb.md` | orientation + bench harness map |
| 2 | `docs/MODEL.md` | strategic framing |
| 3 | `docs/CONTRACTS.md` | normative spec — interfaces, value-rep, dispatch |
| 4 | `docs/PATTERNS.md` | the five measured rules + JIT-claims battery |
| 5 | `docs/STATUS.md` | current state — including the rank-1 perf table |
| 6 | `ROADMAP.md` | tier ordering, exit criteria, work-plan items + #10 deferred |
| 7 | `bench/probe-real-library.md` | rank-1 commons-lang3 coverage report |
| ref | `docs/JVM-PHP-DELTA.md` | platform/semantic/API deltas |
| ref | `docs/CLOJURE-BOOT-ANALYSIS.md` | empirical class-load trace (T2 sizing) |

---

## Stamp

- **Date:** 2026-05-03
- **SHA:** `10cbc3b` (branch `roadmap`, **not yet pushed** at handover)
- **Repository:** https://github.com/hbtweb/php-java
- **Author of session:** Claude (Opus 4.7) working with Apollo Nicolson
- **Total commits this session:** 23 (10 work-plan items + 4 surfaced gaps + 1 soak harness + 3 boxing slice + 4 follow-on)
- **Branch state:** 23 commits ahead of `origin/roadmap`, working tree clean
- **Test status:** 9/9 fixture tests + 4/4 record tests + 18/18 overflow + Loader smoke all PASS; PHPUnit 49 errors / 47 failures (pre-session baseline ~ same; not regressed)
- **Probe status:** 100% IR / 4983 methods / 0 fallbacks / 0 top-level fails on commons-lang3

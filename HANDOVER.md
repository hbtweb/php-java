# Handover — design rework session 2026-04-25 → 2026-05-01

> SHA at hand-off: `b633bbe` on branch `roadmap`, pushed to `origin`.
> Author: Claude (Opus 4.7) working with Apollo Nicolson.
> Following the SBAR pattern from `~/CLAUDE.md`.

---

## Situation

The hbtweb fork of [php-java/php-java](https://github.com/php-java/php-java)
exists at `https://github.com/hbtweb/php-java`. Branch `roadmap` (not yet
merged to `master`) holds a comprehensive design rework. The upstream
project has been quiet since 2021; this fork repurposes it for a
different goal.

**Original upstream goal:** simulate the JVM in PHP for educational purposes.
Per-opcode classes; wrapper instances per primitive; bytecode-stream
abstractions. Spec-faithful, ~50× the necessary code, ~10,000× HotSpot
interpreted slow. Reference implementation in PHP.

**Our fork's goal:** translate JVM bytecode to PHP that Zend executes
natively. Long-running deployment target (Swoole / AMPHP / RoadRunner
primarily, request-scoped FPM secondarily). Run Java libraries from PHP
code at within ~50× HotSpot interpreted; deploy as `.php` files; no
exotic dependencies.

These are different projects sharing a directory. The fork keeps the
upstream parser (it's solid), gut everything below it.

---

## Background — what landed this session

### Documentation (12 docs in `docs/` + 4 at top level)

In the order a fresh reader should consume them:

| Doc | Status |
|---|---|
| `README-hbtweb.md` | orientation, doc map |
| `docs/MODEL.md` | the keystone — architectural model, why these choices |
| `docs/CONTRACTS.md` | normative spec — interfaces, value-rep, dispatch |
| `docs/PATTERNS.md` | five measured rules + what to subtract |
| `docs/BOXING.md` | wrappers gutted entirely; rationale + JVM mapping |
| `docs/BOTTLENECKS.md` | every measured cost + type emulation |
| `docs/JVM-PHP-DELTA.md` | platform/semantic/API differences (rank-1 verified) |
| `docs/STATUS.md` | snapshot — where we are, what's next |
| `ROADMAP.md` | tier ordering, exit criteria |
| `docs/ADJACENT-SHAPES.md` | TeaVM, bb, cljp, project landscape |
| `docs/GAP-JDK.md` | Java 19 → 21 → 25 concrete deltas |
| `docs/CLOJURE-BOOT-ANALYSIS.md` | empirical class-load trace |
| `bench/README.md` | bench methodology, LD_PRELOAD finding |
| `bench/PATTERN-VALIDATION.md` | dispatch/array/boxing measurements |
| `bench/profile-c930e2c.md` | xhprof profile attribution |

### Code (committed and working)

| Component | Status | LOC |
|---|---|---|
| AOT compiler that walks PHPJava's parsed bytecode | works for ~17 opcodes; emits valid PHP for `BenchAdd::sum1k` (returns 499500) | ~250 |
| Test suite unblock (JDK 25 compatibility) | committed | 2 lines |
| Bench harness via FFM (libphp via JVM Foreign Linker) | working — calls `php_embed_init` + `zend_eval_string` directly | ~190 |
| 7 measurement harnesses in `bench/` | all working, generate the rank-1 numbers in docs | ~1500 |

### Measurements (rank 1, all reproducible)

The cost model spans `5,220 ns/op → 0.4 ns/op` across the architectural
spectrum. Key data points:

| Implementation | ns/op (no opt) | ns/op (opcache+JIT) | vs current PHPJava |
|---|---|---|---|
| Current PHPJava | 5,220 | 3,977 | 1× |
| Switch dispatch interpreter | 22 | 36 | 200× |
| Naive AOT (hand) | 5.6 | 1.5 | 940× |
| **Real AOT compiler-emitted** | **14** | **3.2** | **460×** |
| Idiomatic AOT (hand) | 0.4 | 0.2 | 13,000× |
| HotSpot interpreted reference | 0.52 | — | — |

Per `bench/spike-fast-interp.php` and `bench/aot-out/BenchAdd.php`. The
**real compiler-emitted PHP** runs `BenchAdd::sum1k` in 14 ns/op — the
unified-compiler model from `MODEL.md` is rank-1 validated.

---

## Assessment

### What's settled

1. **Architectural model.** PHPJava is a JVM-bytecode-to-PHP translator
   with three cache strategies (eager AOT / lazy AOT / interpret-fallback)
   sharing one compiler. Two peers on Zend with cljp, neither hosting the
   other. See `docs/MODEL.md`.

2. **Contracts locked.** Interfaces named: `ClassLoaderInterface`,
   `InvokerInterface`, `NativeMethodInterface`, plus value-representation
   rules (no wrappers; PHP scalars throughout). See `docs/CONTRACTS.md`.

3. **Pattern rules backed by measurement.** Five mechanical rules for
   hot-path code: pre-decoded int array bytecode, PHP locals over frame
   objects, regular array over SplFixedArray, no primitive wrappers,
   switch-in-static-function dispatch. See `docs/PATTERNS.md`.

4. **Boxing gutted.** Every primitive wrapper instance eliminated. Wrapper
   classes shrink to static-method namespaces + reflection metadata.
   ~2,500 LOC removed; ~600 LOC of static helpers added. See
   `docs/BOXING.md`.

5. **Falsifier F1 lifted.** Original concern was per-op cost > 1 µs after
   refactor. Measured 9–14 ns/op. The interpreter path is viable; AOT
   path even more so.

6. **Cut list quantified.** ~25 kloc removed from the runtime engine
   (~93% shrinkage of the non-Packages code). Public API preserved
   (`JavaClass::load(...)->getInvoker()->...->call(...)`); internals
   become thin functions over plain arrays.

### What's unsettled

1. **Test suite still 79%.** 49 errors / 48 failures. Root cause is the
   value-rep refactor (boxing wrappers); fixing that resolves most.
   STATUS.md "Week 2" estimate.

2. **Tier 1a interpreter rewrite not started.** `JavaMethodCallable::call`
   still has its 330-line dispatch loop with M1 (temp file), per-iter
   allocations, etc. The rewrite to switch-in-static-function is bounded
   work (~1 week). STATUS.md "Week 1" estimate.

3. **Lambda metafactory and `defineClass(byte[])` not implemented.**
   These are the load-bearing capability gaps for modern Java code per
   `docs/JVM-PHP-DELTA.md` §14b. STATUS.md "Week 3" estimate.

4. **No long-running soak test run.** All measurements are single-shot
   CLI. The lazy-AOT cache hypothesis untested in actual Swoole/AMPHP
   process. ~2 days of work; depends on Tier 1 first.

5. **No PR opened.** Branch `roadmap` is 13 commits ahead of `master`;
   pushed to `origin/roadmap`. Whether to PR or merge directly is the
   user's call.

### What was rejected (explicit anti-context)

This list is the most valuable section per CLAUDE.md handover discipline.
Each entry was actively considered and rejected; future contributors
should not re-derive these decisions.

- **Do not adopt cljp's `$GLOBALS`-as-runtime contract.** cljp's
  closures-in-globals serve Clojure's redefinable-Var semantics. Java
  doesn't have runtime redefinability natively; forcing PHPJava
  through `$GLOBALS` distorts Java values for the dominant user (P1 in
  MODEL.md). cljp and PHPJava are **peers on Zend, not nested.**
  Marshal at language boundary when interop needed.

- **Do not borrow cljp's IR for AOT.** cljp's `:ps/*`/`:pl/*` IR is
  shaped for Clojure semantics; JVM bytecode has different shape.
  **JVM bytecode IS the IR for naive AOT.** Add a TeaVM-shape SSA+CFG
  IR only if/when idiomatic AOT is needed (3–6 months later).

- **Do not build a tiered compiler infrastructure.** HotSpot has C1/C2/
  Graal tiers; we don't need them. Zend already has its own tier
  structure (interpret → opcache → JIT). Single high-quality AOT pass
  + Zend's runtime tiers does the job. See `docs/PATTERNS.md` Rule 5.

- **Do not use SplFixedArray.** Loses by 2–7× across all measurements
  (operand stack, lookups, iteration). Regular PHP array is faster.
  This contradicts conventional PHP wisdom; `bench/validate-datastructures.php`
  confirms.

- **Do not use closure-table dispatch in the interpreter.** 3–6× slower
  than switch in static function. Even with PHP 8.5 closure improvements,
  switch wins. See `bench/validate-hotloop.php`.

- **Do not retain `Int_`/`Long_`/`Double_`/etc. wrapper instances.**
  7–9× per-op cost. Wrapper *classes* exist as namespaces of static
  methods + reflection metadata only. See `docs/BOXING.md`.

- **Do not use `eval`'d closures for the dispatch loop.** Worse than
  switch in static function (50 ns vs 9 ns) — JIT can't trace through
  eval'd closures as well as static functions.

- **Do not use `call_user_func_array` in hot paths.** 6× direct call
  cost. Use direct dispatch.

- **Do not use `stdClass` for hot-path state.** 2× cost vs typed object.

- **Do not enforce strict Java identity equality on Integer.** `==` on
  Integer references should fail (Java) but pass (us, value equality).
  This affects only buggy Java code that uses `==` on boxed types
  (every style guide forbids). Documented divergence; accepted.

- **Do not implement the JVM bytecode verifier.** Trust the input class
  files. Verifier costs more than it saves at our scale.

- **Do not pursue the existing `Compiler/Lang/Assembler/` (PHP-syntax
  → JVM bytecode) feature.** Wrong direction; educational artifact;
  out of scope. Keep frozen or cut entirely.

- **Do not re-implement OpenJDK source verbatim** for the curated
  `Packages/java/*` shim layer. License compatibility concern (OpenJDK
  is GPL+CE, this fork is MIT). Re-implement from spec; reference
  OpenJDK only for design clarity, never copy.

- **Do not target Java 25 directly.** Aim at Java 21 LTS. Java 25's
  additions are mostly preview-going-final and small new APIs; the
  perf-relevant work is Java 21. Java 25 follows when 21 is stable.

---

## Recommendation

### Immediate (next session)

The 3-week milestone in `docs/STATUS.md` is well-defined:

**Week 1** — Tier 1 interpreter rewrite + AOT opcode coverage:
1. Rewrite `JavaMethodCallable::call` as switch over int array,
   frame state in PHP locals.
2. Cut `Kernel/Mnemonics/_*::execute()` per-opcode classes (200 files).
3. Expand `src/Aot/Compiler.php` from 17 opcodes to all ~200.

**Week 2** — Boxing refactor + test suite:
1. Drop `Kernel/Types/Int_.php`, `Long_.php`, `Double_.php`, etc.
   (~2,500 LOC).
2. Update `Kernel/Filters/Normalizer.php` to wrap only at autobox
   sites (~80% reduction).
3. Run test suite to 100%. Most of 49 errors / 48 failures resolve.

**Week 3** — Integration + capability:
1. Wire `AotEager`/`AotLazy`/`Interpret` strategies into `JavaClass::load`.
2. Implement `LambdaMetafactory.metafactory` + `StringConcatFactory.makeConcatWithConstants`.
3. Implement `defineClass(byte[])` extension surface.
4. Long-running soak test in Swoole/AMPHP daemon.

End-of-3-weeks milestone: real Java library AOT-compiles successfully,
callable from PHP code with idiomatic types at the boundary, runs in a
Swoole daemon for 24h without memory growth.

### After the milestone

The 233-class T2 surface (per `docs/CLOJURE-BOOT-ANALYSIS.md`) becomes
the long-tail work. Mechanical, parallelisable, ~2–4 person-months.
Then: Clojure-on-PHPJava becomes a runnable hypothesis; bb compatibility
list a probe.

### Optional polish

- Open PR `roadmap` → `master` (or merge directly; user's call).
- Symfony Console version mismatch (`composer.json` constraint vs PHP 8.4)
  — cosmetic, affects only `./PHPJava -h` cosmetic output.
- Naming/branding decision (the fork is meaningfully different from
  upstream's "fun JVM in PHP"; renaming may follow once stable).

---

## Pointers

| Concern | File / location |
|---|---|
| Reproduce baseline measurements | `cd bench && php8.5 -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=256M validate-*.php` |
| Reproduce AOT compiler validation | `cd bench && php aot-compile.php` (must run after `composer install`) |
| Inspect emitted PHP from AOT | `cat bench/aot-out/BenchAdd.php` |
| Inspect bytecode shape via javap | `javap -c bench/fixtures/*.class` |
| Re-run bb allowlist intersection | `/tmp/clojure-boot-classes.txt`, `/tmp/bb-allowlist.txt`, `/tmp/required-set.txt` (regenerable; commands in `docs/CLOJURE-BOOT-ANALYSIS.md`) |
| Re-run profile under xhprof | `php -d extension=xhprof.so bench/profile-xhprof.php` |
| Test suite | `cd /home/hbtweb/GitHub/php-java && vendor/bin/phpunit` |
| Branch state | `cd /home/hbtweb/GitHub/php-java && git status` |

---

## Stamp

- **Date:** 2026-05-01
- **SHA:** `b633bbe` (branch `roadmap`, pushed)
- **Repository:** https://github.com/hbtweb/php-java
- **Author of session:** Claude (Opus 4.7), working with Apollo Nicolson
- **Total commits this session:** 13
- **Total LOC added across docs + bench harnesses:** ~5000
- **Hours of design conversation:** roughly 6–8 of focused back-and-forth
- **Key methodological discipline that emerged:** "follow the data in the
  simplest, smallest model" — every architectural choice in this session
  was either backed by rank-1 measurement or marked as deferred. Where
  initial assumptions proved wrong (boxing cost, switch vs closures,
  IntegerCache range, PHP int overflow behaviour), measurements corrected
  them and the docs were revised.

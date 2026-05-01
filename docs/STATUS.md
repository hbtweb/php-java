# Project status — 2026-05-01

> Snapshot of where we are, what's measured, what's next.
> Updated as work lands. The roadmap is a hypothesis; this is reality.

## Headline

**The unified-compiler model is rank-1 validated.** A real AOT compiler
walks PHPJava's parsed bytecode and emits PHP that runs at **14 ns/op
no opt / 3.2 ns/op opcache+JIT — 468× faster than current PHPJava and
within 6× of HotSpot interpreted.**

The architecture works. The remaining work is mechanical: full opcode
coverage, capability completeness, surface fill.

## What's measured (rank 1)

| Bench | Value | File |
|---|---|---|
| Current PHPJava interpreter | 5.22–6.56 µs / op | `bench/baseline-d803364.json` |
| Switch-dispatch interpreter (spike) | 22 ns / op no opt; 36 ns / op JIT | `bench/spike-fast-interp.php` |
| Hand-translated naive AOT (spike) | 5.6 ns / op no opt; 1.5 ns / op JIT | `bench/spike-fast-interp.php` |
| **Real compiler-emitted naive AOT** | **14 ns / op no opt; 3.2 ns / op JIT** | `bench/aot-out/BenchAdd.php` |
| Hand-translated idiomatic AOT (spike) | 0.4 ns / op no opt; 0.2 ns / op JIT | `bench/spike-fast-interp.php` |
| HotSpot interpreted reference | 0.52 ns / op | `bench/baseline-d803364-hotspot.json` |
| HotSpot JIT reference | ~0.10 ns / op | (recall) |

**Falsifier F1 (per-op > 1 µs after Phase 2) — lifted by measurement.**
Switch dispatch alone is at 22 ns/op, well under the 1 µs threshold.

## What's documented (consolidated)

| Doc | Purpose |
|---|---|
| `ROADMAP.md` | what's done, what's next, exit criteria |
| `docs/MODEL.md` | strategic framing — *why* the architecture is what it is |
| `docs/CONTRACTS.md` | normative spec — interface boundaries, value-rep decisions |
| `docs/GAP-JDK.md` | concrete deltas Java 19 → 21 → 25 |
| `docs/CLOJURE-BOOT-ANALYSIS.md` | empirical class-load trace from Clojure boot |
| `docs/ADJACENT-SHAPES.md` | TeaVM, DoppioJVM, bb, cljp landscape |
| `bench/README.md` | bench methodology + LD_PRELOAD finding |
| `bench/profile-c930e2c.md` | xhprof attribution of accidental costs |

## What's built (rank 1, in repo)

| Component | Status | Notes |
|---|---|---|
| Bench harness (FFM-based) | ✓ working | `bench/baseline.clj`, `bench/bench-cli.php` |
| Profile harness (xhprof) | ✓ working | `bench/profile-xhprof.php` + LD_PRELOAD |
| Switch-dispatch interpreter (spike) | hand-coded subset of 9 opcodes | `bench/spike-fast-interp.php` |
| AOT compiler (real, walks PHPJava parser) | ~17 opcodes | `src/Aot/Compiler.php` |
| AOT-compiled BenchAdd | ✓ working, returns 499500 | `bench/aot-out/BenchAdd.php` |
| Test suite unblock for JDK 25 | ✓ committed | `tests/Cases/Base.php` |

## What's open (rank 2/3 estimates)

| Work | Estimated effort | Blocking |
|---|---|---|
| Full opcode coverage in AOT compiler | ~2 weeks | nothing |
| Method dispatch (INVOKE*) emission | 1 week | needs INVOKE* test fixtures |
| Exception-table → try/catch translation | 3–5 days | nothing |
| Lambda metafactory + StringConcatFactory | 2 weeks | nothing critical |
| `defineClass(byte[])` | 3–5 days | nothing |
| Test suite to green | 1–2 weeks | value-rep refactor |
| Value-rep refactor (drop `Int_`/`Long_`/`Double_` boxing) | 2 weeks | CONTRACTS.md ✓ |
| `Unsafe` shim (PHP-locked CAS) | 1 week | nothing |
| 233-class T2 implementation | 2–4 months | T1 |

## Revised tier work estimate (after CLOJURE-BOOT-ANALYSIS)

| Tier | Original estimate | Revised | Reason |
|---|---|---|---|
| T0 — contracts | 3–5 wks | **mostly done** — CONTRACTS.md committed | |
| T1 — class file format + indy | 3–5 wks | unchanged | |
| T2 — bb-allowlist core | ~80 classes | **233 classes** | empirical from Clojure boot trace |
| T3 — concurrency | ~6 classes | **~50 classes** | full `java.util.concurrent.*` |
| T4 — bb-allowlist tail | ~300 classes | **150 classes** | smaller than thought |
| T5 — `Unsafe` + JDK-internal stubs | ~10 surfaces | **~100 stubs + `Unsafe`** | needed for ConcurrentHashMap |

**Net revised work:** ~530 classes total (~400 implementations,
~130 stubs). Bigger than the earlier "~383" estimate but a meaningful
fraction is generator-amenable stubs.

## Ranked next steps

1. **Value-rep refactor.** Drop `Int_`/`Long_`/`Double_` wrappers per
   `docs/CONTRACTS.md` §1. ~2 weeks. Unblocks: AOT codegen quality
   improvements, test-suite parity, JIT efficacy on AOT'd code.
2. **Test suite to green.** The 49 errors / 48 failures are mostly
   value-rep symptoms. After step 1, most resolve naturally. ~1 week
   of residual fixes.
3. **Full opcode coverage in AOT compiler.** Mechanical extension of
   `src/Aot/Compiler.php`. ~200 cases, each ~5 LOC. ~2 weeks.
4. **AOT classloader integration.** Wire `AotEager` and `AotLazy`
   strategies into `JavaClass::load`. New `JavaClassAotMethodInvoker`.
   ~1 week.
5. **Lambda metafactory.** Single biggest capability unlock for "any
   modern Java code." ~2 weeks.
6. **`defineClass(byte[])` extension surface.** ~3 days. Enables
   downstream Clojure-on-PHPJava work.
7. **Stub generator.** For the ~130 JDK-internal classes that just
   need to exist. ~3 days of tooling.
8. **Long-running soak test.** Swoole daemon, 24h, measure cache hit
   rate, memory growth, request latency. ~2 days.

This is a 6–10 week sequence to reach a state where: a typical Java
library AOT-compiles successfully, runs at within 10× HotSpot
interpreted, deploys as PHP files, and serves request traffic in a
Swoole daemon. The Clojure-on-PHPJava ambition follows from there
(steps 6 + the curated 233-class set + Clojure's own infrastructure).

## Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| GraalVM Crema matures, takes our niche | medium | high | Watch Graal release notes; the deployment target (shared PHP hosting) doesn't have Graal anyway |
| OpenJDK source-license entanglement | low | medium | Re-implement from spec; case-by-case override |
| `java.util.concurrent.*` complexity exceeds estimate | medium | medium | Lock + atomics first; defer ConcurrentHashMap until needed |
| opcache memory pressure with large emitted PHP | medium | medium | Generate compact PHP; measure at scale |
| PHP 9 breaking changes when it ships | low | high | Track PHP RFC; test against alpha |
| Self-hosting cljp diverges, breaks shared patterns | low | low | cljp and PHPJava are peers; bridge is small enough that drift is contained |

## What 3–6 weeks looks like

**Weeks 1–2:** Value-rep refactor + test suite to green. Lock
`CONTRACTS.md` §1 in code.

**Weeks 3–4:** Full opcode coverage in AOT compiler + classloader
integration. AOT'd code runs through the full `JavaClass::load`
classloader pipeline transparently.

**Weeks 5–6:** Lambda metafactory + `defineClass(byte[])`. Modern Java
code starts to AOT-compile correctly. Clojure-on-PHPJava becomes a
runnable hypothesis.

End of week 6 milestone: **AOT compile a small real Java library
(e.g. a single-class JSON parser) and call it from PHP code with idiomatic
types at the boundary.** That's the demonstration that the architecture
delivers on its promise.

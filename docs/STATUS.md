# Project status — 2026-05-01

> Snapshot of where we are, what's measured, what's next.
> Updated as work lands. The roadmap is a hypothesis; this is reality.
> Last updated after JVM-PHP-DELTA.md committed (commit `b633bbe`).

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

### Decisions locked (with measurement backing)

- **Drop primitive wrappers entirely.** 7–9× cost per arithmetic op
  measured (`bench/validate-boxing.php` §3). Wrapper classes shrink to
  static-method namespaces + reflection metadata. See `docs/BOXING.md`.
- **Switch in static function for interpreter dispatch.** 9–10 ns/op
  with JIT — beats closure-table by 3–6×. See `docs/PATTERNS.md` Rule 5.
- **Regular array, never `SplFixedArray`.** Loses by 2–7× across all
  measurements (`bench/validate-datastructures.php`).
- **Frame state in PHP locals**, not arrays/objects. 5–13× faster.
- **Pre-decoded int array bytecode**, not stream. 2–3× faster per byte.
- **Java int overflow semantics preserved** via `& 0xFFFFFFFF` mask +
  sign extend. 1 ns extra per op.
- **PHP scalars throughout**; only tagged tuples at CFG-merge autobox sites
  (rare, ~5% of code paths).
- **No shared `$GLOBALS` runtime with cljp.** They're peers on Zend; bridge
  marshals at language boundary.
- **No separate IR for naive AOT.** JVM bytecode IS the IR (PHPJava parses).
- **PHP 8.1 minimum** (Fibers needed for Thread emulation). 8.4+ recommended.
- **Java 21 LTS target** for capability scope (lambda metafactory, indy,
  records, sealed, virtual threads).

## What's documented (consolidated, current)

Read in this order if landing fresh:

| Order | Doc | Purpose |
|---|---|---|
| 1 | `README-hbtweb.md` | orientation; doc map |
| 2 | `docs/MODEL.md` | strategic framing — *why* the architecture is what it is |
| 3 | `docs/CONTRACTS.md` | normative spec — interfaces, value-rep, dispatch decisions |
| 4 | `docs/PATTERNS.md` | the 5 measured rules + what to subtract |
| 5 | `docs/BOXING.md` | gut the wrappers; rationale + JVM contract mapping |
| 6 | `docs/BOTTLENECKS.md` | every measured cost + type emulation guidance |
| 7 | `docs/JVM-PHP-DELTA.md` | platform/semantic/API differences, version evolution (rank 1 verified) |
| 8 | `docs/STATUS.md` | this doc — where we are now |
| 9 | `ROADMAP.md` | tier ordering, exit criteria |
| ref | `docs/GAP-JDK.md` | concrete JDK version deltas (Java 19 → 21 → 25) |
| ref | `docs/CLOJURE-BOOT-ANALYSIS.md` | empirical class-load trace from Clojure boot |
| ref | `docs/ADJACENT-SHAPES.md` | TeaVM, DoppioJVM, bb, cljp landscape |
| ref | `bench/README.md` | bench methodology + LD_PRELOAD finding |
| ref | `bench/profile-c930e2c.md` | xhprof attribution of accidental costs |
| ref | `bench/PATTERN-VALIDATION.md` | dispatch/array/boxing pattern measurements |

Plus 7 measurement harnesses in `bench/`:
- `validate-patterns.php` — dispatch shape comparison
- `validate-hotloop.php` — tight-loop variants
- `validate-datastructures.php` — array vs SplFixedArray vs object frame
- `validate-boxing.php` — 6 boxing patterns × 5 workloads
- `validate-remaining.php` — strings, exceptions, numeric, static fields
- `spike-fast-interp.php` — interpreter vs AOT spike
- `aot-compile.php` — real AOT compiler verification

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

## Ranked next steps (revised after pattern validation)

The pattern measurements (`docs/PATTERNS.md`) collapse the prior 8-step
sequence to **3 weeks of focused work**. Most H1–H8 / M1–M4 fixes from
the original ROADMAP collapse to one structural change.

**Week 1 — interpreter rewrite + AOT opcode coverage**
1. Rewrite `JavaMethodCallable::call` as switch over pre-decoded int
   array, frame state in PHP locals. Cut the per-opcode class tree
   (`Kernel/Mnemonics/_*::execute()` ~200 files). H1–H8 + M1–M4 mostly
   collapse to this single change.
2. Expand `src/Aot/Compiler.php` to full opcode coverage. ~200 cases
   each ~5 LOC. The compiler's emit functions mirror the new
   interpreter's switch cases — same pattern, different consumer.

**Week 2 — boxing refactor + test suite**
3. Drop `Int_`/`Long_`/`Double_`/`Boolean_`/`Char_` wrappers from
   runtime use. Keep type-tag constants for descriptor parsing.
   Touches ~50 files. Most of the 49 errors / 48 failures are value-rep
   symptoms; they resolve naturally.
4. Run test suite to 100%. Residual fixes for cases that aren't
   value-rep.

**Week 3 — integration + capability**
5. AOT classloader integration: wire `AotEager` and `AotLazy`
   strategies into `JavaClass::load` per CONTRACTS.md §3 + §5. New
   `JavaClassAotMethodInvoker`.
6. Lambda metafactory implementation (`StringConcatFactory` +
   `LambdaMetafactory`). Single biggest capability unlock.
7. `defineClass(byte[])` extension surface (~3 days). Enables
   Clojure-on-PHPJava follow-on work.
8. Long-running soak test in Swoole. Measure cache hit rate, memory,
   latency.

End-of-three-weeks milestone: **~9 ns/op interpreter, ~3-5 ns/op AOT,
test suite green, real Java library AOT-compiles successfully, callable
from PHP code with idiomatic types at the boundary, runs in a Swoole
daemon for 24h without memory growth.**

The longer-tail T2 surface (233 classes from `docs/CLOJURE-BOOT-ANALYSIS.md`)
runs after this milestone. That tail is mechanical implementation work,
not architecture.

## Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| GraalVM Crema matures, takes our niche | medium | high | Watch Graal release notes; the deployment target (shared PHP hosting) doesn't have Graal anyway |
| OpenJDK source-license entanglement | low | medium | Re-implement from spec; case-by-case override |
| `java.util.concurrent.*` complexity exceeds estimate | medium | medium | Lock + atomics first; defer ConcurrentHashMap until needed |
| opcache memory pressure with large emitted PHP | medium | medium | Generate compact PHP; measure at scale |
| PHP 9 breaking changes when it ships | low | high | Track PHP RFC; test against alpha |
| Self-hosting cljp diverges, breaks shared patterns | low | low | cljp and PHPJava are peers; bridge is small enough that drift is contained |

## What 3 weeks looks like (revised)

The pattern validation (`docs/PATTERNS.md`) showed the H1–H8 + M1–M4
fixes collapse to one structural change. Original 6-week target now
3 weeks.

**Week 1:** Interpreter rewrite + AOT opcode expansion.
**Week 2:** Boxing refactor + test suite to green.
**Week 3:** Integration + lambda metafactory + `defineClass(byte[])` +
soak test.

End-of-three milestone: **AOT compile a small real Java library, call
it from PHP code with idiomatic types at the boundary, run a 24h
Swoole soak test.** Demonstrates the architecture delivers.

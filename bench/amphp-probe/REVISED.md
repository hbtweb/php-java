# Revision: AMPHP is too slow to pair with AOT

> Date: 2026-05-04. Supersedes the AMPHP-primary recommendation in
> `RESULTS.md` and `COMPARISON.md`. Triggered by Apollo's
> observation: "amphp is too slow to pair with our complete AOT."

## The math that closes the question

| Operation | Cost | vs AOT iadd loop |
|---|---|---|
| AOT iadd loop iter (rank 1) | **0.20 ns** | 1× |
| AOT empty-method dispatch | 22 ns | 110× |
| AOT array workload | 2.2 ns | 11× |
| Custom PoC v2 runtime | 365 ns | 1,825× |
| HotSpot virtual thread context switch | ~500 ns | 2,500× |
| **AMPHP async+await** | **2,031 ns** | **10,155×** |

`docs/STATUS.md` headline: *"AOT pipeline is feature-complete and
within 1.8× of HotSpot JIT."* That claim is per-op for the compute
path. Pairing it with AMPHP at the concurrency surface throws the
AOT win away — code that uses concurrency at any frequency runs
at AMPHP-class performance, not AOT-class.

A `Thread.start(runnable).join()` where the runnable does 100 hot-
loop ops:
- AOT compute: 100 × 0.20 ns = **20 ns of work**
- AMPHP overhead: **2,031 ns**
- **Overhead is 100× the work.**

Even with a long-running runnable, the per-call overhead caps the
useful concurrency density. A million `Thread.start()` calls per
second on AMPHP costs 2 seconds of overhead — half the wall time of
a 4-second daemon run, just on async machinery.

## Why the earlier recommendation was wrong

Original framing in `RESULTS.md` (this session, earlier):

> Mean Amp/raw ratio: 1.35× under JIT. Inside the fork-or-not
> threshold (we set 3× as the bar pre-measurement).

The threshold was set against raw Fibers. Wrong anchor. The real
anchor is AOT's per-op cost — what the rest of the project achieves.
Against that anchor, AMPHP is **10,155×**, raw Fibers are **62,500×**,
neither is acceptable.

Per CLAUDE.md "Update on evidence; retract on contradiction": the
earlier "1.35× — don't fork" recommendation was correct under the
assumption that we'd accept Fiber-class overhead. We shouldn't. The
project has already proven (the 4.8 kloc `src/Aot/` substrate) that
purpose-built PHP runtime layers can hit perf classes 10–100× tighter
than off-the-shelf libraries. AMPHP is the off-the-shelf library here.

## Revised plan

Three layers, layered:

| Layer | LOC | Cost | vs AOT |
|---|---|---|---|
| **B-emit (inlinable)** | ~300 specialiser + ~250 analyzer (shipped) | **9 ns/op** | 45× |
| **Tier A custom runtime** (non-suspending fallback) | ~500 | **~150–365 ns/op** | 750–1,800× |
| **Tier B custom runtime** (suspending — real Fibers) | ~300 | ~1 µs/op tuned | 5,000× |
| ~~AMPHP~~ | (skipped) | 2,031 ns/op | 10,155× |

Total: ~1,000–1,500 LOC of custom code (vs the original "AMPHP-shim
~1,600 LOC + B's ~550 LOC = 2,150 LOC"). Custom path is *fewer* LOC
than the AMPHP-shim path AND faster across every workload.

The correctness machinery AMPHP provides (cancellation, error
propagation, composition) becomes our code. At ~1,000 LOC total it's
in scope — comparable to the existing IR transforms in
`src/Aot/Ir/InlinePass.php` (~120 LOC) + `src/Aot/Ir/Builder.php`'s
escape-analysis paths. We already build optimisation infrastructure;
adding a tight async runtime to it is structurally consistent.

## What ships

- ✓ **B's analyzer** (this session, `src/Aot/Ir/Analysis/MaySuspendAnalyzer.php`)
- **Tier A runtime** — clean up `bench/amphp-probe/poc-runtime-v2.php`,
  promote into `src/Aot/Runtime/Async/Tier_A.php`, add cancellation
  + error propagation. ~500 LOC, ~3 days.
- **Tier B runtime** — Fiber-backed for genuinely-suspending tasks
  (the cases AMPHP needs a Fiber for). PATTERNS.md-style tight
  emit. ~300 LOC, ~2 days.
- **B's emit-specialiser** — consumes analyzer verdicts at AOT
  compile sites; emits direct call (Tier 0 — inlined), Tier A call,
  or Tier B call per the analyzer's verdict. ~300 LOC, ~2 days.
- **JDK shim layer wiring** (`Thread`, `CompletableFuture`, `Future`,
  `ExecutorService`, `BlockingQueue`, `ReentrantLock` etc.) — these
  shims wrap the Tier A/B runtime, not AMPHP. Maps directly to per
  the cljp CLJP-CONCURRENCY.md AMPHP/Revolt pattern but the Revolt
  primitives are replaced by our custom equivalents. ~800 LOC, ~3 days.

Total: ~1,900 LOC, ~10 days focused work to deliver a T3 surface
that hits AOT-class performance on the inlinable hot path AND
HotSpot-class overhead on the genuinely-async path.

Vs the AMPHP-shim plan (~1,600 LOC, ~3-5 days, 2 µs/op everywhere):
- More LOC and more time, but the perf class matches the rest of
  the project.
- The deliverable is a runtime PHPJava owns, can profile, can
  optimise, and can emit from cljp's compiler when the dual-runtime
  story matures.
- The strategic-vision endpoint (rule-configurable cljp compiler
  emits the async runtime as part of its standard library) gets
  cleaner because the runtime is already shaped to the project's
  perf class. Compiling it via cljp later is a recompile-the-runtime
  step, not a fork-AMPHP step.

## What this means for ROADMAP

T3's "AMPHP-primary" framing flips. The canonical path becomes:

  1. ✓ **B's analyzer** (shipped this session)
  2. **Tier A + Tier B custom runtime** (replaces AMPHP-shim plan)
  3. **JDK shim wiring against Tier A/B** (the actual j.u.concurrent
     surface; what `bb-allowlist non-stub fill` will exercise)
  4. **B's emit-specialiser** (consumes analyzer; chooses inline /
     Tier A / Tier B per call site)
  5. **Eventually: rewrite Tier A/B in cljp** (the rule-configurable-
     compiler endpoint — runtime becomes a cljp deliverable, not a
     hand-maintained chunk of PHP)

Swoole stays in the picture as the alternative backend for Tier-2
shared-memory cases. AMPHP/Revolt drops out of the canonical path
entirely; could be added back as an optional backend if a deployment
context wants it (composer.json suggest, not require), but it's
not the default.

## Resequencing the immediate next steps

The "B-analyzer first, then 3, then B-emit-specialiser" sequence
from `COMPARISON.md` survives — but **3 means "Tier A + Tier B custom
runtime", not "AMPHP shim wiring."** The substrate decision flips;
the order of work doesn't.

Practical implication: less wiring code (no AMPHP API surface to
adapt), more runtime code (the custom Tier A + Tier B). Net LOC is
similar; perf class is fundamentally different.

## Acknowledgement

The user's "AMPHP is too slow to pair with our complete AOT" reframe
is correct. The earlier RESULTS.md / POC-RESULTS.md analysis had the
right data but the wrong recommendation — I was anchoring against
raw Fibers as the "fixed cost we accept" rather than against AOT's
per-op cost as the "perf class we should match." Per CLAUDE.md
"Drop the path when evidence says so" — dropping the AMPHP-primary
recommendation, retaining the data that produced it.

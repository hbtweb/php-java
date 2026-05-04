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

## Revised plan — full Java concurrency at AOT-class perf

Apollo's reframe (2026-05-04): "we can get this to similar speed as
iadd with the breadth that we need for full Java concurrency."

That's plausible because most "concurrency" in real Java code reduces
to patterns the compiler can prove are non-suspending or non-racy:

- `Thread.start(runnable).join()` where the runnable is provably
  non-suspending → emit as direct call (`$runnable();`)
- `AtomicInteger.incrementAndGet()` → emit as `++$value` (PHP single-
  threaded so no actual atomicity needed; observable behaviour
  identical)
- `ReentrantLock.lock()/unlock()` when no contention possible →
  emit as no-op pair (single-threaded; no-one to contend with)
- `BlockingQueue.put + take` when producer + consumer share a
  scheduling context → emit as direct array push/pop
- `CompletableFuture.supplyAsync(fn).thenApply(g).join()` where every
  link is non-suspending → fold whole chain to inline expressions

For these patterns, the AOT-emit collapses the entire concurrency
construct to ~iadd-class operations. The breadth comes from the
analyzer covering enough JVM-API surface that the compiler recognises
the reducible patterns; the perf comes from emitting nothing more
than the underlying work needs.

For genuinely-async patterns (real cooperative scheduling, real I/O
suspension, real cross-task communication) the runtime must engage —
but that's a smaller fraction of real concurrency code, and it's the
fraction where Fiber suspend/resume's cost is unavoidable in any
PHP-runtime.

| Layer | LOC | Cost | vs AOT iadd |
|---|---|---|---|
| **Inlined emit** (analyzer + specialiser) | ~550 | **~iadd-class** when fully foldable | 1× — 10× |
| **InlineExecutor (non-suspending)** (non-suspending fallback) | ~500 | ~150-365 ns/op (PoC v2 measured) | ~1,000-2,000× |
| **VirtualThreadExecutor (suspending)** (suspending; real Fibers) | ~300 | ~1 µs/op tuned | ~5,000× |
| ~~AMPHP~~ | (skipped) | 2,031 ns/op | 10,155× |

Total: ~1,350 LOC of custom code (analyzer ~250 shipped + specialiser
~300 + InlineExecutor ~500 + VirtualThreadExecutor ~300). Comparable to existing IR-transform
substrate in `src/Aot/`. The correctness machinery AMPHP would
provide (cancellation, error propagation, composition) becomes ours;
that's the price of matching the project's perf class on the
concurrency surface.

## What ships

- ✓ **Analyzer** (this session, `src/Aot/Ir/Analysis/MaySuspendAnalyzer.php`).
- **InlineExecutor runtime** — clean up `bench/amphp-probe/poc-runtime-v2.php`,
  promote into `src/Aot/Runtime/Async/InlineExecutor.php`, add cancellation
  + error propagation. ~500 LOC.
- **VirtualThreadExecutor runtime** — Fiber-backed for genuinely-suspending tasks.
  PATTERNS.md-style tight emit: pooled fibers, sealed-shape arrays,
  switch-dispatched event loop, no virtual dispatch. ~300 LOC.
- **Emit-specialiser** — consumes analyzer verdicts at AOT compile
  sites; emits inlined direct call (Inlined — full fold), InlineExecutor call,
  or VirtualThreadExecutor call per the analyzer's verdict. ~300 LOC.
- **JDK shim layer wiring** (`Thread`, `CompletableFuture`, `Future`,
  `ExecutorService`, `BlockingQueue`, `ReentrantLock`, atomics) —
  these shims wrap the InlineExecutor / VirtualThreadExecutor runtime. ~800 LOC.

Total: ~1,900 LOC, ~10 days focused work to deliver a T3 surface
that hits AOT-class performance on the inlinable hot path
(via the emit-specialiser folding the construct away) AND tight
overhead on the genuinely-async path (InlineExecutor / VirtualThreadExecutor sized to the project's
perf class).

## What this means for ROADMAP

T3's canonical path:

  1. ✓ **Analyzer** (shipped this session)
  2. **InlineExecutor + VirtualThreadExecutor**
  3. **JDK shim wiring against InlineExecutor / VirtualThreadExecutor** (the actual j.u.concurrent
     surface; what `bb-allowlist non-stub fill` will exercise)
  4. **Emit-specialiser** (consumes analyzer; picks Inlined / InlineExecutor / VirtualThreadExecutor
     per call site)

Swoole stays in the picture as the alternative backend for Tier-2
shared-memory cases. AMPHP/Revolt drops out of the canonical path
entirely.

## Resequencing the immediate next steps

The "B-analyzer first, then 3, then B-emit-specialiser" sequence
from `COMPARISON.md` survives — but **3 means "InlineExecutor + VirtualThreadExecutor custom
runtime", not "AMPHP shim wiring."** The substrate decision flips;
the order of work doesn't.

Practical implication: less wiring code (no AMPHP API surface to
adapt), more runtime code (the custom InlineExecutor + VirtualThreadExecutor). Net LOC is
similar; perf class is fundamentally different.

## Acknowledgement

The user's "AMPHP is too slow to pair with our complete AOT" reframe
is correct. The earlier RESULTS.md / POC-RESULTS.md analysis had the
right data but the wrong recommendation — I was anchoring against
raw Fibers as the "fixed cost we accept" rather than against AOT's
per-op cost as the "perf class we should match." Per CLAUDE.md
"Drop the path when evidence says so" — dropping the AMPHP-primary
recommendation, retaining the data that produced it.

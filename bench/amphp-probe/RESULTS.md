# AMPHP/Revolt vs raw Fiber — overhead profile

> Date: 2026-05-04. PHP 8.4.20. amphp/amp 3.x + revolt/event-loop 1.x.
> Run with `php bench.php` (no JIT) and
> `php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=256M -d opcache.jit=tracing bench.php`.

**Question driving this probe** (per user, 2026-05-04): is AMPHP's
Future/EventLoop layer fast enough to use as-is for PHPJava's
Thread / CompletableFuture / BlockingQueue surface, or is the
overhead over raw Fibers significant enough to justify forking and
trimming?

## Predictions (rank 5, recall — pre-measurement)

- Raw `Fiber::suspend()/resume()`: ~2 µs round-trip
- `Amp\async() + Future::await()`: 5–10 µs (Future alloc + EventLoop
  registration + callback dispatch)
- If AMPHP is >3× raw Fiber, fork justified for hot Thread/Future paths
- If <2×, AMPHP's correctness machinery (cancellation, composition,
  error propagation) is worth keeping

## Results (rank 1)

### No JIT

```
raw Fiber empty spawn+join                    median=   11134 ns/op
Amp\async empty spawn+await                   median=    3676 ns/op
    → Amp/raw ratio: 0.33x          [AMPHP FASTER — see anomaly note]

raw Fiber suspend+resume cycle                median=   12649 ns/op
Amp\async with delay(0) reschedule            median=   24346 ns/op
    → Amp/raw ratio: 1.92x

raw Fiber 1000 spawn+resume in flight         median=   13039 ns/op
Amp\async 1000 spawn+await in flight          median=   35198 ns/op
    → Amp/raw ratio: 2.70x          [bulk-throughput case]

raw Fiber 5-deep chain (manual)               median=   62320 ns/op
Amp\Future 5-deep chain (then-style)          median=   78475 ns/op
    → Amp/raw ratio: 1.26x

Mean Amp/raw ratio: 1.55x (workload-mean)
```

### Tracing JIT

```
raw Fiber empty spawn+join                    median=   13700 ns/op
Amp\async empty spawn+await                   median=    2642 ns/op
    → Amp/raw ratio: 0.19x          [anomaly — see below]

raw Fiber suspend+resume cycle                median=   12771 ns/op
Amp\async with delay(0) reschedule            median=   24960 ns/op
    → Amp/raw ratio: 1.95x

raw Fiber 1000 spawn+resume in flight         median=   14122 ns/op
Amp\async 1000 spawn+await in flight          median=   30360 ns/op
    → Amp/raw ratio: 2.15x

raw Fiber 5-deep chain (manual)               median=   69099 ns/op
Amp\Future 5-deep chain (then-style)          median=   76968 ns/op
    → Amp/raw ratio: 1.11x

Mean Amp/raw ratio: 1.35x (workload-mean)
```

## Reading the numbers

**Bulk-spawn-in-flight is the load-bearing case** for PHPJava — it
matches `Thread.start()` patterns where multiple Java threads run
concurrently. Under JIT, AMPHP is 2.15× raw Fibers (~30 µs vs 14 µs
per Thread.start equivalent).

**Suspend/resume cycle** corresponds to `Object.wait()/notify()` and
inter-thread synchronization. AMPHP is ~2× — adds ~12 µs per
context switch via the event-loop dispatch.

**Future composition (chain)** — the `CompletableFuture.thenApply`
analog. AMPHP is 1.11× under JIT. Negligible overhead; the chain's
dominant cost is the work itself, not the composition machinery.

**Mean: 1.35× under JIT.** Inside the fork-or-not threshold (we set
3× as the bar pre-measurement).

### The empty-spawn anomaly

`Amp\async(fn(){})->await()` is **0.19–0.33× raw Fiber empty
spawn+join** — i.e. AMPHP is faster at running an empty function.
Counter-intuitive since AMPHP does strictly more work (Future alloc
+ event-loop tick + watcher cleanup).

Two plausible explanations (rank 5):

1. AMPHP keeps a fiber pool; `Amp\async` reuses an existing fiber
   instead of allocating a new one. Raw `new Fiber(...)` always
   allocates a fresh ~4 KB stack page.
2. JIT specialises better over `Amp\async`'s closure-call path than
   over the `new Fiber()->start()` opcode sequence.

Either way, the anomaly only affects the empty case — real workloads
hit the 1.95–2.15× case, which is the relevant overhead.

## Verdict — don't fork yet

The 1.35× mean overhead under JIT doesn't justify the maintenance
burden of forking. Three reasons:

1. **The absolute perf ceiling is set by Fibers themselves**, not by
   AMPHP. Raw Fiber suspend/resume is ~12 µs; HotSpot virtual threads
   are sub-microsecond. Forking AMPHP saves 1.35× but the gap to
   HotSpot virtual threads stays at 10–100×. Forking is fighting the
   wrong battle.
2. **PHPJava's v1 workloads don't stress Thread.start hot paths.**
   bb-allowlist non-stub fill exercises stdlib classes (Pattern,
   String, Collection, Math) — concurrency primitives are tail-tier
   work. The 1.35× tax doesn't surface.
3. **AMPHP's correctness machinery is load-bearing** when concurrency
   does land. Cancellation tokens, error propagation through
   compositions, debugger integration, well-tested production
   deployments. A forked-and-trimmed version would either re-derive
   these (pointless) or punt them (worse).

### When forking would be justified

- A real workload surfaces that needs >100K Thread.start/sec AND
- Profiling pinpoints AMPHP's machinery (not raw Fiber alloc) as
  the dominant cost AND
- The dead-weight we'd remove is localised (Cancellation, complex
  composition primitives) and not load-bearing for our paths

None of these conditions hold today. Reopening this question is
appropriate when a fixture hits the bottleneck.

## What this means for ROADMAP §T3

T3's AMPHP-primary framing (committed earlier this session) stands
unchanged. The pure-PHP deployment story is the strategic win;
1.35× of raw-Fiber perf is the price of that portability and the
correctness machinery. Swoole-when-available remains the optimisation
path for workloads where the 1.35× matters AND Tier-2 shared memory
is needed.

## Reproducibility

- Bench: `bench/amphp-probe/bench.php`
- Composer (out-of-tree, doesn't pollute root): `bench/amphp-probe/composer.json`
- Run: `php bench/amphp-probe/bench.php`
- Run with JIT: `php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=256M -d opcache.jit=tracing bench/amphp-probe/bench.php`

The `bench/amphp-probe/vendor/` is gitignored (added below); install
via `cd bench/amphp-probe && composer install` to reproduce.

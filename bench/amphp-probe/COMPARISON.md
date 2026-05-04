# Option B vs Option 3 — comparison + layering

> Date: 2026-05-04. Continuing from `POC-RESULTS.md` and the
> may-suspend analyzer commit.
>
> Original frame from end-of-session "next steps":
>   - **B**: may-suspend analysis IR pass + future emit-specialiser
>   - **3**: wire AMPHP as the actual Thread/Future shim (T3)

## TL;DR

**B and 3 are layered, not competing.** 3 is the runtime substrate; B
is the compile-time optimisation that skips the substrate when
analysis proves it's safe. Both ship; the question is order.

## Option B status (this commit)

`src/Aot/Ir/Analysis/MaySuspendAnalyzer.php` — IR pass that classifies
methods as may-suspend / definitely-doesn't-suspend. Walks Stmt + Expr
trees for calls into a 30-method JVM-API whitelist (Thread.sleep,
Object.wait, Future.get, BlockingQueue.take, Lock.lock, Socket.connect,
etc.). 4/4 fixture-driven tests pass.

What B unlocks per bench:
- 39× speedup for inlinable async patterns (vs going through the
  PoC v2 runtime)
- 220× over AMPHP for the inlinable case
- Coverage estimate: 60-80% of real `Thread.start(runnable)` and
  `executor.submit(fn).get()` patterns in user Java code (per the
  POC-RESULTS.md analysis)

What B does NOT include yet:
- The emit-specialiser that consumes the verdict at AOT compile sites
  to actually rewrite async/await pairs into direct calls. Downstream
  work; folds into the T3 wire-up when the runtime substrate lands.

The analyzer alone is dormant infrastructure — but it's **the load-
bearing piece**. The emit-specialiser is mechanical pattern-matching
on top of it. Without the analyzer, the specialiser has nothing to
specialise *on*.

## Option 3 — wire AMPHP as the shim runtime

Implementation surface (estimated):

| Class | What | LOC est |
|---|---|---|
| `java.lang.Thread` | wrap `Amp\async` for `start`, `Amp\delay` for `sleep`, `Future::await` for `join` | ~150 |
| `j.u.c.CompletableFuture` | wrap `Amp\Future`; `supplyAsync` → `Amp\async`; `thenApply` → `Future::map`-equivalent via composition | ~250 |
| `j.u.c.Future` | interface; backing impl is `Amp\Future` | ~80 |
| `j.u.c.ExecutorService` | wrap `Amp\Pipeline\Queue` + worker fibers | ~150 |
| `j.u.c.locks.ReentrantLock` | wrap `Amp\Sync\LocalMutex` | ~100 |
| `j.u.c.locks.ReadWriteLock` | wrap `Amp\Sync\LocalKeyedMutex` | ~100 |
| `j.u.c.BlockingQueue` | wrap `Amp\Pipeline\Queue` (buffered) | ~150 |
| `j.u.c.CountDownLatch` | wrap `DeferredFuture` | ~80 |
| `j.u.c.Semaphore` | wrap `Amp\Sync\LocalSemaphore` | ~100 |
| Strategy-dispatcher boot hook | check `extension_loaded('swoole')` + env, pick backend | ~60 |
| Composer dep glue | add `amphp/amp` + `amphp/sync` to root composer.json | trivial |
| Tests | per-class fixture + `await + assert == JVM` parity case | ~400 |

Total: **~1,600 LOC**. Days-of-work, mechanical, well-scoped.

## What 3 unlocks

- **bb-allowlist concurrency surface becomes functional** — most JVM
  Clojure code that touches `j.u.concurrent.*` runs against AMPHP
  primitives instead of throwing `NotImplementedException`.
- **AMPHP-baseline perf** — every async call pays the ~2 µs/op AMPHP
  cost the bench measured. Functional, but unoptimised.
- **The runtime substrate B's emit-specialiser falls back to** when
  may-suspend analysis can't prove safety.

## What 3 doesn't unlock alone

- The 39× speedup for inlinable patterns — that requires B's emit-
  specialiser. Without it, even the trivial `submit(fn).get()`
  case pays the full AMPHP cost.
- The InlineExecutor path from POC-RESULTS.md (the PoC's 5–9× speedup over
  AMPHP via Fiber-skip) — that's a parallel optimisation that could
  be applied INSIDE 3's shim implementations, but it's a separate
  build choice (use AMPHP-direct vs ship a custom Tier-A runtime).

## How they compose

```
   AOT compile site sees:
     CompletableFuture.supplyAsync(lambda).get()
                 │
                 ▼
       MaySuspendAnalyzer.maySuspend(lambda)
                 │
        ┌────────┴────────┐
        │                 │
       false             true
        │                 │
        ▼                 ▼
   Inline as          Emit normal call:
   $r = $lambda();    Runtime::CompletableFuture::supplyAsync($lambda)->get()
                            │
                            ▼
                      AMPHP shim runtime
                      (option 3 deliverable)
```

The compiler chooses per-callsite. Inlinable cases skip the runtime
entirely (39× win). Non-inlinable cases go through the AMPHP shim
(1× = baseline). Both shipped, both load-bearing for different paths.

## Order of work

1. **✓ Analyzer (this commit)** — dormant infra; small (~250 LOC)
   but load-bearing.
2. **3 — AMPHP shim runtime** — ~1,600 LOC, ~3-5 days. Brings T3
   surface from stub-only to functional.
3. **B — emit-specialiser** — ~300 LOC consuming analyzer verdicts
   at AOT compile sites. Wires into 3's call sites: the specialiser
   intercepts `CompletableFuture.supplyAsync(lambda).get()` patterns,
   asks the analyzer about lambda's body, emits direct call OR
   shim call accordingly. Days-of-work; structurally simple.

This order maximises immediate user-visible progress (3 unblocks
real code from throwing `NotImplementedException`) while keeping
the optimisation infrastructure in place (B) for when the emit
specialiser is wired.

## Why not 3 first then B?

It's the right order, with B's analyzer prerequisite shipped first
(this session). Once 3 lands, the emit-specialiser is straightforward
because the analyzer's already producing verdicts the specialiser
just consumes.

The reverse order — B-emit-specialiser before 3 — is structurally
broken: there's no shim surface for the specialiser to optimise
against. The non-inlinable fallback path leads to
`NotImplementedException`.

So:
- B-analyzer **before 3**: ✓ (load-bearing for B-emit-specialiser
  later, doesn't block 3)
- 3 **before B-emit-specialiser**: ✓ (specialiser needs the surface
  it's specialising)
- **3 + B-analyzer**: ✓ (current state — substrate becomes
  functional, optimisation infra ready)
- **3 + B-analyzer + B-emit-specialiser**: ✓ (full T3 vision
  delivered; ~220× speedup on inlinable patterns vs un-optimised
  AMPHP)

## Comparison summary

| Dimension | B (analyzer + future emit) | 3 (AMPHP shim) |
|---|---|---|
| LOC | ~250 (analyzer) + ~300 (specialiser) = ~550 | ~1,600 |
| Effort | analyzer ~half-day (this session); specialiser ~2 days | ~3-5 days mechanical |
| Unblocks user code | only when paired with 3 | yes — j.u.concurrent stops throwing NotImplemented |
| Perf ceiling | 220× over AMPHP for inlinable; ~1× for fallback | 1× AMPHP-baseline |
| Standalone deliverable | analyzer alone is dormant | yes |
| Project location | PHPJava's IR + AOT | PHPJava's JDK shim layer |

**Verdict**: this session shipped B's analyzer (the prerequisite) plus
the bench measurement substantiating the 220× claim. Next focused
session: **option 3 (AMPHP shim runtime)**, then B's emit-specialiser
on top. Estimated: ~5-8 days total to reach "functional T3 surface
with optimised inlinable pattern emit."

This is the canonical implementation path per ROADMAP §Build T3
detail's "Higher-leverage variant". Documenting here completes the
analysis chain that started with the user's "how is it possible /
can it be faster" question.

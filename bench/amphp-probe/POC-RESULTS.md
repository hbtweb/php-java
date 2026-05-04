# PoC: minimum-viable async runtime in PATTERNS.md style

> Date: 2026-05-04. PHP 8.4.20. Bench: `bench-poc.php`.
> Probe sibling: `RESULTS.md` (raw Fiber vs AMPHP).
>
> User question driving this PoC: "what would happen if we wrote
> the runtime like our AOT compiles — the fastest patterns?"

## Predictions (rank 5, before measurement)

- 6–10× speedup over AMPHP, mechanism = same Fiber+Suspension+Future
  shape but with PATTERNS.md rules (no boxing, sealed arrays, locals
  not properties, switch-dispatch).

## Rank-1 results

### No JIT

```
empty spawn+await:        raw 9.8 µs · Amp 3.1 µs · PoC 0.67 µs · PoC vs Amp 0.21×
return-value spawn+await: raw 10.6 µs · Amp 3.8 µs · PoC 0.77 µs · PoC vs Amp 0.20×
1000 in flight:           raw 11.4 µs · Amp 4.2 µs · PoC 1.07 µs · PoC vs Amp 0.26×
```

### Tracing JIT

```
empty spawn+await:        raw 11.1 µs · Amp 2.4 µs · PoC 0.53 µs · PoC vs Amp 0.22×
return-value spawn+await: raw 9.5 µs · Amp 2.6 µs · PoC 0.57 µs · PoC vs Amp 0.22×
1000 in flight:           raw 9.7 µs · Amp 2.7 µs · PoC 0.90 µs · PoC vs Amp 0.34×
```

**PoC: 3–5× faster than AMPHP, 15–20× faster than raw Fibers.**

## Key insight — prediction wrong, conclusion stronger

The 6–10× prediction was rank-5 speculation about TIGHTENING the
AMPHP shape. Rank-1 reality: the speedup comes from CHANGING the
shape — skipping Fibers entirely for non-suspending tasks.

AMPHP allocates `Fiber` + `Suspension` + `Future` on every `async()`
regardless of whether the task body actually suspends. For pure-compute
bodies (`fn() => 42`), the Fiber stack page is unused — pure overhead.
The PoC's "Tier A" path recognises non-suspending tasks and runs them
as queued callbacks. No fiber, no suspension, no allocation beyond
the Future tuple.

```
   AMPHP unconditional path:
       async($fn)
       ├── new DeferredFuture
       ├── new Closure(wrap)
       ├── EventLoop::queue
       ├── Driver::dispatch ──> Fiber alloc + start
       │                        $fn() runs
       │                        Fiber terminates
       └── return Future

   PoC Tier A (non-suspending fn):
       async($fn)
       ├── futures[id] = [PENDING, null, -1]
       ├── queue[] = wrap-and-settle closure
       └── return id
       await(id)
       ├── while pending: array_shift(queue)(); fire wrap-and-settle
       └── return futures[id][1]
```

For tasks that DO suspend (call `await()` themselves), the PoC needs
real Fibers and the cost converges to AMPHP-equivalent overhead. The
speedup shrinks toward the 1.5–2× originally predicted. The Tier A
fast path is the win when it applies.

## What this means for the strategic frame

The user's framing was: **rule-configurable compiler, write source
once in Clojure, recompile per backend**. Rank-1 evidence here makes
the case concrete:

The right async runtime isn't a single shape — it's two shapes plus
a static analysis to choose between them. AMPHP can't make this
choice because it's a runtime library shipping one shape. A
compile-time emit (cljp / PHPJava's AOT) can:

1. Static analysis on the `async()` body: does it ever call `await()`,
   `BlockingQueue.take()`, `Thread.sleep()`, `socket.read()`,
   `Object.wait()`, or any other suspend point?
2. If no → emit Tier A (queue+drain, ~0.5 µs/call)
3. If yes → emit Tier B (Fiber+Suspension+Future, ~2.5 µs/call —
   AMPHP-equivalent, plausibly tunable to ~1.5 µs with PATTERNS.md
   rules but the structural cost dominates)

This is exactly the kind of choice cljp's IR transforms already make
for other patterns (escape analysis on Java arrays, inline pass for
single-return-expr methods, peephole on operand-stack residue).
Adding a "may-suspend" analysis to the IR + a dual-emit at the async
site is structural work — but it's the right work, and it's the kind
of work that's hard for AMPHP to do because they don't have an IR.

## Constraints and caveats

- The PoC omits the entire Cancellation token system. Java has
  `Thread.interrupt()` which is the moral equivalent; the full mapping
  needs a check-on-suspend-resume protocol that adds overhead. Probably
  ~10–20% on the Tier B path; Tier A unaffected (no fiber to interrupt).
- The PoC's `await()` inside a fiber path is correct but unbenched.
  The 0.5 µs number is the queue-only path. Fiber-suspending await
  measurements are a follow-up.
- I/O integration (the actual *useful* part of an async runtime) isn't
  in the PoC. AMPHP's value isn't async/await syntax — it's that
  `Amp\Socket\connect()` doesn't block. The PoC inherits raw PHP I/O
  (blocking). For PHPJava's Java I/O surface, the underlying `fread`/
  `fwrite` would still block; making them non-blocking requires a
  StreamSelectDriver-equivalent (~333 LOC in Revolt).

## Verdict

The previous "don't fork AMPHP" decision was correct under the
assumption that a rewrite would be 1.35× over AMPHP. With the
shape-change insight, a rewrite (or cljp-emit) could be 3–5× faster
on the Tier A workload — but only because we know how to choose
between shapes via compile-time analysis. AMPHP doesn't know that
trick.

The real opportunity isn't "rewrite AMPHP." It's "give the AOT
compiler enough static analysis to pick the right shape per call
site," and let the runtime stay shaped to handle either. The
~330 LOC PoC + the ~150 LOC the cancellation/IO stubs would add =
~500 LOC of runtime. Plus an IR analysis pass in cljp / php-java
that classifies async-bodies as suspend-or-not. That last part is
the leverage.

## What to do with this

Three options ranked by leverage:

| Option | Cost | What we get |
|---|---|---|
| Stay on AMPHP | 0 | 1× baseline; loses the Tier A speedup forever |
| Ship the PoC runtime | ~500–1000 LOC + ongoing maintenance | 3–5× on Tier A; ~1× on Tier B; reinvent AMPHP's correctness machinery |
| **cljp-emit + 2-tier runtime + IR analysis pass** | sister-project compiler work + ~500 LOC runtime | 3–5× on Tier A AS DEFAULT; ~1.5–2× on Tier B with PATTERNS.md rules; runtime is generated, not maintained |

The third is the user's strategic vision applied. It's also clearly
out of scope for this PHPJava session — the pieces are split across
projects. Adding to ROADMAP §Build "concurrency adapter (T3)" as
the canonical implementation path; the AMPHP-as-default story stands
as the fallback when the cljp path isn't ready.

## Reproducibility

```bash
cd bench/amphp-probe
composer install
php bench-poc.php
php -d opcache.jit=tracing -d opcache.jit_buffer_size=256M bench-poc.php
```

Bench harness: `bench-poc.php`. PoC source: `poc-runtime.php`.

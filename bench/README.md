# PHPJava bench

Phase 0 of `ROADMAP.md` — establish measured baselines before any optimisation work.

## Method

The harness uses Java 22+ Foreign Function & Memory API to call into `libphp8.4-embed`
(`/usr/lib/libphp8.4.so`) directly from a long-running JVM, calling
`zend_eval_string` for each measurement. PHP runtime is initialised once;
PHPJava autoload is preloaded once. This isolates PHPJava interpreter
cost from PHP process startup cost.

The FFM transport pattern is borrowed from `cljp.transport.ffm` in the
ClojurePHP project — same Linker/SymbolLookup plumbing, narrowed to the
two functions needed (`php_embed_init`, `zend_eval_string`).

## Run

```bash
clj -M:run
```

Requires:
- JDK 22+ (FFM final API)
- `libphp8.4-embed` Debian package (or equivalent providing libphp.so)
- PHPJava `vendor/` populated (`composer install` from repo root)

## Baseline @ d803364 (master + roadmap branch, no optimisations)

PHPJava 8.4.20 / OpenJDK 25.0.2 / commodity x86_64 laptop.

| Bench | Cost | Per-unit |
|---|---|---|
| `ffm-eval-rtt` | 2.69 ns/iter | (PHP `$x=1;` inside one eval — measures Zend opcode, not FFM RTT) |
| `phpjava-helloworld` | 2.33 ms/call | full HelloWorld + class load |
| `phpjava-empty-method` | 810 µs/call | includes class load per iter |
| `phpjava-iadd-1k` | 41.8 ms/call | **5.22 µs per JVM bytecode op** |
| `phpjava-invokevirtual-100` | 9.5 ms/call | **95 µs per Java method call** |

HotSpot reference (same fixtures, run via `BenchRunner.java`):

| Bench | `java -Xint` | `java` (JIT) |
|---|---|---|
| `sum1k` | 4.19 µs/call (0.52 ns/op) | 6.04 µs/call (JIT noise at 50 iters) |
| `invoke100` | 1.25 µs/call (12.5 ns/call) | 3.62 µs/call |

## PHPJava vs HotSpot interpreted

- **Per JVM bytecode op:** ~10,000× slower (5.22 µs vs 0.52 ns)
- **Per Java method call:** ~7,600× slower (95 µs vs 12.5 ns)

These numbers are larger than the rank-3 estimates in `ROADMAP.md` (which
predicted "100–1000× slowdown"). The roadmap and `docs/GAP-JDK.md` will
be revised to reflect measured reality.

## What this means for the roadmap

The hot-loop costs identified in `ROADMAP.md` (H1–H8, M1–M4) explain
*most* of the gap, but not all of it. Even if every accidental cost
listed there is fixed:

- Best-case post-fix per-op: ~500 ns – 1 µs (vs HotSpot 0.52 ns)
- Best-case post-fix per-call: ~5–15 µs (vs HotSpot 12.5 ns)

That's **~1,000–2,000× HotSpot interpreted** post-fix. Clojure boot, which
takes ~1–2 s on JVM, projects to ~17–50 minutes on PHPJava even after
Phase 2 of the roadmap. The "60 s Clojure boot" target in Phase 5 of
`ROADMAP.md` is too optimistic; falsifier F1 is in play.

The honest Phase 5 line is probably "REPL prompt in 5–10 minutes" with
extreme optimisation, or "REPL prompt at all, time uncapped" as the
realistic first milestone. This needs strategic discussion before more
work commits.

## Caveats

- `ffm-eval-rtt` does NOT measure FFM round-trip time — it measures
  Zend opcode cost inside one eval. To measure true FFM RTT, do
  10,000 separate `eval-php` calls. Future revision.
- `phpjava-empty-method` includes `JavaClass::load` per iteration via
  `ClassResolver` cache. The per-call number (810 µs) is class-load +
  method-call cost, not pure method-call. The 95 µs/call figure from
  `invokevirtual-100` is the cleaner per-method-call measurement (no
  class re-resolution, just INVOKESTATIC inside one already-loaded class).
- HotSpot JIT numbers are noisy at 50 iters because JIT compilation
  overhead is not amortised. Take only `-Xint` numbers as load-bearing.
- All measurements are wall-clock from `microtime(true)` in PHP and
  `System.nanoTime()` in Java. No statistical replication. Single-shot
  numbers; could be off ±10–20% on a noisy host.

Reproduce by running `clj -M:run` in this directory and `java -Xint
BenchRunner` from `fixtures/`.

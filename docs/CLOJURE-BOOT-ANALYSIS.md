# Historical JDK stress trace from Clojure boot

> Date: 2026-05-01.
> Method: `java -verbose:class -cp clojure-1.13.0-slim.jar clojure.main -e '(println "ok")'`
> Captured 2825 distinct class names; classified below.
>
> Status: historical stress trace only. PHPJava's product goal is running
> Java libraries from PHP/CLJP, not running Clojure or `clojure.lang` on
> PHPJava. This document remains useful as a broad JDK-surface corpus.

## Headline numbers

| Set | Count |
|---|---|
| All classes loaded during the trace | 2825 |
| `java.*` / `javax.*` classes loaded | 985 |
| `clojure.*` classes loaded | 1458 |
| Other (`sun.*`, `jdk.*`) | ~382 |

| Set | Count |
|---|---|
| bb's curated allowlist (`src/babashka/impl/classes.clj`) | 383 |
| **bb allowlist ∩ traced loads** | **233** |
| Traced loads NOT in bb allowlist | **752** |

## What this means

- **The `233` set is a broad JDK stress subset of bb's allowlist.** It is useful T2 input because it touches real `java.*` / `javax.*` surfaces that Java libraries commonly rely on. It is not a commitment to boot Clojure on PHPJava.

- **The `752` set shows how quickly a large JVM program pulls in JDK internals.** When bb is built with `native-image`, Graal bundles full OpenJDK implementations of these classes. PHPJava has no Graal substrate; any Java library that touches a similar surface must find either a PHP implementation or a deliberate stub.

- **Many of the `752` are transitively required but never user-visible.** Classes like `java.io.UnixFileSystem`, `java.beans.Introspector`, `java.awt.Image` get loaded as part of static init chains in `java.io.File`, `java.lang.Class`, etc. They don't need full implementations — often a stub class with the right name and one or two methods is enough to satisfy class-loading.

## Categorised gap (`752` traced classes that bb doesn't allowlist)

Hand-grouped from sampling the gap list:

### Likely-stub-only (load but don't actively use ~400 classes)

- `java.awt.*` — the trace touches `Image`, `FontMetrics`, etc. through reflection-driven introspection of `java.beans`. Never actually used.
- `java.beans.*` — Introspector, BeanInfo, etc. Loaded by reflection paths that Clojure rarely takes.
- `java.io.UnixFileSystem`, `DefaultFileSystem`, `FileCleanable` — internals of File. Never user-visible.
- `sun.*`, `jdk.internal.*` — internals of OpenJDK. Stub for class-loading; never call.

These can be implemented as **empty PHP classes with minimal stubs** — they exist so `Class.forName` resolves and `<clinit>` doesn't throw, nothing more.

### Transitively required (~250 classes)

- `java.io.Filter*Stream/Reader/Writer` — required by `BufferedInputStream` etc. (already in bb)
- `java.lang.ref.*` (Reference, ReferenceQueue, SoftReference, WeakReference) — referenced by JDK collections
- `java.util.concurrent.*` and `java.util.concurrent.atomic.*` — required by ConcurrentHashMap (which Clojure uses heavily)
- `java.nio.charset.*` — full charset machinery
- `java.text.*` — Format/Collator chains

These need **real implementations** because Clojure code paths actually exercise them. They're the "T3 — concurrency adapter" and parts of T2 in the roadmap.

### JDK-internal hot paths (~100 classes)

- `sun.misc.Unsafe` (or `jdk.internal.misc.Unsafe`) — used by ConcurrentHashMap, AtomicReference, lots of JDK collections
- `jdk.internal.reflect.*` — reflection backbone
- `sun.reflect.misc.MethodUtil` — varargs-aware reflection invocation

These are the **`Unsafe` requirement** flagged in ROADMAP.md T5 — until something stands in for `Unsafe`, ConcurrentHashMap (and thus most of `java.util.concurrent.*`) doesn't work.

## Implications for the roadmap

The original ROADMAP.md T2 estimate ("~80 bb core classes") was an
underestimate. Revised:

| Tier | Original | Revised | Reasoning |
|---|---|---|---|
| T1 (class file format + lambda metafactory) | unchanged | unchanged | not class-list driven |
| **T2 (bb-allowlist ∩ Clojure-boot)** | ~80 classes | **233 classes** | empirical; `comm -12` of allowlist and boot trace |
| **T3 (concurrency adapter)** | ~6 classes | **~50 classes** | full `java.util.concurrent.*` not just atomics + locks |
| T4 (bb-allowlist tail not on boot path) | ~300 classes | **150 classes** (383 - 233) | smaller than thought |
| **T5 (extension surfaces + JDK internals)** | ~10 surfaces | **`Unsafe` + ~100 JDK-internal stubs** | Unsafe is not optional for ConcurrentHashMap |

**Net scope: ~530 classes total to implement, of which ~400 are
real implementations and ~130 are stubs.** Roughly 5–8× more code than
the earlier "~383 class" estimate suggested, but a meaningful fraction
is stubs (10–50 LOC each).

## Concrete next steps from this analysis

1. **Stub generator** — **DONE 2026-05-04**. `tools/gen-aot-stubs.php`
   javap-driven; emits PHP signature stubs under
   `src/Aot/Runtime/<package>/<Name>.php` with `NotImplementedException`
   bodies. 110 stubs generated covering the bb-allowlist ∩
   Clojure-boot ∩ not-already-present surface. 4 PHP-reserved-leaf
   classes (Object, Class, Float, plus List/Function) deferred — need
   a `Builder.classFqn` remap to route AOT calls to `<Name>_`.
2. **`Unsafe` implementation** — pure PHP fallback (no FFI required for
   most uses; CAS via PHP-level locking). ~500 LOC. **High priority** —
   without it, ConcurrentHashMap doesn't work.
3. **Concurrency adapter** scope expanded — full `java.util.concurrent`
   not just atomics. Per-class effort ~100–500 LOC.
4. **Reflection completeness** — `Class`, `Method`, `Field`, `Constructor`
   to JVMS spec. Used by Clojure's RT internals.

## Files

- `/tmp/clojure-boot-classes.txt` — full class-load trace (2825 entries)
- `/tmp/bb-allowlist.txt` — bb's java/javax allowlist (383 entries)
- `/tmp/required-set.txt` — intersection (233 entries — the "must implement" core)
- `/tmp/clojure-boot-classes.txt` minus `/tmp/bb-allowlist.txt` — the 752-class gap

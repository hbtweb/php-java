# CLJP Java interop bridge

> Design sketch for connecting CLJP programs to AOT'd Java library code
> at runtime. CLJP owns Clojure semantics; PHPJava owns Java/JVM-library
> bytecode semantics. The bridge marshals values only at Java interop
> boundaries.
>
> Companion to `docs/MODEL.md` (peers on Zend), `docs/ADJACENT-SHAPES.md`
> (adjacent project shapes), and `docs/LAYERS.md` (what survives, what
> cuts).

## Boundary

| Surface | Owner |
|---|---|
| Clojure source, `clojure.core`, Vars, protocols, persistent collections, `clojure.lang`-shaped runtime behavior | CLJP |
| Java/JDK/Maven class files, bytecode dispatch, class loading, JDK shims | PHPJava |
| Calls between CLJP and Java libraries | Explicit bridge/adapters |

PHPJava is not a Clojure runtime. It should not run CLJP source,
translate Clojure bytecode as the product path, or map `clojure.lang.*`
to CLJP classes. Running Clojure on PHPJava remains a possible research
experiment, but it is outside this repository's product contract.

## Direction 1: CLJP calls AOT'd Java code

**Path:** CLJP code imports a Java class and calls a static/instance
method:

```clojure
(ns app.pdf
  (:import [org.apache.pdfbox.pdmodel PDDocument]))

(PDDocument/load file)
(.getNumberOfPages doc)
```

CLJP resolves the Java class through its normal `:import` mechanism,
loads the PHPJava-generated PHP class, and emits an ordinary PHP
static/method call. Once AOT receivers are PHP-native objects, this is
just Zend method dispatch.

## Direction 2: Java code receives a CLJP value or callback

Java libraries sometimes need a callback, iterable, map, stream, or
other host object. That is an adapter problem, not a `clojure.lang`
substitution problem.

Examples:

| Java expects | Bridge shape |
|---|---|
| Functional interface / callback | Adapter object whose Java-facing method calls a CLJP `IFn` |
| `Iterable` / iterator | Adapter over a CLJP seqable value |
| `Map` / `List` / `Set` | Adapter over CLJP persistent collections, or an explicit copy where Java mutability is required |
| Java exception crossing back | Exception wrapper preserving Java class identity and CLJP source context where available |

The adapter is explicit at the interop boundary. CLJP values keep CLJP
semantics inside CLJP; Java values keep Java semantics inside PHPJava.

## Direction 3: Values crossing the boundary

| Value | Default crossing |
|---|---|
| `nil` / `null`, booleans, ints, floats, strings | Direct PHP scalar |
| CLJP keywords/symbols | CLJP-owned values; bridge only if a Java API explicitly needs a representation |
| CLJP persistent collections | Adapter or explicit copy to Java collection shape |
| Java collections | Adapter to CLJP seq/map/set operations, or explicit copy to persistent collection |
| PHP host objects | Passed as ordinary PHP objects when both sides agree on the contract |

No shared `$GLOBALS` runtime is involved. No CLJP persistent collection is
made into a Java collection by pretending it is `clojure.lang.*` inside
PHPJava.

## PHPJava implementation surface

PHPJava needs only Java-side support:

1. Stable AOT output classes (`\PHPJava\Aot\Generated\<X>`) that CLJP can
   call through ordinary PHP method/static dispatch.
2. Loader hooks for resolving Java class names requested by CLJP
   `:import`.
3. A small adapter library for Java-facing interfaces/collections where
   CLJP values cross into Java.
4. Benchmarks for CLJP -> Java call overhead and adapter overhead.

The existing compiler `substitutionMap` is still a generic low-level AOT
escape hatch for tests and advanced embedding, but it is not the CLJP
interop model and must not be documented as `clojure.lang.*` mapping.

## Sequencing

| Step | What | Blocked by |
|---|---|---|
| 1 | Smoke probe: PHP calls an AOT'd Java method through PHPJava-generated class | Current AOT path |
| 2 | CLJP import resolver recognizes Java FQNs and loads PHPJava-generated classes | CLJP-side integration |
| 3 | Smoke probe: CLJP calls an AOT'd Java static and instance method | Step 2 |
| 4 | Adapter: CLJP `IFn` -> Java functional interface | Step 3 |
| 5 | Adapter: CLJP seq/map/set <-> Java iterable/map/list as surfaced by real libraries | Step 3 |
| 6 | Bench: direct call and adapter costs under CLI + Swoole/RoadRunner | Steps 3-5 |

## Non-goals

- Do not compile CLJP source through PHPJava.
- Do not route CLJP's `clojure.core` or `clojure.lang` through PHPJava.
- Do not add a `clojure/lang/*` namespace substitution table for CLJP.
- Do not share CLJP's `$GLOBALS`/Var tables with PHPJava.
- Do not make Java values obey Clojure semantics globally.

The clean model is smaller: CLJP runs Clojure; PHPJava runs Java
libraries; explicit adapters connect them where user code crosses the
language boundary.

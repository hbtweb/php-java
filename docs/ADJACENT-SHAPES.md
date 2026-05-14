# Adjacent shapes — what's been built, what hasn't, what we can borrow

Date: 2026-05-01.

The PHPJava project sits in a 2D space of (source language) × (target runtime) × (execution mode). Mapping the whole space surfaces what's been proven, what's empty territory, and where existing projects already solved problems we'd otherwise re-derive.

---

## 1. The matrix

Each cell is `(source, target, mode)` → projects that fill it.

| Source | Target | Mode | Project(s) | Notes for our work |
|---|---|---|---|---|
| Java source (.java) | JVM bytecode (.class) | compile | `javac` | canonical |
| Java source | JVM bytecode | compile | **PHPJava `Compiler/Lang/Assembler/`** | the PHP-syntax → bytecode thing — wrong direction |
| Java source | PHP | compile | — | empty |
| Java source | JS | compile | GWT, J2CL | proven shape, decades of production |
| Java source | C++ | compile | RoboVM (defunct), J2ObjC | Apple toolchain |
| **Java bytecode (.class)** | **JVM native** | **JIT** | **HotSpot, OpenJ9** | reference perf: 0.1–0.5 ns/op |
| Java bytecode | JVM native | interp | HotSpot template interp (`-Xint`) | reference perf: ~0.5 ns/op |
| Java bytecode | Native binary | AOT | **GraalVM native-image** | foundation for bb |
| Java bytecode | Native + dynamic load | AOT | **GraalVM Crema** | preview, lifts native-image's class-loading restriction |
| Java bytecode | C | AOT | Excelsior JET (defunct), Avian AOT | proven, niche |
| Java bytecode | JS | AOT | **TeaVM, CheerpJ, Bck2Brwsr** | **closest reference** for what we want |
| Java bytecode | JS | interp | **DoppioJVM** | direct browser analogue of PHPJava |
| Java bytecode | WASM | AOT | JWebAssembly, J2CL→WASM, CheerpJ-WASM | newer; smaller code, faster |
| Java bytecode | .NET CIL | translate | IKVM.NET | Java libraries on .NET |
| **Java bytecode** | **PHP** | **interp** | **PHPJava** | **the cell we're in** |
| **Java bytecode** | **PHP** | **AOT** | **— empty —** | **the gap** |
| PHP source | JVM bytecode | interp | **Quercus** (Caucho) | PHP-on-JVM, ran WordPress, discontinued ~2020 |
| PHP source | JVM bytecode | compile | **PHPJava `Lang/Assembler/`** | PHP-syntax → real JVM, can run with `java` |
| PHP source | .NET CLR | compile | **PeachPie** | PHP-on-.NET, active project |
| PHP source | C++ | AOT | **KPHP** (VK) | production at vk.com |
| PHP source | Native | JIT | **HHVM** (Meta), Zend OPcache JIT | de facto modern PHP perf path |
| PHP source | Native | interp | **Zend Engine** | the canonical PHP runtime |
| Clojure source | JVM bytecode | compile | `clojure` (canonical) | reference Clojure |
| Clojure source | JS | compile | **ClojureScript** | proven sister-target |
| Clojure source | Native (via JVM) | AOT | **Babashka** | bb = AOT'd JVM Clojure + SCI |
| Clojure source | PHP | compile | **cljp** | this team's project, the sister of cljp |
| Clojure source (interp) | JVM | interp | **SCI** | bb's interpretation layer |
| Clojure source (interp) | PHP | interp | — empty (`sci-on-php` would fit) | discussed earlier |

---

## 2. The empty cells worth filling

Three are open:

1. **Java bytecode → PHP, AOT.** Nobody has done it. TeaVM is the proven reference (Java→JS AOT) — same architectural shape, different target.
2. **Clojure source → PHP, interp.** "SCI on PHP." Discussed in earlier rounds. Tractable if cljp's reader/macros are reused.
3. **Java source → PHP, compile.** Marginal value — most Java code with sources should compile to `.class` first then go through #1. Skip.

The interesting structural observation: **cljp already chose PHP as a target and built the runtime + emitter for it.** A Java-bytecode-to-PHP AOT compiler would emit code into the *same PHP runtime* cljp emits into. **The runtime is shared; only the front end differs.** That's the leverage.

---

## 3. The closest reference: TeaVM

TeaVM (Konsulthuset/Konsoletta — open source since ~2014, active) does exactly Java-bytecode-to-JS AOT. Its architecture is the textbook for the AOT path here.

Pipeline:
```
.class file
  → ClassReader (parses constant pool, methods, attributes)
  → IR (high-level: variables, basic blocks, expressions — NOT operand stack)
  → Decompiler pass: bytecode → expression trees
    (this is the load-bearing transformation — the operand stack disappears)
  → Optimisation passes (constant folding, dead code, inlining)
  → JS emitter: one JS function per Java method
  → Reachability-driven dead-code elimination across the whole program
```

Key design decisions worth borrowing:

- **High-level IR with named variables, not operand-stack.** Decompile `iadd; istore_3` into `let var3 = pop2 + pop1`. The stack dissolves at IR level. PHP target benefits identically — emit `$var3 = $a + $b;` not a synthetic `$stack[$sp++]`.
- **Whole-program reachability.** Don't translate every class — only what's reachable from the entry point. Output is small. (For PHP: emit only used methods to keep generated PHP files manageable.)
- **Standard library is curated, not auto-translated.** TeaVM has a hand-written JS implementation of `java.util.HashMap`, `String`, etc. — it doesn't translate the OpenJDK source. Same call: PHPJava already has the curated `Packages/java/...` shim layer; reuse it. AOT-emitted user code calls into PHP shims by name, exactly like the interpreter does.
- **Pluggable native methods.** Each native method (or hand-written intrinsic) can be supplied as JS. PHP equivalent: each stub'd `java.lang.String` method has a PHP function backing it — emit AOT'd code that calls those functions directly.

What TeaVM doesn't solve for us:

- **invokedynamic with arbitrary bootstrap methods.** TeaVM handles lambda metafactory specifically (synthesises a JS callable). Same shortcut works for PHP — emit a PHP closure for each lambda site.
- **Class loading at runtime.** TeaVM is fully closed-world AOT — you can't `Class.forName("Foo")` on a class you didn't compile. PHP has the same constraint *unless* you keep PHPJava's interpreter as a fallback for dynamically-loaded classes.

---

## 4. The other useful reference: DoppioJVM

DoppioJVM (`doppiojvm.org`, ~2014) is **JVM bytecode interpreter in TypeScript/JavaScript** — the browser analogue of PHPJava. Same architectural shape. Same perf characteristics.

What DoppioJVM teaches us:

- They published per-op perf numbers. Their interpreter ran ~10–50× slower than V8's JIT-compiled JS. PHPJava is at ~10,000× HotSpot interp; DoppioJVM was at ~1,000× V8 native. **PHPJava's architecture costs are an order worse than DoppioJVM's** — confirming the architectural-cost diagnosis (most of our 10,000× isn't PHP being slow, it's PHPJava's encrustation).
- They had a bytecode→JS AOT path proposed but never finished. Reason cited (in their 2015 papers): runtime class loading + reflection were too coupled to JIT-style code generation. Same constraint as TeaVM.
- Their interpreter was rewritten three times, each time getting ~3–5× faster, before plateauing at the architectural floor for a JS interpreter. **Same shape as our spike result** — switch dispatch beats class-per-opcode by ~200× because of accumulated dispatch cost.

---

## 5. The dead reference: Quercus

Quercus (Caucho Technology, ~2003–2020) was **PHP source on JVM** — the inverse direction. Built a full PHP interpreter in Java. Ran WordPress in production. Discontinued when Caucho's Resin server died.

Quercus is interesting as a *failure mode* reference:
- It was correctness-complete and fast (on JVM, with HotSpot underneath, it ran PHP at ~50–80% of native PHP-FPM speed for many workloads).
- It died because the audience was tiny: organisations that wanted to run PHP code but were forced onto JVM infrastructure. As containerisation made polyglot deployment trivial, the value proposition collapsed.

**The lesson for us:** the "run X on Y" projects survive on the unique deployment story. Quercus had nothing unique once Docker existed. PHPJava's deployment story is the inverse and *is* unique — "JVM code on shared PHP hosting where you can't install anything else." That's the ambition that justifies the work, and it's what Quercus didn't have.

---

## 6. The neighbour: cljp

cljp's architecture from this team's repo:

```
.cljp / .cljc source
  → reader → form → expander → expanded form
  → IR (:ps/* structural opcodes, :pl/* leaf opcodes)
  → emitter → PHP source string
  → write to disk
```

PHP-side runtime: `src/clj/cljp/core.cljp` — ~8.4kloc of Clojure that compiles to PHP, providing PersistentVector, PersistentHashMap, Atom, Var, etc. as PHP classes.

If we build a `.class → PHP` AOT compiler — **revised after measurement
2026-05-01.** The earlier draft of this section claimed cljp's IR was a
suitable target. That was wrong:

```
.class file
  → ClassReader (PHPJava already has it — `Core/JVM/`)
  → walk parsed bytecode (no separate IR for naive AOT)
  → emit PHP source per opcode
  → write to disk / opcache
```

For naive AOT, **JVM bytecode is the IR.** PHPJava already parses it.
A one-pass walker emits PHP per opcode. Spike measured this at 5.6 ns/op.

For idiomatic AOT (0.4 ns/op spike), a **TeaVM-shape IR** is needed
(SSA + CFG + decompilation pass). cljp's IR doesn't fit either need:

- cljp IR is shaped for Clojure semantics (multi-arity, protocols,
  persistent collections); JVM bytecode has Java semantics (narrow
  numerics, exception tables, monitors, object init).
- cljp IR is at the wrong abstraction level — it's macroexpanded
  Lisp forms. Naive AOT needs bytecode-level shape; idiomatic AOT
  needs SSA-level shape. Neither matches.

**The runtime is NOT shared with cljp.** Java values map to natural
PHP values (int → int, String → string, HashMap → idiomatic PHP class
with ArrayAccess/Countable). cljp's `$GLOBALS`-closures + persistent
collections + tagged strings serve Clojure semantics; forcing Java
through them would distort Java for the dominant user (P1 in
`docs/MODEL.md`). cljp and PHPJava are peers on Zend, not nested.

What IS reusable from cljp:
- Patterns (the closures-in-array dispatch from `mesh/sig.php`,
  the opcache-cacheable PHP emission shape, the parity test harness)
- The classloader cache shape (similar to cljp's `_loaded` map)
- The deployment philosophy (pure PHP files, `opcache`-cacheable)
- DX harness (parity tests, contract gates)

Not reusable: the value representation, the `$GLOBALS` runtime
namespace, the IR.

---

## 7. The neighbour we haven't built: SCI on PHP

Earlier rounds discussed this. SCI is bb's source interpreter. ~13kloc of Clojure. If ported to PHP (or compiled via cljp from the original source), provides:

- Runtime `eval` of Clojure source on PHP-only infrastructure
- Shared `java.*` shim layer with the AOT path
- Dynamic library loading without an AOT step

The SCI-on-PHP path doesn't compete with the AOT path; it complements it. AOT is for hot code; SCI is for ad-hoc scripting / REPLs / dynamic plugins.

---

## 8. The strategic picture (revised 2026-05-01)

The earlier draft of this section depicted a "shared runtime" across cljp
and PHPJava. That framing was wrong — Java and Clojure values have
different shapes; forcing both into one runtime distorts at least one.
The corrected picture is **two peers on Zend**:

```
.cljp source                                      .class files
     │                                                  │
     │  cljp compiler                                   │  PHPJava
     │  (Clojure→PHP — Clojure semantics)               │  (Java bytecode→PHP — JVM semantics)
     │                                                  │
     ▼                                                  ▼
  ┌──────────────────────────────────────────────────────┐
  │                      Zend Engine                     │
  │     opcache caches, JIT optimises, refcount GC,      │
  │           x86 execution underneath                   │
  └──────────────────────────────────────────────────────┘
                              │
                              ▼
                            CPU
```

cljp and PHPJava sit at the same layer above Zend, neither hosting the
other. Each maintains its own value representation:

- **cljp** emits PHP that uses persistent collections, tagged strings,
  `$GLOBALS` closures — Clojure semantics preserved.
- **PHPJava** emits PHP that uses idiomatic native types — Java
  semantics preserved.

When code crosses the language boundary (Clojure code calling Java
code, or vice versa), values are **marshalled at the boundary** by a
thin `cljp/java/bridge` adapter. Cost is paid only at the crossing,
not throughout each runtime.

Inside PHPJava itself, the `InvokerInterface` boundary already supports
multiple implementations. The classloader picks per-class:

```
                ┌─────────────────────────────────┐
                │  PHPJava JVM contract           │
                │  (ClassLoader + InvokerIF)      │
                └─────────┬───────────────────────┘
                          │
       ┌──────────────────┼──────────────────┐
       │                  │                  │
       ▼                  ▼                  ▼
  ┌───────────┐    ┌───────────┐     ┌─────────────┐
  │ Interp    │    │ AOT'd     │     │ Curated PHP │
  │ (cold or  │    │ PHP       │     │ shim        │
  │ runtime-  │    │ closures  │     │ (Packages/  │
  │ generated │    │ (eager OR │     │  java/*)    │
  │ classes)  │    │ lazy)     │     │             │
  └───────────┘    └───────────┘     └─────────────┘
```

These three are not separate projects — they're cache-strategy variants
of one compiler (per `docs/MODEL.md`). The classloader chooses based on
what's available for a given class.

---

## 9. What's worth borrowing from each adjacent project

| From | What |
|---|---|
| **TeaVM** | High-level IR design (variables not stack); whole-program reachability; pluggable native methods; lambda metafactory shortcut |
| **CheerpJ** | Standard library curation strategy (when to translate vs hand-write) |
| **DoppioJVM** | Per-op perf measurement methodology; their three-iteration interpreter rewrite history |
| **Babashka** | Curated `java.*` allowlist (the 383 classes); SCI architecture if we go interpreter-on-PHP |
| **Quercus** | Negative — what kills these projects (no unique deployment story); we're avoiding their failure mode |
| **GraalVM native-image** | Closed-world AOT semantics; reachability analysis |
| **GraalVM Crema** | The escape hatch for runtime class loading on closed-world AOT |
| **Doppio papers** | Why they didn't ship AOT — gives us the failure modes to avoid |
| **PHPJava (existing)** | Class file parser, constant pool, attribute reader, curated `Packages/java/*` shims |
| **cljp** | Reader, IR, emitter, runtime — direct reuse |

---

## 10. Concrete decisions this analysis surfaces (revised 2026-05-01)

1. **AOT is a cache-mode of the same compiler, not a separate project.**
   The interpreter and AOT share opcode-handler structure; each is a
   different consumer of the same parsed bytecode. See `docs/MODEL.md`
   for the unified model.
2. **JVM bytecode is the IR for naive AOT.** No separate IR layer
   needed. Idiomatic AOT (later) adds a TeaVM-shape SSA+CFG pass.
3. **cljp and PHPJava are peers on Zend, not one hosting the other.**
   Independent runtimes. Marshalling at language boundary when needed.
4. **Long-running deployments (Swoole / AMPHP / RoadRunner /
   FrankenPHP) are the primary target.** Lazy AOT is the natural
   default in those processes; eager AOT supports request-scoped FPM.
5. **The interpreter remains** as the cold-method / runtime-generated-
   bytecode path. Phase 2 rewrite still earns its keep — 200× speedup
   makes it usable as the fallback.
6. **The deployment story is the moat.** Pure-PHP-files-runs-on-any-PHP
   is what makes this worth doing. Anything that requires a JVM
   coprocess or a custom PHP build defeats the point. (Optional
   accelerators — Swoole, FFI, opcache JIT — degrade gracefully.)
7. **Capability work and optimisation work are different tracks.**
   Optimisation = subtraction (remove layers between bytecode and
   Zend). Capability = addition (implement more of the JVM contract).
   Both proceed in parallel, with different success criteria.

This frames Phase 5+ of the roadmap differently. Runtime-generated
classes target the **interpreter path** first (`defineClass(byte[])`
output cannot be eagerly AOT'd before it exists). Then a per-process
lazy AOT cache catches synthesised classes on second call onward.
Pre-compiled `.class` files in Java libraries AOT eagerly at build time.

The clean form: **eager AOT for everything we can predict; lazy AOT for
everything the runtime produces; interpreter for cold and one-shot.**
All three are the same compiler with different cache TTLs.

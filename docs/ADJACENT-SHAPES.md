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

If we build a `.class → PHP` AOT compiler:

```
.class file
  → ClassReader (PHPJava's Compiler/Builder/ has parts of this — reusable)
  → IR (could be cljp's IR — `:ps/*` and `:pl/*` are JVM-shaped)
  → Java→Clojure-IR lowerer (the new piece — ~5–10kloc of work)
  → cljp's emitter → PHP source string
  → write to disk
```

**The runtime is shared.** Java's `int` becomes PHP `int`; Java's `String` becomes whatever PHP representation cljp uses for Clojure strings; Java's collections become Clojure persistent collections (with the surface methods that Java code expects).

This is a real architectural alignment — not a metaphor. cljp.core's representations were chosen with JVM Clojure parity in mind (per `docs/CLJP-DECISIONS.md`); they're already Java-shaped.

---

## 7. The neighbour we haven't built: SCI on PHP

Earlier rounds discussed this. SCI is bb's source interpreter. ~13kloc of Clojure. If ported to PHP (or compiled via cljp from the original source), provides:

- Runtime `eval` of Clojure source on PHP-only infrastructure
- Shared `java.*` shim layer with the AOT path
- Dynamic library loading without an AOT step

The SCI-on-PHP path doesn't compete with the AOT path; it complements it. AOT is for hot code; SCI is for ad-hoc scripting / REPLs / dynamic plugins.

---

## 8. The strategic picture

Putting it all together, the shape that emerges:

```
                      ┌───────────────────────────────────┐
                      │                                   │
                      │     SHARED PHP RUNTIME            │
                      │     (from cljp.core)              │
                      │                                   │
                      │  + java.* curated shims           │
                      │    (from PHPJava Packages/)       │
                      │                                   │
                      └─────────▲─────────▲──────────▲────┘
                                │         │          │
                       emits    │  emits  │  shims   │
                                │         │          │
                  ┌─────────────┴─┐ ┌─────┴──────┐ ┌─┴─────────────┐
                  │  cljp         │ │  Java AOT  │ │  PHPJava       │
                  │  Clojure→PHP  │ │ .class→PHP │ │  interpreter   │
                  │  (compile)    │ │  (compile) │ │  (fallback)    │
                  └───────▲───────┘ └─────▲──────┘ └────────▲───────┘
                          │               │                 │
                       .cljp /          .class             .class
                       .cljc           (modern              (legacy /
                       source           Java/Kotlin)          dynamic-load)
                                          ↑
                       SCI-on-PHP ────────┘
                       (interp Clojure source dynamically)
```

Four front ends, one shared runtime. Each front end fills a different deployment niche:

- **cljp**: write Clojure code, compile ahead of time, deploy `.php` files.
- **Java AOT**: compile existing Java/Kotlin libraries ahead of time, deploy `.php` files.
- **PHPJava interpreter**: load `.class` files at runtime (from disk, network, dynamically generated) — slow but flexible.
- **SCI-on-PHP**: evaluate Clojure source at runtime — for plugin systems, REPL access, ad-hoc scripting.

The PHPJava roadmap currently focuses on making the interpreter usable. The strategic upgrade is to **build the AOT compiler as the primary path** and keep the interpreter for the dynamic-load case only.

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

## 10. Concrete decisions this analysis surfaces

1. **AOT is the primary path, not a stretch goal.** TeaVM has proven the architecture; cljp has built the runtime; PHPJava has built the parser. The compiler middle is ~5–10kloc of new work.
2. **Don't reinvent IR.** Use cljp's `:ps/*` / `:pl/*` two-tier as the AOT target. The lowerer (`.class` → Clojure IR) becomes the new piece; everything downstream is shared.
3. **Keep the interpreter** for dynamic-load fallback (`Class.forName`, runtime class generation). Mark it as "slow path" explicitly. The Phase 2 switch-dispatch rewrite is still worth doing because it's bounded work that gives a 200× speedup *for the cases that need the interpreter at all*.
4. **SCI-on-PHP** is third priority — fills a real niche but isn't on the critical path for "Clojure / Java libs on shared PHP hosting."
5. **The deployment story is the moat.** Quercus's lesson: pure-PHP-files-runs-anywhere-PHP-runs is what makes this worth doing. Anything that requires a non-standard PHP build, a Zend extension, a coprocess, or a JVM coprocess defeats the point.

This frames Phase 5+ of the roadmap differently. The Clojure-boot probe should target the AOT path specifically, not the interpreter path. Boot Clojure means: AOT-compile the slim jar's `.class` files to `.php` files once, deploy them, run.

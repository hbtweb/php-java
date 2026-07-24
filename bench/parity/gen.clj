(ns gen
  "Path D′ parity — case-battery generator.

   ROADMAP §Next-work plan, Phase 0a. Reflects a real JDK class and
   emits a case battery in the format `oracle-driver` consumes, drawing
   arguments from a per-type corpus of edge values rather than from
   whoever is writing the shim.

   Why this exists: batteries were hand-authored, so the author of a
   shim was also the author of its test — wrong in the same direction
   twice. Deriving the battery from the JDK by reflection breaks that
   coupling, which is what makes the 67-class fill fannable.

   Run from the repo root:
     clj -Sdeps '{:paths [\"bench/parity\"] :deps {org.clojure/data.json {:mvn/version \"2.5.0\"}}}' \\
         -M -m gen --class java.lang.Integer

   Emits <out-dir>/<FQN>.gen.json (seen) and <FQN>.holdout.json.

   The holdout half is the point: an implementer works against `seen`
   only, and `holdout` — same distribution, disjoint draw — is run at
   verify time. Without it, a shim that special-cases the visible
   inputs shows green.

   Scope: static methods. Instance-mode generation (constructor +
   op-chain, the `{new, ops}` shape the driver already supports) is not
   implemented; classes needing it are reported, not silently skipped."
  (:require [clojure.data.json :as json]
            [clojure.java.io :as io]
            [clojure.string :as str])
  (:import [java.lang.reflect Method Modifier])
  (:gen-class))

;; ─── argument corpus ─────────────────────────────────────────────────────────
;;
;; Ordered edge-first: the earliest entries are the values most likely to
;; expose a divergence, because the cartesian walk takes its head in order.
;; Values are JSON-shaped, including the tagged forms `decode-arg` /
;; decodeArg() understand ({"__nan__" true}, {"__inf__" ±1}).

(def ^:private corpus
  {Boolean/TYPE   [true false]

   Byte/TYPE      [0 1 -1 127 -128 63 -64]
   Short/TYPE     [0 1 -1 32767 -32768 255 -256]
   Integer/TYPE   [0 1 -1 2147483647 -2147483648 2147483646 -2147483647
                   2 7 10 16 255 65535 65536 -65536 1000000]
   Long/TYPE      [0 1 -1 9223372036854775807 -9223372036854775808
                   9223372036854775806 -9223372036854775807
                   2147483647 -2147483648 4294967296 2 10 255]

   ;; Java char is the full UTF-16 code-unit range. Surrogate halves are
   ;; in-corpus deliberately: they are where byte-oriented PHP string
   ;; handling diverges from UTF-16 semantics.
   Character/TYPE [0 32 48 57 65 90 97 122 127 128 233 0x2028
                   0xD800 0xDBFF 0xDC00 0xDFFF 0xFFFF]

   Float/TYPE     [0.0 -0.0 1.0 -1.0 0.5 -0.5 2.5 -2.5 3.5
                   {"__nan__" true} {"__inf__" 1} {"__inf__" -1}]
   Double/TYPE    [0.0 -0.0 1.0 -1.0 0.5 -0.5 2.5 -2.5 3.5
                   3.141592653589793 1.0E308 -1.0E308 4.9E-324
                   {"__nan__" true} {"__inf__" 1} {"__inf__" -1}]

   String         ["" "a" "abc" " " "  x  " "0" "1" "-1" "true" "TRUE"
                   "0.5" "2147483648" "9223372036854775808"
                   "é" "日本語" "𝔘" "a\nb" "\t" nil]

   Object         [nil "x" 0 1 true 0.5]})

(def ^:private array-tags
  "Parameter array class → the `__arr__` element tag the wire format uses."
  {"[I" "int" "[J" "long" "[D" "double" "[F" "float"
   "[S" "short" "[B" "byte" "[C" "char" "[Z" "boolean"
   "[Ljava.lang.String;" "String" "[Ljava.lang.Object;" "Object"})

(def ^:private array-element-types
  {"int" Integer/TYPE "long" Long/TYPE "double" Double/TYPE
   "float" Float/TYPE "short" Short/TYPE "byte" Byte/TYPE
   "char" Character/TYPE "boolean" Boolean/TYPE
   "String" String "Object" Object})

(defn- array-corpus
  "Array-valued arguments for a `__arr__` tag: empty, singleton over each
   of the first few element edge values, and a small multi-element array.
   Kept short — array arity multiplies fast against other parameters."
  [tag]
  (let [elems (vec (take 4 (remove nil? (get corpus (array-element-types tag)))))]
    (vec (concat
          [{"__arr__" tag "v" []}]
          (map (fn [e] {"__arr__" tag "v" [e]}) elems)
          [{"__arr__" tag "v" (vec (take 3 elems))}
           {"__arr__" tag "v" (vec (reverse (take 3 elems)))}]))))

(defn- param-corpus
  "Candidate values for one parameter type, or nil if the type isn't
   expressible on the wire (the method then gets skipped, with a reason)."
  [^Class p]
  (let [n (.getName p)]
    (cond
      (contains? corpus p)     (get corpus p)
      (contains? array-tags n) (array-corpus (get array-tags n))
      (= p CharSequence)       (get corpus String)
      (.isAssignableFrom p String) (get corpus String)   ; Comparable, Serializable, …
      (= p Object)             (get corpus Object)
      :else                    nil)))

;; ─── case construction ───────────────────────────────────────────────────────

(defn- cartesian-count [colls] (reduce * 1 (map count colls)))

(defn- cartesian
  "Lazy cartesian product, first-varying-slowest so the edge-first
   ordering of each corpus carries into the head of the product."
  [colls]
  (if (empty? colls)
    [[]]
    (for [x (first colls), more (cartesian (rest colls))]
      (cons x more))))

(defn- random-tuple [^java.util.Random rng colls]
  (mapv (fn [c] (nth c (.nextInt rng (count c)))) colls))

(defn- sample-tuples
  "Up to `cap` argument tuples for one method.

   Small products are taken whole. Large ones take an edge-first head
   (half the cap) plus a seeded-random tail, so the obvious corners are
   always present and the rest of the space is still probed. Deterministic
   given the seed."
  [colls cap ^java.util.Random rng]
  (let [total (cartesian-count colls)]
    (if (<= total cap)
      (mapv vec (cartesian colls))
      (let [head (mapv vec (take (quot cap 2) (cartesian colls)))
            seen (set head)]
        (loop [acc head, seen seen, guard 0]
          (if (or (>= (count acc) cap) (> guard (* 20 cap)))
            acc
            (let [t (random-tuple rng colls)]
              (if (contains? seen t)
                (recur acc seen (inc guard))
                (recur (conj acc t) (conj seen t) (inc guard))))))))))

(def ^:private nondeterministic
  "Methods whose return can't be compared across two runtimes. Skipped
   with a reason rather than emitted as guaranteed-red cases."
  #{"random" "currentTimeMillis" "nanoTime" "gc" "exit" "identityHashCode"
    "hashCode" "arraycopy" "getProperty" "getenv" "lineSeparator"
    "notify" "notifyAll" "wait" "getClass" "clone" "finalize"})

(defn- candidate-methods
  "Public static methods declared on the class itself, minus the
   non-deterministic set and synthetic/bridge artefacts."
  [^Class cls]
  (->> (.getMethods cls)
       (filter (fn [^Method m]
                 (and (Modifier/isStatic (.getModifiers m))
                      (Modifier/isPublic (.getModifiers m))
                      (= (.getDeclaringClass m) cls)
                      (not (.isSynthetic m))
                      (not (.isBridge m))
                      (not (contains? nondeterministic (.getName m))))))
       (sort-by (juxt #(.getName ^Method %) #(.getParameterCount ^Method %)))))

(defn- gen-method
  "{:cases [...]} or {:skip {...}} for one method."
  [^Method m cap ^java.util.Random rng]
  (let [params  (vec (.getParameterTypes m))
        corpora (mapv param-corpus params)]
    (if (some nil? corpora)
      {:skip {"method" (.getName m)
              "reason" (str "parameter type not expressible on the wire: "
                            (str/join ", " (map #(.getName ^Class %)
                                                (keep-indexed (fn [i p] (when (nil? (nth corpora i)) p))
                                                              params))))}}
      {:cases (mapv (fn [args] {"method" (.getName m) "args" args})
                    (sample-tuples corpora cap rng))})))

(defn generate
  "Generate {:seen [...] :holdout [...] :skipped [...] :methods n} for a class."
  [^String fqn {:keys [cap seed holdout-cap]
                :or   {cap 24 seed 20260724 holdout-cap 12}}]
  (let [cls     (Class/forName fqn)
        methods (candidate-methods cls)
        rng-a   (java.util.Random. seed)
        rng-b   (java.util.Random. (+ seed 977))
        results (mapv #(gen-method % cap rng-a) methods)
        seen    (vec (mapcat :cases results))
        seen-set (set seen)
        ;; Holdout draws from the same corpora with a different seed and
        ;; is filtered against `seen` — same distribution, disjoint draw.
        hold    (->> methods
                     (mapv #(gen-method % holdout-cap rng-b))
                     (mapcat :cases)
                     (remove seen-set)
                     vec)]
    {:seen    seen
     :holdout hold
     :skipped (vec (keep :skip results))
     :methods (count methods)}))

;; ─── emit ────────────────────────────────────────────────────────────────────

(defn- write-battery! [path fqn cases meta-map]
  (io/make-parents path)
  (spit path (str (json/write-str {"class"      fqn
                                   "_generated" meta-map
                                   "cases"      cases}
                                  :indent true)
                  "\n"))
  path)

(defn -main [& argv]
  (let [opts (loop [a argv, o {:out "bench/parity/cases" :cap 24 :seed 20260724}]
               (cond
                 (empty? a) o
                 (= "--class" (first a)) (recur (drop 2 a) (assoc o :class (second a)))
                 (= "--out"   (first a)) (recur (drop 2 a) (assoc o :out (second a)))
                 (= "--cap"   (first a)) (recur (drop 2 a) (assoc o :cap (parse-long (second a))))
                 (= "--seed"  (first a)) (recur (drop 2 a) (assoc o :seed (parse-long (second a))))
                 :else (do (binding [*out* *err*] (println "unknown arg:" (first a)))
                           (System/exit 2))))
        fqn  (:class opts)]
    (when-not fqn
      (binding [*out* *err*]
        (println "Usage: -m gen --class <FQN> [--out DIR] [--cap N] [--seed N]"))
      (System/exit 2))
    (let [{:keys [seen holdout skipped methods]} (generate fqn opts)
          base (str (:out opts) "/" fqn)
          meta-base {"by"      "bench/parity/gen.clj"
                     "seed"    (:seed opts)
                     "cap"     (:cap opts)
                     "jdk"     (System/getProperty "java.version")
                     "methods" methods
                     "mode"    "static-only"}]
      (write-battery! (str base ".gen.json") fqn seen
                      (assoc meta-base "split" "seen" "skipped" skipped))
      (write-battery! (str base ".holdout.json") fqn holdout
                      (assoc meta-base "split" "holdout"))
      (println (format "%s — %d static methods, %d seen cases, %d holdout cases, %d methods skipped"
                       fqn methods (count seen) (count holdout) (count skipped)))
      (doseq [s skipped]
        (println (format "  SKIP %-24s %s" (get s "method") (get s "reason"))))
      (println (format "  wrote %s.gen.json + %s.holdout.json" base base)))))

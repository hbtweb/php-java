(ns oracle-driver
  "Path D′ behavioural-parity oracle — Clojure-side driver.

   ROADMAP §Build #1 — the v1-critical-path prerequisite for
   bb-allowlist non-stub fill. Loads a case-battery JSON, runs each
   case against (1) the real JDK via reflection and (2) the PHPJava
   AOT shim via subprocess into oracle-runner.php, compares the
   normalised JSON shapes per the contract in bench/parity/README.md,
   and emits a per-class parity report.

   MVP transport: clojure.java.shell to invoke the PHP runner. The
   FFM transport sketched in bench/baseline.clj is a perf optimisation
   for the long-tail-fill phase (~12k cases × 100ms subprocess startup
   = ~20min wall, vs ms with FFM). Subprocess is correctness-first;
   FFM lands when the bench-time numbers warrant it.

   Run from the repo root:
     clj -Sdeps '{:paths [\"bench/parity\"]}' \\
         -M -m oracle-driver \\
         --case bench/parity/cases/java.lang.Math.json

   Exit code: 0 if all cases match, 1 if any diverged."
  (:require [clojure.data.json :as json]
            [clojure.java.io :as io]
            [clojure.java.shell :as shell]
            [clojure.string :as str])
  (:import [java.io ByteArrayOutputStream PrintStream]
           [java.lang.reflect InvocationTargetException Method])
  (:gen-class))

;; ─── invocation: the real JDK ────────────────────────────────────────────────

(def ^:private object-array-class (class (object-array [])))

(defn- arg-class
  "The runtime Class of a decoded JSON arg, for overload resolution.
   JSON arrays decode as Clojure vectors and dispatch to Object[] —
   matches Java vararg `Object...` declarations after array bundling."
  [v]
  (cond
    (nil? v)              Object        ; null → Object so resolution treats arg as compatible with any reference param
    (vector? v)           object-array-class
    (instance? Boolean v) Boolean
    (instance? Long v)    Long
    (instance? Integer v) Integer
    (instance? Double v)  Double
    (instance? Float v)   Float
    (string? v)           String
    :else                 Object))

(defn- box-arg
  "Convert a Clojure-decoded JSON value to the Java reference its
   reflection invoke needs. Vectors → Object[]; recursive so nested
   arrays box. Scalars pass through (Long, Double, Boolean, String,
   nil are already Java references)."
  [v]
  (if (vector? v)
    (into-array Object (map box-arg v))
    v))

(defn- coerce-arg
  "Narrow an arg to match a primitive parameter type. Reflection
   requires exact wrapper-to-primitive correspondence; case specs
   pass JSON ints (decode as Long) but bb-allowlist methods often
   take int. Manual conversion at the wrapper level — the Java
   reflection layer then unboxes."
  [^Class param arg]
  (cond
    (nil? arg) arg
    (and (= param Integer/TYPE) (instance? Long arg))    (Integer/valueOf (int (long arg)))
    (and (= param Short/TYPE)   (instance? Long arg))    (Short/valueOf (short (long arg)))
    (and (= param Short/TYPE)   (instance? Integer arg)) (Short/valueOf (short (int arg)))
    (and (= param Byte/TYPE)    (instance? Long arg))    (Byte/valueOf (byte (long arg)))
    (and (= param Character/TYPE) (instance? Long arg))  (Character/valueOf (char (long arg)))
    (and (= param Character/TYPE) (instance? Integer arg)) (Character/valueOf (char (int arg)))
    (and (= param Float/TYPE)   (instance? Double arg))  (Float/valueOf (float (double arg)))
    :else (box-arg arg)))

(defn- param-accepts?
  "Whether a parameter Class<?> can accept an arg whose runtime class
   is `arg-cls`. Models JVM overload-resolution: identity, subtype,
   primitive ↔ wrapper unboxing, primitive widening (byte→short→
   int→long→float→double; char→int→...). Skip narrowing; reflection
   would reject the .invoke."
  [^Class param ^Class arg-cls]
  (or
    (.isAssignableFrom param arg-cls)
    ;; null fits any reference param
    (and (= arg-cls Object) (not (.isPrimitive param)))
    ;; unboxing wrapper → primitive
    (and (= param Boolean/TYPE)   (= arg-cls Boolean))
    (and (= param Byte/TYPE)      (= arg-cls Byte))
    (and (= param Short/TYPE)     (= arg-cls Short))
    (and (= param Integer/TYPE)   (= arg-cls Integer))
    (and (= param Long/TYPE)      (= arg-cls Long))
    (and (= param Float/TYPE)     (= arg-cls Float))
    (and (= param Double/TYPE)    (= arg-cls Double))
    ;; primitive widening — wrapper-arg widens to a wider primitive
    (and (= param Short/TYPE)     (#{Byte} arg-cls))
    (and (= param Integer/TYPE)   (#{Byte Short} arg-cls))
    (and (= param Long/TYPE)      (#{Byte Short Integer} arg-cls))
    (and (= param Float/TYPE)     (#{Byte Short Integer Long} arg-cls))
    (and (= param Double/TYPE)    (#{Byte Short Integer Long Float} arg-cls))
    ;; primitive narrowing — case spec uses JSON ints (decode as Long)
    ;; but bb-allowlist methods often take int. Allow Long → int when
    ;; the value's actual range fits; coerce-args performs the
    ;; truncation. Mirrors Java's auto-boxing-with-explicit-cast at
    ;; call sites where the JSON spec is the source of truth on
    ;; intended type.
    (and (= param Integer/TYPE)   (= arg-cls Long))
    (and (= param Short/TYPE)     (#{Long Integer} arg-cls))
    (and (= param Byte/TYPE)      (#{Long Integer Short} arg-cls))
    (and (= param Character/TYPE) (#{Long Integer Short} arg-cls))
    (and (= param Float/TYPE)     (= arg-cls Double))))

(defn- specificity
  "Lower score = more specific match. Used to disambiguate when the
   same name + arity has multiple compatible overloads. Exact wrapper
   ↔ primitive match wins over widening / narrowing; among narrowing
   choices for a Long arg the canonical integer hierarchy is preferred
   (int < short < byte < char) so e.g. Character.toLowerCase(int) wins
   over Character.toLowerCase(char) when the case spec passes a JSON
   int (Long)."
  [^java.lang.reflect.Method m arg-classes]
  (->> (map (fn [^Class p ^Class a]
              (cond
                (= p a)                                                0  ; identity (e.g. String → String)
                (or (and (= p Long/TYPE)    (= a Long))
                    (and (= p Integer/TYPE) (= a Integer))
                    (and (= p Double/TYPE)  (= a Double))
                    (and (= p Float/TYPE)   (= a Float))
                    (and (= p Boolean/TYPE) (= a Boolean))
                    (and (= p Byte/TYPE)    (= a Byte))
                    (and (= p Short/TYPE)   (= a Short)))               1  ; exact unbox
                ;; Narrow from Long — prefer int > short > byte > char.
                (and (= p Integer/TYPE)   (= a Long))                   2
                (and (= p Short/TYPE)     (= a Long))                   3
                (and (= p Byte/TYPE)      (= a Long))                   4
                (and (= p Character/TYPE) (= a Long))                   5
                (and (= p Float/TYPE)     (= a Double))                 2
                (= p Object)                                           50  ; least specific reference
                (.isPrimitive p)                                       10  ; primitive widening
                :else                                                   5)) ; subtype
            (.getParameterTypes m) arg-classes)
       (apply +)))

(defn- find-method
  "Scan all public methods of `cls` for one matching `method-name`
   and arity, where every parameter type accepts the corresponding
   arg-class. Pick the most specific. Throws NoSuchMethodException
   if none match."
  [^Class cls ^String method-name args]
  (let [arg-classes (mapv arg-class args)
        candidates  (->> (.getMethods cls)
                         (filter (fn [^java.lang.reflect.Method m]
                                   (and (= (.getName m) method-name)
                                        (= (.getParameterCount m) (count args))
                                        (every? identity
                                                (map param-accepts?
                                                     (.getParameterTypes m)
                                                     arg-classes))))))]
    (when (empty? candidates)
      (throw (NoSuchMethodException.
              (str (.getName cls) "." method-name "(" (str/join "," (map #(.getName %) arg-classes)) ")"))))
    (first (sort-by #(specificity % arg-classes) candidates))))

(defn- normalise-return
  "Project a JDK return value into the JSON-stable shape the comparator
   can deep-eq against the PHP side. Mirrors oracle-runner.php's
   normaliseReturn — kept in lockstep so JSON in/out matches both ways."
  [v]
  (cond
    (nil? v)              nil
    (instance? Boolean v) v
    (or (instance? Long v) (instance? Integer v)
        (instance? Short v) (instance? Byte v))
    (long v)
    (instance? Character v)
    (long (.charValue ^Character v))
    (or (instance? Double v) (instance? Float v))
    (let [d (double v)]
      (cond
        (Double/isNaN d)                {"__nan__" true}
        (= d Double/POSITIVE_INFINITY)  {"__inf__" 1}
        (= d Double/NEGATIVE_INFINITY)  {"__inf__" -1}
        :else                           d))
    (string? v) v
    ;; Java arrays (any element type — Object[], int[], long[], etc.)
    ;; get reified to vectors via reflective Array/get to avoid the
    ;; `[L...@hash` toString output. Element-wise normalise so nested
    ;; arrays of primitives lift cleanly.
    (and (some? v) (.isArray ^Class (class v)))
    (let [n (java.lang.reflect.Array/getLength v)]
      (mapv #(normalise-return (java.lang.reflect.Array/get v %)) (range n)))
    :else (str v)))

(defn- find-constructor
  "Resolve a constructor on `cls` accepting the given args. Same
   matching rules as find-method but over getConstructors."
  [^Class cls args]
  (let [arg-classes (mapv arg-class args)
        candidates  (->> (.getConstructors cls)
                         (filter (fn [^java.lang.reflect.Constructor c]
                                   (and (= (.getParameterCount c) (count args))
                                        (every? identity
                                                (map param-accepts?
                                                     (.getParameterTypes c)
                                                     arg-classes))))))]
    (when (empty? candidates)
      (throw (NoSuchMethodException. (str (.getName cls) ".<init>"))))
    ;; Constructors don't have getMethods-style ranking; pick the first
    ;; candidate whose param types best match. Reuse specificity by
    ;; coercing constructor to a method-like score.
    (first (sort-by
            (fn [^java.lang.reflect.Constructor c]
              (apply +
                     (map (fn [^Class p ^Class a]
                            (cond
                              (= p a) 0
                              (.isPrimitive p) 1
                              (= p Object) 50
                              :else 5))
                          (.getParameterTypes c) arg-classes)))
            candidates))))

(defn- invoke-method
  "Invoke a Method on an optional instance (nil = static). Returns the
   normalised result. Args are coerced to match the parameter types."
  [^Method m instance args]
  (let [params (.getParameterTypes m)
        boxed  (object-array (map coerce-arg params args))]
    (.invoke m instance boxed)))

(defn- invoke-jdk
  "Reflect over `class-fqn`, dispatch per the case-spec shape.
   Two modes:
     - Static (default): {:method NAME, :args [...]} → invoke static
       method, return its value.
     - Instance: {:new [ctor-args], :ops [{:call NAME, :args [...]}
       ...]} → construct instance, run each op in sequence, return
       the LAST op's return value.

   Captures System.out/System.err redirected to ByteArrayOutputStreams
   across all ops in the session. Returns a string-keyed map matching
   the parity contract."
  [class-fqn case-spec]
  (let [orig-out (System/out)
        orig-err (System/err)
        baos-out (ByteArrayOutputStream.)
        baos-err (ByteArrayOutputStream.)
        instance-mode? (contains? case-spec "new")]
    (try
      (System/setOut (PrintStream. baos-out))
      (System/setErr (PrintStream. baos-err))
      (try
        (let [cls (Class/forName class-fqn)
              result
              (if instance-mode?
                ;; Instance mode — construct then run ops chain
                (let [ctor-args (get case-spec "new" [])
                      ^java.lang.reflect.Constructor ctor (find-constructor cls ctor-args)
                      ctor-params (.getParameterTypes ctor)
                      ctor-boxed (object-array (map coerce-arg ctor-params ctor-args))
                      instance (.newInstance ctor ctor-boxed)
                      ops (get case-spec "ops" [])]
                  (loop [ops ops, last-r nil]
                    (if (empty? ops)
                      last-r
                      (let [op (first ops)
                            method-name (get op "call")
                            op-args (get op "args" [])
                            ^Method m (find-method cls method-name op-args)
                            r (invoke-method m instance op-args)]
                        (recur (rest ops) r)))))
                ;; Static mode (the original path)
                (let [method-name (get case-spec "method")
                      args (get case-spec "args" [])
                      ^Method m (find-method cls method-name args)]
                  (invoke-method m nil args)))]
          {"kind"   "ok"
           "return" (normalise-return result)
           "stdout" (.toString baos-out)
           "stderr" (.toString baos-err)})
        (catch InvocationTargetException ite
          (let [cause (.getCause ite)]
            {"kind"    "exception"
             "class"   (.getName (.getClass cause))
             "message" (.getMessage cause)})))
      (finally
        (System/setOut orig-out)
        (System/setErr orig-err)))))

;; ─── invocation: the PHPJava AOT shim ────────────────────────────────────────

(defn- invoke-php
  "Subprocess into bench/parity/oracle-runner.php with the full case
   spec (as JSON via stdin to avoid argv length limits on long
   instance-mode op chains). Parse its JSON output, return the
   `result` sub-map (string-keyed)."
  [repo-root class-fqn case-spec]
  (let [spec-json (json/write-str (assoc case-spec "class" class-fqn))
        {:keys [exit out err]}
        (shell/sh "php" "bench/parity/oracle-runner.php" "--stdin"
                  :in spec-json
                  :dir repo-root)]
    (cond
      (not (zero? exit))
      {"kind"      "harness-error"
       "exit"      exit
       "stderr"    err
       "stdout"    out}

      (str/blank? out)
      {"kind"   "harness-error"
       "stderr" "oracle-runner produced no stdout"}

      :else
      (try
        (let [parsed (json/read-str out)]
          (get parsed "result"))
        (catch Throwable t
          {"kind"   "harness-error"
           "stderr" (str "JSON parse failed: " (.getMessage t))
           "stdout" out})))))

;; ─── comparator ──────────────────────────────────────────────────────────────

(defn- numeric-eq?
  "Compare two normalised return values. Floats compared with strict
   eq because the contract preserves Double bit-equality (NaN/INF are
   wrapped). For float pairs whose representation may legitimately
   diverge (transcendentals), case spec carries an explicit `tolerance`."
  [a b tolerance]
  (cond
    (and (number? a) (number? b))
    (if tolerance
      (<= (Math/abs (- (double a) (double b))) (double tolerance))
      (= a b))
    :else
    (= a b)))

(defn- compare-results
  "Per-axis equality. Returns {:status :match} or
   {:status :diverge :axis ... :jdk ... :php ...}."
  [jdk php tolerance]
  (let [jk (get jdk "kind")
        pk (get php "kind")]
    (cond
      (= pk "harness-error")
      {:status :diverge :axis "harness" :jdk jdk :php php}

      (not= jk pk)
      {:status :diverge :axis "kind" :jdk jdk :php php}

      (= pk "exception")
      (if (not= (get jdk "class") (get php "class"))
        {:status :diverge :axis "exception.class" :jdk jdk :php php}
        {:status :match})

      :else
      (cond
        (not (numeric-eq? (get jdk "return") (get php "return") tolerance))
        {:status :diverge :axis "return"
         :jdk (get jdk "return") :php (get php "return")}

        (not= (get jdk "stdout") (get php "stdout"))
        {:status :diverge :axis "stdout"
         :jdk (get jdk "stdout") :php (get php "stdout")}

        :else {:status :match}))))

;; ─── per-case + per-class run ────────────────────────────────────────────────

(defn- run-case
  [repo-root class-fqn case-spec]
  (let [tolerance (get case-spec "tolerance")
        jdk       (invoke-jdk class-fqn case-spec)
        php       (invoke-php repo-root class-fqn case-spec)
        cmp       (compare-results jdk php tolerance)]
    (assoc cmp :case-spec case-spec :jdk jdk :php php)))

(defn- format-case-label [case-spec]
  (if (contains? case-spec "new")
    (let [ops (get case-spec "ops" [])]
      (format "new + %s" (str/join " > "
                                   (map #(format "%s(%s)"
                                                 (get % "call")
                                                 (str/join "," (map pr-str (get % "args" []))))
                                        ops))))
    (let [m (get case-spec "method")
          args (get case-spec "args" [])]
      (format "%s(%s)" m (str/join ", " (map pr-str args))))))

(defn- format-case-line [{:keys [status case-spec]}]
  (let [tag (case status :match "  PASS" :diverge "  FAIL")]
    (str tag "  " (format-case-label case-spec))))

(defn- format-divergence [{:keys [axis jdk php jdk-value php-value] :as r}]
  (str "         axis: " axis "\n"
       "         JDK : " (pr-str (or jdk-value jdk)) "\n"
       "         PHP : " (pr-str (or php-value php))))

(defn run-class
  "Drive every case in a battery file. Prints per-case + summary.
   Returns a {:total :match :diverge :results} map."
  [repo-root case-file]
  (let [spec       (json/read-str (slurp case-file))
        class-fqn  (get spec "class")
        cases      (get spec "cases")
        results    (mapv #(run-case repo-root class-fqn %) cases)
        matches    (count (filter #(= :match   (:status %)) results))
        divs       (count (filter #(= :diverge (:status %)) results))]
    (println (format "Parity: %s — %d cases" class-fqn (count cases)))
    (doseq [r results]
      (println (format-case-line r))
      (when (= :diverge (:status r))
        (println (format-divergence r))))
    (println)
    (println (format "  %d/%d match  (%d diverged)"
                     matches (count cases) divs))
    {:class    class-fqn
     :total    (count cases)
     :match    matches
     :diverge  divs
     :results  results}))

;; ─── CLI entrypoint ──────────────────────────────────────────────────────────

(defn- parse-opts [args]
  (loop [args args, opts {:repo-root (System/getProperty "user.dir")}]
    (cond
      (empty? args) opts
      (= "--case"      (first args)) (recur (drop 2 args) (assoc opts :case-file (second args)))
      (= "--repo-root" (first args)) (recur (drop 2 args) (assoc opts :repo-root (second args)))
      :else (do (binding [*out* *err*]
                  (println "unknown arg:" (first args))) (System/exit 2)))))

(defn -main [& args]
  (let [{:keys [case-file repo-root]} (parse-opts args)]
    (when-not case-file
      (binding [*out* *err*]
        (println "Usage: -m oracle-driver --case <case-file.json> [--repo-root <path>]"))
      (System/exit 2))
    (let [report (run-class repo-root case-file)]
      (System/exit (if (zero? (:diverge report)) 0 1)))))

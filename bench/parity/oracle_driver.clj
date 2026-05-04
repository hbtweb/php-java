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

(defn- arg-type
  "Map a JSON-decoded value to the Class<?> reflection should match.
   Long is the default for integral JSON because clojure.data.json
   decodes integers as Long. Double is the default for floats.
   bb-allowlist Math methods that need Integer/Float specifically
   add a `descriptor` field per case (deferred — MVP sticks to
   long/double-equivalent calls)."
  [v]
  (cond
    (instance? Boolean v) Boolean/TYPE
    (instance? Long v)    Long/TYPE
    (instance? Integer v) Integer/TYPE
    (instance? Double v)  Double/TYPE
    (instance? Float v)   Float/TYPE
    (string? v)           String
    (nil? v)              Object
    :else                 Object))

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
    (or (instance? Double v) (instance? Float v))
    (let [d (double v)]
      (cond
        (Double/isNaN d)                {"__nan__" true}
        (= d Double/POSITIVE_INFINITY)  {"__inf__" 1}
        (= d Double/NEGATIVE_INFINITY)  {"__inf__" -1}
        :else                           d))
    (string? v) v
    :else (str v)))

(defn- invoke-jdk
  "Reflect over `class-fqn`, find `method-name` matching the inferred
   arg types, invoke. Captures System.out/System.err redirected to
   ByteArrayOutputStreams. Returns a string-keyed map matching the
   parity contract:
     {:kind \"ok\"        :return X :stdout S :stderr E}
   or
     {:kind \"exception\" :class C :message M}"
  [class-fqn method-name args]
  (let [orig-out  System/out
        orig-err  System/err
        baos-out  (ByteArrayOutputStream.)
        baos-err  (ByteArrayOutputStream.)]
    (try
      (System/setOut (PrintStream. baos-out))
      (System/setErr (PrintStream. baos-err))
      (try
        (let [cls       (Class/forName class-fqn)
              arg-types (into-array Class (map arg-type args))
              ^Method m (.getMethod cls method-name arg-types)
              boxed     (object-array args)
              result    (.invoke m nil boxed)]
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
  "Subprocess into bench/parity/oracle-runner.php with the case triple,
   parse its JSON output, return the `result` sub-map (string-keyed)."
  [repo-root class-fqn method-name args]
  (let [{:keys [exit out err]}
        (shell/sh "php" "bench/parity/oracle-runner.php"
                  (str "--class="  class-fqn)
                  (str "--method=" method-name)
                  (str "--args="   (json/write-str args))
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
  (let [method    (get case-spec "method")
        args      (get case-spec "args" [])
        tolerance (get case-spec "tolerance")
        jdk       (invoke-jdk class-fqn method args)
        php       (invoke-php repo-root class-fqn method args)
        cmp       (compare-results jdk php tolerance)]
    (assoc cmp :method method :args args :jdk jdk :php php)))

(defn- format-case-line [{:keys [status method args]}]
  (let [tag (case status :match "  PASS" :diverge "  FAIL")]
    (format "%s  %s(%s)" tag method (str/join ", " (map pr-str args)))))

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

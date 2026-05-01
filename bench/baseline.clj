(ns baseline
  "PHPJava interpreter baseline benchmark.

   Uses Java 22+ Foreign Function & Memory API to call libphp8.4-embed
   directly, avoiding per-iteration process startup. PHP runtime is
   initialised once; PHPJava autoload is preloaded once; benchmarks
   then call zend_eval_string repeatedly within the same process.

   Output format:
     bench/baseline-<sha>.json — committed alongside ROADMAP.md.

   Run:
     clj -M --enable-native-access=ALL-UNNAMED -m baseline

   Or from inside this repo with classpath set explicitly:
     java --enable-native-access=ALL-UNNAMED \\
          -cp $(clj -Spath -A:cljp-bench) \\
          clojure.main -m baseline"
  (:require [clojure.data.json :as json]
            [clojure.java.shell :as shell]
            [clojure.string :as str])
  (:import [java.lang.foreign Arena Linker Linker$Option SymbolLookup
                              MemorySegment MemoryLayout ValueLayout
                              FunctionDescriptor]
           [java.lang.invoke MethodHandle]
           [java.io File])
  (:gen-class))

;; ─── FFM plumbing ────────────────────────────────────────────────────────────

(defonce ^:private linker (Linker/nativeLinker))

(defn- fdesc [ret & args]
  (FunctionDescriptor/of ret (into-array MemoryLayout args)))

(defn- handle ^MethodHandle [^SymbolLookup lookup ^String name ^FunctionDescriptor descriptor]
  (let [seg (.. lookup (find name) (orElseThrow))
        opts (into-array Linker$Option [])]
    (.downcallHandle linker seg descriptor opts)))

(defonce ^:private *php
  (delay
    (let [arena   (Arena/ofShared)
          lookup  (SymbolLookup/libraryLookup "libphp8.4.so" arena)
          init-h  (handle lookup "php_embed_init"
                          (fdesc ValueLayout/JAVA_INT
                                 ValueLayout/JAVA_INT
                                 ValueLayout/ADDRESS))
          eval-h  (handle lookup "zend_eval_string"
                          (fdesc ValueLayout/JAVA_INT
                                 ValueLayout/ADDRESS
                                 ValueLayout/ADDRESS
                                 ValueLayout/ADDRESS))
          rc      (.invokeWithArguments init-h
                    (object-array [(int 0) MemorySegment/NULL]))]
      (when-not (zero? rc)
        (throw (ex-info "php_embed_init failed" {:rc rc})))
      {:eval-h eval-h
       :stdout-file (doto (File/createTempFile "phpjava_bench_" ".out")
                      .deleteOnExit)})))

(defn eval-php
  "Evaluate `code` inside the embedded PHP runtime. Returns {:stdout :error}."
  [code]
  (let [{:keys [^MethodHandle eval-h ^File stdout-file]} @*php
        out-path (.getAbsolutePath stdout-file)
        ;; Wrap in ob_start/ob_get_clean to capture stdout via tmp file.
        wrapped  (str "ob_start();"
                      (if (str/starts-with? code "<?php")
                        (subs code 5)
                        code)
                      "\nfile_put_contents('" out-path "', ob_get_clean());")]
    (spit stdout-file "")
    (with-open [arena (Arena/ofConfined)]
      (let [c-str  (.allocateFrom arena ^String wrapped)
            c-name (.allocateFrom arena "bench")
            rc     (.invokeWithArguments eval-h
                     (object-array [c-str MemorySegment/NULL c-name]))]
        (if (zero? rc)
          {:stdout (slurp stdout-file)}
          {:error  (str "zend_eval_string failed (rc=" rc ")")
           :stdout (slurp stdout-file)})))))

;; ─── benchmark machinery ─────────────────────────────────────────────────────

(defn- now-ns ^long [] (System/nanoTime))

(defn- bench
  "Run `body-php` N times. body-php should be a self-contained PHP snippet
   that does work without any output. Returns map with :ns-per-iter,
   :total-ms, :iters."
  [label iters body-php]
  ;; Warm up.
  (eval-php body-php)
  ;; Measure: do iters in a tight PHP loop wrapped around body-php.
  ;; This minimises FFM-RTT contribution; what we measure is what the
  ;; PHP code itself does.
  (let [code (str "$start=microtime(true);"
                  "for($i=0;$i<" iters ";$i++){"
                  body-php
                  "}"
                  "echo number_format((microtime(true)-$start)*1e9,3,'.','');")
        {:keys [stdout error]} (eval-php code)]
    (when error (throw (ex-info (str "bench " label " failed") {:error error :stdout stdout})))
    (when (str/blank? stdout)
      (throw (ex-info (str "bench " label " produced no output")
                      {:stdout (pr-str stdout) :code (subs code 0 (min 200 (count code)))})))
    (let [total-ns (Double/parseDouble (str/trim stdout))]
      {:label label
       :iters iters
       :total-ms (/ total-ns 1e6)
       :ns-per-iter (double (/ total-ns iters))})))

(defn- preload-phpjava!
  "Load PHPJava autoload once into the embedded PHP runtime."
  []
  (let [autoload "/home/hbtweb/GitHub/php-java/vendor/autoload.php"
        {:keys [error]} (eval-php (str "require_once '" autoload "';"))]
    (when error
      (throw (ex-info "PHPJava autoload failed" {:error error})))))

(defn- compile-fixture!
  "Ensure `bench/fixtures/<name>.class` exists. Compiles if missing or
   stale relative to the .java source."
  [name]
  (let [base "/home/hbtweb/GitHub/php-java/bench/fixtures"
        src  (str base "/" name ".java")
        cls  (str base "/" name ".class")
        java-modified (.lastModified (File. src))
        cls-modified  (if (.exists (File. cls)) (.lastModified (File. cls)) 0)]
    (when (> java-modified cls-modified)
      (let [pb (ProcessBuilder. ["javac" "--release" "11" "-d" base src])
            _  (.redirectErrorStream pb true)
            p  (.start pb)
            _  (.waitFor p)]
        (when-not (zero? (.exitValue p))
          (throw (ex-info "javac failed" {:src src
                                          :stdout (slurp (.getInputStream p))})))))))

;; ─── benchmark suite ─────────────────────────────────────────────────────────

(def cljp-classloader-base
  "/home/hbtweb/GitHub/php-java/bench/fixtures")

(defn- phpjava-load-class [class-name]
  ;; Use fully-qualified namespace refs so this is valid mid-block in eval'd PHP.
  (str "\\PHPJava\\Kernel\\Resolvers\\ClassResolver::add(["
       "[\\PHPJava\\Kernel\\Resolvers\\ClassResolver::RESOURCE_TYPE_FILE,"
       "'" cljp-classloader-base "']]);"
       "$cls = \\PHPJava\\Core\\JavaClass::load('" class-name "');"))

(defn- phpjava-call [class-name method-name & args]
  (str (phpjava-load-class class-name)
       "$cls->getInvoker()->getStatic()->getMethods()->call('"
       method-name "'"
       (when (seq args) (str "," (str/join "," args)))
       ");"))

(defn- verify-phpjava-call!
  "Run a single PHPJava call to confirm the path works before benchmarking."
  []
  (let [code (phpjava-call "BenchEmpty" "noop")
        {:keys [stdout error]} (eval-php code)]
    (when error
      (throw (ex-info (str "PHPJava verify failed: " error)
                      {:stdout stdout :error error :code code})))
    {:stdout stdout :ok true}))

(defn baselines
  "Run all baseline bench measurements and return a map suitable for
   JSON serialisation."
  []
  (preload-phpjava!)
  (compile-fixture! "BenchAdd")
  (compile-fixture! "BenchInvoke")
  (compile-fixture! "BenchEmpty")
  (compile-fixture! "HelloWorld")
  (println "  verifying PHPJava call path...")
  (let [v (verify-phpjava-call!)]
    (println "    ok:" (pr-str v)))
  {:phpjava-version (str/trim (:stdout (eval-php "echo PHP_VERSION;")))
   :php-version     (str/trim (:stdout (eval-php "echo PHP_VERSION;")))
   :hostname        (str/trim (:stdout (eval-php "echo gethostname();")))
   ;; Pure PHP eval RTT — to subtract from PHPJava measurements.
   :ffm-eval-rtt    (bench "ffm-eval-rtt" 10000 "$x=1;")
   ;; HelloWorld total — the gross top-line number.
   :phpjava-helloworld
     (bench "phpjava-helloworld" 10
            (phpjava-call "HelloWorld" "main" "[]"))
   ;; Empty static method — measures per-method-call setup cost (M1, M2, M3).
   :phpjava-empty-method
     (bench "phpjava-empty-method" 100
            (phpjava-call "BenchEmpty" "noop"))
   ;; Tight iadd loop inside Java — measures per-bytecode op cost (H1-H8).
   ;; BenchAdd::sum runs `int s=0; for(int i=0;i<N;i++) s+=i; return s;`
   ;; with N=1000 → 1000 iadd ops + 1000 iinc + 1000 if_icmplt + ... ≈ 4000 ops.
   :phpjava-iadd-1k
     (bench "phpjava-iadd-1k" 50
            (phpjava-call "BenchAdd" "sum1k"))
   ;; Tight INVOKEVIRTUAL loop — measures method dispatch cost.
   ;; BenchInvoke::callLoop calls a no-op method 100 times.
   :phpjava-invokevirtual-100
     (bench "phpjava-invokevirtual-100" 50
            (phpjava-call "BenchInvoke" "callLoop"))})

(defn -main [& args]
  (println "PHPJava baseline benchmark")
  (println "==========================")
  (let [results (baselines)
        sha (str/trim (:out (shell/sh "git" "-C" "/home/hbtweb/GitHub/php-java" "rev-parse" "--short" "HEAD")))
        out-path (str "/home/hbtweb/GitHub/php-java/bench/baseline-" sha ".json")]
    (println)
    (doseq [[k v] results]
      (if (map? v)
        (println (format "  %-30s %10.2f ns/iter (×%d, total %.2f ms)"
                         (name k)
                         (:ns-per-iter v)
                         (:iters v)
                         (:total-ms v)))
        (println (format "  %-30s %s" (name k) v))))
    (println)
    (spit out-path (json/write-str results :indent 2))
    (println "Results: " out-path)))

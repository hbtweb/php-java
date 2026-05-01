// Empty static method — measures pure per-method-call setup cost.
// Body is a single RETURN bytecode. Every invocation goes through
// JavaMethodCallable::call which writes bytecode to a temp file
// (the M1 hypothesis from ROADMAP.md).
public class BenchEmpty {
    public static void noop() {
    }
}

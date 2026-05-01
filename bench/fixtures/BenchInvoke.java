// Tight INVOKEVIRTUAL loop — measures method-dispatch cost.
// callLoop calls a no-op static method 100 times. Each invocation
// is one INVOKESTATIC bytecode but lands the full per-method-call
// setup path in JavaMethodCallable::call (the M1-M4 hypotheses).
public class BenchInvoke {
    public static int callLoop() {
        int n = 0;
        for (int i = 0; i < 100; i++) {
            n = noop(n);
        }
        return n;
    }

    private static int noop(int x) {
        return x + 1;
    }
}

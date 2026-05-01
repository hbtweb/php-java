// HotSpot reference runner — measures the same workloads run natively
// for comparison against PHPJava. Run with `java -Xint` for interpreted-
// only baseline; default JIT will be much faster.
public class BenchRunner {
    public static void main(String[] args) {
        // sum1k loop (matches BenchAdd.sum1k — 1000 iadd iters)
        {
            long t0 = System.nanoTime();
            int total = 0;
            for (int call = 0; call < 50; call++) {
                int s = 0;
                for (int i = 0; i < 1000; i++) s = s + i;
                total += s;
            }
            long t = System.nanoTime() - t0;
            System.out.println("sum1k        " + (t / 50) + " ns/call  (total=" + total + ")");
        }
        // empty-method-call x100 (matches BenchInvoke.callLoop)
        {
            long t0 = System.nanoTime();
            int total = 0;
            for (int call = 0; call < 50; call++) {
                int n = 0;
                for (int i = 0; i < 100; i++) n = noop(n);
                total += n;
            }
            long t = System.nanoTime() - t0;
            System.out.println("invoke100    " + (t / 50) + " ns/call  (total=" + total + ")");
        }
    }
    private static int noop(int x) { return x + 1; }
}

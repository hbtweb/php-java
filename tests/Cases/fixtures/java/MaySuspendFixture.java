// Probe fixture for MaySuspendAnalyzer (ROADMAP §Build T3 — async-emit
// specialiser PoC). Two methods: one provably non-suspending, one
// that calls Thread.sleep so the analyzer should classify it as
// may-suspend. Used to validate the IR-level analysis works on real
// AOT-compiled bytecode, not just hand-built IR.

class MaySuspendFixture
{
    /** Pure compute. No suspend point reachable. */
    public static int pure(int a, int b)
    {
        int x = a * a;
        int y = b * b;
        return x + y;
    }

    /** Branchy compute. Still no suspend. */
    public static int branchy(int n)
    {
        int r = 0;
        for (int i = 0; i < n; i++) {
            if (i % 2 == 0) r += i;
            else r -= i;
        }
        return r;
    }

    /** Calls Thread.sleep. Definitely suspends. */
    public static int suspendingSleep() throws InterruptedException
    {
        Thread.sleep(1L);
        return 42;
    }

    /** Calls another method in the same class that itself sleeps.
     *  Phase 1 of the analyzer is single-method — this returns
     *  conservative-true under default settings; would return false
     *  under transitive analysis when Phase 2 lands. */
    public static int callsSuspending() throws InterruptedException
    {
        return suspendingSleep();
    }
}

class NaNComparisonTest
{
    // fcmpl/dcmpl: returns -1 if either operand is NaN
    // fcmpg/dcmpg: returns +1 if either operand is NaN
    // The opcode chosen depends on the surrounding compare. javac
    // typically emits fcmpl before if_ge/if_gt (so NaN doesn't pass)
    // and fcmpg before if_le/if_lt (same reason).

    public static int dcmplNaN()
    {
        // NaN < 1.0 — javac emits dcmpl, then if_ge ELSE 1
        // dcmpl(NaN, 1.0) = -1; -1 < 0 → true → returns 1
        double a = Double.NaN;
        double b = 1.0;
        return (a < b) ? 1 : 0;
    }

    public static int dcmpgNaN()
    {
        // NaN > 1.0 — javac emits dcmpg, then if_le ELSE 1
        // dcmpg(NaN, 1.0) = +1; +1 > 0 → true if Java semantics match
        // PHP <=> returns 0 → 0 > 0 false → wrong without helper
        double a = Double.NaN;
        double b = 1.0;
        return (a > b) ? 1 : 0;
    }

    // Float.equals(NaN, NaN) returns true per Java spec
    public static boolean floatEqualsNaN()
    {
        Float a = Float.NaN;
        Float b = Float.NaN;
        return a.equals(b);
    }

    public static boolean doubleEqualsNaN()
    {
        Double a = Double.NaN;
        Double b = Double.NaN;
        return a.equals(b);
    }

    // Float.compareTo with NaN — NaN > anything-else
    public static int compareToNaN()
    {
        Float a = Float.NaN;
        Float b = 1.0f;
        return a.compareTo(b);  // expected: positive
    }
}

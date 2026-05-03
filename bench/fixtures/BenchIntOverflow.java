// Int overflow fixture — exercises JVM 32-bit signed wraparound
// semantics that the interpreter (and AOT) must preserve per
// CONTRACTS.md §1 ("Java int overflow semantics preserved via
// & 0xFFFFFFFF mask + sign extend").
//
// Each method takes args so javac can't constant-fold the arithmetic.
// Reference values come from HotSpot.
//
// Compile: javac --release 8 BenchIntOverflow.java
public class BenchIntOverflow {
    // a + b with a=MAX_VALUE, b=1 → wraps to MIN_VALUE
    public static int add(int a, int b) {
        return a + b;
    }

    // a - b with a=MIN_VALUE, b=1 → wraps to MAX_VALUE
    public static int sub(int a, int b) {
        return a - b;
    }

    // a * b with a=MIN_VALUE, b=-1 → MIN_VALUE (overflow keeps sign)
    public static int mul(int a, int b) {
        return a * b;
    }

    // -a (ineg) where a=MIN_VALUE → MIN_VALUE (overflow keeps sign)
    public static int neg(int a) {
        return -a;
    }

    // a * b with a=MAX_VALUE, b=2 → -2 (multiplication overflow)
    public static int mulByTwo(int a) {
        return a * 2;
    }
}

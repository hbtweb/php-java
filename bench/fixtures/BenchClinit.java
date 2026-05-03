// Static-initialiser fixture. The static {} block runs at class-init
// time on the JVM (first active use). If the AOT skips <clinit>, both
// fields read as their primitive defaults (0).
//
//   getCounter()  == 55  (1+2+...+10)
//   getDoubled()  == 110 (counter * 2)
//   constant()    == 42  (a static method that doesn't touch the
//                          static fields, used to verify clinit triggers
//                          on the parent class even when the called
//                          method itself doesn't read static state).
public class BenchClinit {
    public static int counter;
    public static int doubled;

    static {
        for (int i = 1; i <= 10; i++) {
            counter += i;
        }
        doubled = counter * 2;
    }

    public static int getCounter() {
        return counter;
    }

    public static int getDoubled() {
        return doubled;
    }

    public static int constant() {
        return 42;
    }
}

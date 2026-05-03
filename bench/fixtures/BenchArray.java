// Exercises array opcodes: newarray, iastore, iaload, arraylength.
//   sumArray(): build int[10] = {0,1,...,9}, sum elements, return 45.
public class BenchArray {
    public static int sumArray() {
        int[] a = new int[10];
        for (int i = 0; i < 10; i++) a[i] = i;
        int s = 0;
        for (int i = 0; i < a.length; i++) s += a[i];
        return s;
    }
}

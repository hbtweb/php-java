// Tight integer-add loop — measures per-bytecode op cost (H1-H8).
// sum1k runs N=1000 iterations; bytecode shape per iteration:
//   iload_1   (load i)         1 op
//   iconst    (or bipush)      1 op
//   iadd                       1 op
//   istore_2  (store s)        1 op
//   iinc      (i++)            1 op
//   iload_1                    1 op
//   sipush                     1 op
//   if_icmplt                  1 op  (back-edge)
// → ~8 bytecodes per iter × 1000 iters = ~8000 ops per call.
public class BenchAdd {
    public static int sum1k() {
        int s = 0;
        for (int i = 0; i < 1000; i++) {
            s = s + i;
        }
        return s;
    }
}

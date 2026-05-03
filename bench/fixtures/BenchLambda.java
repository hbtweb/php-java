// Smallest lambda fixture. Uses java.util.function.IntSupplier (built-in
// SAM interface). The lambda is a no-capture int producer.
//
// Bytecode shape:
//   invokedynamic #N { LambdaMetafactory.metafactory, captures... }
//   invokeinterface IntSupplier.getAsInt
public class BenchLambda {
    public static int run() {
        java.util.function.IntSupplier s = () -> 42;
        return s.getAsInt();
    }

    public static int withCapture(int base) {
        java.util.function.IntSupplier s = () -> base + 100;
        return s.getAsInt();
    }
}

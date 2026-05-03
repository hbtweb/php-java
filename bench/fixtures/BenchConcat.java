// Java 9+ string concat compiles to invokedynamic + StringConcatFactory.
// makeConcatWithConstants. The simplest fixture exercises this path.
public class BenchConcat {
    public static String greet(String name, int count) {
        return "hello " + name + "! count=" + count;
    }
}

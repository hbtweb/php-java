// Smallest try/catch fixture: exception thrown via throw, caught in same
// method. Exercises the exception-table → try/catch translation path
// without needing full constructor support (uses pre-built RE class).
//
// Bytecode shape:
//   NEW java/lang/RuntimeException
//   DUP
//   LDC "test"
//   INVOKESPECIAL <init>(String)V
//   ATHROW
//   <handler:>
//   ASTORE exception slot
//   ICONST_<value>
//   IRETURN
public class BenchTryCatch {
    public static int run() {
        try {
            throw new RuntimeException("test");
        } catch (RuntimeException e) {
            return 42;
        }
    }
}

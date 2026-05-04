// Java 16+ record. Tests that the parser loads classes with Record
// attribute + auto-generated equals/hashCode/toString via ObjectMethods
// invokedynamic. End-to-end record execution still pending; this test
// validates load-time only.
public record RecordLoadTest(int x, int y)
{
    public static int sumOfNew()
    {
        return new RecordLoadTest(3, 4).x + new RecordLoadTest(3, 4).y;
    }
}

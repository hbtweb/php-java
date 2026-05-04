// Probe fixture for the substitution-table API. Invokes a well-known
// JDK static method so the emitted PHP contains a literal FQN that
// the test can search-and-substitute.
class AotSubstitutionMapTest
{
    public static boolean testMatch()
    {
        return java.util.regex.Pattern.matches("^[a-z]+$", "hello");
    }
}

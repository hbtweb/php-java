class CharSurrogateTest
{
    public static int asciiCharAt0()
    {
        return "hello".charAt(0);  // 'h' = 0x68 = 104
    }

    public static int latin1CharAt3()
    {
        // "café".charAt(3) = 'é' = 0xE9 = 233
        // Java string is UTF-16: c, a, f, é — index 3 is é.
        // Pre-fix PHP did ord("café"[3]) = ord(byte 0xC3) = 195 (wrong)
        return "café".charAt(3);
    }

    public static int multibyteCharAt2()
    {
        // "こんにちは".charAt(2) = 'に' = U+306B = 12395
        return "こんにちは".charAt(2);
    }

    public static int supplementaryCharAt0()
    {
        // "😀".charAt(0) = high surrogate U+D83D = 55357
        // (😀 = U+1F600, surrogate pair U+D83D U+DE00)
        return "😀".charAt(0);
    }

    public static int supplementaryCharAt1()
    {
        // "😀".charAt(1) = low surrogate U+DE00 = 56832
        return "😀".charAt(1);
    }

    public static int mixedSupplementaryCharAt2()
    {
        // "aé😀".charAt(2) = high surrogate of 😀 = 0xD83D = 55357
        // (a=1 unit, é=1 unit, then surrogate pair starts at index 2)
        return "aé😀".charAt(2);
    }

    public static int mixedSupplementaryCharAt3()
    {
        return "aé😀".charAt(3);  // low surrogate = 0xDE00 = 56832
    }
}

class StringUtf16LengthTest
{
    public static int asciiLength()
    {
        return "hello".length();  // 5 bytes, 5 UTF-16 units
    }

    public static int latin1Length()
    {
        return "café".length();  // 5 bytes UTF-8, 4 UTF-16 units
    }

    public static int multibyteLength()
    {
        // Japanese: each char is 3 bytes UTF-8, 1 UTF-16 unit
        return "こんにちは".length();  // 15 bytes UTF-8, 5 UTF-16 units
    }

    public static int supplementaryLength()
    {
        // 😀 (U+1F600 grinning face) — supplementary plane.
        // 4 bytes UTF-8, 2 UTF-16 units (surrogate pair).
        return "😀".length();  // Java explicitly: 2
    }

    public static int mixedLength()
    {
        // ASCII + latin-1 + supplementary: "a" + "é" + "😀"
        // = 1 + 1 + 2 = 4 UTF-16 units
        return ("aé😀").length();
    }
}

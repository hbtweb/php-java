class IincOverflowTest
{
    public static int incrementMaxInt()
    {
        int x = Integer.MAX_VALUE;  // 2^31 - 1
        x++;                         // iinc: wraps to Integer.MIN_VALUE
        return x;
    }

    public static int decrementMinInt()
    {
        int x = Integer.MIN_VALUE;
        x--;
        return x;
    }

    public static int wideIincPositive()
    {
        // iinc_w with a +short delta still needs 32-bit wrap
        int x = Integer.MAX_VALUE - 100;
        x += 200;  // crosses MAX_VALUE
        return x;
    }

    public static int wideIincNegative()
    {
        int x = Integer.MIN_VALUE + 100;
        x -= 200;
        return x;
    }
}

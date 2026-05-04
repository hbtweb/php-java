class LongOverflowTest
{
    public static long maxPlusOne()
    {
        return Long.MAX_VALUE + 1L;
    }

    public static long minMinusOne()
    {
        return Long.MIN_VALUE - 1L;
    }

    public static long mulOverflow()
    {
        long x = 1L << 32;
        return x * x;
    }

    public static long negMin()
    {
        return -Long.MIN_VALUE;
    }

    public static long divMinByMinusOne()
    {
        return Long.MIN_VALUE / -1L;
    }

    public static long remMinByMinusOne()
    {
        return Long.MIN_VALUE % -1L;
    }

    public static long maxPlusVar(long v)
    {
        return Long.MAX_VALUE + v;
    }
}

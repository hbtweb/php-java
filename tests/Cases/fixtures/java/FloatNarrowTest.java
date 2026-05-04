class FloatNarrowTest
{
    public static float storedField;
    public static float[] storedArr = new float[1];

    public static float castDoubleToFloat()
    {
        // 0.1 + 0.2 in double: 0.30000000000000004
        // Narrowed to float: 0.3f (binary32 representation, ~3.0000001e-1)
        double d = 0.1 + 0.2;
        return (float) d;
    }

    public static float roundTripField()
    {
        // 1.0/3.0 in double has more bits than float can hold
        // Stored in float field, read back, must equal the float-narrowed value
        storedField = (float) (1.0 / 3.0);
        return storedField;
    }

    public static float roundTripArray()
    {
        storedArr[0] = (float) (1.0 / 3.0);
        return storedArr[0];
    }

    public static float intToFloatLargeMagnitude()
    {
        // 16777217 = 2^24 + 1; not representable in 24-bit float mantissa.
        // Java i2f rounds to nearest even → 16777216.0f
        return (float) 16777217;
    }

    public static float longToFloatLargeMagnitude()
    {
        // Same idea, but starting from a long
        return (float) 16777217L;
    }
}

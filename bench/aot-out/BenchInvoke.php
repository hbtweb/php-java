<?php
namespace PHPJava\Aot\Generated;

final class BenchInvoke
{
    // callLoop ()I
    public static function callLoop()
    {
$L = [0, 0];
        $stack = []; $sp = 0;
L_0:
        $L[0] = 0;
        $L[1] = 0;
L_4:
        if ($L[1] >= 100) goto L_21;
        $L[0] = (($L[0]) + 1);
        $L[1] += 1;
        goto L_4;
L_21:
        return $L[0];
    }

    // noop (I)I
    public static function noop($__a0)
    {
$L = [$__a0];
        $stack = []; $sp = 0;
L_0:
        return $L[0] + 1;
    }
}

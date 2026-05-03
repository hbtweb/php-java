<?php
namespace PHPJava\Aot\Generated;

#[\AllowDynamicProperties]
final class BenchArray
{
    // __construct ()V
    public function __construct()
    {
$L = [$this];
        $stack = []; $sp = 0;
L_0:
        return;
    }

    // sumArray ()I
    public static function sumArray()
    {
$L = [0, 0, 0];
        $stack = []; $sp = 0;
L_0:
        $L[0] = \array_fill(0, 10, 0);
        $L[1] = 0;
L_7:
        if ($L[1] >= 10) goto L_23;
        $L[0][$L[1]] = $L[1];
        $L[1] += 1;
        goto L_7;
L_23:
        $L[1] = 0;
        $L[2] = 0;
L_27:
        if ($L[2] >= \count($L[0])) goto L_45;
        $L[1] = $L[1] + $L[0][$L[2]] << 32 >> 32;
        $L[2] += 1;
        goto L_27;
L_45:
        return $L[1];
    }
}

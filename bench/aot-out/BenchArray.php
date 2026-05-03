<?php
namespace PHPJava\Aot\Generated;

final class BenchArray
{
    // sumArray ()I
    public static function sumArray()
    {
$L = [0, 0, 0];
        $stack = []; $sp = 0;
L_0:
        $L[0] = \PHPJava\Aot\Ir\ArrayHelper::newPrimArray(10);
        $L[1] = 0;
L_7:
        if ($L[1] >= 10) goto L_23;
        \PHPJava\Aot\Ir\ArrayHelper::set($L[0], $L[1], $L[1]);
        $L[1] += 1;
        goto L_7;
L_23:
        $L[1] = 0;
        $L[2] = 0;
L_27:
        if ($L[2] >= \PHPJava\Aot\Ir\ArrayHelper::len($L[0])) goto L_45;
        $L[1] = $L[1] + \PHPJava\Aot\Ir\ArrayHelper::get($L[0], $L[2]);
        $L[2] += 1;
        goto L_27;
L_45:
        return $L[1];
    }
}

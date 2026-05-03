<?php
namespace PHPJava\Aot\Generated;

#[\AllowDynamicProperties]
final class BenchAdd
{
    // __construct ()V
    public function __construct()
    {
$L = [$this];
        $stack = []; $sp = 0;
L_0:
        return;
    }

    // sum1k ()I
    public static function sum1k()
    {
$L = [0, 0];
        $stack = []; $sp = 0;
L_0:
        $L[0] = 0;
        $L[1] = 0;
L_4:
        if ($L[1] >= 1000) goto L_21;
        $L[0] = $L[0] + $L[1] << 32 >> 32;
        $L[1] += 1;
        goto L_4;
L_21:
        return $L[0];
    }
}

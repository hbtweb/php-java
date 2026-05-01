<?php
namespace PHPJava\Aot\Generated;

final class BenchAdd
{
    // sum1k ()I
    public static function sum1k()
    {
$L = []; // locals
$stack = []; $sp = 0;
L_0:
        $stack[$sp++] = 0;
        $L[0] = $stack[--$sp];
        $stack[$sp++] = 0;
        $L[1] = $stack[--$sp];
L_4:
        $stack[$sp++] = $L[1] ?? 0;
        $stack[$sp++] = 1000;
        $b = $stack[--$sp]; $a = $stack[--$sp]; if ($a >= $b) goto L_21;
        $stack[$sp++] = $L[0] ?? 0;
        $stack[$sp++] = $L[1] ?? 0;
        $b = $stack[--$sp]; $stack[$sp - 1] += $b;
        $L[0] = $stack[--$sp];
        $L[1] = ($L[1] ?? 0) + (1);
        goto L_4;
L_21:
        $stack[$sp++] = $L[0] ?? 0;
        return $stack[--$sp];
    }
}

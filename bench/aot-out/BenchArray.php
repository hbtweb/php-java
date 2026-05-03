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
        $stack[$sp++] = 10;
        $__sz = $stack[--$sp]; $stack[$sp++] = (object)['v' => \array_fill(0, $__sz, 0)];
        $L[0] = $stack[--$sp];
        $L[1] = 0;
L_7:
        if ($L[1] >= 10) goto L_23;
        $stack[$sp++] = $L[0];
        $stack[$sp++] = $L[1];
        $stack[$sp++] = $L[1];
        $__v = $stack[--$sp]; $__i = $stack[--$sp]; $stack[--$sp]->v[$__i] = $__v;
        $L[1] += 1;
        goto L_7;
L_23:
        $L[1] = 0;
        $L[2] = 0;
L_27:
        $stack[$sp++] = $L[2];
        $stack[$sp++] = $L[0];
        $stack[$sp - 1] = \count($stack[$sp - 1]->v);
        $b = $stack[--$sp]; $a = $stack[--$sp]; if ($a >= $b) goto L_45;
        $stack[$sp++] = $L[1];
        $stack[$sp++] = $L[0];
        $stack[$sp++] = $L[2];
        $__i = $stack[--$sp]; $stack[$sp - 1] = $stack[$sp - 1]->v[$__i];
        $b = $stack[--$sp]; $stack[$sp - 1] += $b;
        $L[1] = $stack[--$sp];
        $L[2] += 1;
        goto L_27;
L_45:
        return $L[1];
    }
}

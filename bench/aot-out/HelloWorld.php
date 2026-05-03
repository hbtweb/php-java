<?php
namespace PHPJava\Aot\Generated;

final class HelloWorld
{
    // main ([Ljava/lang/String;)V
    public static function main($__a0)
    {
$L = [$__a0, 0, 0];
        $stack = []; $sp = 0;
L_0:
        $stack[$sp++] = \PHPJava\Aot\Runtime\java\lang\System::$out;
        $stack[$sp++] = 'hello from phpjava';
        $__a0 = $stack[--$sp]; $__obj = $stack[--$sp]; $__obj->println($__a0);
        $L[1] = 0;
        $L[2] = 1;
L_12:
        if ($L[2] > 10) goto L_28;
        $L[1] = $L[1] + $L[2];
        $L[2] += 1;
        goto L_12;
L_28:
        $stack[$sp++] = \PHPJava\Aot\Runtime\java\lang\System::$out;
        $stack[$sp++] = $L[1];
        $__a0 = $stack[--$sp]; $__obj = $stack[--$sp]; $__obj->println($__a0);
        return;
    }
}

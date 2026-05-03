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
        \PHPJava\Aot\Runtime\java\lang\System::$out->println('hello from phpjava');
        $L[1] = 0;
        $L[2] = 1;
L_12:
        if ($L[2] > 10) goto L_28;
        $L[1] = $L[1] + $L[2];
        $L[2] += 1;
        goto L_12;
L_28:
        \PHPJava\Aot\Runtime\java\lang\System::$out->println($L[1]);
        return;
    }
}

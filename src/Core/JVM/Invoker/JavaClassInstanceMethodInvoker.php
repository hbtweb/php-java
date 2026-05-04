<?php
declare(strict_types=1);
namespace PHPJava\Core\JVM\Invoker;

class JavaClassInstanceMethodInvoker extends JavaClassMethodInvoker implements InvokerInterface
{
    public function isInstanceMethod(): bool
    {
        return true;
    }
}

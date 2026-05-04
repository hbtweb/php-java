<?php
declare(strict_types=1);
namespace PHPJava\Core\JVM\Invoker;

class PHPClassInstanceMethodInvoker extends PHPClassMethodInvoker implements InvokerInterface
{
    public function isInstanceMethod(): bool
    {
        return true;
    }
}

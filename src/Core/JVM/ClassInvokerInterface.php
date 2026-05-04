<?php
declare(strict_types=1);
namespace PHPJava\Core\JVM;

use PHPJava\Core\JavaClassInterface;
use PHPJava\Core\JVM\Field\JavaInstanceField;
use PHPJava\Core\JVM\Field\JavaStaticField;
use PHPJava\Core\JVM\Field\PHPInstanceField;
use PHPJava\Core\JVM\Field\PHPStaticField;
use PHPJava\Kernel\Provider\ProviderInterface;

interface ClassInvokerInterface
{
    public function __construct(JavaClassInterface $javaClass, array $options);

    public function getJavaClass(): JavaClassInterface;

    public function construct(...$arguments): ClassInvokerInterface;

    /**
     * @return AccessorInterface|JavaInstanceField|PHPInstanceField
     */
    public function getDynamic(): AccessorInterface;

    /**
     * @return AccessorInterface|JavaStaticField|PHPStaticField
     */
    public function getStatic(): AccessorInterface;

    public function getProvider(string $providerName): ProviderInterface;

    public function getClassObject();
}

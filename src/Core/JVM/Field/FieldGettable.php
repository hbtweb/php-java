<?php
declare(strict_types=1);
namespace PHPJava\Core\JVM\Field;

use PHPJava\Packages\java\lang\NoSuchFieldException;

trait FieldGettable
{
    /**
     * @throws NoSuchFieldException
     */
    public function get(string $name)
    {
        $this->javaClassInvoker
            ->getStatic()
            ->getMethods()
            ->callStaticInitializerIfNotInstantiated();

        // Phase B: when the JavaClassInvoker has an AOT instance bound
        // (via ->construct(...)) and this field accessor is the dynamic
        // one, route reads to the PHP-native property on the AOT
        // instance. Static fields and pre-construct dynamic reads still
        // use the internal $fields map.
        if (\method_exists($this->javaClassInvoker, 'getAotInstance')
            && $this instanceof \PHPJava\Core\JVM\Field\JavaInstanceField) {
            $aot = $this->javaClassInvoker->getAotInstance();
            if ($aot !== null && \property_exists($aot, $name)) {
                return $aot->{$name};
            }
        }

        if (!array_key_exists($name, $this->fields)) {
            throw new NoSuchFieldException('Tried to get undefined field ' . $name);
        }

        return $this->fields[$name];
    }
}

<?php
declare(strict_types=1);
namespace PHPJava\Core\JVM\Field;

trait FieldSettable
{
    public function set(string $name, $value)
    {
        // Phase B: write through to the AOT-Generated PHP instance
        // when one is bound via ->construct(...). Mirrors FieldGettable's
        // read path. Static fields and pre-construct dynamic writes
        // still update the internal $fields map only.
        if (\method_exists($this->javaClassInvoker, 'getAotInstance')
            && $this instanceof \PHPJava\Core\JVM\Field\JavaInstanceField) {
            $aot = $this->javaClassInvoker->getAotInstance();
            if ($aot !== null) {
                $aot->{$name} = $value;
            }
        }
        $this->fields[$name] = $value;
        return $this;
    }
}

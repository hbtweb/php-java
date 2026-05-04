<?php
declare(strict_types=1);
namespace PHPJava\Core\JVM\Field;

use PHPJava\Aot\Loader;
use PHPJava\Core\JVM\ClassInvokerInterface;
use PHPJava\Packages\java\lang\NoSuchFieldException;

class JavaStaticField implements FieldInterface
{
    use FieldGettable {
        FieldGettable::get as private getFromInterpStorage;
    }
    use FieldSettable;
    use FieldListable;

    /**
     * @var ClassInvokerInterface
     */
    private $javaClassInvoker;

    /**
     * @var mixed[]
     */
    private $fields = [];

    public function __construct(ClassInvokerInterface $javaClassInvoker, array $fields)
    {
        $this->javaClassInvoker = $javaClassInvoker;
        $this->fields = $fields;
    }

    /**
     * Bridge the AOT-vs-interp static-field-storage divergence (see
     * the trailing note on commit c17722e). When the class has been
     * AOT-loaded — typically because PHPJAVA_AOT_MODE=lazy routed
     * `<clinit>` through `Loader::tryCallStatic` — the AOT-emitted
     * `__staticConstruct` mutated the AOT class's PHP static
     * properties (`\PHPJava\Aot\Generated\Foo::$field`), NOT
     * `$this->fields` (which only the interp `_putstatic` mnemonic
     * writes). Read from the AOT class when present; fall through
     * to interp storage when not.
     *
     * Pure addition: in default mode (env unset, AOT never loaded),
     * `Loader::isLoaded` returns false and the path is identical to
     * the FieldGettable trait.
     *
     * Caveat: if both interp and AOT initialised the same field
     * (eg dispatch mode changed mid-process), AOT wins. In env=lazy
     * mode the interp `_putstatic` never fires for `<clinit>`
     * because `tryCallStatic` short-circuits to AOT first, so this
     * is moot for the typical case.
     */
    public function get(string $name)
    {
        $this->javaClassInvoker
            ->getStatic()
            ->getMethods()
            ->callStaticInitializerIfNotInstantiated();

        $classPath = $this->javaClassInvoker->getJavaClass()->getClassName();
        if (Loader::isLoaded($classPath)) {
            $aotFqn = 'PHPJava\\Aot\\Generated\\'
                . str_replace(['.', '/', '\\', '$'], '_', $classPath);
            // property_exists matches both static and instance
            // properties; reflect to verify it's actually static before
            // accessing as `::$name` (otherwise `Access to undeclared
            // static property` errors out for instance fields the AOT
            // class declares with `public $name`).
            if (property_exists($aotFqn, $name)
                && (new \ReflectionProperty($aotFqn, $name))->isStatic()) {
                return $aotFqn::${$name};
            }
        }

        if (!array_key_exists($name, $this->fields)) {
            throw new NoSuchFieldException('Tried to get undefined field ' . $name);
        }
        return $this->fields[$name];
    }
}

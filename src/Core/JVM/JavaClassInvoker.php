<?php
declare(strict_types=1);
namespace PHPJava\Core\JVM;

use PHPJava\Aot\Loader as AotLoader;
use PHPJava\Core\JavaClassInterface;
use PHPJava\Core\JVM\Field\JavaDynamicField;
use PHPJava\Core\JVM\Field\JavaStaticField;
use PHPJava\Core\JVM\Invoker\JavaClassDynamicMethodInvoker;
use PHPJava\Core\JVM\Invoker\JavaClassStaticMethodInvoker;
use PHPJava\Kernel\Filters\Normalizer;
use PHPJava\Kernel\Maps\FieldAccessFlag;
use PHPJava\Kernel\Maps\MethodAccessFlag;
use PHPJava\Kernel\Structures\FieldInfo;
use PHPJava\Kernel\Structures\MethodInfo;

class JavaClassInvoker implements ClassInvokerInterface
{
    use Extended\ProviderProvidable;
    use Extended\JavaClassProvidable;
    use Extended\DynamicAccessorProvidable;
    use Extended\StaticAccessorProvidable;

    /**
     * Phase B: AOT-Generated PHP instance bound to this invoker by
     * `construct(...)`. When set, instance method dispatch and field
     * access route through PHP-native semantics on this object instead
     * of the interpreter dispatch loop / internal field map. Null
     * before construct() is called, or when AOT compile fails for the
     * underlying class (then the dynamic path falls back to interp).
     */
    private ?object $aotInstance = null;

    public function getAotInstance(): ?object
    {
        return $this->aotInstance;
    }

    /**
     * @var MethodInfo[]
     */
    private $dynamicMethods = [];

    /**
     * @var MethodInfo[]
     */
    private $staticMethods = [];

    /**
     * @var FieldInfo[]
     */
    private $dynamicFields = [];

    /**
     * @var FieldInfo[]
     */
    private $staticFields = [];

    /**
     * @var array
     */
    private $options = [];

    /**
     * @var array
     */
    private $innerClasses = [];

    /**
     * @throws \PHPJava\Exceptions\NormalizerException
     */
    public function __construct(JavaClassInterface $javaClass, array $options)
    {
        $this->javaClass = $javaClass;
        $this->options = $options;
        $cpInfo = $this->javaClass->getConstantPool();

        foreach ($this->javaClass->getDefinedMethods() as $methodInfo) {
            /**
             * @var MethodInfo $methodInfo
             */
            $methodName = $cpInfo[$methodInfo->getNameIndex()]->getString();

            if (($methodInfo->getAccessFlag() & MethodAccessFlag::ACC_STATIC) !== 0) {
                $this->staticMethods[$methodName][] = $methodInfo;
            } else {
                $this->dynamicMethods[$methodName][] = $methodInfo;
            }
        }

        foreach ($this->javaClass->getDefinedFields() as $fieldInfo) {
            /**
             * @var FieldInfo $fieldInfo
             */
            $fieldName = $cpInfo[$fieldInfo->getNameIndex()]->getString();

            if (($fieldInfo->getAccessFlag() & FieldAccessFlag::ACC_STATIC) !== 0) {
                $this->staticFields[$fieldName] = $fieldInfo;
            } else {
                $this->dynamicFields[$fieldName] = $fieldInfo;
            }
        }

        $this->dynamicAccessor = new Accessor(
            $this,
            JavaClassDynamicMethodInvoker::class,
            JavaDynamicField::class,
            $this->dynamicMethods,
            Normalizer::normalizeFields(
                $this->dynamicFields,
                $this->javaClass
            ),
            $this->options
        );

        $this->staticAccessor = new Accessor(
            $this,
            JavaClassStaticMethodInvoker::class,
            JavaStaticField::class,
            $this->staticMethods,
            Normalizer::normalizeFields(
                $this->staticFields,
                $this->javaClass
            ),
            $this->options
        );
    }

    public function construct(...$arguments): ClassInvokerInterface
    {
        // Reset interp-side dynamicAccessor (existing behaviour: each
        // construct() yields a fresh instance with default field state
        // on the JavaDynamicField map).
        $this->dynamicAccessor = new Accessor(
            $this,
            JavaClassDynamicMethodInvoker::class,
            JavaDynamicField::class,
            $this->dynamicMethods,
            Normalizer::normalizeFields(
                $this->dynamicFields,
                $this->javaClass
            ),
            $this->options
        );

        // Phase B: also instantiate the AOT-Generated PHP class for this
        // Java class and run its __construct. Subsequent dynamic dispatch
        // (->getMethods()->call, ->getFields()->get/set) routes through
        // PHP-native semantics on this object. If AOT compile fails, the
        // dynamic path falls back to the interpreter as it did pre-Phase-B.
        $cp = $this->javaClass->getClassName();
        try {
            AotLoader::loadClass($cp);
            $aotFqn = '\\PHPJava\\Aot\\Generated\\'
                . str_replace(['.', '/', '\\', '$'], '_', $cp);
            if (\class_exists($aotFqn)) {
                $rawArgs = [];
                foreach ($arguments as $a) {
                    $rawArgs[] = \is_object($a) && \method_exists($a, 'getValue')
                        ? $a->getValue() : $a;
                }
                $this->aotInstance = (new \ReflectionClass($aotFqn))
                    ->newInstanceWithoutConstructor();
                if (\method_exists($this->aotInstance, '__construct')) {
                    $this->aotInstance->__construct(...$rawArgs);
                }
            }
        } catch (\Throwable $e) {
            // AOT instance unavailable; dynamic dispatch will fall
            // through to the interpreter as before.
            $this->aotInstance = null;
        }

        // If AOT instance is bound, the <init> ran inside it via
        // __construct above. Otherwise, run the interpreter <init> for
        // backward compatibility — keeps the interp-side field map in
        // sync as a fallback.
        if ($this->aotInstance === null) {
            $this->getDynamic()->getMethods()->call(
                '<init>',
                ...$arguments
            );
        }

        return $this;
    }

    /**
     * @return JavaClassInterface
     */
    public function getClassObject()
    {
        return $this->javaClass;
    }
}

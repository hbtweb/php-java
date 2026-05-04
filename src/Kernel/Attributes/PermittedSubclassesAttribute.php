<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Attributes;

/**
 * PermittedSubclasses attribute (Java 17+). Same shape as NestMembers
 * — a count followed by 2-byte CP indices for each permitted subclass.
 *
 * Currently load-only — `sealed` semantics are not enforced at the
 * AOT layer (PHP has no sealed-class equivalent; emit drops the
 * `sealed` modifier). Parsing here exists so the attribute table
 * loads cleanly for any class compiled with Java 17+.
 */
final class PermittedSubclassesAttribute implements AttributeInterface
{
    use \PHPJava\Kernel\Core\BinaryReader;
    use \PHPJava\Kernel\Core\ConstantPool;
    use \PHPJava\Kernel\Core\AttributeReference;
    use \PHPJava\Kernel\Core\DebugTool;

    /**
     * @var \PHPJava\Core\JVM\StructureInterface[]
     */
    private $classes = [];

    public function execute(): void
    {
        $numberOfClasses = $this->readUnsignedShort();
        $cp = $this->getConstantPool();
        for ($i = 0; $i < $numberOfClasses; $i++) {
            $this->classes[] = $cp[$this->readUnsignedShort()];
        }
    }

    /** @return \PHPJava\Core\JVM\StructureInterface[] */
    public function getClasses(): array
    {
        return $this->classes;
    }
}

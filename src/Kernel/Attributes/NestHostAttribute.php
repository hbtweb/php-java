<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Attributes;

/**
 * NestHost attribute (Java 11+). Single 2-byte CP index pointing
 * at the host class of this nest-mate. Required for any nested
 * class with private cross-access between siblings.
 *
 * Currently load-only — the AOT pipeline doesn't enforce nest
 * access checks (we trust the input per CONTRACTS.md §12). Parsing
 * here exists so the attribute table loads cleanly.
 */
final class NestHostAttribute implements AttributeInterface
{
    use \PHPJava\Kernel\Core\BinaryReader;
    use \PHPJava\Kernel\Core\ConstantPool;
    use \PHPJava\Kernel\Core\AttributeReference;
    use \PHPJava\Kernel\Core\DebugTool;

    /**
     * @var \PHPJava\Core\JVM\StructureInterface
     */
    private $hostClass;

    public function execute(): void
    {
        $cp = $this->getConstantPool();
        $this->hostClass = $cp[$this->readUnsignedShort()];
    }

    public function getHostClass()
    {
        return $this->hostClass;
    }
}

<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Structures;

/**
 * CONSTANT_Package_info (tag 20), Java 9+. Same shape as ModuleInfo
 * — a 2-byte UTF-8 index pointing at the package's internal name.
 * Used in `module-info.class` to declare exports/opens packages.
 */
class PackageInfo implements StructureInterface
{
    use \PHPJava\Kernel\Core\BinaryReader;
    use \PHPJava\Kernel\Core\ConstantPool;
    use \PHPJava\Kernel\Core\DebugTool;

    /**
     * @var int
     */
    private $nameIndex = 0;

    public function execute(): void
    {
        $this->nameIndex = $this->readUnsignedShort();
    }

    public function getNameIndex(): int
    {
        return $this->nameIndex;
    }
}

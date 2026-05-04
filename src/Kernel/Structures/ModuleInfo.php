<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Structures;

/**
 * CONSTANT_Module_info (tag 19), Java 9+. Single field: a 2-byte
 * UTF-8 index pointing at the module's binary name. Required for
 * any `module-info.class` to load past the parser; runtime module
 * access enforcement is out of scope (module-info classes load,
 * but encapsulation is not policed).
 */
class ModuleInfo implements StructureInterface
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

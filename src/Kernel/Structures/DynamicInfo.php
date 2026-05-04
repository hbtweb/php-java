<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Structures;

/**
 * CONSTANT_Dynamic_info (tag 17), JEP 309, Java 11+. Same shape as
 * CONSTANT_InvokeDynamic_info — a `bootstrap_method_attr_index` plus
 * a `name_and_type_index` — but resolves to a constant value rather
 * than a CallSite. Used for dynamic constants in modern bytecode
 * (record metadata, switch desugaring etc.).
 *
 * Resolution to an actual value (via the bootstrap method) is
 * deferred — this entry exists so class files containing tag 17
 * load past the parser. AOT call sites that reference dynamic
 * constants currently fall through to the unknown-indy registry
 * path with a clear runtime error.
 */
class DynamicInfo implements StructureInterface
{
    use \PHPJava\Kernel\Core\BinaryReader;
    use \PHPJava\Kernel\Core\ConstantPool;
    use \PHPJava\Kernel\Core\DebugTool;

    /**
     * @var int
     */
    private $bootstrapMethodAttrIndex = 0;

    /**
     * @var int
     */
    private $nameAndTypeIndex = 0;

    public function execute(): void
    {
        $this->bootstrapMethodAttrIndex = $this->readUnsignedShort();
        $this->nameAndTypeIndex = $this->readUnsignedShort();
    }

    public function getBootstrapMethodAttrIndex(): int
    {
        return $this->bootstrapMethodAttrIndex;
    }

    public function getNameAndTypeIndex(): int
    {
        return $this->nameAndTypeIndex;
    }
}

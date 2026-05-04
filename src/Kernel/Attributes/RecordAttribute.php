<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Attributes;

/**
 * Record attribute (Java 16+). Lists record components: each is
 * a (name, descriptor, attributes) triple. Required for any record
 * class to load past the parser.
 *
 * Currently load-only — record-class shape (PHP class extending
 * \PHPJava\Aot\Runtime\java\lang\Record + auto-generated equals/
 * hashCode/toString via ObjectMethods indy) isn't yet emitted. End-
 * to-end record execution still pending; tracked in ROADMAP §Build
 * "ObjectMethods record-shape emit".
 *
 * Component-level attributes (Signature, RuntimeVisibleAnnotations,
 * RuntimeVisibleTypeAnnotations) are skipped — we read the byte
 * count and skip the bytes, so the outer attribute parser stays
 * aligned but inner annotation surfaces aren't exposed yet.
 */
final class RecordAttribute implements AttributeInterface
{
    use \PHPJava\Kernel\Core\BinaryReader;
    use \PHPJava\Kernel\Core\ConstantPool;
    use \PHPJava\Kernel\Core\AttributeReference;
    use \PHPJava\Kernel\Core\DebugTool;

    /**
     * @var array<int, array{name:string, descriptor:string}>
     */
    private $components = [];

    public function execute(): void
    {
        $cp = $this->getConstantPool();
        $count = $this->readUnsignedShort();
        for ($i = 0; $i < $count; $i++) {
            $nameIdx = $this->readUnsignedShort();
            $descIdx = $this->readUnsignedShort();
            $name = $cp[$nameIdx]->getString();
            $desc = $cp[$descIdx]->getString();
            // Skip component-level attributes — we don't expose them
            // yet, but must consume the bytes to stay aligned.
            $attrCount = $this->readUnsignedShort();
            for ($j = 0; $j < $attrCount; $j++) {
                $this->readUnsignedShort();          // attr_name_index
                $attrLen = $this->readUnsignedInt(); // attribute_length
                for ($k = 0; $k < $attrLen; $k++) $this->readByte();
            }
            $this->components[] = ['name' => $name, 'descriptor' => $desc];
        }
    }

    /** @return array<int, array{name:string, descriptor:string}> */
    public function getComponents(): array
    {
        return $this->components;
    }
}

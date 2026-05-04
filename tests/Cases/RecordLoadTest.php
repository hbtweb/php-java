<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates that classes with Record / NestHost / PermittedSubclasses
 * attributes load past the parser. End-to-end record execution
 * (auto-generated equals/hashCode/toString via ObjectMethods indy
 * + record-class shape with extends \PHPJava\Aot\Runtime\java\lang\Record
 * + component accessors) still pending; tracked under ROADMAP §Build
 * "ObjectMethods record-shape emit".
 */
class RecordLoadTest extends Base
{
    protected $fixtures = [
        'RecordLoadTest',
    ];

    /** Records require Java 16+. */
    protected $javacRelease = '17';

    public function testRecordClassLoads(): void
    {
        // Pre-fix: Record attribute would either fatal at AttributeInfo
        // dispatch (no RecordAttribute class) or be silently skipped.
        // Now it loads cleanly via the new RecordAttribute parser.
        $this->assertNotNull(static::$initiatedJavaClasses['RecordLoadTest']);
    }

    public function testRecordComponentsAccessible(): void
    {
        // The Record attribute is loaded into the class's attribute table.
        // Walk the attributes to find it and check the components.
        $jc = static::$initiatedJavaClasses['RecordLoadTest'];
        $attrs = $jc->getAttributes();
        $found = null;
        foreach ($attrs as $attr) {
            $data = $attr->getAttributeData();
            if ($data instanceof \PHPJava\Kernel\Attributes\RecordAttribute) {
                $found = $data;
                break;
            }
        }
        $this->assertNotNull($found, 'RecordAttribute should be present on a record class');
        $components = $found->getComponents();
        $this->assertCount(2, $components);
        $this->assertSame('x', $components[0]['name']);
        $this->assertSame('I', $components[0]['descriptor']);
        $this->assertSame('y', $components[1]['name']);
        $this->assertSame('I', $components[1]['descriptor']);
    }
}

<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Structures;

class DoubleInfo implements StructureInterface
{
    use \PHPJava\Kernel\Core\BinaryReader;
    use \PHPJava\Kernel\Core\ConstantPool;
    use \PHPJava\Kernel\Core\DebugTool;

    /**
     * @var int
     */
    private $highBytes;

    /**
     * @var int
     */
    private $lowBytes;

    /**
     * @var int
     */
    private $realByte;

    public function execute(): void
    {
        $this->highBytes = $this->readUnsignedInt();
        $this->lowBytes = $this->readUnsignedInt();
    }

    public function getBytes(): float
    {
        if (isset($this->realByte)) {
            return $this->realByte;
        }
        // Decode via IEEE754 round-trip: pack the two 32-bit halves as
        // big-endian, then unpack as a big-endian binary64 ('E'). The
        // manual mantissa/exponent math previously here returned INF for
        // every NaN encoding (exponent=0x7ff, non-zero mantissa) because
        // it computed s*m*2^(e-1075) which overflows. pack/unpack handles
        // every special case (±0, denormals, ±INF, NaN) natively.
        return $this->realByte = \unpack(
            'E',
            \pack('NN', $this->highBytes, $this->lowBytes)
        )[1];
    }
}

<?php
declare(strict_types=1);
namespace PHPJava\Core\JVM\Stream;

use PHPJava\Exceptions\BinaryReaderException;

/**
 * Stream-free binary reader. Reads from an in-memory byte string by
 * offset rather than through a file/php://memory stream resource.
 *
 * Replaces the per-read `fread()` + `unpack()` chain in `BinaryReader`
 * (M1 from `bench/profile-c930e2c.md`) — that profile measured
 * `fread` 1.75 calls/op and `unpack` 1.75 calls/op during interpreter
 * execution, contributing ~15% of total dispatch cost. The same chain
 * dominates JCC parse time on cold compiles (rank-1 measured 1487
 * µs/parse for BenchAdd.class with the stream model).
 *
 * Public API matches `BinaryReader` exactly so callers swap by
 * construction site (`FileReader` / `InlineReader`) — no consumer-side
 * changes needed. Both implementations satisfy `StreamReaderInterface`.
 */
final class StringByteReader implements StreamReaderInterface
{
    private string $data;
    private int $length;
    private int $offset = 0;

    public function __construct(string $data)
    {
        $this->data = $data;
        $this->length = strlen($data);
    }

    /**
     * @throws BinaryReaderException
     */
    public function read(int $bytes = 1): string
    {
        if ($bytes === 0) {
            return '';
        }
        if ($this->offset + $bytes > $this->length) {
            throw new BinaryReaderException(
                'Read binary from buffer is incorrect. that expected length is ' .
                $bytes . ', but actual length is ' .
                ($this->length - $this->offset) . '.'
            );
        }
        $read = substr($this->data, $this->offset, $bytes);
        $this->offset += $bytes;
        return $read;
    }

    public function readByte(): int
    {
        if ($this->offset >= $this->length) {
            throw new BinaryReaderException('Read past end of buffer.');
        }
        $byte = ord($this->data[$this->offset++]);
        // sign-extend 8-bit
        return $byte >= 0x80 ? $byte - 0x100 : $byte;
    }

    public function readUnsignedByte(): int
    {
        if ($this->offset >= $this->length) {
            throw new BinaryReaderException('Read past end of buffer.');
        }
        return ord($this->data[$this->offset++]);
    }

    public function readUnsignedShort(): int
    {
        if ($this->offset + 2 > $this->length) {
            throw new BinaryReaderException('Read past end of buffer.');
        }
        $hi = ord($this->data[$this->offset]);
        $lo = ord($this->data[$this->offset + 1]);
        $this->offset += 2;
        return ($hi << 8) | $lo;
    }

    public function readShort(): int
    {
        $short = $this->readUnsignedShort();
        return (($short & 0x8000) > 0) ? ($short - 0xFFFF - 1) : $short;
    }

    public function readUnsignedInt(): int
    {
        if ($this->offset + 4 > $this->length) {
            throw new BinaryReaderException('Read past end of buffer.');
        }
        $b0 = ord($this->data[$this->offset]);
        $b1 = ord($this->data[$this->offset + 1]);
        $b2 = ord($this->data[$this->offset + 2]);
        $b3 = ord($this->data[$this->offset + 3]);
        $this->offset += 4;
        return ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
    }

    public function readInt(): int
    {
        // The original BinaryReader::readInt does sign-aware read by
        // packing as signed bytes; mirror that.
        if ($this->offset + 4 > $this->length) {
            throw new BinaryReaderException('Read past end of buffer.');
        }
        $b0 = ord($this->data[$this->offset]);
        $b1 = ord($this->data[$this->offset + 1]);
        $b2 = ord($this->data[$this->offset + 2]);
        $b3 = ord($this->data[$this->offset + 3]);
        $this->offset += 4;
        // Sign-extend top byte before composing.
        if ($b0 >= 0x80) $b0 -= 0x100;
        return ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
    }

    public function readUnsignedLong(): int
    {
        if ($this->offset + 8 > $this->length) {
            throw new BinaryReaderException('Read past end of buffer.');
        }
        // unpack('J', ...) = big-endian 64-bit unsigned. PHP int is
        // signed 64-bit; for values > PHP_INT_MAX this overflows
        // negative — same behaviour as BinaryReader.
        $val = unpack('J', substr($this->data, $this->offset, 8))[1];
        $this->offset += 8;
        return $val;
    }

    public function readLong(): int
    {
        if ($this->offset + 8 > $this->length) {
            throw new BinaryReaderException('Read past end of buffer.');
        }
        $val = hexdec(bin2hex(substr($this->data, $this->offset, 8)));
        $this->offset += 8;
        return $val;
    }

    public function seek(int $bytes): void
    {
        $this->offset += $bytes;
    }

    public function setOffset(int $pointer): void
    {
        $this->offset = $pointer;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}

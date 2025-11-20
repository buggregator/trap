<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Websocket;

use Buggregator\Trap\Traffic\Websocket\Frame;
use Buggregator\Trap\Traffic\Websocket\Opcode;
use Buggregator\Trap\Traffic\Websocket\StreamReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Buggregator\Trap\Traffic\Websocket\Frame
 * @covers \Buggregator\Trap\Traffic\Websocket\StreamReader
 */
class FrameTest extends TestCase
{
    /**
     * Test that frames with different payload sizes are packed correctly.
     *
     * Tests three size categories according to RFC 6455:
     * - Small (0-125 bytes): payload length is encoded in 7 bits
     * - Medium (126-65535 bytes): payload length 126 + 16-bit length
     * - Large (65536+ bytes): payload length 127 + 64-bit length
     */
    #[DataProvider('payloadSizesProvider')]
    public function testFramePackingWithDifferentSizes(int $size): void
    {
        // Create payload of specified size
        $payload = \str_repeat('A', $size);
        $frame = Frame::text($payload);

        // Convert frame to string (packed format)
        $packed = (string) $frame;

        // Verify the first byte (FIN + opcode)
        $firstByte = \ord($packed[0]);
        $this->assertSame(0x81, $firstByte, 'First byte should be 0x81 (FIN=1, opcode=Text)');

        // Verify the length encoding
        $secondByte = \ord($packed[1]);
        $payloadLen = $secondByte & 127;

        if ($size < 126) {
            // Small payload: length is in second byte
            $this->assertSame($size, $payloadLen, 'Small payload length should be in second byte');
            $headerSize = 2;
        } elseif ($size < 65536) {
            // Medium payload: second byte is 126, followed by 16-bit length
            $this->assertSame(126, $payloadLen, 'Medium payload should have 126 in second byte');
            $unpackedLen = \unpack('n', \substr($packed, 2, 2))[1];
            $this->assertSame($size, $unpackedLen, 'Medium payload length should match');
            $headerSize = 4; // 1 + 1 + 2
        } else {
            // Large payload: second byte is 127, followed by 64-bit length
            $this->assertSame(127, $payloadLen, 'Large payload should have 127 in second byte');
            // Unpack as two 32-bit integers in network byte order (big-endian)
            $parts = \unpack('N2', \substr($packed, 2, 8));
            $unpackedLen = ($parts[1] << 32) | $parts[2];
            $this->assertSame($size, $unpackedLen, 'Large payload length should match');
            $headerSize = 10; // 1 + 1 + 8
        }

        // Verify total frame size
        $this->assertSame($headerSize + $size, \strlen($packed), 'Total frame size should be header + payload');

        // Verify payload content
        $extractedPayload = \substr($packed, $headerSize);
        $this->assertSame($payload, $extractedPayload, 'Payload content should match');
    }

    /**
     * Test that frames can be unpacked by StreamReader correctly.
     */
    #[DataProvider('payloadSizesProvider')]
    public function testFrameUnpackingWithDifferentSizes(int $size): void
    {
        // Create payload of specified size
        $payload = \str_repeat('B', $size);
        $frame = Frame::text($payload);

        // Pack the frame
        $packed = (string) $frame;

        // Unpack using StreamReader
        $frames = \iterator_to_array(StreamReader::readFrames([$packed]));

        $this->assertCount(1, $frames, 'Should read exactly one frame');

        $unpackedFrame = $frames[0];
        $this->assertSame($payload, $unpackedFrame->content, 'Unpacked payload should match original');
        $this->assertSame(Opcode::Text, $unpackedFrame->opcode, 'Unpacked opcode should be Text');
        $this->assertTrue($unpackedFrame->fin, 'Unpacked frame should have FIN=true');
    }

    /**
     * Test round-trip: pack and unpack frames.
     */
    #[DataProvider('payloadSizesProvider')]
    public function testFrameRoundTrip(int $size): void
    {
        // Create payload of specified size
        $payload = \str_repeat('C', $size);
        $originalFrame = Frame::text($payload);

        // Pack and unpack
        $packed = (string) $originalFrame;
        $frames = \iterator_to_array(StreamReader::readFrames([$packed]));
        $unpackedFrame = $frames[0];

        // Verify round-trip
        $this->assertSame($originalFrame->content, $unpackedFrame->content, 'Content should survive round-trip');
        $this->assertSame($originalFrame->opcode, $unpackedFrame->opcode, 'Opcode should survive round-trip');
        $this->assertSame($originalFrame->fin, $unpackedFrame->fin, 'FIN should survive round-trip');
    }

    public static function payloadSizesProvider(): \Generator
    {
        // Small payloads (0-125 bytes)
        yield 'Empty payload' => [0];
        yield 'Small payload (10 bytes)' => [10];
        yield 'Small payload (125 bytes)' => [125];

        // Medium payloads (126-65535 bytes)
        yield 'Medium payload (126 bytes)' => [126];
        yield 'Medium payload (1000 bytes)' => [1000];
        yield 'Medium payload (65535 bytes)' => [65535];

        // Large payloads (65536+ bytes)
        yield 'Large payload (65536 bytes)' => [65536];
        yield 'Large payload (100000 bytes)' => [100000];
        // Note: Testing with very large payloads (GB+) would require too much memory
    }
}

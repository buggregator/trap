<?php

namespace Buggregator\Trap\Tests\Support;

use Buggregator\Trap\Support\ProtobufDTO;
use PHPUnit\Framework\TestCase;

class ProtobufDTOTest extends TestCase
{
    public function testCreateFromFalseMessage()
    {
        $message = new \Google\Protobuf\BoolValue();

        $dto = ProtobufDTO::createFromMessage($message);

        $this->assertSame('google.protobuf.BoolValue', $dto->name);
        $this->assertSame(\Google\Protobuf\BoolValue::class, $dto->class);
        $this->assertFalse($dto->value);
    }

    public function testCreateFromTrueMessage()
    {
        $message = (new \Google\Protobuf\BoolValue())->setValue(true);

        $dto = ProtobufDTO::createFromMessage($message);

        $this->assertSame('google.protobuf.BoolValue', $dto->name);
        $this->assertSame(\Google\Protobuf\BoolValue::class, $dto->class);
        $this->assertTrue($dto->value);
    }

    public function testCreateFromTimestampMessage()
    {
        $message = (new \Google\Protobuf\Timestamp())->setSeconds(1234567890)->setNanos(1234567890);

        $dto = ProtobufDTO::createFromMessage($message);

        $this->assertSame('google.protobuf.Timestamp', $dto->name);
        $this->assertSame(\Google\Protobuf\Timestamp::class, $dto->class);
        $this->assertSame('2009-02-13T23:31:30.1234567890Z', $dto->value);
    }

    public function testCreateFromDurationMessage()
    {
        $message = (new \Google\Protobuf\Duration())->setSeconds(1234567890)->setNanos(1234567890);

        $dto = ProtobufDTO::createFromMessage($message);

        $this->assertSame('google.protobuf.Duration', $dto->name);
        $this->assertSame(\Google\Protobuf\Duration::class, $dto->class);
        $this->assertSame('1234567891.234567890s', $dto->value);
    }
}

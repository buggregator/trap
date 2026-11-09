<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Ray;

use Buggregator\Trap\Module\Ray\Frame;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Covers(Frame::class)]
#[Test]
final class FrameTest
{
    public const REQUEST = '{"uuid":"0f0e1c2b-3a49-4c5d-8e6f-708192a3b4c5","payloads":[{"type":"log","content":{"values":["hi"],"meta":[]},"origin":{"file":"/app/index.php","line_number":7,"hostname":"box"}},{"type":"create_lock","content":{"name":"5d41402abc4b2a76b9719d911017c592"},"origin":{"file":"/app/index.php","line_number":8,"hostname":"box"}}],"meta":{"php_version":"8.4.1","project_name":"","ray_package_version":"1.48.0"}}';

    public static function invalidRequests(): iterable
    {
        yield 'not an object' => ['"text"'];
        yield 'no uuid' => ['{"payloads":[]}'];
        yield 'empty uuid' => ['{"uuid":"","payloads":[]}'];
        yield 'payloads not a list' => ['{"uuid":"a1","payloads":{"x":{"type":"log"}}}'];
        yield 'payload without type' => ['{"uuid":"a1","payloads":[{"content":[]}]}'];
        yield 'meta not an object' => ['{"uuid":"a1","payloads":[],"meta":"x"}'];
    }

    public function testParsesClientRequest(): void
    {
        $time = new \DateTimeImmutable('2026-01-02 03:04:05.678');
        $frame = Frame::fromString(self::REQUEST, $time);

        Assert::same($frame->type, 'ray');
        Assert::same($frame->time, $time);
        Assert::same($frame->uuid, '0f0e1c2b-3a49-4c5d-8e6f-708192a3b4c5');
        Assert::same(\array_column($frame->payloads, 'type'), ['log', 'create_lock']);
        Assert::same($frame->meta['ray_package_version'], '1.48.0');
        Assert::same($frame->lockNames(), ['5d41402abc4b2a76b9719d911017c592']);
    }

    public function testSerializesToTheClientFormat(): void
    {
        $frame = Frame::fromString(self::REQUEST, new \DateTimeImmutable());

        Assert::same(\json_decode((string) $frame, true), \json_decode(self::REQUEST, true));
        Assert::same((string) Frame::fromString((string) $frame, $frame->time), (string) $frame);
    }

    public function testKeepsEmptyMetaAnObject(): void
    {
        Assert::same((string) new Frame('a1', []), '{"uuid":"a1","payloads":[],"meta":{}}');
    }

    public function testMissingMetaIsEmpty(): void
    {
        Assert::same(Frame::fromString('{"uuid":"a1","payloads":[{"type":"log"}]}', new \DateTimeImmutable())->meta, []);
    }

    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidRequest(string $json): void
    {
        Expect::exception(\InvalidArgumentException::class);

        Frame::fromString($json, new \DateTimeImmutable());
    }

    public function testIgnoresLockWithoutName(): void
    {
        $frame = Frame::fromString('{"uuid":"a1","payloads":[{"type":"create_lock","content":{}}]}', new \DateTimeImmutable());

        Assert::same($frame->lockNames(), []);
    }
}

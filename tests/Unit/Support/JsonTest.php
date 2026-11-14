<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\Json;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Json::class)]
final class JsonTest
{
    public function encodesSlashesAndUnicodeAsTheyAre(): void
    {
        Assert::same(Json::encode(['path' => '/tmp/файл']), '{"path":"/tmp/файл"}');
    }

    public function substitutesInvalidUtf8(): void
    {
        Assert::same(Json::encode("a\xFFb"), "\"a\u{FFFD}b\"");
    }

    public function addsGivenFlags(): void
    {
        Assert::same(Json::encode([], \JSON_FORCE_OBJECT), '{}');
    }

    public function throwsOnTooDeepValue(): never
    {
        Expect::exception(\JsonException::class);

        Json::encode([[1]], depth: 1);
    }

    public function decodesObjectsToArrays(): void
    {
        Assert::same(Json::decode('{"a":{"b":[1,2]}}'), ['a' => ['b' => [1, 2]]]);
    }

    public function throwsOnInvalidJson(): never
    {
        Expect::exception(\JsonException::class);

        Json::decode('{"a":');
    }
}

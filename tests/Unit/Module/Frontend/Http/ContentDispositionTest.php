<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Http;

use Buggregator\Trap\Module\Frontend\Http\ContentDisposition;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(ContentDisposition::class)]
#[Test]
final class ContentDispositionTest
{
    #[DataSet(['doc.pdf', 'attachment; filename="doc.pdf"; filename*=UTF-8\'\'doc.pdf'])]
    #[DataSet(['my file.txt', 'attachment; filename="my file.txt"; filename*=UTF-8\'\'my%20file.txt'])]
    #[DataSet(['a"b\\c.txt', 'attachment; filename="a\\"b\\\\c.txt"; filename*=UTF-8\'\'a%22b%5Cc.txt'])]
    #[DataSet(['отчёт.pdf', 'attachment; filename="_____.pdf"; filename*=UTF-8\'\'%D0%BE%D1%82%D1%87%D1%91%D1%82.pdf'])]
    public function testNameIsQuotedAndEncoded(string $name, string $expected): void
    {
        Assert::same(ContentDisposition::attachment($name), $expected);
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mailer;

use Buggregator\Trap\Module\Mailer\EncodedWord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(EncodedWord::class)]
final class EncodedWordTest
{
    public static function dataDecode(): iterable
    {
        yield 'plain text' => ['Hello, world', 'Hello, world'];
        yield 'Q encoding' => ['=?utf-8?Q?Gr=C3=BC=C3=9Fe_aus_Berlin?=', 'Grüße aus Berlin'];
        yield 'B encoding' => ['=?UTF-8?B?0J/RgNC40LLQtdGCLCDQvNC40YA=?=', 'Привет, мир'];
        yield 'lowercase encoding letter' => ['=?utf-8?b?0JzQuNGA?=', 'Мир'];
        yield 'raw UTF-8 in Q text' => ['=?utf-8?Q?Привет=2C_мир?=', 'Привет, мир'];
        yield 'text around a word' => ['Re: =?utf-8?Q?Gr=C3=BC=C3=9Fe?= again', 'Re: Grüße again'];
        yield 'whitespace between words is dropped' => ['=?utf-8?Q?a?= =?utf-8?Q?b?=', 'ab'];
        yield 'folded header' => ["=?utf-8?Q?Hello=2C?=\t =?utf-8?Q?_world?=", 'Hello, world'];
        yield 'character split between B words' => ['=?utf-8?B?0J8=?= =?utf-8?B?0YA=?=', 'Пр'];
        yield 'whitespace between plain words stays' => ['a  b', 'a  b'];
        yield 'ISO-8859-1' => ['=?ISO-8859-1?Q?Caf=E9?=', 'Café'];
        yield 'Windows-1251' => ['=?windows-1251?B?z/Do4uXy?=', 'Привет'];
        yield 'KOI8-R and UTF-8 words' => ['=?koi8-r?B?8NLJ18XU?= =?utf-8?Q?=2C_=D0=BC=D0=B8=D1=80?=', 'Привет, мир'];
        yield 'language suffix' => ['=?utf-8*en?Q?Hello?=', 'Hello'];
        yield 'broken base64 stays' => ['=?utf-8?B?***?=', '=?utf-8?B?***?='];
        yield 'not an encoded word' => ['=?utf-8?X?abc?=', '=?utf-8?X?abc?='];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('dataDecode')]
    public function testDecode(string $value, string $expected): void
    {
        Assert::same(EncodedWord::decode($value), $expected);
    }
}

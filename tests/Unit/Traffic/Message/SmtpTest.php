<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Message;

use Buggregator\Trap\Traffic\Message\Smtp;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
final class SmtpTest
{
    public static function dataTo(): iterable
    {
        yield [
            ['Mary Smith <mary@example.net>'],
            [
                new Smtp\Contact('Mary Smith', 'mary@example.net'),
            ],
        ];

        yield [
            ['Mary Smith <mary@x.test>, jdoe@example.org, Who? <one@y.test>'],
            [
                new Smtp\Contact('Mary Smith', 'mary@x.test'),
                new Smtp\Contact(null, 'jdoe@example.org'),
                new Smtp\Contact('Who?', 'one@y.test'),

            ],
        ];

        yield [
            ['A Group:Chris Jones <c@a.test>,joe@where.test,John <jdoe@one.test>;'],
            [
                new Smtp\Contact('Chris Jones', 'c@a.test'),
                new Smtp\Contact(null, 'joe@where.test'),
                new Smtp\Contact('John', 'jdoe@one.test'),
            ],
        ];

        yield [
            ['"Mary Smith: Personal Account" <smith@home.example>'],
            [
                new Smtp\Contact('Mary Smith: Personal Account', 'smith@home.example'),
            ],
        ];

        yield [
            ['" Mary Smith: Personal Account " <smith@home.example>'],
            [
                new Smtp\Contact(' Mary Smith: Personal Account ', 'smith@home.example'),
            ],
        ];

        yield [
            ['"Agency \"Buggregator\"" <smith@home.example>'],
            [
                new Smtp\Contact('Agency "Buggregator"', 'smith@home.example'),
            ],
        ];
    }

    #[DataProvider('dataTo')]
    public function testTo(array $toList, array $expected): void
    {
        $smtp = Smtp::create(
            protocol: [],
            headers: [
                'To' => $toList,
                'CC' => $toList,
                'Reply-To' => $toList,
            ],
        );
        Assert::equals($smtp->getTo(), $expected);
        Assert::equals($smtp->getCc(), $expected);
        Assert::equals($smtp->getReplyTo(), $expected);
    }
}

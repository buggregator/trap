<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Message;

use Buggregator\Trap\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use Buggregator\Trap\Traffic\Message\Smtp;
use Buggregator\Trap\Traffic\Message\Smtp\MessageFormat;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Smtp::class)]
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

    public function testFromArrayRestoresMessagesAndAttachments(): void
    {
        $data = [
            'protocol' => ['FROM' => ['<sender@example.com>'], 'BCC' => ['hidden@example.com']],
            'headers' => ['Subject' => ['Hello']],
            'messages' => [
                ['headers' => ['Content-Type' => ['text/plain']], 'value' => 'plain body'],
            ],
            'attachments' => [
                ['headers' => ['Content-Type' => ['image/png']], 'name' => 'logo', 'fileName' => 'logo.png', 'size' => 42],
            ],
        ];

        $smtp = Smtp::fromArray($data);

        Assert::same($smtp->getProtocol(), $data['protocol']);
        Assert::same($smtp->getSubject(), 'Hello');
        Assert::count($smtp->getMessages(), 1);
        Assert::same($smtp->getMessages()[0]->getValue(), 'plain body');
        Assert::count($smtp->getAttachments(), 1);
        Assert::same($smtp->getAttachments()[0]->getClientFilename(), 'logo.png');
        Assert::same($smtp->getAttachments()[0]->getSize(), 42);
        Assert::same(\json_decode(\json_encode($smtp), true), $data);
    }

    public function testFromArrayWithoutMessagesAndAttachments(): void
    {
        $smtp = Smtp::fromArray(['protocol' => [], 'headers' => [], 'messages' => [], 'attachments' => []]);

        Assert::same($smtp->getMessages(), []);
        Assert::same($smtp->getAttachments(), []);
        Assert::same($smtp->getProtocol(), []);
    }

    public function testWithMessagesReturnsNewInstance(): void
    {
        $smtp = Smtp::create([], []);
        $messages = [new Field(['Content-Type' => ['text/plain']], value: 'body')];

        $clone = $smtp->withMessages($messages);

        Assert::notSame($clone, $smtp);
        Assert::same($clone->getMessages(), $messages);
        Assert::same($smtp->getMessages(), []);
    }

    public function testWithAttachmentsReturnsNewInstance(): void
    {
        $smtp = Smtp::create([], []);
        $attachments = [new File(['Content-Type' => ['image/png']], 'logo', 'logo.png')];

        $clone = $smtp->withAttachments($attachments);

        Assert::notSame($clone, $smtp);
        Assert::same($clone->getAttachments(), $attachments);
        Assert::same($smtp->getAttachments(), []);
    }

    public function testGetSenderMergesProtocolAndHeaderWithoutDuplicates(): void
    {
        $smtp = Smtp::create(
            protocol: ['FROM' => ['John Doe <john@example.com>']],
            headers: ['From' => ['John Doe <john@example.com>', 'jane@example.com']],
        );

        Assert::equals(\array_values($smtp->getSender()), [
            new Smtp\Contact('John Doe', 'john@example.com'),
            new Smtp\Contact(null, 'jane@example.com'),
        ]);
    }

    public function testGetBccReadsProtocolRecipientsOnly(): void
    {
        $smtp = Smtp::create(
            protocol: ['BCC' => ['Hidden <hidden@example.com>, other@example.com']],
            headers: ['Bcc' => ['ignored@example.com']],
        );

        Assert::equals($smtp->getBcc(), [
            new Smtp\Contact('Hidden', 'hidden@example.com'),
            new Smtp\Contact(null, 'other@example.com'),
        ]);
    }

    public function testGetSenderIsEmptyWithoutFrom(): void
    {
        Assert::same(Smtp::create([], [])->getSender(), []);
    }

    #[DataSet([['Hello'], 'Hello'], 'single subject header')]
    #[DataSet([['Hello', 'World'], 'Hello World'], 'multiple subject headers are joined')]
    #[DataSet([[], ''], 'no subject header')]
    public function testGetSubject(array $subject, string $expected): void
    {
        $smtp = Smtp::create([], $subject === [] ? [] : ['Subject' => $subject]);

        Assert::same($smtp->getSubject(), $expected);
    }

    #[DataSet([MessageFormat::Plain, 'plain'], 'plain text part')]
    #[DataSet([MessageFormat::Html, 'html'], 'html part matched case-insensitively')]
    #[DataSet([MessageFormat::Watch, null], 'missing format')]
    public function testGetMessageByFormat(MessageFormat $format, ?string $expected): void
    {
        $smtp = Smtp::create([], [])->withMessages([
            new Field(['Content-Type' => ['text/plain; charset=utf-8']], value: 'plain'),
            new Field(['Content-Type' => ['TEXT/HTML']], value: 'html'),
        ]);

        $message = $smtp->getMessage($format);

        Assert::same($message?->getValue(), $expected);
    }
}

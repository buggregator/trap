<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Parser;

use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Traffic\Message;
use Buggregator\Trap\Traffic\Parser;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Covers(Parser\Smtp::class)]
final class SmtpParserTest
{
    use FiberTrait;

    public static function provideHeaders()
    {
        yield [
            "To: User1 <gam6itko@mail.ru>, User2 <gam6itko@gmail.com>, User3\r\n <gam6itko@yandex.ru>\r\n",
            ['To' => ['User1 <gam6itko@mail.ru>, User2 <gam6itko@gmail.com>, User3 <gam6itko@yandex.ru>']],
        ];
    }

    #[DataProvider('provideHeaders')]
    #[Test]
    public function testParseHeader(string $headerString, array $expected): void
    {
        Assert::same(Parser\Smtp::parseHeaders($headerString), $expected);
    }

    #[Test]
    public function testMultilineHeaders(): void
    {
        $data = \str_split(<<<SMTP
            To: User1 <gam6itko@mail.ru>, User2 <gam6itko@gmail.com>, User3\r
             <gam6itko@yandex.ru>\r
            Subject: ABCDEFGHIJKLMNOPQRSTUVWXYZ- ABCDEFGHIJKLMNOPQRSTUVWXYZ-\r
             ABCDEFGHIJKLMNOPQRSTUVWXYZ- ABCDEFGHIJKLMNOPQRSTUVWXYZ-\r
             ABCDEFGHIJKLMNOPQRSTUVWXYZ-\r
            From: sandbox producer <test@producer.sandbox>\r
            Content-Type: multipart/alternative; boundary=_ZwZ4SBw\r
            \r
            --_ZwZ4SBw\r
            Content-Type: text/plain; charset=utf-8\r
            Content-Transfer-Encoding: quoted-printable\r
            \r
            Hello from Producer!\r
            \r
            --_ZwZ4SBw\r
            Content-Type: text/html; charset=utf-8\r
            Content-Transfer-Encoding: quoted-printable\r
            \r
                <h1>Hello from Producer!</h1>\r
            \r
            --_ZwZ4SBw--\r
            \r\n.\r\n
            SMTP, 10);
        $message = $this->parse($data);

        Assert::same((string) $message->getBody(), \implode('', $data));
        Assert::count($message->getMessages(), 2);
        // Check headers
        Assert::equals($message->getHeaders(), [
            'From' => ['sandbox producer <test@producer.sandbox>'],
            'To' => ['User1 <gam6itko@mail.ru>, User2 <gam6itko@gmail.com>, User3 <gam6itko@yandex.ru>'],
            'Subject' => ['ABCDEFGHIJKLMNOPQRSTUVWXYZ- ABCDEFGHIJKLMNOPQRSTUVWXYZ- ABCDEFGHIJKLMNOPQRSTUVWXYZ- ABCDEFGHIJKLMNOPQRSTUVWXYZ- ABCDEFGHIJKLMNOPQRSTUVWXYZ-'],
            'Content-Type' => ['multipart/alternative; boundary=_ZwZ4SBw'],
        ]);
        // Check body
        Assert::same($message->getMessages()[0]->getValue(), "Hello from Producer!\r\n");
        Assert::same($message->getMessages()[1]->getValue(), "    <h1>Hello from Producer!</h1>\r\n");
    }

    #[Test]
    public function testParseSimpleBody(): void
    {
        $data = \str_split(<<<SMTP
                               From: Some User <someusername@somecompany.ru>\r
                               To: User1 <user1@company.tld>\r
                               Subject: Very important theme\r
                               Content-Type: text/plain\r
                               \r
                               Hi!\r
                               .\r\n
                               SMTP, 10);
        $message = $this->parse($data);

        Assert::same((string) $message->getBody(), \implode('', $data));
        Assert::count($message->getMessages(), 1);
        // Check headers
        Assert::equals($message->getHeaders(), [
            'From' => ['Some User <someusername@somecompany.ru>'],
            'To' => ['User1 <user1@company.tld>'],
            'Subject' => ['Very important theme'],
            'Content-Type' => ['text/plain'],
        ]);
        // Check body
        Assert::same($message->getMessages()[0]->getValue(), 'Hi!');
    }

    #[Test]
    public function testParseMultipart(): void
    {
        $data = \str_split(<<<SMTP
                               From: sender@example.com\r
                               To: recipient@example.com\r
                               Subject: Multipart Email Example\r
                               Content-Type: multipart/alternative; boundary="boundary-string"\r
                               \r
                               --boundary-string\r
                               Content-Type: text/plain; charset="utf-8"\r
                               Content-Transfer-Encoding: quoted-printable\r
                               Content-Disposition: inline\r
                               \r
                               Plain text email goes here!\r
                               This is the fallback if email client does not support HTML\r
                               \r
                               --boundary-string\r
                               Content-Type: text/html; charset="utf-8"\r
                               Content-Transfer-Encoding: quoted-printable\r
                               Content-Disposition: inline\r
                               \r
                               <h1>This is the HTML Section!</h1>\r
                               <p>This is what displays in most modern email clients</p>\r
                               \r
                               --boundary-string--\r
                               Content-Type: image/x-icon\r
                               Content-Transfer-Encoding: base64\r
                               Content-Disposition: attachment;filename=logo.ico\r
                               \r
                               123456789098765432123456789\r
                               \r
                               --boundary-string--\r
                               Content-Type: text/watch-html; charset="utf-8"\r
                               Content-Transfer-Encoding: quoted-printable\r
                               Content-Disposition: inline\r
                               \r
                               <b>Apple Watch formatted content</b>\r
                               \r
                               --boundary-string--\r\n\r\n
                               SMTP, 10);
        $message = $this->parse($data, [
            'FROM' => ['<someusername@foo.bar>'],
            'BCC' => ['<user1@company.tld>', '<user2@company.tld>'],
        ]);

        // Check contacts

        // Sender
        Assert::count($message->getSender(), 2);
        Assert::null($message->getSender()[0]->name);
        Assert::same($message->getSender()[0]->email, 'someusername@foo.bar');
        Assert::null($message->getSender()[1]->name);
        Assert::same($message->getSender()[1]->email, 'sender@example.com');
        // BCC
        Assert::count($message->getBcc(), 2);
        Assert::null($message->getBcc()[0]->name);
        Assert::same($message->getBcc()[0]->email, 'user1@company.tld');
        Assert::null($message->getBcc()[1]->name);
        Assert::same($message->getBcc()[1]->email, 'user2@company.tld');

        Assert::same((string) $message->getBody(), \implode('', $data));
        Assert::count($message->getMessages(), 3);
        // Check headers
        Assert::equals($message->getHeaders(), [
            'From' => ['sender@example.com'],
            'To' => ['recipient@example.com'],
            'Subject' => ['Multipart Email Example'],
            'Content-Type' => ['multipart/alternative; boundary="boundary-string"'],
        ]);

        // Check bodies

        // Body 0
        Assert::same($message->getMessages()[0]->getValue(), "Plain text email goes here!\r\nThis is the fallback if email client does not support HTML\r\n");
        Assert::equals($message->getMessages()[0]->getHeaders(), [
            'Content-Type' => ['text/plain; charset="utf-8"'],
            'Content-Transfer-Encoding' => ['quoted-printable'],
            'Content-Disposition' => ['inline'],
        ]);

        // Body 1
        Assert::same($message->getMessages()[1]->getValue(), "<h1>This is the HTML Section!</h1>\r\n<p>This is what displays in most modern email clients</p>\r\n");
        Assert::equals($message->getMessages()[1]->getHeaders(), [
            'Content-Type' => ['text/html; charset="utf-8"'],
            'Content-Transfer-Encoding' => ['quoted-printable'],
            'Content-Disposition' => ['inline'],
        ]);

        // Body 2
        Assert::same($message->getMessages()[2]->getValue(), "<b>Apple Watch formatted content</b>\r\n");
        Assert::equals($message->getMessages()[2]->getHeaders(), [
            'Content-Type' => ['text/watch-html; charset="utf-8"'],
            'Content-Transfer-Encoding' => ['quoted-printable'],
            'Content-Disposition' => ['inline'],
        ]);

        // Check attachments
        Assert::count($message->getAttachments(), 1);
    }

    private function parse(array|string $body, array $protocol = []): Message\Smtp
    {
        $stream = StreamClientMock::createFromGenerator(
            (static function () use ($body) {
                if (\is_string($body)) {
                    yield $body;
                    return;
                }
                yield from $body;
            })(),
        );
        return $this->runInFiber(static fn() => (new Parser\Smtp())->parseStream($protocol, $stream));
    }
}

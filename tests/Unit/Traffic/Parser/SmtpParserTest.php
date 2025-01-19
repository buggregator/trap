<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Parser;

use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Traffic\Message;
use Buggregator\Trap\Traffic\Parser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Parser\Smtp::class)]
final class SmtpParserTest extends TestCase
{
    use FiberTrait;

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

        self::assertSame(\implode('', $data), (string) $message->getBody());
        self::assertCount(1, $message->getMessages());
        // Check headers
        self::assertEquals([
            'From' => ['Some User <someusername@somecompany.ru>'],
            'To' => ['User1 <user1@company.tld>'],
            'Subject' => ['Very important theme'],
            'Content-Type' => ['text/plain'],
        ], $message->getHeaders());
        // Check body
        self::assertSame('Hi!', $message->getMessages()[0]->getValue());
    }

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
        self::assertCount(2, $message->getSender());
        self::assertNull($message->getSender()[0]->name);
        self::assertSame('someusername@foo.bar', $message->getSender()[0]->email);
        self::assertNull($message->getSender()[1]->name);
        self::assertSame('sender@example.com', $message->getSender()[1]->email);
        // BCC
        self::assertCount(2, $message->getBcc());
        self::assertNull($message->getBcc()[0]->name);
        self::assertSame('user1@company.tld', $message->getBcc()[0]->email);
        self::assertNull($message->getBcc()[1]->name);
        self::assertSame('user2@company.tld', $message->getBcc()[1]->email);

        self::assertSame(\implode('', $data), (string) $message->getBody());
        self::assertCount(3, $message->getMessages());
        // Check headers
        self::assertEquals([
            'From' => ['sender@example.com'],
            'To' => ['recipient@example.com'],
            'Subject' => ['Multipart Email Example'],
            'Content-Type' => ['multipart/alternative; boundary="boundary-string"'],
        ], $message->getHeaders());

        // Check bodies

        // Body 0
        self::assertSame(
            "Plain text email goes here!\r\nThis is the fallback if email client does not support HTML\r\n",
            $message->getMessages()[0]->getValue(),
        );
        self::assertEquals([
            'Content-Type' => ['text/plain; charset="utf-8"'],
            'Content-Transfer-Encoding' => ['quoted-printable'],
            'Content-Disposition' => ['inline'],
        ], $message->getMessages()[0]->getHeaders());

        // Body 1
        self::assertSame(
            "<h1>This is the HTML Section!</h1>\r\n<p>This is what displays in most modern email clients</p>\r\n",
            $message->getMessages()[1]->getValue(),
        );
        self::assertEquals([
            'Content-Type' => ['text/html; charset="utf-8"'],
            'Content-Transfer-Encoding' => ['quoted-printable'],
            'Content-Disposition' => ['inline'],
        ], $message->getMessages()[1]->getHeaders());

        // Body 2
        self::assertSame(
            "<b>Apple Watch formatted content</b>\r\n",
            $message->getMessages()[2]->getValue(),
        );
        self::assertEquals([
            'Content-Type' => ['text/watch-html; charset="utf-8"'],
            'Content-Transfer-Encoding' => ['quoted-printable'],
            'Content-Disposition' => ['inline'],
        ], $message->getMessages()[2]->getHeaders());

        // Check attachments
        self::assertCount(1, $message->getAttachments());
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

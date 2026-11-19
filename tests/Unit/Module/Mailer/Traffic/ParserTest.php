<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mailer\Traffic;

use Buggregator\Trap\Module\Mailer\Message;
use Buggregator\Trap\Module\Mailer\Traffic\Parser;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataCross;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Data\DataUnion;
use Testo\Test;

#[Covers(Parser::class)]
final class ParserTest
{
    use FiberTrait;

    public static function provideHeaders()
    {
        yield [
            "To: User1 <gam6itko@mail.ru>, User2 <gam6itko@gmail.com>, User3\r\n <gam6itko@yandex.ru>\r\n",
            ['To' => ['User1 <gam6itko@mail.ru>, User2 <gam6itko@gmail.com>, User3 <gam6itko@yandex.ru>']],
        ];
    }

    /**
     * Texts are `[Content-Type prefix, substring of the value]`,
     * attachments are `[file name, media type, embedding ID, decoded size]`.
     */
    public static function provideNestedMultipartEmails(): iterable
    {
        $plain = ['text/plain', 'Hello from the plain text part.'];
        $html = ['text/html', 'Hello from the <b>HTML</b> part.'];
        $pdf = ['report.pdf', 'application/pdf', null, 77];

        // Quoted-printable splits the `cid:` link with a soft line break and encodes `=` as `=3D`
        yield 'Symfony Mailer: mixed > related > alternative' => [
            'symfony-mixed-related-alternative.eml',
            [$plain, ['text/html', '<img src="cid:92fd177dacc4e60bbdcdd851b24be016@symfony">']],
            [['logo', 'image/png', '92fd177dacc4e60bbdcdd851b24be016@symfony', 70], $pdf],
        ];
        yield 'Symfony Mailer: alternative' => ['symfony-alternative.eml', [$plain, $html], []];
        yield 'Symfony Mailer: related without alternative' => [
            'symfony-related.eml',
            [['text/html', '<img src="cid:a494a0178b1158463e93077436c5493f@symfony">']],
            [['logo', 'image/png', 'a494a0178b1158463e93077436c5493f@symfony', 70]],
        ];
        yield 'Thunderbird: preamble, folded headers, mixed > alternative > related, non-ASCII file name' => [
            'thunderbird.eml',
            [$plain, $html],
            [
                ['logo.png', 'image/png', 'part1.Hq3vO9aL.T2nYk8cE@example.com', 70],
                $pdf,
                ['отчёт.txt', 'text/plain', null, 10],
            ],
        ];
        yield 'Outlook: mixed > related > alternative' => [
            'outlook.eml',
            [$plain, ['text/html', '<p class="MsoNormal"><img width="1" height="1"']],
            [['image001.png', 'image/png', 'image001.png@01DB3A7C.5E2F1B30', 70], $pdf],
        ];
        yield 'Gmail: attachments with a Content-ID' => [
            'gmail.eml',
            [$plain, $html],
            [['logo.png', 'image/png', 'ii_m3p9x1ab0', 70], $pdf, ['photo.png', 'image/png', null, 70]],
        ];
        yield 'Apple Mail: alternative > mixed, inline PDF between two HTML parts' => [
            'apple-mail.eml',
            [$plain, $html, ['text/html', '<div><br></div>']],
            [['report.pdf', 'application/pdf', '1A2B3C4D-5E6F-4A7B-8C9D-0E1F2A3B4C5D', 77]],
        ];
        yield 'calendar invitation: text/calendar alternative' => [
            'calendar-invite.eml',
            [$plain, $html],
            [['invite.ics', 'text/calendar', null, 93], ['invite.ics', 'application/ics', null, 93]],
        ];
        yield 'four levels of multipart' => [
            'deeply-nested.eml',
            [$plain, $html],
            [
                [null, 'image/png', 'logo@example.com', 70],
                ['notes.txt', 'text/plain', null, 30],
                $pdf,
            ],
        ];
        yield 'Outlook Express: files named in Content-Type only' => [
            'name-in-content-type.eml',
            [$plain, $html],
            [
                ['logo.png', 'image/png', 'logo.png', 70],
                $pdf,
                ['отчёт.pdf', 'application/pdf', null, 77],
                ['заметки.txt', 'text/plain', null, 8],
            ],
        ];
        yield 'message/rfc822 stays an attachment' => [
            'forwarded-as-attachment.eml',
            [['text/plain', 'The original email is attached.']],
            [['original.eml', 'message/rfc822', null, 373]],
        ];
    }

    /**
     * `[Content-Type, Content-Transfer-Encoding or null, encoded body, decoded UTF-8 text]`.
     */
    public static function provideEncodedTexts(): iterable
    {
        yield 'quoted-printable: soft line break and =3D' => [
            'text/html; charset=utf-8',
            'quoted-printable',
            "<img src=3D\"cid:92fd177dacc4=\r\ne60bbdcd@symfony\">\r\nnext line",
            "<img src=\"cid:92fd177dacc4e60bbdcd@symfony\">\r\nnext line",
        ];
        yield 'quoted-printable: lowercase hex' => ['text/plain; charset=utf-8', 'quoted-printable', 'caf=c3=a9', 'café'];
        yield 'quoted-printable: encoding name in odd case' => ['text/plain', 'Quoted-Printable', 'a=3Db', 'a=b'];
        yield 'base64: lines of 76 characters' => [
            'text/html; charset=UTF-8',
            'base64',
            \chunk_split(\base64_encode(\str_repeat('<p>Привет, мир!</p>', 5)), 76, "\r\n"),
            \str_repeat('<p>Привет, мир!</p>', 5),
        ];
        yield 'base64: encoding name in upper case' => ['text/plain', ' BASE64 ', \base64_encode('decoded'), 'decoded'];
        yield 'charset: iso-8859-1, quoted-printable' => ['text/plain; charset=iso-8859-1', 'quoted-printable', 'caf=E9', 'café'];
        yield 'charset: quoted windows-1251, base64' => [
            'text/plain; charset="windows-1251"',
            'base64',
            \base64_encode("\xCF\xF0\xE8\xE2\xE5\xF2"),
            'Привет',
        ];
        yield 'charset: KOI8-R, 8bit' => ['text/plain; charset=KOI8-R', '8bit', "\xF0\xD2\xC9\xD7\xC5\xD4", 'Привет'];
        yield 'type in upper case' => ['TEXT/PLAIN; CHARSET=US-ASCII', '7BIT', 'Hello from Pine', 'Hello from Pine'];
        yield 'type in mixed case' => ['Text/Html; Charset=ISO-8859-1', 'quoted-printable', '<p class=3D"x">caf=E9</p>', '<p class="x">café</p>'];
        yield 'charset: Outlook name of Korean' => [
            'text/plain; charset="ks_c_5601-1987"',
            'base64',
            \base64_encode("\xC7\xD1\xB1\xB9"),
            '한국',
        ];
        yield 'charset: logical-order Hebrew' => ['text/plain; charset=iso-8859-8-i', 'quoted-printable', '=F9=EC=E5=ED', 'שלום'];
        yield 'charset: unknown keeps the bytes' => ['text/plain; charset=x-unknown', '8bit', "caf\xE9", "caf\xE9"];
        yield '7bit: untouched' => ['text/plain; charset=us-ascii', '7bit', 'a=3Db', 'a=3Db'];
        yield '8bit: untouched' => ['text/plain; charset=utf-8', '8bit', 'café =3D', 'café =3D'];
        yield 'no Content-Transfer-Encoding: untouched' => ['text/plain', null, 'a=3Db', 'a=3Db'];
        yield 'unknown Content-Transfer-Encoding: untouched' => ['text/plain', 'x-uuencode', 'a=3Db', 'a=3Db'];
        yield 'quoted-printable: LF-only soft line breaks' => [
            'text/plain; charset=utf-8',
            'quoted-printable',
            "Gr=C3=BC=\n=C3=9Fe, so=\nft",
            'Grüße, soft',
        ];
        yield 'charset: windows-1250, unknown to mbstring' => [
            'text/plain; charset=windows-1250',
            'quoted-printable',
            '=C8esk=FD',
            'Český',
        ];
        yield 'charset: in a quoted value of another parameter' => [
            'text/plain; name="x; charset=koi8-r"; charset=iso-8859-1',
            '8bit',
            "Gr\xFC\xDFe",
            'Grüße',
        ];
        yield 'charset: spaces around `=` and single quotes' => [
            "text/plain; charset = 'ISO-8859-1' ; format=flowed",
            '8bit',
            "Gr\xFC\xDFe",
            'Grüße',
        ];
        yield 'charset: uuencode is no charset' => ['text/plain; charset=uuencode', '7bit', 'begin 644 x', 'begin 644 x'];
        yield 'charset: html-entities is no charset' => [
            'text/html; charset=HTML-ENTITIES',
            '7bit',
            '&lt;b&gt; &amp;',
            '&lt;b&gt; &amp;',
        ];
        yield 'charset: a list is no charset' => ['text/plain; charset="koi8-r,utf-8"', '7bit', 'abc', 'abc'];
    }

    public static function provideEncodedAttachments(): iterable
    {
        yield 'quoted-printable' => ['Quoted-Printable', "a=3Db, a long line that the client brea=\r\nks"];
        yield 'quoted-printable with LF-only soft line breaks' => [
            'quoted-printable',
            "a=3Db, a long line that the client brea=\nks",
        ];
        yield 'quoted-printable ending with an escape' => ['quoted-printable', 'a=3Db, a long line that the client break=73'];
        yield 'base64 in upper case' => [
            'BASE64',
            \chunk_split(\base64_encode('a=b, a long line that the client breaks'), 12, "\r\n"),
        ];
    }

    /**
     * `[headers of the file part, file name]`.
     */
    public static function provideNamedFiles(): iterable
    {
        yield 'Content-Type name, quoted' => ["Content-Type: application/pdf; name=\"my report.pdf\"\r\n", 'my report.pdf'];
        yield 'Content-Type name, unquoted' => ["Content-Type: application/pdf; name=report.pdf\r\n", 'report.pdf'];
        yield 'Content-Type name, folded and in upper case' => [
            "Content-Type: application/pdf;\r\n\tNAME=\"report.pdf\"\r\n",
            'report.pdf',
        ];
        yield 'Content-Type name*, UTF-8' => [
            "Content-Type: application/pdf; name*=UTF-8''%D0%BE%D1%82%D1%87%D1%91%D1%82.pdf\r\n",
            'отчёт.pdf',
        ];
        yield 'Content-Type name*, windows-1251' => [
            "Content-Type: application/pdf; name*=windows-1251''%EE%F2%F7%B8%F2.pdf\r\n",
            'отчёт.pdf',
        ];
        yield 'Content-Type name*, continuations' => [
            "Content-Type: application/pdf;\r\n name*0*=UTF-8''%D0%BE%D1%82;\r\n name*1*=%D1%87%D1%91%D1%82;\r\n name*2=\".pdf\"\r\n",
            'отчёт.pdf',
        ];
        yield 'Content-Type name*, wins over name' => [
            "Content-Type: application/pdf; name=\"otchet.pdf\"; name*=UTF-8''%D0%BE%D1%82%D1%87%D1%91%D1%82.pdf\r\n",
            'отчёт.pdf',
        ];
        yield 'Content-Type name, base64 encoded word' => [
            "Content-Type: application/pdf; name=\"=?UTF-8?B?0L7RgtGH0ZHRgi5wZGY=?=\"\r\n",
            'отчёт.pdf',
        ];
        yield 'Content-Type name, encoded words split in two' => [
            "Content-Type: application/pdf;\r\n name=\"=?UTF-8?B?0L7RgtGH?=\r\n =?UTF-8?B?0ZHRgi5wZGY=?=\"\r\n",
            'отчёт.pdf',
        ];
        yield 'Content-Type name, quoted-printable encoded word' => [
            "Content-Type: application/pdf; name=\"=?iso-8859-1?Q?caf=E9.pdf?=\"\r\n",
            'café.pdf',
        ];
        yield 'bare attachment disposition, Content-Type name' => [
            "Content-Type: application/pdf; name=\"report.pdf\"\r\nContent-Disposition: attachment\r\n",
            'report.pdf',
        ];
        yield 'Content-Disposition filename wins over Content-Type name' => [
            "Content-Type: application/pdf; name=\"name.pdf\"\r\nContent-Disposition: attachment; filename=\"file.pdf\"\r\n",
            'file.pdf',
        ];
        yield 'Content-Disposition filename, encoded word' => [
            "Content-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"=?UTF-8?B?0L7RgtGH0ZHRgi5wZGY=?=\"\r\n",
            'отчёт.pdf',
        ];
        yield 'no name' => ["Content-Type: application/pdf\r\n", null];
        yield 'empty name' => ["Content-Type: application/pdf; name=\"\"\r\n", null];
    }

    #[DataProvider('provideEncodedAttachments')]
    #[Test]
    public function decodesTextAttachment(string $encoding, string $body): void
    {
        $message = $this->parse(
            "Content-Type: multipart/mixed; boundary=o\r\n\r\n"
            . "--o\r\nContent-Type: text/plain\r\n\r\nSee the notes.\r\n"
            . "--o\r\nContent-Type: text/plain; name=notes.txt\r\nContent-Transfer-Encoding: {$encoding}\r\n"
            . "Content-Disposition: attachment; filename=notes.txt\r\n\r\n{$body}\r\n--o--\r\n.\r\n",
        );

        Assert::count($message->getAttachments(), 1);
        Assert::same($message->getAttachments()[0]->getClientFilename(), 'notes.txt');
        Assert::same(
            (string) $message->getAttachments()[0]->getStream(),
            'a=b, a long line that the client breaks',
        );
    }

    #[Test]
    public function keepsPartsAfterMalformedQuotedPrintableAttachment(): void
    {
        $message = $this->parse(
            "Content-Type: multipart/mixed; boundary=o\r\n\r\n"
            . "--o\r\nContent-Type: text/plain; name=notes.txt\r\nContent-Transfer-Encoding: quoted-printable\r\n"
            . "Content-Disposition: attachment; filename=notes.txt\r\n\r\nx = y, a=zz b, abc= \r\ndef\r\n"
            . "--o\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "<p>Gr=C3=BC=C3=9Fe</p>\r\n--o--\r\n.\r\n",
        );

        Assert::count($message->getAttachments(), 1);
        Assert::same((string) $message->getAttachments()[0]->getStream(), 'x = y, a=zz b, abcdef');
        Assert::count($message->getMessages(), 1);
        Assert::same($message->getMessages()[0]->getValue(), '<p>Grüße</p>');
    }

    #[DataProvider('provideHeaders')]
    #[Test]
    public function testParseHeader(string $headerString, array $expected): void
    {
        Assert::same(Parser::parseHeaders($headerString), $expected);
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

        Assert::same((string) $message->getBody(), \substr(\implode('', $data), 0, -\strlen(".\r\n")));
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

        Assert::same((string) $message->getBody(), \substr(\implode('', $data), 0, -\strlen(".\r\n")));
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
                               --boundary-string--\r\n\r\n.\r\n
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

        Assert::same((string) $message->getBody(), \substr(\implode('', $data), 0, -\strlen(".\r\n")));
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

    #[DataProvider('provideNestedMultipartEmails')]
    #[Test]
    public function parsesNestedMultipart(string $fixture, array $texts, array $attachments): void
    {
        $email = \file_get_contents(\dirname(__DIR__, 4) . "/Stub/mail/$fixture");

        $message = $this->parse(\str_split($email . ".\r\n", 64));

        // Whether the stored body keeps the end-of-data line is not up to multipart parsing
        Assert::same(\rtrim((string) $message->getBody(), ".\r\n"), \rtrim($email, "\r\n"));
        Assert::same(
            \array_map(
                static fn(Field $text): string => \explode(';', $text->getHeaderLine('Content-Type'))[0],
                $message->getMessages(),
            ),
            \array_column($texts, 0),
        );
        foreach ($message->getMessages() as $i => $text) {
            Assert::string($text->getValue())->contains($texts[$i][1]);
        }
        Assert::same(
            \array_map(
                static fn(File $file): array => [
                    $file->getClientFilename(),
                    $file->getClientMediaType(),
                    $file->getEmbeddingId(),
                    $file->getSize(),
                ],
                $message->getAttachments(),
            ),
            $attachments,
        );
    }

    #[Test]
    public function partWithoutContentTypeIsText(): void
    {
        $message = $this->parse(
            "Content-Type: multipart/mixed; boundary=o\r\n\r\n"
            . "--o\r\nContent-Transfer-Encoding: 7bit\r\n\r\nno content-type body\r\n--o--\r\n.\r\n",
        );

        Assert::count($message->getMessages(), 1);
        Assert::same($message->getMessages()[0]->getValue(), 'no content-type body');
        Assert::count($message->getAttachments(), 0);
    }

    #[Test]
    public function decodesQuotedPrintableTextAttachment(): void
    {
        $message = $this->parse(
            "Content-Type: multipart/alternative; boundary=o\r\n\r\n"
            . "--o\r\nContent-Type: text/calendar; method=REQUEST\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "DESCRIPTION:a=3Db and a long line that the client brea=\r\nks\r\n--o--\r\n.\r\n",
        );

        Assert::count($message->getMessages(), 0);
        Assert::count($message->getAttachments(), 1);
        Assert::same($message->getAttachments()[0]->getClientFilename(), 'invite.ics');
        Assert::same(
            (string) $message->getAttachments()[0]->getStream(),
            'DESCRIPTION:a=b and a long line that the client breaks',
        );
    }

    #[DataCross(
        new DataProvider('provideEncodedTexts'),
        new DataUnion(
            new DataSet([false], 'single-part message'),
            new DataSet([true], 'part of a multipart message'),
        ),
    )]
    #[Test]
    public function decodesTextBody(
        string $contentType,
        ?string $encoding,
        string $encoded,
        string $decoded,
        bool $multipart,
    ): void {
        $headers = "Content-Type: {$contentType}\r\n";
        $encoding === null or $headers .= "Content-Transfer-Encoding: {$encoding}\r\n";
        $email = $multipart
            ? "Content-Type: multipart/alternative; boundary=o\r\n\r\n--o\r\n{$headers}\r\n{$encoded}\r\n--o--\r\n"
            : "{$headers}\r\n{$encoded}\r\n";

        $message = $this->parse(\str_split($email . ".\r\n", 10));

        Assert::count($message->getMessages(), 1);
        Assert::same($message->getMessages()[0]->getValue(), $decoded);
        Assert::same(\rtrim((string) $message->getBody(), ".\r\n"), \rtrim($email, "\r\n"));
    }

    #[DataSet(["Content-Disposition: attachment; filename=logo.png\r\n", 'logo.png'], 'file name')]
    #[DataSet(
        ["Content-Disposition: attachment; filename*=UTF-8''%D0%BB%D0%BE%D0%B3%D0%BE.png\r\n", 'лого.png'],
        'percent-encoded file name',
    )]
    #[DataSet(['', null], 'no file name')]
    #[DataSet(["content-disposition: attachment; filename=logo.png\r\n", 'logo.png', 'content-type', 'CONTENT-TRANSFER-ENCODING'], 'header names in any case')]
    #[Test]
    public function singlePartBodyThatIsNotTextIsAttachment(
        string $disposition,
        ?string $fileName,
        string $contentType = 'Content-Type',
        string $encoding = 'Content-Transfer-Encoding',
    ): void {
        $png = \base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        );

        $message = $this->parse(
            "{$contentType}: image/png\r\n{$encoding}: base64\r\n{$disposition}\r\n"
            . \chunk_split(\base64_encode($png), 76, "\r\n") . ".\r\n",
        );

        Assert::count($message->getMessages(), 0);
        Assert::count($message->getAttachments(), 1);
        $file = $message->getAttachments()[0];
        Assert::same($file->getClientFilename(), $fileName);
        Assert::same($file->getClientMediaType(), 'image/png');
        Assert::same((string) $file->getStream(), $png);
    }

    #[DataCross(
        new DataProvider('provideNamedFiles'),
        new DataUnion(
            new DataSet([false], 'single-part message'),
            new DataSet([true], 'part of a multipart message'),
        ),
    )]
    #[Test]
    public function namesFile(string $headers, ?string $fileName, bool $multipart): void
    {
        $part = "{$headers}Content-Transfer-Encoding: base64\r\n\r\ncGRm\r\n";
        $email = $multipart
            ? "Content-Type: multipart/mixed; boundary=o\r\n\r\n--o\r\n{$part}--o--\r\n"
            : $part;

        $message = $this->parse(\str_split($email . ".\r\n", 10));

        Assert::count($message->getMessages(), 0);
        Assert::count($message->getAttachments(), 1);
        Assert::same($message->getAttachments()[0]->getClientFilename(), $fileName);
        Assert::same((string) $message->getAttachments()[0]->getStream(), 'pdf');
    }

    #[Test]
    public function namesTextAttachmentByEncodedContentTypeName(): void
    {
        $message = $this->parse(
            "Content-Type: multipart/mixed; boundary=o\r\n\r\n"
            . "--o\r\nContent-Type: text/calendar; method=REQUEST; name=\"=?UTF-8?Q?=D0=B2=D1=81=D1=82=D1=80=D0=B5=D1=87=D0=B0.ics?=\"\r\n"
            . "\r\nBEGIN:VCALENDAR\r\n--o--\r\n.\r\n",
        );

        Assert::count($message->getAttachments(), 1);
        Assert::same($message->getAttachments()[0]->getClientFilename(), 'встреча.ics');
    }

    /**
     * Some mailers name the message text too; without a disposition the name doesn't make the text an attachment.
     */
    #[Test]
    public function keepsNamedTextWithoutDispositionAsBody(): void
    {
        $message = $this->parse(
            "Content-Type: multipart/mixed; boundary=o\r\n\r\n"
            . "--o\r\nContent-Type: text/plain; charset=utf-8; name=\"message.txt\"\r\n\r\nHello\r\n--o--\r\n.\r\n",
        );

        Assert::count($message->getAttachments(), 0);
        Assert::count($message->getMessages(), 1);
        Assert::same($message->getMessages()[0]->getValue(), 'Hello');
    }

    #[Test]
    public function parsesMultipartTypeInAnyCase(): void
    {
        $message = $this->parse(
            "Content-Type: Multipart/Alternative; Boundary=o\r\n\r\n"
            . "--o\r\nContent-Type: text/plain\r\n\r\nplain\r\n--o\r\nContent-Type: text/html\r\n\r\n<p>html</p>\r\n--o--\r\n.\r\n",
        );

        Assert::count($message->getAttachments(), 0);
        Assert::same(
            \array_map(static fn(Field $text): string => $text->getValue(), $message->getMessages()),
            ['plain', '<p>html</p>'],
        );
    }

    #[Test]
    public function decodesSinglePartBodyWithHeaderNamesInAnyCase(): void
    {
        $message = $this->parse(
            "Content-type: text/plain; charset=utf-8\r\nContent-transfer-encoding: quoted-printable\r\n\r\n"
            . "caf=C3=A9 a=3Db\r\n.\r\n",
        );

        Assert::count($message->getAttachments(), 0);
        Assert::count($message->getMessages(), 1);
        Assert::same($message->getMessages()[0]->getValue(), 'café a=b');
    }

    #[Test]
    public function keepsMultipartNestedTooDeepAsAttachment(): void
    {
        // Every level holds a text and the next level; levels close in reverse order.
        // `b1` would also match `b10`, hence the `_` closing each boundary.
        $email = $tail = '';
        for ($level = 0; $level < 20; ++$level) {
            $next = $level + 1;
            $email .= "--b{$level}_\r\nContent-Type: text/plain\r\n\r\nLevel {$level}\r\n"
                . "--b{$level}_\r\nContent-Type: multipart/mixed; boundary=b{$next}_\r\n\r\n";
            $tail = "\r\n--b{$level}_--\r\n" . $tail;
        }
        $email .= "--b20_\r\nContent-Type: text/plain\r\n\r\nLevel 20\r\n--b20_--" . $tail;

        $message = $this->parse("Content-Type: multipart/mixed; boundary=b0_\r\n\r\n{$email}\r\n.\r\n");

        Assert::count($message->getMessages(), 16);
        Assert::same($message->getMessages()[15]->getValue(), 'Level 15');
        Assert::count($message->getAttachments(), 1);
        Assert::same($message->getAttachments()[0]->getClientMediaType(), 'multipart/mixed');
    }

    private function parse(array|string $body, array $protocol = []): Message
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
        return $this->runInFiber(static fn() => (new Parser())->parseStream($protocol, $stream));
    }
}

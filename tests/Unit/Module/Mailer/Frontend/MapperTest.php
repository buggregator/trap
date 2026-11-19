<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mailer\Frontend;

use Buggregator\Trap\Module\Mailer\Contact;
use Buggregator\Trap\Module\Mailer\Frame as SmtpFrame;
use Buggregator\Trap\Module\Mailer\Frontend\Mapper;
use Buggregator\Trap\Module\Mailer\Message as SmtpMessage;
use Buggregator\Trap\Module\Mailer\Traffic\Parser;
use Buggregator\Trap\Server\Event\AttachedFile;
use Buggregator\Trap\Server\Event\EmbeddedFile;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Mapper::class)]
#[Test]
final class MapperTest
{
    use FiberTrait;

    public function testMapsMessageToSmtpEvent(): void
    {
        $message = SmtpMessage::create(
            protocol: ['FROM' => ['sender@example.org'], 'BCC' => ['hidden@example.org']],
            headers: [
                'From' => ['John Doe <john@example.org>'],
                'Reply-To' => ['reply@example.org'],
                'To' => ['Mary <mary@example.org>'],
                'Cc' => ['cc@example.org'],
                'Subject' => ['Hello'],
            ],
        )->withMessages([
            new Field(['Content-Type' => ['text/plain; charset=utf-8']], value: 'plain text'),
            new Field(['Content-Type' => ['text/html; charset=utf-8']], value: '<p>html</p>'),
        ])->withBody(Stream::create('raw body'));
        $time = new \DateTimeImmutable('2024-01-02 03:04:05.123456');

        $event = (new Mapper())->map(new SmtpFrame($message, $time));

        Assert::same($event->type, 'smtp');
        Assert::string($event->uuid)->notSame('');
        Assert::same($event->timestamp, (float) $time->format('U.u'));
        Assert::equals($event->payload['from'], [
            new Contact(null, 'sender@example.org'),
            new Contact('John Doe', 'john@example.org'),
        ]);
        Assert::equals($event->payload['reply_to'], [new Contact(null, 'reply@example.org')]);
        Assert::same($event->payload['subject'], 'Hello');
        Assert::equals($event->payload['to'], [new Contact('Mary', 'mary@example.org')]);
        Assert::equals($event->payload['cc'], [new Contact(null, 'cc@example.org')]);
        Assert::equals($event->payload['bcc'], [new Contact(null, 'hidden@example.org')]);
        Assert::same($event->payload['text'], 'plain text');
        Assert::same($event->payload['html'], '<p>html</p>');
        Assert::same($event->payload['raw'], 'raw body');
        Assert::count($event->assets, 0);
    }

    public function testMissingBodiesBecomeEmptyStrings(): void
    {
        $message = SmtpMessage::create(protocol: [], headers: []);

        $event = (new Mapper())->map(new SmtpFrame($message));

        Assert::same($event->payload['text'], '');
        Assert::same($event->payload['html'], '');
        Assert::same($event->payload['raw'], '');
    }

    public function testAttachmentsBecomeAssetsAndCidLinksPointToEmbeddedFiles(): void
    {
        $attached = new File(['Content-Disposition' => ['attachment; filename="doc.pdf"']], 'doc', 'doc.pdf');
        $embedded = new File(['Content-Disposition' => ['inline; name="logo"']], 'logo', 'logo.png');
        $message = SmtpMessage::create(protocol: [], headers: [])
            ->withMessages([
                new Field(['Content-Type' => ['text/html']], value: '<img src="cid:logo"><img src="cid:other">'),
            ])
            ->withAttachments([$attached, $embedded]);

        $event = (new Mapper())->map(new SmtpFrame($message));

        $assets = \iterator_to_array($event->assets);
        Assert::count($assets, 2);
        [$first, $second] = \array_values($assets);
        Assert::instanceOf($first, AttachedFile::class);
        Assert::same($first->file, $attached);
        Assert::instanceOf($second, EmbeddedFile::class);
        Assert::same($second->file, $embedded);
        Assert::same($second->name, 'logo');
        foreach ($assets as $key => $asset) {
            Assert::same($key, $asset->uuid);
        }
        Assert::same(
            $event->payload['html'],
            "<img src=\"/api/smtp/{$event->uuid}/attachment/{$second->uuid}\"><img src=\"cid:other\">",
        );
    }

    public function testSymfonyMailerQuotedPrintableHtmlEmbedsTheImage(): void
    {
        $email = \file_get_contents(\dirname(__DIR__, 4) . '/Stub/mail/symfony-mixed-related-alternative.eml');
        $stream = StreamClientMock::createFromGenerator((static fn() => yield $email . ".\r\n")());
        $message = $this->runInFiber(static fn() => (new Parser())->parseStream([], $stream));

        $event = (new Mapper())->map(new SmtpFrame($message));

        $assets = \array_values(\iterator_to_array($event->assets));
        Assert::count($assets, 2);
        Assert::instanceOf($assets[0], EmbeddedFile::class);
        Assert::same($assets[0]->file->getClientFilename(), 'logo');
        Assert::instanceOf($assets[1], AttachedFile::class);
        Assert::same($assets[1]->file->getClientFilename(), 'report.pdf');
        Assert::string($event->payload['html'])
            ->contains("<img src=\"/api/smtp/{$event->uuid}/attachment/{$assets[0]->uuid}\">");
        Assert::same($event->payload['raw'], $email);
    }

    public function testEmbeddableFileTheHtmlDoesNotReferToIsAttached(): void
    {
        $inline = new File(['Content-ID' => ['<pdf@example.org>'], 'Content-Disposition' => ['inline']], null, 'a.pdf');
        $noHtml = SmtpMessage::create(protocol: [], headers: [])->withAttachments([$inline]);
        $otherCid = $noHtml->withMessages([new Field(['Content-Type' => ['text/html']], value: '<img src="cid:logo">')]);

        foreach ([$noHtml, $otherCid] as $message) {
            $assets = \array_values(\iterator_to_array((new Mapper())->map(new SmtpFrame($message))->assets));

            Assert::count($assets, 1);
            Assert::instanceOf($assets[0], AttachedFile::class);
            Assert::same($assets[0]->file, $inline);
        }
    }
}

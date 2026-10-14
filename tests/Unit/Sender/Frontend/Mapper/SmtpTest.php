<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender\Frontend\Mapper;

use Buggregator\Trap\Proto\Frame\Smtp as SmtpFrame;
use Buggregator\Trap\Sender\Frontend\Event\AttachedFile;
use Buggregator\Trap\Sender\Frontend\Event\EmbeddedFile;
use Buggregator\Trap\Sender\Frontend\Mapper\Smtp;
use Buggregator\Trap\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use Buggregator\Trap\Traffic\Message\Smtp as SmtpMessage;
use Buggregator\Trap\Traffic\Message\Smtp\Contact;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Smtp::class)]
#[Test]
final class SmtpTest
{
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

        $event = (new Smtp())->map(new SmtpFrame($message, $time));

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

        $event = (new Smtp())->map(new SmtpFrame($message));

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

        $event = (new Smtp())->map(new SmtpFrame($message));

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
}

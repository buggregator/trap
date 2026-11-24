<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mailer\Frontend;

use Buggregator\Trap\Module\Mailer\Alternative;
use Buggregator\Trap\Module\Mailer\Contact;
use Buggregator\Trap\Module\Mailer\Frame as SmtpFrame;
use Buggregator\Trap\Module\Mailer\Frontend\Mapper;
use Buggregator\Trap\Module\Mailer\Message as SmtpMessage;
use Buggregator\Trap\Module\Mailer\Traffic\Parser;
use Buggregator\Trap\Server\Event\AttachedFile;
use Buggregator\Trap\Server\Event\EmbeddedFile;
use Buggregator\Trap\Server\Event\Event;
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
        Assert::null($event->payload['auth']);
    }

    public function testTraceHeaderIsTheTraceFacet(): void
    {
        $message = SmtpMessage::create(protocol: [], headers: [
            'Sentry-Trace' => ['4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-1'],
        ]);

        $event = (new Mapper())->map(new SmtpFrame($message));

        Assert::same($event->facets, ['trace' => ['4bf92f3577b34da6a3ce929d0e0e4736']]);
        Assert::same((new Mapper())->map(new SmtpFrame(SmtpMessage::create(protocol: [], headers: [])))->facets, []);
    }

    public function testCredentialsOfSessionGoToAuth(): void
    {
        $message = SmtpMessage::create(
            protocol: ['FROM' => ['a@example.org'], 'AUTH' => ['LOGIN'], 'USERNAME' => ['john'], 'PASSWORD' => ['s3cret']],
            headers: [],
        );

        $event = (new Mapper())->map(new SmtpFrame($message));

        Assert::same($event->payload['auth'], ['mechanism' => 'LOGIN', 'username' => 'john', 'password' => 's3cret', 'authzid' => null]);
    }

    public function testAuthorizationIdentityGoesToAuth(): void
    {
        $message = SmtpMessage::create(
            protocol: ['AUTH' => ['PLAIN'], 'AUTHZID' => ['admin'], 'USERNAME' => ['john'], 'PASSWORD' => ['s3cret']],
            headers: [],
        );

        $event = (new Mapper())->map(new SmtpFrame($message));

        Assert::same($event->payload['auth'], ['mechanism' => 'PLAIN', 'username' => 'john', 'password' => 's3cret', 'authzid' => 'admin']);
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
            "<img src=\"/api/smtp/attachments/{$event->uuid}/{$second->uuid}\"><img src=\"cid:other\">",
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
            ->contains("<img src=\"/api/smtp/attachments/{$event->uuid}/{$assets[0]->uuid}\">");
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

    public function testOrdinaryEmailHasNoPartsAndCalendar(): void
    {
        $payload = $this->mapFixture('symfony-alternative.eml')->payload;

        Assert::false(\array_key_exists('parts', $payload));
        Assert::false(\array_key_exists('calendar', $payload));
    }

    public function testOtherVersionsBecomePartsInMessageOrder(): void
    {
        $payload = $this->mapFixture('bounce.eml')->payload;

        Assert::same($payload['text'], "I'm sorry to have to inform you that your message could not be delivered.");
        Assert::same(\array_map(static fn(array $part): array => [$part['type'], $part['label']], $payload['parts']), [
            ['message/delivery-status', 'Delivery status'],
            ['text/rfc822-headers', 'Original headers'],
        ]);
        Assert::same($payload['parts'][1], [
            'type' => 'text/rfc822-headers',
            'label' => 'Original headers',
            'content' => "From: app@example.com\r\nTo: nobody@example.org\r\nSubject: Welcome",
            'size' => 63,
        ]);
        Assert::false(\array_key_exists('calendar', $payload));
    }

    public function testCalendarVersionIsPartAndInvite(): void
    {
        $event = $this->mapFixture('google-invite.eml');

        Assert::same(\array_column($event->payload['parts'], 'label'), ['Calendar']);
        Assert::string($event->payload['parts'][0]['content'])->startsWith('BEGIN:VCALENDAR');
        Assert::same($event->payload['calendar']['method'], 'REQUEST');
        Assert::same($event->payload['calendar']['summary'], 'Release sync');
        Assert::same($event->payload['calendar']['start'], '2026-12-01T09:00:00Z');
        Assert::same(\array_column($event->payload['calendar']['attendees'], 'status'), ['NEEDS-ACTION', 'ACCEPTED']);
        // The `.ics` attachment stays a file to download
        Assert::count($event->assets, 1);
    }

    public function testAttachedCalendarIsInviteWithoutPart(): void
    {
        $payload = $this->mapFixture('ics-attachment.eml')->payload;

        Assert::false(\array_key_exists('parts', $payload));
        Assert::same($payload['calendar'], [
            'summary' => 'Haircut',
            'start' => '2026-12-05',
            'end' => '2026-12-06',
            'all_day' => true,
        ]);
    }

    public function testCancelledInviteOfOutlook(): void
    {
        $payload = $this->mapFixture('outlook-cancel.eml')->payload;

        Assert::same($payload['text'], '');
        Assert::same(\array_column($payload['parts'], 'type'), ['text/calendar']);
        Assert::same($payload['calendar']['method'], 'CANCEL');
        Assert::same($payload['calendar']['status'], 'CANCELLED');
        Assert::same($payload['calendar']['end'], \class_exists(\IntlTimeZone::class)
            ? '2026-12-03T15:00:00+01:00'
            : '2026-12-03T15:00:00');
    }

    /**
     * An `.ics` attachment typed with parameters and written in another charset.
     */
    public function testInviteOfAttachmentInItsCharset(): void
    {
        $file = new File(
            ['Content-Type' => [' Text/Calendar ; charset=windows-1251; method=REQUEST']],
            null,
            'meeting.dat',
        );
        $file->setStream(Stream::create("BEGIN:VEVENT\r\nSUMMARY:\xC2\xF1\xF2\xF0\xE5\xF7\xE0\r\nEND:VEVENT"));
        $message = SmtpMessage::create(protocol: [], headers: [])->withAttachments([$file]);

        $payload = (new Mapper())->map(new SmtpFrame($message))->payload;

        Assert::same($payload['calendar'], ['summary' => 'Встреча']);
        Assert::false(\array_key_exists('parts', $payload));
    }

    public function testCalendarWithoutEventIsNoInvite(): void
    {
        $message = SmtpMessage::create(protocol: [], headers: [])->withMessages([
            new Field(['Content-Type' => ['text/calendar']], value: "BEGIN:VCALENDAR\r\nEND:VCALENDAR"),
            new Field(['Content-Type' => ['text/calendar']], value: "BEGIN:VEVENT\r\nSUMMARY:Second\r\nEND:VEVENT"),
        ]);

        $payload = (new Mapper())->map(new SmtpFrame($message))->payload;

        Assert::count($payload['parts'], 2);
        Assert::same($payload['calendar'], ['summary' => 'Second']);
    }

    public function testLargeVersionIsCut(): void
    {
        $content = \str_repeat('<p>AMP</p>', Alternative::MAX_CONTENT);
        $message = SmtpMessage::create(protocol: [], headers: [])->withMessages([
            new Field(['Content-Type' => ['text/x-amp-html; charset=utf-8']], value: $content),
        ]);

        $part = (new Mapper())->map(new SmtpFrame($message))->payload['parts'][0];

        Assert::same($part['label'], 'AMP');
        Assert::same($part['size'], \strlen($content));
        Assert::same(\strlen($part['content']), Alternative::MAX_CONTENT);
        Assert::true($part['truncated']);
    }

    private function mapFixture(string $fixture): Event
    {
        $email = \file_get_contents(\dirname(__DIR__, 4) . "/Stub/mail/$fixture");
        $stream = StreamClientMock::createFromGenerator((static fn() => yield $email . ".\r\n")());
        $message = $this->runInFiber(static fn() => (new Parser())->parseStream([], $stream));

        return (new Mapper())->map(new SmtpFrame($message));
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mailer\Console;

use Buggregator\Trap\Module\Mailer\Console\Renderer;
use Buggregator\Trap\Module\Mailer\Frame;
use Buggregator\Trap\Module\Mailer\Message;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Renderer::class)]
#[Test]
final class RendererTest
{
    public function testDecodesEncodedHeaderValues(): void
    {
        $output = new BufferedOutput();
        $message = Message::create(['FROM' => ['sender@example.com']], [
            'Subject' => ['=?utf-8?Q?=D0=9F=D1=80=D0=B8=D0=B2=D0=B5=D1=82=2C_=D0=BC=D0=B8=D1=80?='],
            'From' => ['=?utf-8?Q?Doe=2C_John?= <sender@example.com>'],
        ]);

        (new Renderer())->render($output, new Frame($message));
        $text = $output->fetch();

        Assert::string($text)
            ->contains('Привет, мир')
            ->contains('Doe, John <sender@example.com>')
            ->notContains('=?utf-8?');
    }

    public function testNamesVersionsAndShowsInviteInsteadOfCalendarText(): void
    {
        $output = new BufferedOutput();
        $message = Message::create([], [])->withMessages([
            new Field(['Content-Type' => ['text/plain']], value: 'plain'),
            new Field(['Content-Type' => ['text/watch-html']], value: '<b>watch</b>'),
            new Field(['Content-Type' => ['text/calendar; method=REQUEST']], value: \implode("\r\n", [
                'BEGIN:VCALENDAR',
                'METHOD:REQUEST',
                'BEGIN:VEVENT',
                'SUMMARY:<error>Sprint</error> review',
                'DTSTART;TZID=Europe/Berlin:20261205T100000',
                'DTEND;TZID=Europe/Berlin:20261205T103000',
                'LOCATION:Room 4',
                'ORGANIZER;CN=Ann:mailto:ann@example.com',
                'ATTENDEE;CN="Doe, Jane";PARTSTAT=TENTATIVE:mailto:jane@example.com',
                'ATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.com',
                'END:VEVENT',
                'END:VCALENDAR',
            ])),
        ]);

        (new Renderer())->render($output, new Frame($message));
        $text = $output->fetch();

        Assert::string($text)
            ->contains('Apple Watch')
            ->contains('Invitation')
            ->contains('Summary: <error>Sprint</error> review')
            ->contains('When: 2026-12-05T10:00:00+01:00 – 2026-12-05T10:30:00+01:00 (Europe/Berlin)')
            ->contains('Where: Room 4')
            ->contains('Organizer: Ann <ann@example.com>')
            ->contains('Guests: Doe, Jane <jane@example.com>: TENTATIVE')
            ->contains('Guests: bob@example.com: ACCEPTED')
            ->notContains('BEGIN:VEVENT');
    }

    #[DataSet(['20261205', '20261206', 'When: 2026-12-05, all day'], 'one day')]
    #[DataSet(['20261205', '20261208', 'When: 2026-12-05 – 2026-12-07, all day'], 'three days')]
    public function testAllDayInviteEndsTheDayBeforeItsEndDate(string $start, string $end, string $when): void
    {
        $output = new BufferedOutput();
        $message = Message::create([], [])->withMessages([
            new Field(['Content-Type' => ['text/calendar']], value: \implode("\n", [
                'BEGIN:VCALENDAR',
                'METHOD:CANCEL',
                'BEGIN:VEVENT',
                "DTSTART;VALUE=DATE:$start",
                "DTEND;VALUE=DATE:$end",
                'END:VEVENT',
                'END:VCALENDAR',
            ])),
        ]);

        (new Renderer())->render($output, new Frame($message));

        Assert::string($output->fetch())->contains('Cancelled')->contains($when);
    }

    /**
     * The invite stands for the calendar version it is read from; another calendar version stays a body.
     */
    public function testHidesOnlyTheCalendarVersionOfTheInvite(): void
    {
        $output = new BufferedOutput();
        $message = Message::create([], [])->withMessages([
            new Field(['Content-Type' => ['text/calendar']], value: "BEGIN:VCALENDAR\nX-NOTE:no event\nEND:VCALENDAR"),
            new Field(['Content-Type' => ['text/calendar']], value: "BEGIN:VEVENT\nSUMMARY:Sync\nEND:VEVENT"),
        ]);

        (new Renderer())->render($output, new Frame($message));

        Assert::string($output->fetch())
            ->contains('X-NOTE:no event')
            ->contains('Summary: Sync')
            ->notContains('SUMMARY:Sync');
    }
}

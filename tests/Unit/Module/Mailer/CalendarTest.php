<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mailer;

use Buggregator\Trap\Module\Mailer\Calendar;
use Buggregator\Trap\Module\Mailer\ZoneLinks;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Calendar::class)]
#[Test]
final class CalendarTest
{
    public function testReadsTheInviteOfGoogleCalendar(): void
    {
        $invite = Calendar::read(self::ics(<<<'ICS'
            BEGIN:VCALENDAR
            PRODID:-//Google Inc//Google Calendar 70.9054//EN
            METHOD:REQUEST
            BEGIN:VEVENT
            DTSTART:20261201T090000Z
            DTEND:20261201T093000Z
            UID:abc123@google.com
            ORGANIZER;CN=Ann Lee:mailto:ann@example.com
            ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=
             TRUE;CN=bob@example.com;X-NUM-GUESTS=0:mailto:bob@example.com
            ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;CN="Lee, Ann":MAILTO:ann@example.com
            DESCRIPTION:Agenda:\n1. Release\, docs\n2. Q&A
            LOCATION:Room 4\; floor 2
            SEQUENCE:0
            STATUS:confirmed
            SUMMARY:Release sync
            URL:https://example.com/event
            RRULE:FREQ=WEEKLY
            END:VEVENT
            END:VCALENDAR
            ICS));

        Assert::same($invite, [
            'method' => 'REQUEST',
            'summary' => 'Release sync',
            'start' => '2026-12-01T09:00:00Z',
            'end' => '2026-12-01T09:30:00Z',
            'tz' => 'UTC',
            'location' => 'Room 4; floor 2',
            'description' => "Agenda:\n1. Release, docs\n2. Q&A",
            'url' => 'https://example.com/event',
            'status' => 'CONFIRMED',
            'uid' => 'abc123@google.com',
            'sequence' => '0',
            'rrule' => 'FREQ=WEEKLY',
            'organizer' => ['name' => 'Ann Lee', 'email' => 'ann@example.com'],
            'attendees' => [
                ['name' => 'bob@example.com', 'email' => 'bob@example.com', 'status' => 'NEEDS-ACTION', 'role' => 'REQ-PARTICIPANT'],
                ['name' => 'Lee, Ann', 'email' => 'ann@example.com', 'status' => 'ACCEPTED', 'role' => 'REQ-PARTICIPANT'],
            ],
        ]);
    }

    #[DataSet(['DTSTART;TZID=Europe/Berlin:20261205T100000', '2026-12-05T10:00:00+01:00', 'Europe/Berlin'], 'IANA zone in winter')]
    #[DataSet(['DTSTART;TZID="America/New_York":20260705T100000', '2026-07-05T10:00:00-04:00', 'America/New_York'], 'quoted IANA zone in summer')]
    #[DataSet(['DTSTART;TZID=Europe/London:20261205T100000', '2026-12-05T10:00:00Z', 'Europe/London'], 'zone at UTC offset')]
    #[DataSet(['DTSTART;TZID=/Europe/Berlin:20261205T100000', '2026-12-05T10:00:00+01:00', 'Europe/Berlin'], 'globally unique zone')]
    #[DataSet(['DTSTART;TZID=/mozilla.org/20050126_1/Europe/Berlin:20261205T100000', '2026-12-05T10:00:00+01:00', 'Europe/Berlin'], 'zone of a vendor prefix')]
    #[DataSet(['DTSTART;TZID="America/Argentina/Buenos_Aires":20261205T100000', '2026-12-05T10:00:00-03:00', 'America/Argentina/Buenos_Aires'], 'zone of three segments')]
    #[DataSet(['DTSTART;TZID=Mars/Olympus:20261205T100000', '2026-12-05T10:00:00', 'Mars/Olympus'], 'unknown zone')]
    #[DataSet(['DTSTART;TZID=europe/berlin:20261205T100000', '2026-12-05T10:00:00', 'europe/berlin'], 'zone name in another case')]
    #[DataSet(['DTSTART;TZID=EST:20261205T100000', '2026-12-05T10:00:00-05:00', 'America/Panama'], 'zone the IANA database keeps for compatibility')]
    #[DataSet(['DTSTART;TZID=PST8PDT:20260705T100000', '2026-07-05T10:00:00-07:00', 'America/Los_Angeles'], 'zone of System V with summer time')]
    #[DataSet(['DTSTART;TZID=PST:20261205T100000', '2026-12-05T10:00:00', 'PST'], 'abbreviation')]
    #[DataSet(['DTSTART;TZID=Asia/Calcutta:20261205T100000', '2026-12-05T10:00:00+05:30', 'Asia/Kolkata'], 'renamed zone')]
    #[DataSet(['DTSTART;TZID=Europe/Oslo:20261205T100000', '2026-12-05T10:00:00+01:00', 'Europe/Oslo'], 'zone tzdata merged into another country')]
    #[DataSet(['DTSTART;TZID=Atlantic/Reykjavik:20261205T100000', '2026-12-05T10:00:00Z', 'Atlantic/Reykjavik'], 'merged zone at UTC offset')]
    #[DataSet(['DTSTART;TZID=US/Eastern:20261205T100000', '2026-12-05T10:00:00-05:00', 'America/New_York'], 'zone of Java')]
    #[DataSet(['DTSTART;TZID=Japan:20261205T100000', '2026-12-05T10:00:00+09:00', 'Asia/Tokyo'], 'country name as a zone')]
    #[DataSet(['DTSTART;TZID=/mozilla.org/20050126_1/EST5EDT:20260705T100000', '2026-07-05T10:00:00-04:00', 'America/New_York'], 'System V zone of a vendor prefix')]
    #[DataSet(['DTSTART;TZID=/citadel.org/20190914_1/Asia/Calcutta:20261205T100000', '2026-12-05T10:00:00+05:30', 'Asia/Kolkata'], 'renamed zone of a vendor prefix')]
    #[DataSet(['DTSTART;TZID=Foo/Bar/GMT:20261205T100000', '2026-12-05T10:00:00', 'Foo/Bar/GMT'], 'last segment without a vendor domain')]
    #[DataSet(['DTSTART;TZID=Foo/Europe/Berlin:20261205T100000', '2026-12-05T10:00:00+01:00', 'Europe/Berlin'], 'zone of a path')]
    #[DataSet(['DTSTART;TZID="+01:00":20261205T100000', '2026-12-05T10:00:00', '+01:00'], 'offset as a zone')]
    #[DataSet(['DTSTART:20261205T100000', '2026-12-05T10:00:00', null], 'floating time')]
    #[DataSet(['DTSTART;TZID=Europe/Berlin:20261205T100000Z', '2026-12-05T10:00:00Z', 'UTC'], 'UTC wins over the zone')]
    #[DataSet(['DTSTART;TZID=Europe/Berlin:20260230T100000', '20260230T100000', 'Europe/Berlin'], 'no such day')]
    #[DataSet(['DTSTART:20261205T250000Z', '20261205T250000Z', null], 'no such hour in UTC')]
    #[DataSet(['DTSTART:soon', 'soon', null], 'not a time')]
    public function testReadsTimeInItsZone(string $line, string $start, ?string $tz): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\r\n$line\r\nEND:VEVENT");

        Assert::same($invite['start'] ?? null, $start);
        Assert::same($invite['tz'] ?? null, $tz);
        Assert::false(isset($invite['all_day']));
    }

    #[DataSet(['DTSTART;VALUE=DATE:20261205', 'DTEND;VALUE=DATE:20261206', '2026-12-05', '2026-12-06'], 'VALUE=DATE')]
    #[DataSet(['DTSTART:20261205', 'DTEND:20261206', '2026-12-05', '2026-12-06'], 'date without VALUE')]
    #[DataSet(['DTSTART;VALUE=date:20261205', 'DTEND;VALUE=date:20261206', '2026-12-05', '2026-12-06'], 'value type in lower case')]
    #[DataSet(['DTSTART;VALUE=DATE:20261205', 'DURATION:P3D', '2026-12-05', '2026-12-08'], 'duration of days')]
    #[DataSet(['DTSTART;VALUE=DATE:20261205', 'DURATION:P1W', '2026-12-05', '2026-12-12'], 'duration of a week')]
    #[DataSet(['DTSTART;VALUE=DATE:20261231', 'X-NONE:1', '2026-12-31', '2027-01-01'], 'no end: the day')]
    #[DataSet(['DTSTART;VALUE=DATE:20261205', 'DURATION:PT1H', '2026-12-05', '2026-12-06'], 'duration of hours: the day')]
    #[DataSet(['DTSTART;VALUE=DATE:20261205', 'DURATION:P2DT1H', '2026-12-05', '2026-12-06'], 'duration with a time: the day')]
    #[DataSet(['DTSTART;VALUE=DATE:20261205', 'DURATION:P0D', '2026-12-05', '2026-12-06'], 'duration of no days: the day')]
    #[DataSet(['DTSTART;VALUE=DATE:20261305', 'DTEND;VALUE=DATE:2026', '20261305', '2026'], 'not a date')]
    public function testReadsAllDayEvent(string $startLine, string $endLine, string $start, string $end): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\r\n$startLine\r\n$endLine\r\nSUMMARY:Off\r\nEND:VEVENT");

        Assert::same($invite['start'] ?? null, $start);
        Assert::same($invite['end'] ?? null, $end);
        Assert::true($invite['all_day'] ?? false);
        Assert::false(isset($invite['tz']));
    }

    /**
     * Outlook names zones the Windows way; ext-intl maps them to zones, some by the names of `backward`
     * (`Asia/Calcutta`) that a platform without `tzdata-legacy` doesn't know.
     */
    #[DataSet(['W. Europe Standard Time', '2026-12-03T14:00:00+01:00', 'Europe/Berlin'], 'zone')]
    #[DataSet(['India Standard Time', '2026-12-03T14:00:00+05:30', 'Asia/Kolkata'], 'Asia/Calcutta')]
    #[DataSet(['FLE Standard Time', '2026-12-03T14:00:00+02:00', 'Europe/Kyiv'], 'Europe/Kiev')]
    #[DataSet(['Nepal Standard Time', '2026-12-03T14:00:00+05:45', 'Asia/Kathmandu'], 'Asia/Katmandu')]
    #[DataSet(['Myanmar Standard Time', '2026-12-03T14:00:00+06:30', 'Asia/Yangon'], 'Asia/Rangoon')]
    #[DataSet(['Argentina Standard Time', '2026-12-03T14:00:00-03:00', 'America/Argentina/Buenos_Aires'], 'America/Buenos_Aires')]
    #[DataSet(['US Eastern Standard Time', '2026-12-03T14:00:00-05:00', 'America/Indiana/Indianapolis'], 'America/Indianapolis')]
    #[DataSet(['Greenwich Standard Time', '2026-12-03T14:00:00Z', 'Atlantic/Reykjavik'], 'zone tzdata merged')]
    public function testReadsWindowsZone(string $windows, string $start, string $tz): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\nDTSTART;TZID=$windows:20261203T140000\nEND:VEVENT");

        \class_exists(\IntlTimeZone::class) or [$start, $tz] = ['2026-12-03T14:00:00', $windows];
        Assert::same($invite['start'] ?? null, $start);
        Assert::same($invite['tz'] ?? null, $tz);
    }

    /**
     * A zone of a country stays itself even when tzdata links it to the zone of another country with the same
     * clocks, as `Europe/Oslo` to `Europe/Berlin`: the map holds obsolete names only. Checked against the names
     * of `zone.tab` the map is written from, not those of the platform, whose tzdata may be older.
     */
    public function testNoCurrentZoneIsMapped(): void
    {
        $current = self::currentZones();
        foreach (\array_keys(ZoneLinks::TARGETS) as $link) {
            Assert::false(isset($current[$link]), "`$link` is a current zone.");
        }
        foreach (['Europe/Oslo', 'Europe/Stockholm', 'Europe/Amsterdam', 'Atlantic/Reykjavik', 'Africa/Accra', 'Asia/Kuwait'] as $zone) {
            Assert::true(isset($current[$zone]), "`$zone` is no current zone.");
            Assert::false(isset(ZoneLinks::TARGETS[$zone]), "`$zone` is mapped.");
        }
    }

    /**
     * The zone of a link is the same on every platform: its target is a current zone, not another link.
     */
    public function testEveryLinkResolvesToCurrentZone(): void
    {
        $current = self::currentZones() + ['UTC' => true];
        foreach (ZoneLinks::TARGETS as $link => $zone) {
            Assert::false(isset(ZoneLinks::TARGETS[$zone]), "The target of `$link` is a link.");
            Assert::true(isset($current[$zone]), "The target `$zone` of `$link` is no current zone.");
            $invite = Calendar::read("BEGIN:VEVENT\nDTSTART;TZID=$link:20261203T140000\nEND:VEVENT");
            Assert::same($invite['tz'] ?? null, $zone, "`$link` resolves elsewhere.");
        }
    }

    /**
     * An obsolete name stands for the zone of its own country, not the zone `backward` merged that into.
     */
    #[DataSet(['Iceland', 'Atlantic/Reykjavik'])]
    #[DataSet(['Africa/Asmera', 'Africa/Asmara'])]
    #[DataSet(['Pacific/Truk', 'Pacific/Chuuk'])]
    #[DataSet(['Pacific/Ponape', 'Pacific/Pohnpei'])]
    #[DataSet(['America/Virgin', 'America/St_Thomas'])]
    #[DataSet(['America/Coral_Harbour', 'America/Atikokan'])]
    #[DataSet(['Atlantic/Jan_Mayen', 'Europe/Oslo'])]
    #[DataSet(['Africa/Timbuktu', 'Africa/Bamako'])]
    #[DataSet(['Antarctica/South_Pole', 'Antarctica/McMurdo'])]
    #[DataSet(['Pacific/Yap', 'Pacific/Chuuk'])]
    public function testObsoleteNameKeepsItsCountry(string $tzid, string $zone): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\nDTSTART;TZID=$tzid:20261203T140000\nEND:VEVENT");

        Assert::same($invite['tz'] ?? null, $zone);
    }

    /**
     * The intl settings make the Windows lookup of an unknown name throw or warn; the name stays a local zone.
     */
    #[DataSet(['intl.use_exceptions', '1'], 'exceptions')]
    #[DataSet(['intl.error_level', '2'], 'warnings, E_WARNING')]
    public function testUnknownZoneUnderStrictIntlSettings(string $setting, string $value): void
    {
        if (!\class_exists(\IntlTimeZone::class)) {
            return;
        }

        $previous = \ini_get($setting);
        @\ini_set($setting, $value);
        try {
            $invite = Calendar::read(
                "BEGIN:VEVENT\nDTSTART;TZID=Customized Time Zone:20261205T100000\n"
                . "DTEND;TZID=/example.com/2026/Mars/Olympus:20261205T110000\nEND:VEVENT",
            );
        } finally {
            @\ini_set($setting, (string) $previous);
        }

        Assert::same($invite['start'] ?? null, '2026-12-05T10:00:00');
        Assert::same($invite['tz'] ?? null, 'Customized Time Zone');
        Assert::same($invite['end'] ?? null, '2026-12-05T11:00:00');
    }

    #[DataSet(['DTSTART:20261205T100000Z', 'PT1H30M', '2026-12-05T11:30:00Z'], 'UTC')]
    #[DataSet(['DTSTART;TZID=Europe/Berlin:20261024T100000', 'P1D', '2026-10-25T10:00:00+01:00'], 'a day over the change of time')]
    #[DataSet(['DTSTART;TZID=Europe/Berlin:20261205T233000', 'PT45M', '2026-12-06T00:15:00+01:00'], 'over midnight')]
    #[DataSet(['DTSTART:20261205T100000', '+P1DT2H', '2026-12-06T12:00:00'], 'floating time')]
    #[DataSet(['DTSTART;TZID=Mars/Olympus:20261205T100000', 'PT30S', '2026-12-05T10:00:30'], 'unknown zone')]
    #[DataSet(['DTSTART;TZID=Europe/Berlin:20260329T013000', 'PT1H', '2026-03-29T03:30:00+02:00'], 'an exact hour over the change of time')]
    #[DataSet(['DTSTART:20261205T100000Z', '-PT1H', null], 'negative')]
    #[DataSet(['DTSTART:20261205T100000Z', 'P', null], 'empty')]
    #[DataSet(['DTSTART:20261205T100000Z', 'PT', null], 'empty time')]
    #[DataSet(['DTSTART:20261205T100000Z', 'P1DT', null], 'time without a value')]
    #[DataSet(['DTSTART:20261205T100000Z', 'PT1H2', null], 'number without a unit')]
    #[DataSet(['DTSTART:20261205T100000Z', 'P1W2D', null], 'weeks with days')]
    #[DataSet(['DTSTART:soon', 'PT1H', null], 'start that is no time')]
    public function testEndIsStartPlusDuration(string $startLine, string $duration, ?string $end): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\n$startLine\nDURATION:$duration\nEND:VEVENT");

        Assert::same($invite['end'] ?? null, $end);
    }

    public function testEndWinsOverDuration(): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\nDTSTART:20261205T100000Z\nDURATION:PT5H\nDTEND:20261205T110000Z\nEND:VEVENT");

        Assert::same($invite['end'] ?? null, '2026-12-05T11:00:00Z');
    }

    public function testTimedEventWithoutEndHasNone(): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\nDTSTART:20261205T100000Z\nEND:VEVENT");

        Assert::false(isset($invite['end']));
    }

    public function testGuestWithoutNameAndAddressIsDropped(): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\nATTENDEE:\nATTENDEE;PARTSTAT=ACCEPTED:mailto:\nATTENDEE;CN=Ann:\nEND:VEVENT");

        Assert::same($invite['attendees'] ?? null, [['name' => 'Ann']]);
    }

    public function testUnfoldsLinesContinuedBySpaceOrTab(): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\r\nSUMMARY:Re\r\n lease\r\n\t sync\nLOCA\n TION:Room 4\r\nEND:VEVENT\r\n");

        Assert::same($invite['summary'] ?? null, 'Release sync');
        Assert::same($invite['location'] ?? null, 'Room 4');
    }

    public function testUnescapesText(): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\nSUMMARY:a\\,b\\;c\\\\n\\Nd\\ne\\x\nEND:VEVENT");

        Assert::same($invite['summary'] ?? null, "a,b;c\\n\nd\ne\\x");
    }

    public function testValueStartsAtTheFirstColonOutsideQuotes(): void
    {
        $invite = Calendar::read(
            "BEGIN:VEVENT\nORGANIZER;CN=\"Team: Ops; on call\";SENT-BY=\"mailto:x@example.com\":mailto:ops@example.com\nEND:VEVENT",
        );

        Assert::same($invite['organizer'] ?? null, ['name' => 'Team: Ops; on call', 'email' => 'ops@example.com']);
    }

    #[DataSet(["BEGIN:VCALENDAR\nMETHOD:CANCEL\nBEGIN:VEVENT\nEND:VEVENT\nEND:VCALENDAR", 'Cancelled'], 'cancelled by the method')]
    #[DataSet(["BEGIN:VEVENT\nSTATUS:Cancelled\nEND:VEVENT", 'Cancelled'], 'cancelled by the status')]
    #[DataSet(["BEGIN:VCALENDAR\nMETHOD:request\nBEGIN:VEVENT\nEND:VEVENT\nEND:VCALENDAR", 'Invitation'], 'request')]
    #[DataSet(["BEGIN:VCALENDAR\nBEGIN:VEVENT\nEND:VEVENT\nMETHOD:REPLY\nEND:VCALENDAR", 'Reply'], 'method after the event')]
    #[DataSet(["BEGIN:VCALENDAR\nMETHOD:PUBLISH\nBEGIN:VEVENT\nEND:VEVENT\nEND:VCALENDAR", 'Calendar event'], 'published')]
    #[DataSet(["BEGIN:VEVENT\nEND:VEVENT", 'Calendar event'], 'no method')]
    #[DataSet(["BEGIN:VCALENDAR\nBEGIN:VEVENT\nMETHOD:CANCEL\nEND:VEVENT\nEND:VCALENDAR", 'Calendar event'], 'method inside the event')]
    #[DataSet(["METHOD:CANCEL\nBEGIN:VEVENT\nEND:VEVENT", 'Calendar event'], 'method outside a calendar')]
    public function testStateOfInvite(string $ics, string $state): void
    {
        $invite = Calendar::read($ics);

        Assert::notNull($invite);
        Assert::same(Calendar::state($invite), $state);
    }

    public function testReadsTheFirstEventOnlyAndSkipsItsAlarms(): void
    {
        $invite = Calendar::read(self::ics(<<<'ICS'
            BEGIN:VCALENDAR
            BEGIN:VTIMEZONE
            TZID:Europe/Berlin
            BEGIN:STANDARD
            DTSTART:19701025T030000
            END:STANDARD
            END:VTIMEZONE
            BEGIN:VEVENT
            SUMMARY:First
            BEGIN:VALARM
            DESCRIPTION:Reminder
            BEGIN:X-NESTED
            SUMMARY:Nested
            END:X-NESTED
            END:VALARM
            DESCRIPTION:Of the event
            END:VEVENT
            BEGIN:VEVENT
            SUMMARY:Second
            END:VEVENT
            END:VCALENDAR
            ICS));

        Assert::same($invite, ['summary' => 'First', 'description' => 'Of the event']);
    }

    #[DataSet([''], 'empty')]
    #[DataSet(['no calendar here'], 'plain text')]
    #[DataSet(["BEGIN:VCALENDAR\nMETHOD:REQUEST\nEND:VCALENDAR"], 'calendar without an event')]
    #[DataSet(["BEGIN;X=\"a:VEVENT\nBEGIN\nEND:VEVENT"], 'line with an unclosed quote')]
    public function testTextWithoutEventIsNoInvite(string $ics): void
    {
        Assert::null(Calendar::read($ics));
    }

    public function testMalformedLinesAreSkipped(): void
    {
        $invite = Calendar::read(
            "BEGIN:VEVENT\n:\nSUMMARY\n;;;:x\nATTENDEE;PARTSTAT;CN=\"Bob:mailto:bob@example.com\n"
            . "ATTENDEE;=;==:\nDTSTART;VALUE=DATE:\nSUMMARY:Kept\n\x00\xFF\xFE:\x80\nEND:VEVENT",
        );

        Assert::same($invite, ['summary' => 'Kept', 'all_day' => true]);
    }

    public function testKeepsTwoHundredAttendees(): void
    {
        $invite = Calendar::read(
            "BEGIN:VEVENT\n" . \str_repeat("ATTENDEE;PARTSTAT=ACCEPTED:mailto:guest@example.com\n", 1000) . 'END:VEVENT',
        );

        Assert::count($invite['attendees'] ?? [], 200);
    }

    public function testCutsDescriptionToFourThousandCharacters(): void
    {
        $invite = Calendar::read("BEGIN:VEVENT\nDESCRIPTION:" . \str_repeat('ж', 5000) . "\nEND:VEVENT");

        Assert::same($invite['description'] ?? null, \str_repeat('ж', 4000));
    }

    public function testReadsTheFirstMegabyteOnly(): void
    {
        $invite = Calendar::read(
            "BEGIN:VEVENT\nSUMMARY:Big\nX-PAD:" . \str_repeat('x', 3 * Calendar::MAX_INPUT) . "\nSUMMARY:Too far\nEND:VEVENT",
        );

        Assert::same($invite, ['summary' => 'Big']);
    }

    #[DataSet(['text/calendar', null, true], 'calendar type')]
    #[DataSet(['application/ics', 'invite', true], 'ics type')]
    #[DataSet(['application/octet-stream', 'Invite.ICS', true], 'file name')]
    #[DataSet(['text/plain', 'notes.txt', false], 'other file')]
    public function testIsCalendar(string $type, ?string $fileName, bool $expected): void
    {
        Assert::same(Calendar::isCalendar($type, $fileName), $expected);
    }

    /**
     * @return array<string, true>
     */
    private static function currentZones(): array
    {
        $lines = \file(\dirname(__DIR__, 3) . '/Stub/tzdata/zone-tab.txt', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $lines = \array_filter($lines ?: [], static fn(string $line): bool => !\str_starts_with($line, '#'));

        return \array_fill_keys($lines, true);
    }

    private static function ics(string $text): string
    {
        return \str_replace("\n", "\r\n", $text);
    }
}

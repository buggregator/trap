<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer;

/**
 * Reads the invite of an iCalendar text: the first VEVENT and the METHOD of the calendar.
 *
 * Times are RFC 3339 when the zone is known: UTC, or a TZID that {@see self::zone()} resolves, with `tz` naming
 * the IANA zone. Otherwise they are local `2026-12-03T14:00:00` with `tz` as written; a date for an all-day event.
 * Empty fields are left out: the web UI takes a missing field for an empty one.
 *
 * @psalm-type Person = array{name?: string, email?: string}
 * @psalm-type Attendee = array{name?: string, email?: string, status?: string, role?: string}
 * @psalm-type Invite = array{
 *     method?: string,
 *     summary: string,
 *     start?: string,
 *     end?: string,
 *     tz?: string,
 *     all_day?: true,
 *     location?: string,
 *     description?: string,
 *     url?: string,
 *     status?: string,
 *     uid?: string,
 *     sequence?: string,
 *     rrule?: string,
 *     organizer?: Person,
 *     attendees?: list<Attendee>,
 * }
 *
 * @internal
 */
final class Calendar
{
    /** Bytes of the text read: the event comes before this in any real invite. */
    public const MAX_INPUT = 1 << 20;

    private const MAX_ATTENDEES = 200;

    /** Characters of the description kept. */
    private const MAX_DESCRIPTION = 4000;

    /** Nesting of components kept track of; deeper ones are skipped. */
    private const MAX_DEPTH = 32;

    private const FORMAT_DATE = 'date';
    private const FORMAT_LOCAL = 'local';
    private const FORMAT_UTC = 'utc';
    private const FORMAT_ZONED = 'zoned';

    /** The fields in the order of the web UI's type. */
    private const FIELDS = [
        'method', 'summary', 'start', 'end', 'tz', 'all_day', 'location', 'description', 'url', 'status', 'uid',
        'sequence', 'rrule', 'organizer', 'attendees',
    ];

    /** @var array<string, true>|null */
    private static ?array $zones = null;

    /**
     * @return Invite|null Null when the text has no VEVENT.
     */
    public static function read(string $ics): ?array
    {
        $ics = \substr($ics, 0, self::MAX_INPUT);
        // A line that starts with a space or a tab continues the previous one
        $ics = \str_replace(["\r\n", "\n ", "\n\t"], ["\n", '', ''], $ics);

        $invite = ['summary' => ''];
        $attendees = [];
        $method = '';
        $start = $duration = null;
        // The components the line is in, as `VCALENDAR`, `VEVENT`, `VALARM`; `$event` is the depth of the event read
        $stack = [];
        $event = 0;
        foreach (\explode("\n", $ics) as $raw) {
            $line = self::parseLine(\rtrim($raw, "\r"));
            if ($line === null) {
                continue;
            }

            [$name, $params, $value] = $line;
            if ($name === 'BEGIN') {
                $component = \strtoupper(\trim($value));
                if (\count($stack) >= self::MAX_DEPTH) {
                    continue;
                }
                $stack[] = $component;
                $event === 0 && $component === 'VEVENT' and $event = \count($stack);
                continue;
            }

            if ($name === 'END') {
                // Closes the innermost open component of that name, and any left open inside it
                $at = \array_search(\strtoupper(\trim($value)), \array_reverse($stack, true), true);
                if (\is_int($at)) {
                    // The event or a component around it has ended: the first event is read
                    $at < $event and $event = -1;
                    $stack = \array_slice($stack, 0, $at);
                }
                continue;
            }

            // A method of the scheduling message (RFC 5546) belongs to the calendar, not to a component of it
            if ($name === 'METHOD' && $stack === ['VCALENDAR']) {
                $method = \strtoupper(\trim($value));
                continue;
            }

            if ($event <= 0 || \count($stack) !== $event) {
                continue;
            }

            switch ($name) {
                case 'SUMMARY':
                    $invite['summary'] = self::text($value);
                    break;
                case 'DTSTART':
                    $start = self::time($params, $value);
                    [$invite['start'], $invite['tz'], $invite['all_day']] = $start;
                    break;
                case 'DTEND':
                    $invite['end'] = self::time($params, $value)[0];
                    break;
                case 'DURATION':
                    $duration = self::duration($value);
                    break;
                case 'LOCATION':
                    $invite['location'] = self::text($value);
                    break;
                case 'DESCRIPTION':
                    $invite['description'] = \mb_substr(self::text($value), 0, self::MAX_DESCRIPTION, 'UTF-8');
                    break;
                case 'URL':
                    $invite['url'] = $value;
                    break;
                case 'STATUS':
                    $invite['status'] = \strtoupper($value);
                    break;
                case 'UID':
                    $invite['uid'] = $value;
                    break;
                case 'SEQUENCE':
                    $invite['sequence'] = $value;
                    break;
                case 'RRULE':
                    $invite['rrule'] = $value;
                    break;
                case 'ORGANIZER':
                    $invite['organizer'] = self::person($params, $value);
                    break;
                case 'ATTENDEE':
                    $person = self::person($params, $value);
                    // A guest with neither a name nor an address is no one to show
                    $person === [] || \count($attendees) >= self::MAX_ATTENDEES or $attendees[] = $person + \array_filter(
                        [
                            'status' => \strtoupper($params['PARTSTAT'] ?? ''),
                            'role' => \strtoupper($params['ROLE'] ?? ''),
                        ],
                        static fn(string $v): bool => $v !== '',
                    );
                    break;
            }
        }

        if ($event === 0) {
            return null;
        }

        if ($start !== null && !isset($invite['end'])) {
            // RFC 5545: the end is the start plus the duration; an all-day event without either lasts the day,
            // and its duration is whole days
            $start[2] && $duration !== null && ($duration->d === 0 || $duration->h + $duration->i + $duration->s > 0)
                and $duration = null;
            $length = $duration ?? ($start[2] ? new \DateInterval('P1D') : null);
            $length === null || $start[3] === null or $invite['end'] = self::format($start[3]->add($length), $start[4]);
        }

        $invite['method'] = $method;
        $invite['attendees'] = $attendees;
        $ordered = [];
        foreach (self::FIELDS as $field) {
            /** @var string|bool|array $value */
            $value = $invite[$field] ?? '';
            $field === 'summary' || ($value !== '' && $value !== false && $value !== []) and $ordered[$field] = $value;
        }

        /** @var Invite $ordered */
        return $ordered;
    }

    /**
     * What the invite is, as the web UI names it: `Invitation`, `Cancelled`, `Reply` or `Calendar event`.
     *
     * @param Invite $invite
     */
    public static function state(array $invite): string
    {
        $method = $invite['method'] ?? '';
        return match (true) {
            $method === 'CANCEL' || ($invite['status'] ?? '') === 'CANCELLED' => 'Cancelled',
            $method === 'REQUEST' => 'Invitation',
            $method === 'REPLY' => 'Reply',
            default => 'Calendar event',
        };
    }

    /**
     * Whether a part or an attachment carries iCalendar data.
     */
    public static function isCalendar(string $mediaType, ?string $fileName = null): bool
    {
        return $mediaType === 'text/calendar'
            || $mediaType === 'application/ics'
            || \str_ends_with(\strtolower((string) $fileName), '.ics');
    }

    /**
     * Splits `NAME;P1=V1;P2="V 2":value`: the value starts at the first colon outside double quotes.
     *
     * @return array{string, array<string, string>, string}|null Null for a line without a value.
     */
    private static function parseLine(string $line): ?array
    {
        $parts = self::splitOutsideQuotes($line, ':', 1);
        if (\count($parts) < 2) {
            return null;
        }

        $head = self::splitOutsideQuotes($parts[0], ';');
        $params = [];
        foreach (\array_slice($head, 1) as $param) {
            $pair = \explode('=', $param, 2);
            \count($pair) === 2 and $params[\strtoupper($pair[0])] = \trim($pair[1], '"');
        }

        return [\strtoupper($head[0]), $params, $parts[1]];
    }

    /**
     * @param non-empty-string $separator One character.
     * @param int<0, max>|null $limit Splits at most this many times.
     * @return non-empty-list<string>
     */
    private static function splitOutsideQuotes(string $text, string $separator, ?int $limit = null): array
    {
        $result = [];
        $quoted = false;
        $start = $i = 0;
        $length = \strlen($text);
        while (($limit === null || \count($result) < $limit)
            && ($i += \strcspn($text, $quoted ? '"' : '"' . $separator, $i)) < $length
        ) {
            if ($text[$i] === '"') {
                $quoted = !$quoted;
            } else {
                $result[] = \substr($text, $start, $i - $start);
                $start = $i + 1;
            }
            ++$i;
        }
        $result[] = \substr($text, $start);

        return $result;
    }

    /**
     * Undoes the escaping of iCalendar text: `\n`, `\,`, `\;`, `\\`.
     */
    private static function text(string $value): string
    {
        return \strtr($value, ['\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\']);
    }

    /**
     * @param array<string, string> $params
     * @return array{name?: string, email?: string}
     */
    private static function person(array $params, string $value): array
    {
        \strncasecmp($value, 'mailto:', 7) === 0 and $value = \substr($value, 7);

        return \array_filter(['name' => $params['CN'] ?? '', 'email' => $value], static fn(string $v): bool => $v !== '');
    }

    /**
     * A DTSTART or DTEND value: RFC 3339 when the zone is known, local time and the zone's name when not,
     * a date for `VALUE=DATE`. A value that is no time stays as written.
     *
     * @param array<string, string> $params
     * @return array{string, string, bool, \DateTimeImmutable|null, self::FORMAT_*} The time, the zone, whether
     *         the event lasts all day, and the time to add a duration to with the format to write the sum in.
     */
    private static function time(array $params, string $value): array
    {
        $value = \trim($value);
        if (\strcasecmp($params['VALUE'] ?? '', 'DATE') === 0 || \strlen($value) === 8) {
            return \preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) === 1 && \checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? ["$m[1]-$m[2]-$m[3]", '', true, new \DateTimeImmutable("$m[1]-$m[2]-$m[3]", new \DateTimeZone('UTC')), self::FORMAT_DATE]
                : [$value, '', true, null, self::FORMAT_DATE];
        }

        $utc = \str_ends_with($value, 'Z');
        $tzid = $params['TZID'] ?? '';
        if (\preg_match('/^(\d{4})(\d{2})(\d{2})T([01]\d|2[0-3])([0-5]\d)([0-5]\d)Z?$/', $value, $m) !== 1
            || !\checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            return [$value, $utc ? '' : $tzid, false, null, self::FORMAT_LOCAL];
        }

        $local = "$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]";
        if ($utc) {
            return ["{$local}Z", 'UTC', false, new \DateTimeImmutable($local, new \DateTimeZone('UTC')), self::FORMAT_UTC];
        }

        $zone = self::zone($tzid);
        if ($zone !== null && $zone !== '') {
            $time = new \DateTimeImmutable($local, new \DateTimeZone($zone));
            return [self::format($time, self::FORMAT_ZONED), $zone, false, $time, self::FORMAT_ZONED];
        }

        $time = new \DateTimeImmutable($local, new \DateTimeZone('UTC'));
        return [$local, $tzid, false, $time, self::FORMAT_LOCAL];
    }

    /**
     * @param self::FORMAT_* $format
     */
    private static function format(\DateTimeImmutable $time, string $format): string
    {
        return match ($format) {
            self::FORMAT_DATE => $time->format('Y-m-d'),
            self::FORMAT_LOCAL => $time->format('Y-m-d\TH:i:s'),
            self::FORMAT_UTC => $time->format('Y-m-d\TH:i:s\Z'),
            // RFC 3339 as the UI reads it: `Z` for any zero offset
            self::FORMAT_ZONED => \str_replace('+00:00', 'Z', $time->format('Y-m-d\TH:i:sP')),
        };
    }

    /**
     * A DURATION value (RFC 5545 §3.3.6), as `PT1H30M`, `P1D` or `P2W`; null for a negative or malformed one.
     */
    private static function duration(string $value): ?\DateInterval
    {
        $value = \trim($value);
        if (\preg_match('/\d/', $value) !== 1 || \preg_match(
            '/^\+?P(?:(\d{1,6})W|(?:(\d{1,6})D)?(?:T(?=\d)(?:(\d{1,6})H)?(?:(\d{1,6})M)?(?:(\d{1,6})S)?)?)$/i',
            $value,
            $m,
        ) !== 1) {
            return null;
        }

        return new \DateInterval(\sprintf(
            'P%dDT%dH%dM%dS',
            7 * (int) ($m[1] ?? 0) + (int) ($m[2] ?? 0),
            (int) ($m[3] ?? 0),
            (int) ($m[4] ?? 0),
            (int) ($m[5] ?? 0),
        ));
    }

    /**
     * The IANA zone of a TZID, the same on every platform. Tried in this order, each name through {@see known()}:
     * 1. the TZID without the `/` that marks a globally unique ID;
     * 2. the zone a vendor path ends with, as `/mozilla.org/20050126_1/Europe/Berlin`, longest first;
     *    a single last segment, as `EST5EDT`, only after a vendor domain, so `Foo/Bar/GMT` is no zone;
     * 3. the zone of a Windows name, as Outlook writes `W. Europe Standard Time`, when ext-intl is loaded.
     */
    private static function zone(string $tzid): ?string
    {
        $tzid = \ltrim($tzid, '/');
        if ($tzid === '') {
            return null;
        }

        $zone = self::known($tzid);
        if ($zone !== null) {
            return $zone;
        }

        $segments = \explode('/', $tzid);
        $vendor = \count($segments) > 1 && \str_contains($segments[0], '.');
        for ($i = 1, $count = \count($segments); $i < $count - ($vendor ? 0 : 1); ++$i) {
            $zone = self::known(\implode('/', \array_slice($segments, $i)));
            if ($zone !== null) {
                return $zone;
            }
        }

        $windows = self::intl(static fn(): string|false => \IntlTimeZone::getIDForWindowsID($tzid));
        return $windows === null ? null : self::known($windows);
    }

    /**
     * The canonical zone of a name the time zone database knows, as written: `DateTimeZone` also takes
     * abbreviations, offsets and any letter case, and reads `EST` as an abbreviation.
     *
     * A link of the `backward` file of tzdata, as `Asia/Calcutta` or `US/Eastern`, is in the build of PHP
     * only when the system has `tzdata-legacy`, and ICU gives such names for Windows zones,
     * so {@see ZoneLinks} maps it to its zone. A platform older than the zone keeps the link.
     */
    private static function known(string $name): ?string
    {
        $zones = self::$zones ??= \array_fill_keys(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
        $candidates = [
            ZoneLinks::TARGETS[$name] ?? $name,
            // Only an Area/Location name: ICU also takes abbreviations, as `PST` and `IST`, for zones
            \str_contains($name, '/')
                ? self::intl(static fn(): string|false => self::ianaId($name))
                : null,
            $name,
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '' && isset($zones[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The IANA name ICU gives, with PHP 8.4 and ICU 74 or later.
     *
     * @psalm-suppress MixedReturnStatement, MixedInferredReturnType, UndefinedMethod
     */
    private static function ianaId(string $name): string|false
    {
        return \method_exists(\IntlTimeZone::class, 'getIanaID') ? \IntlTimeZone::getIanaID($name) : false;
    }

    /**
     * A name ext-intl gives, null without ext-intl or on a failure: by the intl settings an unknown name throws
     * an `IntlException`, warns, or sets the error code only.
     *
     * @param \Closure(): (string|false) $lookup
     */
    private static function intl(\Closure $lookup): ?string
    {
        if (!\class_exists(\IntlTimeZone::class)) {
            return null;
        }

        try {
            $name = @$lookup();
        } catch (\IntlException) {
            return null;
        }

        return \is_string($name) && !\intl_is_failure(\intl_get_error_code()) ? $name : null;
    }
}

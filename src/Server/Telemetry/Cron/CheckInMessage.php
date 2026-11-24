<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Cron;

/**
 * A check-in as received: a `check_in` envelope item, a call of the Sentry cron HTTP endpoint or a ping URL.
 *
 * @internal
 */
final class CheckInMessage
{
    public const IN_PROGRESS = 'in_progress';
    public const OK = 'ok';
    public const ERROR = 'error';
    public const STATUSES = [self::IN_PROGRESS, self::OK, self::ERROR];
    public const SOURCE_SENTRY = 'sentry';
    public const SOURCE_PING = 'ping';

    /** Sentry cuts monitor slugs to this length */
    public const MAX_SLUG = 50;

    /** The tail of a ping body Trap keeps: the end of the job's output */
    public const MAX_OUTPUT = 10240;

    /**
     * @param non-empty-string $project
     * @param non-empty-string $slug See {@see self::slug()}.
     * @param string $checkInId See {@see self::checkInId()}; empty for none.
     * @param value-of<self::STATUSES> $status
     * @param float $time Unix seconds when Trap received it.
     * @param float|null $duration Seconds the run took, as the SDK measured it.
     * @param self::SOURCE_* $source
     * @param string $output The tail of a ping body.
     */
    public function __construct(
        public readonly string $project,
        public readonly string $slug,
        public readonly string $checkInId,
        public readonly string $status,
        public readonly float $time,
        public readonly ?float $duration = null,
        public readonly string $environment = '',
        public readonly string $traceId = '',
        public readonly string $source = self::SOURCE_SENTRY,
        public readonly string $output = '',
        public readonly ?MonitorConfig $config = null,
    ) {}

    /**
     * A monitor slug as Sentry makes it of what the SDK sends: lower case ASCII letters, digits, `_` and `-`,
     * other characters dropped, runs of spaces and dashes one dash, at most 50 characters.
     *
     * @return non-empty-string|null Null when nothing is left.
     */
    public static function slug(string $value): ?string
    {
        $slug = (string) \preg_replace('/[^a-z0-9_\s-]/', '', \strtolower($value));
        $slug = \trim((string) \preg_replace('/[-\s]+/', '-', $slug), '-_');
        $slug = \trim(\substr($slug, 0, self::MAX_SLUG), '-');

        return $slug === '' ? null : $slug;
    }

    /**
     * A check-in ID in lower case without dashes: SDKs send UUIDs in both forms.
     */
    public static function checkInId(string $id): string
    {
        $id = \strtolower(\str_replace('-', '', \trim($id)));
        return \preg_match('/^[a-z0-9]{1,64}$/D', $id) === 1 ? $id : '';
    }

    /**
     * The last `$max` bytes, valid UTF-8: a cut character and invalid bytes are dropped.
     *
     * @param int<1, max> $max
     */
    public static function output(string $body, int $max = self::MAX_OUTPUT): string
    {
        \strlen($body) > $max and $body = \substr($body, -$max);
        if (\preg_match('//u', $body) === 1) {
            return $body;
        }

        return (string) \preg_replace(
            '/((?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}'
            . '|\xF4[\x80-\x8F][\x80-\xBF]{2})*+)./s',
            '$1',
            $body,
        );
    }
}

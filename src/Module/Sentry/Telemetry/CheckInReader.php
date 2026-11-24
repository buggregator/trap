<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Telemetry;

use Buggregator\Trap\Server\Telemetry\Cron\CheckInMessage;
use Buggregator\Trap\Server\Telemetry\Cron\MonitorConfig;

/**
 * Reads a Sentry Crons check-in: a `check_in` envelope item, or the body of the cron HTTP endpoint
 * `{"check_in_id", "monitor_slug", "status", "duration", "environment", "monitor_config", "contexts": {"trace"}}`.
 *
 * A check-in that the {@see \Buggregator\Trap\Module\Sentry\Http\CronTrap} made of a ping URL has the item header
 * `source: ping` and the job's output in `output`.
 *
 * @internal
 */
final class CheckInReader
{
    public const MAX_ENVIRONMENT = 64;

    /**
     * @param non-empty-string $project
     * @param float $time Unix seconds when Trap received it.
     * @param array<array-key, mixed> $headers The item headers.
     * @return array{CheckInMessage|null, string|null} The check-in, null for one Trap can't take; and why it
     *         was dropped, or why its monitor config was ignored.
     */
    public static function read(string $project, array $payload, float $time, array $headers = []): array
    {
        /** @var mixed $slug */
        $slug = $payload['monitor_slug'] ?? null;
        $slug = \is_string($slug) ? CheckInMessage::slug($slug) : null;
        if ($slug === null) {
            return [null, 'the check-in has no monitor slug'];
        }

        /** @var mixed $status */
        $status = $payload['status'] ?? null;
        if (!\in_array($status, CheckInMessage::STATUSES, true)) {
            return [null, \sprintf('the check-in of `%s` has no status of in_progress, ok or error', $slug)];
        }

        $warning = null;
        $config = null;
        /** @var mixed $rawConfig */
        $rawConfig = $payload['monitor_config'] ?? null;
        try {
            $rawConfig === null || \is_array($rawConfig) && ($rawConfig === [] || !\array_is_list($rawConfig))
                or throw new \InvalidArgumentException('it is no object');
            \is_array($rawConfig) and $config = MonitorConfig::fromArray($rawConfig);
        } catch (\InvalidArgumentException $e) {
            $warning = \sprintf('the monitor config of `%s` is ignored: %s', $slug, $e->getMessage());
        }

        $ping = ($headers['source'] ?? null) === CheckInMessage::SOURCE_PING;
        /** @var mixed $duration */
        $duration = $payload['duration'] ?? null;
        /** @var mixed $contexts */
        $contexts = $payload['contexts'] ?? null;
        /** @var mixed $trace */
        $trace = \is_array($contexts) ? $contexts['trace'] ?? null : null;
        $trace = \is_array($trace) ? $trace : [];

        return [
            new CheckInMessage(
                project: $project,
                slug: $slug,
                checkInId: CheckInMessage::checkInId(self::string($payload['check_in_id'] ?? null)),
                status: $status,
                time: $time,
                duration: \is_int($duration) || \is_float($duration) ? (float) $duration : null,
                environment: self::environment($payload['environment'] ?? null),
                traceId: self::traceId($trace['trace_id'] ?? null),
                source: $ping ? CheckInMessage::SOURCE_PING : CheckInMessage::SOURCE_SENTRY,
                output: $ping ? CheckInMessage::output(self::string($payload['output'] ?? null)) : '',
                config: $config,
            ),
            $warning,
        ];
    }

    /**
     * At most the 64 characters Sentry takes of an environment name.
     */
    private static function environment(mixed $value): string
    {
        $value = self::string($value);
        return \preg_match('/^.{0,' . self::MAX_ENVIRONMENT . '}/su', $value, $match) === 1
            ? $match[0]
            : \substr($value, 0, self::MAX_ENVIRONMENT);
    }

    /**
     * 32 hex digits in lower case; empty for anything else.
     */
    private static function traceId(mixed $value): string
    {
        $id = \strtolower(\str_replace('-', '', self::string($value)));
        return \preg_match('/^[0-9a-f]{32}$/D', $id) === 1 ? $id : '';
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? \trim($value) : '';
    }
}

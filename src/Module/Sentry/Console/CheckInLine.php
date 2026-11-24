<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Console;

use Buggregator\Trap\Module\Sentry\Telemetry\CheckInReader;
use Buggregator\Trap\Server\Telemetry\Cron\CheckInMessage;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A cron check-in in a few lines: the job, the status and duration, the monitor config it sent, the end of
 * a ping's output, and why Trap dropped the check-in or ignored its config.
 *
 * @internal
 */
final class CheckInLine
{
    /** Lines of a ping's output the console shows: its end */
    private const OUTPUT_LINES = 5;

    /**
     * @param array<array-key, mixed> $headers The item headers.
     */
    public static function render(OutputInterface $output, array $payload, array $headers = []): void
    {
        [$checkIn, $warning] = CheckInReader::read('default', $payload, 0.0, $headers);
        $lines = [''];
        if ($checkIn !== null) {
            $lines[] = self::line($checkIn);
            $config = $checkIn->config;
            if ($config !== null) {
                $lines[] = '<fg=gray>Monitor:</> ' . self::text(\implode(', ', \array_filter([
                    $config->schedule?->describe() ?? '',
                    $config->timezone ?? '',
                    $config->margin === null ? '' : "margin $config->margin min",
                    $config->maxRuntime === null ? '' : "max runtime $config->maxRuntime min",
                ], static fn(string $part): bool => $part !== '')));
            }

            $tail = self::tail($checkIn->output);
            $tail === [] or $lines = [...$lines, '<fg=gray>Output:</>', ...\array_map(self::text(...), $tail)];
        }

        $warning === null or $lines[] = '<fg=red>' . self::text(\ucfirst($warning)) . '.</>';
        $output->writeln($lines);
    }

    private static function line(CheckInMessage $checkIn): string
    {
        $status = match ($checkIn->status) {
            CheckInMessage::OK => '<fg=green;options=bold>ok</>',
            CheckInMessage::ERROR => '<fg=red;options=bold>error</>',
            default => '<fg=cyan>in progress</>',
        };
        $parts = \array_filter([
            $status,
            $checkIn->duration === null ? '' : \sprintf('<fg=yellow>%s s</>', \round($checkIn->duration, 3)),
            $checkIn->environment === '' ? '' : 'env ' . self::text($checkIn->environment),
            $checkIn->checkInId === '' ? '' : '<fg=gray>check-in ' . self::text($checkIn->checkInId) . '</>',
        ], static fn(string $part): bool => $part !== '');

        return \sprintf(
            '<fg=gray>%s</> <options=bold>%s</>: %s',
            $checkIn->source === CheckInMessage::SOURCE_PING ? 'Cron ping' : 'Cron check-in',
            self::text($checkIn->slug),
            \implode(', ', $parts),
        );
    }

    /**
     * @return list<string>
     */
    private static function tail(string $output): array
    {
        $lines = \array_values(\array_filter(
            \array_map(\rtrim(...), \preg_split('/\R/', \trim($output)) ?: []),
            static fn(string $line): bool => $line !== '',
        ));

        return \array_slice($lines, -self::OUTPUT_LINES);
    }

    private static function text(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer\Sentry;

use Buggregator\Trap\Sender\Console\Support\Common;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap\Sender\Console\Renderer
 */
final class Exceptions
{
    /**
     * Render Exceptions block
     */
    public static function render(OutputInterface $output, mixed $exceptions): void
    {
        if (!\is_array($exceptions)) {
            return;
        }

        $exceptions = \array_filter(
            $exceptions,
            static fn(mixed $exception): bool => \is_array($exception),
        );

        if (\count($exceptions) === 0) {
            return;
        }

        Common::renderHeader2($output, 'Exceptions');

        foreach ($exceptions as $exception) {
            // Exception type
            $output->writeln(\sprintf(
                '<fg=red;options=bold>%s</>',
                $exception['type'] ?? 'Exception',
            ));

            isset($exception['value']) and $output->writeln($exception['value']);

            $output->writeln('');

            try {
                // Stacktrace
                $stacktrace = $exception['stacktrace']['frames'] ?? null;
                \is_array($stacktrace) and self::renderTrace($output, $stacktrace);
            } catch (\Throwable $e) {
                $output->writeln(\sprintf('  <fg=red>Unable to render stacktrace: %s</>', $e->getMessage()));
            }
        }
    }

    /**
     * Renders the trace of the exception.
     *
     * @psalm-suppress RiskyTruthyFalsyComparison
     */
    private static function renderTrace(OutputInterface $output, array $frames, bool $verbose = false): void
    {
        if ($frames === []) {
            return;
        }

        $getValue = static fn(array $frame, string $key, string $default = ''): string|int|float|bool =>
        isset($frame[$key]) && \is_scalar($frame[$key]) ? $frame[$key] : $default;

        $i = \count($frames) ;
        $numPad = \strlen((string) ($i - 1)) + 2;
        // Skipped frames
        $vendorLines = [];
        $isFirst = true;

        foreach (\array_reverse($frames) as $frame) {
            $i--;
            if (!\is_array($frame)) {
                continue;
            }

            $file = $getValue($frame, 'filename');
            $line = $getValue($frame, 'lineno');
            $class = $getValue($frame, 'class');
            /** @psalm-suppress RiskyTruthyFalsyComparison */
            $class = empty($class) ? '' : $class . '::';
            $function = $getValue($frame, 'function');

            $renderedLine = \sprintf(
                "<fg=gray>%s</><fg=white;options=bold>%s<fg=yellow>%s</>\n%s<fg=yellow>%s</><fg=gray>%s()</>",
                \str_pad("#$i", $numPad, ' '),
                (string) $file,
                $line !== '' ? ":$line" : '',
                \str_repeat(' ', $numPad),
                $class,
                (string) $function,
            );

            if ($isFirst) {
                $isFirst = false;
                $output->writeln('Stacktrace:');
                $output->writeln($renderedLine);
                self::renderCodeSnippet($output, $frame, padding: $numPad);
                continue;
            }

            $renderer = static function () use ($output, $renderedLine): void {
                $output->writeln($renderedLine);
            };
            if (!$verbose && \str_starts_with(\ltrim(\str_replace('\\', '/', $file), './'), 'vendor/')) {
                $vendorLines[] = $renderer;
                continue;
            }

            if (\count($vendorLines) > 2) {
                $output->writeln(\sprintf(
                    '%s<fg=cyan>... %d hidden vendor frames ...</>',
                    \str_repeat(' ', $numPad),
                    \count($vendorLines),
                ));
                $vendorLines = [];
            }
            \array_map(static fn(callable $renderer) => $renderer(), $vendorLines);
            $vendorLines = [];
            $renderer();
        }
    }

    /**
     * Renders the code snippet around an exception.
     */
    private static function renderCodeSnippet(OutputInterface $output, array $frame, int $padding = 0): void
    {
        if (!isset($frame['context_line']) || !\is_string($frame['context_line'])) {
            return;
        }
        $minPadding = 80;
        $calcPadding = static fn(string $row): int => \strlen($row) - \strlen(\ltrim($row, ' '));
        $content = [];

        try {
            $startLine = (int) $frame['lineno'];
            if (isset($frame['pre_context']) && \is_array($frame['pre_context'])) {
                foreach ($frame['pre_context'] as $row) {
                    if (!\is_string($row)) {
                        continue;
                    }

                    $minPadding = \min($minPadding, $calcPadding($row));
                    --$startLine;
                    $content[] = $row;
                }
            }

            $content[] = $frame['context_line'];
            $minPadding = \min($minPadding, $calcPadding($frame['context_line']));
            $contextLine = \array_key_last($content);

            if (isset($frame['post_context']) && \is_array($frame['post_context'])) {
                foreach ($frame['post_context'] as $row) {
                    if (!\is_string($row)) {
                        continue;
                    }

                    $minPadding = \min($minPadding, $calcPadding($row));
                    $content[] = $row;
                }
            }

            Common::hr($output, 'white', padding: $padding);
            $strPad = \strlen((string) ($startLine + \count($content) - 1));
            $paddingStr = \str_repeat(' ', $padding);
            foreach ($content as $line => $row) {
                $output->writeln(
                    \sprintf(
                        '%s<fg=%s>%s</>▕<fg=%s;options=bold>%s</>',
                        $paddingStr,
                        $line === $contextLine ? 'red' : 'gray',
                        \str_pad((string) ($startLine + $line), $strPad, ' ', \STR_PAD_LEFT),
                        $line === $contextLine ? 'red' : 'blue',
                        \substr($row, $minPadding),
                    ),
                );
            }
            Common::hr($output, 'white', padding: $padding);
        } catch (\Throwable) {
        }
    }
}

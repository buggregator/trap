<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\RendererInterface;
use Buggregator\Trap\Sender\Console\Support\Common;
use DateTimeImmutable;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class SentryStore implements RendererInterface
{
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Sentry && $frame instanceof Frame\Sentry\SentryStore;
    }

    /**
     * @param \Buggregator\Trap\Proto\Frame\Sentry\SentryStore $frame
     * @throws \JsonException
     */
    public function render(OutputInterface $output, Frame $frame): void
    {
        // Collect metadata
        $meta = [];
        try {
            $time = new DateTimeImmutable($frame->message['timestamp']);
        } catch (\Throwable) {
            $time = $frame->time;
        }
        $meta['Time'] = $time->format('Y-m-d H:i:s.u');
        isset($frame->message['event_id']) and $meta['Event ID'] = $frame->message['event_id'];
        isset($frame->message['transaction']) and $meta['Transaction'] = $frame->message['transaction'];
        isset($frame->message['server_name']) and $meta['Server'] = $frame->message['server_name'];

        // Metadata from context
        if (isset($frame->message['contexts']) && \is_array($frame->message['contexts'])) {
            $context = $frame->message['contexts'];
            isset($context['runtime']) and $meta['Runtime'] = \implode(' ', (array)$context['runtime']);
            isset($context['os']) and $meta['OS'] = \implode(' ', (array)$context['os']);
        }
        isset($frame->message['sdk']) and $meta['SDK'] = \implode(' ', (array)$frame->message['sdk']);

        Common::renderHeader1($output, 'SENTRY');
        Common::renderMetadata($output, $meta);

        // Render short content values as tags
        $tags = $this->pullTagsFromMessage($frame->message, [
            'level' => 'level',
            'platform' => 'platform',
            'environment' => 'env',
            'logger' => 'logger',
        ]);
        if ($tags !== []) {
            $output->writeln('');
            Common::renderTags($output, $tags);
        }

        // Render tags
        $tags = isset($message['tags']) && \is_array($message['tags']) ? $message['tags'] : [];
        if ($tags !== []) {
            Common::renderHeader2($output, 'Tags');
            Common::renderTags($output, $tags);
        }

        $this->rendererExceptions($output, $frame->message['exception']['values'] ?? []);
    }

    /**
     * Collect tags from message fields
     *
     * @param array<string, mixed> $message
     * @param array<string, string> $tags Key => Alias
     *
     * @return array<string, string>
     */
    public function pullTagsFromMessage(array $message, array $tags): array
    {
        $result = [];
        foreach ($tags as $key => $alias) {
            if (isset($message[$key]) && \is_string($message[$key])) {
                $result[$alias] ??= \implode(' ', (array)($message[$key]));
            }
        }

        return $result;
    }

    private function rendererExceptions(OutputInterface $output, mixed $exceptions): void
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
                isset($exception['type']) ? $exception['type'] : 'Exception',
            ));

            isset($exception['value']) and $output->writeln($exception['value']);

            $output->writeln('');

            try {
                // Stacktrace
                $stacktrace = $exception['stacktrace']['frames'] ?? null;
                \is_array($stacktrace) and $this->renderTrace($output, $stacktrace);
            } catch (\Throwable $e) {
                $output->writeln(\sprintf('  <fg=red>Unable to render stacktrace: %s</>', $e->getMessage()));
            }
        }
    }

    /**
     * Renders the trace of the exception.
     */
    protected function renderTrace(OutputInterface $output, array $frames, bool $verbose = false): void
    {
        if ($frames === []) {
            return;
        }
        $getValue = static fn(array $frame, string $key, ?string $default = ''): string|int|float|bool|null =>
            isset($frame[$key]) && \is_scalar($frame[$key]) ? $frame[$key] : $default;

        $i = \count($frames) ;
        $numPad = \strlen((string)($i - 1)) + 2;
        // Skipped frames
        $vendorLines = [];
        $isFirst = true;

        foreach (\array_reverse($frames) as $frame) {
            $i--;
            if (!\is_array($frame)) {
                continue;
            }

            $file = $getValue($frame, 'filename');
            $line = $getValue($frame, 'lineno', null);
            $class = $getValue($frame, 'class');
            $class = empty($class) ? '' : $class . '::';
            $function = $getValue($frame, 'function');

            $renderer = static fn() => $output->writeln(
                \sprintf(
                    "<fg=gray>%s</><fg=white;options=bold>%s<fg=yellow>%s</>\n%s<fg=yellow>%s</><fg=gray>%s()</>",
                    \str_pad("#$i", $numPad, ' '),
                    $file,
                    !$line ? '' : ":$line",
                    \str_repeat(' ', $numPad),
                    $class,
                    $function,
                )
            );

            if ($isFirst) {
                $isFirst = false;
                $output->writeln('Stacktrace:');
                $renderer();
                $this->renderCodeSnippet($output, $frame, padding: $numPad);
                continue;
            }

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
    private function renderCodeSnippet(OutputInterface $output, array $frame, int $padding = 0): void
    {
        if (!isset($frame['context_line']) || !\is_string($frame['context_line'])) {
            return;
        }
        $minPadding = 80;
        $calcPadding = static fn(string $row): int => \strlen($row) - \strlen(\ltrim($row, ' '));
        $content = [];

        try {
            $startLine = (int)$frame['lineno'];
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
            $strPad = \strlen((string)($startLine + \count($content) - 1));
            $paddingStr = \str_repeat(' ', $padding);
            foreach ($content as $line => $row) {
                $output->writeln(
                    \sprintf(
                        '%s<fg=%s>%s</>▕<fg=%s;options=bold>%s</>',
                        $paddingStr,
                        $line === $contextLine ? 'red' : 'gray',
                        \str_pad((string)($startLine + $line), $strPad, ' ', \STR_PAD_LEFT),
                        $line === $contextLine ? 'red' : 'blue',
                        \substr($row, $minPadding)
                    )
                );
            }
            Common::hr($output, 'white', padding: $padding);
        } catch (\Throwable) {
        }
    }
}

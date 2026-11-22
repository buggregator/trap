<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Console;

use Buggregator\Trap\Module\Sentry\Telemetry\ItemReader;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Transaction;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Transactions and v2 spans as a line per trace: their spans are shown by the web UI and the MCP trace tools.
 * A transaction without a trace ID, a feed event, gets a line of its own.
 *
 * @internal
 */
final class TraceLine
{
    /**
     * @param string $type An envelope item type, `transaction` for a transaction sent to the `store` endpoint.
     * @return bool False when the item is neither a transaction nor spans.
     */
    public static function render(OutputInterface $output, string $type, array $payload): bool
    {
        if (!\in_array($type, ['transaction', 'span', 'spans'], true)) {
            return false;
        }

        $batch = new Batch('default');
        ItemReader::read($batch, $type, $payload);

        /** @var array<array-key, Transaction> $roots */
        $roots = [];
        /** @var array<array-key, list<Span>> $spans */
        $spans = [];
        foreach ($batch->transactions as $transaction) {
            $roots[$transaction->traceId] ??= $transaction;
            $spans[$transaction->traceId] ??= [];
        }
        foreach ($batch->spans as $span) {
            $spans[$span->traceId][] = $span;
        }

        if ($spans === [] && $type === 'transaction') {
            $output->writeln(['', self::untraced($payload)]);
            return true;
        }

        $output->writeln('');
        $spans === [] and $output->writeln('<fg=gray>Spans without a trace ID</>');
        foreach ($spans as $traceId => $list) {
            $output->writeln(self::line((string) $traceId, $roots[$traceId] ?? self::rootSpan($list), $list));
        }

        return true;
    }

    /**
     * @param Transaction|Span|null $root The transaction, else the root span of the spans.
     * @param list<Span> $spans
     */
    private static function line(string $traceId, Transaction|Span|null $root, array $spans): string
    {
        $parts = [...$spans];
        $root instanceof Transaction and $parts[] = $root;
        $failed = \count(\array_filter($parts, static fn(Transaction|Span $part): bool => $part->isError()));
        $name = match (true) {
            $root instanceof Transaction => $root->name,
            $root instanceof Span => $root->description,
            default => '',
        };
        $op = $root?->op ?? '';
        $name = \trim($name . ($op === '' ? '' : " ($op)"));
        $duration = $root?->durationMs();
        $count = \count($parts);
        $line = \array_filter([
            $name === '' ? '' : '<options=bold>' . self::text($name) . '</>',
            $duration === null ? '' : "<fg=yellow>$duration ms</>",
            // The transaction counts as a span, as in `list_traces`.
            $count === 1 ? '1 span' : "$count spans",
            $failed === 0 ? '' : "<fg=red;options=bold>$failed failed</>",
        ], static fn(string $part): bool => $part !== '');

        return \sprintf('<fg=gray>Trace %s:</> %s', self::text($traceId), \implode(', ', $line));
    }

    /**
     * A transaction without a trace ID, a feed event: its spans are counted as the telemetry store would read them.
     */
    private static function untraced(array $payload): string
    {
        /** @var mixed $spans */
        $spans = $payload['spans'] ?? null;
        $count = 1;
        /** @var mixed $span */
        foreach (\is_array($spans) && \array_is_list($spans) ? $spans : [] as $span) {
            \is_array($span) && \is_string($span['span_id'] ?? null) && $span['span_id'] !== '' and ++$count;
        }

        /** @var mixed $name */
        $name = $payload['transaction'] ?? '';
        /** @var mixed $context */
        $context = $payload['contexts']['trace'] ?? null;
        /** @var mixed $op */
        $op = $payload['op'] ?? (\is_array($context) ? $context['op'] ?? '' : '');
        $name = \trim((\is_string($name) ? $name : '') . (\is_string($op) && $op !== '' ? " ($op)" : ''));
        $duration = Span::durationBetween(
            ItemReader::time($payload['start_timestamp'] ?? null),
            ItemReader::time($payload['timestamp'] ?? null),
        );
        $line = \array_filter([
            $name === '' ? '' : '<options=bold>' . self::text($name) . '</>',
            $duration === null ? '' : "<fg=yellow>$duration ms</>",
            $count === 1 ? '1 span' : "$count spans",
        ], static fn(string $part): bool => $part !== '');

        return '<fg=gray>Transaction without a trace ID:</> ' . \implode(', ', $line);
    }

    /**
     * The span without a parent, else the earliest one whose parent is not among them.
     *
     * @param list<Span> $spans
     */
    private static function rootSpan(array $spans): ?Span
    {
        $ids = \array_fill_keys(\array_map(static fn(Span $span): string => $span->spanId, $spans), true);
        $root = null;
        foreach ($spans as $span) {
            if ($span->parentSpanId !== null && isset($ids[$span->parentSpanId])) {
                continue;
            }

            $rank = [$span->parentSpanId === null ? 0 : 1, $span->start ?? \INF];
            if ($root === null || $rank < $root[0]) {
                $root = [$rank, $span];
            }
        }

        return $root[1] ?? null;
    }

    /**
     * Received text without console markup and control characters.
     */
    private static function text(string $value): string
    {
        return OutputFormatter::escape((string) \preg_replace('/[\x00-\x1F\x7F]/', '?', $value));
    }
}

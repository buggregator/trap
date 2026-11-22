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
 *
 * @internal
 */
final class TraceLine
{
    /**
     * @param string $type An envelope item type, `transaction` for a transaction sent to the `store` endpoint.
     * @return bool False when the item is not a trace.
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

        $output->writeln('');
        $spans === [] and $output->writeln('<fg=gray>A trace without a trace ID</>');
        foreach ($spans as $traceId => $list) {
            $output->writeln(self::line((string) $traceId, $roots[$traceId] ?? null, $list));
        }

        return true;
    }

    /**
     * @param list<Span> $spans
     */
    private static function line(string $traceId, ?Transaction $transaction, array $spans): string
    {
        $failed = \count(\array_filter($spans, static fn(Span $span): bool => $span->isError()))
            + ($transaction?->isError() ? 1 : 0);
        $name = $transaction === null ? '' : \trim($transaction->name . ($transaction->op === '' ? '' : " ($transaction->op)"));
        $duration = $transaction?->durationMs();
        $parts = \array_filter([
            $name === '' ? '' : '<options=bold>' . self::text($name) . '</>',
            $duration === null ? '' : "<fg=yellow>$duration ms</>",
            \count($spans) === 1 ? '1 span' : \count($spans) . ' spans',
            $failed === 0 ? '' : "<fg=red;options=bold>$failed failed</>",
        ], static fn(string $part): bool => $part !== '');

        return \sprintf('<fg=gray>Trace %s:</> %s', self::text($traceId), \implode(', ', $parts));
    }

    /**
     * Received text without console markup and control characters.
     */
    private static function text(string $value): string
    {
        return OutputFormatter::escape((string) \preg_replace('/[\x00-\x1F\x7F]/', '?', $value));
    }
}

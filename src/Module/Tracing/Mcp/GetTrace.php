<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\MaxLength;
use Buggregator\Trap\Module\Mcp\Tool\Time;
use Buggregator\Trap\Module\Tracing\Config\Traces;
use Buggregator\Trap\Module\Tracing\Console\SpanBuffer;
use Buggregator\Trap\Module\Tracing\Console\TraceTree;
use Buggregator\Trap\Server\Telemetry\Insight\Finding;
use Buggregator\Trap\Server\Telemetry\Insight\TraceInsights;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Telemetry\Trace;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A stored trace of a Sentry SDK or an OTLP exporter as plain text: a header line, the findings of
 * {@see TraceInsights} when there are any, then the tree of all its spans as the console prints OTLP spans,
 * see {@see TraceSpans}.
 *
 * @internal
 */
final class GetTrace implements Tool
{
    public function __construct(
        private readonly TelemetryStore $store,
        private readonly TraceInsights $insights,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'get_trace';
    }

    #[\Override]
    public function title(): string
    {
        return 'Get trace';
    }

    #[\Override]
    public function description(): string
    {
        return 'Shows one trace, of Sentry or OpenTelemetry, as plain text, as the Trap console prints '
            . 'OpenTelemetry traces: a header line with its services, sources and projects; the findings, when '
            . 'Trap found a problem, such as `n_plus_one` for the same database query run 5 times or more in one '
            . 'transaction (the N+1 pattern), each with the span count, the total duration, the transaction, the '
            . 'place in the code when the spans tell it, the ID of the first span and the query with its values '
            . 'as `?`; then the tree of its spans and transactions with kind, duration, offset from the trace '
            . 'start, status, attributes, events and links. Take the ID from `list_traces`, or from the trace ID '
            . 'of a Sentry error.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'string',
                    'description' => 'The trace ID from `list_traces`.',
                ],
                'max_length' => MaxLength::schema(),
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        return null;
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    #[\Override]
    public function call(array $arguments): string
    {
        /** @var mixed $id */
        $id = $arguments['id'] ?? null;
        \is_string($id) && $id !== '' or throw new \InvalidArgumentException('`id` must be a non-empty string.');
        $maxLength = MaxLength::fromArguments($arguments);
        $trace = $this->store->trace($id) ?? $this->store->trace(\strtolower($id));
        $summary = $trace === null ? null : TraceSummary::of($trace);
        $summary === null and throw new \InvalidArgumentException(
            "No trace with ID `$id`: it may have been dropped. Call `list_traces` for the current IDs.",
        );

        $config = new Traces();
        $config->maxSpans = $summary->count();
        $buffer = new SpanBuffer($config);
        $buffer->add(...TraceSpans::of($summary));
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        (new TraceTree($buffer))->renderStored($output, $summary->trace->id);

        $text = \sprintf(
            "trace %s, %d %s%s%s%s%s, updated at %s\n%s%s",
            $summary->trace->id,
            $summary->count(),
            $summary->count() === 1 ? 'span' : 'spans',
            self::names('service', $summary->services),
            self::names('source', $summary->sources),
            self::names('project', $summary->projects),
            $summary->error ? ', has errors' : '',
            Time::format($summary->trace->updatedAt),
            self::findings($summary->trace, $this->insights->of($summary->trace)),
            self::clean($output->fetch()),
        );

        return MaxLength::cut($text, $maxLength);
    }

    /**
     * Above the tree, so a cut text keeps them: a line per finding, and the repeated query under it.
     *
     * @param list<Finding> $findings
     */
    private static function findings(Trace $trace, array $findings): string
    {
        if ($findings === []) {
            return '';
        }

        $text = "findings, the heaviest first:\n";
        foreach ($findings as $finding) {
            $name = $finding->transactionId === null ? '' : $trace->transactions[$finding->transactionId]->name ?? '';
            $text .= \sprintf(
                "- %s: %d spans, %.2f ms in total%s%s, first id:%s\n  %s\n",
                $finding->rule,
                $finding->count,
                $finding->totalMs,
                $name === '' ? '' : ", in transaction \"$name\"",
                $finding->origin === null ? '' : ", from {$finding->origin}",
                $finding->spanIds[0],
                $finding->summary,
            );
        }

        return $text;
    }

    /**
     * @param list<string> $names
     */
    private static function names(string $what, array $names): string
    {
        return match (\count($names)) {
            0 => '',
            1 => ", $what $names[0]",
            default => ", {$what}s " . \implode('/', $names),
        };
    }

    /**
     * Drops the blank lines around the tree and the trailing spaces of the header.
     */
    private static function clean(string $text): string
    {
        $text = (string) \preg_replace('/[ \t]+$/m', '', \str_replace("\r\n", "\n", $text));

        return \trim($text, "\n") . "\n";
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\MaxLength;
use Buggregator\Trap\Module\Mcp\Tool\Time;
use Buggregator\Trap\Module\Tracing\Config\Traces;
use Buggregator\Trap\Module\Tracing\Console\SpanBuffer;
use Buggregator\Trap\Module\Tracing\Console\TraceTree;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A stored trace of a Sentry SDK or an OTLP exporter as plain text: a header line, then the tree of all its spans
 * as the console prints OTLP spans, see {@see TraceSpans}.
 *
 * @internal
 */
final class GetTrace implements Tool
{
    public function __construct(
        private readonly TelemetryStore $store,
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
            . 'OpenTelemetry traces: a header line with its services, sources and projects, then the tree of its '
            . 'spans and transactions with kind, duration, offset from the trace start, status, attributes, events '
            . 'and links. Take the ID from `list_traces`, or from the trace ID of a Sentry error.';
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
            "trace %s, %d %s%s%s%s%s, updated at %s\n%s",
            $summary->trace->id,
            $summary->count(),
            $summary->count() === 1 ? 'span' : 'spans',
            self::names('service', $summary->services),
            self::names('source', $summary->sources),
            self::names('project', $summary->projects),
            $summary->error ? ', has errors' : '',
            Time::format($summary->trace->updatedAt),
            self::clean($output->fetch()),
        );

        return MaxLength::cut($text, $maxLength);
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

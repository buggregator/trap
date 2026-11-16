<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\MaxLength;
use Buggregator\Trap\Module\Mcp\Tool\Time;
use Buggregator\Trap\Module\Tracing\Console\TraceTree;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A stored trace as plain text: a header line, then the tree of all its spans as the console prints it.
 *
 * @internal
 */
final class GetTrace implements Tool
{
    public function __construct(
        private readonly SpanStore $store,
        private readonly TraceTree $tree,
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
        return 'Shows one OpenTelemetry trace as plain text, as the Trap console prints it: the tree of its '
            . 'spans with kind, duration, offset from the trace start, status, attributes, events and links. '
            . 'Take the ID from `list_traces`.';
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
        $id = \strtolower($id);
        $summary = $this->store->summary($id) ?? throw new \InvalidArgumentException(
            "No trace with ID `$id`: it may have expired. Call `list_traces` for the current IDs.",
        );

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $this->tree->renderStored($output, $id);

        $text = \sprintf(
            "trace %s, %d %s%s%s, updated at %s\n%s",
            $summary->traceId,
            $summary->spans,
            $summary->spans === 1 ? 'span' : 'spans',
            $summary->service === null ? '' : ", service $summary->service",
            $summary->error ? ', has errors' : '',
            Time::format($summary->updatedAt),
            self::clean($output->fetch()),
        );

        return MaxLength::cut($text, $maxLength);
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

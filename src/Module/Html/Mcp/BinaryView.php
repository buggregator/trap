<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html\Mcp;

use Buggregator\Trap\Module\Html\Binary\Payload;
use Buggregator\Trap\Module\Mcp\View\EventFields;
use Buggregator\Trap\Module\Mcp\View\EventView;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Support\BinaryFormat;
use Buggregator\Trap\Support\Hexdump;
use Buggregator\Trap\Support\Measure;

/**
 * Binary events for the MCP tools: `html` events, as the UI shows them, told from pages by the `kind` field.
 * The text is the guessed format and a hexdump of the start, not the markup of the viewer.
 *
 * @internal
 */
final class BinaryView implements EventView, EventFields
{
    /** Bytes of the hexdump in the text. */
    public const TEXT_BYTES = 1024;

    #[\Override]
    public function summary(Event $event): ?string
    {
        $payload = $event->payload;

        return $payload instanceof Payload ? \sprintf('%s (%s)', $payload->title, Measure::memory($payload->size)) : null;
    }

    #[\Override]
    public function text(Record $record): ?string
    {
        $payload = $record->event->payload;
        if (!$payload instanceof Payload) {
            return null;
        }

        $head = $payload->bytes(\max(BinaryFormat::HEAD, self::TEXT_BYTES));
        $entropy = BinaryFormat::entropy($head);
        $lines = [
            'Binary data: TCP traffic no protocol module recognised.',
            \sprintf('Size: %d bytes', $payload->size),
        ];
        $payload->format === null or $lines[] = 'Format: ' . $payload->format->name
            . ($payload->format->hint === '' ? '' : ' - ' . $payload->format->hint);
        $head === '' or $lines[] = \sprintf(
            'Entropy: %.2f bits per byte of the first %d bytes%s',
            $entropy,
            \strlen($head),
            $entropy > 7.5 ? ': compressed or encrypted' : '',
        );

        $shown = \substr($head, 0, self::TEXT_BYTES);
        $shown === '' or $lines[] = \sprintf(
            "\n%s:\n%s",
            \strlen($shown) < $payload->size ? \sprintf('The first %d bytes', \strlen($shown)) : 'Bytes',
            \rtrim(Hexdump::text($shown)),
        );

        return \implode("\n", $lines);
    }

    /**
     * The names of the `html` fields are given by {@see HtmlView}: the first view to name them for a type wins.
     */
    #[\Override]
    public function fieldNames(): array
    {
        return [];
    }

    #[\Override]
    public function fields(Event $event): ?array
    {
        $payload = $event->payload;

        return $payload instanceof Payload
            ? ['title' => $payload->title, 'kind' => 'binary', 'format' => $payload->format->id ?? '']
            : null;
    }
}

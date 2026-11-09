<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray\Frontend;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventMapper;
use Buggregator\Trap\Module\Frontend\EventStorage;
use Buggregator\Trap\Module\Ray\Frame as RayFrame;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Support\Uuid;

/**
 * Maps a Ray request to the `ray` UI event: `{"uuid", "payloads", "meta"}`, as the client sent it.
 *
 * @internal
 */
final class Mapper implements EventMapper
{
    /**
     * UI event type of Ray events.
     */
    public const EVENT_TYPE = 'ray';

    public function __construct(
        private readonly EventStorage $events,
    ) {}

    /**
     * Requests with the UUID of a stored Ray event add their payloads to it: the client sends one
     * request per chained call, such as `ray($value)->red()->label('x')`, and the UI shows them as one event.
     *
     * @return Event|null Null for `clear_all`, which deletes the stored Ray events instead.
     */
    #[\Override]
    public function map(Frame $frame): ?Event
    {
        \assert($frame instanceof RayFrame);

        foreach ($frame->payloads as $payload) {
            if ($payload['type'] === 'clear_all') {
                $this->clear();
                return null;
            }
        }

        // UI routes such as `api/event/{uuid}` match hexadecimal UUIDs only
        $uuid = \preg_match('/^[a-f0-9-]++$/iD', $frame->uuid) === 1 ? $frame->uuid : Uuid::generate();
        $stored = $this->events->get($uuid);
        $storedPayload = $stored?->payload;
        $previous = $storedPayload instanceof Payload ? $storedPayload->payloads : [];

        return new Event(
            uuid: $uuid,
            type: self::EVENT_TYPE,
            payload: new Payload(
                $uuid,
                [...$previous, ...\array_map(self::cleanDump(...), $frame->payloads)],
                $frame->meta,
            ),
            timestamp: $stored?->timestamp ?? (float) $frame->time->format('U.u'),
        );
    }

    /**
     * A dump of a value comes as the full output of the Symfony HTML dumper: `<style>`, the `Sfdump` script,
     * then `<pre class=sf-dump id=sf-dump-N>`. The UI runs its own `Sfdump` on the `sf-dump-N` element, and
     * the dumper's `<style>` would restyle the whole page, so only the `<pre>` is kept.
     */
    private static function cleanDump(array $payload): array
    {
        $content = $payload['content'] ?? null;
        if (!\is_array($content)) {
            return $payload;
        }

        /** @var mixed $value */
        foreach ($content as $key => $value) {
            if (\is_string($value)) {
                $content[$key] = self::dumpPre($value);
            } elseif ($key === 'values' && \is_array($value)) {
                $content[$key] = \array_map(
                    static fn(mixed $item): mixed => \is_string($item) ? self::dumpPre($item) : $item,
                    $value,
                );
            }
        }

        $payload['content'] = $content;
        return $payload;
    }

    private static function dumpPre(string $html): string
    {
        return \str_contains($html, 'sf-dump') && \preg_match('~<pre\b[^>]*\bsf-dump\b.*?</pre>~s', $html, $m) === 1
            ? $m[0]
            : $html;
    }

    private function clear(): void
    {
        foreach (\iterator_to_array($this->events) as $event) {
            $event->type === self::EVENT_TYPE and $this->events->delete($event->uuid);
        }
    }
}

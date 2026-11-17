<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Mcp\View\EventFields;
use Buggregator\Trap\Module\Mcp\View\EventView;
use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;

/**
 * A summary and the fields of a profile for the MCP listings; the console renderer gives the full text.
 *
 * @internal
 */
final class ProfileView implements EventView, EventFields
{
    /**
     * The application name, else the file name of the profile.
     */
    public static function name(Payload $payload): string
    {
        $metadata = $payload->getProfile()->metadata;
        $appName = $metadata['app_name'] ?? null;
        $fileName = $metadata['filename'] ?? null;

        return match (true) {
            \is_string($appName) && $appName !== '' => $appName,
            \is_string($fileName) && $fileName !== '' => $fileName,
            default => '',
        };
    }

    #[\Override]
    public function summary(Event $event): ?string
    {
        $payload = $event->payload;
        if (!$payload instanceof Payload) {
            return null;
        }

        $profile = $payload->getProfile();
        $name = self::name($payload);
        $wallTime = $profile->peaks->wt;

        return \sprintf(
            '%s (%s)%s, %d edges',
            $name === '' ? 'profile' : $name,
            $payload->type->value,
            $wallTime > 0 ? \sprintf(', wall time %.2f ms', (float) $wallTime / 1e3) : '',
            $profile->calls->count(),
        );
    }

    #[\Override]
    public function text(Record $record): ?string
    {
        return null;
    }

    #[\Override]
    public function fieldNames(): array
    {
        return ['profiler' => ['name', 'format']];
    }

    #[\Override]
    public function fields(Event $event): ?array
    {
        $payload = $event->payload;
        if (!$payload instanceof Payload) {
            return null;
        }

        return ['name' => self::name($payload), 'format' => $payload->type->value];
    }
}

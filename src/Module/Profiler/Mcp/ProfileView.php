<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Mcp\View\EventView;
use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;

/**
 * A summary of a profile for the MCP listings; the console renderer gives the full text.
 *
 * @internal
 */
final class ProfileView implements EventView
{
    #[\Override]
    public function summary(Event $event): ?string
    {
        $payload = $event->payload;
        if (!$payload instanceof Payload) {
            return null;
        }

        $profile = $payload->getProfile();
        $metadata = $profile->metadata;
        $appName = $metadata['app_name'] ?? null;
        $fileName = $metadata['filename'] ?? null;
        $name = match (true) {
            \is_string($appName) && $appName !== '' => $appName,
            \is_string($fileName) && $fileName !== '' => $fileName,
            default => 'profile',
        };
        $wallTime = $profile->peaks->wt;

        return \sprintf(
            '%s (%s)%s, %d edges',
            $name,
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
}

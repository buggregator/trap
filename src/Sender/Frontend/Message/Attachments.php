<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Message;

use Buggregator\Trap\Sender\Frontend\Event;

/**
 * @internal
 */
final class Attachments implements \JsonSerializable
{
    private array $data = [];

    /**
     * @param array<array-key, Event\AttachedFile> $files
     * @param array<array-key, mixed> $meta
     */
    public function __construct(
        Event $event,
        array $files,
        public readonly array $meta = [],
    ) {
        foreach ($files as $file) {
            $this->data[] = [
                'uuid' => $file->uuid,
                'name' => $file->file->getClientFilename(),
                'path' => "$event->uuid/attachment/$file->uuid",
                'size' => $file->file->getSize(),
                'mime' => $file->file->getClientMediaType(),
            ];
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'data' => $this->data,
            'meta' => $this->meta + ['grid' => []],
        ];
    }
}

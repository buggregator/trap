<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Event;

use Psr\Http\Message\UploadedFileInterface;

/**
 * @internal
 */
final class EmbeddedFile extends Asset
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $name
     */
    public function __construct(
        string $id,
        public readonly UploadedFileInterface $file,
        public readonly string $name,
    ) {
        parent::__construct($id);
    }
}

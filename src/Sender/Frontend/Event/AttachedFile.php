<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Event;

use Psr\Http\Message\UploadedFileInterface;

/**
 * @internal
 */
final class AttachedFile extends Asset
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        string $id,
        public readonly UploadedFileInterface $file,
    ) {
        parent::__construct($id);
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Proto;

use Psr\Http\Message\UploadedFileInterface;

/**
 * @internal
 * @psalm-internal Buggregator
 */
interface FilesCarrier
{
    public function hasFiles(): bool;

    /**
     * @return UploadedFileInterface[]
     */
    public function getFiles(): array;
}

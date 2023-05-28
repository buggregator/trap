<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto;

use Psr\Http\Message\UploadedFileInterface;

interface FilesCarrier
{
    public function hasFiles(): bool;

    /**
     * @return UploadedFileInterface[]
     */
    public function getFiles(): array;
}

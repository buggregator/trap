<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Service\FilesObserver;

/**
 * A converter that keeps state for the files it checks, such as the inflater of a gzipped file being written.
 *
 * @internal
 */
interface ForgetsFiles
{
    /**
     * Releases what is kept for the file: the observer stops following it, as it was converted, removed or abandoned.
     */
    public function forget(string $path): void;
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Service\FilesObserver\Stub;

use Buggregator\Trap\Module\Monolog\Frame;
use Buggregator\Trap\Server\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Server\Service\FilesObserver\ForgetsFiles;
use Buggregator\Trap\Server\Service\FilesObserver\FrameConverter;

/**
 * Takes `*.fake` files ending with `END` for complete, recording what the observer asks.
 */
final class FakeConverter implements FrameConverter, ForgetsFiles
{
    /** The converter the observer made last. */
    public static ?self $last = null;

    /** Fails the listing of the directory, as a file removed while it is listed may. */
    public static bool $failListing = false;

    /** @var list<string> Paths checked with {@see isReady()} */
    public array $checked = [];

    /** @var list<string> */
    public array $forgotten = [];

    public function __construct()
    {
        self::$last = $this;
    }

    #[\Override]
    public function validate(FileInfo $file): bool
    {
        self::$failListing and throw new \RuntimeException('The listing fails.');

        return \str_ends_with($file->path, '.fake');
    }

    #[\Override]
    public function isReady(FileInfo $file): bool
    {
        $this->checked[] = $file->path;

        return \str_ends_with((string) @\file_get_contents($file->path), 'END');
    }

    #[\Override]
    public function convert(FileInfo $file): iterable
    {
        yield new Frame(['message' => \basename($file->path)]);
    }

    #[\Override]
    public function forget(string $path): void
    {
        $this->forgotten[] = $path;
    }
}

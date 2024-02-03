<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Profiler;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Support\Json;
use DateTimeImmutable;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class File extends Frame\Profiler
{
    public const PROFILE_FRAME_TYPE = 'file';

    public function __construct(
        public readonly FileInfo $fileInfo,
        DateTimeImmutable $time = new DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Profiler, $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode($this->fileInfo->toArray());
    }

    public static function fromArray(array $data, DateTimeImmutable $time): static
    {
        return new self(FileInfo::fromArray($data), $time);
    }
}

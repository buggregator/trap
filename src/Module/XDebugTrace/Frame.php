<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace;

use Buggregator\Trap\Module\XDebugTrace\Trace\Summary;
use Buggregator\Trap\Server\Proto\Frame as ProtoFrame;
use Buggregator\Trap\Server\Sender\LocalOnlyFrame;
use Buggregator\Trap\Support\Json;

/**
 * An Xdebug function trace kept in the {@see TraceStorage}: the frame names the file, the tree is read from it.
 * Local only: the file is of this process.
 *
 * @psalm-import-type SummaryData from Summary
 *
 * @internal
 * @psalm-internal Buggregator
 */
final class Frame extends ProtoFrame implements LocalOnlyFrame
{
    public const TYPE = 'xdebug-trace';

    /**
     * @param non-empty-string $uuid Of the file in the storage and of the event.
     * @param string $name The name the client gave, may be empty.
     * @param string $url The request the trace was recorded for, may be empty.
     * @param int<0, max> $size Bytes stored.
     * @param SummaryData $summary
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $file,
        public readonly string $name,
        public readonly string $url,
        public readonly int $size,
        public readonly bool $compressed,
        public readonly array $summary,
        public readonly ?string $project = null,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(self::TYPE, $time);
    }

    public static function fromStored(
        StoredTrace $trace,
        string $name = '',
        string $url = '',
        ?string $project = null,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ): self {
        return new self(
            $trace->uuid,
            \basename($trace->path),
            $name,
            $url,
            $trace->size,
            $trace->compressed,
            $trace->summary,
            $project,
            $time,
        );
    }

    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        /** @var array{uuid: non-empty-string, file: string, name: string, url: string, size: int<0, max>, compressed: bool, summary: SummaryData, project?: string|null} $data */
        $data = Json::decode($payload);

        return new self(
            $data['uuid'],
            $data['file'],
            $data['name'],
            $data['url'],
            $data['size'],
            $data['compressed'],
            $data['summary'],
            $data['project'] ?? null,
            $time,
        );
    }

    /**
     * The payload of the event: what the trace page reads.
     *
     * @return array{file: string, name: string, url: string, size: int<0, max>, compressed: bool, summary: SummaryData}
     */
    public function payload(): array
    {
        return [
            'file' => $this->file,
            'name' => $this->name,
            'url' => $this->url,
            'size' => $this->size,
            'compressed' => $this->compressed,
            'summary' => $this->summary,
        ];
    }

    public function __toString(): string
    {
        return Json::encode(['uuid' => $this->uuid] + $this->payload() + ['project' => $this->project]);
    }
}

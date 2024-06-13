<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

/**
 * @psalm-type Metadata = array{
 *     app_name?: string,
 *     hostname?: string,
 *     filename?: string,
 *     ...
 * }
 *
 * @psalm-type ProfileData = array{
 *     date: int,
 *     app_name?: string,
 *     hostname?: string,
 *     filename?: string,
 *     tags: array<non-empty-string, non-empty-string>,
 *     peaks: PeaksData|Peaks,
 *     edges: array,
 *     total_edges: int<0, max>,
 * }
 *
 * @psalm-import-type PeaksData from Peaks
 *
 * @internal
 */
final class Profile implements \JsonSerializable
{
    public Peaks $peaks;

    /**
     * @param Metadata $metadata
     * @param array<non-empty-string, non-empty-string> $tags
     * @param Tree<Edge> $calls
     */
    public function __construct(
        public \DateTimeInterface $date = new \DateTimeImmutable(),
        public array $metadata = [],
        public array $tags = [],
        public Tree $calls = new Tree(),
        ?Peaks $peaks = null,
    ) {
        if ($peaks === null) {
            $this->peaks = new Peaks();

            /** @var Edge $edge */
            foreach ($this->calls as $edge) {
                $this->peaks->update($edge->cost);
            }
        } else {
            $this->peaks = $peaks;
        }
    }

    /**
     * @param ProfileData $data
     */
    public static function fromArray(array $data): self
    {
        $metadata = $data;
        unset($metadata['tags'], $metadata['peaks'], $metadata['total_edges'], $metadata['date']);

        $self = new self(
            date: new \DateTimeImmutable('@' . $data['date']),
            metadata: $metadata,
            tags: $data['tags'],
            // todo calls from edges
            peaks: Peaks::fromArray($data['peaks']),
        );

        return $self;
    }

    /**
     * @return ProfileData
     */
    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date->getTimestamp(),
            'app_name' => $this->metadata['app_name'] ?? '',
            'hostname' => $this->metadata['hostname'] ?? '',
            'filename' => $this->metadata['filename'] ?? '',
            'tags' => $this->tags,
            'peaks' => $this->peaks,
            'edges' => \iterator_to_array($this->calls->getItemsSortedV1(
                static fn(Branch $a, Branch $b): int => $b->item->cost->wt <=> $a->item->cost->wt,
            )),
            'total_edges' => $this->calls->count(),
        ];
    }
}

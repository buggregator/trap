<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

use Buggregator\Trap\Support\Uuid;

/**
 * @psalm-type Metadata = array{
 *     app_name?: string,
 *     hostname?: string,
 *     filename?: string,
 *     filesize?: int<0, max>,
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
 * @psalm-type ProfileInput = array{
 *     date: int,
 *     tags: array<non-empty-string, non-empty-string>,
 *     peaks: PeaksData,
 *     ...
 * }
 *
 * @psalm-import-type PeaksData from Peaks
 *
 * @internal
 */
final class Profile implements \JsonSerializable
{
    /**
     * Unfolded nodes with a smaller share of the profile are dropped: they are too small to show.
     */
    private const UNFOLD_MIN_SHARE = 0.0001;

    public Peaks $peaks;
    public Graph $calls;

    /** @var non-empty-string some leaked bs required for Frontend */
    public string $uuid;

    /**
     * @param Metadata $metadata
     * @param array<non-empty-string, non-empty-string> $tags
     * @param Tree<Edge>|null $tree Exact call tree, when the profiler records one.
     */
    public function __construct(
        public \DateTimeInterface $date = new \DateTimeImmutable(),
        public array $metadata = [],
        public array $tags = [],
        ?Graph $calls = null,
        ?Peaks $peaks = null,
        private ?Tree $tree = null,
    ) {
        $this->uuid = Uuid::uuid4();
        $this->calls = $calls ?? new Graph();
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
     * @param ProfileInput $data
     */
    public static function fromArray(array $data): self
    {
        $metadata = $data;
        unset($metadata['tags'], $metadata['peaks'], $metadata['total_edges'], $metadata['date'], $metadata['profile_uuid']);

        $profile = new self(
            date: new \DateTimeImmutable('@' . $data['date']),
            metadata: $metadata,
            tags: $data['tags'],
            // todo calls from edges
            peaks: Peaks::fromArray($data['peaks']),
        );

        /** @var mixed $uuid */
        $uuid = $data['profile_uuid'] ?? null;
        \is_string($uuid) && $uuid !== '' and $profile->uuid = $uuid;

        return $profile;
    }

    /**
     * Call tree: the exact one when the profiler records it, otherwise unfolded from the call graph.
     *
     * @return Tree<Edge>
     */
    public function tree(): Tree
    {
        return $this->tree ??= $this->calls->unfold(self::UNFOLD_MIN_SHARE);
    }

    /**
     * @return ProfileData
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date->getTimestamp(),
            'app_name' => $this->metadata['app_name'] ?? '',
            'hostname' => (string) ($this->metadata['hostname'] ?? ''),
            'filename' => (string) ($this->metadata['filename'] ?? ''),
            'profile_uuid' => $this->uuid,
            'tags' => $this->tags,
            'peaks' => $this->peaks,
            'total_edges' => $this->calls->count(),
        ];
    }
}

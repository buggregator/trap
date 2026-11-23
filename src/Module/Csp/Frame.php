<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Csp;

use Buggregator\Trap\Server\Proto\Frame as ProtoFrame;
use Buggregator\Trap\Server\Sender\LocalOnlyFrame;
use Buggregator\Trap\Support\Json;

/**
 * The security reports of one browser request, read by {@see Report::parse()}.
 *
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type CspReport from Report
 */
final class Frame extends ProtoFrame implements LocalOnlyFrame
{
    public const TYPE = 'csp';

    /**
     * @param Report::FORMAT_* $format
     * @param non-empty-list<CspReport> $reports
     * @param non-empty-string|null $project Null for the default project.
     */
    public function __construct(
        public readonly string $format,
        public readonly array $reports,
        public readonly ?string $project = null,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(self::TYPE, $time);
    }

    /**
     * @throws \JsonException
     * @throws \InvalidArgumentException When the JSON is not a frame of reports.
     */
    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        $data = Json::decode($payload);
        $format = \is_array($data) ? $data['format'] ?? null : null;
        $reports = \is_array($data) ? $data['reports'] ?? null : null;
        \in_array($format, [Report::FORMAT_CSP, Report::FORMAT_REPORTS], true)
            && \is_array($reports) && \array_is_list($reports) && $reports !== []
            or throw new \InvalidArgumentException('A CSP frame must have a format and reports.');
        \assert(\is_array($data));

        /** @var mixed $project */
        $project = $data['project'] ?? null;

        return new self(
            $format,
            \array_map(Report::restore(...), $reports),
            \is_string($project) && $project !== '' ? $project : null,
            $time,
        );
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode(['format' => $this->format, 'reports' => $this->reports, 'project' => $this->project]);
    }
}

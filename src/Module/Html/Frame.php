<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html;

use Buggregator\Trap\Server\Proto\Frame as ProtoFrame;
use Buggregator\Trap\Server\Sender\LocalOnlyFrame;
use Buggregator\Trap\Support\Json;

/**
 * A page of the sender's own HTML markup.
 *
 * @internal
 * @psalm-internal Buggregator
 */
final class Frame extends ProtoFrame implements LocalOnlyFrame
{
    public const TYPE = 'html';

    /**
     * @param string $title One line, at most {@see Page::MAX_TITLE} characters; may be empty.
     * @param non-empty-string $html UTF-8 markup.
     * @param non-empty-string|null $project Null for the default project.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $html,
        public readonly ?string $project = null,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(self::TYPE, $time);
    }

    /**
     * @throws \JsonException
     * @throws \InvalidArgumentException When the JSON is not a frame of a page.
     */
    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        $data = Json::decode($payload);
        $title = \is_array($data) ? $data['title'] ?? null : null;
        $html = \is_array($data) ? $data['html'] ?? null : null;
        \is_string($title) && \is_string($html) && $html !== ''
            or throw new \InvalidArgumentException('An HTML frame must have a title and markup.');
        \assert(\is_array($data));

        /** @var mixed $project */
        $project = $data['project'] ?? null;

        return new self($title, $html, \is_string($project) && $project !== '' ? $project : null, $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode(['title' => $this->title, 'html' => $this->html, 'project' => $this->project]);
    }
}

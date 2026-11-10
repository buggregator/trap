<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray;

use Buggregator\Trap\Server\Proto\Frame as ProtoFrame;
use Buggregator\Trap\Server\Sender\LocalOnlyFrame;
use Buggregator\Trap\Support\Json;

/**
 * One request of a Ray client: `{"uuid": ..., "payloads": [...], "meta": {...}}`.
 *
 * A client sends several requests with the same UUID when calls are chained on one `ray()` instance.
 *
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-type RayPayload = array{type: non-empty-string, content?: mixed, origin?: mixed, ...}
 */
final class Frame extends ProtoFrame implements LocalOnlyFrame
{
    public const TYPE = 'ray';

    /**
     * @param non-empty-string $uuid
     * @param list<RayPayload> $payloads
     */
    public function __construct(
        public readonly string $uuid,
        public readonly array $payloads,
        public readonly array $meta = [],
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(self::TYPE, $time);
    }

    /**
     * @throws \JsonException
     * @throws \InvalidArgumentException When the JSON is not a Ray request.
     */
    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        $data = Json::decode($payload);
        \is_array($data) or throw new \InvalidArgumentException('A Ray request must be a JSON object.');

        return self::fromArray($data, $time);
    }

    /**
     * @throws \InvalidArgumentException When the data is not a Ray request.
     */
    public static function fromArray(array $data, \DateTimeImmutable $time = new \DateTimeImmutable()): self
    {
        $uuid = $data['uuid'] ?? null;
        \is_string($uuid) && $uuid !== '' or throw new \InvalidArgumentException('A Ray request must have a UUID.');

        $payloads = $data['payloads'] ?? null;
        // The UI reads the type of the first payload
        \is_array($payloads) && \array_is_list($payloads) && $payloads !== []
            or throw new \InvalidArgumentException('Ray payloads must be a non-empty list.');
        foreach ($payloads as $item) {
            \is_array($item) && \is_string($item['type'] ?? null) && $item['type'] !== ''
                or throw new \InvalidArgumentException('A Ray payload must have a type.');
        }
        /** @var list<RayPayload> $payloads */

        $meta = $data['meta'] ?? [];
        \is_array($meta) or throw new \InvalidArgumentException('Ray meta must be an object.');

        return new self($uuid, $payloads, $meta, $time);
    }

    /**
     * Names of the execution locks the request creates: `ray()->pause()` sends one and waits for it.
     *
     * @return list<string>
     */
    public function lockNames(): array
    {
        $names = [];
        foreach ($this->payloads as $payload) {
            /** @var mixed $content */
            $content = $payload['content'] ?? null;
            /** @var mixed $name */
            $name = \is_array($content) ? $content['name'] ?? null : null;
            $payload['type'] === 'create_lock' && \is_string($name) and $names[] = $name;
        }

        return $names;
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode([
            'uuid' => $this->uuid,
            'payloads' => $this->payloads,
            'meta' => (object) $this->meta,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Sentry;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;

/**
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-type SentryStoreMessage=array{
 *     type: SentryStore::SENTRY_FRAME_TYPE,
 *     event_id: non-empty-string,
 *     timestamp: positive-int,
 *     platform?: non-empty-string,
 *     sdk?: array{
 *          name: non-empty-string,
 *          version: non-empty-string,
 *     },
 *     logger?: non-empty-string,
 *     contexts?: array<non-empty-string, array<non-empty-string, non-empty-string>>,
 *     environment?: non-empty-string,
 *     server_name?: non-empty-string,
 *     transaction?: non-empty-string,
 *     modules?: array<non-empty-string, non-empty-string>,
 *     exception?: array<array-key, array{
 *         type: non-empty-string,
 *         value: non-empty-string,
 *         stacktrace: array{
 *             frames: array<array-key, array{
 *                 filename: non-empty-string,
 *                 lineno: positive-int,
 *                 abs_path: non-empty-string,
 *                 context_line: non-empty-string
 *             }>
 *         }
 *     }>
 * }
 */
final class SentryStore extends Frame\Sentry
{
    public const SENTRY_FRAME_TYPE = 'store';

    /**
     * @param SentryStoreMessage $message
     */
    public function __construct(
        public readonly array $message,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Sentry, $time);
    }

    /**
     * @psalm-assert SentryStoreMessage $data
     *
     * @param SentryStoreMessage $data
     */
    public static function fromArray(array $data, \DateTimeImmutable $time): static
    {
        return new self($data, $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode($this->message);
    }
}

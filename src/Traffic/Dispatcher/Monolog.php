<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Dispatcher;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Traffic\Dispatcher;
use Buggregator\Trap\Traffic\StreamClient;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Monolog implements Dispatcher
{
    /**
     * @throws \JsonException
     */
    public function dispatch(StreamClient $stream): iterable
    {
        while (!$stream->isFinished()) {
            $line = \trim($stream->fetchLine());
            if ($line === '') {
                continue;
            }

            yield new Frame\Monolog(
                (array) \json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                $stream->getCreatedAt(),
            );
        }
    }

    public function detect(string $data, \DateTimeImmutable $createdAt): ?bool
    {
        return \str_starts_with($data, '{"message":');
    }
}

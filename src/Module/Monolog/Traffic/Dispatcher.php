<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Monolog\Traffic;

use Buggregator\Trap\Module\Monolog\Frame;
use Buggregator\Trap\Traffic\Dispatcher as DispatcherInterface;
use Buggregator\Trap\Traffic\StreamClient;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Dispatcher implements DispatcherInterface
{
    /**
     * @throws \JsonException
     */
    #[\Override]
    public function dispatch(StreamClient $stream): iterable
    {
        while (!$stream->isFinished()) {
            $line = \trim($stream->fetchLine());
            if ($line === '') {
                continue;
            }

            yield new Frame(
                (array) \json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                $stream->getCreatedAt(),
            );
        }
    }

    #[\Override]
    public function detect(string $data, \DateTimeImmutable $createdAt): ?bool
    {
        $prefix = '{"message":';
        if (\strlen($data) < \strlen($prefix) && \substr($prefix, 0, \strlen($data)) === $data) {
            return null;
        }

        return \str_starts_with($data, $prefix);
    }
}

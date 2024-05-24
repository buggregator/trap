<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic;

use Buggregator\Trap\Proto\Frame;

/**
 * @internal
 * @psalm-internal Buggregator\Trap\Traffic
 */
interface Dispatcher
{
    /**
     * @return iterable<Frame>
     */
    public function dispatch(StreamClient $stream): iterable;

    /**
     * Detect if this dispatcher can handle this data.
     *
     * @param string $data Read data from stream.
     * @param \DateTimeImmutable $createdAt Time when the client was created.
     *
     * @return null|bool Return {@see null} if dispatcher can't detect this data and it needs more data.
     */
    public function detect(string $data, \DateTimeImmutable $createdAt): ?bool;
}

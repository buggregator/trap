<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Traffic;

use Buggregator\Trap\Server\Proto\Frame;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
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
     * @param float $silence Seconds the client has sent nothing for, see {@see StreamClient::silence()}.
     *
     * @return null|bool Return {@see null} if dispatcher can't detect this data and it needs more data.
     */
    public function detect(string $data, float $silence): ?bool;
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Traffic\StreamClient;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
interface RequestHandler
{
    /**
     * @param callable(StreamClient, ServerRequestInterface): iterable<array-key, Frame> $next
     *
     * @return iterable<array-key, Frame>
     */
    public function handle(StreamClient $streamClient, ServerRequestInterface $request, callable $next): iterable;
}

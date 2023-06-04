<?php

declare(strict_types=1);

namespace Buggregator\Client\Handler\Http;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Traffic\StreamClient;
use Psr\Http\Message\ServerRequestInterface;

interface RequestHandler
{
    /**
     * @param callable(StreamClient, ServerRequestInterface): iterable<array-key, Frame> $next
     *
     * @return iterable<array-key, Frame>
     */
    public function handle(StreamClient $streamClient, ServerRequestInterface $request, callable $next): iterable;
}

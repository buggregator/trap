<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

interface HandlerInterface
{
    public function priority(): int;
    /**
     * @param \Closure<Request> $next
     */
    public function handle(Request $request, \Closure $next): Response;
}

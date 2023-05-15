<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

final class RayRequestDump implements HandlerInterface
{
    public function priority(): int
    {
        return 0;
    }

    public function handle(Request $request, \Closure $next): Response
    {
        if ($request->uri === '_availability_check') {
            return new Response(400);
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Middleware;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class DebugPage implements Middleware
{
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if (\str_ends_with($request->getUri()->getPath(), '_debug')) {
            return new Response(
                200,
                ['Content-Type' => ['text/html; charset=UTF-8']],
                <<<'HTML'
                    <!doctype html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <script src="https://cdn.tailwindcss.com"></script>
                    </head>
                    <body class="overflow-hidden h-full w-full px-3 py-10 bg-gray-200 flex justify-center">
                        <div>
                            <h1 class="text-xl font-bold mb-4">Test form</h1>
                            <form class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4"  method="post" action="/_debug/bar?get=test&hello=world" enctype='multipart/form-data'>
                                <div class="mb-4"><input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" type="text" name="name" value="Actor"/></div>
                                <div class="mb-4"><textarea class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"  name="message">Hello World!</textarea></div>
                                <div class="mb-4"><input type="file" name="files" multiple /></div>
                                <div><button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline" type="submit">Send</button></div>
                            </form>
                            <p class="text-center text-gray-500 text-xs">
                            &copy;2023 Buggregator.
                            </p>
                        </div>
                    </body>
                    </html>
                    HTML,
            );
        }

        // if (\str_ends_with($request->getUri()->getPath(), '_debug/bar')) {
        //     return new Response(301, ['Location' => '/_debug?success=1']);
        // }
        if (\str_ends_with($request->getUri()->getPath(), '_debug/bar')) {
            return new Response(200, body: 'Hello World!');
        }

        return $next($request);
    }
}

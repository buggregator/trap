<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HandlerPipeline
{
    /** @var array<int, HandlerInterface[]> */
    private array $handlers = [];
    private int $position = 0;
    private bool $isHandled = false;

    public function register(HandlerInterface $handler): void
    {
        if ($this->isHandled) {
            throw new \RuntimeException('Cannot register new handler after pipeline is handled.');
        }

        $this->handlers[$handler->priority()][] = $handler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->position = 0;

        /** @var HandlerInterface[] $newHandlers */
        $newHandlers = [];

        if (!$this->isHandled) {
            \ksort($this->handlers);
            foreach ($this->handlers as $handlers) {
                foreach ($handlers as $handler) {
                    $newHandlers[] = $handler;
                }
            }

            $this->isHandled = true;
        }

        return $this->handlePipeline($request, $newHandlers);
    }

    /**
     * @param HandlerInterface[] $handlers
     */
    private function handlePipeline(ServerRequestInterface $request, array $handlers): ResponseInterface
    {
        $handler = $handlers[$this->position] ?? null;
        $this->position++;

        if ($handler === null) {
            return new Response(200);
        }

        return $handler->handle(
            $request,
            fn(ServerRequestInterface $request): ResponseInterface => $this->handlePipeline($request, $handlers)
        );
    }
}

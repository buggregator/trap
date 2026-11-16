<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Protocol;

/**
 * A JSON-RPC error response to a request.
 *
 * @internal
 */
final class RpcError extends \RuntimeException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    public static function methodNotFound(string $method): self
    {
        return new self("Method not found: $method", self::METHOD_NOT_FOUND);
    }

    public static function invalidParams(string $message): self
    {
        return new self($message, self::INVALID_PARAMS);
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Protocol;

use Buggregator\Trap\Module\Mcp\Protocol\RpcError;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RpcError::class)]
final class RpcErrorTest
{
    public function testMethodNotFoundNamesTheMethod(): void
    {
        $error = RpcError::methodNotFound('tools/frobnicate');

        Assert::same($error->getMessage(), 'Method not found: tools/frobnicate');
        Assert::same($error->getCode(), RpcError::METHOD_NOT_FOUND);
    }

    public function testInvalidParamsKeepsTheMessage(): void
    {
        $error = RpcError::invalidParams('Tool name must be a string.');

        Assert::same($error->getMessage(), 'Tool name must be a string.');
        Assert::same($error->getCode(), RpcError::INVALID_PARAMS);
    }
}

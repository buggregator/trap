<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http\Stub;

use Nyholm\Psr7\Stream;

/**
 * A stream that fails as streams of the server may: it throws, or it never ends and gives nothing.
 */
final class FaultyStream extends Stream
{
    private function __construct(
        private readonly bool $throws,
    ) {
        parent::__construct(\fopen('php://memory', 'r+b'));
    }

    public static function throwing(): self
    {
        return new self(true);
    }

    public static function stalled(): self
    {
        return new self(false);
    }

    #[\Override]
    public function eof(): bool
    {
        return false;
    }

    #[\Override]
    public function read($length): string
    {
        $this->throws and throw new \RuntimeException('Unable to read /var/tmp/secret/upload-42.');

        return '';
    }
}

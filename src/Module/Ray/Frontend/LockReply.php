<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray\Frontend;

use Buggregator\Trap\Module\Frontend\Message\HttpCode;
use Buggregator\Trap\Module\Ray\Lock\LockState;

/**
 * The reply to a Continue or Stop of a pause: `{code, status, state}`.
 *
 * @internal
 */
final class LockReply implements \JsonSerializable, HttpCode
{
    public function __construct(
        public readonly LockState $state,
    ) {}

    #[\Override]
    public function httpCode(): int
    {
        return 200;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'code' => 200,
            'status' => true,
            'state' => $this->state->value,
        ];
    }
}

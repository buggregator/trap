<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Message;

/**
 * @internal
 */
final class Response implements \JsonSerializable
{
    public function __construct(
        public readonly string|int $id,
        public ?Rpc $rpc = null,
        public ?Connect $connect = null,
    ) {}

    public function jsonSerialize(): array
    {
        $data = ['id' => $this->id];

        $this->rpc and $data['rpc'] = $this->rpc;
        $this->connect and $data['connect'] = $this->connect;

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Mapper;

use Buggregator\Trap\Proto\Frame\VarDumper;
use Buggregator\Trap\Sender\Frontend\Message\Event;
use Buggregator\Trap\Support\Uuid;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\Stub;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

/**
 * @internal
 */
final class VarDump
{
    public function map(VarDumper $frame): Event
    {
        $payload = $this->parse($frame->dump);
        return new Event(
            uuid: Uuid::uuid4(),
            type: 'var-dump',
            payload: [
                'payload' => [
                    'type' => $payload[0]->getType(),
                    'value' => $this->convertToPrimitive($payload[0]),
                ],
                'context' => $payload[1],
            ],
            timestamp: (float)$frame->time->format('U.u'),
        );
    }

    private function parse(string $message): array
    {
        $payload = @\unserialize(\base64_decode($message), ['allowed_classes' => [Data::class, Stub::class]]);

        // Impossible to decode the message, give up.
        if (false === $payload) {
            throw new \RuntimeException('Unable to decode a message from var-dumper client.');
        }

        if (
            !\is_array($payload)
            || \count($payload) < 2
            || !$payload[0] instanceof Data
            || !\is_array($payload[1])
        ) {
            throw new \RuntimeException('Invalid var-dumper payload.');
        }

        return $payload;
    }

    private function convertToPrimitive(Data $data): string|null
    {
        if (\in_array($data->getType(), ['string', 'boolean'])) {
            return (string)$data->getValue();
        }

        return (new HtmlDumper())->dump($data, true);
    }
}

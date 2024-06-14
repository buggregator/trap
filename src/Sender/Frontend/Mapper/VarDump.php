<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Mapper;

use Buggregator\Trap\Proto\Frame\VarDumper as VarDumperFrame;
use Buggregator\Trap\Sender\Frontend\Event;
use Buggregator\Trap\Support\Uuid;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\Stub;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

/**
 * @internal
 */
final class VarDump
{
    public function map(VarDumperFrame $frame): Event
    {
        $parsed = $this->parse($frame->dump);

        $dataContext = $parsed[0]->getContext();

        $payload = [
            'type' => $parsed[0]->getType(),
            'value' => $this->convertToPrimitive($parsed[0]),
            'label' => $dataContext['label'] ?? null,
        ];

        if (\array_key_exists('language', $dataContext) && \is_string($dataContext['language'])) {
            $payload['type'] = 'code';
            $payload['language'] = $dataContext['language'];
        }

        $project = \array_key_exists('project', $dataContext) && \is_scalar($dataContext['project'])
            ? (string) $dataContext['project']
            : null;

        return new Event(
            uuid: Uuid::generate(),
            type: 'var-dump',
            payload: [
                'payload' => $payload,
                'context' => $parsed[1],
            ],
            timestamp: (float) $frame->time->format('U.u'),
            project: $project,
        );
    }

    /**
     * @return array{0: Data, 1: array, ...}
     */
    private function parse(string $message): array
    {
        $payload = @\unserialize(\base64_decode($message, true), ['allowed_classes' => [Data::class, Stub::class]]);

        // Impossible to decode the message, give up.
        if ($payload === false) {
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
        if (\in_array($data->getType(), ['string', 'boolean'], true)) {
            /** @psalm-suppress PossiblyInvalidCast */
            return (string) $data->getValue();
        }

        return (new HtmlDumper())->dump($data, true);
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\VarDumper\Frontend;

use Buggregator\Trap\Module\VarDumper\Frame as VarDumperFrame;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Event\Facets;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Uuid;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\Stub;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

/**
 * @internal
 */
final class Mapper implements EventMapper
{
    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof VarDumperFrame);

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
            facets: (new Facets())->add(Facets::HOST, self::requestHost($parsed[1]))->toArray(),
        );
    }

    /**
     * A dump carries no machine name: the host of the request it was made in is the closest one.
     * A CLI dump has none.
     */
    private static function requestHost(array $context): ?string
    {
        /** @var mixed $request */
        $request = $context['request'] ?? null;
        /** @var mixed $uri */
        $uri = \is_array($request) ? $request['uri'] ?? null : null;
        $host = \is_string($uri) ? \parse_url($uri, \PHP_URL_HOST) : null;

        return \is_string($host) ? \trim($host, '[]') : null;
    }

    /**
     * @return array{0: Data, 1: array, ...}
     */
    private function parse(string $message): array
    {
        $payload = @\unserialize((string) \base64_decode($message, true), ['allowed_classes' => [Data::class, Stub::class]]);

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

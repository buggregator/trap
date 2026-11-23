<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Monolog\Frontend;

use Buggregator\Trap\Module\Monolog\Frame as MonologFrame;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Event\Facets;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class Mapper implements EventMapper
{
    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof MonologFrame);

        return new Event(
            uuid: Uuid::uuid4(),
            type: 'monolog',
            payload: $frame->message,
            timestamp: (float) $frame->time->format('U.u'),
            facets: self::facets($frame->message),
        );
    }

    /**
     * The machine is the one a `HostnameProcessor` puts into `extra`: Monolog sends none by default.
     *
     * @return array<non-empty-string, list<non-empty-string>>
     */
    private static function facets(array $message): array
    {
        /** @var array $extra */
        $extra = \is_array($message['extra'] ?? null) ? $message['extra'] : [];
        /** @var array $context */
        $context = \is_array($message['context'] ?? null) ? $message['context'] : [];
        /** @var mixed $trace */
        $trace = \is_string($extra['trace_id'] ?? null) && $extra['trace_id'] !== ''
            ? $extra['trace_id']
            : $context['trace_id'] ?? null;

        return (new Facets())
            ->add(Facets::CHANNEL, $message['channel'] ?? null)
            ->add(Facets::LEVEL, $message['level_name'] ?? null)
            ->add(Facets::HOST, $extra['hostname'] ?? null)
            ->add(Facets::TRACE, $trace)
            ->toArray();
    }
}

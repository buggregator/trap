<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler;

use Buggregator\Trap\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Handler\Router\Attribute\QueryParam;
use Buggregator\Trap\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventStorage;
use Buggregator\Trap\Proto\Frame\Profiler\Payload as ProfilerPayload;

trait ApiController
{
    private readonly Mapper $mapper;

    private readonly Logger $logger;

    private readonly EventStorage $eventsStorage;

    #[RegexpRoute(Method::Get, '#^api/profiler/(?<uuid>[a-f0-9-]++)/top$#i')]
    #[
        AssertSuccess(
            Method::Get,
            'api/profiler/0190402f-7eb2-7287-82a8-897a0091f58e/top?metric=excl_wt',
            [
                'uuid' => '0190402f-7eb2-7287-82a8-897a0091f58e',
                'metric' => 'excl_wt',
            ],
        ),
    ]
    public function profilerTop(string $uuid, #[QueryParam] string $metric = ''): Message\TopFunctions
    {
        $event = $this->eventsStorage->get($uuid) ?? throw new \RuntimeException('Event not found.');

        $event->payload instanceof ProfilerPayload or throw new \RuntimeException('Invalid payload type.');
        /** @var Event<ProfilerPayload> $event */

        return $this->mapper->topFunctions($event, $metric);
    }

    #[RegexpRoute(Method::Get, '#^api/profiler/(?<uuid>[a-f0-9-]++)/call-graph$#i')]
    #[
        AssertSuccess(
            Method::Get,
            'api/profiler/0190402f-7eb2-7287-82a8-897a0091f58e/call-graph',
            ['uuid' => '0190402f-7eb2-7287-82a8-897a0091f58e'],
        ),
    ]
    public function profilerCallGraph(string $uuid): Message\CallGraph
    {
        $event = $this->eventsStorage->get($uuid) ?? throw new \RuntimeException('Event not found.');

        $event?->payload instanceof ProfilerPayload or throw new \RuntimeException('Invalid payload type.');
        /** @var Event<ProfilerPayload> $event */

        return $this->mapper->callGraph($event);
    }

    #[RegexpRoute(Method::Get, '#^api/profiler/(?<uuid>[a-f0-9-]++)/flame-chart$#i')]
    #[
        AssertSuccess(
            Method::Get,
            'api/profiler/0190402f-7eb2-7287-82a8-897a0091f58e/flame-chart',
            ['uuid' => '0190402f-7eb2-7287-82a8-897a0091f58e'],
        ),
    ]
    public function profilerFlameChart(string $uuid): Message\FlameChart
    {
        $event = $this->eventsStorage->get($uuid) ?? throw new \RuntimeException('Event not found.');

        $event?->payload instanceof ProfilerPayload or throw new \RuntimeException('Invalid payload type.');
        /** @var Event<ProfilerPayload> $event */

        return $this->mapper->flameChart($event);
    }
}

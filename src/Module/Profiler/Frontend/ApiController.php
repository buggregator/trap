<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Profiler\Frame\Payload as ProfilerPayload;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Server\Handler\Router\Attribute\QueryParam;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Server\Handler\Router\Method;

/**
 * @internal
 */
final class ApiController
{
    public function __construct(
        private readonly Mapper $mapper,
        private readonly EventStore $eventsStorage,
    ) {}

    #[RegexpRoute(Method::Get, '#^api/profiler/(?<uuid>[a-f0-9-]++)/top$#i')]
    #[AssertSuccess(
        Method::Get,
        'api/profiler/0190402f-7eb2-7287-82a8-897a0091f58e/top?metric=excl_wt',
        [
            'uuid' => '0190402f-7eb2-7287-82a8-897a0091f58e',
            'metric' => 'excl_wt',
        ],
    ),]
    public function profilerTop(string $uuid, #[QueryParam] string $metric = 'wt'): Message\TopFunctions|Error
    {
        $event = $this->profile($uuid);
        if ($event === null) {
            return Error::notFound('Profile not found');
        }

        return $this->mapper->topFunctions($event, $metric);
    }

    #[RegexpRoute(Method::Get, '#^api/profiler/(?<uuid>[a-f0-9-]++)/call-graph$#i')]
    #[AssertSuccess(
        Method::Get,
        'api/profiler/0190402f-7eb2-7287-82a8-897a0091f58e/call-graph?threshold=1.1&percentage=15.1&metric=wt',
        [
            'uuid' => '0190402f-7eb2-7287-82a8-897a0091f58e',
            'threshold' => 1.1,
            'percentage' => 15.1,
            'metric' => 'wt',
        ],
    ),]
    public function profilerCallGraph(
        string $uuid,
        #[QueryParam] float $threshold = 1,
        #[QueryParam] float $percentage = 15,
        #[QueryParam] string $metric = 'wt',
    ): Message\CallGraph|Error {
        $event = $this->profile($uuid);
        if ($event === null) {
            return Error::notFound('Profile not found');
        }

        return $this->mapper->callGraph($event, $threshold, $percentage, $metric);
    }

    #[RegexpRoute(Method::Get, '#^api/profiler/(?<uuid>[a-f0-9-]++)/flame-chart$#i')]
    #[AssertSuccess(
        Method::Get,
        'api/profiler/0190402f-7eb2-7287-82a8-897a0091f58e/flame-chart?metric=mu',
        ['uuid' => '0190402f-7eb2-7287-82a8-897a0091f58e', 'metric' => 'mu'],
    ),]
    public function profilerFlameChart(string $uuid, #[QueryParam] string $metric = 'wt'): Message\FlameChart|Error
    {
        $event = $this->profile($uuid);
        if ($event === null) {
            return Error::notFound('Profile not found');
        }

        return $this->mapper->flameChart($event, $metric);
    }

    /**
     * @param string $base UUID of the base profile.
     * @param string $compare UUID of the profile to compare with it.
     */
    #[StaticRoute(Method::Get, 'api/profiler/compare')]
    #[AssertSuccess(
        Method::Get,
        'api/profiler/compare?base=0190402f-7eb2&compare=0190402f-7eb3',
        ['base' => '0190402f-7eb2', 'compare' => '0190402f-7eb3'],
    ),]
    public function profilerCompare(
        #[QueryParam] string $base = '',
        #[QueryParam] string $compare = '',
    ): Message\Comparison|Error {
        $a = $this->profile($base);
        $b = $this->profile($compare);
        if ($a === null || $b === null) {
            return Error::notFound('Profile not found');
        }

        return $this->mapper->compare($a, $b);
    }

    /**
     * @return Event<ProfilerPayload>|null Null when there is no such event or it is no profile.
     */
    private function profile(string $uuid): ?Event
    {
        $event = $this->eventsStorage->get($uuid);
        if (!$event?->payload instanceof ProfilerPayload) {
            return null;
        }

        /** @var Event<ProfilerPayload> $event */
        return $event;
    }
}

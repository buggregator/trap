<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray\Frontend;

use Buggregator\Trap\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Frontend\Message\Success;
use Buggregator\Trap\Module\Ray\Lock\Locks;

/**
 * The buttons of a paused Ray event: the UI sends `post:api/ray/locks/{name}` with `stop_execution: true`
 * to stop the client, and without it to let the client continue.
 *
 * @internal
 */
final class LocksController
{
    public function __construct(
        private readonly Logger $logger,
        private readonly Locks $locks,
    ) {}

    /**
     * @param mixed $stop_execution The UI sends a JSON boolean.
     */
    #[RegexpRoute(Method::Post, '#^api/ray/locks/(?<name>[\w.-]++)$#')]
    #[AssertSuccess(Method::Post, 'api/ray/locks/9e1ba2d1ba25b4e0d3c4d2a3b1f4c7e8', [
        'name' => '9e1ba2d1ba25b4e0d3c4d2a3b1f4c7e8',
    ]),
        AssertFail(Method::Get, 'api/ray/locks/9e1ba2d1ba25b4e0d3c4d2a3b1f4c7e8'),
        AssertFail(Method::Post, 'api/ray/locks/'),]
    public function resolve(string $name, mixed $stop_execution = false): Success
    {
        $stop = \filter_var($stop_execution, \FILTER_VALIDATE_BOOL);
        $this->logger->debug('[UI Service] %s Ray execution of lock %s', $stop ? 'Stop' : 'Continue', $name);

        return new Success(status: $this->locks->resolve($name, $stop));
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Frontend\Message\Success;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Json;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Server\Handler\Router\Attribute\QueryParam;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Telemetry\Cron\CheckIn;
use Buggregator\Trap\Server\Telemetry\Cron\CheckInMessage;
use Buggregator\Trap\Server\Telemetry\Cron\Monitor;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;

/**
 * The cron monitors of the Crons tab of the Sentry section, as buggregator/server answers `/api/crons`.
 *
 * @internal
 */
final class CronController
{
    /** Runs a monitor of the list shows */
    public const RECENT = 20;

    public function __construct(
        private readonly TelemetryStore $telemetry,
    ) {}

    /**
     * The monitors with their recent runs, by project and slug.
     *
     * @param string|null $project Only this project; every project when empty.
     */
    #[StaticRoute(Method::Get, 'api/crons')]
    #[AssertSuccess(Method::Get, 'api/crons?project=default'),
        AssertFail(Method::Post, 'api/crons'),]
    public function list(#[QueryParam] ?string $project = null): array
    {
        $project = \trim((string) $project);

        return ['data' => \array_map(
            self::monitor(...),
            $this->telemetry->crons->monitors($project === '' ? null : $project),
        )];
    }

    /**
     * The runs of a monitor, the newest first; 404 for a slug the project has not.
     */
    #[RegexpRoute(Method::Get, '#^api/crons/(?<slug>[^/]++)/checkins$#')]
    #[AssertSuccess(Method::Get, 'api/crons/reports-send/checkins?project=default&page=2', [
        'slug' => 'reports-send',
        'project' => 'default',
        'page' => 2,
        'limit' => 50,
    ]),
        AssertFail(Method::Get, 'api/crons/reports-send'),]
    public function checkIns(
        string $slug,
        #[QueryParam] ?string $project = null,
        #[QueryParam] int $page = 1,
        #[QueryParam] int $limit = 50,
    ): array|Error {
        $monitor = $this->find($slug, $project);
        if ($monitor === null) {
            return Error::notFound('cron monitor not found');
        }

        $result = Json::page($monitor->history(), Json::pageNumber($page), Json::limit($limit, 50));
        $result['data'] = \array_map(static fn(CheckIn $run): array => [
            'id' => $run->id,
            'status' => $run->status,
            'started_at' => Json::time($run->startedAt),
            'finished_at' => Json::time($run->finishedAt),
            'duration_ms' => $run->durationMs,
            'environment' => $run->environment,
            'trace_id' => $run->traceId,
            'source' => $run->source,
            'body' => $run->output,
            'server_made' => $run->serverMade(),
            'expected_at' => Json::time($run->expectedAt),
        ], $result['data']);

        return $result;
    }

    /**
     * Deletes the monitor and its runs; its next check-in makes it again.
     */
    #[RegexpRoute(Method::Delete, '#^api/crons/(?<slug>[^/]++)$#')]
    #[AssertSuccess(Method::Delete, 'api/crons/reports-send?project=billing', [
        'slug' => 'reports-send',
        'project' => 'billing',
    ]),
        AssertFail(Method::Delete, 'api/crons/reports-send/checkins'),]
    public function delete(string $slug, #[QueryParam] ?string $project = null): Success|Error
    {
        $monitor = $this->find($slug, $project);

        return $monitor !== null && $this->telemetry->crons->delete($monitor->project, $monitor->slug)
            ? new Success()
            : Error::notFound('cron monitor not found');
    }

    private static function monitor(Monitor $monitor): array
    {
        return [
            'project' => $monitor->project,
            'slug' => $monitor->slug,
            'source' => $monitor->source,
            'schedule' => $monitor->schedule?->toArray(),
            'timezone' => $monitor->timezone,
            'checkin_margin' => $monitor->margin,
            'max_runtime' => $monitor->maxRuntime,
            'last_status' => $monitor->lastStatus(),
            'last_checkin_at' => Json::time($monitor->lastCheckInAt),
            'next_expected_at' => Json::time($monitor->nextExpectedAt === null ? null : (float) $monitor->nextExpectedAt),
            'created_at' => Json::time($monitor->createdAt),
            'recent' => \array_map(static fn(CheckIn $run): array => [
                'status' => $run->status,
                'started_at' => Json::time($run->startedAt),
            ], \array_slice($monitor->history(), 0, self::RECENT)),
        ];
    }

    /**
     * @param string|null $project The default project when empty.
     */
    private function find(string $slug, ?string $project): ?Monitor
    {
        $project = \trim((string) $project);
        $slug = CheckInMessage::slug(\rawurldecode($slug));

        return $slug === null ? null : $this->telemetry->crons->monitor(
            $project === '' ? Event::DEFAULT_PROJECT : $project,
            $slug,
        );
    }
}

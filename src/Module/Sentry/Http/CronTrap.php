<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Http;

use Buggregator\Trap\Module\Sentry\Frame\EnvelopeItem;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Telemetry\Cron\CheckInMessage;
use Buggregator\Trap\Server\Telemetry\Cron\MonitorConfig;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Takes cron check-ins sent without an envelope, `GET` or `POST`, as a `check_in` envelope frame:
 *
 * - the Sentry cron HTTP endpoint `/api/{project_id}/cron/{slug}/{public_key}/`, with `status`, `check_in_id`,
 *   `environment` and `duration` (seconds) in the query or a JSON body that may have a `monitor_config` too;
 *   answered 202 with `{"id": "<check-in ID>"}`;
 * - the ping URL `/ping/{slug}` (finished), `/ping/{slug}/start` and `/ping/{slug}/fail`, whose body, the job's
 *   output, is kept, its last 10 KiB. `schedule` (a crontab), `tz`, `margin` and `max_runtime` (minutes) in the
 *   query configure the monitor, `check_in_id` and `environment` the run. Answered `OK`, with the run's ID in
 *   `X-Check-In-Id` when Trap knows it: a finish without an ID ends the newest started ping run.
 *
 * The project is `?project=` of a ping, else the `X-Buggregator-Project` header. A request marked
 * by `X-Buggregator-Event` for another module is left to it.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class CronTrap implements Middleware
{
    public const MAX_BODY_SIZE = 1024 * 1024;
    private const PING = '#^/ping/([^/]+)(?:/(start|fail))?/?$#D';
    private const SENTRY = '#^/api/[^/]+/cron/([^/]+)/[^/]+/?$#D';

    public function __construct(
        private readonly ?Logger $logger = null,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $marked = \strtolower(\trim($request->getHeaderLine('X-Buggregator-Event')));
        $ping = \preg_match(self::PING, $path, $match) === 1;
        if (!\in_array($request->getMethod(), ['GET', 'POST'], true)
            || $marked !== '' && $marked !== 'sentry'
            || !$ping && \preg_match(self::SENTRY, $path, $match) !== 1
        ) {
            return $next($request);
        }

        $size = $request->getBody()->getSize();
        if ($size === null || $size > self::MAX_BODY_SIZE) {
            return $this->reject(413, 'the body is larger than 1 MiB');
        }

        $slug = CheckInMessage::slug(\rawurldecode($match[1]));
        if ($slug === null) {
            return $this->reject(400, 'the monitor slug has no letter or digit');
        }

        try {
            return $ping
                ? $this->ping($request, $slug, $match[2] ?? '')
                : $this->sentry($request, $slug);
        } catch (\InvalidArgumentException $e) {
            return $this->reject(400, $e->getMessage());
        }
    }

    /**
     * The parser of Trap leaves the query params of a request empty.
     */
    private static function query(ServerRequestInterface $request): array
    {
        \parse_str($request->getUri()->getQuery(), $query);
        return $query;
    }

    private static function string(array $query, string $key): string
    {
        /** @var mixed $value */
        $value = $query[$key] ?? '';
        return \is_string($value) ? $value : '';
    }

    /**
     * @param non-empty-string $slug
     * @throws \InvalidArgumentException
     */
    private function ping(ServerRequestInterface $request, string $slug, string $action): ResponseInterface
    {
        $query = self::query($request);
        $status = match ($action) {
            'start' => CheckInMessage::IN_PROGRESS,
            'fail' => CheckInMessage::ERROR,
            default => CheckInMessage::OK,
        };
        $id = CheckInMessage::checkInId(self::string($query, 'check_in_id'));
        $id === '' && $status === CheckInMessage::IN_PROGRESS and $id = \bin2hex(\random_bytes(16));

        $config = [];
        ($schedule = \trim(self::string($query, 'schedule'))) === ''
            or $config['schedule'] = ['type' => 'crontab', 'value' => $schedule];
        $tz = \trim(self::string($query, 'tz'));
        \array_key_exists('tz', $query) and $config['timezone'] = $tz === '' ? 'UTC' : $tz;
        ($margin = self::string($query, 'margin')) === '' or $config['checkin_margin'] = $margin;
        ($runtime = self::string($query, 'max_runtime')) === '' or $config['max_runtime'] = $runtime;
        $config === [] or MonitorConfig::fromArray($config);

        $project = \trim(self::string($query, 'project'));
        $project === '' and $project = \trim($request->getHeaderLine('X-Buggregator-Project'));
        $this->send($request, $project === '' ? null : $project, ['source' => CheckInMessage::SOURCE_PING], [
            'monitor_slug' => $slug,
            'status' => $status,
            'output' => CheckInMessage::output((string) $request->getBody()),
        ] + ($id === '' ? [] : ['check_in_id' => $id])
            + (($env = \trim(self::string($query, 'environment'))) === '' ? [] : ['environment' => $env])
            + ($config === [] ? [] : ['monitor_config' => $config]));

        return new Response(
            200,
            ['Content-Type' => 'text/plain; charset=utf-8'] + ($id === '' ? [] : ['X-Check-In-Id' => $id]),
            "OK\n",
        );
    }

    /**
     * @param non-empty-string $slug
     * @throws \InvalidArgumentException
     */
    private function sentry(ServerRequestInterface $request, string $slug): ResponseInterface
    {
        $payload = [];
        $body = \trim((string) $request->getBody());
        if ($request->getMethod() === 'POST' && $body !== '') {
            try {
                /** @var mixed $payload */
                $payload = Json::finite(\json_decode($body, true, 32, \JSON_THROW_ON_ERROR));
            } catch (\JsonException) {
                throw new \InvalidArgumentException('the body is no JSON');
            }
            \is_array($payload) && !\array_is_list($payload)
                or throw new \InvalidArgumentException('the body is no JSON object');
        }

        $query = self::query($request);
        foreach (['status', 'check_in_id', 'environment'] as $key) {
            ($value = self::string($query, $key)) === '' or $payload[$key] = $value;
        }
        $duration = self::string($query, 'duration');
        \is_numeric($duration) and $payload['duration'] = (float) $duration;
        \in_array($payload['status'] ?? null, CheckInMessage::STATUSES, true)
            or throw new \InvalidArgumentException('status must be in_progress, ok or error');
        /** @var mixed $config */
        $config = $payload['monitor_config'] ?? null;
        \is_array($config) and MonitorConfig::fromArray($config);

        /** @var mixed $given */
        $given = $payload['check_in_id'] ?? null;
        $id = CheckInMessage::checkInId(\is_string($given) ? $given : '');
        $id === '' and $id = \bin2hex(\random_bytes(16));
        $payload['check_in_id'] = $id;
        $payload['monitor_slug'] = $slug;

        $project = \trim($request->getHeaderLine('X-Buggregator-Project'));
        $this->send($request, $project === '' ? null : $project, [], $payload);

        return new Response(202, ['Content-Type' => 'application/json'], Json::encode(['id' => $id]));
    }

    private function send(ServerRequestInterface $request, ?string $project, array $headers, array $payload): void
    {
        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        \Fiber::suspend(new SentryEnvelope(
            [],
            [new EnvelopeItem(['type' => 'check_in'] + $headers, $payload)],
            $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable(),
            $project,
        ));
    }

    private function reject(int $code, string $reason): ResponseInterface
    {
        $this->logger?->warning('Sentry Crons: check-in rejected with %d: %s.', $code, $reason);
        return new Response($code, ['Content-Type' => 'text/plain; charset=utf-8'], "$reason\n");
    }
}

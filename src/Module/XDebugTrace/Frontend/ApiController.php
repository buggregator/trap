<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Frontend;

use Buggregator\Trap\Module\XDebugTrace\Config\Traces;
use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Module\XDebugTrace\Trace\CallTree;
use Buggregator\Trap\Module\XDebugTrace\Trace\Page;
use Buggregator\Trap\Module\XDebugTrace\Trace\TraceError;
use Buggregator\Trap\Module\XDebugTrace\TraceStorage;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Handler\Router\Attribute\QueryParam;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Support\Json;
use Buggregator\Trap\Support\TimeSlice;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * The trace page of the UI:
 *
 *     GET /api/xdebug-traces/{uuid}/summary
 *     GET /api/xdebug-traces/{uuid}/tree?parent=&from=&limit=    one level of calls: the top level without parent,
 *                                                                the callees of the call at offset parent; from is
 *                                                                the next of a page of the same list
 *     GET /api/xdebug-traces/{uuid}/search?q=&from=&limit=
 *
 * Errors are `{error}` with an HTTP status.
 *
 * @internal
 */
final class ApiController
{
    private const DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly EventStore $events,
        private readonly TraceStorage $storage,
        private readonly Traces $config,
    ) {}

    #[RegexpRoute(Method::Get, '#^api/xdebug-traces/(?<uuid>[a-f0-9-]++)/summary$#i')]
    public function summary(string $uuid): array|ResponseInterface
    {
        $event = $this->events->get($uuid);
        if ($event === null || $event->type !== Frame::TYPE || !\is_array($event->payload)) {
            return self::error(404, 'trace not found');
        }

        $payload = $event->payload;
        unset($payload['file']);

        return $payload;
    }

    #[RegexpRoute(Method::Get, '#^api/xdebug-traces/(?<uuid>[a-f0-9-]++)/tree$#i')]
    public function tree(
        string $uuid,
        #[QueryParam] string $parent = '',
        #[QueryParam] string $from = '',
        #[QueryParam] string $limit = '',
    ): Page|ResponseInterface {
        $parentOffset = self::offset($parent);
        $fromOffset = self::offset($from);
        $max = self::limit($limit);
        if ($parentOffset === null || $fromOffset === null || $max === null) {
            return self::error(400, 'parent, from and limit must be whole numbers');
        }

        return $this->scan($uuid, static fn(CallTree $tree): Page => $tree->children($parentOffset, $fromOffset, $max));
    }

    #[RegexpRoute(Method::Get, '#^api/xdebug-traces/(?<uuid>[a-f0-9-]++)/search$#i')]
    public function search(
        string $uuid,
        #[QueryParam] string $q = '',
        #[QueryParam] string $from = '',
        #[QueryParam] string $limit = '',
    ): Page|ResponseInterface {
        $q = \trim($q);
        if ($q === '' || \strlen($q) > 256) {
            return self::error(400, 'q is required (at most 256 bytes)');
        }
        $fromOffset = self::offset($from);
        $max = self::limit($limit);
        if ($fromOffset === null || $max === null) {
            return self::error(400, 'from and limit must be whole numbers');
        }

        return $this->scan($uuid, static fn(CallTree $tree): Page => $tree->search($q, \max($fromOffset, 0), $max));
    }

    /**
     * @return int|null -1 when absent, null when not a whole number.
     */
    private static function offset(string $value): ?int
    {
        return match (true) {
            $value === '' => -1,
            \ctype_digit($value) => (int) $value,
            default => null,
        };
    }

    private static function limit(string $value): ?int
    {
        return match (true) {
            $value === '' => self::DEFAULT_LIMIT,
            \ctype_digit($value) && (int) $value > 0 => (int) $value,
            default => null,
        };
    }

    private static function error(int $status, string $message): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], Json::encode(['error' => $message]));
    }

    /**
     * @param \Closure(CallTree): Page $run
     */
    private function scan(string $uuid, \Closure $run): Page|ResponseInterface
    {
        $event = $this->events->get($uuid);
        if ($event === null || $event->type !== Frame::TYPE) {
            return self::error(404, 'trace not found');
        }
        $path = $this->storage->path($uuid);
        if ($path === null) {
            return self::error(404, 'trace file is gone');
        }

        try {
            return $run(new CallTree($path, $this->config->maxSizeBytes(), new TimeSlice()));
        } catch (TraceError $e) {
            return self::error(400, $e->getMessage());
        } catch (\Throwable $e) {
            return self::error(500, 'cannot read trace: ' . $e->getMessage());
        }
    }
}

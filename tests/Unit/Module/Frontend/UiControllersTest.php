<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Http\Pipeline;
use Buggregator\Trap\Module\Frontend\RPC;
use Buggregator\Trap\Module\Frontend\Service;
use Buggregator\Trap\Module\Frontend\UiControllers;
use Buggregator\Trap\Module\HttpDump\Frontend\AssetsController as HttpDumpAssetsController;
use Buggregator\Trap\Module\Mailer\Frame as SmtpFrame;
use Buggregator\Trap\Module\Mailer\Frontend\ApiController as MailerApiController;
use Buggregator\Trap\Module\Mailer\Frontend\AssetsController as MailerAssetsController;
use Buggregator\Trap\Module\Mailer\Frontend\Mapper as SmtpMapper;
use Buggregator\Trap\Module\Mailer\Message as SmtpMessage;
use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Frontend\ApiController as ProfilerApiController;
use Buggregator\Trap\Module\Profiler\Frontend\Mapper as ProfilerMapper;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Buggregator\Trap\Module\Ray\Frontend\LocksController as RayLocksController;
use Buggregator\Trap\Module\Ray\Frontend\Payload as RayPayload;
use Buggregator\Trap\Module\Ray\Lock\Locks;
use Buggregator\Trap\Module\Sentry\Frontend\ApiController as SentryApiController;
use Buggregator\Trap\Module\Sentry\Frontend\ClearController as SentryClearController;
use Buggregator\Trap\Module\Sentry\Frontend\CronController as SentryCronController;
use Buggregator\Trap\Module\Sentry\Frontend\QueueController as SentryQueueController;
use Buggregator\Trap\Module\XDebug\Debugger;
use Buggregator\Trap\Module\XDebug\Frontend\ApiController as XDebugApiController;
use Buggregator\Trap\Module\XDebugTrace\Frontend\ApiController as XDebugTraceApiController;
use Buggregator\Trap\Server\Event\AttachedFile;
use Buggregator\Trap\Server\Event\EmbeddedFile;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess;
use Buggregator\Trap\Server\Handler\Router\Router;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Log;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Internal\Container\ObjectContainer;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(UiControllers::class)]
#[Covers(MailerApiController::class)]
#[Covers(MailerAssetsController::class)]
#[Covers(HttpDumpAssetsController::class)]
#[Covers(ProfilerApiController::class)]
#[Covers(RayLocksController::class)]
#[Covers(SentryClearController::class)]
#[Test]
final class UiControllersTest
{
    public function testControllersInRouteMatchingOrder(): void
    {
        $controllers = (new ObjectContainer())->get(UiControllers::class);

        Assert::same(
            \array_map(static fn(object $c): string => $c::class, $controllers->api()),
            [
                Service::class,
                MailerApiController::class,
                RayLocksController::class,
                SentryClearController::class,
                SentryApiController::class,
                SentryQueueController::class,
                SentryCronController::class,
                ProfilerApiController::class,
                XDebugTraceApiController::class,
                XDebugApiController::class,
            ],
        );
        Assert::same(
            \array_map(static fn(object $c): string => $c::class, $controllers->assets()),
            [MailerAssetsController::class, HttpDumpAssetsController::class],
        );
    }

    public function testRoutesResolveToTheirControllersInComposedRouters(): void
    {
        $controllers = (new ObjectContainer())->get(UiControllers::class);

        foreach ([$controllers->api(), $controllers->assets()] as $list) {
            $router = Router::compose(...$list);
            foreach ($list as $controller) {
                foreach ((new \ReflectionClass($controller))->getMethods() as $method) {
                    foreach ($method->getAttributes(AssertRouteSuccess::class) as $attribute) {
                        $assertion = $attribute->newInstance();
                        $resolved = $router->resolve($assertion->method, $assertion->path);

                        Assert::same(
                            $resolved?->class . '::' . $resolved?->name,
                            $method->class . '::' . $method->name,
                            "{$assertion->method->value} {$assertion->path}",
                        );
                    }
                }
            }
        }
    }

    public function testProfilerTopOverHttpAndRpc(): void
    {
        $container = new ObjectContainer();
        $event = self::profilerEvent();
        $container->get(EventStore::class)->add($event);
        $expected = self::json((new ProfilerMapper())->topFunctions($event, 'excl_wt'));

        $response = self::get($container, "/api/profiler/{$event->uuid}/top?metric=excl_wt");
        // Query parameters are read from the path, not from the RPC data
        $rpc = $container->get(RPC::class)->handleMessage([
            'method' => "get:api/profiler/{$event->uuid}/top?metric=excl_wt",
        ]);

        Assert::same($response->getStatusCode(), 200);
        Assert::same(\json_decode((string) $response->getBody(), true), $expected);
        Assert::same(self::json($rpc), ['data' => ['code' => 200, 'data' => $expected]]);
    }

    public function testSmtpAttachmentsOverHttpAndRpc(): void
    {
        $container = new ObjectContainer();
        [$event, $attachId] = self::smtpEvent($container);
        $expected = [
            'data' => [[
                'uuid' => $attachId,
                'name' => 'doc.pdf',
                'path' => "/api/smtp/attachments/{$event->uuid}/{$attachId}",
                'size' => 3,
                'mime' => 'application/pdf',
            ]],
            'meta' => ['grid' => []],
        ];

        $response = self::get($container, "/api/smtp/attachments/{$event->uuid}");
        $rpc = $container->get(RPC::class)->handleMessage(['method' => "get:api/smtp/attachments/{$event->uuid}"]);

        Assert::same(\json_decode((string) $response->getBody(), true), $expected);
        Assert::same(self::json($rpc), ['data' => ['code' => 200, 'data' => $expected]]);
        Assert::same(
            \json_decode((string) self::get($container, '/api/smtp/attachments/0000-1111')->getBody(), true),
            ['message' => 'Event not found', 'code' => 404],
        );
    }

    /**
     * The debugger answers the HTTP request of the UI only: a field named as the request stands for none.
     */
    public function testDebuggerTakesRequestOfHttpOnly(): void
    {
        $container = new ObjectContainer();

        $http = self::get($container, '/api/xdebug?__request=1');
        $rpc = $container->get(RPC::class)->handleMessage(['method' => 'get:api/xdebug', 'data' => ['__request' => []]]);

        Assert::same($http->getStatusCode(), 200);
        Assert::same($http->getHeaderLine('Cache-Control'), 'no-cache');
        Assert::false(\json_decode((string) $http->getBody(), true)['enabled']);
        Assert::same(self::json($rpc)['data']['code'], 403);
        Assert::same(self::json($rpc)['data']['data']['code'], 'http_only');
    }

    public function testDebuggerBreakpointStatusesOverHttp(): void
    {
        $container = new ObjectContainer();
        $container->get(Debugger::class)->enable();
        $pipeline = $container->get(Pipeline::class);
        $headers = ['Content-Type' => 'application/json', 'Sec-Fetch-Site' => 'same-origin'];

        $added = $pipeline->handle(
            new ServerRequest('POST', '/api/xdebug/breakpoints', $headers, '{"file":"/app/a.php","line":3}'),
            static fn(): Response => new Response(418),
        );
        $id = \json_decode((string) $added->getBody(), true)['id'];
        $deleted = $pipeline->handle(
            new ServerRequest('DELETE', "/api/xdebug/breakpoints/$id", $headers),
            static fn(): Response => new Response(418),
        );

        Assert::same($added->getStatusCode(), 201);
        Assert::same($deleted->getStatusCode(), 204);
        Assert::same((string) $deleted->getBody(), '');
        Assert::same($container->get(Debugger::class)->breakpoints->all(), []);
    }

    public function testSmtpHtmlAndAttachmentAssets(): void
    {
        $container = new ObjectContainer();
        [$event, $attachId] = self::smtpEvent($container);

        $html = self::get($container, "/api/smtp/{$event->uuid}/html");
        $file = self::get($container, "/api/smtp/attachments/{$event->uuid}/{$attachId}");

        Assert::same($html->getStatusCode(), 200);
        Assert::same($html->getHeaderLine('Content-Type'), 'text/html; charset=utf-8');
        Assert::same((string) $html->getBody(), '<p>hi</p>');
        Assert::same($file->getStatusCode(), 200);
        Assert::same($file->getHeaderLine('Content-Disposition'), 'attachment; filename="doc.pdf"; filename*=UTF-8\'\'doc.pdf');
        Assert::same((string) $file->getBody(), 'pdf');
    }

    /**
     * The UI adds `token` and `project` to the data of every RPC call.
     */
    public function testRayLocksOverRpc(): void
    {
        $container = new ObjectContainer();
        $locks = $container->get(Locks::class);
        $locks->create('aaa');
        $locks->create('bbb');
        $rpc = $container->get(RPC::class);

        $continue = $rpc->handleMessage(['method' => 'post:api/ray/locks/aaa', 'data' => ['token' => null, 'project' => 'default']]);
        $stop = $rpc->handleMessage([
            'method' => 'post:api/ray/locks/bbb',
            'data' => ['token' => null, 'project' => 'default', 'stop_execution' => true],
        ]);
        $again = $rpc->handleMessage(['method' => 'post:api/ray/locks/aaa', 'data' => ['stop_execution' => true]]);
        $unknown = $rpc->handleMessage(['method' => 'post:api/ray/locks/ccc', 'data' => []]);

        Assert::same(self::json($continue), ['data' => ['code' => 200, 'data' => ['code' => 200, 'status' => true, 'state' => 'continued']]]);
        Assert::same(self::json($stop), ['data' => ['code' => 200, 'data' => ['code' => 200, 'status' => true, 'state' => 'stopped']]]);
        // Another tab's click gets the state of the first one
        Assert::same(self::json($again)['data']['data']['state'], 'continued');
        Assert::same(self::json($unknown), ['data' => ['code' => 404, 'data' => ['error' => 'Lock not found.', 'status' => false]]]);
        Assert::false($locks->poll('aaa')?->stop);
        Assert::true($locks->poll('bbb')?->stop);
    }

    /**
     * The event of the pause keeps its state for a reload, after the lock is gone.
     */
    public function testRayLocksOverHttp(): void
    {
        $container = new ObjectContainer();
        $locks = $container->get(Locks::class);
        $locks->create('aaa');
        $container->get(EventStore::class)->add(new Event('0145a0e0', 'ray', new RayPayload('0145a0e0', [
            ['type' => 'create_lock', 'content' => ['name' => 'aaa'], 'origin' => []],
        ], []), 1.0));
        $pipeline = $container->get(Pipeline::class);
        $post = static fn(): ResponseInterface => $pipeline->handle(
            new ServerRequest('POST', '/api/ray/locks/aaa', ['Content-Type' => 'application/json'], '{"stop_execution":true,"project":"default"}'),
            static fn(): Response => new Response(404),
        );

        $response = $post();
        Assert::same($response->getStatusCode(), 200);
        Assert::same(\json_decode((string) $response->getBody(), true), ['code' => 200, 'status' => true, 'state' => 'stopped']);
        Assert::true($locks->poll('aaa')?->stop);

        $locks->poll('aaa', \microtime(true) + Locks::RESOLVED_TTL + 1);
        Assert::false($locks->has('aaa'));
        $event = $pipeline->handle(new ServerRequest('GET', '/api/event/0145a0e0'), static fn(): Response => new Response(404));
        Assert::same(\json_decode((string) $event->getBody(), true)['payload']['payloads'][0]['content'], ['name' => 'aaa', 'state' => 'stopped']);
        Assert::same(\json_decode((string) $post()->getBody(), true)['state'], 'stopped');
    }

    /**
     * The UI tells that the script no longer waits.
     */
    public function testUnknownRayLockOverHttpIsNotFound(): void
    {
        $response = (new ObjectContainer())->get(Pipeline::class)->handle(
            new ServerRequest('POST', '/api/ray/locks/ccc', ['Content-Type' => 'application/json'], '{}'),
            static fn(): Response => new Response(500),
        );

        Assert::same($response->getStatusCode(), 404);
        Assert::same(\json_decode((string) $response->getBody(), true), ['error' => 'Lock not found.', 'status' => false]);
    }

    /**
     * The UI takes an RPC call for done only on a 2xx `code`; a call with no reply fails it by a timeout.
     */
    public function testRpcRepliesWithCodeOfTheCall(): void
    {
        $container = new ObjectContainer();
        $container->get(EventStore::class)->add(new Event('0145a0e0', 'smtp', [], 1.0));
        $rpc = $container->get(RPC::class);

        $pin = $rpc->handleMessage(['method' => 'post:api/event/0145a0e0/pin', 'data' => ['token' => null, 'project' => 'default']]);
        $missing = $rpc->handleMessage(['method' => 'post:api/event/0145a0e1/pin', 'data' => []]);
        $unknown = $rpc->handleMessage(['method' => 'post:api/nothing', 'data' => []]);
        $clear = $rpc->handleMessage(['method' => 'delete:api/events', 'data' => ['project' => 'default', 'keep_pinned' => true]]);

        Assert::same(self::json($pin)['data']['code'], 200);
        Assert::true($container->get(EventStore::class)->isPinned('0145a0e0'));
        Assert::same(self::json($missing)['data']['code'], 404);
        Assert::same(self::json($unknown)['data']['code'], 404);
        Assert::same(self::json($clear)['data']['code'], 200);
        Assert::count($container->get(EventStore::class), 1);
    }

    /**
     * The UI sends it with every clear, and takes the clear for failed when it fails.
     */
    public function testSentryClearDeletesSentryEventsOfProject(): void
    {
        $container = new ObjectContainer();
        $store = $container->get(EventStore::class);
        $store->add(new Event('aa01', 'sentry', [], 1.0));
        $store->add(new Event('aa02', 'sentry', [], 2.0));
        $store->add(new Event('aa03', 'sentry', [], 3.0, project: 'shop'));
        $store->add(new Event('aa04', 'smtp', [], 4.0));
        $store->pin('aa02');
        $telemetry = $container->get(TelemetryStore::class);
        foreach (['default', 'shop'] as $project) {
            $batch = new Batch($project);
            $batch->logs[] = new Log("l-$project", $project, 'info', 'hi', 1.0, Source::Sentry);
            $telemetry->add($batch);
        }

        $response = $container->get(Pipeline::class)->handle(
            new ServerRequest('DELETE', '/api/sentry/all?project=default&keep_pinned=1'),
            static fn(): Response => new Response(404),
        );

        Assert::same($response->getStatusCode(), 200);
        Assert::same(\array_keys(\iterator_to_array($store)), ['aa04', 'aa03', 'aa02']);
        Assert::same(\array_map(static fn(Log $log): string => $log->project, $telemetry->logs()), ['shop']);
    }

    public function testHttpDumpFileDownload(): void
    {
        $container = new ObjectContainer();
        $file = new AttachedFile('0b1b', new UploadedFile(Stream::create('abc'), 3, \UPLOAD_ERR_OK, 'a.txt', 'text/plain'));
        $container->get(EventStore::class)->add(
            new Event('0a1a', 'http-dump', [], 1.0, assets: new \ArrayObject(['0b1b' => $file])),
        );

        $found = self::get($container, '/api/http-dump/attachments/0a1a/0b1b');
        $missing = self::get($container, '/api/http-dump/attachments/0a1a/0b1c');

        Assert::same($found->getStatusCode(), 200);
        Assert::same($found->getHeaderLine('Content-Disposition'), 'attachment; filename="a.txt"; filename*=UTF-8\'\'a.txt');
        Assert::same((string) $found->getBody(), 'abc');
        Assert::same($missing->getStatusCode(), 404);
    }

    public function testFileWithoutNameIsDownloadedAsUnnamed(): void
    {
        $container = new ObjectContainer();
        $file = new AttachedFile('0b1b', new UploadedFile(Stream::create('abc'), 3, \UPLOAD_ERR_OK, '', 'text/plain'));
        $container->get(EventStore::class)->add(
            new Event('0a1a', 'http-dump', [], 1.0, assets: new \ArrayObject(['0b1b' => $file])),
        );

        $response = self::get($container, '/api/http-dump/attachments/0a1a/0b1b');

        Assert::string($response->getHeaderLine('Content-Disposition'))->contains('filename="unnamed"');
    }

    public function testProfilerAnswersNotFoundForNoProfile(): void
    {
        $container = new ObjectContainer();
        $container->get(EventStore::class)->add(new Event('0a1a', 'smtp', [], 1.0));

        foreach (['top', 'call-graph', 'flame-chart'] as $part) {
            Assert::same(self::get($container, "/api/profiler/0a1a/{$part}")->getStatusCode(), 404, $part);
            Assert::same(self::get($container, "/api/profiler/0b1b/{$part}")->getStatusCode(), 404, $part);
        }
        Assert::same(self::get($container, '/api/profiler/compare?base=0a1a&compare=0b1b')->getStatusCode(), 404);
    }

    public function testParameterOfWrongTypeIsBadRequest(): void
    {
        $container = new ObjectContainer();
        $pipeline = $container->get(Pipeline::class);
        $next = static fn(): Response => new Response(418);

        $preview = self::get($container, '/api/events/preview?project[]=x');
        $sentry = $pipeline->handle(new ServerRequest('DELETE', '/api/sentry/all?project[]=x'), $next);
        $clear = $pipeline->handle(new ServerRequest('DELETE', '/api/events', [], '{"uuids":"x"}'), $next);
        $rpc = $container->get(RPC::class)->handleMessage(['method' => 'delete:api/events', 'data' => ['project' => []]]);

        Assert::same($preview->getStatusCode(), 400);
        Assert::same($sentry->getStatusCode(), 400);
        Assert::same($clear->getStatusCode(), 400);
        Assert::same(self::json($rpc)['data']['code'], 400);
        Assert::same(self::get($container, '/api/profiler/0a1a/top?metric[]=x')->getStatusCode(), 400);
        Assert::same(
            self::json($container->get(RPC::class)->handleMessage(['method' => 'get:api/profiler/0a1a/top?metric[]=x']))['data']['code'],
            400,
        );
    }

    public function testMissingEventIsNotFoundOverHttp(): void
    {
        $response = self::get(new ObjectContainer(), '/api/event/0145a0e0-0b1a');

        Assert::same($response->getStatusCode(), 404);
        Assert::true($response->hasHeader(Pipeline::FRONTEND_HEADER));
    }

    /**
     * Opened by its URL, a caught email must not run as a page of the UI: it could call the debugger.
     */
    public function testSmtpContentOpenedByUrlIsSandboxed(): void
    {
        $container = new ObjectContainer();
        $logo = new File(['Content-Disposition' => ['inline; name="logo"'], 'Content-Type' => ['image/svg+xml']], 'logo', 'logo.svg');
        $logo->setStream(Stream::create('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
        $message = SmtpMessage::create(protocol: [], headers: [])
            ->withMessages([new Field(['Content-Type' => ['text/html']], value: '<script>alert(1)</script><img src="cid:logo">')])
            ->withAttachments([$logo]);
        $event = (new SmtpMapper())->map(new SmtpFrame($message));
        $container->get(EventStore::class)->add($event);
        $embedded = \array_filter(\iterator_to_array($event->assets), static fn(object $a): bool => $a instanceof EmbeddedFile);
        Assert::count($embedded, 1);

        $html = self::get($container, "/api/smtp/{$event->uuid}/html");
        $file = self::get($container, "/api/smtp/attachments/{$event->uuid}/" . \array_key_first($embedded));

        Assert::same($html->getHeaderLine('Content-Security-Policy'), 'sandbox');
        Assert::same($file->getStatusCode(), 200);
        Assert::same($file->getHeaderLine('Content-Security-Policy'), 'sandbox');
    }

    private static function get(ObjectContainer $container, string $uri): ResponseInterface
    {
        return $container->get(Pipeline::class)->handle(
            new ServerRequest('GET', $uri),
            static fn(): Response => new Response(404),
        );
    }

    private static function profilerEvent(): Event
    {
        $profile = (new ProfileBuilder())->createProfile(calls: [
            'main()==>foo' => ['ct' => 1, 'wt' => 104, 'cpu' => 0, 'mu' => 4168, 'pmu' => 0],
            'main()' => ['ct' => 1, 'wt' => 139, 'cpu' => 0, 'mu' => 5936, 'pmu' => 0],
        ]);

        return new Event(
            uuid: $profile->uuid,
            type: 'profiler',
            payload: Payload::new(Type::XHProf, static fn() => $profile),
            timestamp: 0.0,
        );
    }

    /**
     * @return array{Event, non-empty-string} The stored event and the ID of its attachment.
     */
    private static function smtpEvent(ObjectContainer $container): array
    {
        $file = new File(
            ['Content-Disposition' => ['attachment; filename="doc.pdf"'], 'Content-Type' => ['application/pdf']],
            'doc',
            'doc.pdf',
        );
        $file->setStream(Stream::create('pdf'));
        $message = SmtpMessage::create(protocol: [], headers: [])
            ->withMessages([new Field(['Content-Type' => ['text/html']], value: '<p>hi</p>')])
            ->withAttachments([$file]);
        $event = (new SmtpMapper())->map(new SmtpFrame($message));
        $container->get(EventStore::class)->add($event);

        return [$event, (string) \array_key_first(\iterator_to_array($event->assets))];
    }

    private static function json(mixed $message): array
    {
        return \json_decode(\json_encode($message, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }
}

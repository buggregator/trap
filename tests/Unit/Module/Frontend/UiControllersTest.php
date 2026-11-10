<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventStorage;
use Buggregator\Trap\Module\Frontend\Http\Pipeline;
use Buggregator\Trap\Module\Frontend\RPC;
use Buggregator\Trap\Module\Frontend\Service;
use Buggregator\Trap\Module\Frontend\UiControllers;
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
use Buggregator\Trap\Module\Ray\Lock\Locks;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess;
use Buggregator\Trap\Server\Handler\Router\Router;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Internal\Container\ObjectContainer;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(UiControllers::class)]
#[Covers(MailerApiController::class)]
#[Covers(MailerAssetsController::class)]
#[Covers(ProfilerApiController::class)]
#[Covers(RayLocksController::class)]
#[Test]
final class UiControllersTest
{
    public function testControllersInRouteMatchingOrder(): void
    {
        $controllers = (new ObjectContainer())->get(UiControllers::class);

        Assert::same(
            \array_map(static fn(object $c): string => $c::class, $controllers->api()),
            [Service::class, MailerApiController::class, RayLocksController::class, ProfilerApiController::class],
        );
        Assert::same(
            \array_map(static fn(object $c): string => $c::class, $controllers->assets()),
            [MailerAssetsController::class],
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
        $container->get(EventStorage::class)->add($event);
        $expected = self::json((new ProfilerMapper())->topFunctions($event, 'excl_wt'));

        $response = self::get($container, "/api/profiler/{$event->uuid}/top?metric=excl_wt");
        // Query parameters are read from the path, not from the RPC data
        $rpc = $container->get(RPC::class)->handleMessage([
            'method' => "get:api/profiler/{$event->uuid}/top?metric=excl_wt",
        ]);

        Assert::same($response->getStatusCode(), 200);
        Assert::same(\json_decode((string) $response->getBody(), true), $expected);
        Assert::same(self::json($rpc), ['data' => $expected]);
    }

    public function testSmtpAttachmentsOverHttpAndRpc(): void
    {
        $container = new ObjectContainer();
        [$event, $attachId] = self::smtpEvent($container);
        $expected = [
            'data' => [[
                'uuid' => $attachId,
                'name' => 'doc.pdf',
                'path' => "{$event->uuid}/attachment/{$attachId}",
                'size' => 3,
                'mime' => 'application/pdf',
            ]],
            'meta' => ['grid' => []],
        ];

        $response = self::get($container, "/api/smtp/{$event->uuid}/attachments");
        $rpc = $container->get(RPC::class)->handleMessage(['method' => "get:api/smtp/{$event->uuid}/attachments"]);

        Assert::same(\json_decode((string) $response->getBody(), true), $expected);
        Assert::same(self::json($rpc), ['data' => $expected]);
        Assert::same(
            \json_decode((string) self::get($container, '/api/smtp/0000-1111/attachments')->getBody(), true),
            ['code' => 200, 'status' => false],
        );
    }

    public function testSmtpHtmlAndAttachmentAssets(): void
    {
        $container = new ObjectContainer();
        [$event, $attachId] = self::smtpEvent($container);

        $html = self::get($container, "/api/smtp/{$event->uuid}/html");
        $file = self::get($container, "/api/smtp/{$event->uuid}/attachment/{$attachId}");

        Assert::same($html->getStatusCode(), 200);
        Assert::same($html->getHeaderLine('Content-Type'), 'text/html');
        Assert::same((string) $html->getBody(), '<p>hi</p>');
        Assert::same($file->getStatusCode(), 200);
        Assert::same($file->getHeaderLine('Content-Disposition'), 'attachment; filename="doc.pdf"');
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
        $unknown = $rpc->handleMessage(['method' => 'post:api/ray/locks/ccc', 'data' => []]);

        Assert::same(self::json($continue), ['data' => ['code' => 200, 'status' => true]]);
        Assert::same(self::json($stop), ['data' => ['code' => 200, 'status' => true]]);
        Assert::same(self::json($unknown), ['data' => ['code' => 200, 'status' => false]]);
        Assert::false($locks->poll('aaa')?->stop);
        Assert::true($locks->poll('bbb')?->stop);
    }

    public function testRayLocksOverHttp(): void
    {
        $container = new ObjectContainer();
        $locks = $container->get(Locks::class);
        $locks->create('aaa');

        $response = $container->get(Pipeline::class)->handle(
            new ServerRequest('POST', '/api/ray/locks/aaa', ['Content-Type' => 'application/json'], '{"stop_execution":true,"project":"default"}'),
            static fn(): Response => new Response(404),
        );

        Assert::same($response->getStatusCode(), 200);
        Assert::same(\json_decode((string) $response->getBody(), true), ['code' => 200, 'status' => true]);
        Assert::true($locks->poll('aaa')?->stop);
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
        $container->get(EventStorage::class)->add($event);

        return [$event, (string) \array_key_first(\iterator_to_array($event->assets))];
    }

    private static function json(mixed $message): array
    {
        return \json_decode(\json_encode($message, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }
}

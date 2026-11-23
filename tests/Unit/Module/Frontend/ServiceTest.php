<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Config\Frontend;
use Buggregator\Trap\Module\Frontend\Http\Router;
use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Frontend\Service;
use Buggregator\Trap\Module\Frontend\UiControllers;
use Buggregator\Trap\Module\XDebug\Debugger;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Config\TcpPorts;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Logger;
use Internal\Container\ObjectContainer;
use Nyholm\Psr7\ServerRequest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Service::class)]
#[Covers(Event::class)]
#[Test]
final class ServiceTest
{
    public function testEventWithoutProjectBelongsToDefaultOne(): void
    {
        $event = new Event('a1', 'smtp', [], 1.0);

        Assert::same($event->jsonSerialize()['project'], 'default');
        Assert::same((new Event('a2', 'smtp', [], 1.0, project: ''))->projectKey(), 'default');
        Assert::same((new Event('a3', 'smtp', [], 1.0, project: 'shop'))->projectKey(), 'shop');
    }

    public function testProjectsListDefaultFirstAndProjectsOfEvents(): void
    {
        [$service] = self::service();

        Assert::same(self::json($service->projects())['data'], [
            ['name' => 'Default', 'key' => 'default', 'is_default' => true],
            ['name' => 'shop', 'key' => 'shop', 'is_default' => false],
        ]);
    }

    public function testPreviewFiltersEventsByProject(): void
    {
        [$service] = self::service();

        Assert::same(self::uuids($service->eventsList('shop')), ['s1']);
        Assert::same(self::uuids($service->eventsList('default')), ['d2', 'd1']);
        Assert::same(self::uuids($service->eventsList()), ['d2', 's1', 'd1']);
    }

    public function testPreviewTakesEventsOfWindowAndLimit(): void
    {
        $storage = new EventStore();
        $storage->add(new Event('old', 'smtp', [], \microtime(true) - 7200));
        $storage->add(new Event('new', 'smtp', [], \microtime(true) - 60));
        $storage->add(new Event('newest', 'smtp', [], \microtime(true)));
        $service = self::create($storage);

        Assert::same(self::uuids($service->eventsList('default', '1h')), ['newest', 'new']);
        Assert::same(self::uuids($service->eventsList('default', 'all')), ['newest', 'new', 'old']);
        Assert::same(self::uuids($service->eventsList('default', limit: '1')), ['newest']);
    }

    public function testPreviewOfHttpRequestTakesTheFiltersOfItsQuery(): void
    {
        $storage = new EventStore();
        $storage->add(new Event('l1', 'monolog', [], 1.0, facets: ['level' => ['info']]));
        $storage->add(new Event('l2', 'monolog', [], 2.0, facets: ['level' => ['error']]));
        $storage->add(new Event('d1', 'smtp', [], 3.0));
        $service = self::create($storage);
        $request = static fn(string $query): ServerRequest => new ServerRequest('GET', "/api/events/preview?{$query}");

        Assert::same(self::uuids($service->eventsList(__request: $request('type=monolog'))), ['l2', 'l1']);
        Assert::same(self::uuids($service->eventsList(__request: $request('level=info&level=error'))), ['l2', 'l1']);
        Assert::same(self::uuids($service->eventsList(__request: $request('not.level=error'))), ['d1', 'l1']);
        Assert::same(self::json($service->eventsList(__request: $request('type=monolog&limit=1')))['data'][0]['facets'], [
            'level' => ['error'],
        ]);
        Assert::same(self::json($service->eventsFacets($request('type=monolog'))), [
            'data' => ['level' => [['value' => 'error', 'count' => 1], ['value' => 'info', 'count' => 1]]],
            'keys' => ['level'],
        ]);
        Assert::same(self::json($service->eventsHistogram(60, $request('type=monolog')))['data'][0]['count'], 2);
        Assert::same($service->eventsHistogram(0)->httpCode(), 400);

        $container = new ObjectContainer();
        $container->set($service);
        $container->set($container->make(Modules::class, ['list' => []]));
        $router = new Router(new Logger(), $container->get(UiControllers::class));
        $response = $router->handle(
            new ServerRequest('GET', '/api/events/histogram?type=monolog&bucket=31622401'),
            static fn(): never => throw new \LogicException('Not routed'),
        );
        Assert::same($response->getStatusCode(), 400);
        Assert::string((string) $response->getBody())->contains('bucket');
    }

    public function testEventsHaveTheirPinState(): void
    {
        [$service] = self::service();
        $service->eventPin('d1');

        Assert::same(\array_column(self::json($service->eventsList())['data'], 'is_pinned', 'uuid'), [
            'd2' => false,
            's1' => false,
            'd1' => true,
        ]);

        $service->eventUnpin('d1');
        Assert::false(self::json($service->eventShow('d1'))['is_pinned']);
    }

    public function testPinOfMissingEventIsNotFound(): void
    {
        [$service] = self::service();

        Assert::same($service->eventPin('missing')->httpCode(), 404);
        Assert::same($service->eventUnpin('missing')->httpCode(), 404);
    }

    public function testMissingEventIsNotFound(): void
    {
        [$service] = self::service();

        Assert::instanceOf($service->eventShow('missing'), Error::class);
        Assert::same($service->eventShow('missing')->httpCode(), 404);
    }

    public function testDeletesEventsOfProject(): void
    {
        [$service, $storage] = self::service();

        $service->eventsDelete(project: 'default');

        Assert::same(\array_keys(\iterator_to_array($storage)), ['s1']);
    }

    public function testDeletesEventsOfTypeInProject(): void
    {
        [$service, $storage] = self::service();

        $service->eventsDelete(type: 'smtp', project: 'default');

        Assert::same(\array_keys(\iterator_to_array($storage)), ['d2', 's1']);
    }

    public function testDeletesListedEventsWhateverTheProject(): void
    {
        [$service, $storage] = self::service();

        $service->eventsDelete(uuids: ['s1', 'd1'], project: 'default');

        Assert::same(\array_keys(\iterator_to_array($storage)), ['d2']);
    }

    public function testClearKeepsPinnedEventsWhenAsked(): void
    {
        [$service, $storage] = self::service();
        $service->eventPin('d1');
        $service->eventPin('s1');

        $service->eventsDelete(project: 'default', keep_pinned: true);
        Assert::same(\array_keys(\iterator_to_array($storage)), ['s1', 'd1']);

        $service->eventsDelete(keep_pinned: true);
        Assert::same(\array_keys(\iterator_to_array($storage)), ['s1', 'd1']);

        $service->eventsDelete(project: 'default');
        Assert::same(\array_keys(\iterator_to_array($storage)), ['s1']);
    }

    public function testSettingsListEventTypesOfTrap(): void
    {
        [$service] = self::service();

        Assert::same(
            self::json($service->settings())['events'],
            ['var-dump', 'smtp', 'monolog', 'ray', 'sentry', 'profiler', 'xdebug-trace', 'http-dump'],
        );
    }

    public function testSettingsListPortsOfTrap(): void
    {
        [$service] = self::service();

        Assert::same(
            self::json($service->settings())['ports'],
            ['http' => 8000, 'smtp' => 1025, 'monolog' => 9913, 'var_dumper' => 9912],
        );
    }

    public function testSettingsTakeTheFirstPortForTheMissingOnes(): void
    {
        $ports = new TcpPorts();
        $ports->ports = [9912, 8000];
        $ui = new Frontend();
        $ui->port = 8080;
        $service = new Service(new Logger(), new EventStore(), self::modules(), $ui, $ports, new ObjectContainer());

        Assert::same(
            self::json($service->settings())['ports'],
            ['http' => 8000, 'smtp' => 9912, 'monolog' => 9912, 'var_dumper' => 9912],
        );
    }

    public function testSettingsListNoPortOfDebuggerWhileOff(): void
    {
        [$service] = self::service();

        Assert::false(\array_key_exists('xdebug', self::json($service->settings())['ports']));
    }

    public function testSettingsListPortOfDebuggerWhileOn(): void
    {
        $container = new ObjectContainer();
        $container->get(Debugger::class)->enable();
        $service = new Service(new Logger(), new EventStore(), self::modules(), new Frontend(), new TcpPorts(), $container);

        Assert::same(self::json($service->settings())['ports']['xdebug'] ?? null, 9003);
    }

    /**
     * @return array{Service, EventStore} Storage with events `d1` (smtp), `s1` (smtp, project `shop`)
     *         and `d2` (var-dump), the newest first.
     */
    private static function service(): array
    {
        $storage = new EventStore();
        $storage->add(new Event('d1', 'smtp', [], 1.0));
        $storage->add(new Event('s1', 'smtp', [], 2.0, project: 'shop'));
        $storage->add(new Event('d2', 'var-dump', [], 3.0));

        return [self::create($storage), $storage];
    }

    private static function create(EventStore $storage): Service
    {
        return new Service(new Logger(), $storage, self::modules(), new Frontend(), new TcpPorts(), new ObjectContainer());
    }

    private static function modules(): Modules
    {
        return (new ObjectContainer())->get(Modules::class);
    }

    /**
     * @return list<string>
     */
    private static function uuids(\JsonSerializable $collection): array
    {
        return \array_column(self::json($collection)['data'], 'uuid');
    }

    private static function json(\JsonSerializable|array $message): array
    {
        return \json_decode(\json_encode($message, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }
}

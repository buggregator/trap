<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventStorage;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Mapper;
use Buggregator\Trap\Module\Frontend\Service;
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

    public function testSettingsListEventTypesOfTrap(): void
    {
        [$service] = self::service();

        Assert::same(
            self::json($service->settings())['client']['events'],
            ['var-dump', 'smtp', 'sentry', 'profiler', 'monolog', 'http-dump'],
        );
    }

    /**
     * @return array{Service, EventStorage} Storage with events `d1` (smtp), `s1` (smtp, project `shop`)
     *         and `d2` (var-dump), the newest first.
     */
    private static function service(): array
    {
        $storage = new EventStorage();
        $storage->add(new Event('d1', 'smtp', [], 1.0));
        $storage->add(new Event('s1', 'smtp', [], 2.0, project: 'shop'));
        $storage->add(new Event('d2', 'var-dump', [], 3.0));

        return [new Service(new Logger(), new Mapper(), $storage), $storage];
    }

    /**
     * @return list<string>
     */
    private static function uuids(\JsonSerializable $collection): array
    {
        return \array_column(self::json($collection)['data'], 'uuid');
    }

    private static function json(\JsonSerializable $message): array
    {
        return \json_decode(\json_encode($message, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Application\Module\Modules;
use Buggregator\Trap\Module\Frontend\FrameMapper;
use Buggregator\Trap\Module\Frontend\Service;
use Buggregator\Trap\Module\Frontend\UiControllers;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Tests\Unit\Module\Frontend\Stub\ApiController;
use Buggregator\Trap\Tests\Unit\Module\Frontend\Stub\AssetsController;
use Buggregator\Trap\Tests\Unit\Module\Frontend\Stub\UiModule;
use Internal\Container\ObjectContainer;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * The Frontend takes controllers, mappers and event types from the modules and adds its own.
 */
#[Covers(UiControllers::class)]
#[Covers(FrameMapper::class)]
#[Covers(Service::class)]
#[Test]
final class UiContributionsTest
{
    public function testControllersOfModulesFollowTheFrontendOnes(): void
    {
        $controllers = new UiControllers(self::container());

        Assert::same(self::classes($controllers->api()), [Service::class, ApiController::class]);
        Assert::same(self::classes($controllers->assets()), [AssetsController::class]);
    }

    public function testMapsFramesWithMappersOfModulesAndItsOwn(): void
    {
        $mapper = new FrameMapper(self::container());

        Assert::same($mapper->map(new Frame\Binary(Stream::create('data')))?->type, 'binary');
        Assert::same($mapper->map(new Frame\Http(new ServerRequest('GET', 'http://localhost/')))?->type, 'http-dump');
    }

    public function testFrameOfNoModuleFailsToMap(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        (new FrameMapper(new ObjectContainer()))->map(new Frame\Binary(Stream::create('data')));
    }

    public function testSettingsListEventTypesOfModulesThenHttpDump(): void
    {
        $settings = self::container()->get(Service::class)->settings()->jsonSerialize();

        Assert::same($settings['client']['events'], ['binary', 'http-dump']);
    }

    private static function container(): ObjectContainer
    {
        $container = new ObjectContainer();
        $container->set($container->make(Modules::class, ['list' => [UiModule::class]]));

        return $container;
    }

    /**
     * @param list<object> $objects
     * @return list<class-string>
     */
    private static function classes(array $objects): array
    {
        return \array_map(static fn(object $object): string => $object::class, $objects);
    }
}

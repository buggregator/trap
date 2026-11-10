<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\EventStorage;
use Buggregator\Trap\Module\Frontend\FrameMapper;
use Buggregator\Trap\Module\Frontend\Service;
use Buggregator\Trap\Module\Frontend\UiControllers;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Tests\Unit\Module\Frontend\Stub\ApiController;
use Buggregator\Trap\Tests\Unit\Module\Frontend\Stub\AssetsController;
use Buggregator\Trap\Tests\Unit\Module\Frontend\Stub\ChildFrame;
use Buggregator\Trap\Tests\Unit\Module\Frontend\Stub\ChildUiModule;
use Buggregator\Trap\Tests\Unit\Module\Frontend\Stub\TakingHttpUi;
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
        $controllers = (self::container())->get(UiControllers::class);

        Assert::same(self::classes($controllers->api()), [Service::class, ApiController::class]);
        Assert::same(self::classes($controllers->assets()), [AssetsController::class]);
    }

    public function testMapsFramesWithMappersOfModulesAndItsOwn(): void
    {
        $mapper = (self::container())->get(FrameMapper::class);

        Assert::same($mapper->map(new Frame\Binary(Stream::create('data')))?->type, 'binary');
        Assert::same($mapper->map(new Frame\Http(new ServerRequest('GET', 'http://localhost/')))?->type, 'http-dump');
    }

    public function testMapperTakesSubclassesOfItsFrame(): void
    {
        $mapper = (self::container())->get(FrameMapper::class);

        Assert::same($mapper->map(new ChildFrame())?->type, 'binary');
    }

    public function testMapperOfExactFrameClassWinsOverParentOne(): void
    {
        $container = new ObjectContainer();
        $container->set($container->make(Modules::class, ['list' => [UiModule::class, ChildUiModule::class]]));

        Assert::same($container->get(FrameMapper::class)->map(new ChildFrame())?->type, 'child');
    }

    public function testFrameOfNoModuleFailsToMap(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        ((new ObjectContainer())->get(FrameMapper::class))->map(new Frame\Binary(Stream::create('data')));
    }

    public function testSettingsListEventTypesOfModulesThenHttpDump(): void
    {
        $settings = self::container()->get(Service::class)->settings()->jsonSerialize();

        Assert::same($settings['client']['events'], ['binary', 'http-dump']);
    }

    public function testMapperOfTakenFrameClassIsRejected(): void
    {
        $container = self::container(TakingHttpUi::class);

        Expect::exception(\LogicException::class)->withMessageContaining('is already given by');

        new FrameMapper($container->get(Modules::class), $container);
    }

    public function testDuplicateEventTypeIsRejected(): void
    {
        $modules = self::container(UiModule::class, UiModule::class)->get(Modules::class);

        Expect::exception(\LogicException::class)->withMessageContaining('is already declared by');

        new Service(new Logger(), new EventStorage(), $modules);
    }

    public function testHttpDumpEventTypeIsReserved(): void
    {
        $modules = self::container(TakingHttpUi::class)->get(Modules::class);

        Expect::exception(\LogicException::class)->withMessageContaining('is reserved for HTTP dumps');

        new Service(new Logger(), new EventStorage(), $modules);
    }

    /**
     * @param class-string ...$modules
     */
    private static function container(string ...$modules): ObjectContainer
    {
        $container = new ObjectContainer();
        $container->set($container->make(Modules::class, ['list' => $modules === [] ? [UiModule::class] : $modules]));

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

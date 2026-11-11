<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Application\Module;

use Buggregator\Trap\Module\HttpDump\Http\DebugPage;
use Buggregator\Trap\Module\HttpDump\Http\Dump;
use Buggregator\Trap\Module\Mailer\Traffic\Dispatcher as MailerDispatcher;
use Buggregator\Trap\Module\Monolog\Traffic\Dispatcher as MonologDispatcher;
use Buggregator\Trap\Module\Profiler\Config\SPX;
use Buggregator\Trap\Module\Profiler\Config\XDebug;
use Buggregator\Trap\Module\Profiler\Config\XHProf;
use Buggregator\Trap\Module\Profiler\Http\SpeedscopeTrap;
use Buggregator\Trap\Module\Profiler\Http\XDebugTrap;
use Buggregator\Trap\Module\Profiler\Http\XHProfTrap;
use Buggregator\Trap\Module\Ray\Http\RayTrap;
use Buggregator\Trap\Module\Sentry\Http\SentryTrap;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap;
use Buggregator\Trap\Module\VarDumper\Traffic\Dispatcher as VarDumperDispatcher;
use Buggregator\Trap\Server\Application\Module\Module;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Service\FilesObserver\ProvidesFileObservers;
use Buggregator\Trap\Server\Traffic\ProvidesDispatchers;
use Buggregator\Trap\Tests\Unit\Server\Application\Module\Stub\Dispatching;
use Buggregator\Trap\Tests\Unit\Server\Application\Module\Stub\DispatchingAndHandling;
use Buggregator\Trap\Tests\Unit\Server\Application\Module\Stub\Handling;
use Buggregator\Trap\Tests\Unit\Server\Application\Module\Stub\Plain;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Modules::class)]
#[Test]
final class ModulesTest
{
    public function testFiltersByInterfaceKeepingListOrder(): void
    {
        $modules = $this->modules([Handling::class, Plain::class, DispatchingAndHandling::class, Dispatching::class]);

        Assert::same(
            self::classes($modules->implementing(ProvidesDispatchers::class)),
            [DispatchingAndHandling::class, Dispatching::class],
        );
        Assert::same(
            self::classes($modules->implementing(ProvidesHttpMiddlewares::class)),
            [Handling::class, DispatchingAndHandling::class],
        );
        Assert::same(
            self::classes($modules->implementing(Module::class)),
            [Handling::class, Plain::class, DispatchingAndHandling::class, Dispatching::class],
        );
    }

    public function testOrderFollowsTheList(): void
    {
        $modules = $this->modules([Dispatching::class, DispatchingAndHandling::class]);
        $reversed = $this->modules([DispatchingAndHandling::class, Dispatching::class]);

        Assert::same(
            self::classes($modules->implementing(ProvidesDispatchers::class)),
            [Dispatching::class, DispatchingAndHandling::class],
        );
        Assert::same(
            self::classes($reversed->implementing(ProvidesDispatchers::class)),
            [DispatchingAndHandling::class, Dispatching::class],
        );
    }

    public function testNoModuleImplementsTheInterface(): void
    {
        Assert::same($this->modules([Plain::class])->implementing(ProvidesDispatchers::class), []);
    }

    public function testListAndManifestsAreContainerServices(): void
    {
        $container = new ObjectContainer();

        $modules = $container->get(Modules::class);

        Assert::same($container->get(Modules::class), $modules);
        Assert::same($modules->implementing(ProvidesDispatchers::class)[0], $container->get(\Buggregator\Trap\Module\VarDumper\Module::class));
    }

    /**
     * Pins the order the server applies the module contributions in.
     */
    public function testServerModulesProvideInOrder(): void
    {
        $modules = (new ObjectContainer())->get(Modules::class);

        $dispatchers = $middlewares = $configs = [];
        foreach ($modules->implementing(ProvidesDispatchers::class) as $module) {
            $dispatchers = [...$dispatchers, ...$module->dispatchers()];
        }
        foreach ($modules->implementing(ProvidesHttpMiddlewares::class) as $module) {
            $middlewares = [...$middlewares, ...$module->httpMiddlewares()];
        }
        foreach ($modules->implementing(ProvidesFileObservers::class) as $module) {
            $configs = [...$configs, ...$module->fileObserverConfigs()];
        }

        Assert::same($dispatchers, [VarDumperDispatcher::class, MailerDispatcher::class, MonologDispatcher::class]);
        Assert::same(
            $middlewares,
            [
                RayTrap::class,
                SentryTrap::class,
                XHProfTrap::class,
                XDebugTrap::class,
                SpeedscopeTrap::class,
                OtlpTrap::class,
                DebugPage::class,
                Dump::class,
            ],
        );
        Assert::same($configs, [XHProf::class, XDebug::class, SPX::class]);
    }

    /**
     * @param list<object> $objects
     * @return list<class-string>
     */
    private static function classes(array $objects): array
    {
        return \array_map(static fn(object $object): string => $object::class, $objects);
    }

    /**
     * @param list<class-string<Module>> $list
     */
    private function modules(array $list): Modules
    {
        return (new ObjectContainer())->make(Modules::class, ['list' => $list]);
    }
}

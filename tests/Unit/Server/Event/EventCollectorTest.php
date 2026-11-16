<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Event;

use Buggregator\Trap\Server\Application;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Bootstrap;
use Buggregator\Trap\Server\Event\EventCollector;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Proto\Buffer;
use Buggregator\Trap\Server\Proto\Frame\Binary;
use Buggregator\Trap\Server\Sender;
use Buggregator\Trap\Tests\Unit\Module\Frontend\Stub\ParentFrame;
use Buggregator\Trap\Tests\Unit\Server\Event\Stub\CountingMapper;
use Buggregator\Trap\Tests\Unit\Server\Event\Stub\EventModule;
use Buggregator\Trap\Tests\Unit\Server\Event\Stub\EventSender;
use Internal\Container\Container;
use Nyholm\Psr7\Stream;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The application maps the buffered frames to events once, for all the event consumers.
 */
#[Test]
#[Covers(EventCollector::class)]
#[Covers(Application::class)]
final class EventCollectorTest
{
    public function testFramesAreMappedOnceForTwoConsumers(): void
    {
        $container = self::container();
        $senders = [new EventSender(), new EventSender()];
        $frames = [self::frame(), self::frame()];

        self::flush($container, $senders, $frames);

        Assert::same($container->get(CountingMapper::class)->calls, 2);
        $store = $container->get(EventStore::class);
        Assert::same($store->record('e1')?->frames, []);
        Assert::same($store->record('e2')?->frames, []);
        Assert::same($senders[0]->frames, $frames);
        Assert::same($senders[1]->frames, $frames);
    }

    public function testFramesAreKeptWhenTheStoreKeepsThem(): void
    {
        $container = self::container();
        $store = $container->get(EventStore::class);
        $store->keepFrames();
        $frames = [self::frame(), self::frame()];

        self::flush($container, [new EventSender()], $frames);

        Assert::same($store->record('e1')?->frames, [$frames[0]]);
        Assert::same($store->record('e2')?->frames, [$frames[1]]);
    }

    public function testFramesAreNotMappedWithoutConsumers(): void
    {
        $container = self::container();
        $sender = new class implements Sender {
            public function send(iterable $frames): void {}
        };

        self::flush($container, [$sender], [self::frame()]);

        Assert::same($container->get(CountingMapper::class)->calls, 0);
        Assert::count($container->get(EventStore::class), 0);
    }

    public function testFailedMappingIsLoggedAndSkipped(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $container = Bootstrap::init()->finish();
        $container->set(new Logger($output));
        $container->set(new Modules($container, []));
        $collector = $container->get(EventCollector::class);

        $collector->collect([new ParentFrame(), self::frame()]);

        Assert::string($output->fetch())->contains('Mapping frame failed');
        Assert::count($container->get(EventStore::class), 0);
    }

    private static function container(): Container
    {
        $container = Bootstrap::init()->finish();
        $container->set(new Logger(new BufferedOutput()));
        $container->set(new Modules($container, [EventModule::class]));

        return $container;
    }

    /**
     * Sends the frames to the senders as the main loop does when the buffer is ready.
     *
     * @param list<Sender> $senders
     * @param list<Binary> $frames
     */
    private static function flush(Container $container, array $senders, array $frames): void
    {
        $app = new Application($container, [], $senders);
        $buffer = $container->get(Buffer::class);
        foreach ($frames as $frame) {
            $buffer->addFrame($frame);
        }

        (new \ReflectionMethod($app, 'sendBuffer'))->invoke($app, $senders);
        $app->process();
        $app->destroy();
    }

    private static function frame(): Binary
    {
        return new Binary(Stream::create('data'));
    }
}

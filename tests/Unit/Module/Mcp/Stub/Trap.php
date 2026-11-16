<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Monolog\Frame as MonologFrame;
use Buggregator\Trap\Module\VarDumper\Frame as VarDumperFrame;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\FrameMapper;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Proto\Frame;
use Internal\Container\ObjectContainer;
use Symfony\Component\VarDumper\Cloner\VarCloner;

/**
 * A container with the real modules and an event store filled as the application fills it.
 */
final class Trap
{
    public readonly ObjectContainer $container;
    public readonly EventStore $store;

    public function __construct()
    {
        $this->container = new ObjectContainer();
        $this->container->set(new Modules($this->container));
        $this->store = $this->container->get(EventStore::class);
        // As the MCP sender does
        $this->store->keepFrames();
    }

    public static function dump(mixed $value, array $context = []): VarDumperFrame
    {
        $data = (new VarCloner())->cloneVar($value);
        $context === [] or $data = $data->withContext($context);

        return new VarDumperFrame(
            \base64_encode(\serialize([$data, ['timestamp' => 1_700_000_000, 'cli' => ['command_line' => 'test']]])),
            new \DateTimeImmutable('2023-11-14 22:13:20.5 UTC'),
        );
    }

    public static function log(string $message, string $level = 'ERROR'): MonologFrame
    {
        return new MonologFrame([
            'message' => $message,
            'level_name' => $level,
            'channel' => 'app',
            'context' => [],
        ]);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): object
    {
        return $this->container->get($class);
    }

    public function add(Frame $frame): Record
    {
        $event = $this->container->get(FrameMapper::class)->map($frame);
        \assert($event !== null);

        return $this->store->add($event, $frame);
    }
}

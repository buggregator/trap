<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Console;

use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Sender;
use Internal\Container\Container;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class ConsoleSender implements Sender
{
    private readonly FrameHandler $handler;

    /**
     * The renderers of the modules go first, in the module list order; the channel's own ones last,
     * with {@see Renderer\Plain} accepting any frame.
     *
     * @param Container $container Renderers get their dependencies from it, such as the spans shared
     *        by the Trap process.
     */
    public function __construct(OutputInterface $output, Modules $modules, Container $container)
    {
        $this->handler = new FrameHandler($output);
        foreach ($modules->implementing(ProvidesRenderers::class) as $module) {
            foreach ($module->renderers() as $class) {
                $this->handler->register($container->make($class));
            }
        }

        $this->handler->register(new Renderer\Binary());
        $this->handler->register(new Renderer\Plain());
    }

    #[\Override]
    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            $this->handler->handle($frame);
        }
    }
}

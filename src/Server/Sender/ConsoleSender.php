<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Sender;
use Buggregator\Trap\Server\Sender\Console\FrameHandler;
use Buggregator\Trap\Server\Sender\Console\ProvidesRenderers;
use Buggregator\Trap\Server\Sender\Console\Renderer;
use Internal\Container\Container;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class ConsoleSender implements Sender
{
    public function __construct(
        private readonly FrameHandler $handler,
    ) {}

    /**
     * The renderers of the modules go first, in the module list order; the core ones last,
     * with {@see Renderer\Plain} accepting any frame.
     *
     * @param Container $container Renderers get their dependencies from it, such as the spans shared
     *        by the Trap process.
     */
    public static function create(OutputInterface $output, Container $container): self
    {
        $handler = new FrameHandler($output);
        foreach ($container->get(Modules::class)->implementing(ProvidesRenderers::class) as $module) {
            foreach ($module->renderers() as $class) {
                $handler->register($container->make($class));
            }
        }

        $handler->register(new Renderer\Http());
        $handler->register(new Renderer\Binary());
        $handler->register(new Renderer\Plain());

        return new self($handler);
    }

    #[\Override]
    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            $this->handler->handle($frame);
        }
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\View;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Console\Renderer;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Proto\Frame;
use Internal\Container\Container;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The frames of an event as the console prints them, without colors, each by the first module renderer
 * that supports it, as {@see \Buggregator\Trap\Module\Console\ConsoleSender} picks them.
 *
 * Renderers are created for every event, so no state of theirs is shared with the console or carried
 * between events.
 *
 * @internal
 */
final class ConsoleText implements EventView
{
    /** Terminal width the renderers that wrap lines get. */
    public const WIDTH = 120;

    /** @var list<class-string<Renderer>> */
    private readonly array $renderers;

    public function __construct(
        Modules $modules,
        private readonly Container $container,
    ) {
        $renderers = [];
        foreach ($modules->implementing(ProvidesRenderers::class) as $module) {
            $renderers = [...$renderers, ...$module->renderers()];
        }

        $this->renderers = $renderers;
    }

    #[\Override]
    public function summary(Event $event): ?string
    {
        return null;
    }

    /**
     * @return string|null Null for an event stored without frames or with frames no module renders.
     */
    #[\Override]
    public function text(Record $record): ?string
    {
        $renderers = [];
        foreach ($this->renderers as $class) {
            $renderers[] = $this->container->make($class);
        }

        $parts = [];
        foreach ($record->frames as $frame) {
            foreach ($renderers as $renderer) {
                /** @psalm-suppress RedundantCondition The frame type of a renderer is unknown here, so its assertion is `Frame` */
                if ($renderer->isSupport($frame)) {
                    $parts[] = self::render($renderer, $frame);
                    break;
                }
            }
        }

        return $parts === [] ? null : \implode("\n", $parts);
    }

    private static function render(Renderer $renderer, Frame $frame): string
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $columns = \getenv('COLUMNS');
        // Symfony reads the width from the environment first; otherwise it asks the terminal of the process
        \putenv('COLUMNS=' . self::WIDTH);
        try {
            // A renderer may suspend its fiber while reading a stream: resumed at once, the rendering
            // stays synchronous, and no other fiber sees the changed environment
            $fiber = new \Fiber(static fn() => $renderer->render($output, $frame));
            $fiber->start();
            while (!$fiber->isTerminated()) {
                $fiber->resume();
            }
        } catch (\Throwable $e) {
            $output->writeln(\sprintf('Rendering of the %s frame failed: %s', $frame->type, OutputFormatter::escape($e->getMessage())));
        } finally {
            \putenv($columns === false ? 'COLUMNS' : "COLUMNS=$columns");
        }

        return self::clean($output->fetch());
    }

    /**
     * The padding of the console badges and the blank lines around the text carry nothing.
     */
    private static function clean(string $text): string
    {
        $text = (string) \preg_replace('/[ \t]+$/m', '', \str_replace("\r\n", "\n", $text));

        return \ltrim(\rtrim($text, "\n"), "\n ") . "\n";
    }
}

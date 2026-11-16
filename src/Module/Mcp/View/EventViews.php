<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\View;

use Buggregator\Trap\Module\Mcp\ProvidesEventViews;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Support\Json;
use Internal\Container\Container;

/**
 * The views of the modules, in the module list order, then {@see PayloadSummary} and {@see ConsoleText}.
 *
 * @internal
 */
final class EventViews
{
    /** @var list<EventView> */
    private readonly array $views;

    public function __construct(Modules $modules, Container $container)
    {
        $views = [];
        foreach ($modules->implementing(ProvidesEventViews::class) as $module) {
            foreach ($module->eventViews() as $class) {
                $views[] = $container->get($class);
            }
        }

        $views[] = $container->get(PayloadSummary::class);
        $views[] = $container->get(ConsoleText::class);
        $this->views = $views;
    }

    /**
     * @return non-empty-string The event type when no view knows the event.
     */
    public function summary(Event $event): string
    {
        foreach ($this->views as $view) {
            try {
                $summary = $view->summary($event);
            } catch (\Throwable) {
                // A payload of an unexpected shape must not fail the whole listing
                continue;
            }

            if ($summary !== null) {
                return $summary;
            }
        }

        return $event->type;
    }

    /**
     * @return string The JSON of the payload when no view knows the event.
     */
    public function text(Record $record): string
    {
        foreach ($this->views as $view) {
            $text = $view->text($record);
            if ($text !== null) {
                return $text;
            }
        }

        try {
            return Json::encode($record->event->payload, \JSON_PRETTY_PRINT);
        } catch (\JsonException $e) {
            return 'The event has no text view: ' . $e->getMessage();
        }
    }
}

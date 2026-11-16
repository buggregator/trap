<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\ClearEvents;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Buggregator\Trap\Tests\Unit\Server\Event\Stub\RecordingListener;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ClearEvents::class)]
final class ClearEventsTest
{
    public function testClearsAll(): void
    {
        $trap = self::trap();
        $listener = new RecordingListener();
        $trap->store->listen($listener);

        $result = $trap->get(ClearEvents::class)->call([]);

        Assert::same($result, ['removed' => 3]);
        Assert::count($trap->store, 0);
        Assert::same($listener->calls, ['cleared']);
    }

    #[DataSet([['type' => 'var-dump'], 2, ['monolog@default']], 'type')]
    #[DataSet([['type' => ['var-dump', 'monolog']], 3, []], 'types')]
    #[DataSet([['project' => 'shop'], 1, ['monolog@default', 'var-dump@default']], 'project')]
    #[DataSet([['type' => 'var-dump', 'project' => 'default'], 1, ['monolog@default', 'var-dump@shop']], 'type and project')]
    #[DataSet([['type' => 'smtp'], 0, ['monolog@default', 'var-dump@default', 'var-dump@shop']], 'nothing matches')]
    public function testDeletesMatchingEvents(array $arguments, int $removed, array $left): void
    {
        $trap = self::trap();

        $result = $trap->get(ClearEvents::class)->call($arguments);

        Assert::same($result, ['removed' => $removed]);
        Assert::same(self::types($trap), $left);
    }

    public function testDeletionReachesListeners(): void
    {
        $trap = self::trap();
        $listener = new RecordingListener();
        $trap->store->listen($listener);

        $trap->get(ClearEvents::class)->call(['type' => 'monolog']);

        Assert::count($listener->calls, 1);
        Assert::string((string) $listener->calls[0])->startsWith('deleted');
    }

    public function testRepeatedCallRemovesNothing(): void
    {
        $trap = self::trap();
        $tool = $trap->get(ClearEvents::class);
        $tool->call(['type' => 'var-dump']);

        Assert::same($tool->call(['type' => 'var-dump']), ['removed' => 0]);
    }

    public function testUnknownTypeFails(): never
    {
        $trap = self::trap();

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Unknown event type `nope`');

        $trap->get(ClearEvents::class)->call(['type' => 'nope']);
    }

    public function testIsDestructiveAndIdempotent(): void
    {
        $tool = (new Trap())->get(ClearEvents::class);

        Assert::same($tool->name(), 'clear_events');
        Assert::same($tool->annotations(), [
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ]);
        Assert::same(\array_keys($tool->inputSchema()['properties']), ['type', 'project']);
    }

    private static function trap(): Trap
    {
        $trap = new Trap();
        $trap->add(Trap::dump('dump'));
        $trap->add(Trap::log('log'));
        $trap->add(Trap::dump('shop dump', ['project' => 'shop']));

        return $trap;
    }

    /**
     * @return list<string>
     */
    private static function types(Trap $trap): array
    {
        $types = \array_map(
            static fn(Event $event): string => "$event->type@{$event->projectKey()}",
            \iterator_to_array($trap->store, false),
        );
        \sort($types);

        return $types;
    }
}

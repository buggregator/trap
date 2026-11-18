<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\DeleteEvent;
use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\JsonSchema;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Buggregator\Trap\Tests\Unit\Server\Event\Stub\RecordingListener;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DeleteEvent::class)]
final class DeleteEventTest
{
    public function testDeletesOneEvent(): void
    {
        [$trap, $records] = self::trap();
        $listener = new RecordingListener();
        $trap->store->listen($listener);
        $id = $records[1]->event->uuid;

        $result = $trap->get(DeleteEvent::class)->call(['id' => $id]);

        Assert::same($result, ['deleted' => 1]);
        Assert::null($trap->store->record($id));
        Assert::count($trap->store, 2);
        Assert::same($listener->calls, ["deleted:$id"]);
    }

    public function testDeletesManyEventsAndListsTheMissingOnes(): void
    {
        [$trap, $records] = self::trap();
        $first = $records[0]->event->uuid;
        $last = $records[2]->event->uuid;

        $result = $trap->get(DeleteEvent::class)->call(['ids' => [$first, 'nope', $last, $first]]);

        Assert::same($result, ['deleted' => 2, 'not_found' => ['nope']]);
        Assert::same(\array_keys(\iterator_to_array($trap->store)), [$records[1]->event->uuid]);
    }

    public function testUnknownIdFails(): never
    {
        [$trap] = self::trap();

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('No event with ID `nope`: it may have been deleted before or pushed out by newer events. Call `list_events` for the current IDs.');

        $trap->get(DeleteEvent::class)->call(['id' => 'nope']);
    }

    public function testDeletedIdFailsAndDeletesNothingMore(): void
    {
        [$trap, $records] = self::trap();
        $tool = $trap->get(DeleteEvent::class);
        $id = $records[0]->event->uuid;
        $tool->call(['id' => $id]);

        try {
            $tool->call(['ids' => [$id, 'nope']]);
            Assert::fail('The call must fail.');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->startsWith('None of the IDs is of a stored event: they may have been deleted');
        }

        Assert::count($trap->store, 2);
    }

    #[DataSet([[], 'Pass either `id`'], 'nothing')]
    #[DataSet([['id' => 'a', 'ids' => ['b']], 'Pass either `id`'], 'both')]
    #[DataSet([['id' => ''], '`id` must be a non-empty string.'], 'empty id')]
    #[DataSet([['id' => 42], '`id` must be a non-empty string.'], 'number id')]
    #[DataSet([['ids' => []], '`ids` must be a list of 1 to 200 non-empty strings.'], 'no ids')]
    #[DataSet([['ids' => ['a', '']], '`ids` must be a list of 1 to 200 non-empty strings.'], 'empty item')]
    #[DataSet([['ids' => 'a'], '`ids` must be a list of 1 to 200 non-empty strings.'], 'string ids')]
    #[DataSet([['ids' => ['x' => 'a']], '`ids` must be a list of 1 to 200 non-empty strings.'], 'object ids')]
    public function testMalformedArgumentsDeleteNothing(array $arguments, string $message): void
    {
        [$trap] = self::trap();

        try {
            $trap->get(DeleteEvent::class)->call($arguments);
            Assert::fail('The call must fail.');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->startsWith($message);
        }

        Assert::count($trap->store, 3);
    }

    public function testTooManyIdsFail(): never
    {
        [$trap] = self::trap();

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('1 to 200');

        $trap->get(DeleteEvent::class)->call(['ids' => \array_map(\strval(...), \range(1, DeleteEvent::MAX_IDS + 1))]);
    }

    #[DataSet([false], 'all found')]
    #[DataSet([true], 'some missing')]
    public function testResultConformsToOutputSchema(bool $missing): void
    {
        [$trap, $records] = self::trap();
        $tool = $trap->get(DeleteEvent::class);

        $result = $tool->call(['ids' => [$records[0]->event->uuid, ...($missing ? ['nope'] : [])]]);

        Assert::same(JsonSchema::errors($result, (array) $tool->outputSchema()), []);
    }

    public function testIsListedAsDestructiveAndIdempotent(): void
    {
        $tool = (new Trap())->get(Tools::class)->get('delete_event');

        Assert::instanceOf($tool, DeleteEvent::class);
        Assert::same($tool->title(), 'Delete event');
        Assert::string($tool->description())
            ->contains('up to ' . DeleteEvent::MAX_IDS . ' with `ids`')
            ->contains('use `clear_events`');
        Assert::same($tool->annotations(), [
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ]);
        Assert::same(\array_keys($tool->inputSchema()['properties']), ['id', 'ids']);
    }

    /**
     * @return array{Trap, list<Record>}
     */
    private static function trap(): array
    {
        $trap = new Trap();

        return [$trap, [$trap->add(Trap::dump('one')), $trap->add(Trap::log('two')), $trap->add(Trap::dump('three'))]];
    }
}

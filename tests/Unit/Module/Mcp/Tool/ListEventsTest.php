<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\EventFilter;
use Buggregator\Trap\Module\Mcp\Tool\ListEvents;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\JsonSchema;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ListEvents::class)]
#[Covers(EventFilter::class)]
final class ListEventsTest
{
    public function testListsEventsInChangeOrder(): void
    {
        $trap = new Trap();
        $dump = $trap->add(Trap::dump('first'));
        $trap->add(Trap::log('second'));

        $result = $trap->get(ListEvents::class)->call([]);

        Assert::same($result['events'][0], [
            'id' => $dump->event->uuid,
            'type' => 'var-dump',
            'project' => 'default',
            'time' => '2023-11-14T22:13:20.500+00:00',
            'summary' => 'first',
            'fields' => ['value_type' => 'string'],
        ]);
        Assert::same(self::summaries($result), ['first', 'ERROR app: second']);
        Assert::same($result['cursor'], $trap->store->id . ':2');
        Assert::false($result['has_more']);
    }

    public function testCursorGivesOnlyNewEvents(): void
    {
        $trap = new Trap();
        $tool = $trap->get(ListEvents::class);
        $trap->add(Trap::dump('before'));
        $cursor = $tool->call([])['cursor'];
        $trap->add(Trap::dump('after'));

        $result = $tool->call(['since' => $cursor]);

        Assert::same(self::summaries($result), ['after']);
        Assert::same($tool->call(['since' => $result['cursor']])['events'], []);
    }

    public function testUpdatedEventIsListedAgain(): void
    {
        $trap = new Trap();
        $tool = $trap->get(ListEvents::class);
        $record = $trap->add(Trap::dump('once'));
        $trap->add(Trap::dump('other'));
        $cursor = $tool->call([])['cursor'];

        $trap->store->add($record->event);

        Assert::same(self::summaries($tool->call(['since' => $cursor])), ['once']);
    }

    #[DataSet(['0123abcd:1'], 'of another process')]
    #[DataSet([''], 'empty')]
    #[DataSet(['garbage'], 'no sequence number')]
    #[DataSet([':1'], 'no process')]
    public function testForeignCursorListsEverything(string $since): void
    {
        $trap = new Trap();
        $trap->add(Trap::dump('kept'));

        $result = $trap->get(ListEvents::class)->call(['since' => $since]);

        Assert::same(self::summaries($result), ['kept']);
        Assert::same($result['cursor'], $trap->store->id . ':1');
    }

    public function testCursorOfEarlierProcessListsTheFirstEventsOfTheNewOne(): void
    {
        $earlier = new Trap();
        foreach (['a', 'b', 'c'] as $value) {
            $earlier->add(Trap::dump($value));
        }
        $cursor = $earlier->get(ListEvents::class)->call([])['cursor'];
        $restarted = new Trap();
        foreach (['x', 'y', 'z', 'w'] as $value) {
            $restarted->add(Trap::dump($value));
        }

        $result = $restarted->get(ListEvents::class)->call(['since' => $cursor]);

        Assert::notSame($restarted->store->id, $earlier->store->id);
        Assert::same(self::summaries($result), ['x', 'y', 'z', 'w']);
    }

    #[DataSet([':x'], 'not a number')]
    #[DataSet([':-1'], 'negative')]
    #[DataSet([':100'], 'ahead of the store')]
    public function testInvalidCursorOfThisProcessListsEverything(string $seq): void
    {
        $trap = new Trap();
        $trap->add(Trap::dump('kept'));

        $result = $trap->get(ListEvents::class)->call(['since' => $trap->store->id . $seq]);

        Assert::same(self::summaries($result), ['kept']);
    }

    public function testEmptyStoreCursorIsZeroOfThisProcess(): void
    {
        $trap = new Trap();

        Assert::same($trap->get(ListEvents::class)->call([])['cursor'], $trap->store->id . ':0');
    }

    public function testLimitLeavesTheRestForTheNextCall(): void
    {
        $trap = new Trap();
        $tool = $trap->get(ListEvents::class);
        foreach (['a', 'b', 'c'] as $value) {
            $trap->add(Trap::dump($value));
        }

        $first = $tool->call(['limit' => 2]);
        $second = $tool->call(['since' => $first['cursor'], 'limit' => 2]);

        Assert::same(self::summaries($first), ['a', 'b']);
        Assert::true($first['has_more']);
        Assert::same($first['cursor'], $trap->store->id . ':2');
        Assert::same(self::summaries($second), ['c']);
        Assert::false($second['has_more']);
        Assert::same($second['cursor'], $trap->store->id . ':3');
    }

    public function testFilteredOutEventsMoveTheCursor(): void
    {
        $trap = new Trap();
        $trap->add(Trap::dump('dump'));
        $trap->add(Trap::log('log'));

        $result = $trap->get(ListEvents::class)->call(['type' => 'var-dump']);

        Assert::same(self::summaries($result), ['dump']);
        Assert::same($result['cursor'], $trap->store->id . ':2');
    }

    #[DataSet([['type' => 'monolog'], ['ERROR app: log']], 'one type')]
    #[DataSet([['type' => ['monolog', 'var-dump']], ['dump', 'ERROR app: log', 'shop dump']], 'several types')]
    #[DataSet([['project' => 'shop'], ['shop dump']], 'project')]
    #[DataSet([['project' => 'default', 'type' => 'var-dump'], ['dump']], 'default project and type')]
    #[DataSet([['project' => ''], ['dump', 'ERROR app: log']], 'empty project is the default one')]
    #[DataSet([['project' => 'none'], []], 'unknown project')]
    public function testFilters(array $arguments, array $expected): void
    {
        $trap = new Trap();
        $trap->add(Trap::dump('dump'));
        $trap->add(Trap::log('log'));
        $trap->add(Trap::dump('shop dump', ['project' => 'shop']));

        Assert::same(self::summaries($trap->get(ListEvents::class)->call($arguments)), $expected);
    }

    #[DataSet([['type' => 'nope'], 'Unknown event type `nope`; known types: '], 'unknown type')]
    #[DataSet([['type' => [1]], 'Unknown event type int'], 'type of a wrong kind')]
    #[DataSet([['type' => ['a' => 'monolog']], '`type` must be a string or a list of strings.'], 'type map')]
    #[DataSet([['project' => 1], '`project` must be a string or a list of strings.'], 'project')]
    #[DataSet([['order' => 'random'], '`order` must be one of: oldest, newest.'], 'order')]
    #[DataSet([['include' => 'all'], '`include` must be one of: summary, text.'], 'include')]
    #[DataSet([['max_length' => 99], '`max_length` must be an integer from 100 to'], 'max_length below minimum')]
    #[DataSet([['where' => ['level' => null]], '`where.level` must be'], 'where')]
    #[DataSet([['since' => 1], '`since` must be a string.'], 'cursor integer')]
    #[DataSet([['limit' => 0], '`limit` must be an integer from 1 to 200.'], 'zero limit')]
    #[DataSet([['limit' => 201], '`limit` must be an integer from 1 to 200.'], 'limit above maximum')]
    #[DataSet([['limit' => 1.5], '`limit` must be an integer from 1 to 200.'], 'fractional limit')]
    public function testInvalidArguments(array $arguments, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        (new Trap())->get(ListEvents::class)->call($arguments);
    }

    public function testWholeFloatIsAnInteger(): void
    {
        $trap = new Trap();
        $trap->add(Trap::dump('a'));
        $trap->add(Trap::dump('b'));

        Assert::same(self::summaries($trap->get(ListEvents::class)->call(['limit' => 1.0])), ['a']);
    }

    public function testSchemaListsKnownTypes(): void
    {
        $schema = (new Trap())->get(ListEvents::class)->inputSchema();

        Assert::same($schema['properties']['type']['anyOf'][0]['enum'], [
            'var-dump',
            'smtp',
            'monolog',
            'ray',
            'sentry',
            'profiler',
            'http-dump',
        ]);
        Assert::same($schema['properties']['limit']['maximum'], ListEvents::MAX_LIMIT);
        Assert::false($schema['additionalProperties']);
    }

    #[DataSet([[]], 'some events')]
    #[DataSet([['type' => 'smtp']], 'no events')]
    #[DataSet([['limit' => 1]], 'more to come')]
    #[DataSet([['order' => 'newest', 'limit' => 1]], 'newest')]
    #[DataSet([['include' => 'text', 'max_length' => 100]], 'texts')]
    #[DataSet([['where' => ['level' => 'error'], 'search' => 'second']], 'filtered')]
    public function testResultConformsToOutputSchema(array $arguments): void
    {
        $trap = new Trap();
        $tool = $trap->get(ListEvents::class);
        $trap->add(Trap::dump('first'));
        $trap->add(Trap::log('second'));

        $result = $tool->call($arguments);

        Assert::same(JsonSchema::errors($result, $tool->outputSchema()), []);
    }

    public function testNewestGivesTheLatestMatchesFirst(): void
    {
        $trap = new Trap();
        $tool = $trap->get(ListEvents::class);
        foreach (['a', 'b', 'c', 'd'] as $value) {
            $trap->add(Trap::dump($value));
        }
        $trap->add(Trap::log('log'));

        $result = $tool->call(['order' => 'newest', 'type' => 'var-dump', 'limit' => 2]);

        Assert::same(self::summaries($result), ['d', 'c']);
        Assert::true($result['has_more']);
        Assert::same($result['cursor'], $trap->store->id . ':5');
    }

    public function testNewestCursorGivesOnlyLaterEvents(): void
    {
        $trap = new Trap();
        $tool = $trap->get(ListEvents::class);
        $trap->add(Trap::dump('before'));
        $cursor = $tool->call(['order' => 'newest'])['cursor'];
        $trap->add(Trap::dump('one'));
        $trap->add(Trap::dump('two'));

        $result = $tool->call(['since' => $cursor, 'order' => 'newest']);

        Assert::same(self::summaries($result), ['two', 'one']);
        Assert::false($result['has_more']);
        Assert::same($tool->call(['since' => $result['cursor'], 'order' => 'newest'])['events'], []);
    }

    public function testFilteredOutEventsMoveTheCursorOfTheSearch(): void
    {
        $trap = new Trap();
        $tool = $trap->get(ListEvents::class);
        $trap->add(Trap::dump('wanted'));
        $trap->add(Trap::dump('other'));

        $result = $tool->call(['search' => 'WANTED']);

        Assert::same(self::summaries($result), ['wanted']);
        Assert::same($result['cursor'], $trap->store->id . ':2');
    }

    public function testSearchFindsTheTextOfTheEvent(): void
    {
        $trap = new Trap();
        $trap->add(Trap::dump(['order_id' => 42]));
        $trap->add(Trap::dump('unrelated'));

        $result = $trap->get(ListEvents::class)->call(['search' => '"order_id" => 42']);

        Assert::count($result['events'], 1);
        Assert::string($result['events'][0]['summary'])->contains('order_id');
    }

    public function testIncludeTextAddsTheTextOfEachEvent(): void
    {
        $trap = new Trap();
        $trap->add(Trap::dump('short'));
        $trap->add(Trap::dump(\str_repeat('long ', 100)));

        $events = $trap->get(ListEvents::class)->call(['include' => 'text', 'max_length' => 100])['events'];

        Assert::string($events[0]['text'])->contains('"short"')->notContains('[Truncated');
        Assert::string($events[1]['text'])->contains('[Truncated: 100 of ');
    }

    public function testSummaryAloneByDefault(): void
    {
        $trap = new Trap();
        $trap->add(Trap::dump('short'));

        $event = $trap->get(ListEvents::class)->call([])['events'][0];

        Assert::same(\array_keys($event), ['id', 'type', 'project', 'time', 'summary', 'fields']);
    }

    public function testEventWithoutFieldsHasNoFieldsKey(): void
    {
        $trap = new Trap();
        $trap->store->add(new Event('5a4f8e1c-0000-4000-8000-000000000001', 'monolog', ['message' => 'bare'], 1.0));

        $events = $trap->get(ListEvents::class)->call([])['events'];

        Assert::same(\array_keys($events[0]), ['id', 'type', 'project', 'time', 'summary']);
    }

    public function testWhereFiltersByTheFields(): void
    {
        $trap = new Trap();
        $trap->add(Trap::log('failed'));
        $trap->add(Trap::log('done', 'INFO'));

        $result = $trap->get(ListEvents::class)->call(['where' => ['level' => 'info']]);

        Assert::same(self::summaries($result), ['INFO app: done']);
        Assert::same($result['events'][0]['fields'], ['level' => 'INFO', 'channel' => 'app']);
    }

    public function testIsReadOnly(): void
    {
        $tool = (new Trap())->get(ListEvents::class);

        Assert::same($tool->name(), 'list_events');
        Assert::same($tool->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
        Assert::string($tool->description())->contains('get_event')->contains('monolog: level, channel');
    }

    /**
     * @return list<string> Summaries of the listed events.
     */
    private static function summaries(array $result): array
    {
        return \array_column($result['events'], 'summary');
    }
}

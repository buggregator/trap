<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\CollectMemo;
use Buggregator\Trap\Module\Mcp\Tool\EventFilter;
use Buggregator\Trap\Module\Mcp\Tool\EventFilterArguments;
use Buggregator\Trap\Module\Mcp\Tool\ListEvents;
use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Module\Mcp\View\TextCache;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\JsonSchema;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\ScriptedView;
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
    #[DataSet([['type' => [1]], '`type` must be a string or a non-empty list of strings.'], 'type of a wrong kind')]
    #[DataSet([['type' => ['a' => 'monolog']], '`type` must be a string or a non-empty list of strings.'], 'type map')]
    #[DataSet([['project' => 1], '`project` must be a string or a non-empty list of strings.'], 'project')]
    #[DataSet([['type' => []], '`type` must be a string or a non-empty list of strings.'], 'no types')]
    #[DataSet([['where' => []], '`where` must be an object of at least one field'], 'no fields')]
    #[DataSet([['regex' => true], '`regex` needs `search`'], 'regex without search')]
    #[DataSet([['order' => 'random'], '`order` must be one of: oldest, newest.'], 'order')]
    #[DataSet([['include' => 'all'], '`include` must be one of: summary, text.'], 'include')]
    #[DataSet([['max_length' => 99], '`max_length` must be an integer from 100 to'], 'max_length below minimum')]
    #[DataSet([['max_length' => 50_001], '`max_length` must be an integer from 100 to 50000.'], 'max_length above')]
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
            'xdebug-trace',
            'html',
            'csp',
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

    public function testIncludeTextCutsTheCachedText(): void
    {
        $trap = Trap::scripted();
        $trap->store->add(ScriptedView::event('long', \str_repeat('x', 200_000)));
        $tool = $trap->get(ListEvents::class);

        $first = $tool->call(['include' => 'text'])['events'][0]['text'];
        $second = $tool->call(['include' => 'text', 'max_length' => 3_000])['events'][0]['text'];

        Assert::same(
            $first,
            \str_repeat('x', 2_000) . "\n\n[Truncated: 2000 of 200000 characters shown. Call again with a larger "
                . '`max_length` for more.]',
        );
        Assert::string($second)->startsWith(\str_repeat('x', 3_000) . "\n\n[Truncated: 3000 of 200000 characters");
        Assert::same($trap->view()->renders, 1);
    }

    public function testIncludeTextLongerThanTheCachedStartIsRendered(): void
    {
        $trap = Trap::scripted();
        // Two bytes a letter: the cached start holds fewer letters than the largest `max_length`
        $trap->store->add(ScriptedView::event('long', \str_repeat('ж', 200_000)));
        $tool = $trap->get(ListEvents::class);
        $tool->call(['include' => 'text']);

        $text = $tool->call(['include' => 'text', 'max_length' => 50_000])['events'][0]['text'];

        Assert::string($text)->startsWith(\str_repeat('ж', 50_000) . "\n\n[Truncated: 50000 of 200000 characters");
        Assert::same($trap->view()->renders, 2);
    }

    public function testIncludeTextStopsAtTheLimitOfTheResponse(): void
    {
        [$trap, $tool] = self::longTexts(12);

        $arguments = ['include' => 'text', 'max_length' => ListEvents::MAX_TEXT_LENGTH];
        $first = $tool->call($arguments);
        $second = $tool->call($arguments + ['since' => $first['cursor']]);

        Assert::same(self::ids($first), ['1', '2', '3', '4', '5', '6', '7', '8', '9']);
        Assert::true($first['has_more']);
        Assert::same($first['cursor'], $trap->store->id . ':9');
        Assert::int(self::textLength($first))->lessThanOrEqual(ListEvents::MAX_TOTAL_TEXT_LENGTH);
        Assert::same(self::ids($second), ['10', '11', '12']);
        Assert::false($second['has_more']);
        Assert::same($second['cursor'], $trap->store->id . ':12');
    }

    public function testNewestIncludeTextStopsAtTheLimitOfTheResponse(): void
    {
        [$trap, $tool] = self::longTexts(12);

        $result = $tool->call(['include' => 'text', 'max_length' => ListEvents::MAX_TEXT_LENGTH, 'order' => 'newest']);

        Assert::same(self::ids($result), ['12', '11', '10', '9', '8', '7', '6', '5', '4']);
        Assert::true($result['has_more']);
        Assert::same($result['cursor'], $trap->store->id . ':12');
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

    #[DataSet(['from'], 'from')]
    #[DataSet(['to'], 'to')]
    public function testListedTimeTakesItsEventBack(string $bound): void
    {
        $trap = new Trap();
        $trap->store->add(new Event('5a4f8e1c-0000-4000-8000-000000000001', 'monolog', ['message' => 'before'], 1_763_287_200.7369));
        $trap->store->add(new Event('5a4f8e1c-0000-4000-8000-000000000002', 'monolog', ['message' => 'listed'], 1_763_287_200.7374));
        $trap->store->add(new Event('5a4f8e1c-0000-4000-8000-000000000003', 'monolog', ['message' => 'after'], 1_763_287_200.738));
        $tool = $trap->get(ListEvents::class);
        $time = $tool->call(['search' => 'listed'])['events'][0]['time'];

        $result = $tool->call([$bound => $time]);

        Assert::same($time, '2025-11-16T10:00:00.737+00:00');
        Assert::same(self::summaries($result), $bound === 'from' ? ['listed', 'after'] : ['before', 'listed']);
    }

    public function testDateTakesTheWholeDay(): void
    {
        $trap = new Trap();
        foreach ([1_763_164_799.9999, 1_763_164_800.0, 1_763_251_199.9999, 1_763_251_200.0] as $i => $timestamp) {
            $trap->store->add(new Event("5a4f8e1c-0000-4000-8000-00000000000$i", 'monolog', ['message' => "e$i"], $timestamp));
        }

        $result = $trap->get(ListEvents::class)->call(['from' => '2025-11-15', 'to' => '2025-11-15']);

        Assert::same(self::summaries($result), ['e1', 'e2']);
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
        Assert::same($tool->title(), 'List events');
        Assert::same($tool->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
        Assert::string($tool->description())->contains('get_event')->contains('monolog: level, channel')->contains('list_traces')->contains('list_feedback')->contains('list_crons');
    }

    public function testCollectWithAMemoDescribesAnUnchangedEventOnce(): void
    {
        $trap = Trap::scripted();
        // No text is kept: each description of a record renders it
        $trap->container->set(new TextCache($trap->get(EventViews::class), $trap->store, maxBytes: 0));
        $trap->store->add(ScriptedView::event('1', 'first', 1.0));
        $trap->store->add(ScriptedView::event('2', 'second', 2.0));
        $tool = $trap->get(ListEvents::class);
        $filter = $trap->get(EventFilterArguments::class)->parse([]);
        $memo = new CollectMemo();

        $tool->collect(0, $filter, 10, 100, memo: $memo);
        $first = $trap->view()->renders;
        $trap->store->add(ScriptedView::event('3', 'third', 3.0));
        $page = $tool->collect(0, $filter, 10, 100, memo: $memo);

        Assert::same($first, 2);
        Assert::same($trap->view()->renders, 3, 'Only the new event is rendered.');
        Assert::same(\array_column($page['events'], 'text'), ['first', 'second', 'third']);
        Assert::same($page['events'], $tool->collect(0, $filter, 10, 100)['events']);
    }

    /**
     * @param positive-int $count
     *
     * @return array{Trap, ListEvents} Events with their numbers from 1 as IDs, with texts longer than
     *         {@see ListEvents::MAX_TEXT_LENGTH}: nine of them cut fill the response.
     */
    private static function longTexts(int $count): array
    {
        $trap = Trap::scripted();
        for ($i = 1; $i <= $count; ++$i) {
            $trap->store->add(ScriptedView::event((string) $i, \str_repeat('x', 60_000), (float) $i));
        }

        return [$trap, $trap->get(ListEvents::class)];
    }

    private static function textLength(array $result): int
    {
        return \array_sum(\array_map(\mb_strlen(...), \array_column($result['events'], 'text')));
    }

    /**
     * @return list<string> Summaries of the listed events.
     */
    private static function summaries(array $result): array
    {
        return \array_column($result['events'], 'summary');
    }

    /**
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        return \array_column($result['events'], 'id');
    }
}

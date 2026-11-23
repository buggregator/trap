<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\EventQuery;
use Buggregator\Trap\Server\Event\Event;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(EventQuery::class)]
#[Test]
final class EventQueryTest
{
    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    public function testReadsRepeatedNegatedAndTagFilters(): void
    {
        $query = EventQuery::fromQueryString(
            'type=monolog&project=shop&level=ERROR&level=warning&not.channel=doctrine&tag=feature%3Acheckout'
            . '&q=Order+Failed&limit=5&offset=2&foo=bar&tag=broken',
        );

        Assert::same($query->type, 'monolog');
        Assert::same($query->project, 'shop');
        Assert::same($query->filters, [
            ['key' => 'level', 'values' => ['error', 'warning'], 'not' => false],
            ['key' => 'channel', 'values' => ['doctrine'], 'not' => true],
            ['key' => 'tag:feature', 'values' => ['checkout'], 'not' => false],
        ]);
        Assert::same($query->words, ['order', 'failed']);
        Assert::same($query->limit, 5);
        Assert::same($query->offset, 2);
    }

    public function testWindowOfQuerySetsSinceUpToHundredYears(): void
    {
        Assert::true(\abs(EventQuery::fromQueryString('window=3h')->since - (\microtime(true) - 10800)) < 5);
        Assert::null(EventQuery::fromQueryString('window=all')->since);

        $since = EventQuery::fromQueryString('window=99999999999999999999d')->since;
        Assert::true(\abs($since - (\microtime(true) - 100 * 366 * 86400)) < 5);
    }

    public function testHistogramOfHugeWindowKeepsAnIntegerBucket(): void
    {
        $histogram = EventQuery::fromQueryString('window=99999999999999999999d')
            ->histogram([new Event('a', 'monolog', [], \microtime(true))], 60);

        Assert::true(\is_int($histogram['bucket']));
        Assert::true($histogram['bucket'] <= 100 * 366 * 86400);
        Assert::count($histogram['data'], 1);
    }

    public function testSearchFoldsTheCaseOfAnyLetter(): void
    {
        $events = [new Event('a', 'monolog', ['message' => 'Ошибка оплаты', 'channel' => 'app'], 1.0)];

        Assert::same(self::uuids(EventQuery::fromQueryString('q=' . \urlencode('ОШИБКА'))->select($events)), ['a']);
    }

    public function testWindowCountsFromNow(): void
    {
        Assert::null(EventQuery::since(null));
        Assert::null(EventQuery::since('all'));
        Assert::true(\abs(EventQuery::since('15m') - (\microtime(true) - 900)) < 5);
        Assert::true(\abs(EventQuery::since('7d') - (\microtime(true) - 604800)) < 5);
    }

    public function testSelectsByTypeFiltersAndSearch(): void
    {
        $events = self::events();

        Assert::same(self::uuids(EventQuery::fromQueryString('type=monolog')->select($events)), ['m3', 'm2', 'm1']);
        Assert::same(self::uuids(EventQuery::fromQueryString('level=error')->select($events)), ['m3', 's1']);
        Assert::same(self::uuids(EventQuery::fromQueryString('type=monolog&not.channel=app')->select($events)), ['m2']);
        Assert::same(self::uuids(EventQuery::fromQueryString('q=PAYMENT+42')->select($events)), ['m3']);
        Assert::same(self::uuids(EventQuery::fromQueryString('q=user_id')->select($events)), ['m1']);
        Assert::same(self::uuids(EventQuery::fromQueryString('trace=' . self::TRACE)->select($events)), ['m3', 'h1']);
        Assert::same(self::uuids(EventQuery::fromQueryString('type=monolog&offset=1&limit=1')->select($events)), ['m2']);
    }

    public function testFacetCountsLeaveOutTheirOwnFilter(): void
    {
        $counts = EventQuery::fromQueryString('type=monolog&level=error')->facetCounts(self::events());

        Assert::same($counts['level'], [
            ['value' => 'debug', 'count' => 1],
            ['value' => 'error', 'count' => 1],
            ['value' => 'info', 'count' => 1],
        ]);
        Assert::same($counts['channel'], [['value' => 'app', 'count' => 1]]);
    }

    public function testFacetCountsUnderExclusionLeaveOutOnlyTheExcludedKey(): void
    {
        $counts = EventQuery::fromQueryString('type=monolog&not.channel=doctrine')->facetCounts(self::events());

        Assert::same($counts['channel'], [['value' => 'app', 'count' => 2], ['value' => 'doctrine', 'count' => 1]]);
        Assert::same($counts['level'], [['value' => 'error', 'count' => 1], ['value' => 'info', 'count' => 1]]);
    }

    public function testFacetCountsKeepNumericValuesAsStrings(): void
    {
        $counts = EventQuery::fromQueryString('')->facetCounts([
            new Event('a', 'sentry', [], 1.0, facets: ['release' => ['42']]),
            new Event('b', 'sentry', [], 2.0, facets: ['release' => ['42', '7']]),
        ]);

        Assert::same($counts, ['release' => [['value' => '42', 'count' => 2], ['value' => '7', 'count' => 1]]]);
    }

    public function testHistogramCountsLevelsPerBucket(): void
    {
        $histogram = EventQuery::fromQueryString('type=monolog')->histogram(self::events(), 60);

        Assert::same($histogram['bucket'], 60);
        Assert::same(\json_decode(\json_encode($histogram['data']), true), [
            ['t' => 1200, 'count' => 2, 'levels' => ['debug' => 1, 'info' => 1]],
            ['t' => 1320, 'count' => 1, 'levels' => ['error' => 1]],
        ]);
    }

    public function testHistogramWidensBucketsOfLongSpan(): void
    {
        $events = [
            new Event('b', 'monolog', [], 1_000_000.0),
            new Event('a', 'monolog', [], 0.0),
        ];

        Assert::same(EventQuery::fromQueryString('')->histogram($events, 60)['bucket'], 720);
    }

    /**
     * @return list<Event> The newest first.
     */
    private static function events(): array
    {
        return [
            new Event('m3', 'monolog', [
                'message' => 'Payment failed for order 42',
                'channel' => 'app',
            ], 1330.0, facets: ['channel' => ['app'], 'level' => ['error'], 'trace' => [self::TRACE]]),
            new Event('s1', 'sentry', ['message' => 'Payment'], 1300.0, facets: ['level' => ['error']]),
            new Event('h1', 'http-dump', [], 1250.0, facets: ['trace' => [self::TRACE]]),
            new Event('m2', 'monolog', ['message' => 'SELECT', 'channel' => 'doctrine'], 1220.0, facets: [
                'channel' => ['doctrine'],
                'level' => ['debug'],
            ]),
            new Event('m1', 'monolog', [
                'message' => 'Logged in',
                'channel' => 'app',
                'context' => ['user' => ['user_id' => 7]],
            ], 1210.0, facets: ['channel' => ['app'], 'level' => ['info']]),
        ];
    }

    /**
     * @param list<Event> $events
     * @return list<string>
     */
    private static function uuids(array $events): array
    {
        return \array_map(static fn(Event $event): string => $event->uuid, $events);
    }
}

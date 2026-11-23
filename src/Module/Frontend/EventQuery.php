<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Facets;

/**
 * The events a list of the UI asks for, by the query parameters of buggregator/server:
 * `type`, `project`, `window`, `q`, `limit`, `offset`, and the feed filters as `host=web-1`,
 * `not.level=debug`, `tag=feature:checkout`. Values of one filter key are OR-ed, keys AND-ed.
 *
 * @internal
 */
final class EventQuery
{
    /** At most so many values of one key in the facet counts. */
    private const FACET_VALUES = 50;

    /** A wider histogram gets wider buckets. */
    private const MAX_BUCKETS = 1440;

    public const MAX_BUCKET = 366 * 24 * 3600;

    /** The longest window and widened bucket, 100 years: longer ones would overflow the bucket arithmetic. */
    private const MAX_SPAN = 100 * 366 * 24 * 3600;

    /**
     * @param float|null $since Events since this Unix time; all when null.
     * @param list<array{key: non-empty-string, values: list<string>, not: bool}> $filters
     * @param list<non-empty-string> $words Words the search text of an event has all of, in lower case.
     * @param int<0, max> $limit The number of events; all when 0.
     * @param int<0, max> $offset
     */
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $project = null,
        public readonly ?float $since = null,
        public readonly array $filters = [],
        public readonly array $words = [],
        public readonly int $limit = 0,
        public readonly int $offset = 0,
    ) {}

    /**
     * @param string $query A raw query string: PHP's own parsing keeps one value of a repeated key
     *        and turns the dot of `not.level` into an underscore.
     */
    public static function fromQueryString(string $query): self
    {
        $params = self::parse($query);
        $one = static fn(string $key): string => ($params[$key] ?? [])[0] ?? '';

        /** @var array<non-empty-string, array{key: non-empty-string, values: list<string>, not: bool}> $filters */
        $filters = [];
        foreach ($params as $name => $values) {
            $not = \str_starts_with($name, 'not.');
            $key = $not ? \substr($name, 4) : $name;
            foreach ($values as $value) {
                if ($key === 'tag') {
                    [$tag, $value] = \explode(':', $value, 2) + [1 => null];
                    $tag = \trim($tag);
                    if ($value === null || $tag === '') {
                        continue;
                    }
                    $filterKey = Facets::TAG_PREFIX . $tag;
                } else {
                    $filterKey = $key;
                }

                $value = Facets::normalize($filterKey, $value);
                if ($value === '' || !Facets::isKey($filterKey)) {
                    continue;
                }

                $id = ($not ? '-' : '+') . $filterKey;
                $filters[$id] ??= ['key' => $filterKey, 'values' => [], 'not' => $not];
                $filters[$id]['values'][] = $value;
            }
        }

        $type = $one('type');
        $project = $one('project');

        return new self(
            type: $type === '' ? null : $type,
            project: $project === '' ? null : $project,
            since: self::since($one('window')),
            filters: \array_values($filters),
            words: self::words($one('q')),
            limit: \max(0, (int) $one('limit')),
            offset: \max(0, (int) $one('offset')),
        );
    }

    /**
     * @param string|null $window A period till now as `15m`, `1h` or `7d`; all the time when null, `all`
     *        or not such a period.
     */
    public static function since(?string $window): ?float
    {
        if ($window === null || \preg_match('/^(\d+)([mhd])$/', $window, $m) !== 1) {
            return null;
        }

        $seconds = (float) $m[1] * (float) ['m' => 60, 'h' => 3600, 'd' => 86400][$m[2]];
        if ($seconds <= 0.0) {
            // `0d` is no window, as in buggregator/server: else nothing would be in it
            return null;
        }

        return \microtime(true) - \min($seconds, (float) self::MAX_SPAN);
    }

    /**
     * @param iterable<Event> $events From the newest to the oldest one.
     * @return list<Event> The events of the query, in the same order, from the offset and up to the limit.
     */
    public function select(iterable $events): array
    {
        $result = [];
        $skip = $this->offset;
        foreach ($events as $event) {
            if ($this->since !== null && $event->timestamp < $this->since) {
                break;
            }

            if (!$this->matches($event)) {
                continue;
            }

            if ($skip > 0) {
                --$skip;
                continue;
            }

            $result[] = $event;
            if ($this->limit > 0 && \count($result) >= $this->limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param non-empty-string|null $skipKey A filter key to leave out.
     */
    public function matches(Event $event, ?string $skipKey = null): bool
    {
        if ($this->type !== null && $event->type !== $this->type
            || $this->project !== null && $event->projectKey() !== $this->project
            || $this->since !== null && $event->timestamp < $this->since
        ) {
            return false;
        }

        foreach ($this->filters as $filter) {
            if ($filter['key'] === $skipKey) {
                continue;
            }

            $values = \array_map(
                static fn(string $value): string => Facets::normalize($filter['key'], $value),
                $event->facets[$filter['key']] ?? [],
            );
            $hit = \array_intersect($filter['values'], $values) !== [];
            if ($hit === $filter['not']) {
                return false;
            }
        }

        if ($this->words === []) {
            return true;
        }

        $text = self::searchText($event);
        foreach ($this->words as $word) {
            if (!\str_contains($text, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Values of each filter key with the number of the events of the query having them, the most frequent first.
     * The counts of a key leave out its own filter: picking one host still shows the others.
     *
     * @param iterable<Event> $events
     * @return array<non-empty-string, list<array{value: non-empty-string, count: positive-int}>>
     */
    public function facetCounts(iterable $events): array
    {
        // Counted under `:value`: PHP makes a numeric string key, as a release `42`, an integer one
        /** @var array<non-empty-string, array<non-empty-string, positive-int>> $counts */
        $counts = [];
        foreach ($events as $event) {
            foreach ($event->facets as $key => $values) {
                if (!$this->matches($event, $key)) {
                    continue;
                }
                foreach ($values as $value) {
                    $counts[$key][":{$value}"] = ($counts[$key][":{$value}"] ?? 0) + 1;
                }
            }
        }

        $result = [];
        foreach ($counts as $key => $values) {
            $list = [];
            foreach ($values as $value => $count) {
                $list[] = ['value' => \substr($value, 1), 'count' => $count];
            }
            \usort($list, static fn(array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);
            /** @var list<array{value: non-empty-string, count: positive-int}> */
            $result[$key] = \array_slice($list, 0, self::FACET_VALUES);
        }
        \ksort($result);

        return $result;
    }

    /**
     * The events of the query per time bucket, with their counts by level. Empty buckets are left out.
     *
     * @param iterable<Event> $events
     * @param int<1, max> $bucket Bucket width in seconds; wider when the events span more than 1440 of them.
     * @return array{bucket: int, data: list<array{t: int, count: int, levels: object}>}
     */
    public function histogram(iterable $events, int $bucket): array
    {
        $selected = [];
        foreach ($events as $event) {
            $this->matches($event) and $selected[] = $event;
        }

        if ($selected !== []) {
            $times = \array_map(static fn(Event $event): float => $event->timestamp, $selected);
            $span = \max($times) - ($this->since ?? \min($times));
            $width = (float) ($bucket * self::MAX_BUCKETS);
            $span > $width and $bucket = (int) \min(\ceil($span / $width) * (float) $bucket, (float) self::MAX_SPAN);
        }

        /** @var array<int, array{t: int, count: int, levels: array<string, int>}> $buckets */
        $buckets = [];
        foreach ($selected as $event) {
            $t = (int) \floor($event->timestamp / (float) $bucket) * $bucket;
            $buckets[$t] ??= ['t' => $t, 'count' => 0, 'levels' => []];
            ++$buckets[$t]['count'];
            $level = $event->facets[Facets::LEVEL][0] ?? null;
            $level === null or $buckets[$t]['levels'][$level] = ($buckets[$t]['levels'][$level] ?? 0) + 1;
        }
        \ksort($buckets);

        return [
            'bucket' => \max(1, $bucket),
            'data' => \array_values(\array_map(
                static fn(array $b): array => ['t' => $b['t'], 'count' => $b['count'], 'levels' => (object) $b['levels']],
                $buckets,
            )),
        ];
    }

    /**
     * @return array<non-empty-string, list<string>>
     */
    private static function parse(string $query): array
    {
        $params = [];
        foreach (\explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = \explode('=', $pair, 2) + [1 => ''];
            $name = \urldecode($name);
            // Not a filter: a numeric key would turn into an integer one
            \is_numeric($name) || $name === '' or $params[$name][] = \urldecode($value);
        }

        /** @var array<non-empty-string, list<string>> */
        return $params;
    }

    /**
     * @return list<non-empty-string>
     */
    private static function words(string $search): array
    {
        /** @var list<non-empty-string>|false $words */
        $words = \preg_split('/\s+/u', self::lower(\trim($search)), -1, \PREG_SPLIT_NO_EMPTY);
        return \array_values(\array_unique($words === false ? [] : $words));
    }

    /**
     * The UI folds the case of any letter: `Ошибка` finds `ошибка` live, and must after a reload too.
     */
    private static function lower(string $text): string
    {
        return \function_exists('mb_strtolower') ? \mb_strtolower($text, 'UTF-8') : \strtolower($text);
    }

    /**
     * What the search finds an event by: the `message` and `channel` of its payload and the keys and values of
     * its `context` and `extra`, as a Monolog record has them. A Sentry event has a `message` and `extra` too;
     * an event without these fields matches no search.
     */
    private static function searchText(Event $event): string
    {
        $payload = $event->payload;
        if (!\is_array($payload)) {
            return '';
        }

        $parts = [];
        foreach (['message', 'channel'] as $key) {
            \is_scalar($payload[$key] ?? null) and $parts[] = (string) $payload[$key];
        }
        self::flatten([$payload['context'] ?? null, $payload['extra'] ?? null], $parts);

        return self::lower(\implode("\n", $parts));
    }

    /**
     * @param list<string> $parts
     * @param-out list<string> $parts
     */
    private static function flatten(mixed $value, array &$parts, int $depth = 0): void
    {
        if (\is_scalar($value)) {
            $parts[] = (string) $value;
            return;
        }

        if (!\is_array($value) || $depth > 8) {
            return;
        }

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            \is_string($key) and $parts[] = $key;
            self::flatten($item, $parts, $depth + 1);
        }
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mailer\Contact;
use Buggregator\Trap\Module\Mcp\Tool\EventFilter;
use Buggregator\Trap\Module\Mcp\Tool\EventFilterArguments;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(EventFilterArguments::class)]
#[Covers(EventFilter::class)]
final class EventFilterArgumentsTest
{
    /** Unix time the durations count back from: two seconds after the last event. */
    private const NOW = 8.0;

    /**
     * The events of {@see self::records()} by name, caught a second apart from `error` at 1.
     */
    #[DataSet([[], ['error', 'info', 'request', 'dump', 'mail', 'sentry']], 'no filter')]
    #[DataSet([['from' => '3s'], ['mail', 'sentry']], 'from a duration')]
    #[DataSet([['to' => '1970-01-01T00:00:02Z'], ['error', 'info']], 'to a time')]
    #[DataSet([['from' => '1970-01-01T00:00:02Z', 'to' => '4s'], ['info', 'request', 'dump']], 'between')]
    #[DataSet([['project' => 'shop'], ['dump']], 'project')]
    #[DataSet([['project' => ['shop', 'default']], ['error', 'info', 'request', 'dump', 'mail', 'sentry']], 'projects')]
    #[DataSet([['search' => 'PAYMENT'], ['error']], 'search in the summary')]
    #[DataSet([['search' => 'order_id'], ['request', 'dump']], 'search in the text')]
    #[DataSet([['search' => 'pay\w+ fail', 'regex' => true], ['error']], 'regex')]
    #[DataSet([['search' => 'nothing like it'], []], 'search without matches')]
    #[DataSet([['where' => ['level' => 'error']], ['error', 'sentry']], 'field in any case')]
    #[DataSet([['where' => ['level' => 'err*']], ['error', 'sentry']], 'field wildcard')]
    #[DataSet([['where' => ['level' => ['info', 'warning']]], ['info']], 'any of the values')]
    #[DataSet([['where' => ['level' => 'info', 'channel' => 'app']], []], 'all the fields')]
    #[DataSet([['where' => ['method' => 'POST', 'path' => '/api/orders*']], ['request']], 'request fields')]
    #[DataSet([['where' => ['to' => 'bob@example.com']], ['mail']], 'any value of a list field')]
    #[DataSet([['where' => ['to' => '*@example.org']], []], 'no value of a list field')]
    #[DataSet([['where' => ['exception' => 'PDOException']], ['sentry']], 'exception')]
    #[DataSet([['where' => ['status' => 500]], ['sentry']], 'number')]
    #[DataSet([['where' => ['status' => 500.0]], ['sentry']], 'whole float')]
    #[DataSet([['where' => ['status' => '5*']], ['sentry']], 'number as a string')]
    #[DataSet([['where' => ['status' => 404]], []], 'other number')]
    #[DataSet([['where' => ['level' => 1]], []], 'number against a string')]
    #[DataSet([['where' => ['level' => true]], []], 'boolean against a string')]
    #[DataSet([['where' => ['nope' => 'x']], []], 'unknown field')]
    #[DataSet(
        [['type' => ['monolog', 'sentry'], 'from' => '7s', 'where' => ['level' => 'error'], 'search' => 'pdo'], ['sentry']],
        'all together',
    )]
    public function testMatches(array $arguments, array $expected): void
    {
        $trap = new Trap();
        $records = self::records($trap);
        $filter = $trap->get(EventFilterArguments::class)->parse($arguments, self::NOW);

        $matched = \array_keys(\array_filter($records, $filter->matches(...)));

        Assert::same($matched, $expected);
    }

    public function testNoArgumentsIsEmpty(): void
    {
        Assert::true((new Trap())->get(EventFilterArguments::class)->parse([])->isEmpty());
    }

    #[DataSet([['search' => 'x']], 'search')]
    #[DataSet([['from' => '1m']], 'from')]
    #[DataSet([['to' => '2023-11-14']], 'to')]
    #[DataSet([['type' => 'monolog']], 'type')]
    #[DataSet([['where' => ['level' => 'x']]], 'where')]
    #[DataSet([['project' => 'shop']], 'project')]
    public function testIsNotEmpty(array $arguments): void
    {
        Assert::false((new Trap())->get(EventFilterArguments::class)->parse($arguments)->isEmpty());
    }

    public function testEmptyProjectIsTheDefaultOne(): void
    {
        $filter = (new Trap())->get(EventFilterArguments::class)->parse(['project' => ['', 'default']]);

        Assert::same($filter->projects, ['default']);
    }

    #[DataSet([['where' => ['a', 'b']], '`where` must be an object of at least one field name and value'], 'list')]
    #[DataSet([['where' => 'level=error'], '`where` must be an object of at least one field name and value'], 'string')]
    #[DataSet([['where' => ['level' => []]], '`where.level` must be a string, a number, a boolean or a non-empty list of them.'], 'empty list')]
    #[DataSet([['where' => ['level' => null]], '`where.level` must be a string'], 'null')]
    #[DataSet([['where' => ['level' => ['a' => 'b']]], '`where.level` must be a string'], 'object')]
    #[DataSet([['where' => ['level' => [['error']]]], '`where.level` must be a string'], 'nested list')]
    #[DataSet([['where' => ['' => 'x']], 'A field name in `where` is empty.'], 'empty field name')]
    #[DataSet([['where' => []], '`where` must be an object of at least one field name and value'], 'no fields')]
    #[DataSet([['project' => [1]], '`project` must be a string or a non-empty list of strings.'], 'project')]
    #[DataSet([['project' => []], '`project` must be a string or a non-empty list of strings.'], 'no projects')]
    #[DataSet([['type' => []], '`type` must be a string or a non-empty list of strings.'], 'no types')]
    #[DataSet([['type' => [1]], '`type` must be a string or a non-empty list of strings.'], 'type not a string')]
    #[DataSet([['type' => ['monolog', 'nope']], 'Unknown event type `nope`; known types: '], 'unknown type')]
    #[DataSet([['regex' => true], '`regex` needs `search`, the pattern to match.'], 'regex without search')]
    #[DataSet([['from' => 'yesterday'], '`from` must be ISO 8601'], 'time')]
    #[DataSet([['from' => '1m', 'to' => '1h'], '`from` is later than `to`.'], 'range')]
    #[DataSet([['search' => '(', 'regex' => true], '`search` is not a valid regular expression'], 'regex')]
    public function testInvalidArguments(array $arguments, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        (new Trap())->get(EventFilterArguments::class)->parse($arguments);
    }

    public function testSchemaListsTheFieldsOfTheModules(): void
    {
        $filters = (new Trap())->get(EventFilterArguments::class);

        $schema = $filters->schema();

        Assert::same(\array_keys($schema), ['type', 'project', 'from', 'to', 'search', 'regex', 'where']);
        Assert::string($filters->fieldsHelp())
            ->startsWith('profiler: name, format; var-dump: label, value_type, language, file; monolog: level, channel; ')
            ->contains('smtp: subject, from, to, cc, bcc')
            ->contains('http-dump: method, path, host');
        Assert::string($schema['where']['description'])->contains($filters->fieldsHelp());
    }

    /**
     * @return array<non-empty-string, Record> A second apart from 1 to 6, in the order of the keys.
     */
    private static function records(Trap $trap): array
    {
        $events = [
            'error' => ['monolog', ['message' => 'Payment failed', 'level_name' => 'ERROR', 'channel' => 'app']],
            'info' => ['monolog', ['message' => 'User logged in', 'level_name' => 'INFO', 'channel' => 'audit']],
            'request' => ['http-dump', ['request' => ['method' => 'POST', 'uri' => 'api/orders/42', 'body' => '{"order_id":42}']]],
            'dump' => ['var-dump', ['payload' => ['type' => 'string', 'value' => 'order_id=42']], 'shop'],
            'mail' => ['smtp', [
                'subject' => 'Receipt',
                'to' => [new Contact('Alice', 'alice@example.com'), new Contact('Bob', 'bob@example.com')],
            ]],
            'sentry' => ['sentry', [
                'level' => 'error',
                'exception' => ['values' => [['type' => 'PDOException', 'value' => 'gone away']]],
                'contexts' => ['response' => ['status_code' => 500]],
            ]],
        ];

        $records = [];
        $time = 0.0;
        foreach ($events as $name => $event) {
            $uuid = \sprintf('5a4f8e1c-0000-4000-8000-%012d', \count($records) + 1);
            $records[$name] = $trap->store->add(new Event($uuid, $event[0], $event[1], ++$time, $event[2] ?? null));
        }

        return $records;
    }
}

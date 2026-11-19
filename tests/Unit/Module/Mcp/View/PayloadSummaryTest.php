<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\View;

use Buggregator\Trap\Module\Mailer\Contact;
use Buggregator\Trap\Module\Mcp\View\PayloadSummary;
use Buggregator\Trap\Module\Ray\Frontend\Payload as RayPayload;
use Buggregator\Trap\Module\VarDumper\Frame as VarDumperFrame;
use Buggregator\Trap\Module\VarDumper\Frontend\Mapper as VarDumperMapper;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(PayloadSummary::class)]
final class PayloadSummaryTest
{
    /**
     * @param non-empty-string $type
     */
    public static function event(string $type, array|\JsonSerializable $payload): Event
    {
        return new Event('5a4f8e1c-0000-4000-8000-000000000001', $type, $payload, 1.0);
    }

    public static function provideDumpedValues(): iterable
    {
        yield 'int' => [42, 'n', 'n: 42'];
        yield 'float' => [1.5, 'n', 'n: 1.5'];
        yield 'true' => [true, 'flag', 'flag: true'];
        yield 'false' => [false, 'flag', 'flag: false'];
        yield 'null' => [null, 'nothing', 'nothing: null'];
        yield 'string' => ['hello', 'plain', 'plain: hello'];
        yield 'array' => [['id' => 42], 'order', 'order: array:1 [ "id" => 42 ]'];
        yield 'object' => [(object) ['id' => 42], 'user', 'user: {# +"id": 42 }'];
        yield 'label the dumper wraps in markup' => [42, "<b>a\u{00A0}b</b>", 'a b: 42'];
        yield 'array without a label' => [['id' => 42], null, 'array:1 [ "id" => 42 ]'];
        yield 'float without a label' => [1.5, null, '1.5'];
    }

    #[DataSet(['var-dump', ['payload' => ['type' => 'string', 'value' => 'hello', 'label' => null]], 'hello'], 'string dump')]
    #[DataSet(['var-dump', ['payload' => ['type' => 'int', 'value' => '42', 'label' => 'Answer']], 'Answer: 42'], 'labeled dump')]
    #[DataSet(['var-dump', ['payload' => ['type' => 'boolean', 'value' => true, 'label' => 'flag']], 'flag: true'], 'raw true dump')]
    #[DataSet(['var-dump', ['payload' => ['type' => 'boolean', 'value' => 1, 'label' => 'flag']], 'flag: true'], 'integer true dump')]
    #[DataSet(['var-dump', ['payload' => ['type' => 'boolean', 'value' => false, 'label' => 'flag']], 'flag: false'], 'raw false dump')]
    #[DataSet(
        [
            'var-dump',
            ['payload' => ['type' => 'array', 'value' => "<style>x{}</style><pre>array:1 [<span>&quot;a&quot; =&gt; 1</span>\n]</pre>"]],
            'array:1 ["a" => 1 ]',
        ],
        'HTML dump',
    )]
    #[DataSet(['monolog', ['message' => 'Disk full', 'level_name' => 'ERROR', 'channel' => 'app'], 'ERROR app: Disk full'], 'log')]
    #[DataSet(['http-dump', ['request' => ['method' => 'POST', 'uri' => 'api/orders']], 'POST /api/orders'], 'HTTP request')]
    #[DataSet(['profiler', ['peaks' => []], null], 'type without summary')]
    #[DataSet(['monolog', [], null], 'empty payload')]
    #[DataSet(['var-dump', ['payload' => 'broken'], null], 'dump without a payload structure')]
    #[DataSet(['http-dump', ['request' => 'broken'], null], 'HTTP dump without a request structure')]
    public function testSummary(string $type, array $payload, ?string $expected): void
    {
        Assert::same((new PayloadSummary())->summary(self::event($type, $payload)), $expected);
    }

    /**
     * The event comes from the var-dumper mapper, so the value is rendered as the server renders it.
     */
    #[DataProvider('provideDumpedValues')]
    public function testDumpOfEachType(mixed $value, ?string $label, string $expected): void
    {
        $data = (new VarCloner())->cloneVar($value);
        $label === null or $data = $data->withContext(['label' => $label]);
        $event = (new VarDumperMapper())->map(new VarDumperFrame(\base64_encode(\serialize([$data, []]))));

        $summary = (string) (new PayloadSummary())->summary($event);

        // An object handle depends on the objects created before it.
        Assert::same(\preg_replace('/#\d+/', '#', $summary), $expected);
    }

    #[DataSet(['SELECT 1', 'Query: SELECT 1'], 'string')]
    #[DataSet([['id' => 42], 'Query: array:1 [ "id" => 42 ]'], 'array')]
    public function testCodeDump(mixed $value, string $expected): void
    {
        $data = (new VarCloner())->cloneVar($value)->withContext(['label' => 'Query', 'language' => 'sql']);
        $event = (new VarDumperMapper())->map(new VarDumperFrame(\base64_encode(\serialize([$data, []]))));

        Assert::same((new PayloadSummary())->summary($event), $expected);
    }

    public function testMail(): void
    {
        $event = self::event('smtp', [
            'subject' => 'Welcome',
            'from' => [new Contact('Shop', 'shop@example.com')],
            'to' => [new Contact('Alice', 'alice@example.com'), new Contact('Bob', null)],
        ]);

        Assert::same((new PayloadSummary())->summary($event), 'Welcome from shop@example.com to alice@example.com, Bob');
    }

    public function testMailWithoutSubject(): void
    {
        Assert::same((new PayloadSummary())->summary(self::event('smtp', ['subject' => ''])), '(no subject)');
    }

    public function testRay(): void
    {
        $event = self::event('ray', new RayPayload('5a4f8e1c-0000-4000-8000-000000000001', [
            ['type' => 'log', 'content' => ['values' => ['first', 2]]],
            ['type' => 'exception', 'content' => ['class' => 'LogicException', 'message' => 'Oops']],
        ], []));

        Assert::same((new PayloadSummary())->summary($event), 'log: first, 2; exception: LogicException: Oops');
    }

    public function testRayItemsOfOtherShapes(): void
    {
        $event = self::event('ray', ['payloads' => [
            'broken',
            ['type' => 'text', 'content' => 'plain text'],
            ['type' => 'table', 'content' => ['a' => 1]],
        ]]);

        Assert::same((new PayloadSummary())->summary($event), 'text: plain text; table: {"a":1}');
    }

    #[DataSet([['a' => 1, 'b' => [true]], '{"a":1,"b":[true]}'], 'structure becomes JSON')]
    #[DataSet([[\INF], ''], 'structure JSON cannot encode')]
    public function testPlainStructure(array $value, string $expected): void
    {
        Assert::same(PayloadSummary::plain($value), $expected);
    }

    public function testOtherObjectPayloadIsNotSerialized(): void
    {
        $payload = new class implements \JsonSerializable {
            public function jsonSerialize(): never
            {
                throw new \LogicException('Must not be serialized.');
            }
        };

        Assert::null((new PayloadSummary())->summary(self::event('profiler', $payload)));
    }

    public function testLongSummaryIsShortened(): void
    {
        $event = self::event('monolog', ['message' => \str_repeat('x', 500), 'level_name' => 'INFO']);

        $summary = (new PayloadSummary())->summary($event);

        Assert::string((string) $summary)->startsWith('INFO xxx')->endsWith('x…');
        Assert::same(\mb_strlen((string) $summary), PayloadSummary::MAX_LENGTH + 1);
    }

    public function testControlCharactersBecomeSpaces(): void
    {
        $event = self::event('monolog', ['message' => "two\nlines", 'level_name' => "INFO\e[0m"]);

        Assert::same((new PayloadSummary())->summary($event), 'INFO [0m two lines');
    }

    #[DataSet(
        [
            'var-dump',
            [
                'payload' => ['type' => 'code', 'value' => 'SELECT 1', 'label' => 'Query', 'language' => 'sql'],
                'context' => ['source' => ['file' => '/app/src/Repo.php', 'line' => 7]],
            ],
            ['label' => 'Query', 'value_type' => 'code', 'language' => 'sql', 'file' => '/app/src/Repo.php'],
        ],
        'dump',
    )]
    #[DataSet(['monolog', ['level_name' => 'ERROR', 'channel' => 'app'], ['level' => 'ERROR', 'channel' => 'app']], 'log')]
    #[DataSet(
        [
            'http-dump',
            ['host' => 'shop.test', 'request' => ['method' => 'POST', 'uri' => 'api/orders']],
            ['method' => 'POST', 'path' => '/api/orders', 'host' => 'shop.test'],
        ],
        'HTTP request',
    )]
    public function testFields(string $type, array $payload, array $expected): void
    {
        Assert::same(\array_filter((array) (new PayloadSummary())->fields(self::event($type, $payload))), $expected);
    }

    public function testMailFields(): void
    {
        $event = self::event('smtp', [
            'subject' => 'Welcome',
            'from' => [new Contact('Shop', 'shop@example.com')],
            'to' => [new Contact('Alice', 'alice@example.com'), new Contact('Bob', null)],
            'cc' => [],
        ]);

        Assert::same((new PayloadSummary())->fields($event), [
            'subject' => 'Welcome',
            'from' => ['shop@example.com'],
            'to' => ['alice@example.com', 'Bob'],
            'cc' => [],
            'bcc' => [],
        ]);
    }

    public function testRayFields(): void
    {
        $event = self::event('ray', new RayPayload('5a4f8e1c-0000-4000-8000-000000000001', [
            ['type' => 'log', 'content' => ['values' => ['first']]],
            ['type' => 'exception', 'content' => ['class' => 'LogicException', 'message' => 'Oops']],
            ['type' => 'color', 'content' => ['color' => 'red']],
            ['type' => 'label', 'content' => ['label' => 'checkout']],
            ['type' => 'log', 'content' => ['values' => ['second']]],
        ], []));

        Assert::same((new PayloadSummary())->fields($event), [
            'payload_type' => ['log', 'exception', 'color', 'label'],
            'exception' => ['LogicException'],
            'label' => ['checkout'],
            'color' => ['red'],
        ]);
    }

    public function testRayFieldsSkipItemsOfOtherShapes(): void
    {
        $event = self::event('ray', ['payloads' => [
            'broken',
            ['type' => 'exception', 'content' => 'not a structure'],
        ]]);

        Assert::same((new PayloadSummary())->fields($event), [
            'payload_type' => ['exception'],
            'exception' => [],
            'label' => [],
            'color' => [],
        ]);
    }

    public function testTypeWithoutFieldsIsLeftToOtherProviders(): void
    {
        Assert::null((new PayloadSummary())->fields(self::event('profiler', ['peaks' => []])));
        Assert::array((new PayloadSummary())->fieldNames())
            ->hasKeys('var-dump', 'monolog', 'smtp', 'http-dump', 'ray');
    }

    public function testNoText(): void
    {
        Assert::null((new PayloadSummary())->text(new Record(self::event('monolog', []), 1, [])));
    }
}

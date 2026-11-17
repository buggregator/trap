<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\View;

use Buggregator\Trap\Module\Mailer\Contact;
use Buggregator\Trap\Module\Mcp\View\PayloadSummary;
use Buggregator\Trap\Module\Ray\Frontend\Payload as RayPayload;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Testo\Assert;
use Testo\Codecov\Covers;
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

    #[DataSet(['var-dump', ['payload' => ['type' => 'string', 'value' => 'hello', 'label' => null]], 'hello'], 'string dump')]
    #[DataSet(['var-dump', ['payload' => ['type' => 'int', 'value' => '42', 'label' => 'Answer']], 'Answer: 42'], 'labeled dump')]
    #[DataSet(
        [
            'var-dump',
            ['payload' => ['type' => 'array', 'value' => "<style>x{}</style><pre>array:1 [<span>&quot;a&quot; =&gt; 1</span>\n]</pre>"]],
            'array:1 ["a" => 1 ]',
        ],
        'HTML dump',
    )]
    #[DataSet(['monolog', ['message' => 'Disk full', 'level_name' => 'ERROR', 'channel' => 'app'], 'ERROR app: Disk full'], 'log')]
    #[DataSet(
        [
            'sentry',
            ['exception' => ['values' => [
                ['type' => 'PDOException', 'value' => 'gone away'],
                ['type' => 'RuntimeException', 'value' => 'Query failed'],
            ]]],
            'RuntimeException: Query failed',
        ],
        'sentry exception',
    )]
    #[DataSet(['sentry', ['message' => ['formatted' => 'Something happened']], 'Something happened'], 'sentry message')]
    #[DataSet(['sentry', ['type' => 'transaction', 'transaction' => 'GET /'], 'transaction: GET /'], 'sentry transaction')]
    #[DataSet(['http-dump', ['request' => ['method' => 'POST', 'uri' => 'api/orders']], 'POST /api/orders'], 'HTTP request')]
    #[DataSet(['profiler', ['peaks' => []], null], 'type without summary')]
    #[DataSet(['monolog', [], null], 'empty payload')]
    public function testSummary(string $type, array $payload, ?string $expected): void
    {
        Assert::same((new PayloadSummary())->summary(self::event($type, $payload)), $expected);
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
            'sentry',
            [
                'level' => 'error',
                'exception' => ['values' => [['type' => 'PDOException'], ['type' => 'RuntimeException']]],
                'environment' => 'prod',
                'release' => '1.2.0',
                'transaction' => 'POST /orders',
                'server_name' => 'web-1',
                'contexts' => ['response' => ['status_code' => 500]],
            ],
            [
                'level' => 'error',
                'exception' => ['PDOException', 'RuntimeException'],
                'environment' => 'prod',
                'release' => '1.2.0',
                'transaction' => 'POST /orders',
                'server_name' => 'web-1',
                'status' => 500,
            ],
        ],
        'sentry',
    )]
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

    public function testTypeWithoutFieldsIsLeftToOtherProviders(): void
    {
        Assert::null((new PayloadSummary())->fields(self::event('profiler', ['peaks' => []])));
        Assert::array((new PayloadSummary())->fieldNames())
            ->hasKeys('var-dump', 'monolog', 'sentry', 'smtp', 'http-dump', 'ray');
    }

    public function testNoText(): void
    {
        Assert::null((new PayloadSummary())->text(new Record(self::event('monolog', []), 1, [])));
    }
}

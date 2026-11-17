<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Mcp;

use Buggregator\Trap\Module\Mcp\Tool\GetEvent;
use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Module\Sentry\Frame\EnvelopeItem;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore;
use Buggregator\Trap\Module\Sentry\Http\SentryTrap\EnvelopeParser;
use Buggregator\Trap\Module\Sentry\Mcp\SentryText;
use Buggregator\Trap\Module\Sentry\Mcp\SentryView;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Fiber\RunInFiber;
use Testo\Test;

#[Test]
#[Covers(SentryView::class)]
#[Covers(SentryText::class)]
final class SentryViewTest
{
    public function testStoreEventOfTheFixture(): void
    {
        $trap = new Trap();
        $record = $trap->add(new SentryStore(self::fixture('sentry-store-2.http')));

        $text = $trap->get(GetEvent::class)->call(['id' => $record->event->uuid]);

        Assert::string($text)
            ->startsWith("sentry event {$record->event->uuid} at ")
            ->contains("\nErrorException: Warning: Undefined array key \"VAR_DUMPER_FORMAT\"\n\nEvent ID: 8beb0492c70349d28242468e7028cfa6\nLevel: warning\n")
            ->contains("SDK: sentry.php.spiral 3.1.2\n")
            ->contains(
                "Exception, stack frames innermost first:\n"
                . "ErrorException: Warning: Undefined array key \"VAR_DUMPER_FORMAT\"\n"
                . "  Mechanism: generic, unhandled\n"
                . "  #0 \\app\\src\\Endpoint\\Console\\DoNothing.php:55 in App\\Endpoint\\Console\\DoNothing::getValue\n"
                . "     path: D:\\git\\test\\sentry\\app\\src\\Endpoint\\Console\\DoNothing.php\n"
                . "     52 | public function getValue(): void\n",
            )
            ->contains("   > 55 |         \$_SERVER['VAR_DUMPER_FORMAT'];\n")
            ->contains("  … 23 vendor frames #2-#24 …\n  #25 \\app.php:33\n")
            ->contains("  runtime: name=php, version=8.2.10\n")
            ->notContains('SENTRY')
            ->notContains("\e[");
    }

    public function testShortStoreEventOfTheTestCommand(): void
    {
        $text = self::text(self::fixture('sentry-store.http'));

        Assert::same(
            $text,
            "Event ID: fc6d8c0c43fc4630ad850ee518f1b9d0\n"
            . "Transaction: my.module.function_name\n"
            . "Tags: ios_version=4.0\n"
            . "\n"
            . "Exception, stack frames innermost first:\n"
            . "SyntaxError: Wattttt!\n",
        );
    }

    #[RunInFiber]
    public function testEnvelopeOfTheStub(): void
    {
        $envelope = EnvelopeParser::parse(Stream::create((string) \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/sentry-body.bin')));
        $trap = new Trap();
        $record = $trap->add($envelope);

        $text = (string) $trap->get(SentryView::class)->text($record);

        Assert::string($text)
            ->startsWith("Event ID: 1c375fcd2f15438f82d8762f083cd8c0\nType: transaction\nTransaction: CallAction\nDuration: 4065.4 ms\n")
            ->contains("Request: POST http://127.0.0.1:8000/\n  Headers:\n    host: 127.0.0.1:8000\n")
            ->contains("  Data: {\"action\":\"sentry:report\"}\n")
            ->contains("Breadcrumbs:\n  14:02:47.525 info navigation route: CallAction\n  14:02:51.580 error default log.error: Something went wrong\n")
            ->contains("Spans (2):\n  app.bootstrap (7.9 ms)\n")
            ->notContains('cookie')
            ->notContains('sec-fetch')
            ->notContains('Item 1');
    }

    public function testChainFromTheThrownExceptionToTheRootCause(): void
    {
        $text = self::text(['exception' => ['values' => [
            ['type' => 'PDOException', 'value' => 'gone away', 'stacktrace' => ['frames' => [self::frame('src/Db.php', 7)]]],
            [
                'type' => 'RuntimeException',
                'value' => "Query failed:\nSELECT 1",
                'mechanism' => ['type' => 'generic', 'handled' => true],
                'stacktrace' => ['frames' => [self::frame('src/Repo.php', 12)]],
            ],
        ]]]);

        Assert::same(
            $text,
            "Exception chain of 2, the thrown one first, then what caused it; stack frames innermost first:\n"
            . "RuntimeException: Query failed:\n  SELECT 1\n"
            . "  Mechanism: generic, handled\n"
            . "  #0 src/Repo.php:12 in App\\Code::run\n"
            . "Caused by PDOException: gone away\n"
            . "  #0 src/Db.php:7 in App\\Code::run\n",
        );
    }

    public function testVendorRunsAreCollapsed(): void
    {
        // Sentry order: the outermost call first
        $frames = [
            self::frame('public/index.php', 1),
            self::frame('vendor/framework/Kernel.php', 2),
            self::frame('vendor/framework/Router.php', 3),
            self::frame('src/Controller.php', 4),
            self::frame('/app/vendor/lib/Single.php', 5),
            self::frame('src/Service.php', 6),
            self::frame('lib/Client.php', 7, ['in_app' => false]),
            self::frame('lib/Socket.php', 8, ['in_app' => false]),
        ];

        $text = self::text(['exception' => ['values' => [['type' => 'E', 'stacktrace' => ['frames' => $frames]]]]]);

        Assert::string($text)->contains(
            "E\n"
            . "  #0 lib/Socket.php:8 in App\\Code::run\n"
            . "  #1 lib/Client.php:7 in App\\Code::run\n"
            . "  #2 src/Service.php:6 in App\\Code::run\n"
            . "  #3 /app/vendor/lib/Single.php:5 in App\\Code::run\n"
            . "  #4 src/Controller.php:4 in App\\Code::run\n"
            . "  … 2 vendor frames #5-#6 …\n"
            . "  #7 public/index.php:1 in App\\Code::run\n",
        );
    }

    public function testLibraryFramesAreShownWithoutApplicationFrames(): void
    {
        $frames = [self::frame('vendor/a.php', 1), self::frame('vendor/b.php', 2), self::frame('vendor/c.php', 3)];

        $text = self::text(['exception' => ['values' => [['type' => 'E', 'stacktrace' => ['frames' => $frames]]]]]);

        Assert::string($text)->contains("  #0 vendor/c.php:3 in App\\Code::run\n  #1 vendor/b.php:2 in App\\Code::run\n  #2 vendor/a.php:1");
    }

    public function testCodeOfTheInnermostAndApplicationFrames(): void
    {
        $code = [
            'pre_context' => ['    one', '    two', '    three', '    four', '    five'],
            'context_line' => '    six',
            'post_context' => ['    seven', '    eight', '    nine', '    ten'],
            'abs_path' => '/app/src/Code.php',
            'vars' => \array_combine(\array_map(static fn(int $i): string => "\$v$i", \range(1, 12)), \range(1, 12)),
        ];
        $frames = [
            self::frame('src/D.php', 60, $code),
            self::frame('src/C.php', 50, $code),
            self::frame('src/B.php', 40, $code),
            self::frame('vendor/lib/A.php', 30, $code),
        ];

        $text = self::text(['exception' => ['values' => [['type' => 'E', 'stacktrace' => ['frames' => $frames]]]]]);

        Assert::string($text)
            ->contains(
                "  #0 vendor/lib/A.php:30 in App\\Code::run\n"
                . "     path: /app/src/Code.php\n"
                . "     27 | three\n     28 | four\n     29 | five\n   > 30 | six\n     31 | seven\n     32 | eight\n     33 | nine\n"
                . "     vars:\n       \$v1 = 1\n",
            )
            ->contains("       \$v10 = 10\n       … 2 more …\n  #1 src/B.php:40")
            ->contains("   > 50 | six\n")
            ->notContains('> 60 |')
            ->notContains('$v11');
    }

    public function testFramesAreBounded(): void
    {
        $frames = \array_map(static fn(int $line): array => self::frame('src/A.php', $line), \range(1, 50));

        $text = self::text(['exception' => ['values' => [['type' => 'E', 'stacktrace' => ['frames' => $frames]]]]]);

        Assert::string($text)->contains("  #29 src/A.php:21 in App\\Code::run\n  … 20 more frames …\n")->notContains('#30');
    }

    public function testChainIsBounded(): void
    {
        $exceptions = \array_map(static fn(int $i): array => ['type' => "E$i", 'value' => \str_repeat('x', 3000)], \range(1, 12));

        $text = self::text(['exception' => ['values' => $exceptions]]);

        Assert::string($text)
            ->startsWith('Exception chain of 12, ')
            ->contains('E12: ' . \str_repeat('x', SentryText::MAX_TEXT) . "…\n")
            ->contains("Caused by E3: ")
            ->endsWith("… 2 more causes …\n")
            ->notContains('E2:');
    }

    public function testBreadcrumbsHeadersAndEntriesAreBounded(): void
    {
        $crumbs = \array_map(
            static fn(int $i): array => ['timestamp' => 1_700_000_000 + $i, 'category' => 'query', 'message' => "crumb $i"],
            \range(1, 15),
        );
        $headers = \array_map(static fn(int $i): array => ["x-h$i", "v$i"], \range(1, 20));
        $extra = \array_combine(\array_map(static fn(int $i): string => "k$i", \range(1, 25)), \range(1, 25));

        $text = self::text(['breadcrumbs' => ['values' => $crumbs], 'request' => ['headers' => $headers], 'extra' => $extra]);

        Assert::string($text)
            ->contains("Breadcrumbs, the last 10 of 15:\n  22:13:26.000 query: crumb 6\n")
            ->contains("  22:13:35.000 query: crumb 15\n")
            ->contains("    x-h15: v15\n    … 5 more …\n")
            ->contains("  k20: 20\n  … 5 more …\n")
            ->notContains('crumb 5')
            ->notContains('x-h16')
            ->notContains('k21');
    }

    public function testEventFields(): void
    {
        $text = self::text([
            'event_id' => 'abc',
            'level' => 'error',
            'logentry' => ['message' => 'User %s failed', 'formatted' => 'User 42 failed'],
            'environment' => 'prod',
            'release' => '1.2.0',
            'fingerprint' => ['{{ default }}', 'checkout'],
            'tags' => [['region', 'eu'], ['shard', '3']],
            'user' => ['id' => 42, 'email' => 'alice@example.com'],
            'request' => [
                'method' => 'GET',
                'url' => 'https://shop.test/orders',
                'query_string' => [['page', '2']],
                'headers' => ['Accept' => 'text/html', 'Cookie' => 'secret'],
            ],
            'contexts' => ['os' => ['type' => 'os', 'name' => 'Linux'], 'response' => ['status_code' => 500]],
            'extra' => ['order' => ['id' => 7], 'none' => null],
        ]);

        Assert::same(
            $text,
            "Event ID: abc\nLevel: error\nMessage: User 42 failed\nEnvironment: prod\nRelease: 1.2.0\n"
            . "Fingerprint: {{ default }}, checkout\nTags: region=eu, shard=3\nUser: id=42, email=alice@example.com\n"
            . "\nRequest: GET https://shop.test/orders\n  Query: page=2\n  Headers:\n    Accept: text/html\n"
            . "\nContexts:\n  os: name=Linux\n  response: status_code=500\n"
            . "\nExtra:\n  order: id=7\n  none: null\n",
        );
    }

    public function testStackTraceOfTheCrashedThread(): void
    {
        $text = self::text(['message' => 'Deadlock', 'threads' => ['values' => [
            ['id' => 1, 'name' => 'main', 'stacktrace' => ['frames' => [self::frame('src/Main.php', 1)]]],
            ['id' => 2, 'name' => 'worker', 'crashed' => true, 'stacktrace' => ['frames' => [self::frame('src/Worker.php', 2)]]],
        ]]]);

        Assert::same(
            $text,
            "Message: Deadlock\n\nStack trace of thread worker, frames innermost first:\n  #0 src/Worker.php:2 in App\\Code::run\n",
        );
    }

    #[DataSet([[]], 'empty')]
    #[DataSet([['exception' => 'oops', 'request' => 'GET /', 'breadcrumbs' => 3, 'tags' => 'x']], 'unexpected shapes')]
    #[DataSet([['exception' => ['values' => ['oops', ['stacktrace' => ['frames' => ['frame', []]]]]]]], 'broken frames')]
    public function testMissingFieldsAreLeftOut(array $payload): void
    {
        $text = self::text($payload);

        Assert::string($text)->notContains('Request:')->notContains('Breadcrumbs')->notContains('Tags');
    }

    public function testFrameWithoutFileAndLine(): void
    {
        $text = self::text(['exception' => ['values' => [['stacktrace' => ['frames' => [['function' => 'main']]]]]]]);

        Assert::same($text, "Exception, stack frames innermost first:\nException\n  #0 [unknown] in main\n");
    }

    public function testEveryItemOfAnEnvelope(): void
    {
        $envelope = new SentryEnvelope(['event_id' => 'abc'], [
            new EnvelopeItem(['type' => 'event'], ['event_id' => 'abc', 'message' => 'Boom']),
            new EnvelopeItem(
                ['type' => 'attachment', 'length' => 11, 'filename' => 'report.txt', 'content_type' => 'text/plain'],
                Stream::create('hello world'),
            ),
        ]);

        $text = (string) (new SentryView())->text(new Record(self::event(['event_id' => 'abc']), 1, [$envelope]));

        Assert::same(
            $text,
            "--- Item 1 of 2: event ---\nEvent ID: abc\nMessage: Boom\n\n"
            . "--- Item 2 of 2: attachment ---\nreport.txt, text/plain, 11 bytes\n",
        );
    }

    public function testRecordWithoutFramesShowsThePayload(): void
    {
        $text = (new SentryView())->text(new Record(self::event(['message' => 'Boom']), 1, []));

        Assert::same($text, "Message: Boom\n");
    }

    public function testOtherEventsAreLeftToOtherViews(): void
    {
        $view = new SentryView();
        $event = new Event('5a4f8e1c-0000-4000-8000-000000000001', 'monolog', ['level' => 'error'], 1.0);

        Assert::null($view->summary($event));
        Assert::null($view->fields($event));
        Assert::null($view->text(new Record($event, 1, [])));
    }

    public function testSentryViewGoesBeforeTheConsoleText(): void
    {
        $trap = new Trap();
        $record = $trap->add(new SentryStore(['event_id' => 'abc', 'message' => 'Boom']));

        Assert::same($trap->get(EventViews::class)->text($record), "Event ID: abc\nMessage: Boom\n");
    }

    #[DataSet(
        [
            ['exception' => ['values' => [
                ['type' => 'PDOException', 'value' => 'gone away'],
                ['type' => 'RuntimeException', 'value' => 'Query failed'],
            ]]],
            'RuntimeException: Query failed',
        ],
        'exception',
    )]
    #[DataSet([['message' => ['formatted' => 'Something happened']], 'Something happened'], 'message')]
    #[DataSet([['logentry' => ['message' => 'User %s failed']], 'User %s failed'], 'log entry')]
    #[DataSet([['type' => 'transaction', 'transaction' => 'GET /'], 'transaction: GET /'], 'transaction')]
    #[DataSet([[], null], 'empty')]
    public function testSummary(array $payload, ?string $expected): void
    {
        Assert::same((new SentryView())->summary(self::event($payload)), $expected);
    }

    public function testFields(): void
    {
        $fields = (new SentryView())->fields(self::event([
            'level' => 'error',
            'exception' => ['values' => [['type' => 'PDOException'], ['type' => 'RuntimeException']]],
            'environment' => 'prod',
            'release' => '1.2.0',
            'transaction' => 'POST /orders',
            'server_name' => 'web-1',
            'contexts' => ['response' => ['status_code' => 500]],
        ]));

        Assert::same($fields, [
            'level' => 'error',
            'exception' => ['PDOException', 'RuntimeException'],
            'environment' => 'prod',
            'release' => '1.2.0',
            'transaction' => 'POST /orders',
            'server_name' => 'web-1',
            'status' => 500,
        ]);
        Assert::same(
            (new SentryView())->fieldNames(),
            ['sentry' => ['level', 'exception', 'environment', 'release', 'transaction', 'server_name', 'status']],
        );
    }

    #[DataSet([1_704_103_200.123456, 1_704_103_200.623456], 'Unix times')]
    #[DataSet(['1704103200.123456', '1704103200.623456'], 'numeric strings')]
    #[DataSet(['2024-01-01T10:00:00.123456Z', '2024-01-01T10:00:00.623456789Z'], 'RFC 3339 of Go')]
    #[DataSet(['2024-01-01T10:00:00.123456', '2024-01-01T12:00:00.623456+02:00'], 'ISO of Python')]
    public function testDurationOfTimestamps(mixed $start, mixed $end): void
    {
        $text = self::text([
            'type' => 'transaction',
            'start_timestamp' => $start,
            'timestamp' => $end,
            'spans' => [['op' => 'db', 'start_timestamp' => $start, 'timestamp' => $end]],
            'breadcrumbs' => [['timestamp' => $start, 'message' => 'begin']],
        ]);

        Assert::string($text)
            ->contains("Duration: 500.0 ms\n")
            ->contains("  db (500.0 ms)\n")
            ->contains("  10:00:00.123 begin\n");
    }

    public function testExceptionFramesOfItsThread(): void
    {
        $text = self::text([
            'exception' => ['values' => [['type' => 'EXC_BAD_ACCESS', 'value' => 'KERN_INVALID_ADDRESS', 'thread_id' => 2]]],
            'threads' => ['values' => [
                ['id' => 1, 'crashed' => true, 'stacktrace' => ['frames' => [self::frame('Main.swift', 1)]]],
                ['id' => 2, 'stacktrace' => ['frames' => [self::frame('App.swift', 9), self::frame('Crash.swift', 3)]]],
            ]],
        ]);

        Assert::same(
            $text,
            "Exception, stack frames innermost first:\nEXC_BAD_ACCESS: KERN_INVALID_ADDRESS\n"
            . "  #0 Crash.swift:3 in App\\Code::run\n  #1 App.swift:9 in App\\Code::run\n",
        );
    }

    public function testExceptionFramesOfTheCrashedThread(): void
    {
        $text = self::text([
            'exception' => ['values' => [['type' => 'SIGSEGV']]],
            'threads' => ['values' => [
                ['id' => 1, 'stacktrace' => ['frames' => [self::frame('main.c', 1)]]],
                ['id' => 2, 'crashed' => true, 'stacktrace' => ['frames' => [self::frame('crash.c', 2)]]],
            ]],
        ]);

        Assert::string($text)->contains("SIGSEGV\n  #0 crash.c:2 in App\\Code::run\n")->notContains('main.c');
    }

    public function testSecretsOfTheRequestAreFiltered(): void
    {
        $text = self::text(['request' => [
            'url' => 'https://shop.test/?page=2&password=abc#top',
            'query_string' => 'page=2&access_token=abc&apiKey=def',
            'headers' => [
                'Authorization' => 'Bearer abc',
                'Proxy-Authorization' => 'Basic abc',
                'X-Api-Key' => 'abc',
                'X-Auth-Token' => ['abc'],
                'X-Client-Secret' => 'abc',
                'X-Password' => 'abc',
                'Accept' => 'text/html',
            ],
        ]]);
        $listed = self::text(['request' => ['query_string' => [['page', '2'], ['api_key', 'abc']]]]);

        Assert::string($text)
            ->contains("Request: https://shop.test/?page=2&password=[Filtered]#top\n  Query: page=2&access_token=[Filtered]&apiKey=[Filtered]\n")
            ->contains(
                "    Authorization: [Filtered]\n    Proxy-Authorization: [Filtered]\n    X-Api-Key: [Filtered]\n"
                . "    X-Auth-Token: [Filtered]\n    X-Client-Secret: [Filtered]\n    X-Password: [Filtered]\n"
                . "    Accept: text/html\n",
            )
            ->notContains('abc')
            ->notContains('def');
        Assert::string($listed)->contains("  Query: page=2, api_key=[Filtered]\n");
    }

    public function testKeysAreCutToOneLine(): void
    {
        $key = "multi\nline" . \str_repeat('k', 5000);
        $frame = self::frame('src/A.php', 1, ['context_line' => 'run();', 'vars' => [$key => 1]]);

        $text = self::text([
            'exception' => ['values' => [['type' => 'E', 'stacktrace' => ['frames' => [$frame]]]]],
            'contexts' => [$key => ['a' => 1]],
            'extra' => [$key => 2],
        ]);

        $cut = 'multi line' . \str_repeat('k', SentryText::MAX_VALUE - 10) . '…';
        Assert::string($text)
            ->contains("       $cut = 1\n")
            ->contains("  $cut: a=1\n")
            ->contains("  $cut: 2\n")
            ->notContains("multi\n")
            ->notContains(\str_repeat('k', SentryText::MAX_VALUE));
    }

    public function testEnvelopeItemsAreBounded(): void
    {
        $items = [
            new EnvelopeItem(['type' => 'event'], ['message' => 'Boom']),
            new EnvelopeItem(['type' => 'session'], ['sid' => 's1', 'status' => 'exited', 'errors' => 1]),
            new EnvelopeItem(['type' => 'client_report'], []),
            ...\array_map(
                static fn(int $i): EnvelopeItem => new EnvelopeItem(['type' => 'check_in'], ['monitor_slug' => "job$i", 'status' => 'ok']),
                \range(4, 25),
            ),
        ];

        $text = (string) (new SentryView())->text(new Record(self::event([]), 1, [new SentryEnvelope([], $items)]));

        Assert::string($text)
            ->startsWith("--- Item 1 of 25: event ---\nMessage: Boom\n\n--- Item 2 of 25: session ---\nsid=s1, status=exited, errors=1\n")
            ->contains("--- Item 3 of 25: client_report ---\nEmpty\n")
            ->contains("--- Item 20 of 25: check_in ---\nmonitor_slug=job20, status=ok\n\n… 5 more items …\n")
            ->notContains('Item 21');
    }

    public function testSingleItemThatIsNotAnEvent(): void
    {
        $envelope = new SentryEnvelope([], [new EnvelopeItem(['type' => 'session'], ['sid' => 's1', 'status' => 'ok'])]);

        $text = (new SentryView())->text(new Record(self::event([]), 1, [$envelope]));

        Assert::same($text, "--- Item 1 of 1: session ---\nsid=s1, status=ok\n");
    }

    #[DataSet([['logentry' => ['message' => 'User %s', 'formatted' => 'User 42'], 'message' => 'Plain'], 'User 42'], 'formatted log entry')]
    #[DataSet([['logentry' => ['message' => 'User %s'], 'message' => 'Plain'], 'Plain'], 'message string')]
    #[DataSet([['logentry' => ['message' => 'User %s'], 'message' => ['formatted' => 'Formatted']], 'Formatted'], 'formatted message')]
    #[DataSet([['logentry' => ['message' => 'User %s']], 'User %s'], 'log entry template')]
    public function testMessagePrecedence(array $payload, string $expected): void
    {
        Assert::same((new SentryView())->summary(self::event($payload)), $expected);
        Assert::same(self::text($payload), "Message: $expected\n");
    }

    public function testSingleExceptionObject(): void
    {
        $payload = ['exception' => ['type' => 'TypeError', 'value' => 'x is undefined', 'stacktrace' => ['frames' => [self::frame('app.js', 3)]]]];
        $view = new SentryView();

        $text = self::text($payload);

        Assert::same($text, "Exception, stack frames innermost first:\nTypeError: x is undefined\n  #0 app.js:3 in App\\Code::run\n");
        Assert::same($view->summary(self::event($payload)), 'TypeError: x is undefined');
        Assert::same(($view->fields(self::event($payload)) ?? [])['exception'] ?? null, ['TypeError']);
    }

    public function testCausesCollapseTheFramesInCommon(): void
    {
        $code = ['context_line' => 'call();'];
        $outer = [self::frame('index.php', 1, $code), self::frame('src/Kernel.php', 2, $code), self::frame('src/Service.php', 3, $code)];
        $cause = [...\array_slice($outer, 0, 2), self::frame('src/Service.php', 5, $code), self::frame('src/Db.php', 9, $code)];
        $root = [...$cause];

        $text = self::text(['exception' => ['values' => [
            ['type' => 'PDOException', 'stacktrace' => ['frames' => $root]],
            ['type' => 'DbException', 'stacktrace' => ['frames' => $cause]],
            ['type' => 'ServiceException', 'stacktrace' => ['frames' => $outer]],
        ]]]);

        Assert::string($text)->contains(
            "ServiceException\n"
            . "  #0 src/Service.php:3 in App\\Code::run\n   > 3 | call();\n"
            . "  #1 src/Kernel.php:2 in App\\Code::run\n   > 2 | call();\n"
            . "  #2 index.php:1 in App\\Code::run\n   > 1 | call();\n"
            . "Caused by DbException\n"
            . "  #0 src/Db.php:9 in App\\Code::run\n   > 9 | call();\n"
            . "  #1 src/Service.php:5 in App\\Code::run\n   > 5 | call();\n"
            . "  … 2 frames in common with the exception above …\n"
            . "Caused by PDOException\n"
            . "  #0 src/Db.php:9 in App\\Code::run\n"
            . "  … 3 frames in common with the exception above …\n",
        );
    }

    public function testLongChainLeavesTheRequestWithinTheSearchedText(): void
    {
        $exceptions = \array_map(static fn(int $i): array => [
            'type' => "E$i",
            'value' => \str_repeat('message ', 200),
            'stacktrace' => ['frames' => \array_map(static fn(int $line): array => self::frame("src/Module$i/Service.php", $line, [
                'abs_path' => "/var/www/app/src/Module$i/Service.php",
                'pre_context' => \array_fill(0, 5, \str_repeat('    $value = $this->call($value);', 3)),
                'context_line' => \str_repeat('    $value = $this->call($value);', 3),
                'post_context' => \array_fill(0, 5, \str_repeat('    $value = $this->call($value);', 3)),
            ]), \range(1, 40))],
        ], \range(1, 11));

        $text = self::text([
            'exception' => ['values' => $exceptions],
            'request' => ['method' => 'GET', 'url' => 'https://shop.test/'],
            'contexts' => ['runtime' => ['name' => 'php']],
            'breadcrumbs' => [['message' => 'last step']],
        ]);

        Assert::int((int) \strpos($text, "\nBreadcrumbs:\n  last step\n"))->greaterThan(0)->lessThan(64 * 1024 - 100);
        Assert::string($text)
            ->contains("Request: GET https://shop.test/\n")
            ->contains("Contexts:\n  runtime: name=php\n")
            ->matchesRegex('/\n… \d more causes …\n/');
    }

    public function testEmptyBreadcrumbsAndMechanismAreLeftOut(): void
    {
        $text = self::text([
            'exception' => ['values' => [['type' => 'E', 'mechanism' => []]]],
            'breadcrumbs' => [['category' => 'query'], [], ['data' => []], ['message' => 'done']],
        ]);

        Assert::same($text, "Exception, stack frames innermost first:\nE\n\nBreadcrumbs:\n  query\n  done\n");
    }

    /**
     * The decoded JSON body of a request in `resources/payloads`, as `trap test` sends it.
     */
    private static function fixture(string $name): array
    {
        $request = (string) \file_get_contents(Info::TRAP_ROOT . '/resources/payloads/' . $name);
        $body = \substr($request, (int) \strpos($request, "\r\n\r\n") + 4);
        $decoded = @\gzdecode($body);

        return (array) \json_decode($decoded === false ? $body : $decoded, true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function frame(string $file, int $line, array $more = []): array
    {
        return ['filename' => $file, 'lineno' => $line, 'function' => 'App\\Code::run', ...$more];
    }

    private static function event(array $payload): Event
    {
        return new Event('5a4f8e1c-0000-4000-8000-000000000001', 'sentry', $payload, 1.0);
    }

    private static function text(array $payload): string
    {
        return (string) (new SentryView())->text(new Record(self::event($payload), 1, [new SentryStore($payload)]));
    }
}

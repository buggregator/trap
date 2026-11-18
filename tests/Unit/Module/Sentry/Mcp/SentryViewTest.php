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
    #[DataSet(['2024-01-01 10:00:00.123456z', '2024-01-01t07:00:00.623456-0300'], 'space, lowercase and offset without colon')]
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

    public function testBareListOfExceptionsWithoutTheValuesWrapper(): void
    {
        $text = self::text(['exception' => [['type' => 'PDOException', 'value' => 'gone away'], ['type' => 'DbError', 'value' => 'query failed']]]);

        Assert::same(
            $text,
            "Exception chain of 2, the thrown one first, then what caused it; stack frames innermost first:\n"
            . "DbError: query failed\nCaused by PDOException: gone away\n",
        );
    }

    public function testSingleExceptionWithOnlyValue(): void
    {
        $payload = ['exception' => ['value' => 'gone away']];

        $text = self::text($payload);

        Assert::same($text, "Exception, stack frames innermost first:\nException: gone away\n");
        Assert::same((new SentryView())->summary(self::event($payload)), 'gone away');
    }

    public function testObjectOfOtherKeysIsNoException(): void
    {
        $text = self::text(['exception' => ['mechanism' => ['type' => 'generic', 'handled' => false]]]);

        Assert::same($text, '');
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

    public function testLargeThrownExceptionLeavesTheRequestWithinTheSearchedText(): void
    {
        $name = \str_repeat('Ж', 400);
        $frames = \array_map(static fn(int $line): array => self::frame("src/$name.php", $line, [
            'class' => $name,
            'function' => $name,
            'context_line' => $name,
            'pre_context' => [$name, $name, $name],
            'post_context' => [$name, $name, $name],
            'vars' => \array_fill_keys(\range(1, 10), $name),
        ]), \range(1, 40));

        $text = self::text([
            'exception' => ['values' => [['type' => 'Ошибка', 'value' => $name, 'stacktrace' => ['frames' => $frames]]]],
            'request' => ['method' => 'GET', 'url' => 'https://shop.test/'],
            'contexts' => ['runtime' => ['name' => 'php']],
            'breadcrumbs' => [['message' => 'last step']],
        ]);

        Assert::int((int) \strpos($text, "\nRequest: GET https://shop.test/\n"))->greaterThan(0)->lessThan(SentryText::MAX_CHAIN_BYTES);
        Assert::int((int) \strpos($text, "\nBreadcrumbs:\n  last step\n"))->greaterThan(0)->lessThan(64 * 1024 - 100);
        Assert::string($text)
            ->startsWith("Exception, stack frames innermost first:\nОшибка: $name\n  #0 ")
            ->matchesRegex('/\n  … \d+ more frames …\n/')
            ->notContains('vars:');
    }

    public function testMoreSecretsOfTheRequestAreFiltered(): void
    {
        $text = self::text(['request' => [
            'url' => 'https://user:s3cret@shop.test/callback#access_token=abc&state=1',
            'query_string' => 'page=2;authorization=abc;user[password]=abc',
            'headers' => ['X-Authorization' => 'abc', 'X-Forwarded-Authorization' => 'abc', 'Set-Cookie' => 'abc'],
        ]]);
        $nested = self::text(['request' => ['query_string' => ['page' => 2, 'user' => ['name' => 'Ann', 'password' => 'abc']]]]);

        Assert::string($text)
            ->contains("Request: https://user:[Filtered]@shop.test/callback#access_token=[Filtered]&state=1\n")
            ->contains("  Query: page=2;authorization=[Filtered];user[password]=[Filtered]\n")
            ->contains("    X-Authorization: [Filtered]\n    X-Forwarded-Authorization: [Filtered]\n    Set-Cookie: [Filtered]\n")
            ->notContains('abc')
            ->notContains('s3cret');
        Assert::string($nested)
            ->contains('  Query: page=2, user={"name":"Ann","password":"[Filtered]"}' . "\n")
            ->notContains('abc');
    }

    public function testExceptionFramesOfAThreadWithAnIdOfAnotherType(): void
    {
        $text = self::text([
            'exception' => ['values' => [['type' => 'SIGSEGV', 'thread_id' => '2']]],
            'threads' => ['values' => [
                ['id' => 1, 'crashed' => true, 'stacktrace' => ['frames' => [self::frame('main.c', 1)]]],
                ['id' => 2, 'stacktrace' => ['frames' => [self::frame('worker.c', 2)]]],
            ]],
        ]);

        Assert::string($text)->contains("SIGSEGV\n  #0 worker.c:2 in App\\Code::run\n")->notContains('main.c');
    }

    #[DataSet([[]], 'empty')]
    #[DataSet([['a' => ['filename' => 'own.c']]], 'not a list')]
    public function testExceptionWithoutFramesTakesThoseOfItsThread(array $frames): void
    {
        $text = self::text([
            'exception' => ['values' => [['type' => 'SIGSEGV', 'stacktrace' => ['frames' => $frames]]]],
            'threads' => ['values' => [['id' => 1, 'crashed' => true, 'stacktrace' => ['frames' => [self::frame('crash.c', 2)]]]]],
        ]);

        Assert::string($text)->contains("SIGSEGV\n  #0 crash.c:2 in App\\Code::run\n")->notContains('own.c');
    }

    #[DataSet(['now'], 'now')]
    #[DataSet(['tomorrow'], 'tomorrow')]
    #[DataSet(['1 hour'], 'relative')]
    #[DataSet(['2024-01-01T00:00:00 +1000000000000 years'], 'date with relative years')]
    #[DataSet(['2024-01-01 00:00 next monday'], 'date with a relative day')]
    #[DataSet(["2024-01-01\n"], 'date with a newline')]
    #[DataSet(['2024-01-01T00:00:00.9999999999Z'], 'date with more than 9 fraction digits')]
    #[DataSet(['2024-13-01'], 'date with a month out of range')]
    #[DataSet(['2024-01-01T25:00'], 'date with an hour out of range')]
    #[DataSet([\NAN], 'NaN')]
    #[DataSet([\INF], 'infinity')]
    #[DataSet(['1e999'], 'infinite numeric string')]
    public function testValuesThatAreNotTimesAreLeftOut(mixed $time): void
    {
        $text = self::text([
            'type' => 'transaction',
            'start_timestamp' => 1_704_103_200.0,
            'timestamp' => $time,
            'spans' => [['op' => 'db', 'start_timestamp' => $time, 'timestamp' => 1_704_103_200.0]],
            'breadcrumbs' => [['timestamp' => $time, 'message' => 'begin']],
        ]);

        Assert::string($text)
            ->notContains('Duration:')
            ->contains("  db\n")
            ->contains("Breadcrumbs:\n  begin\n");
    }

    public function testLinesOfMessageAndRequestDataAreIndented(): void
    {
        $text = self::text(['message' => "first\nsecond", 'request' => ['url' => 'https://shop.test/', 'data' => "a=1\nb=2"]]);

        Assert::string($text)
            ->startsWith("Message: first\n  second\n")
            ->contains("  Data: a=1\n    b=2\n");
    }

    public function testOneFrameInCommon(): void
    {
        $outer = [self::frame('index.php', 1), self::frame('src/Service.php', 3)];

        $text = self::text(['exception' => ['values' => [
            ['type' => 'Cause', 'stacktrace' => ['frames' => [$outer[0], self::frame('src/Db.php', 9)]]],
            ['type' => 'Thrown', 'stacktrace' => ['frames' => $outer]],
        ]]]);

        Assert::string($text)->endsWith("Caused by Cause\n  #0 src/Db.php:9 in App\\Code::run\n  … 1 frame in common with the exception above …\n");
    }

    public function testTypeOfEveryEventItem(): void
    {
        $items = [
            new EnvelopeItem(['type' => 'transaction'], ['transaction' => 'GET /']),
            new EnvelopeItem(['type' => 'feedback'], ['contexts' => ['feedback' => ['message' => 'Nice']]]),
        ];

        $text = (string) (new SentryView())->text(new Record(self::event([]), 1, [new SentryEnvelope([], $items)]));

        Assert::string($text)
            ->contains("--- Item 1 of 2: transaction ---\nType: transaction\nTransaction: GET /\n")
            ->contains("--- Item 2 of 2: feedback ---\nType: feedback\n");
    }

    public function testEmptyBreadcrumbsAndMechanismAreLeftOut(): void
    {
        $text = self::text([
            'exception' => ['values' => [['type' => 'E', 'mechanism' => []]]],
            'breadcrumbs' => [['category' => 'query'], [], ['data' => []], ['message' => 'done']],
        ]);

        Assert::same($text, "Exception, stack frames innermost first:\nE\n\nBreadcrumbs:\n  query\n  done\n");
    }

    public function testDataOfBreadcrumbsIsShownAsJson(): void
    {
        $text = self::text(['breadcrumbs' => [
            ['category' => 'http', 'message' => 'GET /orders', 'data' => ['status_code' => 500, 'reason' => null]],
            ['message' => 'retry', 'data' => ['ratio' => \NAN]],
        ]]);

        Assert::same($text, "Breadcrumbs:\n  http: GET /orders {\"status_code\":500,\"reason\":null}\n  retry\n", 'Data JSON cannot encode is left out.');
    }

    public function testThrownExceptionWithoutRoomForFramesCountsThem(): void
    {
        $frames = \array_map(static fn(int $line): array => self::frame('src/A.php', $line), \range(1, 40));
        $withCode = [];

        $text = \Closure::bind(
            static fn(): string => SentryText::thrown(['type' => 'E', 'value' => 'Boom'], $frames, $withCode, 10),
            null,
            SentryText::class,
        )();

        Assert::same($text, "E: Boom\n  … 40 more frames …\n");
    }

    public function testLargeMetadataAndRequestLeaveContextsAndBreadcrumbsWithinTheSearchedText(): void
    {
        $long = \str_repeat('😀', 3000);
        $pairs = \array_map(static fn(int $i): array => ["q$i$long", $long], \range(1, 20));
        $name = \str_repeat('Ж', 400);
        $frames = \array_map(static fn(int $line): array => self::frame("src/$name.php", $line, [
            'function' => $name,
            'context_line' => $name,
            'vars' => \array_fill_keys(\range(1, 10), $name),
        ]), \range(1, 40));

        $text = self::text([
            'event_id' => $long,
            'level' => $long,
            'message' => $long,
            'transaction' => $long,
            'environment' => $long,
            'release' => $long,
            'server_name' => $long,
            'platform' => $long,
            'logger' => $long,
            'sdk' => ['name' => $long, 'version' => $long],
            'fingerprint' => [$long],
            'tags' => $pairs,
            'user' => $pairs,
            'exception' => ['values' => [
                ['type' => 'Cause', 'value' => $long, 'stacktrace' => ['frames' => $frames]],
                ['type' => 'Ошибка', 'value' => $long, 'stacktrace' => ['frames' => $frames]],
            ]],
            'request' => [
                'method' => 'POST',
                'url' => "https://shop.test/$long",
                'query_string' => $pairs,
                'headers' => \array_map(static fn(int $i): array => ["X-H$i-$long", $long], \range(1, 15)),
                'data' => $long,
            ],
            'contexts' => ['runtime' => ['name' => 'php']],
            'breadcrumbs' => [['message' => 'last step']],
        ]);

        Assert::int((int) \strpos($text, "\nContexts:\n  runtime: name=php\n"))->greaterThan(0)->lessThan(64 * 1024 - 100);
        Assert::int((int) \strpos($text, "\nBreadcrumbs:\n  last step\n"))->greaterThan(0)->lessThan(64 * 1024 - 100);
        Assert::string($text)
            ->contains("\nОшибка: ")
            ->contains("\n  Query: q1" . \str_repeat('😀', SentryText::MAX_VALUE - 2) . "…\n")
            ->matchesRegex('/\n    … \d+ more …\n  Data: /u');
    }

    public function testLongValuesAreCollapsedToOneLine(): void
    {
        $text = self::text(['extra' => [
            'runs' => \str_repeat("ab \n\t ", 2000),
            'leading' => \str_repeat(' ', 5000) . 'x' . \str_repeat(' ', 5000) . 'y',
        ]]);

        Assert::same($text, "Extra:\n  runs: " . \str_repeat('ab ', 100) . "…\n  leading: x y\n");
    }

    public function testUrlsOfHeadersAndQueryValuesAreFiltered(): void
    {
        $text = self::text(['request' => [
            'url' => 'http://[::1]:8080/users/@ann?next=' . \rawurlencode('https://id.test/cb?code=1&access_token=abc'),
            'headers' => [
                'Referer' => 'https://user:abc@shop.test/cart?token=abc#access_token=abc',
                'Location' => 'https://shop.test/done?api_key=abc',
                'Origin' => 'https://shop.test',
                'Content-Location' => 'http://[::1]:8080/users/@ann?page=2',
            ],
        ]]);

        Assert::string($text)
            ->contains("Request: http://[::1]:8080/users/@ann?next=https%3A%2F%2Fid.test%2Fcb%3Fcode%3D1%26access_token%3D[Filtered]\n")
            ->contains(
                "    Referer: https://user:[Filtered]@shop.test/cart?token=[Filtered]#access_token=[Filtered]\n"
                . "    Location: https://shop.test/done?api_key=[Filtered]\n"
                . "    Origin: https://shop.test\n"
                . "    Content-Location: http://[::1]:8080/users/@ann?page=2\n",
            )
            ->notContains('abc');
    }

    #[DataSet(['r=https://a.test/cb?access_token=abc', 'r=https://a.test/cb?access_token=[Filtered]'], 'not encoded')]
    #[DataSet(['r=https://a.test/?s=https://b.test/?token=abc', 'r=https://a.test/?s=https://b.test/?token=[Filtered]'], 'nested twice')]
    #[DataSet(['r=https%3A%2F%2Fa.test%2Fcb%3Fcode%3D1%26token%3Dabc', 'r=https%3A%2F%2Fa.test%2Fcb%3Fcode%3D1%26token%3D[Filtered]'], 'encoded')]
    #[DataSet(['r=https%3a%2f%2fa.test%2f%3fq%3da+b', 'r=https%3a%2f%2fa.test%2f%3fq%3da+b'], 'encoded, not masked')]
    #[DataSet(['r=https%3A%2F%2Fa.test%2F%3Fq%3Da+b%26secret%3Dabc', 'r=https%3A%2F%2Fa.test%2F%3Fq%3Da%20b%26secret%3D[Filtered]'], 'plus, masked')]
    public function testUrlsNestedInQueryValuesAreFiltered(string $query, string $expected): void
    {
        $text = self::text(['request' => [
            'url' => "https://shop.test/#$query",
            'query_string' => $query,
            'headers' => ['Referer' => "https://shop.test/?$query"],
        ]]);

        Assert::string($text)
            ->contains("Request: https://shop.test/#$expected\n")
            ->contains("  Query: $expected\n")
            ->contains("    Referer: https://shop.test/?$expected\n");
    }

    public function testStackTraceOfAThreadLeavesContextsAndBreadcrumbsWithinTheSearchedText(): void
    {
        $long = \str_repeat('😀', 1000);
        $frames = \array_map(static fn(int $line): array => self::frame("src/$long.php", $line, [
            'function' => $long,
            'pre_context' => [$long, $long, $long],
            'context_line' => $long,
            'post_context' => [$long, $long, $long],
            'vars' => \array_fill_keys(\range(1, 10), $long),
        ]), \range(1, 40));

        $text = self::text([
            'threads' => ['values' => [['id' => 1, 'crashed' => true, 'stacktrace' => ['frames' => $frames]]]],
            'contexts' => ['runtime' => ['name' => 'php']],
            'breadcrumbs' => [['message' => 'last step']],
        ]);

        Assert::string($text)->startsWith("Stack trace of thread 1, frames innermost first:\n  #0 src/");
        Assert::int((int) \strpos($text, "\nContexts:\n  runtime: name=php\n"))->greaterThan(0)->lessThan(64 * 1024 - 100);
        Assert::int((int) \strpos($text, "\nBreadcrumbs:\n  last step\n"))->greaterThan(0)->lessThan(64 * 1024 - 100);
    }

    public function testDateWithThousandsOfFractionDigitsIsLeftOut(): void
    {
        $time = '2024-01-01T00:00:00.' . \str_repeat('9', 5000) . 'Z';

        $text = self::text([
            'type' => 'transaction',
            'start_timestamp' => 1_704_103_200.0,
            'timestamp' => $time,
            'breadcrumbs' => [['timestamp' => $time, 'message' => 'begin']],
        ]);

        Assert::string($text)
            ->notContains('Duration:')
            ->contains("Breadcrumbs:\n  begin\n");
    }

    public function testDurationTooLongForAFloatIsLeftOut(): void
    {
        $text = self::text([
            'type' => 'transaction',
            'start_timestamp' => -1e308,
            'timestamp' => 1e308,
            'spans' => [['op' => 'db', 'start_timestamp' => -1e308, 'timestamp' => 1e308]],
        ]);

        Assert::string($text)
            ->notContains('Duration:')
            ->contains("  db\n");
    }

    #[DataSet(['=?'], 'queries')]
    #[DataSet(['=#'], 'fragments')]
    public function testUrlsNestedPastTheDepthAreFilteredWhole(string $nesting): void
    {
        $query = 'r' . \str_repeat($nesting, 150) . 'x=abc';

        $text = self::text(['request' => [
            'url' => "https://shop.test/?$query",
            'query_string' => $query,
            'headers' => ['Referer' => "https://shop.test/?$query"],
        ]]);

        Assert::string($text)
            ->matchesRegex('~^Request: https://shop\.test/\?r[=?#]{10,14}\[Filtered\]$~m')
            ->matchesRegex('~^  Query: r[=?#]{10,14}\[Filtered\]$~m')
            ->matchesRegex('~^    Referer: https://shop\.test/\?r[=?#]{10,14}\[Filtered\]$~m')
            ->notContains('abc');
    }

    #[DataSet([['next' => 'https://a.test/?token=abc', 'q' => 'a+b']], 'map')]
    #[DataSet([[['next', 'https://a.test/?token=abc'], ['q', 'a+b']]], 'list of pairs')]
    public function testUrlsInValuesOfAQueryMapAreFiltered(array $query): void
    {
        $text = self::text(['request' => ['url' => 'https://shop.test/', 'query_string' => $query]]);

        Assert::string($text)->contains("  Query: next=https://a.test/?token=[Filtered], q=a+b\n");
    }

    public function testPasswordOfAUrlPastTheCutIsFiltered(): void
    {
        $url = 'https://' . \str_repeat('u', 250) . ':' . \str_repeat('p', 500) . '@shop.test/';

        $text = self::text(['request' => ['url' => $url, 'headers' => ['Referer' => $url]]]);

        $filtered = 'https://' . \str_repeat('u', 250) . ':[Filtered]@shop.test/';
        Assert::string($text)
            ->contains("Request: $filtered\n")
            ->contains("    Referer: $filtered\n")
            ->notContains('pp');
    }

    #[DataSet([['r' => ['https://a.test/?token=abc']], 'r=["https://a.test/?token=[Filtered]"]'], 'list in a map')]
    #[DataSet([['r' => ['a' => 'https://a.test/?token=abc']], 'r={"a":"https://a.test/?token=[Filtered]"}'], 'map in a map')]
    #[DataSet([[['r', ['https://a.test/?token=abc']]], 'r=["https://a.test/?token=[Filtered]"]'], 'list in a pair')]
    public function testUrlsNestedInValuesOfAQueryMapAreFiltered(array $query, string $expected): void
    {
        $text = self::text(['request' => ['url' => 'https://shop.test/', 'query_string' => $query]]);

        Assert::string($text)
            ->contains("  Query: $expected\n")
            ->notContains('abc');
    }

    public function testQueryMapNestedPastTheDepthIsFilteredWhole(): void
    {
        $value = 'https://a.test/?token=abc';
        for ($i = 0; $i < 600; ++$i) {
            $value = [$value];
        }

        $text = self::text(['request' => ['url' => 'https://shop.test/', 'query_string' => ['r' => $value]]]);

        Assert::string($text)->contains('  Query: r=' . \str_repeat('[', 32) . '"[Filtered]"' . \str_repeat(']', 32) . "\n");
    }

    #[DataSet(["\t https://user:abc@shop.test/"], 'leading whitespace')]
    #[DataSet(['//user:abc@shop.test/'], 'protocol-relative')]
    #[DataSet([['https://user:abc@shop.test/']], 'list')]
    public function testPasswordOfAUrlInAnotherFormIsFiltered(string|array $url): void
    {
        $text = self::text(['request' => ['url' => $url, 'headers' => ['Referer' => $url]]]);

        Assert::string($text)
            ->matchesRegex('~^Request: .*//user:\[Filtered]@shop\.test/~m')
            ->matchesRegex('~^    Referer: .*//user:\[Filtered]@shop\.test/~m')
            ->notContains('abc');
    }

    public function testQueryParameterNamedWithAnEncodedSpaceIsFiltered(): void
    {
        $text = self::text(['request' => ['query_string' => 'api%20key=abc&api+key=abc', 'headers' => ['Api key' => 'abc']]]);

        Assert::string($text)
            ->contains("  Query: api%20key=[Filtered]&api+key=[Filtered]\n")
            ->notContains('abc');
    }

    public function testUrlsOfAListStayReadable(): void
    {
        $text = self::text(['request' => [
            'url' => ['https://shop.test/?token=abc&q=a%20b', 'https://shop.test/?q=a%20b'],
            'headers' => ['Referer' => ['https://shop.test/?token=abc&q=a%20b']],
        ]]);

        Assert::string($text)
            ->contains("Request: [\"https://shop.test/?token=[Filtered]&q=a%20b\",\"https://shop.test/?q=a%20b\"]\n")
            ->contains("    Referer: https://shop.test/?token=[Filtered]&q=a%20b\n")
            ->notContains('abc');
    }

    public function testUrlsOfAListNestedPastTheDepthAreFilteredWhole(): void
    {
        $value = 'https://a.test/?token=abc';
        for ($i = 0; $i < 600; ++$i) {
            $value = [$value];
        }

        $text = self::text(['request' => ['url' => $value, 'headers' => ['Referer' => $value]]]);

        $filtered = \str_repeat('[', 32) . '"[Filtered]"' . \str_repeat(']', 32);
        Assert::string($text)
            ->contains("Request: $filtered\n")
            ->contains("    Referer: $filtered\n")
            ->notContains('abc');
    }

    public function testHugeNestedListsAreBounded(): void
    {
        $list = \array_fill(0, 300_000, 'x');

        $start = \microtime(true);
        $text = self::text(['request' => [
            'url' => [$list],
            'query_string' => ['r' => [$list]],
            'headers' => ['Referer' => $list, 'Location' => [$list]],
        ]]);
        $seconds = \microtime(true) - $start;

        $shown = \str_repeat('"x",', 20) . '"… 299980 more"';
        Assert::string($text)
            ->contains("Request: [[$shown]]\n")
            ->contains("  Query: r=[[$shown]]\n")
            ->contains('    Referer: ' . \str_repeat('x, ', 20) . "… 299980 more\n")
            ->contains("    Location: [$shown]\n");
        Assert::numeric(\strlen($text))->lessThan(4096);
        Assert::numeric($seconds)->lessThan(1.0);
    }

    public function testTextWithTwoSlashesIsNotAUrlWithAPassword(): void
    {
        $text = self::text(['request' => [
            'url' => '// TODO: ping me@bob',
            'headers' => ['Referer' => '//u:abc@x/'],
        ]]);

        Assert::string($text)
            ->contains("Request: // TODO: ping me@bob\n")
            ->contains("    Referer: //u:[Filtered]@x/\n");
    }

    public function testTextWithSpacesAfterTwoSlashesIsNotAUrlWithAPassword(): void
    {
        $text = self::text(['request' => [
            'url' => '//TODO: ping me@bob',
            'headers' => ['Referer' => '//cdn.test:443 see ops@x'],
        ]]);

        Assert::string($text)
            ->contains("Request: //TODO: ping me@bob\n")
            ->contains("    Referer: //cdn.test:443 see ops@x\n");
    }

    public function testCountOfLeftOutEntriesKeepsAnEntryNamedLikeIt(): void
    {
        $map = ['…' => 'real'];
        for ($i = 1; $i <= 25; ++$i) {
            $map["k$i"] = 'v';
        }

        $text = self::text(['request' => ['query_string' => ['m' => $map]]]);

        Assert::string($text)
            ->contains('"…":"real"')
            ->contains('"……":"6 more"');
    }

    public function testEventThatFailsToRenderShowsNoPayload(): void
    {
        $payload = [
            'message' => 'Boom',
            'request' => ['url' => 'https://shop.test/?token=abc', 'headers' => ['Authorization' => 'Bearer abc']],
            'extra' => ['broken' => new class implements \JsonSerializable {
                #[\Override]
                public function jsonSerialize(): never
                {
                    throw new \RuntimeException('Not serializable');
                }
            }],
        ];

        $text = (new Trap())->get(EventViews::class)->text(new Record(self::event($payload), 1, [new SentryStore($payload)]));

        Assert::same($text, "Boom\n\nThe event could not be rendered: RuntimeException\n");
    }

    public function testEventThatFailsToSummarizeShowsOnlyTheError(): void
    {
        $text = self::text(['message' => new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): never
            {
                throw new \RuntimeException('Not serializable');
            }
        }]);

        Assert::same($text, "The event could not be rendered: RuntimeException\n");
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

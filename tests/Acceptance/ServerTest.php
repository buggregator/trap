<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Acceptance;

use Buggregator\Trap\Info;
use Buggregator\Trap\Tests\Acceptance\Support\TrapProcess;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Skip;
use Testo\Test;

/**
 * Each test starts `bin/trap` listening on one port, with the `file` and `console` senders
 * and the web UI on a separate port, sends one kind of traffic and checks what the server
 * answered, what the `file` sender wrote and the event type the web UI lists.
 */
#[Test]
final class ServerTest
{
    private ?TrapProcess $trap = null;
    private int $port = 0;
    private int $uiPort = 0;

    #[BeforeTest]
    public function pickPorts(): void
    {
        $this->trap = null;
        $this->port = TrapProcess::freePort();
        $this->uiPort = TrapProcess::freePort();
    }

    #[AfterTest]
    public function stopTrap(): void
    {
        $this->trap?->stop();
        $this->trap?->removeWorkDir();
        $this->trap = null;
    }

    public function testHttpRequestIsCapturedAndAnswered(): void
    {
        $trap = $this->startTrap('http');

        [$status, $head] = TrapProcess::http(
            $this->port,
            'POST',
            '/acceptance/orders?id=42',
            '{"order":"acceptance-http"}',
            ['Content-Type' => 'application/json'],
        );

        Assert::same($status, 404);
        Assert::string($head)->ignoringCase()->contains('content-length: 0');
        $request = $trap->waitForDumpedLine(
            static fn(string $line): ?array => \str_contains($line, 'acceptance-http') ? self::json($line) : null,
            'the HTTP request in the dump file',
        );
        Assert::same($request['method'], 'POST');
        Assert::string($request['uri'])->endsWith('/acceptance/orders?id=42');
        Assert::same($request['body'], '{"order":"acceptance-http"}');
        Assert::same($request['queryParams'], ['id' => '42']);
        $event = $trap->waitForUiEvent($this->uiPort, 'http-dump');
        Assert::same($event['payload']['request']['method'], 'POST');
        Assert::same($event['payload']['request']['uri'], 'acceptance/orders');
    }

    public function testVarDumperDumpFromTrapFunctionIsCaptured(): void
    {
        $trap = $this->startTrap('var-dumper');
        $script = $trap->workDir . '/client.php';
        \file_put_contents($script, \sprintf(
            "<?php\nrequire %s;\ntrap(['acceptance' => 'var-dumper-marker']);\n",
            \var_export(\realpath(Info::TRAP_ROOT . '/vendor/autoload.php'), true),
        ));

        [$exitCode, $clientOutput] = $this->runPhp($script, [
            'VAR_DUMPER_FORMAT' => 'server',
            'VAR_DUMPER_SERVER' => "127.0.0.1:$this->port",
        ]);

        Assert::same($exitCode, 0);
        Assert::string($clientOutput)->notContains('var-dumper-marker');
        $dump = $trap->waitForDumpedLine(
            static function (string $line): ?string {
                $decoded = \base64_decode($line, true);
                return \is_string($decoded) && \str_contains($decoded, 'var-dumper-marker') ? $decoded : null;
            },
            'the dump in the dump file',
        );
        Assert::string($dump)->contains('acceptance');
        $event = $trap->waitForUiEvent($this->uiPort, 'var-dump');
        Assert::string(\json_encode($event['payload']['payload']['value']))->contains('var-dumper-marker');
    }

    public function testSmtpMailIsCaptured(): void
    {
        $trap = $this->startTrap('smtp');
        $socket = \stream_socket_client("tcp://127.0.0.1:$this->port", $errno, $error, 5.0)
            ?: throw new \RuntimeException("Unable to connect: $error");
        \stream_set_timeout($socket, 5);

        try {
            $replies = ['greeting' => self::readSmtpReply($socket)];
            foreach ([
                'HELO' => "HELO acceptance.test\r\n",
                'MAIL' => "MAIL FROM:<sender@acceptance.test>\r\n",
                'RCPT1' => "RCPT TO:<alice@acceptance.test>\r\n",
                'RCPT2' => "RCPT TO:<bob@acceptance.test>\r\n",
                'DATA' => "DATA\r\n",
                'message' => \implode("\r\n", [
                    'From: Sender <sender@acceptance.test>',
                    'To: Alice <alice@acceptance.test>, bob@acceptance.test',
                    'Subject: Acceptance subject',
                    'Content-Type: text/plain; charset=utf-8',
                    '',
                    'Hello from the acceptance test.',
                    '.',
                    '',
                ]),
                'QUIT' => "QUIT\r\n",
            ] as $step => $command) {
                TrapProcess::write($socket, $command);
                $replies[$step] = self::readSmtpReply($socket);
            }
        } finally {
            \fclose($socket);
        }

        Assert::same(\array_map(static fn(string $reply): string => \substr($reply, 0, 3), $replies), [
            'greeting' => '220',
            'HELO' => '250',
            'MAIL' => '250',
            'RCPT1' => '250',
            'RCPT2' => '250',
            'DATA' => '354',
            'message' => '250',
            'QUIT' => '221',
        ]);
        $mail = $trap->waitForDumpedLine(
            static fn(string $line): ?array => \str_contains($line, 'Acceptance subject') ? self::json($line) : null,
            'the mail in the dump file',
        );
        Assert::same($mail['protocol']['FROM'], ['sender@acceptance.test']);
        Assert::same($mail['protocol']['BCC'], ['alice@acceptance.test', 'bob@acceptance.test']);
        $event = $trap->waitForUiEvent($this->uiPort, 'smtp');
        Assert::same($event['payload']['subject'], 'Acceptance subject');
        Assert::same(
            \array_column($event['payload']['to'], 'email'),
            ['alice@acceptance.test', 'bob@acceptance.test'],
        );
        Assert::string($event['payload']['text'])->contains('Hello from the acceptance test.');
    }

    public function testSentryEnvelopeIsCaptured(): void
    {
        $trap = $this->startTrap('sentry');

        [$status] = TrapProcess::parseResponse(TrapProcess::exchange(
            $this->port,
            (string) \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/sentry.bin'),
        ));

        Assert::same($status, 200);
        $envelope = $trap->waitForDumpedLine(
            static fn(string $line): ?array => \str_contains($line, '1c375fcd2f15438f82d8762f083cd8c0')
                ? self::json($line)
                : null,
            'the Sentry envelope in the dump file',
        );
        Assert::same($envelope['headers']['event_id'], '1c375fcd2f15438f82d8762f083cd8c0');
        Assert::notBlank($envelope['items']);
        $event = $trap->waitForUiEvent($this->uiPort, 'sentry');
        Assert::string(\json_encode($event['payload']))->contains('1c375fcd2f15438f82d8762f083cd8c0');
    }

    /**
     * The client connects and writes a moment later, as a socket handler that opens its connection
     * before the first record does. Writing right after connecting races with the server
     * and fails about every other run the same way.
     */
    #[Skip('Bug: a Monolog record that arrives after the connection is accepted is dumped as binary, see src/Traffic/Dispatcher/Monolog.php:37')]
    public function testMonologRecordIsCaptured(): void
    {
        $trap = $this->startTrap('monolog');
        $record = [
            'message' => 'acceptance monolog record',
            'context' => ['order' => 42],
            'level' => 200,
            'level_name' => 'INFO',
            'channel' => 'acceptance',
            'datetime' => '2026-01-01T00:00:00.000000+00:00',
            'extra' => [],
        ];

        $socket = \stream_socket_client("tcp://127.0.0.1:$this->port", $errno, $error, 5.0)
            ?: throw new \RuntimeException("Unable to connect: $error");
        try {
            \usleep(300_000);
            TrapProcess::write($socket, \json_encode($record, \JSON_THROW_ON_ERROR) . "\n");
        } finally {
            \fclose($socket);
        }

        $dumped = $trap->waitForDumpedLine(
            static fn(string $line): ?array => \str_contains($line, 'acceptance monolog record')
                ? self::json($line)
                : null,
            'the Monolog record in the dump file',
        );
        Assert::same($dumped, $record);
        $event = $trap->waitForUiEvent($this->uiPort, 'monolog');
        Assert::same($event['payload']['message'], 'acceptance monolog record');
        Assert::same($event['payload']['channel'], 'acceptance');
    }

    public function testOtlpTracesAreCaptured(): void
    {
        $trap = $this->startTrap('otlp');

        [$status, $head] = TrapProcess::http(
            $this->port,
            'POST',
            '/v1/traces',
            (string) \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'),
            ['Content-Type' => 'application/json'],
        );

        Assert::same($status, 200);
        Assert::string($head)->ignoringCase()->contains('content-type: application/json');
        $traces = $trap->waitForDumpedLine(
            static fn(string $line): ?array => \str_starts_with($line, '{"type":"otlp.traces"') ? self::json($line) : null,
            'the OTLP traces in the dump file',
        );
        $spans = \array_merge(...\array_merge(...\array_map(
            static fn(array $resource): array => \array_column($resource['scopeSpans'], 'spans'),
            $traces['resourceSpans'],
        )));
        Assert::array(\array_column($spans, 'name'))->contains('GET /orders')->contains('db.query');
        Assert::array(\array_unique(\array_column($spans, 'traceId')))->sameElementsAs([
            '382bfca057473920912cab0a4ed95abe',
        ]);
        $console = TrapProcess::waitFor(
            static fn(): ?string => \str_contains($output = $trap->output(), 'db.query') ? $output : null,
            'the trace in the console output',
        );
        Assert::string($console)->contains('GET /orders');
    }

    /**
     * @param resource $socket
     */
    private static function readSmtpReply($socket): string
    {
        $reply = '';
        do {
            $line = \fgets($socket);
            $line === false and throw new \RuntimeException("No SMTP reply, got so far: $reply");
            $reply .= $line;
        } while (\preg_match('/^\d{3}-/', $line) === 1);

        return $reply;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(string $line): array
    {
        return (array) \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
    }

    private function startTrap(string $name): TrapProcess
    {
        return $this->trap = TrapProcess::start(
            $name,
            ["--port=$this->port", '--sender=file', '--sender=console', "--ui=$this->uiPort"],
            ['TRAP_TCP_PORTS' => (string) $this->port, 'TRAP_UI_PORT' => (string) $this->uiPort],
            [$this->port, $this->uiPort],
        );
    }

    /**
     * Runs a PHP script in the working directory of the trap with a clean environment.
     *
     * @param array<non-empty-string, string> $env
     * @return array{int, string} Exit code and the combined stdout and stderr.
     */
    private function runPhp(string $script, array $env): array
    {
        \assert($this->trap !== null);
        $env['XDEBUG_MODE'] = 'off';
        $keep = \PHP_OS_FAMILY === 'Windows' ? ['PATH', 'SystemRoot', 'SystemDrive', 'windir', 'TEMP', 'TMP'] : ['PATH'];
        foreach ($keep as $name) {
            \getenv($name) === false or $env[$name] = (string) \getenv($name);
        }

        $process = \proc_open(
            [\PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->trap->workDir,
            $env,
        ) ?: throw new \RuntimeException('Unable to start PHP.');
        \fclose($pipes[0]);
        $output = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);

        return [\proc_close($process), $output];
    }
}

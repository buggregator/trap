<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Acceptance;

use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Tests\Acceptance\Support\TrapProcess;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
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

    public function testHttpsRequestIsCapturedOnThePlainPort(): void
    {
        \extension_loaded('openssl') or throw new SkipTest('TLS needs ext-openssl.');
        $trap = $this->startTrap('https');

        [$status] = TrapProcess::http($this->port, 'POST', '/acceptance/tls', '{"order":"acceptance-https"}', tls: true);
        [$plainStatus] = TrapProcess::http($this->port, 'POST', '/acceptance/plain', '{"order":"acceptance-plain"}');

        Assert::same([$status, $plainStatus], [404, 404]);
        $request = $trap->waitForDumpedLine(
            static fn(string $line): ?array => \str_contains($line, 'acceptance-https') ? self::json($line) : null,
            'the HTTPS request in the dump file',
        );
        Assert::same($request['body'], '{"order":"acceptance-https"}');
        Assert::string($trap->output())->contains('localhost.pem');
    }

    public function testUiIsServedOverHttps(): void
    {
        \extension_loaded('openssl') or throw new SkipTest('TLS needs ext-openssl.');
        $this->startTrap('https-ui');

        $response = TrapProcess::exchange(
            $this->uiPort,
            "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n",
            tls: true,
        );

        // Early hints go before the page
        Assert::string($response)->contains("HTTP/1.1 200 OK\r\n");
        Assert::string($response)->contains('<html');
    }

    public function testUiWebSocketUpgradesOverTls(): void
    {
        \extension_loaded('openssl') or throw new SkipTest('TLS needs ext-openssl.');
        $this->startTrap('wss-ui');

        $context = \stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $socket = \stream_socket_client("tls://127.0.0.1:$this->uiPort", $errno, $error, 5, context: $context)
            ?: throw new \RuntimeException("Unable to connect over TLS: $error");
        try {
            \stream_set_timeout($socket, 5);
            TrapProcess::write($socket, \implode("\r\n", [
                'GET /connection/websocket HTTP/1.1',
                "Host: 127.0.0.1:$this->uiPort",
                'Upgrade: websocket',
                'Connection: Upgrade',
                'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==',
                'Sec-WebSocket-Version: 13',
                '',
                '',
            ]));

            $head = '';
            while (!\str_contains($head, "\r\n\r\n") && !\feof($socket)) {
                $chunk = \fread($socket, 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $head .= $chunk;
            }
        } finally {
            \fclose($socket);
        }

        Assert::string($head)->startsWith("HTTP/1.1 101 Switching Protocols\r\n");
        // The accept value of the key from RFC 6455
        Assert::string($head)->contains('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=');
    }

    public function testUiOnTrafficPortServesUiAndCapturesRequests(): void
    {
        $trap = $this->trap = TrapProcess::start(
            'shared-ui',
            ["--port=$this->port", '--sender=file', "--ui=$this->port"],
            ['TRAP_TCP_PORTS' => (string) $this->port, 'TRAP_UI_PORT' => (string) $this->port],
            [$this->port],
        );

        [$status] = TrapProcess::http($this->port, 'POST', '/acceptance/shared', '{"order":"acceptance-shared"}');

        Assert::same($status, 404);
        $event = $trap->waitForUiEvent($this->port, 'http-dump');
        Assert::same($event['payload']['request']['uri'], 'acceptance/shared');
    }

    public function testMcpServerSharesPortWithUi(): void
    {
        $this->trap = TrapProcess::start(
            'mcp',
            ["--port=$this->port", '--sender=file', "--ui=$this->uiPort", "--mcp=$this->uiPort"],
            [
                'TRAP_TCP_PORTS' => (string) $this->port,
                'TRAP_UI_PORT' => (string) $this->uiPort,
                'TRAP_MCP_PORT' => (string) $this->uiPort,
                // No MCP host: the server takes the UI host
                'TRAP_MCP_HOST' => null,
            ],
            [$this->port, $this->uiPort],
        );

        [$status, $head, $body] = TrapProcess::http(
            $this->uiPort,
            'POST',
            '/mcp',
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'],
        );
        [$notificationStatus] = TrapProcess::http(
            $this->uiPort,
            'POST',
            '/mcp',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
        );
        [$uiStatus] = TrapProcess::http($this->uiPort, 'GET', '/api/events');

        Assert::same($status, 200);
        Assert::string($head)->ignoringCase()->contains('content-type: application/json');
        Assert::same(self::json($body)['result']['protocolVersion'], '2025-06-18');
        Assert::same($notificationStatus, 202);
        Assert::same($uiStatus, 200);
    }

    public function testMcpServerWithTokenAnswersOnlyRequestsCarryingIt(): void
    {
        $trap = $this->trap = TrapProcess::start(
            'mcp-token',
            ["--port=$this->port", '-s', "--mcp=$this->uiPort"],
            [
                'TRAP_TCP_PORTS' => (string) $this->port,
                'TRAP_MCP_PORT' => (string) $this->uiPort,
                'TRAP_MCP_TOKEN' => 'acceptance-s3cr3t',
            ],
            [$this->port, $this->uiPort],
        );
        $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

        [$anonymousStatus, $anonymousHead, $anonymousBody] = TrapProcess::http($this->uiPort, 'POST', '/mcp', $ping);
        [$wrongStatus] = TrapProcess::http($this->uiPort, 'POST', '/mcp', $ping, ['Authorization' => 'Bearer wrong']);
        [$status, , $body] = TrapProcess::http(
            $this->uiPort,
            'POST',
            '/mcp',
            $ping,
            ['Authorization' => 'Bearer acceptance-s3cr3t'],
        );

        Assert::same($anonymousStatus, 401);
        Assert::string($anonymousHead)->ignoringCase()->contains('www-authenticate: bearer');
        Assert::string($anonymousBody)->notContains('acceptance-s3cr3t');
        Assert::same($wrongStatus, 401);
        Assert::same($status, 200);
        Assert::same(self::json($body)['result'], []);
        Assert::string($trap->output())->notContains('acceptance-s3cr3t')->notContains('without a token');
    }

    /**
     * On a traffic port shared with the UI, the token guards `/mcp` only, and a request carrying it to
     * another path is never dumped.
     */
    public function testMcpTokenOnPortSharedWithUiAndTraffic(): void
    {
        $trap = $this->trap = TrapProcess::start(
            'mcp-token-shared',
            ["--port=$this->port", '--sender=file', '--sender=console', "--ui=$this->port", "--mcp=$this->port"],
            [
                'TRAP_TCP_PORTS' => (string) $this->port,
                'TRAP_UI_PORT' => (string) $this->port,
                'TRAP_MCP_PORT' => (string) $this->port,
                'TRAP_MCP_TOKEN' => 'acceptance-s3cr3t',
            ],
            [$this->port],
        );
        $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
        $token = ['Authorization' => 'Bearer acceptance-s3cr3t'];

        [$uiStatus] = TrapProcess::http($this->port, 'GET', '/api/events');
        [$anonymousStatus] = TrapProcess::http($this->port, 'POST', '/mcp', $ping);
        [$mistypedStatus] = TrapProcess::http($this->port, 'POST', '//mcp', $ping, $token);
        [$wrongPathStatus] = TrapProcess::http($this->port, 'POST', '/acceptance/token', $ping, $token);
        TrapProcess::http($this->port, 'POST', '/acceptance/after', '{"order":"acceptance-after"}');

        Assert::same($uiStatus, 200);
        Assert::same($anonymousStatus, 401);
        Assert::same($mistypedStatus, 200);
        Assert::same($wrongPathStatus, 404);
        $event = $trap->waitForUiEvent($this->port, 'http-dump');
        Assert::same($event['payload']['request']['uri'], 'acceptance/after');
        [, , $events] = TrapProcess::http($this->port, 'GET', '/api/events');
        Assert::string($events)->notContains('acceptance-s3cr3t');
        Assert::string(\implode("\n", $trap->dumpedLines()))->notContains('acceptance-s3cr3t');
        Assert::string($trap->output())->notContains('acceptance-s3cr3t');
    }

    /**
     * On a traffic port other than the MCP one, no request carrying the token is dumped, while
     * the traffic of every module is caught as before.
     */
    public function testMcpTokenIsNotDumpedOnTrafficPort(): void
    {
        $trap = $this->trap = TrapProcess::start(
            'mcp-token-traffic',
            [
                "--port=$this->port",
                '--sender=file',
                '--sender=console',
                "--ui=$this->uiPort",
                "--mcp=$this->uiPort",
            ],
            [
                'TRAP_TCP_PORTS' => (string) $this->port,
                'TRAP_UI_PORT' => (string) $this->uiPort,
                'TRAP_MCP_PORT' => (string) $this->uiPort,
                'TRAP_MCP_TOKEN' => 'acceptance-s3cr3t',
            ],
            [$this->port, $this->uiPort],
        );
        $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
        $json = ['Content-Type' => 'application/json'];

        $mcp = TrapProcess::http($this->port, 'POST', '/mcp', $ping, ['Authorization' => 'Bearer acceptance-s3cr3t']);
        $query = TrapProcess::http($this->port, 'GET', '/x?token=acceptance-s3cr3t');
        $header = TrapProcess::http($this->port, 'GET', '/x', '', ['X-Api-Key' => 'acceptance-s3cr3t']);
        TrapProcess::http($this->port, 'POST', '/acceptance/plain', '{"order":"acceptance-plain"}', $json);
        [$sentryStatus] = TrapProcess::http(
            $this->port,
            'POST',
            '/api/1/store/',
            '{"event_id":"acceptance-sentry","level":"error","message":"acceptance sentry"}',
            [...$json, 'X-Sentry-Auth' => 'Sentry sentry_key=acceptance'],
        );
        [$rayStatus] = TrapProcess::http(
            $this->port,
            'POST',
            '/',
            '{"uuid":"acceptance-ray","payloads":[{"type":"log","content":{"values":["acceptance ray"]},"origin":null}],"meta":[]}',
            [...$json, 'User-Agent' => 'Ray 1.0'],
        );
        [$profileStatus] = TrapProcess::http(
            $this->port,
            'POST',
            '/api/profiler/store',
            (string) \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/xhprof/app.json'),
            $json,
        );
        [$otlpStatus] = TrapProcess::http(
            $this->port,
            'POST',
            '/v1/traces',
            (string) \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'),
            $json,
        );

        foreach ([$mcp, $query, $header] as [$status, , $body]) {
            Assert::same([$status, $body], [404, 'Not found.']);
        }
        Assert::same([$sentryStatus, $rayStatus, $profileStatus, $otlpStatus], [200, 200, 200, 200]);
        foreach (['sentry', 'ray', 'profiler'] as $type) {
            $trap->waitForUiEvent($this->uiPort, $type);
        }
        $dump = $trap->waitForUiEvent($this->uiPort, 'http-dump');
        Assert::same($dump['payload']['request']['uri'], 'acceptance/plain');
        $trap->waitForDumpedLine(
            static fn(string $line): ?bool => \str_starts_with($line, '{"type":"otlp.traces"') ?: null,
            'the OTLP traces in the dump file',
        );
        [, , $events] = TrapProcess::http($this->uiPort, 'GET', '/api/events');
        Assert::count(\array_keys(\array_column(self::json($events)['data'], 'type'), 'http-dump'), 1);
        Assert::string($events)->notContains('acceptance-s3cr3t');
        Assert::string(\implode("\n", $trap->dumpedLines()))->notContains('acceptance-s3cr3t');
        Assert::string($trap->output())->notContains('acceptance-s3cr3t');
    }

    /**
     * Without the web UI: the MCP server alone has the caught events kept for its tools.
     */
    public function testMcpToolsReadCaughtEvents(): void
    {
        $trap = $this->trap = TrapProcess::start(
            'mcp-tools',
            ["--port=$this->port", '--sender=file', "--mcp=$this->uiPort"],
            [
                'TRAP_TCP_PORTS' => (string) $this->port,
                'TRAP_MCP_PORT' => (string) $this->uiPort,
                // No MCP host: a port of its own listens on the default one
                'TRAP_MCP_HOST' => null,
            ],
            [$this->port, $this->uiPort],
        );
        $script = $trap->workDir . '/client.php';
        \file_put_contents($script, \sprintf(
            "<?php\nrequire %s;\ntrap(['acceptance' => 'mcp-dump-marker']);\n",
            \var_export(\realpath(Info::TRAP_ROOT . '/vendor/autoload.php'), true),
        ));

        $cursor = $this->callMcpTool('list_events', [])['cursor'];
        TrapProcess::http($this->port, 'POST', '/acceptance/mcp-http', '{"order":"mcp-http-marker"}');
        [$exitCode] = $this->runPhp($script, [
            'VAR_DUMPER_FORMAT' => 'server',
            'VAR_DUMPER_SERVER' => "127.0.0.1:$this->port",
        ]);
        $events = TrapProcess::waitFor(function () use ($cursor): ?array {
            $events = $this->callMcpTool('list_events', ['since' => $cursor])['events'];
            return \count($events) >= 2 ? \array_column($events, null, 'type') : null;
        }, 'two events listed by the MCP server');
        $dump = $this->callMcpTool('get_event', ['id' => $events['var-dump']['id']]);
        $request = $this->callMcpTool('get_event', ['id' => $events['http-dump']['id']]);
        $stale = $this->callMcpTool('list_events', ['since' => 'stale:1'])['events'];
        // The marker is in the request body: in the text of the event, not in its summary
        $found = $this->callMcpTool('list_events', ['search' => 'MCP-HTTP-MARKER', 'include' => 'text'])['events'];
        $dumps = $this->callMcpTool('list_events', ['search' => 'mcp-dump-marker', 'from' => '5m'])['events'];
        $posts = $this->callMcpTool('list_events', ['where' => ['method' => 'post', 'path' => '/acceptance/*']])['events'];

        Assert::same($exitCode, 0);
        Assert::string($cursor)->matchesRegex('/^[0-9a-f]{8}:0$/');
        // A cursor of another Trap process: all the events, the first ones of this process too
        Assert::count($stale, 2);
        Assert::same($events['http-dump']['summary'], 'POST /acceptance/mcp-http');
        Assert::string($events['var-dump']['summary'])->contains('mcp-dump-marker');
        Assert::string($dump)->startsWith('var-dump event ')->contains('"acceptance" => "mcp-dump-marker"')
            ->notContains("\e[");
        Assert::string($request)->contains('/acceptance/mcp-http')->contains('{"order":"mcp-http-marker"}');
        Assert::same(\array_column($found, 'type'), ['http-dump']);
        Assert::string($found[0]['text'])->contains('{"order":"mcp-http-marker"}');
        Assert::same(\array_column($dumps, 'id'), [$events['var-dump']['id']]);
        Assert::same(\array_column($posts, 'id'), [$events['http-dump']['id']]);
        Assert::same($posts[0]['fields']['path'], '/acceptance/mcp-http');
    }

    /**
     * A wait holds its connection open while the server answers other calls and catches the dump that ends it.
     */
    public function testMcpWaitReturnsEventSentWhileWaiting(): void
    {
        $trap = $this->trap = TrapProcess::start(
            'mcp-wait',
            ["--port=$this->port", '--sender=file', "--mcp=$this->uiPort"],
            [
                'TRAP_TCP_PORTS' => (string) $this->port,
                'TRAP_MCP_PORT' => (string) $this->uiPort,
                'TRAP_MCP_HOST' => null,
            ],
            [$this->port, $this->uiPort],
        );
        $script = $trap->workDir . '/client.php';
        \file_put_contents($script, \sprintf(
            "<?php\nrequire %s;\ntrap(['acceptance' => 'mcp-wait-marker']);\n",
            \var_export(\realpath(Info::TRAP_ROOT . '/vendor/autoload.php'), true),
        ));
        $cursor = $this->callMcpTool('list_events', [])['cursor'];
        $body = self::toolCall('wait_for_events', ['since' => $cursor, 'type' => 'var-dump', 'timeout' => 20]);
        $socket = \stream_socket_client("tcp://127.0.0.1:$this->uiPort", $errno, $error, 5)
            ?: throw new \RuntimeException("Unable to connect to port $this->uiPort: $error");

        TrapProcess::write($socket, \sprintf(
            "POST /mcp HTTP/1.1\r\nHost: 127.0.0.1:%d\r\nConnection: close\r\nContent-Type: application/json\r\n"
            . "Content-Length: %d\r\n\r\n%s",
            $this->uiPort,
            \strlen($body),
            $body,
        ));
        $probe = $this->callMcpTool('list_events', []);
        $read = [$socket];
        $none = null;
        $answeredEarly = \stream_select($read, $none, $none, 0) === 1;
        [$exitCode] = $this->runPhp($script, [
            'VAR_DUMPER_FORMAT' => 'server',
            'VAR_DUMPER_SERVER' => "127.0.0.1:$this->port",
        ]);
        \stream_set_timeout($socket, 15);
        $response = (string) \stream_get_contents($socket);
        \fclose($socket);
        [$status, , $answer] = TrapProcess::parseResponse($response);
        $result = self::toolResult($status, $answer);

        Assert::same($probe['events'], [], 'Other calls are answered while the wait goes on.');
        Assert::false($answeredEarly);
        Assert::same($exitCode, 0);
        Assert::false($result['timed_out']);
        Assert::same(\array_column($result['events'], 'type'), ['var-dump']);
        Assert::string($result['events'][0]['summary'])->contains('mcp-wait-marker');
    }

    /**
     * A client gone in the middle of a wait, as one cancelling the call, leaves the server answering
     * and catching events.
     */
    public function testMcpWaitOfClosedConnectionLeavesServerResponsive(): void
    {
        $this->trap = TrapProcess::start(
            'mcp-wait-closed',
            ["--port=$this->port", '--sender=file', "--mcp=$this->uiPort"],
            [
                'TRAP_TCP_PORTS' => (string) $this->port,
                'TRAP_MCP_PORT' => (string) $this->uiPort,
                'TRAP_MCP_HOST' => null,
            ],
            [$this->port, $this->uiPort],
        );
        $cursor = $this->callMcpTool('list_events', [])['cursor'];
        $body = self::toolCall('wait_for_events', ['since' => $cursor, 'timeout' => 20]);
        $socket = \stream_socket_client("tcp://127.0.0.1:$this->uiPort", $errno, $error, 5)
            ?: throw new \RuntimeException("Unable to connect to port $this->uiPort: $error");

        TrapProcess::write($socket, \sprintf(
            "POST /mcp HTTP/1.1\r\nHost: 127.0.0.1:%d\r\nContent-Type: application/json\r\n"
            . "Content-Length: %d\r\n\r\n%s",
            $this->uiPort,
            \strlen($body),
            $body,
        ));
        $read = [$socket];
        $none = null;
        $answered = \stream_select($read, $none, $none, 0, 300_000) === 1;
        \fclose($socket);
        // The server goes around its loop until the request is caught, seeing the closed connection meanwhile
        [$status] = TrapProcess::http($this->port, 'POST', '/acceptance/after-close', '{"order":"after-close"}');
        $events = TrapProcess::waitFor(
            fn(): ?array => $this->callMcpTool('list_events', ['since' => $cursor])['events'] ?: null,
            'the request sent after the closed wait',
        );
        $started = \microtime(true);
        $next = $this->callMcpTool('wait_for_events', ['since' => $cursor]);
        $elapsed = \microtime(true) - $started;

        Assert::false($answered, 'The wait goes on until the client closes the connection.');
        Assert::same($status, 404);
        Assert::same(\array_column($events, 'summary'), ['POST /acceptance/after-close']);
        Assert::same(\array_column($next['events'], 'id'), \array_column($events, 'id'));
        Assert::numeric($elapsed)->lessThan(5.0);
    }

    /**
     * Without the console, which renders traces: the received spans are kept for the tools anyway.
     */
    public function testMcpToolsReadReceivedTraces(): void
    {
        $this->trap = TrapProcess::start(
            'mcp-traces',
            ["--port=$this->port", '-s', "--mcp=$this->uiPort"],
            [
                'TRAP_TCP_PORTS' => (string) $this->port,
                'TRAP_MCP_PORT' => (string) $this->uiPort,
                'TRAP_MCP_HOST' => null,
            ],
            [$this->port, $this->uiPort],
        );

        $cursor = $this->callMcpTool('list_traces', [])['cursor'];
        [$status] = TrapProcess::http(
            $this->port,
            'POST',
            '/v1/traces',
            (string) \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'),
            ['Content-Type' => 'application/json'],
        );
        $traces = TrapProcess::waitFor(
            fn(): ?array => $this->callMcpTool('list_traces', ['since' => $cursor])['traces'] ?: null,
            'the trace listed by the MCP server',
        );
        $tree = $this->callMcpTool('get_trace', ['id' => $traces[0]['id']]);

        Assert::same($status, 200);
        Assert::same(\array_diff_key($traces[0], ['updated' => true]), [
            'id' => '382bfca057473920912cab0a4ed95abe',
            'name' => 'GET /orders',
            'service' => 'e2e-app',
            'spans' => 4,
            'duration_ms' => 27.691,
            'error' => true,
        ]);
        Assert::string($tree)->startsWith('trace 382bfca057473920912cab0a4ed95abe, 4 spans, service e2e-app')
            ->contains("GET /orders SERVER 27.69 ms OK id:286e73f2056fe20e\n")
            ->contains('db.query CLIENT 11.82 ms ERROR: Connection lost')
            ->notContains("\e[");
    }

    public function testMcpShowsAndDeletesSentryEvent(): void
    {
        $this->trap = TrapProcess::start(
            'mcp-sentry',
            ["--port=$this->port", '--sender=file', "--mcp=$this->uiPort"],
            [
                'TRAP_TCP_PORTS' => (string) $this->port,
                'TRAP_MCP_PORT' => (string) $this->uiPort,
                'TRAP_MCP_HOST' => null,
            ],
            [$this->port, $this->uiPort],
        );
        $frame = [
            'filename' => 'src/Checkout.php',
            'lineno' => 42,
            'function' => 'App\\Checkout::pay',
            'in_app' => true,
            'pre_context' => ['    {'],
            'context_line' => '        $gateway->charge($order);',
            'post_context' => ['    }'],
        ];
        $event = [
            'event_id' => 'acceptance-sentry',
            'level' => 'error',
            'platform' => 'php',
            'exception' => ['values' => [
                ['type' => 'PDOException', 'value' => 'gone away', 'stacktrace' => ['frames' => [$frame]]],
                ['type' => 'App\\PaymentFailed', 'value' => 'Payment declined', 'stacktrace' => ['frames' => [$frame]]],
            ]],
        ];

        $cursor = $this->callMcpTool('list_events', [])['cursor'];
        [$status] = TrapProcess::http(
            $this->port,
            'POST',
            '/api/1/store/',
            \json_encode($event, \JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json', 'X-Sentry-Auth' => 'Sentry sentry_key=acceptance'],
        );
        $events = TrapProcess::waitFor(
            fn(): ?array => $this->callMcpTool('list_events', ['since' => $cursor, 'type' => 'sentry'])['events'] ?: null,
            'the Sentry event listed by the MCP server',
        );
        $text = $this->callMcpTool('get_event', ['id' => $events[0]['id']]);
        $deleted = $this->callMcpTool('delete_event', ['id' => $events[0]['id']]);
        $left = $this->callMcpTool('list_events', ['type' => 'sentry'])['events'];

        Assert::same($status, 200);
        Assert::same($events[0]['summary'], 'App\\PaymentFailed: Payment declined');
        Assert::same($events[0]['fields']['exception'], ['PDOException', 'App\\PaymentFailed']);
        Assert::string($text)
            ->contains("App\\PaymentFailed: Payment declined\n  #0 src/Checkout.php:42 in App\\Checkout::pay\n")
            ->contains("     41 | {\n   > 42 |     \$gateway->charge(\$order);\n     43 | }\n")
            ->contains('Caused by PDOException: gone away')
            ->notContains("\e[");
        Assert::same($deleted, ['deleted' => 1]);
        Assert::same($left, []);
    }

    public function testMcpToolsAnalyzeReceivedProfile(): void
    {
        $this->trap = TrapProcess::start(
            'mcp-profile',
            ["--port=$this->port", '--sender=file', "--mcp=$this->uiPort"],
            [
                'TRAP_TCP_PORTS' => (string) $this->port,
                'TRAP_MCP_PORT' => (string) $this->uiPort,
                'TRAP_MCP_HOST' => null,
            ],
            [$this->port, $this->uiPort],
        );

        $cursor = $this->callMcpTool('list_events', [])['cursor'];
        [$status] = TrapProcess::http(
            $this->port,
            'POST',
            '/api/profiler/store',
            (string) \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/xhprof/app.json'),
            ['Content-Type' => 'application/json'],
        );
        $events = TrapProcess::waitFor(
            fn(): ?array => $this->callMcpTool('list_events', ['since' => $cursor, 'type' => 'profiler'])['events'] ?: null,
            'the profile listed by the MCP server',
        );
        $summary = $this->callMcpTool('profile_summary', ['id' => $events[0]['id']]);
        $top = $this->callMcpTool('profile_top', ['id' => $events[0]['id'], 'metric' => 'cpu', 'limit' => 3]);
        $calls = $this->callMcpTool('profile_calls', ['id' => $events[0]['id'], 'function' => 'main()']);

        Assert::same($status, 200);
        Assert::same(
            [$summary['format'], $summary['functions'], $summary['edges'], $summary['peaks']['wt'], $summary['metric']],
            ['XHProf', 582, 2223, 45310, 'wt'],
        );
        Assert::same($summary['hot_path'][0]['function'], 'main()');
        Assert::count($summary['top'], 10);
        Assert::same([$top['metric'], $top['matched'], $top['has_more']], ['cpu', 582, true]);
        Assert::count($top['functions'], 3);
        Assert::true($calls['root']);
        Assert::notBlank($calls['callees']);
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

    /**
     * Mail data ends with the dot line only: right after the closing boundary, as Python smtplib sends it,
     * and not at a closing boundary followed by an empty line.
     */
    public function testSmtpMultipartMailEndsAtDotLine(): void
    {
        $trap = $this->startTrap('smtp-multipart');
        $socket = \stream_socket_client("tcp://127.0.0.1:$this->port", $errno, $error, 5.0)
            ?: throw new \RuntimeException("Unable to connect: $error");
        \stream_set_timeout($socket, 5);

        try {
            $replies = ['greeting' => self::readSmtpReply($socket)];
            foreach ([
                'EHLO' => "EHLO acceptance.test\r\n",
                'MAIL' => "MAIL FROM:<sender@acceptance.test>\r\n",
                'RCPT' => "RCPT TO:<alice@acceptance.test>\r\n",
                'DATA' => "DATA\r\n",
                'smtplib' => self::multipartMail('Dot after the boundary') . ".\r\n",
                'MAIL2' => "MAIL FROM:<sender@acceptance.test>\r\n",
                'RCPT2' => "RCPT TO:<alice@acceptance.test>\r\n",
                'DATA2' => "DATA\r\n",
            ] as $step => $command) {
                TrapProcess::write($socket, $command);
                $replies[$step] = self::readSmtpReply($socket);
            }

            TrapProcess::write($socket, self::multipartMail('Dot in a later packet') . "\r\n");
            \usleep(300_000);
            \stream_set_blocking($socket, false);
            $early = (string) \fread($socket, 1024);
            \stream_set_blocking($socket, true);
            TrapProcess::write($socket, ".\r\n");
            $replies['later dot'] = self::readSmtpReply($socket);
            TrapProcess::write($socket, "QUIT\r\n");
            $replies['QUIT'] = self::readSmtpReply($socket);
        } finally {
            \fclose($socket);
        }

        Assert::same($early, '', 'The server has replied before the end of data.');
        Assert::same(\array_map(static fn(string $reply): string => \substr($reply, 0, 3), $replies), [
            'greeting' => '220',
            'EHLO' => '250',
            'MAIL' => '250',
            'RCPT' => '250',
            'DATA' => '354',
            'smtplib' => '250',
            'MAIL2' => '250',
            'RCPT2' => '250',
            'DATA2' => '354',
            'later dot' => '250',
            'QUIT' => '221',
        ]);
        foreach (['Dot after the boundary', 'Dot in a later packet'] as $subject) {
            $mail = $trap->waitForDumpedLine(
                static fn(string $line): ?array => \str_contains($line, $subject) ? self::json($line) : null,
                "the mail `$subject` in the dump file",
            );
            Assert::same(\array_column($mail['messages'], 'value'), ['Plain text', '<p>HTML text</p>']);
        }
    }

    public function testMailsOfTestCommandAreCaptured(): void
    {
        $trap = $this->startTrap('smtp-test-command');

        [$exitCode, $clientOutput] = TrapProcess::run('smtp-test-command-client', ['test', "--port=$this->port"], timeout: 30.0);

        Assert::same($exitCode, 0, $clientOutput);
        Assert::string($clientOutput)->notContains('Disconnected')->notContains('Mail protocol error');
        $multipart = $trap->waitForDumpedLine(
            static fn(string $line): ?array => \str_contains($line, 'Multipart Email Example') ? self::json($line) : null,
            'the multipart mail in the dump file',
        );
        Assert::string($multipart['messages'][0]['value'])->startsWith('Plain text email goes here!');
        $trap->waitForDumpedLine(
            static fn(string $line): ?bool => \str_contains($line, 'Very important theme!') ? true : null,
            'the plain mail in the dump file',
        );
    }

    /**
     * One run of `trap test` gives every page of the web UI data: each event type of the feed, the Sentry
     * traces, logs and metrics, and a second project.
     */
    public function testTestCommandFillsEveryPageOfTheUi(): void
    {
        $this->startTrap('test-command');

        [$exitCode, $clientOutput] = TrapProcess::run('test-command-client', ['test', "--port=$this->port"], timeout: 30.0);

        Assert::same($exitCode, 0, $clientOutput);
        Assert::string($clientOutput)->notContains('sending error');
        $types = TrapProcess::waitFor(function (): ?array {
            [, , $body] = TrapProcess::http($this->uiPort, 'GET', '/api/events');
            $types = \array_unique(\array_column(self::json($body)['data'], 'type'));
            return \count($types) >= 8 ? $types : null;
        }, 'events of every type in the web UI');
        Assert::array($types)->sameElementsAs([
            'profiler', 'xdebug-trace', 'var-dump', 'smtp', 'monolog', 'ray', 'http-dump', 'sentry',
        ]);

        [, , $projects] = TrapProcess::http($this->uiPort, 'GET', '/api/projects');
        Assert::same(\array_column(self::json($projects)['data'], 'key'), ['default', 'billing']);
        foreach (['traces', 'logs', 'metrics'] as $page) {
            [$status, , $body] = TrapProcess::http($this->uiPort, 'GET', "/api/sentry/$page?project=default");
            Assert::same($status, 200);
            Assert::notBlank(self::json($body)['data'], "No Sentry $page");
        }
        [, , $map] = TrapProcess::http($this->uiPort, 'GET', '/api/sentry/service-map?project=default');
        Assert::string($map)->contains('"gateway"')->contains('"orders"');
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
     * A multipart/alternative message up to its closing boundary, with a boundary as Python's email package makes.
     */
    private static function multipartMail(string $subject): string
    {
        return \implode("\r\n", [
            "Subject: $subject",
            'From: sender@acceptance.test',
            'To: alice@acceptance.test',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="===============0123456789012345678=="',
            '',
            '--===============0123456789012345678==',
            'Content-Type: text/plain; charset="utf-8"',
            '',
            'Plain text',
            '--===============0123456789012345678==',
            'Content-Type: text/html; charset="utf-8"',
            '',
            '<p>HTML text</p>',
            '--===============0123456789012345678==--',
            '',
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(string $line): array
    {
        return (array) \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function toolCall(string $name, array $arguments): string
    {
        return \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $arguments],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<array-key, mixed>|string
     */
    private static function toolResult(int $status, string $body): array|string
    {
        Assert::same($status, 200);
        $result = self::json($body)['result'];
        Assert::false($result['isError']);
        $text = $result['content'][0]['text'];
        $json = \json_decode($text, true);
        // An object result comes structured too, for clients that read no text
        Assert::same($result['structuredContent'] ?? null, \is_array($json) ? $json : null);

        return \is_array($json) ? $json : $text;
    }

    /**
     * Calls a tool of the MCP server on the UI port.
     *
     * @return array<array-key, mixed>|string The decoded JSON result, or the text when it is not JSON.
     */
    private function callMcpTool(string $name, array $arguments): array|string
    {
        [$status, , $body] = TrapProcess::http(
            $this->uiPort,
            'POST',
            '/mcp',
            self::toolCall($name, $arguments),
            ['Content-Type' => 'application/json'],
        );

        return self::toolResult($status, $body);
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

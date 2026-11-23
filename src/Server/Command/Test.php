<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Command;

use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Support\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run application
 *
 * @internal
 */
#[AsCommand(
    name: 'test',
    description: 'Send test data',
)]
final class Test extends Command
{
    private const PAYLOADS = Info::TRAP_ROOT . '/resources/payloads/';

    private string $addr = '127.0.0.1';
    private int $port = 9912;
    private bool $failed = false;

    /** @psalm-suppress PropertyNotSetInConstructor */
    private Logger $logger;

    #[\Override]
    public function configure(): void
    {
        $this->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port of the Trap server', (string) $this->port);
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host of the Trap server', $this->addr);
    }

    #[\Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->logger = new Logger($output);

        $port = \filter_var($input->getOption('port'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            $output->writeln(\sprintf(
                '<error>Invalid port `%s`. It must be in range 1-65535.</error>',
                (string) $input->getOption('port'),
            ));
            return Command::INVALID;
        }

        $this->port = $port;
        $this->addr = (string) $input->getOption('host');

        $this->profiles();
        $this->xdebugTrace();
        $this->dump();
        \usleep(100_000);
        $this->mail($output, true);
        \usleep(100_000);
        $this->mail($output, false);
        \usleep(100_000);
        $this->monolog();
        $this->ray();
        $this->httpDump();
        $this->sentry();
        $this->crons();
        $this->openTelemetry();
        $this->csp();
        \usleep(100_000);
        $this->sendContent('logo.png');

        return $this->failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param resource $socket
     */
    private static function write(mixed $socket, string $data): void
    {
        while ($data !== '') {
            $written = @\fwrite($socket, $data);
            $written === false || $written === 0 and throw new \RuntimeException('Cannot write to the socket.');
            $data = \substr($data, $written);
        }
    }

    /**
     * @param non-empty-string $file File from the {@link resources/payloads} directory
     */
    private static function read(string $file): string
    {
        $content = @\file_get_contents(self::PAYLOADS . $file);
        return $content === false ? throw new \RuntimeException("Cannot read the payload `$file`.") : $content;
    }

    /**
     * A JSON payload of the {@link resources/payloads} directory with placeholders that give each run its own
     * times and IDs. Times are seconds relative to the moment the payload is read:
     * - `"{{ts -1.5}}"`, quotes included, becomes Unix time as a number;
     * - `{{ns -1.5}}` Unix time in nanoseconds, `{{iso -1.5}}` an RFC 3339 time;
     * - `{{hex32 name}}` and `{{uuid name}}` an ID, the same for the same name while `$ids` is shared.
     *
     * Objects stay objects, so `{}` is sent as `{}`.
     *
     * @param non-empty-string $file
     * @param array<string, string> $ids
     * @return array<array-key, mixed> The decoded list.
     */
    private static function template(string $file, array &$ids = []): array
    {
        $now = \microtime(true);
        $json = \preg_replace_callback(
            '/"\{\{ts ([-+]?[\d.]+)}}"|\{\{(ns|iso) ([-+]?[\d.]+)}}|\{\{(hex32|uuid) (\w+)}}/',
            static function (array $match) use ($now, &$ids): string {
                if ($match[1] !== '') {
                    return \sprintf('%.6F', $now + (float) $match[1]);
                }

                if ($match[2] !== '') {
                    $time = \sprintf('%.6F', $now + (float) $match[3]);
                    return $match[2] === 'ns'
                        ? \str_replace('.', '', $time) . '000'
                        : \gmdate('Y-m-d\TH:i:s.', (int) $time) . \substr($time, -6) . '+00:00';
                }

                return $ids["$match[4] $match[5]"] ??= $match[4] === 'uuid'
                    ? Uuid::uuid4()
                    : \bin2hex(\random_bytes(16));
            },
            self::read($file),
        );

        /** @var mixed $data */
        $data = \json_decode((string) $json, false, 512, \JSON_THROW_ON_ERROR);
        return \is_array($data) ? $data : throw new \RuntimeException("The payload `$file` is not a JSON list.");
    }

    private static function json(mixed $value): string
    {
        return \json_encode(
            $value,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param list<array{name: string, body: string, filename?: string, type?: string}> $parts
     */
    private static function formData(string $boundary, array $parts): string
    {
        $data = '';
        foreach ($parts as $part) {
            $data .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$part['name']}\"";
            isset($part['filename']) and $data .= "; filename=\"{$part['filename']}\"";
            $data .= "\r\n";
            isset($part['type']) and $data .= "Content-Type: {$part['type']}\r\n";
            $data .= "\r\n{$part['body']}\r\n";
        }

        return "$data--$boundary--\r\n";
    }

    private function profiles(): void
    {
        $this->sendContent('yii-xhprof.http');
        $this->sendContent('testo-xdebug.http');

        $this->attempt('SPX profile', function (): void {
            $boundary = 'trap-test-' . \bin2hex(\random_bytes(8));
            $this->http('POST', '/api/profiler/spx?app_name=trap-test&name=calls.php', [
                'Content-Type' => "multipart/form-data; boundary=$boundary",
            ], self::formData($boundary, [
                ['name' => 'metadata', 'filename' => 'spx-full.json', 'type' => 'application/json', 'body' => self::read('spx-full.json')],
                ['name' => 'trace', 'filename' => 'spx-full.txt.gz', 'type' => 'application/gzip', 'body' => self::read('spx-full.txt.gz')],
            ]));
        });

        $this->attempt('Speedscope profile', fn() => $this->http(
            'POST',
            '/api/profiler/speedscope?app_name=trap-test&hostname=trap-test&name=excimer&tags%5Bprofiler%5D=excimer',
            ['Content-Type' => 'application/json'],
            self::read('speedscope-excimer.json'),
        ));
    }

    private function xdebugTrace(): void
    {
        $this->attempt('Xdebug trace', fn() => $this->http('POST', '/', [
            'X-Buggregator-Event' => 'xdebug-trace',
            'X-Buggregator-Trace-Url' => 'GET /users',
            'Content-Type' => 'application/octet-stream',
        ], self::read('xdebug-trace.xt.gz')));
    }

    /**
     * Records as `SocketHandler` with `JsonFormatter` writes them: one connection, a record per line.
     */
    private function monolog(): void
    {
        $this->attempt('Monolog', function (): void {
            $data = '';
            /** @var mixed $record */
            foreach (self::template('monolog.json') as $record) {
                $data .= self::json($record) . "\n";
            }

            $socket = $this->connect();
            try {
                self::write($socket, $data);
            } finally {
                \fclose($socket);
            }
        });
    }

    /**
     * spatie/ray sends a request per call; chained calls like `->green()` reuse the UUID of the first one.
     */
    private function ray(): void
    {
        $this->attempt('Ray', function (): void {
            /** @var mixed $request */
            foreach (self::template('ray.json') as $request) {
                $this->http('POST', '/', [
                    'User-Agent' => 'Ray 1.0',
                    'Content-Type' => 'application/json',
                ], self::json($request));
            }
        });
    }

    private function httpDump(): void
    {
        $this->attempt('HTTP dump', fn() => $this->http('GET', '/orders/42?expand=items&page=2', [
            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
            'Cookie' => 'PHPSESSID=9b1d5c2e7f; theme=dark; cart=3',
            'X-Request-Id' => 'f3c1a2b4-trap-test',
        ], '', status: 404));

        $this->attempt('HTTP dump with a file', function (): void {
            $boundary = '----TrapTestBoundary' . \bin2hex(\random_bytes(8));
            $this->http('POST', '/webhooks/upload?source=trap-test', [
                'Content-Type' => "multipart/form-data; boundary=$boundary",
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
                'Cookie' => 'PHPSESSID=9b1d5c2e7f',
            ], self::formData($boundary, [
                ['name' => 'title', 'body' => 'Buggregator logo'],
                ['name' => 'description', 'body' => 'The logo of Buggregator'],
                ['name' => 'attachment', 'filename' => 'logo.png', 'type' => 'image/png', 'body' => self::read('logo.png')],
            ]), status: 404);
        });
    }

    /**
     * Legacy SDK requests, then the envelopes the PHP SDK 4.x sends for one failed request: the error,
     * the transaction of its trace, logs and trace metrics, user feedback on the error and on its own;
     * and an error with logs of another project.
     */
    private function sentry(): void
    {
        $this->sendContent('sentry-store.http');
        $this->sendContent('sentry-store-2.http');
        $this->sendContent('sentry-envelope.http');
        \usleep(100_000);

        $this->attempt('Sentry trace', function (): void {
            /** @var mixed $envelope */
            foreach (self::template('sentry-checkout.json') as $envelope) {
                $this->sentryEnvelope(1, $envelope);
            }
        });

        $this->attempt('Sentry project', function (): void {
            /** @var mixed $envelope */
            foreach (self::template('sentry-billing.json') as $envelope) {
                $this->sentryEnvelope(2, $envelope, ['X-Buggregator-Project' => 'billing']);
            }
        });
    }

    /**
     * Cron check-ins: a nightly job of the Sentry SDK that started and finished, a failed one of an interval,
     * and a backup by ping URL that started and failed with its output.
     */
    private function crons(): void
    {
        $this->attempt('Sentry Crons', function (): void {
            /** @var mixed $envelope */
            foreach (self::template('sentry-crons.json') as $envelope) {
                $this->sentryEnvelope(1, $envelope);
            }

            $schedule = \rawurlencode('30 2 * * *');
            $this->http('GET', "/ping/backup/start?schedule=$schedule&tz=UTC&margin=10&max_runtime=60", [], '');
            $this->http('POST', '/ping/backup/fail', ['Content-Type' => 'text/plain'], "Dumping the database...\nbackup: disk full\n");
        });
    }

    /**
     * @param mixed $envelope The envelope header, then the header and the payload of each item.
     * @param array<non-empty-string, string> $headers
     */
    private function sentryEnvelope(int $projectId, mixed $envelope, array $headers = []): void
    {
        \is_array($envelope) or throw new \RuntimeException('An envelope must be a list of lines.');

        $this->http('POST', "/api/$projectId/envelope/", $headers + [
            'Content-Type' => 'application/x-sentry-envelope',
            'User-Agent' => 'sentry.php/4.18.1',
            'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=sentry.php/4.18.1, sentry_key=sentry',
        ], \implode("\n", \array_map(self::json(...), $envelope)));
    }

    /**
     * A trace across two services, `gateway` calling `orders`, with logs and metrics of the same trace;
     * each service exports on its own, `orders` first as it finishes first.
     */
    private function openTelemetry(): void
    {
        $this->attempt('OpenTelemetry', function (): void {
            $ids = [];
            foreach (['traces', 'logs', 'metrics'] as $signal) {
                /** @var mixed $request */
                foreach (self::template("otlp-$signal.json", $ids) as $request) {
                    $this->http('POST', "/v1/$signal", ['Content-Type' => 'application/json'], self::json($request));
                }
            }
        });
    }

    /**
     * Security reports of a browser, a request of each format: a CSP violation of `report-uri`, and
     * a Reporting API batch of `report-to` with a CSP violation and a deprecation.
     */
    private function csp(): void
    {
        $this->attempt('CSP reports', function (): void {
            /** @var mixed $request */
            foreach (self::template('csp.json') as $request) {
                /** @var mixed $type */
                $type = \is_object($request) ? $request->content_type ?? null : null;
                \is_string($type) or throw new \RuntimeException('A CSP request must have a content type and a body.');
                \assert(\is_object($request));
                $this->http('POST', '/csp', [
                    'Content-Type' => $type,
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                ], self::json($request->body ?? null));
            }
        });
    }

    private function dump(): void
    {
        $_SERVER['VAR_DUMPER_FORMAT'] = 'server';
        $_SERVER['VAR_DUMPER_SERVER'] = "$this->addr:$this->port";

        trap(['foo' => 'bar']);
        trap(123);
        trap(new \DateTimeImmutable());

        $message = (new \Buggregator\Trap\Test\Proto\Message())
            ->setId(123)
            ->setPayload('foo')
            ->setCommand('bar')
            ->setMainMetadata([
                (new \Buggregator\Trap\Test\Proto\Message\Metadata())
                    ->setKey('foo')
                    ->setValue('bar'),
            ])
            ->setHeader(
                (new \Buggregator\Trap\Test\Proto\Message\Header())
                    ->setKey('foo')
                    ->setValue('bar'),
            )
            ->setMapaMapa(['foo' => 'bar', 'baz' => 'qux', '2' => 'quuz', 'quux ff' => 'quuz'])
            ->setFoo(\Buggregator\Trap\Test\Proto\Message\Foo::BAR);
        trap(Nested: (object) ['msg' => $message]);

        try {
            $socket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP) ?: throw new \RuntimeException('Cannot create socket.');
            @\socket_connect($socket, $this->addr, $this->port);
            @\socket_write($socket, $message->serializeToString());
        } catch (\Throwable $e) {
            $this->failed = true;
            $this->logger->exception($e, "Proto sending error", important: true);
        } finally {
            if (isset($socket)) {
                @\socket_close($socket);
            }
        }
    }

    private function mail(OutputInterface $output, bool $multipart = false): void
    {
        try {
            $socket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP) ?: throw new \RuntimeException('Cannot create socket.');
            \socket_connect($socket, $this->addr, $this->port);
            // A server that misses the end of a command must not hang the command
            \socket_set_option($socket, \SOL_SOCKET, \SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);

            $this->sendMailPackage($output, $socket, '', '220 ');
            $this->sendMailPackage($output, $socket, "HELO\r\n", '250 ');
            $this->sendMailPackage($output, $socket, "MAIL FROM: <someusername@foo.bar>\r\n", '250 ');
            $this->sendMailPackage($output, $socket, "RCPT TO: <user1@company.tld>\r\n", '250 ');
            $this->sendMailPackage($output, $socket, "RCPT TO: <user2@company.tld>\r\n", '250 ');
            $this->sendMailPackage($output, $socket, "DATA\r\n", '354 ');

            // Send Data
            if ($multipart) {
                $this->sendMailPackage($output, $socket, "From: sender@example.com\r\n", '');
                $this->sendMailPackage($output, $socket, "To: recipient@example.com\r\n", '');
                $this->sendMailPackage($output, $socket, "Subject: Multipart Email Example\r\n", '');
                $this->sendMailPackage(
                    $output,
                    $socket,
                    "Content-Type: multipart/alternative; boundary=\"boundary-string\"\r\n",
                    '',
                );
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "--boundary-string\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Type: text/plain; charset=\"utf-8\"\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Transfer-Encoding: quoted-printable\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Disposition: inline\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "Plain text email goes here!\r\n", '');
                $this->sendMailPackage(
                    $output,
                    $socket,
                    "This is the fallback if email client does not support HTML\r\n",
                    '',
                );
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "--boundary-string\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Type: text/html; charset=\"utf-8\"\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Transfer-Encoding: quoted-printable\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Disposition: inline\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "<h1>This is the HTML Section!</h1>\r\n", '');
                $this->sendMailPackage(
                    $output,
                    $socket,
                    "<p>This is what displays in most modern email clients</p>\r\n",
                    '',
                );
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "--boundary-string--\r\n", '');
                // Attachment
                $this->sendMailPackage($output, $socket, "Content-Type: image/x-icon\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Transfer-Encoding: base64\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Disposition: attachment;filename=logo.ico\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage(
                    $output,
                    $socket,
                    \base64_encode((string) \file_get_contents(Info::TRAP_ROOT . '/resources/public/favicon.ico')) . "\r\n",
                    '',
                );
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "--boundary-string--\r\n", '');

                $this->sendMailPackage($output, $socket, "Content-Type: text/watch-html; charset=\"utf-8\"\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Transfer-Encoding: quoted-printable\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Disposition: inline\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "<b>Apple Watch formatted content</b>\r\n", '');
                // One packet: older trap servers end multipart data at the closing boundary with an empty line
                // after it, not at the dot, and see them only when the whole sequence comes in a single read
                $this->sendMailPackage($output, $socket, "\r\n--boundary-string--\r\n\r\n.\r\n", '250 ');
            } else {
                $this->sendMailPackage($output, $socket, "From: Some User <someusername@somecompany.ru>\r\n", '');
                $this->sendMailPackage($output, $socket, "To: User1 <user1@company.tld>\r\n", '');
                $this->sendMailPackage($output, $socket, "Subject: Very important theme!\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Type: text/plain\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "Hi!\r\n", '');
                $this->sendMailPackage($output, $socket, ".\r\n", '250 ');
            }
            // End of data
            $this->sendMailPackage($output, $socket, "QUIT\r\n", '221 ');
        } catch (\Throwable $e) {
            $this->failed = true;
            $this->logger->exception($e, 'Mail protocol error', important: true);
        } finally {
            isset($socket) and \socket_close($socket);
        }
    }

    private function sendMailPackage(
        OutputInterface $output,
        \Socket $socket,
        string $content,
        string $expectedResponsePrefix,
    ): void {
        if ($content !== '') {
            \socket_write($socket, $content);
            // print green "hello" string in raw console markup
            $output->write(
                '> ' . \str_replace(["\r", "\n"], ["\e[32m\\r\e[0m", "\e[32m\\n\e[0m"], $content),
                true,
                OutputInterface::OUTPUT_RAW,
            );
        }

        if ($expectedResponsePrefix === '') {
            return;
        }
        @\socket_recv($socket, $buf, 65536, 0) === false and throw new \RuntimeException(
            'No SMTP reply: ' . \socket_strerror(\socket_last_error($socket)),
        );
        /** @var string|null $buf */
        if ($buf === null) {
            $output->writeln('<error>Disconnected</>');
            return;
        }

        $output->write(
            \sprintf(
                "\e[33m< \"%s\"\e[0m",
                \str_replace(["\r", "\n"], ["\e[32m\\r\e[33m", "\e[32m\\n\e[33m"], $buf),
            ),
            true,
            OutputInterface::OUTPUT_RAW,
        );

        $prefix = \substr($buf, 0, \strlen($expectedResponsePrefix));
        if ($prefix !== $expectedResponsePrefix) {
            throw new \RuntimeException("Invalid response `$buf`. Prefix `$expectedResponsePrefix` expected.");
        }
    }

    /**
     * Runs a sender; its failure is reported and the other senders go on.
     *
     * @param \Closure(): void $send
     */
    private function attempt(string $what, \Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            $this->failed = true;
            $this->logger->exception($e, "$what sending error", important: true);
        }
    }

    /**
     * Sends a request and waits for the status line, so the next request of the same event comes after it.
     *
     * @param array<non-empty-string, string> $headers
     * @param int|null $status The expected status; any 2xx when null.
     */
    private function http(string $method, string $uri, array $headers, string $body, ?int $status = null): void
    {
        $head = "$method $uri HTTP/1.1\r\nHost: $this->addr:$this->port\r\n";
        foreach ($headers as $name => $value) {
            $head .= "$name: $value\r\n";
        }
        $head .= 'Content-Length: ' . \strlen($body) . "\r\nConnection: close\r\n\r\n";

        $socket = $this->connect();
        try {
            self::write($socket, $head . $body);
            $line = \fgets($socket);
        } finally {
            \fclose($socket);
        }

        $line === false and throw new \RuntimeException("No response to `$method $uri`.");
        $code = (int) (\explode(' ', $line, 3)[1] ?? 0);
        if ($status === null ? $code < 200 || $code > 299 : $code !== $status) {
            throw new \RuntimeException(\sprintf(
                '`%s %s` answered `%s`, %s expected.',
                $method,
                $uri,
                \trim($line),
                $status ?? '2xx',
            ));
        }
    }

    /**
     * @return resource
     */
    private function connect(): mixed
    {
        $socket = @\stream_socket_client("tcp://$this->addr:$this->port", $errno, $error, 5.0);
        $socket === false and throw new \RuntimeException("Cannot connect to $this->addr:$this->port: $error");
        // A server that never answers must not hang the command
        \stream_set_timeout($socket, 5);

        return $socket;
    }

    /**
     * @param non-empty-string $file File from the {@link resources/payloads} directory
     */
    private function sendContent(string $file): void
    {
        $fp = false;
        try {
            $socket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP) ?: throw new \RuntimeException('Cannot create socket.');
            @\socket_connect($socket, $this->addr, $this->port);

            $fp = @\fopen(self::PAYLOADS . $file, 'rb');
            if ($fp === false) {
                throw new \RuntimeException('Cannot open file.');
            }
            @\flock($fp, LOCK_SH);
            while (!\feof($fp)) {
                $read = \fread($fp, 4096);
                $read === false and throw new \RuntimeException('Cannot read file.');
                @\socket_write($socket, $read);
            }

        } catch (\Throwable $e) {
            $this->failed = true;
            $this->logger->exception($e, "$file sending error", important: true);
        } finally {
            if ($fp !== false) {
                @\flock($fp, LOCK_UN);
                @\fclose($fp);
            }
            if (isset($socket)) {
                @\socket_close($socket);
            }
        }
    }
}

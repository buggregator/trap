<?php

/**
 * Calls the tools of a running Trap MCP server over plain HTTP, for an agent without an MCP client.
 *
 *   php mcp.php tools                      the tool names
 *   php mcp.php tools <tool>               the tool's description and argument schema
 *   php mcp.php <tool> [key=value ...]     call a tool; see usage() for the argument forms
 *
 * TRAP_MCP_URL (default http://127.0.0.1:8000/mcp, or the port of TRAP_MCP_PORT alone) and TRAP_MCP_TOKEN select the server.
 */

declare(strict_types=1);

function trap_mcp_url(): string
{
    $url = \getenv('TRAP_MCP_URL');
    if (\is_string($url) && $url !== '') {
        return $url;
    }

    $port = \getenv('TRAP_MCP_PORT');
    return 'http://127.0.0.1:' . (\is_string($port) && $port !== '' ? $port : '8000') . '/mcp';
}

/**
 * @return array{int, string} The HTTP status and body.
 *
 * @throws RuntimeException When nothing answers.
 */
function trap_mcp_post(string $url, string $body, float $timeout): array
{
    $parts = \parse_url($url);
    if (!\is_array($parts) || !isset($parts['host'])) {
        throw new RuntimeException("Invalid URL: $url");
    }

    $tls = ($parts['scheme'] ?? 'http') === 'https';
    $host = $parts['host'];
    $port = $parts['port'] ?? ($tls ? 443 : 80);
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    $socket = @\stream_socket_client(($tls ? 'ssl' : 'tcp') . "://$host:$port", $errno, $error, \min($timeout, 3.0));
    if ($socket === false) {
        throw new RuntimeException("Nothing listens at $host:$port" . ($error === '' ? '' : " ($error)"));
    }

    \stream_set_timeout($socket, (int) \ceil($timeout));
    $headers = [
        "POST $path HTTP/1.1",
        "Host: $host:$port",
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'Content-Length: ' . \strlen($body),
        'Connection: close',
    ];
    $token = \getenv('TRAP_MCP_TOKEN');
    if (\is_string($token) && $token !== '') {
        $headers[] = "Authorization: Bearer $token";
    }

    \fwrite($socket, \implode("\r\n", $headers) . "\r\n\r\n" . $body);
    $response = (string) \stream_get_contents($socket);
    $timedOut = \stream_get_meta_data($socket)['timed_out'];
    \fclose($socket);

    if ($timedOut) {
        throw new RuntimeException("No answer from $host:$port in $timeout s");
    }

    [$head, $content] = \explode("\r\n\r\n", $response, 2) + [1 => ''];
    if (\preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $head, $match) !== 1) {
        throw new RuntimeException("$host:$port answers, but not with HTTP");
    }

    if (\preg_match('/^Transfer-Encoding:\s*chunked/im', $head) === 1) {
        $content = trap_mcp_unchunk($content);
    }

    return [(int) $match[1], $content];
}

function trap_mcp_unchunk(string $content): string
{
    $result = '';
    while (($eol = \strpos($content, "\r\n")) !== false) {
        $size = (int) \hexdec(\substr($content, 0, $eol));
        if ($size === 0) {
            break;
        }

        $result .= \substr($content, $eol + 2, $size);
        $content = \substr($content, $eol + 2 + $size + 2);
    }

    return $result;
}

/**
 * @param array<string, mixed> $params
 *
 * @return array<array-key, mixed> The JSON-RPC result.
 *
 * @throws RuntimeException When the server is unreachable, is not an MCP server or answers with an error.
 */
function trap_mcp_rpc(string $method, array $params = [], float $timeout = 30.0): array
{
    $url = trap_mcp_url();
    $body = \json_encode(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params],
        \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
    );
    [$status, $content] = trap_mcp_post($url, $body, $timeout);

    if ($status === 401) {
        throw new RuntimeException("$url wants a token: set TRAP_MCP_TOKEN to the one Trap runs with");
    }

    $data = \json_decode($content, true);
    if (!\is_array($data) || ($data['jsonrpc'] ?? null) !== '2.0') {
        throw new RuntimeException(
            "$url answers HTTP $status, not JSON-RPC: Trap runs without --mcp there, or another server listens",
        );
    }

    if (isset($data['error'])) {
        throw new RuntimeException(\sprintf(
            'JSON-RPC error %s: %s',
            $data['error']['code'] ?? '?',
            $data['error']['message'] ?? '',
        ));
    }

    return \is_array($data['result'] ?? null) ? $data['result'] : [];
}

/**
 * `key=value` takes a number, `true`, `false` or `null` as such and anything else as a string;
 * `key:=<json>` takes raw JSON; dots nest: `where.level=error`.
 *
 * @param list<string> $pairs
 *
 * @return array<string, mixed>
 */
function trap_mcp_arguments(array $pairs): array
{
    $arguments = [];
    foreach ($pairs as $pair) {
        if (\preg_match('/^([^=:]+)(:?=)(.*)$/s', $pair, $match) !== 1) {
            throw new InvalidArgumentException("Expected key=value or key:=<json>, got: $pair");
        }

        [, $key, $operator, $raw] = $match;
        $value = $operator === ':='
            ? trap_mcp_json($raw, "`$key:=`")
            : match (true) {
                \preg_match('/^-?\d+$/', $raw) === 1 => (int) $raw,
                \is_numeric($raw) => (float) $raw,
                $raw === 'true' => true,
                $raw === 'false' => false,
                $raw === 'null' => null,
                default => $raw,
            };

        $target = &$arguments;
        foreach (\explode('.', $key) as $segment) {
            \is_array($target) or $target = [];
            $target = &$target[$segment];
        }
        $target = $value;
        unset($target);
    }

    return $arguments;
}

function trap_mcp_json(string $json, string $what): mixed
{
    try {
        return \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException("Invalid JSON in $what: {$e->getMessage()}", previous: $e);
    }
}

function trap_mcp_usage(): string
{
    return <<<'TEXT'
        Usage:
          php mcp.php tools                         the tool names
          php mcp.php tools <tool>                  the tool's description and argument schema
          php mcp.php <tool> [key=value ...]        call a tool
          php mcp.php <tool> '<json object>'        the arguments as one JSON object
          php mcp.php <tool> -                      the JSON object from stdin

        key=value takes numbers, true, false, null as such and the rest as strings;
        key:=<json> takes raw JSON (lists, a string of digits: search:='"42"'); dots nest: where.level=error.
        An argument the tool doesn't take fails before the call.
        TRAP_MCP_URL (default http://127.0.0.1:8000/mcp), or TRAP_MCP_PORT alone, and TRAP_MCP_TOKEN select the server.

        TEXT;
}

/**
 * @param list<string> $argv
 */
function trap_mcp_main(array $argv): int
{
    $tool = $argv[1] ?? null;
    if ($tool === null || \in_array($tool, ['-h', '--help', 'help'], true)) {
        \fwrite(\STDERR, trap_mcp_usage());
        return 2;
    }

    try {
        if ($tool === 'tools') {
            $tools = trap_mcp_rpc('tools/list')['tools'] ?? [];
            $name = $argv[2] ?? null;
            foreach ($tools as $item) {
                if ($name === null) {
                    echo $item['name'], ' - ', $item['title'] ?? '', "\n";
                } elseif ($item['name'] === $name) {
                    echo $item['description'] ?? '', "\n\nArguments (JSON Schema):\n";
                    echo \json_encode($item['inputSchema'] ?? [], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), "\n";
                    return 0;
                }
            }

            if ($name !== null) {
                throw new RuntimeException("No tool $name; `php mcp.php tools` lists them");
            }
            return 0;
        }

        $rest = \array_slice($argv, 2);
        $arguments = match (true) {
            $rest === ['-'] => trap_mcp_json((string) \stream_get_contents(\STDIN), 'stdin'),
            \count($rest) === 1 && \str_starts_with(\ltrim($rest[0]), '{') => trap_mcp_json($rest[0], 'the argument'),
            default => trap_mcp_arguments($rest),
        };
        \is_array($arguments) or throw new InvalidArgumentException('The arguments must be a JSON object');

        // The server ignores unknown arguments, so a typo as `sinse` would turn a `clear_events` into "all"
        $known = null;
        foreach (trap_mcp_rpc('tools/list')['tools'] ?? [] as $item) {
            $item['name'] === $tool and $known = \array_keys($item['inputSchema']['properties'] ?? []);
        }
        $known === null and throw new RuntimeException("No tool $tool; `php mcp.php tools` lists them");
        $unknown = \array_diff(\array_keys($arguments), $known);
        $unknown === [] or throw new InvalidArgumentException(\sprintf(
            '%s takes no %s; it takes: %s',
            $tool,
            \implode(', ', $unknown),
            \implode(', ', $known),
        ));

        // A wait lasts up to its own timeout, 30 s by default
        $timeout = $tool === 'wait_for_events' ? (float) ($arguments['timeout'] ?? 30) + 15 : 30.0;
        $result = trap_mcp_rpc(
            'tools/call',
            ['name' => $tool, 'arguments' => $arguments === [] ? new stdClass() : $arguments],
            $timeout,
        );
    } catch (Throwable $e) {
        \fwrite(\STDERR, $e->getMessage() . "\n");
        return 1;
    }

    if (isset($result['structuredContent'])) {
        echo \json_encode($result['structuredContent'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), "\n";
    } else {
        foreach ($result['content'] ?? [] as $content) {
            echo $content['text'] ?? '', "\n";
        }
    }

    return ($result['isError'] ?? false) === true ? 1 : 0;
}

if (\realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(trap_mcp_main($argv));
}

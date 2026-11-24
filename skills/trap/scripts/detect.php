<?php

/**
 * What a project and this machine offer for debugging with Trap: the installed Trap, the running server
 * and its MCP endpoint, the channels the application can send through, the profilers, the settings
 * that point the application at Trap. Ends with the references of the skill to read.
 *
 *   php detect.php [<project root>]     the current directory by default
 */

declare(strict_types=1);

require __DIR__ . '/mcp.php';

$root = \rtrim($argv[1] ?? (string) \getcwd(), '/\\');
$read = [];

/** @return array<array-key, mixed>|null */
$json = static function (string $file): ?array {
    $data = \is_file($file) ? \json_decode((string) \file_get_contents($file), true) : null;
    return \is_array($data) ? $data : null;
};

/** The name of the process that listens on a local TCP port, when the system tools tell it */
$listener = static function (int $port): ?string {
    if (!\function_exists('shell_exec')) {
        return null;
    }
    if (\PHP_OS_FAMILY === 'Windows') {
        // A listening socket has no remote address; the name of the state is translated
        $pid = \preg_match("/TCP\\s+\\S+:$port\\s+(?:0\\.0\\.0\\.0|\\[::\\]):0\\s+\\S+\\s+(\\d+)/", (string) @\shell_exec('netstat -ano'), $m) === 1 ? $m[1] : null;
        $task = $pid === null ? '' : (string) @\shell_exec("tasklist /FI \"PID eq $pid\" /FO CSV /NH");
        return \preg_match('/^"([^"]+)"/', $task, $m) === 1 ? "{$m[1]} (PID $pid)" : null;
    }
    $out = (string) @\shell_exec("lsof -nP -iTCP:$port -sTCP:LISTEN -Fpc 2>/dev/null");

    return \preg_match('/^p(\d+)\s+c(.+)$/m', $out, $m) === 1 ? "{$m[2]} (PID {$m[1]})" : null;
};

// Trap itself

echo "Trap:\n";
$composer = $json("$root/composer.json") ?? [];
$trapRoot = null;
if (($composer['name'] ?? null) === 'buggregator/trap') {
    $trapRoot = $root;
    echo "  this is the Trap repository; run it as bin/trap\n";
} else {
    $installed = $json("$root/vendor/composer/installed.json");
    foreach ($installed['packages'] ?? $installed ?? [] as $package) {
        if (\is_array($package) && ($package['name'] ?? null) === 'buggregator/trap') {
            $trapRoot = "$root/vendor/buggregator/trap";
            echo '  installed: ', $package['pretty_version'] ?? $package['version'] ?? '?', ", run it as vendor/bin/trap\n";
        }
    }
}
$hasTrap = $trapRoot !== null;
if (!$hasTrap) {
    // A Phar or a binary on the PATH runs the server, without trap() in the project
    foreach (\explode(\PATH_SEPARATOR, (string) \getenv('PATH')) as $dir) {
        foreach (['trap', 'trap.exe', 'trap.phar'] as $file) {
            if ($dir !== '' && \is_file("$dir/$file")) {
                echo "  global: $dir/$file runs the server; trap(), tr(), td() need the Composer package\n";
                break 2;
            }
        }
    }
    echo "  not in the project: ask the user, then `composer require --dev buggregator/trap`\n";
}
if ($trapRoot !== null) {
    echo \is_dir("$trapRoot/src/Module/Mcp")
        ? "  MCP: supported (--mcp)\n"
        : "  MCP: not in this version; update buggregator/trap, or read the events in the console output\n";
}

// The running server

echo "\nServer:\n";
$url = trap_mcp_url();
$mcpPort = (int) (\parse_url($url, \PHP_URL_PORT) ?? 80);
try {
    trap_mcp_rpc('ping', timeout: 3.0);
    echo "  MCP: answers at $url\n";
} catch (RuntimeException $e) {
    echo '  MCP: ', $e->getMessage(), "\n";

    // Only when MCP is silent: a Trap takes a bare connection for an empty event
    $ports = \getenv('TRAP_TCP_PORTS');
    $ports = \is_string($ports) && $ports !== '' ? \array_map('intval', \explode(',', $ports)) : [1025, 4318, 8000, 9912, 9913];
    $open = [];
    foreach ($ports as $port) {
        $socket = @\stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 0.3);
        if ($socket !== false) {
            \fclose($socket);
            $open[] = $port;
        }
    }
    echo '  listening on 127.0.0.1: ', $open === [] ? 'none of ' . \implode(', ', $ports) : \implode(', ', $open), "\n";

    $silent = \str_starts_with($e->getMessage(), 'Nothing listens');
    echo match (true) {
        $silent && $open === [] => "  start it in the background: vendor/bin/trap --mcp\n",
        $silent => "  these ports are held, but not the MCP port $mcpPort: a server of the user, or a Trap with other"
            . " ports; check TRAP_MCP_PORT and TRAP_MCP_URL\n",
        default => "  port $mcpPort answers without MCP: likely a Trap or Buggregator of the user without --mcp; ask before"
            . " restarting it. A Trap there caught this probe as an http-dump event `POST /mcp`\n",
    };
    $read['server.md'] = 'start the server, ports, another host';
}

// The channels of the application

$packages = [];
$lock = $json("$root/composer.lock");
foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
    \is_array($package) && isset($package['name']) and $packages[$package['name']] = true;
}
foreach ([...\array_keys($composer['require'] ?? []), ...\array_keys($composer['require-dev'] ?? [])] as $name) {
    $packages[$name] = true;
}

$channels = [
    'symfony/var-dumper' => 'dump(): VarDumper, sends to Trap with VAR_DUMPER_FORMAT=server',
    'monolog/monolog' => 'Monolog: a SocketHandler with a JsonFormatter to 127.0.0.1:9913',
    'sentry/sentry' => 'Sentry SDK: DSN http://sentry@127.0.0.1:8000/1',
    'spatie/ray' => 'Ray: port 8000 in ray.php',
    'spatie/laravel-ray' => 'Ray for Laravel: RAY_PORT=8000',
    'symfony/mailer' => 'Symfony Mailer: MAILER_DSN=smtp://127.0.0.1:1025',
    'laravel/framework' => 'Laravel: mail on MAIL_PORT=1025, a `socket` log channel, SENTRY_LARAVEL_DSN',
    'open-telemetry/sdk' => 'OpenTelemetry: OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318',
];
echo "\nChannels the project can send through:\n";
$hasTrap and print "  trap(), tr(), td(), trap()::logger(): the functions of Trap\n";
foreach ($channels as $name => $line) {
    if (isset($packages[$name])) {
        echo "  $line\n";
        $name === 'symfony/var-dumper' or $read['integrations.md'] = 'point the channels at Trap';
    }
}

// Long-running workers

$workers = [
    'spiral/roadrunner-worker' => 'RoadRunner',
    'laravel/octane' => 'Laravel Octane',
    'runtime/frankenphp-symfony' => 'FrankenPHP worker mode',
    'symfony/messenger' => 'Symfony Messenger consumers',
    'laravel/horizon' => 'Laravel Horizon queue workers',
];
$found = \array_values(\array_intersect_key($workers, $packages));
(\extension_loaded('swoole') || \extension_loaded('openswoole')) and $found[] = 'Swoole';
if ($found !== []) {
    echo "\nLong-running workers: ", \implode(', ', $found), "\n";
    $read['workers.md'] = 'reload the workers after each change, keep Trap running';
}

// Profilers

echo "\nProfilers of this PHP CLI (", \PHP_BINARY, "; a web server may run another PHP):\n";
$profilers = [
    'excimer' => 'Excimer: sampling, trap()::profile() picks it first',
    'xhprof' => 'XHProf: every call, trap()::profile(new XHProf())',
    'tideways_xhprof' => 'XHProf (tideways): every call, trap()::profile(new XHProf())',
    'spx' => 'SPX: needs spx.* INI at the process start',
    'blackfire' => 'Blackfire probe: blackfire.agent_socket to a Trap port and a query (profiling.md)',
];
$found = false;
foreach ($profilers as $extension => $line) {
    if (\extension_loaded($extension)) {
        echo "  $line\n";
        $found = true;
    }
}
// PHP Debugger first: with php_debugger.report_xdebug_module it passes for xdebug too. Its xdebug.* settings hold
// the values in effect, the php_debugger.* ones keep their defaults when only xdebug.* is set.
$debugger = null;
if (\extension_loaded('php_debugger')) {
    echo '  PHP Debugger ', \phpversion('php_debugger'), ': mode=', \ini_get('xdebug.mode'),
        ', start_with_request=', \ini_get('xdebug.start_with_request'), "\n";
    echo "    steps through code with trap --xdebug (xdebug.md); no profiles, no traces\n";
    $debugger = 'php_debugger';
} elseif (\extension_loaded('xdebug')) {
    $mode = (string) \ini_get('xdebug.mode');
    echo "  Xdebug: mode=$mode", \str_contains($mode, 'profile') ? '' : '; profiles whole runs with xdebug.mode=profile', "\n";
    echo "    steps through code with trap --xdebug (xdebug.md); traces every call with xdebug.mode=trace (profiling.md)\n";
    $debugger = 'xdebug';
    $found = true;
}
if ($debugger !== null) {
    $port = (int) (\getenv('TRAP_XDEBUG_PORT') ?: \ini_get('xdebug.client_port') ?: 9003);
    $held = static function (int $port): bool {
        $socket = @\stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 0.3);
        $socket === false or \fclose($socket);
        return $socket !== false;
    };
    if (!$held($port)) {
        echo "    port $port for the debugger: free\n";
    } else {
        for ($free = 9013; $free < 9100 && $held($free); $free += 10);
        echo "    port $port for the debugger: held", ($owner = $listener($port)) === null ? '' : " by $owner",
            " (an IDE, or a Trap with --xdebug, which shows this probe as an empty event): ",
            $free < 9100
                ? "give Trap a free one, as --xdebug=$free with $debugger.client_port=$free\n"
                : "none of 9013, 9023 … 9093 is free; pick another one\n";
    }
}
echo $found ? '' : "  none; Excimer installs with `pecl install excimer`\n";

// Settings that point at Trap

$keys = [
    'VAR_DUMPER_FORMAT', 'VAR_DUMPER_SERVER', 'SENTRY_DSN', 'SENTRY_LARAVEL_DSN', 'MAILER_DSN', 'MAIL_MAILER',
    'MAIL_HOST', 'MAIL_PORT', 'LOG_CHANNEL', 'LOG_SOCKET_URL', 'RAY_HOST', 'RAY_PORT', 'OTEL_EXPORTER_OTLP_ENDPOINT',
    'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', 'OTEL_EXPORTER_OTLP_PROTOCOL',
];
$settings = [];
foreach (['.env', '.env.local', '.env.dev', '.env.dev.local'] as $file) {
    if (!\is_file("$root/$file")) {
        continue;
    }
    foreach ((array) \file("$root/$file", \FILE_IGNORE_NEW_LINES) as $line) {
        if (\preg_match('/^\s*(?:export\s+)?([A-Z_]+)\s*=\s*(.*)$/', (string) $line, $match) === 1
            && (\in_array($match[1], $keys, true) || \str_starts_with($match[1], 'TRAP_'))) {
            $settings[$match[1]] = [$file, \trim($match[2], " \t\"'")];
        }
    }
}
foreach ([...$keys, ...\array_keys(\array_filter(\getenv(), static fn($k) => \str_starts_with((string) $k, 'TRAP_'), \ARRAY_FILTER_USE_KEY))] as $key) {
    $value = \getenv($key);
    \is_string($value) && $value !== '' and $settings[$key] = ['environment', $value];
}
echo "\nSettings:\n";
foreach ($settings as $key => [$source, $value]) {
    // Userinfo of a URL, a token, a password
    $value = \preg_match('/TOKEN|SECRET|PASSWORD/', $key) === 1 ? '***' : \preg_replace('#//[^/@\s]+@#', '//***@', $value);
    echo "  $key=$value ($source)\n";
}
echo $settings === [] ? "  none of the known ones in .env files or the environment\n" : '';

echo $read === [] ? '' : "\nRead next (references/ of the skill):\n";
foreach ($read as $file => $why) {
    echo "  $file: $why\n";
}

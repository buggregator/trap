<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Acceptance\Support;

use Buggregator\Trap\Server\Info;
use Testo\Core\Exception\SkipTest;

/**
 * Sends mail with Symfony Mailer from the `tools/mailer` bin tool, run in a PHP subprocess
 * to keep its dependencies apart from the ones of Trap.
 */
final class SymfonyMailer
{
    private const TIMEOUT = 15.0;

    /** Starts the line of the client's result, see `tests/Stub/mailer/symfony-mailer.php`. */
    private const RESULT_MARKER = '@@symfony-mailer-result@@';

    /**
     * Skips the calling test when the tool is not installed: `composer bin mailer install`.
     * Fails it on CI instead, where the tool is installed unless a workflow lost the step.
     */
    public static function require(): void
    {
        if (\is_file(Info::TRAP_ROOT . '/tools/mailer/vendor/autoload.php')) {
            return;
        }

        $message = 'Symfony Mailer is not installed: run `composer bin mailer install`.';
        \getenv('CI') === false
            ? throw new SkipTest($message)
            : throw new \RuntimeException($message);
    }

    /**
     * Sends the mails one after another over one connection.
     *
     * @param array{
     *     from: string,
     *     to?: list<string>,
     *     cc?: list<string>,
     *     bcc?: list<string>,
     *     subject: string,
     *     text?: string,
     *     html?: string,
     *     attachments?: list<array{name: string, content: string, type: string}>,
     * } ...$mails
     * @return array{sent: int, error: string|null, debug: string} The number of mails sent, the error that
     *         stopped sending and the SMTP transcript.
     */
    public static function send(string $dsn, array ...$mails): array
    {
        self::require();
        $env = ['XDEBUG_MODE' => 'off'];
        $keep = \PHP_OS_FAMILY === 'Windows' ? ['PATH', 'SystemRoot', 'SystemDrive', 'windir', 'TEMP', 'TMP'] : ['PATH'];
        foreach ($keep as $name) {
            \getenv($name) === false or $env[$name] = (string) \getenv($name);
        }

        $process = \proc_open(
            [
                \PHP_BINARY,
                '-d',
                'default_socket_timeout=' . (int) self::TIMEOUT,
                '-d',
                'display_errors=stdout',
                Info::TRAP_ROOT . '/tests/Stub/mailer/symfony-mailer.php',
                $dsn,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            null,
            $env,
        ) ?: throw new \RuntimeException('Unable to start PHP.');
        TrapProcess::write($pipes[0], \json_encode(\array_values($mails), \JSON_THROW_ON_ERROR));
        \fclose($pipes[0]);
        $output = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $exitCode = \proc_close($process);

        // Warnings and deprecations of the client come before its result line
        $at = \strrpos($output, self::RESULT_MARKER);
        $result = $at === false ? null : \json_decode(\substr($output, $at + \strlen(self::RESULT_MARKER)), true);
        $exitCode === 0 && \is_array($result) or throw new \RuntimeException(
            "The Symfony Mailer client failed with exit code $exitCode:\n$output",
        );
        $diagnostics = \trim(\substr($output, 0, $at));
        $diagnostics === '' or $result['debug'] = "Client output:\n$diagnostics\n\n{$result['debug']}";

        /** @var array{sent: int, error: string|null, debug: string} $result */
        return $result;
    }
}

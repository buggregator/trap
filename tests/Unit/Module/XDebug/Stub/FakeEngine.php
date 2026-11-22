<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebug\Stub;

use Buggregator\Trap\Module\XDebug\Dbgp\Protocol;
use Buggregator\Trap\Module\XDebug\Link\Link;

/**
 * Xdebug for the tests: answers each command line with the reply of its responder, as Xdebug 3.4 writes it.
 */
final class FakeEngine implements Link
{
    public const INIT = '<?xml version="1.0" encoding="iso-8859-1"?>' . "\n"
        . '<init xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" fileuri="file:///app/a.php"'
        . ' language="PHP" protocol_version="1.0" appid="1" idekey="PHPSTORM"><engine version="3.4.0"><![CDATA[Xdebug]]></engine></init>';

    /** @var list<string> Command lines received */
    public array $commands = [];

    public bool $closed = false;

    /** Connected from this host */
    public bool $loopback = true;

    private string $out = '';

    /**
     * @param array<string, \Closure(array<string, string>): string> $responders Inner XML or attributes by command;
     *        the default answers with an empty response.
     */
    public function __construct(
        public array $responders = [],
    ) {}

    public static function response(string $command, string $tid, string $attributes = '', string $inner = ''): string
    {
        return '<?xml version="1.0" encoding="iso-8859-1"?>' . "\n"
            . '<response xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug"'
            . " command=\"$command\" transaction_id=\"$tid\"$attributes>$inner</response>";
    }

    public static function breakAt(string $command, string $tid, string $file, int $line): string
    {
        return self::response(
            $command,
            $tid,
            ' status="break" reason="ok"',
            "<xdebug:message filename=\"$file\" lineno=\"$line\"></xdebug:message>",
        );
    }

    /**
     * Queues a packet as if the engine sent it on its own, as a notification.
     */
    public function emit(string $body): void
    {
        $this->out .= Protocol::encodePacket($body);
    }

    #[\Override]
    public function send(string $bytes): bool
    {
        foreach (\explode("\0", \rtrim($bytes, "\0")) as $line) {
            $this->commands[] = $line;
            \preg_match('/^(\S+) -i (\d+)(.*)$/', $line, $m) === 1 or throw new \LogicException("No -i in `$line`.");
            [, $name, $tid] = $m;
            $responder = $this->responders[$name] ?? null;
            $this->out .= Protocol::encodePacket(
                $responder === null ? self::response($name, $tid) : $responder(['name' => $name, 'tid' => $tid, 'args' => \trim($m[3])]),
            );
        }

        return true;
    }

    #[\Override]
    public function receive(): ?string
    {
        if ($this->out === '' && $this->closed) {
            return null;
        }
        [$data, $this->out] = [$this->out, ''];

        return $data;
    }

    #[\Override]
    public function isLoopback(): bool
    {
        return $this->loopback;
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebug\Dbgp;

use Buggregator\Trap\Module\XDebug\Dbgp\EngineError;
use Buggregator\Trap\Module\XDebug\Dbgp\PacketReader;
use Buggregator\Trap\Module\XDebug\Dbgp\Protocol;
use Buggregator\Trap\Module\XDebug\Dbgp\ProtocolError;
use Buggregator\Trap\Module\XDebug\Dbgp\Response;
use Buggregator\Trap\Module\XDebug\Traffic\Dispatcher;
use Buggregator\Trap\Tests\Unit\Module\XDebug\Stub\FakeEngine;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(Protocol::class)]
#[Covers(PacketReader::class)]
#[Covers(Response::class)]
#[Covers(Dispatcher::class)]
#[Test]
final class ProtocolTest
{
    public function testSplitsPacketsAcrossChunks(): void
    {
        $reader = new PacketReader();
        $stream = Protocol::encodePacket('<a/>') . Protocol::encodePacket('<bb/>');

        $first = $reader->push(\substr($stream, 0, 5));
        $rest = $reader->push(\substr($stream, 5));

        Assert::same($first, []);
        Assert::same($rest, ['<a/>', '<bb/>']);
    }

    public function testSplitsIdeCommands(): void
    {
        $reader = new PacketReader(lengthPrefixed: false);

        Assert::same($reader->push("run -i 1\0step_"), ['run -i 1']);
        Assert::same($reader->push("into -i 2\0"), ['step_into -i 2']);
    }

    public function testRejectsBadLength(): never
    {
        Expect::exception(ProtocolError::class)->withMessage('Bad packet length.');

        (new PacketReader())->push("12x\0");
    }

    public function testRejectsPacketWithoutNul(): never
    {
        Expect::exception(ProtocolError::class)->withMessage('Packet not NUL-terminated.');

        (new PacketReader())->push("2\0abX");
    }

    public function testFindsTransactionIdOfCommand(): void
    {
        $line = 'eval -i 42 -- ' . \base64_encode('-i 7');

        [$name, $start, $end] = Protocol::commandTid($line);

        Assert::same($name, 'eval');
        Assert::same(\substr($line, $start, $end - $start), '42');
        Assert::null(Protocol::commandTid('status'));
        Assert::same(Protocol::commandTid('property_get -n "a -i b" -i 5')[0], 'property_get');
    }

    public function testBuildsCommandsWithoutTrailingSpace(): void
    {
        Assert::same(Protocol::command('run', 3), 'run -i 3');
        Assert::same(Protocol::command('eval', 4, '', '1+1'), 'eval -i 4 -- MSsx');
        Assert::same(Protocol::quote('a b"c'), '"a b\\"c"');
        Assert::same(Protocol::quote('$x'), '$x');
    }

    public function testReadsRootAndReplacesTransactionId(): void
    {
        $body = FakeEngine::breakAt('step_into', '5', 'file:///a.php', 3);

        $root = Protocol::root($body);

        Assert::same($root, ['name' => 'response', 'tid' => '5', 'status' => 'break', 'command' => 'step_into']);
        Assert::string(Protocol::withTid($body, '77'))->contains('transaction_id="77" status="break"');
        Assert::same(Protocol::root(FakeEngine::INIT)['name'], 'init');
    }

    public function testParsesVariablesOfXdebug(): void
    {
        $body = FakeEngine::response('context_get', '1', '', '<property name="$a" fullname="$a" type="array" children="1" numchildren="2" page="0" pagesize="32"><property name="x" fullname="$a[&quot;x&quot;]" type="int"><![CDATA[1]]></property></property><property name="$s" fullname="$s" type="string" size="9" encoding="base64"><![CDATA[aGVsbG8=]]></property><property name="$r" type="uninitialized"></property>');

        $vars = Response::parse($body)->properties();

        Assert::same($vars[0]['numchildren'], 2);
        Assert::same($vars[0]['children'][0], ['name' => 'x', 'fullname' => '$a["x"]', 'type' => 'int', 'value' => '1']);
        Assert::same($vars[1]['value'], 'hello');
        Assert::same($vars[1]['size'], 9);
        Assert::same($vars[2], ['name' => '$r', 'type' => 'uninitialized']);
    }

    public function testReadsBreakLocationAndErrors(): void
    {
        Assert::same(Response::parse(FakeEngine::breakAt('run', '1', 'file:///a.php', 9))->breakLocation(), ['file:///a.php', 9]);

        Expect::exception(EngineError::class)->withMessage('Xdebug error 300: can not get property');

        Response::parse(FakeEngine::response('property_get', '2', '', '<error code="300"><message><![CDATA[can not get property]]></message></error>'))->assertOk();
    }

    public function testDetectsXdebugInit(): void
    {
        $dispatcher = (new \ReflectionClass(Dispatcher::class))->newInstanceWithoutConstructor();

        Assert::true($dispatcher->detect(Protocol::encodePacket(FakeEngine::INIT), 0.0));
        Assert::null($dispatcher->detect('51', 0.0));
        Assert::null($dispatcher->detect("51\0<?x", 0.0));
        Assert::false($dispatcher->detect('GET / HTTP/1.1', 0.0));
        Assert::false($dispatcher->detect("12\0{}", 0.0));
        // A client that stops short is left to the other dispatchers
        Assert::false($dispatcher->detect('12345', Dispatcher::SILENCE + 0.1));
        Assert::false($dispatcher->detect("51\0<?x", Dispatcher::SILENCE + 0.1));
        Assert::false($dispatcher->detect('', Dispatcher::SILENCE + 0.1));
    }
}

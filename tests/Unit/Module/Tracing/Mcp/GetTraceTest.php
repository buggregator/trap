<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Module\Tracing\Mcp\GetTrace;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(GetTrace::class)]
final class GetTraceTest
{
    private const TRACE = '382bfca057473920912cab0a4ed95abe';

    public function testShowsTraceAsPlainTextTree(): void
    {
        $trap = self::trap();

        $text = $trap->get(GetTrace::class)->call(['id' => \strtoupper(self::TRACE)]);

        Assert::string($text)->matchesRegex(
            '/^trace ' . self::TRACE . ', 4 spans, service e2e-app, has errors, updated at \d{4}-[^\n]+\n# Trace /',
        );
        Assert::string($text)->contains("\nGET /orders SERVER 27.69 ms OK id:286e73f2056fe20e\n")->contains("   └─ template.load INTERNAL 14.46 ms +12.97 ms id:8118bc4ed687fb8d\n         template.name: \"orders.twig\"\n")->notContains("\e")->notMatchesRegex('/ $/m');
    }

    public function testLongTextIsTruncated(): void
    {
        $text = self::trap()->get(GetTrace::class)->call(['id' => self::TRACE, 'max_length' => 100]);

        Assert::string($text)->contains("\n\n[Truncated: 100 of ");
        Assert::same(\mb_strpos($text, "\n\n[Truncated"), 100);
    }

    public function testUnknownTraceIsError(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('No trace with ID `ffff`');

        self::trap()->get(GetTrace::class)->call(['id' => 'ffff']);
    }

    public function testRejectsMissingId(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`id` must be a non-empty string.');

        self::trap()->get(GetTrace::class)->call([]);
    }

    public function testIsReadOnlyTextToolOfTheTracingModule(): void
    {
        $tool = (new Trap())->get(Tools::class)->get('get_trace');

        Assert::instanceOf($tool, GetTrace::class);
        Assert::null($tool->outputSchema());
        Assert::same($tool->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
        Assert::same($tool->inputSchema()['required'], ['id']);
    }

    private static function trap(): Trap
    {
        $trap = new Trap();
        $trap->get(SpanStore::class)->receive((new JsonTracesDecoder())->decode(
            (string) \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'),
            new \DateTimeImmutable(),
        ));

        return $trap;
    }
}

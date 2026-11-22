<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Module\Sentry\Frame\EnvelopeItem;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frontend\EnvelopeMapper;
use Buggregator\Trap\Module\Tracing\Frontend\TelemetryMapper;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Module\Tracing\Mcp\GetTrace;
use Buggregator\Trap\Module\Tracing\Mcp\TraceSpans;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(GetTrace::class)]
#[Covers(TraceSpans::class)]
final class GetTraceTest
{
    private const TRACE = '382bfca057473920912cab0a4ed95abe';
    private const SENTRY_TRACE = '5b8efff798038103d269b633813fc60c';

    public function testShowsTraceAsPlainTextTree(): void
    {
        $trap = self::trap();

        $text = $trap->get(GetTrace::class)->call(['id' => \strtoupper(self::TRACE)]);

        Assert::string($text)->matchesRegex(
            '/^trace ' . self::TRACE . ', 4 spans, service e2e-app, source otel, project default, has errors, updated at \d{4}-[^\n]+\n# Trace /',
        );
        Assert::string($text)->contains("\nGET /orders SERVER 27.69 ms OK id:286e73f2056fe20e\n")->contains("   └─ template.load INTERNAL 14.46 ms +12.97 ms id:8118bc4ed687fb8d\n         template.name: \"orders.twig\"\n")->notContains("\e")->notMatchesRegex('/ $/m');
    }

    public function testShowsSentryTransactionWithItsSpans(): void
    {
        $trap = new Trap();
        $trap->get(EnvelopeMapper::class)->map(new SentryEnvelope([], [new EnvelopeItem(['type' => 'transaction'], [
            'event_id' => 'e1',
            'transaction' => 'POST /checkout',
            'server_name' => 'checkout-api',
            'contexts' => ['trace' => ['trace_id' => self::SENTRY_TRACE, 'span_id' => 'aaaaaaaaaaaaaaaa', 'op' => 'http.server']],
            'start_timestamp' => 1_700_000_000.0,
            'timestamp' => 1_700_000_000.25,
            'spans' => [[
                'span_id' => 'bbbbbbbbbbbbbbbb',
                'parent_span_id' => 'aaaaaaaaaaaaaaaa',
                'op' => 'http.client',
                'description' => 'POST http://payments/charge',
                'status' => 'internal_error',
                'start_timestamp' => 1_700_000_000.1,
                'timestamp' => 1_700_000_000.2,
                'data' => ['http.response.status_code' => 502],
            ]],
        ])], project: 'shop'));

        $text = $trap->get(GetTrace::class)->call(['id' => self::SENTRY_TRACE]);

        Assert::string($text)
            ->startsWith('trace ' . self::SENTRY_TRACE . ', 2 spans, service checkout-api, source sentry, project shop, has errors, updated at ')
            ->contains("\nPOST /checkout SERVER 250.00 ms id:aaaaaaaaaaaaaaaa\n")
            ->contains("└─ http.client: POST http://payments/charge CLIENT 100.00 ms ERROR: internal_error +100.00 ms id:bbbbbbbbbbbbbbbb\n")
            ->contains("      http.response.status_code: 502\n");
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
        Assert::same($tool->title(), 'Get trace');
        Assert::string($tool->description())->contains('plain text')->contains('`list_traces`');
        Assert::null($tool->outputSchema());
        Assert::same($tool->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
        Assert::same($tool->inputSchema()['required'], ['id']);
        Assert::same(\array_keys($tool->inputSchema()['properties']), ['id', 'max_length']);
    }

    private static function trap(): Trap
    {
        $trap = new Trap();
        $trap->get(TelemetryMapper::class)->map((new JsonTracesDecoder())->decode(
            (string) \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'),
            new \DateTimeImmutable(),
        ));

        return $trap;
    }
}

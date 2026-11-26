<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Command;

use Buggregator\Trap\Module\Mailer\Frame;
use Buggregator\Trap\Module\Mailer\Frontend\Mapper;
use Buggregator\Trap\Module\Mailer\Traffic\Parser;
use Buggregator\Trap\Server\Command\Test;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test as TestAttribute;

#[TestAttribute]
#[Covers(Test::class)]
final class TestTest
{
    private const PAYLOADS = Info::TRAP_ROOT . '/resources/payloads/';

    /**
     * The payload files named in the command: a file name in quotes with an extension of a payload.
     */
    public static function payloadFiles(): iterable
    {
        $source = (string) \file_get_contents(Info::TRAP_ROOT . '/src/Server/Command/Test.php');
        \preg_match_all('/[\'"]([\w$.-]+\.(?:http|json|png|gz|txt|folded))[\'"]/', $source, $matches);

        foreach (\array_unique($matches[1]) as $file) {
            foreach (\str_contains($file, '$signal') ? ['traces', 'logs', 'metrics'] : [null] as $signal) {
                $name = $signal === null ? $file : \str_replace('$signal', $signal, $file);
                yield $name => [$name];
            }
        }
    }

    public static function templates(): iterable
    {
        foreach (['monolog', 'ray', 'sentry-checkout', 'sentry-billing', 'sentry-crons', 'otlp-traces', 'otlp-logs', 'otlp-metrics', 'csp'] as $name) {
            yield $name => ["$name.json"];
        }
    }

    public function testMissingPayloadIsReportedWithoutThrowing(): void
    {
        $output = new BufferedOutput();
        $command = new Test();
        (new \ReflectionProperty(Test::class, 'logger'))->setValue($command, new Logger($output));

        (new \ReflectionMethod(Test::class, 'sendContent'))->invoke($command, 'no-such-payload.http');

        Assert::string($output->fetch())->contains('Cannot open file.');
    }

    #[DataProvider('payloadFiles')]
    public function testReferencedPayloadExists(string $file): void
    {
        Assert::true(\is_file(self::PAYLOADS . $file), "The payload `$file` is missing.");
    }

    #[DataProvider('templates')]
    public function testTemplateRendersToJsonWithoutPlaceholders(string $file): void
    {
        $before = \microtime(true);
        $ids = [];
        $list = (new \ReflectionMethod(Test::class, 'template'))->invokeArgs(null, [$file, &$ids]);

        Assert::notBlank($list);
        $json = \json_encode($list, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        Assert::string($json)->notContains('{{');
        foreach ($ids as $key => $id) {
            Assert::string($id)->matchesRegex(\str_starts_with($key, 'uuid ') ? '/^[0-9a-f-]{36}$/' : '/^[0-9a-f]{32}$/');
        }
        // Times are the recent past, not the moment the payload was recorded
        \preg_match_all('/"(?:timestamp|start_timestamp)":(\d+\.\d+)|"\w*TimeUnixNano":"(\d+)"/', $json, $times, \PREG_SET_ORDER);
        foreach ($times as $time) {
            $seconds = isset($time[2]) ? (float) $time[2] / 1e9 : (float) $time[1];
            Assert::float($seconds)->greaterThan($before - 120)->lessThan($before + 1);
        }
    }

    public function testMultipartMailCarriesEveryVersionAndInvite(): void
    {
        $payload = $this->mapMail('multipartMail');

        Assert::string($payload['text'])->startsWith('Plain text email goes here!');
        Assert::string($payload['html'])->contains('This is the HTML Section!');
        Assert::same(\array_column($payload['parts'], 'label'), ['Apple Watch', 'AMP', 'Calendar']);
        $calendar = $payload['calendar'];
        Assert::same($calendar['method'], 'REQUEST');
        Assert::same($calendar['summary'], 'Sprint review');
        Assert::same($calendar['tz'], 'Europe/Berlin');
        Assert::string($calendar['start'])->matchesRegex('/^\d{4}-\d\d-\d\dT10:00:00\+0[12]:00$/');
        Assert::same($calendar['description'], "The demo of the sprint.\nAgenda: the release, the docs and the questions.");
        Assert::same(\array_column($calendar['attendees'], 'status'), ['ACCEPTED', 'TENTATIVE', 'NEEDS-ACTION']);
    }

    public function testCancelledMailCarriesCancelledAllDayInvite(): void
    {
        $payload = $this->mapMail('cancelledMail');

        Assert::same(\array_column($payload['parts'], 'type'), ['text/calendar']);
        Assert::same($payload['calendar']['method'], 'CANCEL');
        Assert::same($payload['calendar']['status'], 'CANCELLED');
        Assert::true($payload['calendar']['all_day']);
    }

    public function testPlainMailHasNoVersions(): void
    {
        $payload = $this->mapMail('plainMail');

        Assert::same($payload['text'], 'Hi!');
        Assert::false(\array_key_exists('parts', $payload));
    }

    public function testIdsAreSharedByName(): void
    {
        $ids = [];
        $template = new \ReflectionMethod(Test::class, 'template');
        $traces = \json_encode($template->invokeArgs(null, ['otlp-traces.json', &$ids]), \JSON_THROW_ON_ERROR);
        $logs = \json_encode($template->invokeArgs(null, ['otlp-logs.json', &$ids]), \JSON_THROW_ON_ERROR);

        Assert::array($ids)->hasKeys('hex32 trace');
        Assert::string($traces)->contains($ids['hex32 trace']);
        Assert::string($logs)->contains($ids['hex32 trace']);
    }

    /**
     * The payload the web UI gets for a mail of the command.
     */
    private function mapMail(string $method): array
    {
        $email = (new \ReflectionMethod(Test::class, $method))->invoke(null);
        $stream = StreamClientMock::createFromGenerator((static fn() => yield $email . "\r\n.\r\n")());
        $fiber = new \Fiber(static fn() => (new Parser())->parseStream([], $stream));
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }
        $message = $fiber->getReturn();

        return (new Mapper())->map(new Frame($message))->payload;
    }
}

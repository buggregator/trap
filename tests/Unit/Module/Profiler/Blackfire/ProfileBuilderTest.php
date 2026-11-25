<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Blackfire;

use Buggregator\Trap\Module\Profiler\Blackfire\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ProfileBuilder::class)]
final class ProfileBuilderTest
{
    public const CLI = <<<'TXT'
        file-format: BlackfireProbe
        cost-dimensions: wt cpu mu pmu nw_in nw_out gc_ct gc_num_obj gc_wt gc_mu gc_pmu
        request-mu: 1000
        request-start: 1795542107.093239
        probed-runtime: PHP 8.3.35 (cli)
        probe-version: 2026.11.3
        context: argv%5B0%5D=bin%2Fconsole&argv%5B1%5D=cache%3Aclear
        host: web-1
        response-code: 0
        request-mu: 2000

        main()==>compile::bin/console//1 300 100 2840 0 0 0 0 0 0 0 0
        fib@1==>fib@2//4 70 60 -256 0 0 0 0 0 0 0 0
        fib==>fib@1//2 90 80 512 0 0 0 0 0 0 0 0
        main()==>fib//1 100 90 1024 4096 7 8 0 0 0 0 0
        main()//1 1000 500 9312 8192 0 0 0 0 0 0 0

        TXT;
    public const HTTP = <<<'TXT'
        file-format: BlackfireProbe
        cost-dimensions: wt cpu mu pmu
        context: http_header_host=shop.test&http_method=POST&http_uri=%2Fcheckout%3Fstep%3D2&argv%5B0%5D=step%3D2
        host: web-2
        response-code: 302

        main()==>handle//1 400 300 100 200
        main()//1 500 400 200 300
        TXT;

    public static function malformedProvider(): iterable
    {
        yield 'other format' => [
            \str_replace('BlackfireProbe', 'BlackfireApm', self::HTTP),
            'no `file-format: BlackfireProbe`',
        ];
        yield 'no wall time' => [\str_replace('cost-dimensions: wt', 'cost-dimensions: ', self::HTTP), 'has no `wt`'];
        yield 'too few costs' => [self::HTTP . "\na==>b//1 2 3\n", 'Malformed call: `a==>b//1 2 3`'];
        yield 'no costs' => [self::HTTP . "\na==>b\n", 'Malformed call'];
        yield 'not a number' => [self::HTTP . "\na==>b//1 2 x 4 5\n", 'Malformed cost `x` of `a==>b`'];
        yield 'headers only' => ["file-format: BlackfireProbe\ncost-dimensions: wt\n", 'has no calls'];
    }

    /**
     * @return \Generator<int, string>
     */
    public static function lines(string $data): \Generator
    {
        foreach (\explode("\n", $data) as $line) {
            yield $line . "\n";
        }
    }

    public function readsCallsByTheCostDimensions(): void
    {
        $profile = (new ProfileBuilder())->createProfile(self::lines(self::CLI));

        Assert::same($profile->peaks->wt, 1000);
        Assert::same($profile->peaks->cpu, 500);
        Assert::same($profile->peaks->pmu, 8192);
        $fib = self::edge($profile, 'fib==>fib@1');
        Assert::same($fib->caller, 'fib');
        Assert::same($fib->callee, 'fib');
        Assert::same($fib->cost->ct, 2);
        Assert::same($fib->cost->wt, 90);
    }

    public function describesACommand(): void
    {
        $profile = (new ProfileBuilder())->createProfile(
            self::lines(self::CLI),
            ['profile_title' => ' Cache clear ', 'expires' => '4102444800'],
        );

        Assert::same($profile->date->format('U.u'), '1795542107.093239');
        Assert::same($profile->metadata, [
            'hostname' => 'web-1',
            'details' => [
                'Command' => 'bin/console cache:clear',
                'Runtime' => 'PHP 8.3.35 (cli)',
                'Probe' => '2026.11.3',
            ],
        ]);
        Assert::same($profile->tags, ['name' => 'Cache clear', 'command' => 'bin/console cache:clear']);
    }

    public function describesARequest(): void
    {
        $date = new \DateTimeImmutable('2026-01-02 03:04:05');

        $profile = (new ProfileBuilder())->createProfile(self::lines(self::HTTP), date: $date);

        Assert::same($profile->date, $date);
        Assert::same($profile->metadata, [
            'hostname' => 'web-2',
            'details' => ['Request' => 'POST shop.test/checkout?step=2', 'Response code' => '302'],
        ]);
        Assert::same($profile->tags, ['request' => 'POST /checkout?step=2']);
    }

    public function sumsARepeatedCall(): void
    {
        $data = self::HTTP . "\nmain()==>handle//2 10 20 30 40\n";

        $profile = (new ProfileBuilder())->createProfile(self::lines($data));

        $edge = self::edge($profile, 'main()==>handle');
        Assert::same($edge->cost->ct, 3);
        Assert::same($edge->cost->wt, 410);
        Assert::same($edge->cost->pmu, 240);
    }

    #[DataProvider('malformedProvider')]
    public function rejectsMalformedData(string $data, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        (new ProfileBuilder())->createProfile(self::lines($data));
    }

    private static function edge(Profile $profile, string $key): Edge
    {
        foreach ($profile->calls as $edgeKey => $edge) {
            if ($edgeKey === $key) {
                return $edge;
            }
        }

        Assert::fail("No `$key` edge.");
    }
}

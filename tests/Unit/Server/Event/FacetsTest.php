<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Event;

use Buggregator\Trap\Server\Event\Facets;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Facets::class)]
#[Test]
final class FacetsTest
{
    public function testLevelIsLowerCaseAndEmptyOrRepeatedValuesAreSkipped(): void
    {
        $facets = (new Facets())
            ->add(Facets::LEVEL, 'WARNING')
            ->add(Facets::LEVEL, 'warning')
            ->add(Facets::HOST, '  ')
            ->add(Facets::USER, ['id' => 1])
            ->add(Facets::RELEASE, 42);

        Assert::same($facets->toArray(), ['level' => ['warning'], 'release' => ['42']]);
    }

    public function testTagsComeAsObjectOrPairs(): void
    {
        Assert::same(
            (new Facets())->addTags(['feature' => 'checkout', 'retry' => true])->toArray(),
            ['tag:feature' => ['checkout'], 'tag:retry' => ['true']],
        );
        Assert::same(
            (new Facets())->addTags([['feature', 'checkout'], ['broken'], [' ', 'x']])->toArray(),
            ['tag:feature' => ['checkout']],
        );
    }

    public function testLongValueIsCutOnCharacterBoundary(): void
    {
        $value = (new Facets())->add(Facets::HOST, 'a' . \str_repeat('ж', 150))->toArray()['host'][0];

        Assert::same(\strlen($value), 199);
        Assert::same(\preg_match('//u', $value), 1);
    }

    #[DataSet([['Sentry-Trace' => '4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-1'], '4bf92f3577b34da6a3ce929d0e0e4736'], 'sentry-trace')]
    #[DataSet([['Traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'], '4bf92f3577b34da6a3ce929d0e0e4736'], 'traceparent')]
    #[DataSet([['X-Trace-Id' => '4bf92f3577b34da6a3ce929d0e0e4736'], '4bf92f3577b34da6a3ce929d0e0e4736'], 'x-trace-id')]
    #[DataSet([['Traceparent' => '00-00000000000000000000000000000000-00f067aa0ba902b7-01'], ''], 'zero')]
    #[DataSet([['X-Trace-Id' => 'not-a-trace'], ''], 'not hex')]
    #[DataSet([[], ''], 'none')]
    public function testTraceFromHeaders(array $headers, string $trace): void
    {
        Assert::same(Facets::traceFromHeaders(static fn(string $name): string => $headers[$name] ?? ''), $trace);
    }

    public function testKnownKeysAndTags(): void
    {
        Assert::true(Facets::isKey('host'));
        Assert::true(Facets::isKey('tag:feature'));
        Assert::false(Facets::isKey('tag:'));
        Assert::false(Facets::isKey('type'));
    }
}

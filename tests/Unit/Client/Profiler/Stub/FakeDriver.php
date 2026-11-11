<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler\Stub;

use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\Internal\Driver;
use Buggregator\Trap\Client\Profiler\Internal\Model\SpeedscopeReader;
use Buggregator\Trap\Client\Profiler\Internal\Model\XHProfWriter;
use Buggregator\Trap\Client\Profiler\Internal\Recorded;
use Buggregator\Trap\Client\Profiler\Internal\Recording;

/**
 * A profiler that records nothing and encodes canned data.
 */
final class FakeDriver implements Driver
{
    public const SPEEDSCOPE = '{"$schema":"https://www.speedscope.app/file-format-schema.json",'
        . '"shared":{"frames":[{"name":"/app/index.php","file":"/app/index.php"},{"name":"foo"}]},'
        . '"profiles":[{"type":"sampled","name":"","unit":"none","startValue":0,"endValue":2,"samples":[[0,1]],"weights":[2]}]}';
    public const COLLAPSED = "main;foo;bar 3\nmain;baz 1\n";
    public const XHPROF = '{"main()":{"ct":1,"wt":10},"main()==>foo":{"ct":2,"wt":7}}';

    public int $started = 0;
    public int $stopped = 0;

    /**
     * @param non-empty-list<Format> $formats The native format first.
     * @param array<non-empty-string, scalar|null> $options
     */
    public function __construct(
        private readonly array $formats = [Format::Speedscope, Format::Collapsed],
        private readonly bool $available = true,
        private readonly string $name = 'fake',
        private readonly array $options = ['clock' => 'cpu', 'period' => 0.001],
        public readonly bool $empty = false,
        public readonly ?\Throwable $stopError = null,
        public readonly ?\Throwable $startError = null,
    ) {}

    public static function xhprof(): self
    {
        return new self([Format::XHProf], options: ['memory' => true]);
    }

    /**
     * Like Excimer: Speedscope and Collapsed natively, XHProf converted from the Speedscope document
     * with a 1 ms period.
     */
    public static function excimer(): self
    {
        return new self([Format::Speedscope, Format::Collapsed, Format::XHProf]);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function formats(): array
    {
        return $this->formats;
    }

    public function options(): array
    {
        return $this->options;
    }

    public function start(): Recording
    {
        ++$this->started;
        $this->startError === null or throw $this->startError;

        return new class($this) implements Recording {
            public function __construct(
                private readonly FakeDriver $driver,
            ) {}

            public function stop(): Recorded
            {
                ++$this->driver->stopped;
                $this->driver->stopError === null or throw $this->driver->stopError;

                $data = [
                    Format::Speedscope->value => FakeDriver::SPEEDSCOPE,
                    Format::Collapsed->value => FakeDriver::COLLAPSED,
                    Format::XHProf->value => FakeDriver::XHPROF,
                ];
                $formats = $this->driver->formats();
                if ($formats[0] === Format::Speedscope && \in_array(Format::XHProf, $formats, true)) {
                    $data[Format::XHProf->value] = XHProfWriter::json(
                        SpeedscopeReader::read(\json_decode(FakeDriver::SPEEDSCOPE, true), 0.001, false)->toGraph(),
                    );
                }

                $encoders = [];
                foreach ($formats as $format) {
                    $encoders[$format->value] = static fn(): string => $data[$format->value];
                }

                return new Recorded($encoders, $this->driver->empty);
            }
        };
    }
}

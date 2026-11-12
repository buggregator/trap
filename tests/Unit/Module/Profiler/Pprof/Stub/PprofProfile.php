<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Pprof\Stub;

/**
 * Makes a pprof profile (`profile.proto` of google/pprof), its fields in the order Go writes them.
 */
final class PprofProfile
{
    /** Values of every location ID field: packed, or one field per value. */
    public bool $packed = true;

    /** Bytes appended to the profile, such as unknown fields. */
    public string $extra = '';

    public int $period = 0;
    public int $timeNanos = 0;
    public int $durationNanos = 0;

    /** @var array{string, string}|null */
    public ?array $periodType = null;

    public ?string $defaultSampleType = null;

    /** @var array<string, int> */
    private array $strings = ['' => 0];

    /** @var list<string> */
    private array $samples = [];

    /** @var list<string> */
    private array $mappings = [];

    /** @var list<string> */
    private array $locations = [];

    /** @var list<string> */
    private array $functions = [];

    /**
     * @param list<array{string, string}> $sampleTypes Types and units.
     */
    public function __construct(
        private readonly array $sampleTypes = [['samples', 'count'], ['cpu', 'nanoseconds']],
    ) {}

    /**
     * @return int The function ID.
     */
    public function function(string $name, string $file = '', string $systemName = '', string $extra = ''): int
    {
        $id = \count($this->functions) + 1;
        $this->functions[] = Proto::int(1, $id)
            . Proto::int(2, $this->string($name))
            . Proto::int(3, $this->string($systemName))
            . Proto::int(4, $this->string($file))
            . Proto::int(5, 10)
            . $extra;

        return $id;
    }

    /**
     * @param list<int> $functions Function IDs of the lines, the innermost first, as pprof lists inlined calls.
     *
     * @return int The location ID.
     */
    public function location(array $functions, int $mapping = 0, int $address = 0, string $extra = ''): int
    {
        $id = \count($this->locations) + 1;
        $message = Proto::int(1, $id) . Proto::int(2, $mapping) . Proto::int(3, $address);
        foreach ($functions as $i => $function) {
            $message .= Proto::bytes(4, Proto::int(1, $function) . Proto::int(2, 10 + $i) . $extra);
        }
        $this->locations[] = $message . $extra;

        return $id;
    }

    /**
     * Location of a single function.
     */
    public function frame(string $name, string $file = ''): int
    {
        return $this->location([$this->function($name, $file)]);
    }

    /**
     * @return int The mapping ID.
     */
    public function mapping(string $file): int
    {
        $id = \count($this->mappings) + 1;
        $this->mappings[] = Proto::int(1, $id) . Proto::int(2, 0x1000) . Proto::int(3, 0x9000) . Proto::int(5, $this->string($file));

        return $id;
    }

    /**
     * @param list<int> $locations Location IDs, the leaf first.
     * @param list<int> $values One per sample type.
     */
    public function sample(array $locations, array $values, string $extra = ''): self
    {
        $this->samples[] = Proto::repeated(1, $locations, $this->packed)
            . Proto::repeated(2, $values, $this->packed)
            . $extra;

        return $this;
    }

    public function encode(): string
    {
        $profile = '';
        foreach ($this->sampleTypes as [$type, $unit]) {
            $profile .= Proto::bytes(1, Proto::int(1, $this->string($type)) . Proto::int(2, $this->string($unit)));
        }
        foreach ($this->samples as $sample) {
            $profile .= Proto::bytes(2, $sample);
        }
        foreach ($this->mappings as $mapping) {
            $profile .= Proto::bytes(3, $mapping);
        }
        foreach ($this->locations as $location) {
            $profile .= Proto::bytes(4, $location);
        }
        foreach ($this->functions as $function) {
            $profile .= Proto::bytes(5, $function);
        }
        $periodType = $this->periodType === null
            ? ''
            : Proto::bytes(11, Proto::int(1, $this->string($this->periodType[0])) . Proto::int(2, $this->string($this->periodType[1])));
        $default = $this->defaultSampleType === null ? '' : Proto::int(14, $this->string($this->defaultSampleType));
        foreach (\array_keys($this->strings) as $string) {
            $profile .= Proto::bytes(6, (string) $string);
        }

        return $profile
            . ($this->timeNanos === 0 ? '' : Proto::int(9, $this->timeNanos))
            . ($this->durationNanos === 0 ? '' : Proto::int(10, $this->durationNanos))
            . $periodType
            . ($this->period === 0 ? '' : Proto::int(12, $this->period))
            . $default
            . $this->extra;
    }

    private function string(string $value): int
    {
        return $this->strings[$value] ??= \count($this->strings);
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Stacks;

use Buggregator\Trap\Module\Profiler\Stacks\Functions;
use Buggregator\Trap\Module\Profiler\Stacks\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * Traces phpspy and reli-prof recorded, against phpspy's own collapser, see `tests/Stub/phpspy/README.md`
 * and `tests/Stub/reli/README.md`.
 */
#[Covers(ProfileBuilder::class)]
#[Covers(Functions::class)]
#[Test]
final class FixturesTest
{
    private const STUB_DIR = __DIR__ . '/../../../../Stub';

    #[DataSet(['phpspy/default', 88])]
    #[DataSet(['phpspy/chunked', 93])]
    #[DataSet(['phpspy/single', 96])]
    #[DataSet(['reli/phpspy', 40])]
    #[DataSet(['reli/opcode', 40])]
    #[DataSet(['reli/tracevar', 40])]
    #[DataSet(['reli/depth', 40])]
    public function testTreeMatchesPhpspyCollapser(string $name, int $samples): void
    {
        $builder = new ProfileBuilder();
        $trace = $builder->phpspy((string) \file_get_contents(self::STUB_DIR . "/$name.txt"));
        $reference = $builder->collapsed((string) \file_get_contents(self::STUB_DIR . "/$name.folded"));

        Assert::same(self::paths($trace->tree()->root), self::paths($reference->tree()->root));
        Assert::same($trace->peaks->ct, $samples);
    }

    public function testDeepSamplesAreJoinedFromChunks(): void
    {
        $profile = (new ProfileBuilder())->phpspy((string) \file_get_contents(self::STUB_DIR . '/phpspy/chunked.txt'));

        $depth = 0;
        $branches = $profile->tree()->root;
        while ($branches !== []) {
            $branches = \array_merge(...\array_map(
                static fn(Branch $branch): array => \array_values($branch->children),
                \array_values($branches),
            ));
            ++$depth;
        }
        // `main()`, 121 calls of `App\deep` and `usleep`
        Assert::same($depth, 123);
    }

    /**
     * @param array<array-key, Branch<Edge>> $branches
     *
     * @return array<string, int> Samples by call paths.
     */
    private static function paths(array $branches, string $prefix = ''): array
    {
        $paths = [];
        foreach ($branches as $branch) {
            $path = "$prefix/{$branch->item->callee}";
            $paths[$path] = ($paths[$path] ?? 0) + $branch->item->cost->ct;
            foreach (self::paths($branch->children, $path) as $child => $count) {
                $paths[$child] = ($paths[$child] ?? 0) + $count;
            }
        }
        \ksort($paths);

        return $paths;
    }
}

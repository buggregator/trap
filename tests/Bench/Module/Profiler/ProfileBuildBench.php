<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Bench\Module\Profiler;

use Buggregator\Trap\Module\Profiler\Pprof\ProfileBuilder as PprofBuilder;
use Buggregator\Trap\Module\Profiler\Speedscope\ProfileBuilder as SpeedscopeBuilder;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder as XDebugBuilder;
use Buggregator\Trap\Tests\Bench\Module\Profiler\Stub\Documents;
use Testo\Bench;

/**
 * Builds of the profiles that keep a call tree: the costs grow with the number of tree nodes and their depth.
 *
 * The scenarios are compared to each other, not gated: `tolerance` is infinite. The node limits are given,
 * not taken from the memory left, so that the runs compare. Documents are generated on the warmup call and kept,
 * so the measured calls only build.
 */
final class ProfileBuildBench
{
    /** A limit no scenario reaches. */
    private const UNLIMITED = 10_000_000;

    /** @var array<non-empty-string, array|string> */
    private static array $documents = [];

    /**
     * Within the node limit. The current scenario is a small Excimer profile: 1000 samples, about 4500 nodes.
     */
    #[Bench(
        callables: [
            'speedscope 10k varied samples, 43k nodes' => [self::class, 'speedscopeMedium'],
            'speedscope 40k varied samples, 155k nodes' => [self::class, 'speedscopeLarge'],
            'speedscope 1000 samples at max depth 1000' => [self::class, 'speedscopeMaxDepth'],
            'speedscope 10k deep recursive samples' => [self::class, 'speedscopeDeepRecursion'],
            'pprof 40k varied gzipped samples, 149k nodes' => [self::class, 'pprofLarge'],
            'xdebug 100k calls' => [self::class, 'xdebugLarge'],
        ],
        calls: 1,
        iterations: 5,
        tolerance: \INF,
    )]
    public static function speedscopeSmall(): void
    {
        self::speedscope('small', static fn(): array => Documents::framework(1000));
    }

    /**
     * Over the node limit, compacted. The current scenario prunes 155k nodes of varied samples to 30k.
     */
    #[Bench(
        callables: [
            'xdebug 400k random calls to 50k nodes' => [self::class, 'xdebugRandomCompacted'],
            'xdebug 400k calls of a loop to 50k nodes' => [self::class, 'xdebugLoopCompacted'],
            'speedscope 1000 samples at max depth 1000 to 100 nodes' => [self::class, 'speedscopeMaxDepthCompacted'],
            'pprof 40k varied gzipped samples to 30k nodes' => [self::class, 'pprofLargeCompacted'],
        ],
        calls: 1,
        iterations: 3,
        tolerance: \INF,
    )]
    public static function speedscopeLargeCompacted(): void
    {
        self::speedscope('large', static fn(): array => Documents::framework(40_000), 30_000);
    }

    public static function speedscopeMedium(): void
    {
        self::speedscope('medium', static fn(): array => Documents::framework(10_000));
    }

    public static function speedscopeLarge(): void
    {
        self::speedscope('large', static fn(): array => Documents::framework(40_000));
    }

    public static function speedscopeMaxDepth(): void
    {
        self::speedscope('max-depth', static fn(): array => Documents::maxDepth(1000));
    }

    public static function speedscopeMaxDepthCompacted(): void
    {
        self::speedscope('max-depth', static fn(): array => Documents::maxDepth(1000), 100);
    }

    public static function speedscopeDeepRecursion(): void
    {
        self::speedscope('deep-recursion', static fn(): array => Documents::deepRecursion(10_000));
    }

    public static function xdebugLarge(): void
    {
        self::xdebug('xdebug', static fn(): string => Documents::cachegrind(100_000));
    }

    public static function xdebugRandomCompacted(): void
    {
        self::xdebug('xdebug-random', static fn(): string => Documents::cachegrind(400_000), 50_000);
    }

    public static function xdebugLoopCompacted(): void
    {
        self::xdebug('xdebug-loop', static fn(): string => Documents::cachegrindLoop(400_000), 50_000);
    }

    public static function pprofLarge(): void
    {
        self::pprof('pprof', static fn(): string => Documents::pprofFramework(40_000));
    }

    public static function pprofLargeCompacted(): void
    {
        self::pprof('pprof', static fn(): string => Documents::pprofFramework(40_000), 30_000);
    }

    /**
     * Decodes as well as builds: the protobuf is read sample by sample during the build.
     *
     * @param non-empty-string $key
     * @param \Closure(): string $generate
     * @param positive-int $maxNodes
     */
    private static function pprof(string $key, \Closure $generate, int $maxNodes = self::UNLIMITED): void
    {
        /** @var string $content */
        $content = self::$documents[$key] ??= $generate();
        $builder = new PprofBuilder(maxNodes: $maxNodes);
        $builder->createProfile($builder->decode($content));
    }

    /**
     * @param non-empty-string $key
     * @param \Closure(): array $generate
     * @param positive-int $maxNodes
     */
    private static function speedscope(string $key, \Closure $generate, int $maxNodes = self::UNLIMITED): void
    {
        /** @var array $document */
        $document = self::$documents[$key] ??= $generate();
        (new SpeedscopeBuilder(maxNodes: $maxNodes))->createProfile($document);
    }

    /**
     * @param non-empty-string $key
     * @param \Closure(): string $generate
     * @param positive-int $maxNodes
     */
    private static function xdebug(string $key, \Closure $generate, int $maxNodes = self::UNLIMITED): void
    {
        /** @var string $content */
        $content = self::$documents[$key] ??= $generate();
        (new XDebugBuilder(maxNodes: $maxNodes))->createProfile($content);
    }
}

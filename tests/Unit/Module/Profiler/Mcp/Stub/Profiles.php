<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp\Stub;

use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Pprof\ProfileBuilder as PprofBuilder;
use Buggregator\Trap\Module\Profiler\Speedscope\ProfileBuilder as SpeedscopeBuilder;
use Buggregator\Trap\Module\Profiler\SPX\ProfileBuilder as SpxBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder as XDebugBuilder;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder as XHProfBuilder;
use Buggregator\Trap\Support\Spx\Metadata;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;

/**
 * Profiles of every format, caught by a {@see Trap}.
 */
final class Profiles
{
    /**
     * `main()` calls `index()`, which calls `find()` three times and `strlen()`; `main()` calls `find()` once too,
     * and `find()` calls `query()` and itself.
     */
    public const SHOP = [
        'main()' => ['ct' => 1, 'wt' => 1000, 'cpu' => 600, 'mu' => 4000, 'pmu' => 5000],
        'main()==>App\Controller::index' => ['ct' => 1, 'wt' => 800, 'cpu' => 500, 'mu' => 3000, 'pmu' => 0],
        'main()==>App\Repo::find' => ['ct' => 1, 'wt' => 100, 'cpu' => 50, 'mu' => 200, 'pmu' => 0],
        'App\Controller::index==>App\Repo::find' => ['ct' => 3, 'wt' => 500, 'cpu' => 300, 'mu' => 1000, 'pmu' => 0],
        'App\Controller::index==>strlen' => ['ct' => 10, 'wt' => 50, 'cpu' => 40, 'mu' => 100, 'pmu' => 0],
        'App\Repo::find==>PDO::query' => ['ct' => 3, 'wt' => 400, 'cpu' => 100, 'mu' => 500, 'pmu' => 0],
        'App\Repo::find==>App\Repo::find@1' => ['ct' => 1, 'wt' => 60, 'cpu' => 30, 'mu' => 50, 'pmu' => 0],
    ];

    private const STUB = __DIR__ . '/../../../../../Stub';

    /**
     * @return non-empty-string The event ID.
     */
    public static function add(Trap $trap, Type $type, Profile $profile): string
    {
        return $trap->add(new Frame(Payload::new($type, static fn(): Profile => $profile)))->event->uuid;
    }

    /**
     * @param array<non-empty-string, array{ct: int<0, max>, wt: int<0, max>, cpu: int<0, max>, mu: int<0, max>, pmu: int<0, max>}> $calls
     */
    public static function xhprof(array $calls = self::SHOP, array $metadata = []): Profile
    {
        return (new XHProfBuilder())->createProfile(metadata: $metadata, calls: $calls);
    }

    /**
     * The real application profile: 582 functions, a hot path 473 calls long.
     */
    public static function xhprofApp(): Profile
    {
        /** @var array{profile: array<non-empty-string, array{ct: int<0, max>, wt: int<0, max>, cpu: int<0, max>, mu: int<0, max>, pmu: int<0, max>}>} $data */
        $data = \json_decode((string) \file_get_contents(self::STUB . '/xhprof/app.json'), true);

        return self::xhprof($data['profile'], ['app_name' => 'app']);
    }

    public static function xdebug(): Profile
    {
        return (new XDebugBuilder())->createProfile((string) \file_get_contents(self::STUB . '/xdebug/shared.cachegrind'));
    }

    public static function spx(): Profile
    {
        return (new SpxBuilder())->createProfile(
            Metadata::fromJson((string) \file_get_contents(self::STUB . '/spx/real/metrics.json')),
            [(string) \file_get_contents(self::STUB . '/spx/real/metrics.txt.gz')],
        );
    }

    public static function pprof(): Profile
    {
        $builder = new PprofBuilder();

        return $builder->createProfile(
            $builder->decode((string) \file_get_contents(self::STUB . '/pprof/upstream/alloc_objects.pb.gz')),
        );
    }

    public static function speedscope(): Profile
    {
        $builder = new SpeedscopeBuilder();

        return $builder->createProfile(
            $builder->decode((string) \file_get_contents(self::STUB . '/speedscope/excimer-cpu.json')),
        );
    }

    /**
     * @return array<non-empty-string, array{Type, \Closure(): Profile}> By labels.
     */
    public static function formats(): array
    {
        return [
            'XHProf' => [Type::XHProf, self::xhprofApp(...)],
            'Xdebug' => [Type::XDebug, self::xdebug(...)],
            'SPX' => [Type::SPX, self::spx(...)],
            'pprof' => [Type::Pprof, self::pprof(...)],
            'Speedscope' => [Type::Speedscope, self::speedscope(...)],
        ];
    }
}

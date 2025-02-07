<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\XHProf;

use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileBuilder::class)]
class ProfileBuilderTest extends TestCase
{
    private const STUB = [
        'foo==>bar' => [
            'ct' => 2,          # number of calls to bar() from foo()
            'wt' => 37,         # time in bar() when called from foo()
            'cpu' => 0,         # cpu time in bar() when called from foo()
            'mu' => 2208,       # change in PHP memory usage in bar() when called from foo()
            'pmu' => 0,         # change in PHP peak memory usage in bar() when called from foo()
        ],
        'foo==>strlen' => [
            'ct' => 2,
            'wt' => 3,
            'cpu' => 0,
            'mu' => 624,
            'pmu' => 0,
        ],
        'bar==>bar@1' => [
            'ct' => 1,
            'wt' => 2,
            'cpu' => 0,
            'mu' => 856,
            'pmu' => 0,
        ],
        'main()==>foo' => [
            'ct' => 1,
            'wt' => 104,
            'cpu' => 0,
            'mu' => 4168,
            'pmu' => 0,
        ],
        'main()==>xhprof_disable' => [
            'ct' => 1,
            'wt' => 1,
            'cpu' => 0,
            'mu' => 344,
            'pmu' => 0,
        ],
        'main()' => [           # fake symbol representing root
            'ct' => 1,
            'wt' => 139,
            'cpu' => 0,
            'mu' => 5936,
            'pmu' => 0,
        ],
    ];

    public function testCreateProfileCommonFields(): void
    {
        $date = new \DateTimeImmutable();
        $metadata = ['foo' => 'bar'];
        $tags = ['baz', 'qux'];

        $profile = (new ProfileBuilder())
            ->createProfile($date, $metadata, $tags, self::STUB);

        self::assertSame($date, $profile->date);
        self::assertSame($metadata, $profile->metadata);
        self::assertSame($tags, $profile->tags);
    }

    public function testTreeAllCalls(): void
    {
        $profile = (new ProfileBuilder())
            ->createProfile(new \DateTimeImmutable(), calls: self::STUB);

        self::assertArrayHasKey('main()', $profile->calls->all);
        self::assertArrayHasKey('foo', $profile->calls->all);
        self::assertArrayHasKey('bar', $profile->calls->all);
        self::assertArrayHasKey('xhprof_disable', $profile->calls->all);
        self::assertArrayHasKey('strlen', $profile->calls->all);
        self::assertArrayHasKey('bar@1', $profile->calls->all);
        self::assertCount(6, $profile->calls->all);
    }

    public function testTree(): void
    {
        $profile = (new ProfileBuilder())
            ->createProfile(new \DateTimeImmutable(), calls: self::STUB);

        self::assertSame(['main()'], \array_keys($profile->calls->root));
        self::assertCount(2, $profile->calls->root['main()']->children);
        self::assertSame('foo', $profile->calls->root['main()']->children[0]->item->callee);
        self::assertSame('xhprof_disable', $profile->calls->root['main()']->children[1]->item->callee);

        /** @var Branch<Edge> $foo */
        $foo = $profile->calls->root['main()']->children[0];
        self::assertCount(2, $foo->children);
        self::assertSame('bar', $foo->children[0]->item->callee);
        self::assertSame('strlen', $foo->children[1]->item->callee);

        /** @var Branch<Edge> $bar */
        $bar = $foo->children[0];
        self::assertCount(1, $bar->children);
        self::assertSame('bar', $bar->children[0]->item->callee);
        self::assertSame(2, $bar->children[0]->item->cost->wt);
    }
}

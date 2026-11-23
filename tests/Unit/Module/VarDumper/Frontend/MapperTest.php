<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\VarDumper\Frontend;

use Buggregator\Trap\Module\VarDumper\Frame as VarDumperFrame;
use Buggregator\Trap\Module\VarDumper\Frontend\Mapper;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Mapper::class)]
#[Test]
final class MapperTest
{
    public function testHostOfTheRequestIsTheFacet(): void
    {
        $event = (new Mapper())->map(self::frame(['request' => ['uri' => 'https://shop.test:8443/cart?x=1']]));

        Assert::same($event->facets, ['host' => ['shop.test']]);
    }

    public function testDumpOfCliHasNoFacets(): void
    {
        $event = (new Mapper())->map(self::frame(['cli' => ['command_line' => 'bin/console']]));

        Assert::same($event->facets, []);
    }

    private static function frame(array $context): VarDumperFrame
    {
        return new VarDumperFrame(\base64_encode(\serialize([(new VarCloner())->cloneVar('x'), $context])));
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Buggregator\Trap\Client\TrapHandle\Counter;
use Buggregator\Trap\Client\TrapHandle\Dumper;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Dumper\DataDumperInterface;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;

class Base
{
    protected static ?Data $lastData = null;

    #[BeforeTest]
    protected function setUp(): void
    {
        self::$lastData = null;
        Counter::clear();

        Dumper::setDumper(new class(static function (Data $data): void {
            self::$lastData = $data;
        }) implements DataDumperInterface {

            public function __construct(
                private readonly \Closure $capture,
            ) {}

            public function dump(Data $data): ?string
            {
                ($this->capture)($data);
                return null;
            }
        });
    }

    #[AfterTest]
    protected function tearDown(): void
    {
        self::$lastData = null;
        Counter::clear();
        Dumper::setDumper(null);
    }
}

<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class FunctionTrapTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testLeak(): void
    {
        $object = new \stdClass();
        $ref = \WeakReference::create($object);

        trap($object, $object);
        unset($object);

        self::assertNull($ref->get());
    }
}

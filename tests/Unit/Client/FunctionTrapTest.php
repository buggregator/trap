<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Testo\Assert;
use Testo\Test;

#[Test]
final class FunctionTrapTest
{
    public function testLeak(): void
    {
        $object = new \stdClass();
        $ref = \WeakReference::create($object);

        trap($object, $object);
        unset($object);

        Assert::null($ref->get());
    }
}

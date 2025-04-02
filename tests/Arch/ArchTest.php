<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Arch;

use PHPUnit\Architecture\ArchitectureAsserts;
use PHPUnit\Framework\TestCase;

final class ArchTest extends TestCase
{
    use ArchitectureAsserts;

    protected array $excludedPaths = [
        'resources',
        'tests',
        'vendor',
        'src/Test',
    ];

    public function testForgottenDebugFunctions(): void
    {
        $functions = ['dd', 'exit', 'die', 'var_dump', 'echo', 'print', 'dump', 'tr', 'td'];
        $layer = $this->layer();

        foreach ($layer as $object) {
            foreach ($object->uses as $use) {
                foreach ($functions as $function) {
                    $function === $use and throw new \Exception(
                        \sprintf(
                            'Function `%s()` is used in %s.',
                            $function,
                            $object->name,
                        ),
                    );
                }
            }
        }

        $this->assertTrue(true);
    }
}

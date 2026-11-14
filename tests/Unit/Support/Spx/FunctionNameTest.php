<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Spx;

use Buggregator\Trap\Support\Spx\FunctionName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(FunctionName::class)]
#[Test]
final class FunctionNameTest
{
    #[DataSet(['/app/public/index.php', true], 'script')]
    #[DataSet(['C:\app\index.php', true], 'Windows path')]
    #[DataSet(['/app/bin/console', true], 'script without extension')]
    #[DataSet(['config/routes.inc', true], 'relative include')]
    #[DataSet(['app.phar', true], 'phar')]
    #[DataSet(['[no active file]', true], 'no file')]
    #[DataSet(['App\Kernel::handle', false], 'method')]
    #[DataSet(['strlen', false], 'function')]
    #[DataSet(['App\Http\{closure}', false], 'closure')]
    public function testTellsFileScopeCode(string $name, bool $fileScope): void
    {
        Assert::same(FunctionName::isFileScope($name), $fileScope);
    }
}

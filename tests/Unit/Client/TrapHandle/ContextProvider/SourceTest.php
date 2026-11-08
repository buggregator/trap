<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\TrapHandle\ContextProvider;

use Buggregator\Trap\Client\TrapHandle\ContextProvider\Source;
use Buggregator\Trap\Client\TrapHandle\StaticState;
use Symfony\Component\VarDumper\VarDumper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Source::class)]
final class SourceTest
{
    private ?StaticState $previousState = null;

    #[BeforeTest]
    public function rememberState(): void
    {
        $this->previousState = StaticState::getValue();
    }

    #[AfterTest]
    public function restoreState(): void
    {
        StaticState::setState($this->previousState);
    }

    public function testHere(): void
    {
        $this->staticState();

        Assert::same($this->getContext()['name'], \basename(__FILE__));
        Assert::same($this->getContext()['file'], __FILE__);
        Assert::same($this->getContext()['line'], __LINE__ - 4);
    }

    public function testOnDestruct(): void
    {
        $this->staticState();

        new class {
            public function __destruct()
            {
                Assert::same((new Source())->getContext()['name'], \basename(__FILE__));
                Assert::same((new Source())->getContext()['file'], __FILE__);
            }
        };
    }

    public function testDumpCallerIsTheFirstPlainFunctionFrame(): void
    {
        $this->traceState([
            ['file' => '/app/vendor/var-dumper/Resources/functions/dump.php', 'line' => 10, 'function' => 'trap'],
            ['file' => '/app/vendor/var-dumper/Resources/functions/dump.php', 'line' => 20, 'function' => 'dump', 'class' => VarDumper::class],
            ['file' => '/app/src/Helper.php', 'line' => 30, 'function' => 'format', 'class' => 'App\Helper', 'object' => new \stdClass()],
            ['file' => '/app/src/callback.php', 'line' => 40, 'function' => 'call_user_func_array'],
            ['file' => '/app/src/Controller.php', 'line' => 50, 'function' => 'handle'],
            ['file' => '/app/src/Kernel.php', 'line' => 60, 'function' => 'run'],
        ]);

        $context = (new Source())->getContext();

        Assert::same($context['name'], 'Controller.php');
        Assert::same($context['file'], '/app/src/Controller.php');
        Assert::same($context['line'], 50);
        Assert::false($context['file_excerpt']);
    }

    public function testDumpFrameIsUsedWhenNoCallerWithinLimit(): void
    {
        $this->traceState([
            ['file' => '/app/first.php', 'line' => 1, 'function' => 'trap'],
            ['file' => '/app/dumper.php', 'line' => 2, 'function' => 'dump', 'class' => VarDumper::class],
            ['file' => '/app/Service.php', 'line' => 3, 'function' => 'call', 'class' => 'App\Service'],
            ['file' => '/app/caller.php', 'line' => 4, 'function' => 'outOfLimit'],
        ]);

        $context = (new Source(limit: 3))->getContext();

        Assert::same($context['name'], 'dumper.php');
        Assert::same($context['file'], '/app/dumper.php');
        Assert::same($context['line'], 2);
    }

    public function testDumpFrameWithoutLocationKeepsTopFrameLocation(): void
    {
        $this->traceState([
            ['file' => '/app/top.php', 'line' => 7, 'function' => 'trap'],
            ['function' => 'dump', 'class' => VarDumper::class],
        ]);

        $context = (new Source())->getContext();

        Assert::same($context['file'], '/app/top.php');
        Assert::same($context['line'], 7);
    }

    #[DataSet(['-'], 'dash')]
    #[DataSet(['Standard input code'], 'literal name')]
    public function testStandardInputName(string $file): void
    {
        $this->traceState([['file' => $file, 'line' => 3, 'function' => 'trap']]);

        $context = (new Source())->getContext();

        Assert::same($context['name'], 'Standard input code');
        Assert::same($context['file'], $file);
    }

    public function testProjectDirAddsRelativePathForFileInsideProject(): void
    {
        $projectDir = \DIRECTORY_SEPARATOR . 'app';
        $file = $projectDir . \DIRECTORY_SEPARATOR . 'src' . \DIRECTORY_SEPARATOR . 'Foo.php';
        $this->traceState([['file' => $file, 'line' => 5, 'function' => 'trap']]);

        $context = (new Source(projectDir: $projectDir))->getContext();

        Assert::same($context['project_dir'], $projectDir);
        Assert::same($context['file_relative'], 'src' . \DIRECTORY_SEPARATOR . 'Foo.php');
    }

    public function testProjectDirWithoutRelativePathForFileOutsideProject(): void
    {
        $this->traceState([['file' => '/other/Foo.php', 'line' => 5, 'function' => 'trap']]);

        $context = (new Source(projectDir: '/app'))->getContext();

        Assert::same($context['project_dir'], '/app');
        Assert::false(\array_key_exists('file_relative', $context));
    }

    /**
     * @return array{
     *     name: string,
     *     file: string,
     *     line: int,
     *     file_excerpt: bool
     * }
     */
    private function getContext(): array
    {
        return (new Source())->getContext();
    }

    /**
     * Create a new StaticState instance.
     */
    private function staticState(): StaticState
    {
        return StaticState::new();
    }

    private function traceState(array $trace): void
    {
        StaticState::new(stackTrace: [], stackTraceWithObjects: $trace);
    }
}

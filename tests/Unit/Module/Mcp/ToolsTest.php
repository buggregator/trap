<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp;

use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\DuplicateToolsModule;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\EchoTool;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\PlainTool;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\ToolsModule;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Tools::class)]
final class ToolsTest
{
    /**
     * @param list<class-string> $modules
     */
    public static function create(array $modules = [ToolsModule::class]): Tools
    {
        $container = new ObjectContainer();

        return new Tools(new Modules($container, $modules), $container);
    }

    public function testToolsOfModulesInListOrder(): void
    {
        $tools = self::create();

        Assert::same(\array_map(static fn(object $tool): string => $tool::class, $tools->all()), [
            EchoTool::class,
            PlainTool::class,
        ]);
        Assert::instanceOf($tools->get('plain'), PlainTool::class);
        Assert::null($tools->get('missing'));
    }

    public function testNoTools(): void
    {
        Assert::same(self::create([])->all(), []);
    }

    public function testDuplicateNameFails(): never
    {
        Expect::exception(\LogicException::class)->withMessageContaining('MCP tool `echo`');

        self::create([ToolsModule::class, DuplicateToolsModule::class]);
    }
}

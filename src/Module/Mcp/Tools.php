<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp;

use Buggregator\Trap\Server\Application\Module\Modules;
use Internal\Container\Container;

/**
 * The tools of the modules, in the module list order.
 *
 * @internal
 */
final class Tools
{
    /** @var array<non-empty-string, Tool> */
    private readonly array $tools;

    public function __construct(Modules $modules, Container $container)
    {
        $tools = [];
        foreach ($modules->implementing(ProvidesMcpTools::class) as $module) {
            foreach ($module->mcpTools() as $class) {
                $tool = $container->get($class);
                isset($tools[$tool->name()]) and throw new \LogicException(\sprintf(
                    'MCP tool `%s` of %s is already given by %s.',
                    $tool->name(),
                    $class,
                    $tools[$tool->name()]::class,
                ));
                $tools[$tool->name()] = $tool;
            }
        }

        $this->tools = $tools;
    }

    /**
     * @return list<Tool>
     */
    public function all(): array
    {
        return \array_values($this->tools);
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }
}

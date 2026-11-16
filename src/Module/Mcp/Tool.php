<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp;

/**
 * A tool the MCP clients list with `tools/list` and run with `tools/call`.
 *
 * @internal
 */
interface Tool
{
    /**
     * Unique among the tools: letters, digits, `_`, `-` and `.`.
     *
     * @return non-empty-string
     */
    public function name(): string;

    /**
     * Human-readable name.
     */
    public function title(): string;

    /**
     * What the tool does and when to use it: the model reads it to choose the tool.
     */
    public function description(): string;

    /**
     * JSON Schema of the arguments, with `type: object`. Use `new \stdClass()` for an empty JSON object,
     * such as no `properties`: an empty array is encoded as a JSON list.
     *
     * @return array<non-empty-string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Hints on the behavior: `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`.
     *
     * @return array<non-empty-string, bool>
     */
    public function annotations(): array;

    /**
     * An exception is reported to the client as a failed tool call with its message, so the model can react.
     *
     * @param array<array-key, mixed> $arguments Not validated against {@see self::inputSchema()}.
     *
     * @return string|array<array-key, mixed> Text of the result; an array is sent as JSON text.
     */
    public function call(array $arguments): string|array;
}

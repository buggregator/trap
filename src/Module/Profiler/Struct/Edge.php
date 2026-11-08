<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

/**
 * All the calls of a callee made from a caller, with their total cost.
 *
 * @internal
 */
final class Edge implements \JsonSerializable
{
    /**
     * Callee ID: a function name with a recursion level suffix, e.g. `foo@1`.
     *
     * @var non-empty-string
     */
    public readonly string $calleeId;

    /**
     * Caller ID in the same format as {@see self::$calleeId}; `null` for a root.
     *
     * @var non-empty-string|null
     */
    public readonly ?string $callerId;

    /**
     * @param non-empty-string|null $caller Caller function name.
     * @param non-empty-string $callee Callee function name.
     * @param non-empty-string|null $calleeId Defaults to the callee name.
     * @param non-empty-string|null $callerId Defaults to the caller name.
     */
    public function __construct(
        public readonly ?string $caller,
        public readonly string $callee,
        public readonly Cost $cost,
        ?string $calleeId = null,
        ?string $callerId = null,
    ) {
        $this->calleeId = $calleeId ?? $callee;
        $this->callerId = $callerId ?? $caller;
    }

    /**
     * The calls are nested in other calls of the same function: the callee ID carries a recursion level.
     */
    public function isRecursive(): bool
    {
        return $this->calleeId !== $this->callee;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'caller' => $this->caller,
            'callee' => $this->callee,
            'cost' => $this->cost,
        ];
    }
}

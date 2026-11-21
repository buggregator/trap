<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Trace;

/**
 * One call as the tree and search answers show it. Times are seconds, memory is bytes.
 *
 * @internal
 */
final class Node implements \JsonSerializable
{
    /** One argument or return value, in bytes */
    public const MAX_VALUE = 512;

    /** Arguments per call */
    public const MAX_ARGS = 20;

    public float $incl = 0.0;
    public float $self = 0.0;

    /** Memory change from entry to exit */
    public int $memory = 0;

    public ?string $return = null;

    /** Direct callees */
    public int $children = 0;

    /** Its exit was seen: false when the trace was cut */
    public bool $done = false;

    /**
     * Search results: the callers from the top level down to the direct caller.
     *
     * @var list<array{nr: int, func: string, offset: int}>
     */
    public array $path = [];

    /** Time of the direct callees */
    public float $childTime = 0.0;

    /** @var list<string> */
    public readonly array $args;

    /**
     * @param int $offset Where its entry line starts: what the tree takes as `parent` and `from`.
     */
    public function __construct(
        public readonly Record $entry,
        public readonly int $offset,
    ) {
        $this->args = \array_map(self::clip(...), \array_slice($entry->args, 0, self::MAX_ARGS));
    }

    public static function clip(string $value): string
    {
        if (\strlen($value) <= self::MAX_VALUE) {
            return $value;
        }

        $cut = self::MAX_VALUE;
        // Keeps a UTF-8 character whole
        while ($cut > 0 && (\ord($value[$cut]) & 0xC0) === 0x80) {
            --$cut;
        }

        return \substr($value, 0, $cut) . '…';
    }

    public function exit(Record $exit): void
    {
        $this->incl = \max($exit->time - $this->entry->time, 0.0);
        $this->self = \max($this->incl - $this->childTime, 0.0);
        $this->memory = $exit->memory - $this->entry->memory;
        $this->done = true;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $e = $this->entry;
        $data = [
            'nr' => $e->nr,
            'func' => $e->func,
            'user' => $e->user,
        ];
        $e->include === '' or $data['include'] = $e->include;
        $data += [
            'file' => $e->file,
            'line' => $e->line,
            'level' => $e->level,
            'start' => $e->time,
            'incl' => $this->incl,
            'self' => $this->self,
            'memory' => $this->memory,
        ];
        $this->args === [] or $data['args'] = $this->args;
        $this->return === null or $data['return'] = $this->return;
        $data += [
            'children' => $this->children,
            'done' => $this->done,
            'offset' => $this->offset,
        ];
        $this->path === [] or $data['path'] = $this->path;

        return $data;
    }
}

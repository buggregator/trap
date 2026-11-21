<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug;

/**
 * Saved breakpoints: sent to every session driven here. They live in the memory of the process.
 *
 * @psalm-type Breakpoint = array{
 *     id: int, type: 'line'|'exception', file?: string, line?: int, exception?: string, condition?: string,
 *     enabled: bool, source: 'ui'|'mcp', created_at: string
 * }
 *
 * @internal
 */
final class Breakpoints
{
    /** @var array<int, Breakpoint> */
    private array $list = [];

    private int $nextId = 0;

    /**
     * What a session compares to tell a breakpoint changed.
     *
     * @param Breakpoint $bp
     */
    public static function signature(array $bp): string
    {
        return \implode("\0", [$bp['type'], $bp['file'] ?? '', $bp['line'] ?? 0, $bp['exception'] ?? '', $bp['condition'] ?? '']);
    }

    /**
     * A plain absolute path as PHP sees it becomes a `file://` URI, as Xdebug names files.
     */
    public static function fileUri(string $file): string
    {
        return match (true) {
            \str_starts_with($file, '/') => 'file://' . $file,
            \preg_match('#^[a-z]:[\\\\/]#i', $file) === 1 => 'file:///' . \substr($file, 0, 2) . \str_replace('\\', '/', \substr($file, 2)),
            default => $file,
        };
    }

    /**
     * @return list<Breakpoint>
     */
    public function all(): array
    {
        return \array_values($this->list);
    }

    /**
     * @return Breakpoint|null
     */
    public function get(int $id): ?array
    {
        return $this->list[$id] ?? null;
    }

    /**
     * @param array<array-key, mixed> $fields `type`, `file`, `line`, `exception`, `condition`, `enabled`.
     * @param 'ui'|'mcp' $source
     *
     * @return Breakpoint
     *
     * @throws DebuggerError
     */
    public function add(array $fields, string $source): array
    {
        $bp = self::normalize($fields + ['enabled' => true], 0, $source, \date(\DATE_RFC3339_EXTENDED));
        $bp['id'] = ++$this->nextId;

        return $this->list[$bp['id']] = $bp;
    }

    /**
     * @param array<array-key, mixed> $fields A field left out keeps its value.
     *
     * @return Breakpoint
     *
     * @throws DebuggerError
     */
    public function update(int $id, array $fields): array
    {
        $bp = $this->list[$id] ?? throw DebuggerError::notFound('No such breakpoint.');

        return $this->list[$id] = self::normalize($fields + $bp, $id, $bp['source'], $bp['created_at']);
    }

    /**
     * @throws DebuggerError
     */
    public function delete(int $id): void
    {
        isset($this->list[$id]) or throw DebuggerError::notFound('No such breakpoint.');
        unset($this->list[$id]);
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param 'ui'|'mcp' $source
     *
     * @return Breakpoint
     *
     * @throws DebuggerError
     */
    private static function normalize(array $fields, int $id, string $source, string $createdAt): array
    {
        $string = static fn(string $key): string => \is_scalar($fields[$key] ?? null) ? \trim((string) $fields[$key]) : '';
        $file = self::fileUri($string('file'));
        $exception = \ltrim($string('exception'), '\\');
        $type = $string('type');
        $type === '' and $type = $exception !== '' && $file === '' ? 'exception' : 'line';
        $line = \is_numeric($fields['line'] ?? null) ? (int) $fields['line'] : 0;
        $condition = $string('condition');
        $enabled = (bool) ($fields['enabled'] ?? true);

        if (\str_contains($file . $exception . $condition, "\0")) {
            throw DebuggerError::badRequest('file, exception and condition must not contain NUL.');
        }

        $bp = ['id' => $id, 'type' => $type];
        switch ($type) {
            case 'line':
                $file !== '' && $line >= 1 or throw DebuggerError::badRequest(
                    'A line breakpoint needs file and line (>= 1).',
                );
                \str_starts_with($file, 'file://') or throw DebuggerError::badRequest(
                    'file must be an absolute path as PHP sees it (/var/www/x.php, C:\app\x.php) or a file:// URI.',
                );
                $bp += ['file' => $file, 'line' => $line];
                $condition === '' or $bp['condition'] = $condition;
                break;
            case 'exception':
                $exception !== '' && \preg_match('/[\s"\']/', $exception) !== 1 or throw DebuggerError::badRequest(
                    'An exception breakpoint needs exception: a class name, e.g. RuntimeException, or * for any.',
                );
                $bp['exception'] = $exception;
                break;
            default:
                throw DebuggerError::badRequest("type must be line or exception, got `$type`.");
        }

        /** @var Breakpoint */
        return $bp + ['enabled' => $enabled, 'source' => $source, 'created_at' => $createdAt];
    }
}

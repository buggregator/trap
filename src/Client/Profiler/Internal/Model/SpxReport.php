<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal\Model;

use Buggregator\Trap\Client\Profiler\Internal\Warnings;
use Buggregator\Trap\Support\Spx\Archive;
use Buggregator\Trap\Support\Spx\FunctionName;
use Buggregator\Trap\Support\Spx\Metadata;
use Buggregator\Trap\Support\Spx\TraceReader;

/**
 * An SPX full report in `spx.data_dir`: the `<key>.json` metadata, read, and the gzipped `<key>.txt.gz` trace,
 * left in its file and read in chunks when it is converted or sent.
 *
 * The trace is read with the same {@see TraceReader} as Trap reads it, into a {@see CallTree} with the calls as counts.
 *
 * @internal
 */
final class SpxReport
{
    /** Size limit of a trace in bytes: Trap takes no larger. */
    public const MAX_SIZE = 64 * 1024 * 1024;

    private ?CallTree $tree = null;

    /** @var bool|null Whether a destination took the profile of the report; null until it is told. */
    private ?bool $delivered = null;

    /** @var array<int, \WeakReference<self>> Reports taken aside, put back at shutdown if they are still there */
    private static array $live = [];

    private static bool $shutdown = false;

    /**
     * @param non-empty-string $key
     * @param string $base The path of the report without the extensions, as SPX wrote it.
     * @param int<0, max> $traceSize
     * @param bool $aside The report is taken aside: it is deleted once delivered, otherwise put back.
     */
    private function __construct(
        public readonly string $key,
        public readonly Metadata $metadata,
        private readonly string $json,
        private readonly string $base,
        private string $tracePath,
        private readonly int $traceSize,
        private bool $aside,
    ) {
        if ($aside) {
            self::$live[\spl_object_id($this)] = \WeakReference::create($this);
            // A fatal error skips the destructors; the shutdown functions still run
            self::$shutdown or \register_shutdown_function(self::shutdown(...));
            self::$shutdown = true;
        }
    }

    /**
     * Unless kept, the report is taken out of the way of a Trap observing the directory, and of the SPX web UI,
     * which lists every `.json` there: both files are moved aside, `.trap-` prefixed and with no `.json` suffix,
     * see {@see self::settle()}.
     *
     * @param non-empty-string $key The report key `spx_profiler_stop()` returns.
     * @param bool $keep Leave the files where SPX wrote them.
     *
     * @throws \RuntimeException When the report can't be read, is too large, or its metadata is invalid, or it
     *         can't be moved aside, which would get it delivered twice with a Trap observing the directory;
     *         the files are kept then.
     */
    public static function read(string $dir, string $key, bool $keep): self
    {
        \preg_match('/^spx-full-[\w.-]+$/', $key) === 1
            or throw new \InvalidArgumentException(\sprintf('Unexpected SPX report key `%s`.', $key));
        $base = \rtrim($dir, '/\\') . \DIRECTORY_SEPARATOR . $key;
        $size = @\filesize("$base.txt.gz");
        $json = @\file_get_contents("$base.json", length: Metadata::MAX_SIZE + 1);
        $size === false || $json === false and throw new \RuntimeException(
            \sprintf('Unable to read the SPX report `%s` in `%s`.', $key, $dir),
        );

        $size = \max(0, $size);
        $size > self::MAX_SIZE and throw new \RuntimeException(\sprintf(
            'The SPX trace `%s.txt.gz` is kept: it is larger than the %d MB Trap takes.',
            $base,
            self::MAX_SIZE >> 20,
        ));
        try {
            $metadata = Metadata::fromJson($json);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            throw new \RuntimeException(\sprintf('The SPX report `%s.json` is kept: %s', $base, $invalidArgumentException->getMessage()), $invalidArgumentException->getCode(), previous: $invalidArgumentException);
        }

        if ($keep) {
            return new self($key, $metadata, $json, $base, "$base.txt.gz", $size, false);
        }

        $left = "The SPX report `$base` is not sent: it can't be moved aside, and is left for a Trap observing"
            . ' the directory and the SPX web UI.';
        // The trace first: a trace with no metadata is no report for an observing Trap or the SPX web UI
        [$trace, $meta] = self::asidePaths($base, $key);
        @\rename("$base.txt.gz", $trace) or throw new \RuntimeException($left);
        if (!@\rename("$base.json", $meta)) {
            @\rename($trace, "$base.txt.gz") or throw new \RuntimeException(
                "The SPX report `$base` is not sent: it can't be moved aside, and its trace can't be put back from `$trace`.",
            );
            throw new \RuntimeException($left);
        }

        return new self($key, $metadata, $json, $base, $trace, $size, true);
    }

    /**
     * Puts the reports taken aside and not delivered back, and deletes the delivered ones: after a fatal error,
     * the destructors don't run.
     */
    public static function shutdown(): void
    {
        foreach (self::$live as $reference) {
            $report = $reference->get();
            $report === null or $report->release();
        }
        self::$live = [];
    }

    /**
     * Puts a report taken aside back where SPX wrote it, when no destination took the profile: it is the only copy.
     * A delivered report is deleted once it is no longer used.
     *
     * @return non-empty-string|null What became of an undelivered report, for a warning.
     */
    public function settle(bool $delivered): ?string
    {
        $this->delivered = $delivered;
        if ($delivered || !$this->aside) {
            return null;
        }

        return $this->putBack();
    }

    /**
     * Whether the report records no calls.
     */
    public function isEmpty(): bool
    {
        return $this->metadata->callCount === 0;
    }

    /**
     * @param string|null $customMetadata Replaces the custom metadata of the report: the metadata is written anew.
     *        `spx_profiler_full_report_set_custom_metadata_str()` is not used: it crashes PHP when SPX didn't start.
     *
     * @return array{int<0, max>, \Generator<int, string>} The size and the chunks of the report as {@see Archive}.
     */
    public function archive(?string $customMetadata = null): array
    {
        $json = $this->json;
        if ($customMetadata !== null) {
            $data = $this->metadata->data;
            $data['custom_metadata_str'] = $customMetadata;
            $json = \json_encode(
                $data,
                \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
                | \JSON_INVALID_UTF8_SUBSTITUTE,
            ) . "\n";
        }

        return Archive::stream([
            "{$this->key}.json" => [\strlen($json), [$json]],
            "{$this->key}.txt.gz" => [$this->traceSize, $this->trace()],
        ]);
    }

    /**
     * Self costs per call path: the wall and CPU time, and the calls.
     *
     * @throws \InvalidArgumentException When the trace is malformed.
     */
    public function tree(): CallTree
    {
        if ($this->tree instanceof CallTree) {
            return $this->tree;
        }

        $tree = new CallTree(calls: true);
        $wt = $this->metadata->column('wt');
        $cpu = $this->metadata->column('ct');
        /** @var list<array{int, float, float, float, float}> $path Node, wall and CPU time at its start, of its callees */
        $path = [];
        $names = TraceReader::read(
            $this->trace(),
            \count($this->metadata->metrics),
            $this->metadata->functionCount,
            /** @param list<float> $values */
            static function (int $function, bool $start, array $values) use ($tree, $wt, $cpu, &$path): void {
                $time = $wt === null ? 0.0 : $values[$wt];
                $cpuTime = $cpu === null ? 0.0 : $values[$cpu];
                if ($start) {
                    $parent = $path === [] ? CallTree::ROOT : $path[\array_key_last($path)][0];
                    $node = $tree->node($parent, \max(0, $function), '(unknown)');
                    $tree->add($node, 0, 0, 1);
                    $path[] = [$node, $time, $cpuTime, 0.0, 0.0];
                    return;
                }

                [$node, $from, $cpuFrom, $callees, $cpuCallees] = \array_pop($path) ?? [CallTree::ROOT, 0.0, 0.0, 0.0, 0.0];
                $tree->add(
                    $node,
                    \max(0, (int) \round($time - $from - $callees)),
                    \max(0, (int) \round($cpuTime - $cpuFrom - $cpuCallees)),
                    0,
                );
                if ($path !== []) {
                    $last = \array_key_last($path);
                    $path[$last][3] += $time - $from;
                    $path[$last][4] += $cpuTime - $cpuFrom;
                }
            },
        );

        // SPX names file-scope code by its file: as its own file, the name tells `CallTree` the code is file-scope
        $files = [];
        foreach ($names as $index => $name) {
            FunctionName::isFileScope($name) and $files[$index] = $name;
        }
        $tree->rename($names, $files);

        return $this->tree = $tree;
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * @return array{string, string} Where the trace and the metadata of a report taken aside are.
     */
    private static function asidePaths(string $base, string $key): array
    {
        $aside = \dirname($base) . \DIRECTORY_SEPARATOR . ".trap-$key";

        return ["$aside.txt.gz", "$aside.metadata"];
    }

    /**
     * Moves the modification time past the one the file had: `rename()` keeps it, and a file put back within
     * the second SPX wrote it would keep it touched too, looking unchanged to a Trap that listed it before.
     */
    private function touch(string $path): void
    {
        \clearstatcache(true, $path);
        $mtime = @\filemtime($path);
        @\touch($path, \max(\time(), $mtime === false ? 0 : $mtime + 1));
    }

    /**
     * Deletes a delivered report taken aside, and puts back one not delivered.
     */
    private function release(): void
    {
        if (!$this->aside) {
            return;
        }

        unset(self::$live[\spl_object_id($this)]);
        if ($this->delivered === true) {
            $this->aside = false;
            [$trace, $meta] = self::asidePaths($this->base, $this->key);
            @\unlink($trace);
            @\unlink($meta);
            return;
        }

        // Not told otherwise: the destinations never ran, as when the process stopped on an error
        $warning = $this->putBack();
        Warnings::once("spx:kept:{$this->base}", $warning, ['driver' => 'spx', 'reason' => 'kept']);
    }

    /**
     * The trace first: the metadata, last, completes the report for an observing Trap and the SPX web UI.
     * Touched, the files look changed to a Trap that saw them before they were taken aside, see {@see self::touch()}.
     *
     * @return non-empty-string What became of the report, for a warning.
     */
    private function putBack(): string
    {
        [$trace, $meta] = self::asidePaths($this->base, $this->key);
        $this->aside = false;
        unset(self::$live[\spl_object_id($this)]);
        if (!@\rename($trace, "{$this->base}.txt.gz")) {
            return "The SPX report is not delivered, and can't be put back: its trace is `$trace`, its metadata `$meta`.";
        }

        $this->tracePath = "{$this->base}.txt.gz";
        $this->touch($this->tracePath);
        if (!@\rename($meta, "{$this->base}.json")) {
            return "The SPX report is not delivered, and can't be put back: its trace is `{$this->tracePath}`,"
                . " its metadata `$meta`.";
        }

        $this->touch("{$this->base}.json");
        return "The SPX report is not delivered, and is kept as `{$this->base}.json` and `{$this->base}.txt.gz`.";
    }

    /**
     * @return \Generator<int, string>
     *
     * @throws \RuntimeException When the trace is gone.
     */
    private function trace(): \Generator
    {
        $handle = @\fopen($this->tracePath, 'rb') ?: throw new \RuntimeException(
            "Unable to read the SPX trace `{$this->tracePath}`.",
        );
        try {
            yield from TraceReader::chunks($handle);
        } finally {
            \fclose($handle);
        }
    }
}

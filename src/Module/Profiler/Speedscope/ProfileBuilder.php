<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Speedscope;

use Buggregator\Trap\Module\Profiler\Compaction\FlatTree;
use Buggregator\Trap\Module\Profiler\Compaction\NodeBudget;
use Buggregator\Trap\Module\Profiler\Compaction\Nodes;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Buggregator\Trap\Support\TimeSlice;

/**
 * Builds a profile from a speedscope document, such as the one `ExcimerLog::getSpeedscopeData()` returns.
 *
 * A sampled profile has no calls, only stacks: the samples with the same stack path are merged into a node,
 * so a node's `ct` is the number of samples it is on the stack in, and its children follow in the order
 * they were first sampled. An evented profile records the calls: a node per call, `ct` is 1.
 *
 * Units:
 * - time units give `wt`, and `cpu` repeats it when the profile was taken on the CPU clock;
 * - `bytes` give `mu`, inclusive like the memory of an Xdebug call;
 * - `none` and `samples` (sent by excimetry) are unknown units: sampled weights are counts of samples,
 *   and the `at` values of an evented profile are taken as microseconds, to keep the durations relative.
 *
 * Frames are told apart by name, as in the other profiles, which have nothing else to tell functions by;
 * a frame with no name is `(unknown)`. A single root that is file-scope code becomes `main()`, as an XHProf root,
 * and takes the samples with an empty stack. Otherwise the roots of stacks that start at different frames,
 * and samples with an empty stack, go under a synthetic `main()`.
 *
 * Over the node limit, the samples or calls that would add nodes are summed in a `(truncated)` node of the parent.
 *
 * @link https://github.com/jlfwong/speedscope/wiki/Importing-from-custom-sources
 *
 * @psalm-import-type Metadata from Profile
 *
 * @internal
 */
final class ProfileBuilder
{
    public const SCHEMA = 'https://www.speedscope.app/file-format-schema.json';

    /**
     * Size limit of a document in bytes.
     */
    public const MAX_SIZE = 8 * 1024 * 1024;

    /**
     * Limit of the estimated memory a decoded document takes, in bytes: about 1.6 million frames in the stacks,
     * 16 seconds of Excimer samples at 1 ms with 100-frame stacks.
     *
     * A frame index of a stack takes about 40 bytes decoded, with the slack of the arrays: 10 to 20 times
     * the size of the JSON. The server runs under PHP's default 128 MB memory limit.
     */
    private const MAX_DECODED_SIZE = 64 * 1024 * 1024;

    /** Name of the root over several roots or file-scope code, as XHProf names its root. */
    public const ROOT = 'main()';

    /** Name of a frame that has none. */
    public const UNKNOWN = '(unknown)';

    /** Names of file-scope code besides file paths: Xdebug's, a `main` frame, Excimer's for `php -r`. */
    private const FILE_SCOPE = [self::ROOT, '{main}', 'main', 'Command_line_code', 'Standard_input_code', 'php_shell_code'];

    /**
     * Limit of the summed microseconds or counts: floats hold integers exactly up to 2^53,
     * and larger ones would overflow the integer costs.
     */
    private const MAX_VALUE = 2 ** 53;

    /** Microseconds in a time unit. */
    private const TIME_UNITS = [
        'nanoseconds' => 0.001,
        'microseconds' => 1.0,
        'milliseconds' => 1000.0,
        'seconds' => 1000000.0,
    ];

    /** Units with no known meaning. */
    private const UNKNOWN_UNITS = ['none', 'samples'];

    /**
     * @param positive-int $maxSize Limit of the document size in bytes.
     * @param positive-int|null $maxNodes Limit of the tree nodes, over which the tree is compacted;
     *        by default, what the memory left affords when the build starts, see {@see NodeBudget}.
     */
    public function __construct(
        public readonly int $maxSize = self::MAX_SIZE,
        public readonly ?int $maxNodes = null,
    ) {}

    /**
     * A value for an error message: scalars as they are, shortened, other values by their type.
     */
    public static function describe(mixed $value): string
    {
        return match (true) {
            \is_string($value) => '`' . \mb_strimwidth($value, 0, 64, '...') . '`',
            \is_int($value), \is_float($value) => (string) $value,
            default => \get_debug_type($value),
        };
    }

    /**
     * @throws \InvalidArgumentException When the content is too large or is not a JSON object.
     */
    public function decode(string $content): array
    {
        \strlen($content) > $this->maxSize
            and throw new \InvalidArgumentException("The profile is larger than {$this->maxSize} bytes.");
        // A sample of one small frame takes 4 bytes of JSON and 200 decoded: the size alone doesn't bound the memory
        $decoded = 40 * \substr_count($content, ',') + 200 * \substr_count($content, '[') + 512 * \substr_count($content, '{');
        $decoded > self::MAX_DECODED_SIZE and throw new \InvalidArgumentException(
            'The profile would take more than ' . (self::MAX_DECODED_SIZE >> 20) . ' MB decoded.',
        );

        try {
            $document = \json_decode($content, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("Invalid JSON: {$e->getMessage()}.", previous: $e);
        }

        \is_array($document) or throw new \InvalidArgumentException('The document is not a JSON object.');

        return $document;
    }

    /**
     * @param array $document Decoded speedscope document. Of several profiles, the active one is taken, or the first.
     * @param Metadata $metadata Gets `exporter`, `clock` and `profile_type`; a profile name goes to the `name` tag.
     *        Gets `compacted` too when the tree was over the node limit: what {@see Report::toArray()} tells
     *        and the `limit`.
     * @param array<non-empty-string, non-empty-string> $tags
     * @param non-empty-string|null $name Profile name; defaults to the name in the document.
     * @param bool $cpuClock The samples measure CPU time, not wall time.
     * @param float|null $period Sampling period in seconds, for a sampled profile in a time unit:
     *        a sample whose weight is several periods counts as several samples, as Excimer records
     *        the timer events that came while PHP couldn't take a sample. Without it a sample counts once.
     * @param TimeSlice|null $timeSlice Lets other fibers run during a long build.
     *
     * @throws \InvalidArgumentException When the document is not a valid speedscope profile.
     */
    public function createProfile(
        array $document,
        \DateTimeInterface $date = new \DateTimeImmutable(),
        array $metadata = [],
        array $tags = [],
        ?string $name = null,
        bool $cpuClock = false,
        ?float $period = null,
        ?TimeSlice $timeSlice = null,
    ): Profile {
        $period === null || $period > 0.0
            or throw new \InvalidArgumentException('The sampling period must be positive.');
        [$frames, $fileScope] = self::frames($document);
        $profile = self::selectProfile($document);

        /** @var mixed $unit */
        $unit = $profile['unit'] ?? 'none';
        $known = \is_string($unit)
            && (isset(self::TIME_UNITS[$unit]) || $unit === 'bytes' || \in_array($unit, self::UNKNOWN_UNITS, true));
        $known or throw new \InvalidArgumentException('Unsupported unit: ' . self::describe($unit) . '.');
        /** @var string $unit */
        $scale = self::TIME_UNITS[$unit] ?? null;

        /** @var mixed $type */
        $type = $profile['type'] ?? null;
        $type === 'sampled' || $type === 'evented'
            or throw new \InvalidArgumentException('Unsupported profile type: ' . self::describe($type) . '.');

        /** @var mixed $exporter */
        $exporter = $document['exporter'] ?? null;
        \is_string($exporter) && $exporter !== '' and $metadata['exporter'] = $exporter;
        $metadata['clock'] = $cpuClock ? 'cpu' : 'wall';
        $metadata['profile_type'] = $type;
        $name ??= self::name($document['name'] ?? null) ?? self::name($profile['name'] ?? null);
        $name === null or $tags += ['name' => $name];

        // The samples are freed as they are read, when the caller passed the only reference to the document
        unset($document);
        $limit = $this->maxNodes ?? NodeBudget::nodes();
        $nodes = new Nodes($limit, $frames->count(), calls: $type === 'evented', timeSlice: $timeSlice);
        if ($type === 'sampled') {
            // Unknown units count samples; time and bytes are summed
            $samplesPerUnit = match (true) {
                $scale !== null => $period === null ? 0.0 : 1.0 / ($period * 1e6),
                $unit === 'bytes' => 0.0,
                default => null,
            };
            self::sampled($profile, $frames, $scale ?? ($unit === 'bytes' ? 1.0 : 0.0), $samplesPerUnit, $nodes);
        } else {
            self::evented($profile, $frames, $scale ?? 1.0, $nodes);
        }
        unset($profile);
        $nodes->compact();
        [$root, $rootName] = self::root($nodes, $frames, $fileScope, $type === 'evented')
            ?? throw new \InvalidArgumentException('The profile has no samples.');
        if (!$nodes->report->isEmpty()) {
            $nodes->report->measure($nodes->tree);
            $metadata['compacted'] = $nodes->report->toArray() + ['limit' => $limit];
        }

        $metric = $unit === 'bytes' ? 'mu' : ($cpuClock ? 'cpu' : 'wt');
        $tree = self::buildTree($nodes, $root, $rootName, $frames, $metric);
        unset($nodes);

        /** @var Metadata $metadata */
        return Profile::fromTree($tree, $date, $metadata, $tags, $timeSlice);
    }

    /**
     * Frames of the same name are one function, as in the other profiles; but when their files differ,
     * they are told apart by the file: `render (html.py)`. Excimer can't make such frames: it names a closure
     * by its file and line, and file-scope code by the file.
     *
     * @return array{Frames, array<non-empty-string, true>} The frames, and the names of the frames of file-scope code.
     */
    private static function frames(array $document): array
    {
        /** @var mixed $list */
        $list = $document['shared']['frames'] ?? null;
        \is_array($list) && \array_is_list($list)
            or throw new \InvalidArgumentException('No `shared.frames` list in the document.');

        /** @var list<array{non-empty-string, string}> $read Names and files */
        $read = [];
        /** @var array<non-empty-string, array<string, true>> $files Files by names */
        $files = [];
        /** @var mixed $frame */
        foreach ($list as $i => $frame) {
            \is_array($frame) or throw new \InvalidArgumentException("Frame $i is not an object.");
            /** @var mixed $name */
            $name = $frame['name'] ?? null;
            $name = \is_string($name) && $name !== '' ? $name : self::UNKNOWN;
            /** @var mixed $file */
            $file = $frame['file'] ?? null;
            $file = \is_string($file) ? $file : '';
            $read[] = [$name, $file];
            $files[$name][$file] = true;
        }

        $frames = new Frames();
        $fileScope = [];
        foreach ($read as [$name, $file]) {
            // Excimer names file-scope code by its path, with spaces replaced
            $file !== '' && ($name === $file || $name === \strtr($file, " \0", '__')) and $fileScope[$name] = true;
            $frames->add(\count($files[$name]) > 1 && $file !== '' ? "$name ($file)" : $name);
        }

        return [$frames, $fileScope];
    }

    private static function selectProfile(array $document): array
    {
        /** @var mixed $profiles */
        $profiles = $document['profiles'] ?? null;
        \is_array($profiles) && \array_is_list($profiles) && $profiles !== []
            or throw new \InvalidArgumentException('No `profiles` in the document.');

        /** @var mixed $index */
        $index = $document['activeProfileIndex'] ?? 0;
        \is_int($index) && isset($profiles[$index]) or $index = 0;

        /** @var mixed $profile */
        $profile = $profiles[$index];
        \is_array($profile) or throw new \InvalidArgumentException("Profile $index is not an object.");

        return $profile;
    }

    /**
     * @param float $scale Value of a weight unit: microseconds, bytes, or 0 when the weights are counts.
     * @param float|null $samplesPerUnit Samples a weight unit makes, 0 for a sample each; null when the weights are counts.
     */
    private static function sampled(
        array &$profile,
        Frames $frames,
        float $scale,
        ?float $samplesPerUnit,
        Nodes $nodes,
    ): void {
        /** @var mixed $samples */
        $samples = $profile['samples'] ?? null;
        /** @var mixed $weights */
        $weights = $profile['weights'] ?? null;
        unset($profile['samples']);
        \is_array($samples) && \array_is_list($samples) && \is_array($weights) && \array_is_list($weights)
            or throw new \InvalidArgumentException('A sampled profile needs `samples` and `weights` lists.');
        \count($samples) === \count($weights)
            or throw new \InvalidArgumentException('The numbers of samples and weights differ.');

        $total = 0.0;
        // Not `foreach`: it would hold the array, and the samples could not be freed one by one
        for ($i = 0, $length = \count($samples); $i < $length; ++$i) {
            /** @var mixed $stack */
            $stack = $samples[$i];
            unset($samples[$i]);
            \is_array($stack) && \array_is_list($stack)
                or throw new \InvalidArgumentException("Sample $i is not a list of frames.");
            /** @var mixed $weight */
            $weight = $weights[$i];
            (\is_int($weight) || \is_float($weight)) && $weight >= 0
                or throw new \InvalidArgumentException("Weight $i is not a non-negative number.");
            // Nothing was measured: the client skips such samples too
            if ($weight == 0) {
                continue;
            }

            $value = (float) $weight * $scale;
            $count = match ($samplesPerUnit) {
                null => (int) $weight,
                0.0 => 1,
                default => \max(1, (int) \round($value * $samplesPerUnit)),
            };
            $total += \max($value, (float) $count);
            $total <= self::MAX_VALUE or throw new \InvalidArgumentException('The weights are too large.');

            $stack === [] and ++$nodes->emptyStacks;
            // The frames under a folded node are in it, and still validated
            $nodes->sample($frames->path($stack), $value, $count);
            $nodes->timeSlice?->tick();
        }
    }

    /**
     * Opened frames must be closed in the reverse order; the frames still open are closed at the end of the profile.
     *
     * @param float $scale Microseconds in a unit, or bytes.
     */
    private static function evented(array &$profile, Frames $frames, float $scale, Nodes $nodes): void
    {
        /** @var mixed $events */
        $events = $profile['events'] ?? null;
        unset($profile['events']);
        \is_array($events) && \array_is_list($events)
            or throw new \InvalidArgumentException('An evented profile needs an `events` list.');

        // Calls over the node limit are summed in a folded node, and so are the calls made from them,
        // which add their count, but not their time: the time of the outer call holds it.
        // The nodes of the open calls are in `$nodes->open`, to follow the compactions.
        /** @var list<array{int, float, bool}> $open Frame index, the time it was opened at, whether to add it */
        $open = [];
        $tree = $nodes->tree;
        // More calls than the node limit: the calls of the same function on the same path are merged from the start
        $merge = \count($events) > 2 * $nodes->maxNodes;
        $first = $last = -\INF;
        // Not `foreach`: it would hold the array, and the events could not be freed one by one
        for ($i = 0, $length = \count($events); $i < $length; ++$i) {
            /** @var mixed $event */
            $event = $events[$i];
            unset($events[$i]);
            \is_array($event) or throw new \InvalidArgumentException("Event $i is not an object.");
            /** @var mixed $at */
            $at = $event['at'] ?? null;
            \is_int($at) || \is_float($at) or throw new \InvalidArgumentException("Event $i has no time.");
            $at < $last and throw new \InvalidArgumentException("Event $i goes before the previous one.");
            $last = (float) $at;
            $i === 0 and $first = $last;
            $index = $frames->id($event['frame'] ?? null);

            switch ($event['type'] ?? null) {
                case 'O':
                    $parent = $nodes->open === [] ? 0 : $nodes->open[\array_key_last($nodes->open)];
                    $size = $tree->size();
                    $inside = $nodes->isFolded($parent);
                    $node = match (true) {
                        $inside => $parent,
                        $merge => $nodes->child($parent, $index),
                        default => $nodes->call($parent, $index),
                    };
                    $merge && !$inside && $tree->size() === $size && !$nodes->isFolded($node)
                        and ++$nodes->report->merged;
                    ++$tree->count[$node];
                    $nodes->open[] = $node;
                    $open[] = [$index, (float) $at, !$inside];
                    break;
                case 'C':
                    [$opened, $from, $measured] = \array_pop($open) ?? throw new \InvalidArgumentException(
                        "Event $i closes `{$frames->names[$index]}`, but no frame is open.",
                    );
                    $opened === $index or throw new \InvalidArgumentException(
                        "Event $i closes `{$frames->names[$index]}`, but `{$frames->names[$opened]}` is open.",
                    );
                    $node = (int) \array_pop($nodes->open);
                    $measured and $tree->value[$node] += ((float) $at - $from) * $scale;
                    break;
                default:
                    throw new \InvalidArgumentException("Event $i is neither `O` nor `C`.");
            }
            $nodes->timeSlice?->tick();
        }

        /** @var mixed $end */
        $end = $profile['endValue'] ?? null;
        $end = \is_int($end) || \is_float($end) ? \max((float) $end, $last) : $last;
        foreach ($open as $i => [, $from, $measured]) {
            $measured and $tree->value[$nodes->open[$i]] += ($end - $from) * $scale;
        }
        $nodes->open = [];

        $length === 0 || ($end - $first) * $scale <= self::MAX_VALUE
            or throw new \InvalidArgumentException('The event times are too large.');
    }

    /**
     * @param array<non-empty-string, true> $fileScope Names of the frames of file-scope code.
     *
     * @return array{int, non-empty-string}|null The root node and its name, or null when the profile is empty.
     *         Several roots, and samples with an empty stack, which belong to no root, go under node 0.
     */
    private static function root(Nodes $nodes, Frames $frames, array $fileScope, bool $evented): ?array
    {
        $tree = $nodes->tree;
        $roots = $nodes->roots();
        if (\count($roots) === 1) {
            $root = $roots[0];
            $name = self::nodeName($nodes, $frames, $root);
            if (\in_array($name, self::FILE_SCOPE, true) || isset($fileScope[$name])) {
                // The script takes the samples with an empty stack as its own time
                if ($nodes->emptyStacks > 0) {
                    $tree->value[$root] = $tree->value[0];
                    $tree->count[$root] = $tree->count[0];
                }
                return [$root, self::ROOT];
            }
            if ($nodes->emptyStacks === 0) {
                return [$root, $name];
            }
        }

        if ($roots === [] && $nodes->emptyStacks === 0) {
            return null;
        }

        // Node 0 sums the sampled stacks; an evented `main()` is a call that spans its children
        if ($evented) {
            $tree->count[0] = 1;
            foreach ($roots as $node) {
                $tree->value[0] += $tree->value[$node];
            }
        }

        return [0, self::ROOT];
    }

    /**
     * @return non-empty-string|null
     */
    private static function name(mixed $value): ?string
    {
        $value = \is_string($value) ? \trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Rounds the values to integers, keeping the sums of caller-callee pairs exact as the Xdebug builder does:
     * the rounding error of a pair is carried over to its next node, and a node never takes less than its children.
     *
     * @param int $root Node 0 or the single root: all the nodes after it are its descendants.
     * @param non-empty-string $rootName
     * @param 'wt'|'cpu'|'mu' $metric Cost the values go to; `cpu` repeats them in `wt`.
     *
     * @return Tree<Edge> Node IDs follow the pre-order: `n1` is the root.
     */
    private static function buildTree(Nodes $nodes, int $root, string $rootName, Frames $frames, string $metric): Tree
    {
        $tree = $nodes->tree;
        $frameOf = $tree->frame;
        $parentOf = $tree->parent;
        $valueOf = $tree->value;

        // Names by IDs: a pair of names is keyed by an integer, not by a string built per node
        $names = $frames->names;
        $nameIds = \array_flip($names);
        foreach ([Profile::TRUNCATED, Profile::OTHER, self::ROOT, $rootName] as $marker) {
            isset($nameIds[$marker]) or $nameIds[$marker] = \array_push($names, $marker) - 1;
        }
        /** @var array<int, int> $markerIds */
        $markerIds = [
            FlatTree::TRUNCATED => $nameIds[Profile::TRUNCATED],
            FlatTree::OTHER => $nameIds[Profile::OTHER],
            Nodes::TOP => $nameIds[self::ROOT],
        ];
        $rootId = $nameIds[$rootName];
        unset($nameIds);
        // The ID that stands for no caller
        $none = \count($names);
        $span = $none + 1;

        // A node is added after its parent: in the reversed order the children come before their parents
        $last = $tree->size() - 1;
        /** @var array<int, int> $nameOf */
        $nameOf = \array_fill(0, $last + 1, 0);
        for ($node = $root; $node <= $last; ++$node) {
            $frame = $frameOf[$node];
            $nameOf[$node] = $frame >= 0 ? $frame : $markerIds[$frame];
        }
        $nameOf[$root] = $rootId;

        /** @var array<int, int<0, max>> $rounded */
        $rounded = \array_fill(0, $last + 1, 0);
        /** @var array<int, int<0, max>> $childrenSum */
        $childrenSum = \array_fill(0, $last + 1, 0);
        /** @var array<int, float> $pairValue */
        $pairValue = [];
        /** @var array<int, int<0, max>> $pairRounded */
        $pairRounded = [];
        for ($node = $last; $node >= $root; --$node) {
            $parent = $parentOf[$node];
            $pair = ($node === $root ? $none : $nameOf[$parent]) * $span + $nameOf[$node];
            $pairValue[$pair] = ($pairValue[$pair] ?? 0.0) + $valueOf[$node];
            $given = $pairRounded[$pair] ?? 0;
            $value = \max($childrenSum[$node], (int) \round($pairValue[$pair]) - $given);
            $pairRounded[$pair] = $given + $value;
            $rounded[$node] = $value;
            $node === $root or $childrenSum[$parent] += $value;
            $nodes->timeSlice?->tick();
        }
        unset($childrenSum, $pairValue, $pairRounded, $valueOf);

        // Children as linked lists, the last child first: popped from a stack, they come in their order
        /** @var array<int, int> $first */
        $first = \array_fill(0, $last + 1, -1);
        /** @var array<int, int> $next */
        $next = \array_fill(0, $last + 1, -1);
        for ($node = $root + 1; $node <= $last; ++$node) {
            $parent = $parentOf[$node];
            $next[$node] = $first[$parent];
            $first[$parent] = $node;
        }

        /** @var Tree<Edge> $result */
        $result = new Tree();
        $count = $tree->count;
        /** @var list<int> $stack */
        $stack = [$root];
        /** @var list<Branch<Edge>|null> $callers Parent branches of the nodes in the stack */
        $callers = [null];
        while ($stack !== []) {
            $node = \array_pop($stack);
            $parent = \array_pop($callers);
            $value = $rounded[$node];

            $id = 'n' . (\count($result) + 1);
            $result->addItem(
                new Edge(
                    caller: $parent?->item->callee,
                    callee: $names[$nameOf[$node]],
                    cost: new Cost(
                        ct: \max(0, $count[$node]),
                        wt: $metric === 'mu' ? 0 : $value,
                        cpu: $metric === 'cpu' ? $value : 0,
                        mu: $metric === 'mu' ? $value : 0,
                        pmu: 0,
                    ),
                ),
                $id,
                $parent?->id,
            );

            /** @var Branch<Edge> $branch */
            $branch = $result->all[$id];
            for ($child = $first[$node]; $child !== -1; $child = $next[$child]) {
                $stack[] = $child;
                $callers[] = $branch;
            }
            $nodes->timeSlice?->tick();
        }

        return $result;
    }

    /**
     * @return non-empty-string
     */
    private static function nodeName(Nodes $nodes, Frames $frames, int $node): string
    {
        return match ($frame = $nodes->tree->frame[$node]) {
            FlatTree::TRUNCATED => Profile::TRUNCATED,
            FlatTree::OTHER => Profile::OTHER,
            Nodes::TOP => self::ROOT,
            default => $frames->names[$frame],
        };
    }
}

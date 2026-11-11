<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Bench\Module\Profiler\Stub;

use Buggregator\Trap\Module\Profiler\Speedscope\ProfileBuilder;

/**
 * Generates large profiles in code: multi-megabyte files are not worth committing.
 *
 * The random generator is seeded, so a document is the same on every run.
 */
final class Documents
{
    /**
     * Excimer-shaped document of a framework app: a 45-frame base stack cut at a random depth,
     * then 1 to 8 random leaves out of 400. About 4 unique tree nodes per sample.
     */
    public static function framework(int $samples): array
    {
        \mt_srand(42);
        $frames = new FrameTable();
        $base = [$frames->add('/app/public/index.php', '/app/public/index.php')];
        for ($i = 1; $i < 45; $i++) {
            $base[] = $frames->add("Vendor\\Framework\\Layer$i\\Middleware$i::process", "/app/vendor/framework/src/Layer$i.php");
        }
        $leaves = [];
        for ($i = 0; $i < 400; $i++) {
            $leaves[] = $frames->add('App\\Domain\\Module' . ($i % 40) . "\\Service$i::handle", "/app/src/Domain/Service$i.php");
        }

        $stacks = [];
        $weights = [];
        for ($s = 0; $s < $samples; $s++) {
            $stack = \array_slice($base, 0, \mt_rand(5, 44));
            for ($k = \mt_rand(1, 8); $k > 0; $k--) {
                $stack[] = $leaves[\mt_rand(0, 399)];
            }
            $stacks[] = $stack;
            // Overruns: a long internal call is one sample of several timer events
            $weights[] = (\mt_rand(1, 100) === 1 ? \mt_rand(2, 50) : 1) * 1_000_000;
        }

        return self::sampled($frames, $stacks, $weights);
    }

    /**
     * Unbounded recursion cut by `excimer.default_max_depth`: every stack is `excimer_truncated` and 1000 frames.
     * Few nodes, every one deep in the recursion.
     */
    public static function maxDepth(int $samples): array
    {
        $frames = new FrameTable();
        $truncated = $frames->add('excimer_truncated', 'excimer fake file');
        $visit = $frames->add('App\\Tree\\Walker::visit', '/app/src/Tree/Walker.php');
        $leaf = $frames->add('App\\Tree\\Node::children', '/app/src/Tree/Node.php');
        $stack = [$truncated, ...\array_fill(0, 998, $visit), $leaf];

        return self::sampled($frames, \array_fill(0, $samples, $stack), \array_fill(0, $samples, 1_000_000));
    }

    /**
     * Deep and varied stacks of mutual recursion: a 400-frame base cycling through 4 functions, cut at
     * a random depth, then random leaves. Many nodes, each hundreds of frames deep with high recursion levels.
     */
    public static function deepRecursion(int $samples): array
    {
        \mt_srand(42);
        $frames = new FrameTable();
        $cycle = [];
        foreach (['parse', 'parseBlock', 'parseStatement', 'parseExpression'] as $name) {
            $cycle[] = $frames->add("App\\Parser::$name", '/app/src/Parser.php');
        }
        $base = [];
        for ($i = 0; $i < 400; $i++) {
            $base[] = $cycle[$i % 4];
        }
        $leaves = [];
        for ($i = 0; $i < 100; $i++) {
            $leaves[] = $frames->add("App\\Lexer::token$i", '/app/src/Lexer.php');
        }

        $stacks = [];
        for ($s = 0; $s < $samples; $s++) {
            $stack = \array_slice($base, 0, \mt_rand(50, 399));
            for ($k = \mt_rand(1, 4); $k > 0; $k--) {
                $stack[] = $leaves[\mt_rand(0, 99)];
            }
            $stacks[] = $stack;
        }

        return self::sampled($frames, $stacks, \array_fill(0, $samples, 1_000_000));
    }

    /**
     * Xdebug cachegrind profile of `$calls` calls: random call trees up to 30 levels deep under `{main}`,
     * with function names out of 300.
     */
    public static function cachegrind(int $calls): string
    {
        \mt_srand(42);
        $out = "version: 1\ncreator: xdebug 3.4.0 (PHP 8.4.0)\ncmd: /app/index.php\npart: 1\npositions: line\n\n"
            . "events: Time_(10ns) Memory_(bytes)\n\n";
        $budget = $calls - 1;
        // Post-order: a block is written when its call ends, after the blocks of its callees
        $emit = static function (string $name, int $depth) use (&$emit, &$out, &$budget): int {
            $children = [];
            $inclusive = $self = \mt_rand(1, 500);
            $count = $depth >= 30 ? 0 : \mt_rand(0, 3);
            while ($count-- > 0 && $budget > 0) {
                --$budget;
                $child = 'App\\F' . \mt_rand(0, 299) . '::run';
                /** @var int $time */
                $time = $emit($child, $depth + 1);
                $children[] = [$child, $time];
                $inclusive += $time;
            }

            $out .= "fl=/app/src/F.php\nfn=$name\n1 $self 0\n";
            foreach ($children as [$child, $time]) {
                $out .= "cfl=/app/src/F.php\ncfn=$child\ncalls=1 0 0\n1 $time 0\n";
            }
            $out .= "\n";

            return $inclusive;
        };

        $roots = [];
        $total = 0;
        while ($budget > 0) {
            --$budget;
            $time = $emit('App\\Kernel::handle', 1);
            $roots[] = $time;
            $total += $time;
        }
        $out .= "fl=/app/index.php\nfn={main}\n1 1 0\n";
        foreach ($roots as $time) {
            $out .= "cfl=/app/src/Kernel.php\ncfn=App\\Kernel::handle\ncalls=1 0 0\n1 $time 0\n";
        }

        return $out . "\nsummary: " . ($total + 1) . " 0\n";
    }

    /**
     * Xdebug cachegrind profile of about `$calls` calls of a request that repeats its work: 40 kinds of operations,
     * each a fixed call tree of up to 40 calls of 200 functions, made in a random order under one handler.
     * Merging the calls of the same function on the same path keeps most of it.
     */
    public static function cachegrindLoop(int $calls): string
    {
        \mt_srand(42);
        // An operation is a call tree: [function, [callees...]]
        $grow = static function (int $depth, int &$size) use (&$grow): array {
            $children = [];
            for ($count = $depth >= 6 ? 0 : \mt_rand(0, 3); $count > 0 && $size < 40; --$count) {
                ++$size;
                $children[] = $grow($depth + 1, $size);
            }
            return ['App\\F' . \mt_rand(0, 199) . '::run', $children];
        };
        $operations = [];
        for ($i = 0; $i < 40; ++$i) {
            $size = 1;
            $operations[] = $grow(1, $size);
        }

        $out = "version: 1\ncreator: xdebug 3.4.0 (PHP 8.4.0)\ncmd: /app/index.php\npart: 1\npositions: line\n\n"
            . "events: Time_(10ns) Memory_(bytes)\n\n";
        $made = 0;
        $emit = static function (array $call) use (&$emit, &$out, &$made): int {
            ++$made;
            $inclusive = $self = \mt_rand(1, 500);
            $times = [];
            foreach ($call[1] as $child) {
                $time = $emit($child);
                $times[] = [$child[0], $time];
                $inclusive += $time;
            }
            $out .= "fl=/app/src/F.php\nfn={$call[0]}\n1 $self 0\n";
            foreach ($times as [$name, $time]) {
                $out .= "cfl=/app/src/F.php\ncfn=$name\ncalls=1 0 0\n1 $time 0\n";
            }
            $out .= "\n";
            return $inclusive;
        };

        $times = [];
        while ($made < $calls - 2) {
            $operation = $operations[\mt_rand(0, 39)];
            $times[] = [$operation[0], $emit($operation)];
        }
        $total = 0;
        $out .= "fl=/app/src/Kernel.php\nfn=App\\Kernel::handle\n1 1 0\n";
        foreach ($times as [$name, $time]) {
            $out .= "cfl=/app/src/F.php\ncfn=$name\ncalls=1 0 0\n1 $time 0\n";
            $total += $time;
        }
        $out .= "\nfl=/app/index.php\nfn={main}\n1 1 0\ncfl=/app/src/Kernel.php\ncfn=App\\Kernel::handle\ncalls=1 0 0\n1 "
            . ($total + 1) . " 0\n";

        return $out . "\nsummary: " . ($total + 2) . " 0\n";
    }

    /**
     * @param list<list<int>> $stacks
     * @param list<int> $weights
     */
    private static function sampled(FrameTable $frames, array $stacks, array $weights): array
    {
        return [
            '$schema' => ProfileBuilder::SCHEMA,
            'exporter' => 'Excimer',
            'shared' => ['frames' => $frames->frames],
            'profiles' => [[
                'type' => 'sampled',
                'name' => '',
                'unit' => 'nanoseconds',
                'startValue' => 0,
                'endValue' => \array_sum($weights),
                'samples' => $stacks,
                'weights' => $weights,
            ]],
        ];
    }
}

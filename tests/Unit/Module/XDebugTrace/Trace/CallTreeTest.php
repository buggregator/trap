<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Trace;

use Buggregator\Trap\Module\XDebugTrace\Trace\CallTree;
use Buggregator\Trap\Module\XDebugTrace\Trace\Node;
use Buggregator\Trap\Module\XDebugTrace\Trace\Page;
use Buggregator\Trap\Module\XDebugTrace\Trace\TraceError;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Covers(CallTree::class)]
#[Covers(Node::class)]
#[Covers(Page::class)]
#[Test]
final class CallTreeTest
{
    private const MAIN_ID = 71;
    private const CLOSURE = '{closure:/app/main.php:14-14}';

    private ?string $file = null;

    #[AfterTest]
    public function removeFile(): void
    {
        $this->file === null or @\unlink($this->file);
    }

    public function testListsTopLevel(): void
    {
        $page = (new CallTree(TraceReaderTest::MAIN))->children();

        Assert::count($page->nodes, 1);
        Assert::same($page->nodes[0]->entry->func, '{main}');
        Assert::same($page->nodes[0]->children, 13);
        Assert::true($page->nodes[0]->done);
        Assert::same($page->nodes[0]->offset, self::MAIN_ID);
        Assert::null($page->next);
        Assert::false($page->truncated);
    }

    public function testListsCalleesOfParent(): void
    {
        $page = (new CallTree(TraceReaderTest::MAIN))->children(self::MAIN_ID);

        Assert::same(self::funcs($page), [
            'require', 'App\UserRepo->__construct', 'App\UserRepo->find', 'strtoupper', 'App\UserRepo->find',
            'strtoupper', 'App\UserRepo->find', 'App\fib', 'implode', 'sprintf', self::CLOSURE,
            'Exception->__construct', 'Exception->getMessage',
        ]);
        $fib = $page->nodes[7];
        Assert::same($fib->children, 2);
        Assert::same($fib->return, '8');
        Assert::same($fib->args, ['6']);
        Assert::same($page->parent?->children, 13);
        Assert::true($page->parent->done);
        Assert::false($page->truncated);
    }

    public function testReadsReturnValueOfParent(): void
    {
        $tree = new CallTree(TraceReaderTest::MAIN);
        $closure = $tree->children(self::MAIN_ID)->nodes[10];

        $page = $tree->children($closure->offset);

        Assert::same(self::funcs($page), ['str_repeat']);
        Assert::same($page->nodes[0]->return, "'a\\tba\\tb'");
        Assert::same($page->parent?->return, "'a\\tba\\tb'");
    }

    public function testPagesCallees(): void
    {
        $tree = new CallTree(TraceReaderTest::MAIN);
        $all = [];
        $from = -1;
        do {
            $page = $tree->children(self::MAIN_ID, $from, 5);
            $all = [...$all, ...self::funcs($page)];
            $from = $page->next ?? -1;
        } while ($page->next !== null);

        Assert::same($all, self::funcs($tree->children(self::MAIN_ID)));
    }

    public function testRejectsOffsetOfNoCall(): never
    {
        Expect::exception(TraceError::class)->withMessage('The offset is not the start of a call.');

        (new CallTree(TraceReaderTest::MAIN))->children(self::MAIN_ID + 1);
    }

    public function testRejectsFromOfOtherLevel(): never
    {
        Expect::exception(TraceError::class);

        (new CallTree(TraceReaderTest::MAIN))->children(self::MAIN_ID, self::MAIN_ID);
    }

    public function testListsTraceStartedDeepInScript(): void
    {
        $page = (new CallTree(TraceReaderTest::MID))->children();

        Assert::same(self::funcs($page), ['App\fib', 'after']);
        Assert::same($page->nodes[0]->entry->level, 5);
        Assert::same($page->nodes[0]->return, '3');
        Assert::same($page->nodes[1]->entry->level, 3);
        Assert::false($page->nodes[1]->done);
        Assert::false($page->truncated);
    }

    public function testCutTraceIsTruncated(): void
    {
        $plain = (string) \gzdecode((string) \file_get_contents(TraceReaderTest::MAIN));
        $this->file = (string) \tempnam(\sys_get_temp_dir(), 'trap-xt-test-');
        \file_put_contents($this->file, \substr($plain, 0, 2000));

        $page = (new CallTree($this->file))->children();

        Assert::true($page->truncated);
        Assert::false($page->nodes[0]->done);
    }

    public function testSearchesWithPathsAndPages(): void
    {
        $tree = new CallTree(TraceReaderTest::MAIN);
        $all = [];
        $from = 0;
        do {
            $page = $tree->search('FIB', $from, 10);
            Assert::false($page->truncated);
            $all = [...$all, ...$page->nodes];
            $from = $page->next ?? 0;
        } while ($page->next !== null);

        Assert::count($all, 25);
        Assert::same($all[0]->path, [['nr' => 0, 'func' => '{main}', 'offset' => self::MAIN_ID]]);
        foreach ($all as $node) {
            Assert::count($node->path, $node->entry->level - 1);
            Assert::true($node->done);
            Assert::notSame($node->return, null);
        }
    }

    public function testSearchesFiles(): void
    {
        $page = (new CallTree(TraceReaderTest::MAIN))->search('LIB.php');

        Assert::notBlank($page->nodes);
        foreach ($page->nodes as $node) {
            Assert::same($node->entry->file, '/app/lib.php');
        }
    }

    public function testSerializesNodeAsUiReadsIt(): void
    {
        $page = (new CallTree(TraceReaderTest::MAIN))->search('strtoupper', 0, 1);

        $data = \json_decode(\json_encode($page), true);

        Assert::array($data)->hasKeys('nodes', 'next', 'truncated');
        Assert::false(\array_key_exists('parent', $data));
        Assert::array($data['nodes'][0])->hasKeys(
            'nr',
            'func',
            'user',
            'file',
            'line',
            'level',
            'start',
            'incl',
            'self',
            'memory',
            'args',
            'return',
            'children',
            'done',
            'offset',
            'path',
        );
        Assert::false(\array_key_exists('include', $data['nodes'][0]));
    }

    public function testClipsLongValues(): void
    {
        $value = \str_repeat('a', Node::MAX_VALUE - 1) . 'é';

        Assert::same(Node::clip($value), \str_repeat('a', Node::MAX_VALUE - 1) . '…');
        Assert::same(Node::clip('short'), 'short');
    }

    /**
     * @return list<string>
     */
    private static function funcs(Page $page): array
    {
        return \array_map(static fn(Node $node): string => $node->entry->func, $page->nodes);
    }
}

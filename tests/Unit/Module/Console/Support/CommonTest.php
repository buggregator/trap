<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Console\Support;

use Buggregator\Trap\Module\Console\Support\Common;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Common::class)]
final class CommonTest
{
    #[DataSet(['To', 'dev@example.com', "To: dev@example.com\n"], 'scalar')]
    #[DataSet(['To', ['a@example.com', 'b@example.com'], "To: a@example.com\nTo: b@example.com\n"], 'list repeats the name')]
    #[DataSet(['ctx', ['n' => 1, 'addr' => '127.0.0.1:19999'], "ctx:\n     n: 1\n     addr: 127.0.0.1:19999\n"], 'map shows its keys')]
    #[DataSet(['ctx', ['user' => ['id' => 1, 'roles' => ['admin']]], "ctx:\n     user: {\"id\":1,\"roles\":[\"admin\"]}\n"], 'nested map value as JSON')]
    #[DataSet(['ctx', [['id' => 1], ['id' => 2]], "ctx: {\"id\":1}\nctx: {\"id\":2}\n"], 'list of maps as JSON')]
    #[DataSet(['ctx', [], ''], 'empty array')]
    #[DataSet(['ctx', [1 => 'a', 2 => 'b'], "ctx:\n     1: a\n     2: b\n"], 'sparse integer keys as a map')]
    #[DataSet([
        'ctx',
        ['at' => new \DateTimeImmutable('2026-01-02 03:04:05'), 'ok' => false, 'none' => null],
        "ctx:\n     at: 2026-01-02 03:04:05\n     ok: false\n     none: null\n",
    ], 'map of a date, a bool and null')]
    #[DataSet(['ctx', ['sql' => "SELECT 1\nFROM t"], "ctx:\n     sql: SELECT 1\n          FROM t\n"], 'multi-line value aligned')]
    #[DataSet(['ctx', ['ключ' => "a\nb"], "ctx:\n     ключ: a\n           b\n"], 'multibyte key aligned by width')]
    #[DataSet([
        'ctx',
        ['exception' => ['class' => 'RuntimeException', 'message' => 'Connection refused', 'file' => '/app/src/Client.php:42']],
        "ctx:\n     exception: {\n"
        . "                    \"class\": \"RuntimeException\",\n"
        . "                    \"message\": \"Connection refused\",\n"
        . "                    \"file\": \"/app/src/Client.php:42\"\n"
        . "                }\n",
    ], 'long nested map pretty-printed')]
    #[DataSet(['ctx', ['<error>' => '<fg=red>x</>'], "ctx:\n     <error>: <fg=red>x</>\n"], 'map markup escaped')]
    #[DataSet(['To', ['<error>a</>', 'b'], "To: <error>a</>\nTo: b\n"], 'list markup escaped')]
    public function testHeader(string $name, mixed $value, string $expected): void
    {
        $output = new BufferedOutput();

        Common::renderHeader($output, $name, $value);

        Assert::string($output->fetch())->ignoringLineEndings()->same($expected);
    }

    public function testMetadataShowsKeysOfMapsUnderAlignedNames(): void
    {
        $output = new BufferedOutput();

        Common::renderMetadata($output, [
            'channel' => 'app',
            'context' => ['n' => 1],
            'extra' => [],
        ]);

        Assert::string($output->fetch())->ignoringLineEndings()->same("channel: app\ncontext:\n         n: 1\n");
    }
}

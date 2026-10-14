<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Arch;

use Testo\Assert;
use Testo\Test;

#[Test]
final class ArchTest
{
    private const SOURCE_DIR = __DIR__ . '/../../src';

    /** Paths under `src/` that are not checked; `functions.php` defines the debug helpers themselves. */
    private const EXCLUDED = ['Test', 'functions.php'];

    private const FUNCTIONS = ['dd', 'var_dump', 'dump', 'tr', 'td'];

    public function testForgottenDebugFunctions(): void
    {
        $found = [];
        foreach ($this->sourceFiles() as $file) {
            foreach ($this->debugCalls((string) \file_get_contents($file)) as [$function, $line]) {
                $found[] = \sprintf('`%s()` in %s:%d', $function, $file, $line);
            }
        }

        Assert::same($found, []);
    }

    /**
     * @return iterable<non-empty-string>
     */
    private function sourceFiles(): iterable
    {
        $root = (string) \realpath(self::SOURCE_DIR);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $topLevel = \explode('/', \str_replace('\\', '/', \substr($file->getPathname(), \strlen($root) + 1)))[0];
            if ($file->getExtension() === 'php' && !\in_array($topLevel, self::EXCLUDED, true)) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * Finds calls of the debug functions.
     *
     * @return iterable<array{non-empty-string, int}>
     */
    private function debugCalls(string $code): iterable
    {
        $tokens = \array_values(\array_filter(
            \PhpToken::tokenize($code),
            static fn(\PhpToken $token): bool => !$token->isIgnorable(),
        ));

        foreach ($tokens as $i => $token) {
            $name = \strtolower(\ltrim($token->text, '\\'));
            if (!$token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED]) || !\in_array($name, self::FUNCTIONS, true)) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            if (($tokens[$i + 1] ?? null)?->text === '('
                && !$previous?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW])
            ) {
                yield [$name, $token->line];
            }
        }
    }
}

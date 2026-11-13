<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\InlineArrayReturnAssignRector;
use Rector\CodeQuality\Rector\Expression\InlineIfToExplicitIfRector;
use Rector\CodeQuality\Rector\LogicalAnd\LogicalToBooleanRector;
use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

$root = \dirname(__DIR__, 2);

return RectorConfig::configure()
    ->withPaths([
        // let's add more directories step by step
        // $root . '/src',
        // $root . '/tests',
        // $root . '/bin',
        $root . '/src/Client',
    ])
    ->withSkipPath($root . '/src/Client/TrapHandle/ContextProvider/Source.php')
    ->withImportNames(importNames: false, importDocBlockNames: true, importShortClasses: false, removeUnusedImports: true)
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPhpSets(php81: true)
    ->withPreparedSets(
        deadCode: false,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        naming: false,
        instanceOf: true,
        earlyReturn: true,
        carbon: false,
        rectorPreset: true,
    )->withSkip([
        NewlineAfterStatementRector::class,
        InlineArrayReturnAssignRector::class,
        InlineIfToExplicitIfRector::class,
        LogicalToBooleanRector::class,
        NewlineBetweenClassLikeStmtsRector::class,
    ]);

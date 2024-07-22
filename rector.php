<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\InlineArrayReturnAssignRector;
use Rector\CodeQuality\Rector\Expression\InlineIfToExplicitIfRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\LogicalAnd\LogicalToBooleanRector;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\Config\RectorConfig;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\BooleanAnd\BinaryOpNullableToInstanceofRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        // let's add more directories step by step
        // __DIR__ . '/src',
        // __DIR__ . '/tests',
        // __DIR__ . '/bin',
        __DIR__ . '/src/Client',
    ])
    ->withSkipPath('src/Client/TrapHandle/ContextProvider/Source.php')
    ->withPHPStanConfigs([
        __DIR__ . '/phpstan-baseline.neon',
    ])
    ->withImportNames(importNames: false, importDocBlockNames: true, importShortClasses: false, removeUnusedImports: true)
    ->withPhpVersion(PhpVersion::PHP_81)
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
        strictBooleans: true,
        carbon: false,
        rectorPreset: true,
    )->withSkip([
        InlineArrayReturnAssignRector::class,
        PostIncDecToPreIncDecRector::class,
        InlineIfToExplicitIfRector::class,
        LogicalToBooleanRector::class,
        BinaryOpNullableToInstanceofRector::class,
        FlipTypeControlToUseExclusiveTypeRector::class,
        DisallowedEmptyRuleFixerRector::class,
        NullToStrictStringFuncCallArgRector::class,
        EncapsedStringsToSprintfRector::class,
    ]);

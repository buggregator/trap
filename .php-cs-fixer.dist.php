<?php

/**
 * @see https://mlocati.github.io/php-cs-fixer-configurator/#version:3.55
 * @see https://github.com/wayofdev/php-cs-fixer-config
 */
declare(strict_types=1);

use PhpCsFixer\Fixer\FunctionNotation\NativeFunctionInvocationFixer;
use WayOfDev\PhpCsFixer\Config\ConfigBuilder;
use WayOfDev\PhpCsFixer\Config\RuleSets\DefaultSet;

require_once 'vendor/autoload.php';

$ruleSet = new DefaultSet([
    'static_lambda' => false,
    'global_namespace_import' => [
        'import_classes' => false,
        'import_constants' => false,
        'import_functions' => false,
    ],
    'native_function_invocation' => [
        'include' => [
            NativeFunctionInvocationFixer::SET_INTERNAL
        ],
        'scope' => 'namespaced',
        'strict' => false,
        'exclude' => ['@compiler_optimized']
    ],
]);

$config = ConfigBuilder::createFromRuleSet($ruleSet)
    ->inDir(__DIR__ . '/src')
    ->inDir(__DIR__ . '/tests')
    ->addFiles([__FILE__])
    ->getConfig()
;

$config->setCacheFile(__DIR__ . '/.build/php-cs-fixer/php-cs-fixer.cache');

return $config;

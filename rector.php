<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Transform\Rector\String_\StringToClassConstantRector;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

/*
 * Rector runs in CI as a dry-run gate (composer refactor:check). Local 8.3 is the
 * syntax floor: never let it rewrite code into PHP 8.4-only constructs.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_130,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSkip([
        // Tests set and unset $_ENV on purpose; this rule would rewrite
        // unset($_ENV['X']) into unset(Env::get('X')), which is not valid PHP.
        EnvVariableToEnvHelperRector::class => [__DIR__.'/tests'],
        // The Laravel 5 upgrade sets map old event names such as 'auth.login' to event classes,
        // which silently renamed our routes named 'auth.login' / 'auth.logout'.
        StringToClassConstantRector::class,
    ]);

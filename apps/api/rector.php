<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedConstructorParamRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\PostRector\Rector\NameImportingPostRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php83: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        LaravelLevelSetList::UP_TO_LARAVEL_110,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
        SetList::EARLY_RETURN,
    ])
    ->withImportNames(
        importNames: true,
        importDocBlockNames: true,
        importShortClasses: false,
    )
    ->withSkip([
        __DIR__.'/bootstrap/cache',
        __DIR__.'/storage',
        __DIR__.'/vendor',
        // Routes file deliberately uses namespace-aliased references
        // (`Api\Domains\ListDomains::class`) to collapse what would be 20+
        // identical-prefix `use` statements. Don't let the post-rector
        // helpfully "fix" that into individual imports.
        NameImportingPostRector::class => [
            __DIR__.'/routes/api.php',
        ],
        // Deliberate stub — the constructor properties exist so the real
        // CF Browser Rendering implementation can land without touching
        // the service provider binding. Rector keeps stripping them as
        // "dead code"; skip the dead-code passes on this file.
        RemoveUnusedPromotedPropertyRector::class => [
            __DIR__.'/app/Services/Scanner/HttpCfBrowserRenderingScanner.php',
        ],
        RemoveUnusedConstructorParamRector::class => [
            __DIR__.'/app/Services/Scanner/HttpCfBrowserRenderingScanner.php',
        ],
        RemoveUnusedPrivatePropertyRector::class => [
            __DIR__.'/app/Services/Scanner/HttpCfBrowserRenderingScanner.php',
        ],
        RemoveEmptyClassMethodRector::class => [
            __DIR__.'/app/Services/Scanner/HttpCfBrowserRenderingScanner.php',
        ],
    ]);

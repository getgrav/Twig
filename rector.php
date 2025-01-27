<?php

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withPhpSets(php82: true);
//    ->withPhpVersion(Rector\ValueObject\PhpVersion::PHP_84)
//    ->withRules([
//         Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector::class,
//    ]);
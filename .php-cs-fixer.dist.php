<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/tools', __DIR__.'/migrations'])->append([__FILE__]);

return (new Config())
    ->setRules(['@Symfony' => true, 'declare_strict_types' => true])
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__.'/var/php-cs-fixer.cache')
    ->setFinder($finder);

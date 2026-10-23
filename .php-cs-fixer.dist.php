<?php

// PrestaShop's coding standard (prestashop/php-dev-tools), as PrestaShop's
// own modules use it, for the module itself and its build script:
// `scripts/test lint` checks it, `scripts/test fix` applies it. The tests
// keep the style of the other Monokulo plugins' tests.
$config = new PrestaShop\CodingStandards\CsFixer\Config();
$config->setUsingCache(false)
    ->getFinder()
    ->in(__DIR__ . '/src')
    ->append([__DIR__ . '/scripts/build-zip', __DIR__ . '/.php-cs-fixer.dist.php']);

return $config;

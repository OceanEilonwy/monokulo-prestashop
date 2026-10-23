<?php
// Places an order as "Pay with Monero" does, before Monokulo is asked for
// anything, in the installed shop, and prints its id: the HTTP suite's
// webhooks then have a real order to move. Run inside the shop container.
require __DIR__ . '/../../vendor/autoload.php';
Monokulo\PrestaShop\Tests\Support\PrestaShop::boot('front');
Monokulo\PrestaShop\Tests\Support\PrestaShop::newRequest();
echo Monokulo\PrestaShop\Tests\Support\Fixtures::placedOrder()->id, "\n";

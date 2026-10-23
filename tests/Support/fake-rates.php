<?php
/**
 * A stand-in for CoinGecko's price API, for the smoke test's Monokulo
 * (docker/monokulo-smoke.toml): 1 XMR is 150 in every currency asked for,
 * so making an order never waits on, or is refused by, the real one, and
 * it prices GBP, EUR and USD (`supported_vs_currencies`). A
 * router for PHP's built-in server (scripts/smoke).
 *
 * While the file DOWN exists, it rate limits Monokulo instead (429,
 * Retry-After 2), as CoinGecko does when it's busy: a Monokulo that has
 * no rate yet then has none to price an order with (`rates-unavailable`).
 */
const DOWN = '/tmp/monokulo-smoke-rates-down';

$path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/v3/simple/supported_vs_currencies') {
	header('Content-Type: application/json');
	echo '["gbp","eur","usd"]';

	return true;
}
if ($path !== '/api/v3/simple/price') {
	http_response_code(404);

	return true;
}
header('Content-Type: application/json');
if (is_file(DOWN)) {
	http_response_code(429);
	header('Retry-After: 2');
	echo '{"status":{"error_code":429,"error_message":"You\'ve exceeded the Rate Limit."}}';

	return true;
}
$prices = [];
foreach (explode(',', strtolower((string)($_GET['vs_currencies'] ?? ''))) as $currency) {
	if ($currency !== '') {
		$prices[$currency] = 150;
	}
}
echo json_encode(['monero' => $prices]);

return true;

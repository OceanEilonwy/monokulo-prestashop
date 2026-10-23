<?php
namespace Monokulo\PrestaShop\Tests\Http;

use Monokulo\PrestaShop\Plugin;
use Monokulo\PrestaShop\Tests\Support\Shop;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * dist/monokulo.zip through the back office's module manager, as a merchant
 * installs it: Modules > Module Manager > Upload a module. Any earlier
 * install is uninstalled first the same way, which also shows uninstalling
 * keeps the module's tables and its order status.
 *
 * scripts/test install builds the zip and runs this before every other
 * suite, which then test the module as installed here.
 */
#[Group('installer')]
final class InstallerTest extends TestCase {
	private const ZIP = __DIR__ . '/../../dist/monokulo.zip';
	private const TABLES = ['ps_monokulo_connection', 'ps_monokulo_order', 'ps_monokulo_event', 'ps_monokulo_connect_nonce'];
	private const MODULE_DIR = '/var/www/html/modules/monokulo';

	public function test_over_http_the_zip_installs_through_the_module_manager(): void {
		$this->assertFileExists(self::ZIP, 'run scripts/build-zip first');
		$browser = Shop::admin();
		$token = Shop::routeToken($browser);
		$modules = Shop::ADMIN . 'index.php/improve/modules/';

		$awaiting = null;
		if (Shop::rows("SELECT id_module FROM ps_module WHERE name = 'monokulo'")) {
			$awaiting = Shop::setting('MONOKULO_OS_AWAITING');
			$tables = array_filter(self::TABLES, static fn(string $table): bool => (bool)Shop::rows("SHOW TABLES LIKE '$table'"));
			$answer = $browser->post($modules . 'manage/action/uninstall/monokulo?_token=' . $token, ['actionParams[deletion]' => '1'])->json();
			$this->assertTrue($answer['monokulo']['status'] ?? false, json_encode($answer));
			$this->assertDirectoryDoesNotExist(self::MODULE_DIR);
			foreach ($tables as $table) {
				$this->assertNotEmpty(Shop::rows("SHOW TABLES LIKE '$table'"), "$table stays after uninstalling");
			}
			$this->assertSame([], Shop::rows("SELECT name FROM ps_configuration WHERE name LIKE 'MONOKULO\\_%'"), 'the settings are gone');
			$this->assertNotEmpty(Shop::rows("SELECT id_order_state FROM ps_order_state WHERE id_order_state = " . (int)$awaiting . " AND module_name = 'monokulo' AND deleted = 0"), 'orders keep their status');
		}

		$upload = $browser->upload($modules . 'import?_token=' . $token, 'file_uploaded', self::ZIP, 'monokulo.zip')->json();
		$this->assertTrue($upload['status'] ?? false, json_encode($upload));
		$this->assertSame('monokulo', $upload['module_name'] ?? null, json_encode($upload));

		$zip = new \ZipArchive();
		$zip->open(self::ZIP);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (!str_ends_with($name, '/')) {
				$this->assertSame($zip->getFromIndex($i), file_get_contents('/var/www/html/modules/' . $name), $name);
			}
		}

		$module = Shop::rows("SELECT id_module, active, version FROM ps_module WHERE name = 'monokulo'")[0];
		$this->assertSame('1', (string)$module['active']);
		$this->assertSame(Plugin::VERSION, $module['version']);
		$hooks = array_column(Shop::rows("SELECT h.name FROM ps_hook_module hm JOIN ps_hook h ON h.id_hook = hm.id_hook WHERE hm.id_module = " . (int)$module['id_module'] . " ORDER BY h.name"), 'name');
		$this->assertSame(['displayOrderDetail', 'displayPaymentReturn', 'paymentOptions'], $hooks);
		foreach (self::TABLES as $table) {
			$this->assertNotEmpty(Shop::rows("SHOW TABLES LIKE '$table'"), $table);
		}
		$this->assertNotEmpty(Shop::rows("SELECT * FROM ps_module_currency WHERE id_module = " . (int)$module['id_module']), 'PrestaShop offers it in every currency to begin with');

		$status = (int)Shop::setting('MONOKULO_OS_AWAITING');
		$this->assertSame('Awaiting Monero payment', Shop::rows("SELECT name FROM ps_order_state_lang WHERE id_order_state = $status AND id_lang = 1")[0]['name']);
		if ($awaiting !== null) {
			$this->assertSame((int)$awaiting, $status, 'installing again finds its order status again');
		}
		$this->assertSame('https://www.monokulo.com', (string)Shop::setting('MONOKULO_ADDRESS'));
		$this->assertSame('0', Shop::setting('MONOKULO_FORWARD_ERRORS'));
		foreach (['PENDING', 'WAITING', 'PARTIAL'] as $key) {
			$this->assertSame((string)$status, Shop::setting('MONOKULO_OS_' . $key), $key);
		}
		$this->assertSame(Shop::setting('PS_OS_PAYMENT'), Shop::setting('MONOKULO_OS_PAID'));
		$this->assertSame(Shop::setting('PS_OS_CANCELED'), Shop::setting('MONOKULO_OS_EXPIRED'));

		$settings = Shop::settingsPage($browser);
		$this->assertSame(200, $settings->status);
		$this->assertStringContainsString('Monokulo address', $settings->text());
	}
}

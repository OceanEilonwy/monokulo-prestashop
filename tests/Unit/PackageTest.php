<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\Plugin;
use PHPUnit\Framework\TestCase;

/** What PrestaShop and Monokulo read about the module, and where the version is written. */
final class PackageTest extends TestCase {
	private const MODULE = __DIR__ . '/../../src/monokulo.php';

	/** The version is written in three places (VERSION, Plugin::VERSION, the module class); they must agree. */
	public function test_the_version_is_the_same_everywhere(): void {
		$this->assertMatchesRegularExpression('/\$this->version = \'([^\']+)\';/', (string)file_get_contents(self::MODULE));
		preg_match('/\$this->version = \'([^\']+)\';/', (string)file_get_contents(self::MODULE), $m);

		$this->assertSame(trim((string)file_get_contents(__DIR__ . '/../../VERSION')), Plugin::VERSION);
		$this->assertSame(Plugin::VERSION, $m[1]);
		$this->assertSame('prestashop/' . Plugin::VERSION, Plugin::client());
	}

	/** Monokulo takes a slug of a lowercase letter then 1 to 31 lowercase letters, digits or hyphens, and a name up to 40. */
	public function test_the_slug_and_name_are_ones_monokulo_takes(): void {
		$this->assertMatchesRegularExpression('/\A[a-z][a-z0-9-]{1,31}\z/', Plugin::SLUG);
		$this->assertLessThanOrEqual(40, mb_strlen(Plugin::NAME));
	}

	/**
	 * PrestaShop's validator: an index.php in every folder, and every other
	 * PHP file stops unless PrestaShop loaded it.
	 */
	public function test_every_folder_has_an_index_and_every_file_its_guard(): void {
		$src = realpath(__DIR__ . '/../../src');
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
		$checked = 0;
		foreach ($files as $file) {
			$path = substr($file->getPathname(), strlen($src) + 1);
			if ($file->isDir()) {
				$this->assertFileExists($file->getPathname() . '/index.php', $path);
			} elseif (str_ends_with($path, '.php') && basename($path) !== 'index.php') {
				$this->assertStringContainsString("if (!defined('_PS_VERSION_')) {\n    exit;\n}", (string)file_get_contents($file->getPathname()), $path);
				$checked++;
			}
		}
		$this->assertGreaterThan(20, $checked);
		$this->assertFileExists($src . '/index.php');
		$this->assertFileExists($src . '/logo.png');
	}
}

<?php
namespace AdmidioPlugin\Provisioning\Tests\Unit;

use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Tests\Support\AdmidioTestCase;

final class ProvisioningManifestTest extends AdmidioTestCase
{
    public function testManifestUsesTheNativePluginContract(): void
    {
        $pluginPath = dirname(__DIR__, 2);
        $plugin = Plugin::read($pluginPath);

        $this->assertTrue($plugin->isValid(), (string) $plugin->error);
        $this->assertSame('provisioning', $plugin->id);
        $pages = $plugin->getPages();
        $this->assertContains('index.php', $pages);
        $this->assertContains('groups.php', $pages);

        $normalize = static fn(?string $path): ?string => $path === null ? null : str_replace('\\', '/', $path);
        $this->assertSame(
            $normalize(realpath($pluginPath . '/src')),
            $normalize($plugin->autoload['AdmidioPlugin\\Provisioning\\'] ?? null)
        );

        $this->assertSame(5, $plugin->settings['provisioning_request_budget']['default'] ?? null);
        $this->assertFalse($plugin->settings['provisioning_allow_private_network']['default'] ?? null);
        $this->assertTrue($plugin->settings['provisioning_notify_admins']['default'] ?? null);
    }

    /**
     * Every language key the plugin uses exists in all three language files, and the files have the
     * same keys.
     */
    public function testLanguageFilesAreComplete(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array();
        foreach (array('en', 'de', 'de-DE') as $language) {
            preg_match_all('/<string name="([A-Z0-9_]+)"/', (string) file_get_contents($root . '/languages/' . $language . '.xml'), $matches);
            $files[$language] = $matches[1];
        }
        $this->assertSame($files['en'], $files['de']);
        $this->assertSame($files['en'], $files['de-DE']);

        $used = array();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/tests/') || !preg_match('/\.(php|tpl|json)$/', $path)) {
                continue;
            }
            preg_match_all('/PLG_PROVISIONING_[A-Z0-9_]*[A-Z0-9]/', (string) file_get_contents($path), $matches);
            $used = array_merge($used, $matches[0]);
        }
        // keys that are built from a prefix and a state
        foreach (array('STATE_OK', 'STATE_PARTIAL', 'STATE_PAUSED', 'STATE_DEPROVISIONING', 'GROUP_SOURCE_MANUAL', 'GROUP_SOURCE_SSO', 'GROUP_SOURCE_CATCHALL') as $suffix) {
            $used[] = 'PLG_PROVISIONING_' . $suffix;
        }

        $missing = array_diff(array_unique($used), $files['en']);
        $this->assertSame(array(), array_values($missing));
    }
}

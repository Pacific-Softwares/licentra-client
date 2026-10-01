<?php

namespace Pacific\Licentra\Tests;

use Pacific\Licentra\Config;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Modules\ModuleLicenses;
use Pacific\Licentra\Modules\Registry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class ModuleLicensesTest extends TestCase
{
    private Signer $signer;
    private ModuleLicenses $licenses;
    private string $registryFile;

    protected function setUp(): void
    {
        $this->signer = new Signer();
        $store = new MemoryStore();
        $store->data = ['instance_id' => 'parent-iid', 'token' => $this->signer->token(['product' => 'slotara', 'iid' => 'parent-iid', 'domain' => 'shop.com'])];
        $licentra = new Licentra(new Config('slotara', $this->signer->public, '/unused', 'https://shop.com', '2.4.0'), $store, new FakeTransport());
        $this->licenses = new ModuleLicenses($licentra);
        $this->registryFile = sys_get_temp_dir() . '/licentra-ml-' . bin2hex(random_bytes(4)) . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->registryFile);
    }

    private function token(array $override = []): string
    {
        return $this->signer->token($override + ['product' => 'slotara-hello', 'iid' => 'mod-iid', 'parent' => 'parent-iid', 'domain' => 'shop.com']);
    }

    private function module(array $override = []): array
    {
        return $override + ['slug' => 'slotara-hello', 'token' => $this->token(), 'license_status' => 'valid'];
    }

    public function test_a_token_for_this_module_install_and_domain_is_fine(): void
    {
        $this->assertNull($this->licenses->problem($this->module()));
        $this->assertNull($this->licenses->warning($this->module()));
    }

    public function test_tokens_for_another_module_another_install_or_another_domain_are_refused(): void
    {
        foreach ([['product' => 'slotara-other'], ['parent' => 'someone-else'], ['domain' => 'other.com']] as $bad) {
            $this->assertNotNull($this->licenses->problem($this->module(['token' => $this->token($bad)])), json_encode($bad));
        }
        $this->assertNotNull($this->licenses->problem($this->module(['token' => null])));
        $this->assertNotNull($this->licenses->problem($this->module(['token' => (new Signer())->token(['product' => 'slotara-hello', 'parent' => 'parent-iid', 'domain' => 'shop.com'])])));
    }

    public function test_refunded_addon_keeps_running_for_the_grace_period_then_stops(): void
    {
        $since = time() - 3 * 86400;
        $module = $this->module(['license_status' => 'revoked', 'license_problem_since' => $since]);

        $this->assertNull($this->licenses->problem($module));
        $this->assertStringContainsString('switches off in 4 day', (string) $this->licenses->warning($module));

        $this->assertStringContainsString('refunded', (string) $this->licenses->problem($module, $since + 8 * 86400));
    }

    public function test_expired_token_gets_the_same_grace_then_stops(): void
    {
        $module = $this->module(['token' => $this->token(['exp' => time() - 86400])]);
        $this->assertNull($this->licenses->problem($module));

        $module = $this->module(['token' => $this->token(['exp' => time() - 8 * 86400])]);
        $this->assertStringContainsString('could not be refreshed', (string) $this->licenses->problem($module));
    }

    public function test_developer_mode_modules_are_not_licensed(): void
    {
        $this->assertNull($this->licenses->problem(['slug' => 'slotara-x', 'dev' => true]));
    }

    public function test_heartbeat_states_are_absorbed_and_foreign_tokens_ignored(): void
    {
        $registry = new Registry($this->registryFile);
        $registry->put('slotara-hello', ['version' => '1.0.0', 'status' => 'enabled', 'token' => 'old']);
        $registry->put('slotara-other', ['version' => '1.0.0', 'status' => 'enabled', 'token' => 'keep']);
        $registry->put('slotara-dev', ['version' => '1.0.0', 'status' => 'enabled', 'dev' => true]);

        $this->licenses->absorb([
            'slotara-hello' => ['instance_id' => 'mod-iid', 'status' => 'valid', 'token' => $this->token(), 'update' => ['version' => '1.1.0']],
            // A token issued for a different install must never be stored.
            'slotara-other' => ['instance_id' => 'x', 'status' => 'revoked', 'token' => $this->token(['product' => 'slotara-other', 'parent' => 'not-me'])],
        ], $registry);

        $hello = $registry->get('slotara-hello');
        $this->assertSame($this->licenses->tokenIsOurs('slotara-hello', $hello['token']), true);
        $this->assertSame('1.1.0', $hello['update']['version']);
        $other = $registry->get('slotara-other');
        $this->assertSame('keep', $other['token']);
        $this->assertSame('revoked', $other['license_status']);
        $this->assertNotNull($other['license_problem_since']);
        $this->assertArrayNotHasKey('license_status', $registry->get('slotara-dev'));
    }

    public function test_registry_survives_a_corrupt_file(): void
    {
        file_put_contents($this->registryFile, '<?php return [');

        $this->assertSame([], (new Registry($this->registryFile))->all());
    }
}

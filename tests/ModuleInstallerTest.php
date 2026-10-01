<?php

namespace Pacific\Licentra\Tests;

use Pacific\Licentra\Config;
use Pacific\Licentra\Http\Response;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Modules\ModuleException;
use Pacific\Licentra\Modules\ModuleInstaller;
use Pacific\Licentra\Modules\Registry;
use Pacific\Licentra\Update\ReleaseSignature;
use Pacific\Licentra\Update\Updater;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

/**
 * Module installs against a fake license server: a signed zip comes back from "activate" +
 * "download", and the installer must put it in modules/{slug} or leave the site untouched.
 */
final class ModuleInstallerTest extends TestCase
{
    private string $tmp;
    private Signer $signer;
    private array $releaseKey;
    private FakeTransport $http;
    private FakeDownloader $downloader;
    private RecordingModuleHooks $hooks;
    private Registry $registry;
    private Licentra $licentra;
    private bool $updateRunning = false;
    private array $audit = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/licentra-mi-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->signer = new Signer();
        $this->releaseKey = ReleaseSignature::generateKeyPair();
        $this->http = new FakeTransport();
        $this->downloader = new FakeDownloader();
        $this->hooks = new RecordingModuleHooks();
        $this->registry = new Registry($this->tmp . '/storage/licentra-modules.php');

        $store = new MemoryStore();
        $store->data = ['instance_id' => 'parent-iid', 'token' => $this->signer->token(['product' => 'slotara', 'iid' => 'parent-iid', 'domain' => 'shop.com']), 'last_heartbeat_at' => time()];
        $this->licentra = new Licentra(new Config('slotara', $this->signer->public, '/unused', 'https://shop.com', '2.4.0'), $store, $this->http, $this->downloader);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private function installer(?string $releaseKey = null): ModuleInstaller
    {
        return new ModuleInstaller(
            $this->licentra, $this->registry, $this->hooks,
            $this->tmp . '/modules', $this->tmp . '/public/modules', $this->tmp . '/storage/op', $this->tmp . '/storage/op.lock',
            $releaseKey ?? $this->releaseKey['public'], '2.4.0',
            fn () => $this->updateRunning,
            function (string $action, string $slug, ?string $version) {
                $this->audit[] = "{$action} {$slug} {$version}";
            },
        );
    }

    /** Queue the server's answers for one install: activation response + the zip download. */
    private function serve(ModuleFixture $fx, array $extraFiles = [], ?string $signAs = null, ?string $signVersion = null): void
    {
        $zip = $fx->zip($this->tmp, $extraFiles);
        $this->downloader->zip = $zip['path'];
        $this->http->queue[] = new Response(201, [
            'ok' => true, 'instance_id' => 'mod-iid', 'status' => 'valid',
            'token' => $this->signer->token(['product' => $fx->slug, 'iid' => 'mod-iid', 'parent' => 'parent-iid', 'domain' => 'shop.com']),
            'update' => ['version' => $fx->version, 'download' => [
                'sha256' => $zip['sha256'],
                'signature' => ReleaseSignature::sign($this->releaseKey['secret'], $signAs ?? $fx->slug, $signVersion ?? $fx->version, $zip['sha256']),
            ]],
        ]);
    }

    private function install(string $slug = 'slotara-hello', ?string $code = 'code'): array
    {
        $installer = $this->installer();
        $installer->start($slug, $code);

        return $installer->runToEnd();
    }

    public function test_signed_module_is_installed_migrated_and_enabled(): void
    {
        $this->serve(new ModuleFixture());

        $status = $this->install();

        $this->assertSame('done', $status['step']);
        $this->assertFileExists($this->tmp . '/modules/slotara-hello/module.json');
        $module = $this->registry->get('slotara-hello');
        $this->assertSame(Registry::ENABLED, $module['status']);
        $this->assertSame('mod-iid', $module['instance_id']);
        $this->assertSame($this->tmp . '/modules/slotara-hello', $module['path']);
        $this->assertSame([['migrate', 'slotara-hello', $this->tmp . '/modules/slotara-hello/database/migrations']], array_values(array_filter($this->hooks->calls, fn ($c) => $c[0] === 'migrate')));
        $this->assertContains('changed', $this->hooks->names());
        $this->assertSame(['install_started slotara-hello 1.0.0', 'installed slotara-hello 1.0.0'], $this->audit);

        // The server was asked to activate this add-on on this install.
        $this->assertSame(['product' => 'slotara-hello', 'parent_instance_id' => 'parent-iid', 'purchase_code' => 'code'], array_intersect_key($this->http->sent[0]['body'], array_flip(['product', 'parent_instance_id', 'purchase_code'])));
        $this->assertSame('mod-iid', $this->downloader->sent[0]['body']['instance_id']);
    }

    public function test_only_static_assets_are_published(): void
    {
        $this->serve(new ModuleFixture());

        $this->install();

        $this->assertFileExists($this->tmp . '/public/modules/slotara-hello/app.css');
        $this->assertFileDoesNotExist($this->tmp . '/public/modules/slotara-hello/shell.php');
    }

    public function test_bad_signature_installs_nothing(): void
    {
        $this->serve(new ModuleFixture());
        $installer = $this->installer(ReleaseSignature::generateKeyPair()['public']);
        $installer->start('slotara-hello', 'code');

        $status = $installer->runToEnd();

        $this->assertSame('failed', $status['step']);
        $this->assertStringContainsString('signature', $status['error']);
        $this->assertDirectoryDoesNotExist($this->tmp . '/modules/slotara-hello');
        $this->assertNull($this->registry->get('slotara-hello'));
    }

    public function test_a_validly_signed_zip_of_another_module_is_refused(): void
    {
        // The server hands out slotara-evil's zip (signed by us, for slotara-evil) when asked for slotara-hello.
        $this->serve(new ModuleFixture('slotara-evil'), signAs: 'slotara-hello');
        $this->http->queue[0] = new Response(201, ['ok' => true, 'instance_id' => 'mod-iid', 'token' => 'x'] + ['update' => $this->http->queue[0]->json['update']]);

        $status = $this->install('slotara-hello');

        $this->assertSame('failed', $status['step']);
        $this->assertStringContainsString('expected slotara-hello', $status['error']);
        $this->assertDirectoryDoesNotExist($this->tmp . '/modules');
    }

    public function test_signature_for_another_version_is_refused(): void
    {
        $this->serve(new ModuleFixture(version: '1.0.0'), signVersion: '0.9.0');

        $this->assertStringContainsString('signature', $this->install()['error']);
    }

    public function test_incompatible_or_vendor_shipping_modules_are_refused(): void
    {
        $this->serve(new ModuleFixture(manifest: ['requires' => ['product' => '^3.0']]));
        $this->assertStringContainsString('needs slotara ^3.0', $this->install()['error']);
        $this->installer()->reset();

        $this->serve(new ModuleFixture(), ['vendor/autoload.php' => '<?php']);
        $this->assertStringContainsString('vendor/', $this->install()['error']);
    }

    public function test_older_version_than_installed_is_refused(): void
    {
        $this->serve(new ModuleFixture(version: '1.2.0'));
        $this->install();
        $this->installer()->reset();

        $this->serve(new ModuleFixture(version: '1.1.0'));
        try {
            $this->installer()->start('slotara-hello', 'code');
            $this->fail('downgrade should be refused');
        } catch (ModuleException $e) {
            $this->assertSame('downgrade', $e->reason);
        }
        $this->assertSame('1.2.0', $this->registry->get('slotara-hello')['version']);
    }

    public function test_update_uses_the_stored_activation_and_keeps_install_date(): void
    {
        $this->serve(new ModuleFixture(version: '1.0.0'));
        $this->install();
        $this->installer()->reset();
        $installedAt = $this->registry->get('slotara-hello')['installed_at'];

        $fx = new ModuleFixture(version: '1.1.0');
        $zip = $fx->zip($this->tmp);
        $this->downloader->zip = $zip['path'];
        $this->registry->update('slotara-hello', ['update' => ['version' => '1.1.0', 'download' => [
            'sha256' => $zip['sha256'], 'signature' => ReleaseSignature::sign($this->releaseKey['secret'], 'slotara-hello', '1.1.0', $zip['sha256']),
        ]]]);

        $status = $this->install(code: null);

        $this->assertSame('done', $status['step']);
        $this->assertCount(1, $this->http->sent, 'an update must not activate the add-on again');
        $this->assertSame('1.1.0', $this->registry->get('slotara-hello')['version']);
        $this->assertSame($installedAt, $this->registry->get('slotara-hello')['installed_at']);
    }

    public function test_failed_migration_keeps_files_and_needs_attention_until_retried(): void
    {
        $this->serve(new ModuleFixture());
        $this->hooks->failMigrate = 'SQLSTATE[42S01]: table exists';

        $status = $this->install();

        $this->assertSame('failed', $status['step']);
        $this->assertStringContainsString('database update failed', $status['error']);
        $this->assertFileExists($this->tmp . '/modules/slotara-hello/module.json');
        $this->assertSame(Registry::NEEDS_ATTENTION, $this->registry->get('slotara-hello')['status']);

        $this->hooks->failMigrate = null;
        $this->installer()->retryMigrations('slotara-hello');
        $this->assertSame(Registry::ENABLED, $this->registry->get('slotara-hello')['status']);
    }

    public function test_module_install_refuses_while_a_product_update_is_unfinished(): void
    {
        $this->serve(new ModuleFixture());
        $this->updateRunning = true;

        $this->expectExceptionMessage('Another update or module install is running');
        $this->installer()->start('slotara-hello', 'code');
    }

    public function test_product_update_refuses_while_a_module_install_is_unfinished(): void
    {
        $this->serve(new ModuleFixture());
        $installer = $this->installer();
        $installer->start('slotara-hello', 'code');
        $installer->step(); // downloaded, not finished

        $updater = new Updater($this->licentra, new RecordingHooks(), $this->tmp . '/site', $this->tmp . '/update-work', $this->releaseKey['public'],
            lockPath: $this->tmp . '/storage/op.lock', otherOperationRunning: fn () => $installer->inProgress());

        $this->expectExceptionMessage('A module is being installed');
        $updater->start('9.9.9');
    }

    public function test_a_step_while_another_holds_the_shared_lock_reports_busy(): void
    {
        $this->serve(new ModuleFixture());
        $installer = $this->installer();
        $installer->start('slotara-hello', 'code');

        $lock = fopen($this->tmp . '/storage/op.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $installer->step();
            $this->fail('expected busy');
        } catch (ModuleException $e) {
            $this->assertSame('busy', $e->reason);
        } finally {
            flock($lock, LOCK_UN);
        }
    }

    public function test_enable_disable_and_uninstall_keeping_or_deleting_data(): void
    {
        $this->serve(new ModuleFixture());
        $this->install();
        $installer = $this->installer();

        $installer->disable('slotara-hello');
        $this->assertSame(Registry::DISABLED, $this->registry->get('slotara-hello')['status']);
        $installer->enable('slotara-hello');
        $this->assertSame(Registry::ENABLED, $this->registry->get('slotara-hello')['status']);

        try {
            $installer->enable('slotara-hello', 'The license for this module was refunded or cancelled.');
            $this->fail('a license problem must block enabling');
        } catch (ModuleException $e) {
            $this->assertStringContainsString('refunded', $e->getMessage());
        }

        $installer->uninstall('slotara-hello');
        $this->assertNull($this->registry->get('slotara-hello'));
        $this->assertDirectoryDoesNotExist($this->tmp . '/modules/slotara-hello');
        $this->assertDirectoryDoesNotExist($this->tmp . '/public/modules/slotara-hello');
        $this->assertNotContains('rollback', $this->hooks->names(), 'data is kept by default');

        $installer->reset();
        $this->serve(new ModuleFixture());
        $this->install();
        $this->installer()->uninstall('slotara-hello', deleteData: true);
        $this->assertContains('rollback', $this->hooks->names());
    }

    public function test_module_without_a_published_release_is_refused_before_anything_happens(): void
    {
        $this->http->queue[] = new Response(201, ['ok' => true, 'instance_id' => 'mod-iid', 'update' => null]);

        $this->expectExceptionMessage('no published release');
        $this->installer()->start('slotara-hello', 'code');
    }
}

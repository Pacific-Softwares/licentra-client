<?php

namespace Pacific\Licentra\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Orchestra\Testbench\TestCase;
use Pacific\Licentra\Laravel\LicentraServiceProvider;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Modules\Laravel\ModuleLoader;
use Pacific\Licentra\Modules\Laravel\TenantGate;
use Pacific\Licentra\Modules\Registry;

require_once __DIR__ . '/Fakes.php';

final class DenyAllTenants implements TenantGate
{
    public function allows(string $slug, Request $request): bool
    {
        return false;
    }
}

/**
 * The Laravel side of modules in a real app: an installed module is loaded at boot, its routes
 * are gated, a crashing module is switched off, and the Modules page manages it.
 */
final class LaravelModulesTest extends TestCase
{
    private static string $tmp;
    private static Signer $signer;
    /** Per-test knobs read by defineEnvironment(), which runs before the providers register. */
    private static array $env = [];
    private static ?ModuleFixture $fixture = null;

    protected function getPackageProviders($app): array
    {
        return [LicentraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        self::$tmp = sys_get_temp_dir() . '/licentra-lm-' . bin2hex(random_bytes(4));
        self::$signer = new Signer();
        @mkdir(self::$tmp . '/storage', 0777, true);
        $env = self::$env + ['status' => Registry::ENABLED, 'parent' => 'parent-iid', 'boot' => '$this->loadModuleRoutes();'];

        file_put_contents(self::$tmp . '/storage/licentra.json', json_encode([
            'instance_id' => 'parent-iid',
            'token' => self::$signer->token(['product' => 'slotara', 'iid' => 'parent-iid', 'domain' => 'shop.com']),
            'last_heartbeat_at' => time(),
        ]));

        // An installed module, as ModuleInstaller would have left it.
        self::$fixture = new ModuleFixture(bootModule: $env['boot']);
        $path = self::$fixture->write(self::$tmp . '/modules/slotara-hello');
        $manifest = json_decode(file_get_contents($path . '/module.json'), true);
        unset($manifest['manifest']);
        (new Registry(self::$tmp . '/storage/licentra-modules.php'))->put('slotara-hello', $manifest + [
            'status' => $env['status'], 'path' => $path, 'dev' => false, 'instance_id' => 'mod-iid', 'license_status' => 'valid',
            'token' => self::$signer->token(['product' => 'slotara-hello', 'iid' => 'mod-iid', 'parent' => $env['parent'], 'domain' => 'shop.com']),
        ]);

        if (!empty($env['devModule'])) {
            (new ModuleFixture($env['devModule']))->write(self::$tmp . '/modules-dev/' . $env['devModule']);
        }

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://shop.com');
        $app['config']->set('licentra', array_replace_recursive($app['config']->get('licentra', []), [
            'product' => 'slotara',
            'public_key' => self::$signer->public,
            'product_version' => '2.4.0',
            'release_public_key' => 'unused',
            'server_url' => 'http://127.0.0.1:9', // nothing listens: the add-on list fails fast
            'storage_path' => self::$tmp . '/storage/licentra.json',
            'operation_lock' => self::$tmp . '/storage/op.lock',
            'modules' => [
                'enabled' => true,
                'path' => self::$tmp . '/modules',
                'public_path' => self::$tmp . '/public/modules',
                'registry' => self::$tmp . '/storage/licentra-modules.php',
                'work_path' => self::$tmp . '/storage/op',
                'crash_path' => self::$tmp . '/storage/crashes',
                'audit_log' => self::$tmp . '/storage/modules.log',
                'dev' => $env['dev'] ?? false,
                'dev_path' => self::$tmp . '/modules-dev',
                'safe_mode' => $env['safe'] ?? false,
                'tenant_gate' => $env['gate'] ?? \Pacific\Licentra\Modules\Laravel\AllowAllTenants::class,
            ],
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::define('manage-licentra', fn () => true);
    }

    protected function tearDown(): void
    {
        // Testbench shares one Laravel skeleton across tests: never leave a route cache behind.
        @unlink($this->app->getCachedRoutesPath());
        exec('rm -rf ' . escapeshellarg(self::$tmp));
        self::$env = [];
        parent::tearDown();
    }

    /** Re-create the app with different module settings for this test. */
    private function boot(array $env): void
    {
        self::$env = $env;
        $this->refreshApplication();
        Gate::define('manage-licentra', fn () => true);
    }

    private function admin(): User
    {
        $u = new User();
        $u->forceFill(['id' => 1, 'email' => 'admin@shop.com', 'password' => Hash::make('secret')]);

        return $u;
    }

    public function test_an_enabled_licensed_module_is_loaded_and_its_routes_work(): void
    {
        $this->assertTrue($this->app->make(ModuleLoader::class)->isLoaded('slotara-hello'));

        $this->get('/slotara-hello')->assertOk()->assertSee('hello from slotara-hello');
    }

    public function test_a_disabled_module_is_not_loaded(): void
    {
        $this->boot(['status' => Registry::DISABLED]);

        $this->assertFalse($this->app->make(ModuleLoader::class)->isLoaded('slotara-hello'));
        $this->get('/slotara-hello')->assertNotFound();
    }

    public function test_tenant_gate_denies_module_routes(): void
    {
        $this->boot(['gate' => DenyAllTenants::class]);

        $this->get('/slotara-hello')->assertForbidden();
    }

    public function test_a_module_licensed_to_another_install_is_not_loaded(): void
    {
        $this->boot(['parent' => 'someone-elses-install']);

        $loader = $this->app->make(ModuleLoader::class);
        $this->assertFalse($loader->isLoaded('slotara-hello'));
        $this->assertStringContainsString('not activated', $loader->skipped()['slotara-hello']);
    }

    public function test_a_module_that_throws_while_booting_is_switched_off_and_the_site_keeps_working(): void
    {
        $this->boot(['boot' => 'throw new \RuntimeException("db not reachable");']);

        $this->assertFalse($this->app->make(ModuleLoader::class)->isLoaded('slotara-hello'));
        $module = (new Registry(self::$tmp . '/storage/licentra-modules.php'))->get('slotara-hello');
        $this->assertSame(Registry::DISABLED, $module['status']);
        $this->assertStringContainsString('db not reachable', $module['reason']);
        $this->actingAs($this->admin())->get('/license')->assertOk();
    }

    public function test_safe_mode_loads_nothing(): void
    {
        $this->boot(['safe' => true]);

        $this->assertSame([], $this->app->make(ModuleLoader::class)->loaded());
    }

    public function test_developer_mode_loads_unsigned_modules_from_modules_dev(): void
    {
        $this->boot(['dev' => true, 'devModule' => 'slotara-devthing']);

        $this->assertTrue($this->app->make(ModuleLoader::class)->isLoaded('slotara-devthing'));
        $this->get('/slotara-devthing')->assertOk();
    }

    public function test_dev_modules_are_ignored_without_developer_mode(): void
    {
        $this->boot(['devModule' => 'slotara-devthing']);

        $this->assertFalse($this->app->make(ModuleLoader::class)->isLoaded('slotara-devthing'));
    }

    public function test_heartbeat_modules_block_reaches_the_registry(): void
    {
        $licentra = $this->app->make(Licentra::class);
        $listener = (fn () => $this->onModules)->call($licentra);

        $listener(['slotara-hello' => ['instance_id' => 'mod-iid', 'status' => 'revoked']]);

        $this->assertSame('revoked', (new Registry(self::$tmp . '/storage/licentra-modules.php'))->get('slotara-hello')['license_status']);
    }

    public function test_modules_page_lists_installed_modules_and_survives_an_unreachable_store(): void
    {
        $this->actingAs($this->admin())->get('/license/modules')
            ->assertOk()
            ->assertSee('Hello')
            ->assertSee('Active')
            ->assertSee('could not be loaded right now');
    }

    public function test_managing_a_module_needs_the_password_and_clears_route_caches(): void
    {
        $routesCache = $this->app->getCachedRoutesPath();
        @mkdir(dirname($routesCache), 0777, true);
        file_put_contents($routesCache, '<?php');

        $this->actingAs($this->admin())->from('/license/modules')
            ->post('/license/modules/slotara-hello', ['action' => 'disable', 'password' => 'wrong'])
            ->assertSessionHasErrors('modules');
        $this->assertSame(Registry::ENABLED, (new Registry(self::$tmp . '/storage/licentra-modules.php'))->get('slotara-hello')['status']);

        $this->actingAs($this->admin())->from('/license/modules')
            ->post('/license/modules/slotara-hello', ['action' => 'disable', 'password' => 'secret'])
            ->assertSessionHas('licentra_modules');
        $this->assertSame(Registry::DISABLED, (new Registry(self::$tmp . '/storage/licentra-modules.php'))->get('slotara-hello')['status']);
        $this->assertFileDoesNotExist($routesCache);
        $this->assertStringContainsString('"action":"disabled"', (string) file_get_contents(self::$tmp . '/storage/modules.log'));
    }

    public function test_deleting_data_needs_the_slug_typed(): void
    {
        $this->actingAs($this->admin())->from('/license/modules')
            ->post('/license/modules/slotara-hello', ['action' => 'uninstall', 'password' => 'secret', 'delete_data' => 1, 'confirm_slug' => 'nope'])
            ->assertSessionHasErrors('modules');

        $this->assertNotNull((new Registry(self::$tmp . '/storage/licentra-modules.php'))->get('slotara-hello'));
    }

    public function test_install_start_checks_the_password(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/license/modules/start', ['slug' => 'slotara-hello', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('error', 'That password is not correct.');
    }

    public function test_module_list_and_disable_all_commands(): void
    {
        $this->artisan('module:list')->expectsOutputToContain('slotara-hello')->assertSuccessful();
        $this->artisan('module:disable', ['--all' => true])->assertSuccessful();

        $this->assertSame(Registry::DISABLED, (new Registry(self::$tmp . '/storage/licentra-modules.php'))->get('slotara-hello')['status']);
    }

    public function test_module_make_scaffolds_a_valid_module(): void
    {
        $this->artisan('module:make', ['name' => 'invoices'])->assertSuccessful();

        $dir = self::$tmp . '/modules-dev/slotara-invoices';
        $manifest = \Pacific\Licentra\Modules\ModuleManifest::fromFile($dir . '/module.json');
        $this->assertSame('slotara-invoices', $manifest->slug);
        $this->assertSame('^2.4', $manifest->requiresProduct);
        $this->assertFileExists($dir . '/src/ModuleServiceProvider.php');
        $this->assertFileExists($dir . '/routes/web.php');
        $this->assertNotEmpty(glob($dir . '/database/migrations/*_create_slotara_invoices_items_table.php'));
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($dir . '/src/ModuleServiceProvider.php'), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}

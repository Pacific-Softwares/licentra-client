<?php

namespace Ishalabs\Licentra\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Ishalabs\Licentra\Laravel\LicentraServiceProvider;
use Ishalabs\Licentra\Licentra;
use Orchestra\Testbench\TestCase;

require_once __DIR__ . '/Fakes.php';

final class LaravelAdapterTest extends TestCase
{
    private Signer $signer;

    protected function getPackageProviders($app): array
    {
        return [LicentraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->signer = new Signer();
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://shop-one.com');
        $app['config']->set('licentra.product', 'quizora');
        $app['config']->set('licentra.public_key', $this->signer->public);
        $app['config']->set('licentra.storage_path', sys_get_temp_dir() . '/licentra-test-' . uniqid() . '.json');
    }

    protected function defineRoutes($router): void
    {
        Route::middleware(['web', 'licentra'])->get('/admin', fn () => 'admin area');
        Route::middleware(['web'])->get('/', fn () => 'public site');
    }

    private function user(): User
    {
        $u = new User();
        $u->id = 1;

        return $u;
    }

    public function test_unlicensed_admin_is_redirected_but_public_site_works(): void
    {
        $this->get('/')->assertOk()->assertSee('public site');
        $this->actingAs($this->user())->get('/admin')->assertRedirect(route('licentra.activate'));
        $this->actingAs($this->user())->getJson('/admin')->assertForbidden();
    }

    public function test_license_page_is_denied_until_product_defines_the_gate(): void
    {
        $this->actingAs($this->user())->get('/license')->assertForbidden();

        Gate::define('manage-licentra', fn () => true);
        $this->actingAs($this->user())->get('/license')->assertOk()->assertSee('purchase code');
    }

    public function test_licensed_admin_passes_and_host_is_recorded(): void
    {
        file_put_contents(config('licentra.storage_path'), json_encode([
            'instance_id' => 'i', 'token' => $this->signer->token(), 'last_heartbeat_at' => time(),
        ]));

        $this->actingAs($this->user())->get('http://shop-one.com/admin')->assertOk()->assertSee('admin area');

        $stored = json_decode(file_get_contents(config('licentra.storage_path')), true);
        $this->assertSame('shop-one.com', $stored['request_host']);
    }

    public function test_copied_install_served_elsewhere_is_locked(): void
    {
        file_put_contents(config('licentra.storage_path'), json_encode([
            'instance_id' => 'i', 'token' => $this->signer->token(), 'last_heartbeat_at' => time(),
        ]));

        $this->actingAs($this->user())->get('http://pirate.net/admin')->assertRedirect();
        $this->assertSame('domain_mismatch', app(Licentra::class)->state()->status->value);
    }
}

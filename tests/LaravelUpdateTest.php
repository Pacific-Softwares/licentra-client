<?php

namespace Pacific\Licentra\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use Pacific\Licentra\Config;
use Pacific\Licentra\Exceptions\ServerUnreachable;
use Pacific\Licentra\Laravel\LicentraServiceProvider;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Update\Manifest;
use Pacific\Licentra\Update\ReleaseSignature;
use Pacific\Licentra\Update\Updater;

require_once __DIR__ . '/Fakes.php';

final class LaravelUpdateTest extends TestCase
{
    private string $tmp;
    private MemoryStore $store;
    private RecordingHooks $hooks;

    protected function getPackageProviders($app): array
    {
        return [LicentraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://shop-one.com');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/licentra-lu-' . bin2hex(random_bytes(4));
        Gate::define('manage-licentra', fn () => true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
        parent::tearDown();
    }

    /** Site at 1.0.0 with a signed 1.1.0 release waiting on the "server". */
    private function install(bool $withRelease = true): void
    {
        $site = $this->tmp . '/site';
        @mkdir($site . '/app', 0777, true);
        file_put_contents($site . '/app/Thing.php', 'v1');

        $build = $this->tmp . '/build/app';
        @mkdir($build, 0777, true);
        file_put_contents($build . '/Thing.php', 'v2');
        Manifest::build($this->tmp . '/build', '1.1.0')->write($this->tmp . '/build/' . Manifest::FILE);
        $zipPath = $this->tmp . '/release.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile($build . '/Thing.php', 'app/Thing.php');
        $zip->addFile($this->tmp . '/build/' . Manifest::FILE, Manifest::FILE);
        $zip->close();
        $sha = hash_file('sha256', $zipPath);

        $key = ReleaseSignature::generateKeyPair();
        $signer = new Signer();
        $this->store = new MemoryStore();
        $this->store->data = [
            'instance_id' => 'iid-1', 'token' => $signer->token(), 'last_heartbeat_at' => time(),
            'update' => $withRelease ? ['version' => '1.1.0', 'released_at' => null, 'changelog' => "Faster bookings", 'url' => null,
                'download' => ['size' => filesize($zipPath), 'sha256' => $sha, 'signature' => ReleaseSignature::sign($key['secret'], 'quizora', '1.1.0', $sha)]] : null,
        ];

        $http = new FakeTransport();
        $http->queue[] = new ServerUnreachable('offline');
        $downloader = new FakeDownloader();
        $downloader->zip = $zipPath;

        $licentra = new Licentra(new Config('quizora', $signer->public, '/unused', 'https://shop-one.com', '1.0.0'), $this->store, $http, $downloader);
        $this->hooks = new RecordingHooks();

        $this->app->instance(Licentra::class, $licentra);
        $this->app->instance(Updater::class, new Updater($licentra, $this->hooks, $site, $this->tmp . '/work', $key['public']));
        config(['licentra.update.work_path' => $this->tmp . '/work']);
    }

    private function admin(): User
    {
        $u = new User();
        $u->id = 1;

        return $u;
    }

    public function test_update_page_offers_a_signed_update(): void
    {
        $this->install();

        $this->actingAs($this->admin())->get('/license/update')
            ->assertOk()->assertSee('Update to 1.1.0')->assertSee('Faster bookings');
        $this->actingAs($this->admin())->get('/license')->assertSee('Update now');
    }

    public function test_update_page_without_an_update(): void
    {
        $this->install(withRelease: false);

        $this->actingAs($this->admin())->get('/license/update')->assertOk()->assertSee('latest version');
    }

    public function test_start_and_steps_install_the_update(): void
    {
        $this->install();
        $this->actingAs($this->admin());

        $this->postJson('/license/update/start', ['version' => '1.1.0'])->assertOk()->assertJsonPath('step', 'download');
        for ($i = 0; $i < 10; $i++) {
            $step = $this->postJson('/license/update/step')->assertOk()->json('step');
            if (in_array($step, ['done', 'failed'], true)) {
                break;
            }
        }

        $this->assertSame('done', $step);
        $this->assertSame('v2', file_get_contents($this->tmp . '/site/app/Thing.php'));
        $this->assertSame(['beforeApply', 'finish'], $this->hooks->calls);
    }

    public function test_next_request_finishes_an_applied_update(): void
    {
        $this->install();
        $updater = $this->app->make(Updater::class);
        $updater->start('1.1.0');
        $updater->runToFinish(includeFinish: false);
        $this->assertTrue($updater->finishPending());

        // What the provider runs early in every web request.
        (new LicentraServiceProvider($this->app))->finishPendingUpdate();

        $this->assertSame('done', $updater->status()['step']);
    }

    public function test_starting_a_version_that_is_not_offered_is_refused(): void
    {
        $this->install();

        $this->actingAs($this->admin())->postJson('/license/update/start', ['version' => '9.9.9'])
            ->assertStatus(409)->assertJsonPath('step', 'failed');
    }

    public function test_update_routes_need_the_gate(): void
    {
        $this->install();
        Gate::define('manage-licentra', fn () => false);

        $this->actingAs($this->admin())->get('/license/update')->assertForbidden();
        $this->actingAs($this->admin())->postJson('/license/update/start', ['version' => '1.1.0'])->assertForbidden();
    }

    public function test_check_command_reports_the_update(): void
    {
        $this->install();
        $this->app->make(Licentra::class); // fakes are bound

        $this->artisan('licentra:update', ['--check' => true])
            ->expectsOutputToContain('Version 1.1.0 is available')
            ->assertSuccessful();
    }
}

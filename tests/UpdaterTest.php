<?php

namespace Pacific\Licentra\Tests;

use Pacific\Licentra\Config;
use Pacific\Licentra\Exceptions\ServerUnreachable;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Update\Manifest;
use Pacific\Licentra\Update\ReleaseSignature;
use Pacific\Licentra\Update\UpdateFailed;
use Pacific\Licentra\Update\Updater;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class UpdaterTest extends TestCase
{
    private string $tmp;
    private string $base;
    private Signer $signer;
    private MemoryStore $store;
    private FakeTransport $http;
    private FakeDownloader $downloader;
    private RecordingHooks $hooks;
    private array $releaseKey;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/licentra-upd-' . bin2hex(random_bytes(4));
        $this->base = $this->tmp . '/site';
        $this->signer = new Signer();
        $this->store = new MemoryStore();
        $this->http = new FakeTransport();
        $this->downloader = new FakeDownloader();
        $this->hooks = new RecordingHooks();
        $this->releaseKey = ReleaseSignature::generateKeyPair();

        // Installed 1.0.0: its manifest is what the previous release shipped.
        $this->files($this->base, [
            'app/Same.php' => 'same',
            'app/Changed.php' => 'old code',
            'app/Removed.php' => 'removed in 1.1',
            'app/Customised.php' => 'buyer edited this',
            'lang/en.json' => json_encode(['Hello' => 'Hi there (buyer)', 'Bye' => 'Bye']),
            '.env' => 'APP_KEY=secret',
            'storage/app/uploads/logo.png' => 'buyer upload',
        ]);
        (new Manifest([
            'app/Same.php' => hash('sha256', 'same'),
            'app/Changed.php' => hash('sha256', 'old code'),
            'app/Removed.php' => hash('sha256', 'removed in 1.1'),
            'app/Customised.php' => hash('sha256', 'original 1.0 code'),
            'lang/en.json' => 'whatever',
        ], '1.0.0'))->write($this->base . '/' . Manifest::FILE);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private function files(string $root, array $files): void
    {
        foreach ($files as $path => $contents) {
            @mkdir(dirname("{$root}/{$path}"), 0777, true);
            file_put_contents("{$root}/{$path}", $contents);
        }
    }

    /** Build release 1.1.0 as "slotara/..." inside a zip, like package.sh does. Returns [zip path, sha256]. */
    private function release(array $extraEntries = []): array
    {
        $dir = $this->tmp . '/build/quizora';
        $this->files($dir, [
            'app/Same.php' => 'same',
            'app/Changed.php' => 'new code',
            'app/Customised.php' => 'new 1.1 code',
            'app/New.php' => 'brand new',
            'lang/en.json' => json_encode(['Hello' => 'Hello', 'Bye' => 'Goodbye', 'Welcome' => 'Welcome']),
            '.env' => 'APP_KEY=from-the-zip-must-not-win',
            'storage/app/uploads/logo.png' => 'empty',
        ]);
        Manifest::build($dir, '1.1.0')->write($dir . '/' . Manifest::FILE);

        $zipPath = $this->tmp . '/quizora-1.1.0.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $zip->addFile($file->getPathname(), 'quizora/' . substr($file->getPathname(), strlen($dir) + 1));
        }
        foreach ($extraEntries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return [$zipPath, hash_file('sha256', $zipPath)];
    }

    private function updater(?string $releasePublicKey = 'default', ?string $signature = null, ?string $sha = null, array $extraEntries = []): Updater
    {
        [$zip, $realSha] = $this->release($extraEntries);
        $this->downloader->zip = $zip;
        $sha ??= $realSha;

        $this->store->data = [
            'instance_id' => 'iid-1',
            'token' => $this->signer->token(),
            'last_heartbeat_at' => time(),
            'update' => ['version' => '1.1.0', 'released_at' => null, 'changelog' => 'x', 'url' => null, 'download' => [
                'size' => filesize($zip),
                'sha256' => $sha,
                'signature' => $signature ?? ReleaseSignature::sign($this->releaseKey['secret'], 'quizora', '1.1.0', $realSha),
            ]],
        ];
        $this->http->queue[] = new ServerUnreachable('offline'); // the post-update heartbeat

        $licentra = new Licentra(new Config(
            product: 'quizora',
            publicKey: $this->signer->public,
            storagePath: '/unused',
            appUrl: 'https://shop-one.com',
            productVersion: '1.0.0',
        ), $this->store, $this->http, $this->downloader);

        return new Updater(
            $licentra,
            $this->hooks,
            $this->base,
            $this->tmp . '/work',
            $releasePublicKey === 'default' ? $this->releaseKey['public'] : $releasePublicKey,
            preserve: ['.env', 'storage/*'],
            mergeJson: ['lang/*.json'],
        );
    }

    private function read(string $path): ?string
    {
        return is_file("{$this->base}/{$path}") ? file_get_contents("{$this->base}/{$path}") : null;
    }

    public function test_full_update_applies_only_what_it_should(): void
    {
        $updater = $this->updater();
        $updater->start('1.1.0');
        $status = $updater->runToFinish();

        $this->assertSame('done', $status['step'], $status['error'] ?? '');
        $this->assertSame(['beforeApply', 'finish'], $this->hooks->calls);

        $this->assertSame('same', $this->read('app/Same.php'));
        $this->assertSame('new code', $this->read('app/Changed.php'));
        $this->assertSame('brand new', $this->read('app/New.php'));
        $this->assertNull($this->read('app/Removed.php'), 'unchanged file dropped by the release is deleted');
        $this->assertSame('new 1.1 code', $this->read('app/Customised.php'));
        $this->assertSame(['app/Customised.php'], $status['summary']['overwrote_modified']);

        $this->assertSame('APP_KEY=secret', $this->read('.env'), '.env is preserved');
        $this->assertSame('buyer upload', $this->read('storage/app/uploads/logo.png'), 'storage is preserved');

        // Buyer's translation wins, new strings arrive.
        $this->assertSame(['Hello' => 'Hi there (buyer)', 'Bye' => 'Bye', 'Welcome' => 'Welcome'], json_decode($this->read('lang/en.json'), true));

        $this->assertSame('1.1.0', Manifest::read($this->base . '/' . Manifest::FILE)->version);
        $this->assertFileExists($this->tmp . '/work/backup-1.0.0.zip');
        $this->assertFileDoesNotExist($this->tmp . '/work/update-1.1.0.zip');
        $this->assertSame('iid-1', $this->downloader->sent[0]['body']['instance_id']);
    }

    public function test_file_the_buyer_changed_that_the_release_drops_is_kept(): void
    {
        file_put_contents($this->base . '/app/Removed.php', 'buyer changed it');

        $updater = $this->updater();
        $updater->start('1.1.0');
        $status = $updater->runToFinish();

        $this->assertSame('buyer changed it', $this->read('app/Removed.php'));
        $this->assertSame(['app/Removed.php'], $status['summary']['kept']);
    }

    public function test_unsigned_release_is_refused_and_nothing_changes(): void
    {
        $other = ReleaseSignature::generateKeyPair();
        $updater = $this->updater(signature: ReleaseSignature::sign($other['secret'], 'quizora', '1.1.0', str_repeat('0', 64)));
        $updater->start('1.1.0');
        $status = $updater->runToFinish();

        $this->assertSame('failed', $status['step']);
        $this->assertSame('verify', $status['failed_step']);
        $this->assertStringContainsString('isn\'t signed by the author', $status['error']);
        $this->assertSame('old code', $this->read('app/Changed.php'));
        $this->assertSame([], $this->hooks->calls);
    }

    public function test_signature_for_another_product_is_refused(): void
    {
        [, $sha] = $this->release();
        $updater = $this->updater(signature: ReleaseSignature::sign($this->releaseKey['secret'], 'slotara', '1.1.0', $sha));
        $updater->start('1.1.0');

        $this->assertSame('verify', $updater->runToFinish()['failed_step']);
    }

    public function test_damaged_download_is_refused(): void
    {
        $updater = $this->updater(sha: str_repeat('a', 64));
        $updater->start('1.1.0');
        $status = $updater->runToFinish();

        $this->assertSame('verify', $status['failed_step']);
        $this->assertStringContainsString('checksum', $status['error']);
    }

    public function test_zip_with_path_traversal_is_refused(): void
    {
        $updater = $this->updater(extraEntries: ['../../evil.php' => '<?php evil();']);
        $updater->start('1.1.0');
        $status = $updater->runToFinish();

        $this->assertSame('extract', $status['failed_step']);
        $this->assertFileDoesNotExist($this->tmp . '/evil.php');
    }

    public function test_failed_database_step_restores_the_old_files(): void
    {
        $this->hooks->failFinish = true;
        $updater = $this->updater();
        $updater->start('1.1.0');
        $status = $updater->runToFinish();

        $this->assertSame('failed', $status['step']);
        $this->assertSame('finish', $status['failed_step']);
        $this->assertStringContainsString('migration exploded', $status['error']);
        $this->assertSame(['beforeApply', 'finish', 'afterRollback'], $this->hooks->calls);

        $this->assertSame('old code', $this->read('app/Changed.php'));
        $this->assertSame('removed in 1.1', $this->read('app/Removed.php'));
        $this->assertSame('buyer edited this', $this->read('app/Customised.php'));
        $this->assertNull($this->read('app/New.php'), 'files the update created are removed');
        $this->assertSame(['Hello' => 'Hi there (buyer)', 'Bye' => 'Bye'], json_decode($this->read('lang/en.json'), true));
        $this->assertSame('1.0.0', Manifest::read($this->base . '/' . Manifest::FILE)->version);
    }

    public function test_warnings_from_hooks_survive_to_the_end(): void
    {
        $this->hooks->warnOnApply = 'Database backup failed';
        $updater = $this->updater();
        $updater->start('1.1.0');
        $status = $updater->runToFinish();

        $this->assertSame('done', $status['step']);
        $this->assertSame(['Database backup failed'], $status['warnings']);
    }

    public function test_step_after_done_keeps_done(): void
    {
        $updater = $this->updater();
        $updater->start('1.1.0');
        $updater->runToFinish();

        $this->assertSame('done', $updater->step()['step']);
    }

    public function test_apply_leaves_finish_pending_for_the_next_request(): void
    {
        $updater = $this->updater();
        $updater->start('1.1.0');
        $status = $updater->runToFinish(includeFinish: false);

        $this->assertSame('finish', $status['step']);
        $this->assertTrue($updater->finishPending());
        $this->assertSame('new code', $this->read('app/Changed.php'));

        $updater->finishIfPending();
        $this->assertSame('done', $updater->status()['step']);
    }

    public function test_not_offered_without_a_release_key_or_when_not_newer(): void
    {
        $this->assertNull($this->updater(releasePublicKey: null)->available());

        $updater = $this->updater();
        $this->store->data['update']['version'] = '1.0.0';
        $this->assertNull($updater->available());
        $this->expectException(UpdateFailed::class);
        $updater->start('1.0.0');
    }

    public function test_cannot_cancel_while_installing(): void
    {
        $updater = $this->updater();
        $updater->start('1.1.0');
        $updater->runToFinish(includeFinish: false);

        $this->expectException(UpdateFailed::class);
        $updater->reset();
    }

    public function test_release_signature_round_trip(): void
    {
        $sig = ReleaseSignature::sign($this->releaseKey['secret'], 'quizora', '2.0.0', str_repeat('b', 64));

        $this->assertTrue(ReleaseSignature::verify($this->releaseKey['public'], $sig, 'quizora', '2.0.0', str_repeat('B', 64)));
        $this->assertFalse(ReleaseSignature::verify($this->releaseKey['public'], $sig, 'quizora', '2.0.1', str_repeat('b', 64)));
        $this->assertSame($this->releaseKey['public'], ReleaseSignature::publicKeyFor($this->releaseKey['secret']));
    }
}

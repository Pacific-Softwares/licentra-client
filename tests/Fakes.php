<?php

namespace Pacific\Licentra\Tests;

use Pacific\Licentra\Exceptions\ServerUnreachable;
use Pacific\Licentra\Http\Response;
use Pacific\Licentra\Http\Transport;
use Pacific\Licentra\Store\Store;

final class MemoryStore implements Store
{
    public array $data = [];

    public function read(): array
    {
        return $this->data;
    }

    public function write(array $data): void
    {
        $this->data = $data;
    }
}

final class FakeTransport implements Transport
{
    /** @var list<Response|ServerUnreachable> */
    public array $queue = [];

    /** @var list<array{method: string, url: string, body: ?array}> */
    public array $sent = [];

    public function send(string $method, string $url, ?array $body, int $timeout): Response
    {
        $this->sent[] = compact('method', 'url', 'body');
        $next = array_shift($this->queue) ?? throw new \LogicException('No fake response queued for ' . $url);
        if ($next instanceof ServerUnreachable) {
            throw $next;
        }

        return $next;
    }
}

/** Same format as licentra-server app/Licentra/TokenSigner.php. */
final class Signer
{
    public readonly string $secret;
    public readonly string $public;

    public function __construct()
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    public function token(array $overrides = []): string
    {
        $payload = $overrides + [
            'v' => 1, 'iid' => 'iid-1', 'product' => 'quizora', 'domain' => 'shop-one.com', 'dev' => false,
            'status' => 'valid', 'type' => 'Regular License', 'supported_until' => null,
            'iat' => time(), 'exp' => time() + 30 * 86400,
        ];
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $body = $b64(json_encode($payload));

        return $body . '.' . $b64(sodium_crypto_sign_detached($body, $this->secret));
    }
}

class ReadOnlyStore implements Store
{
    public array $data = [];

    public function read(): array
    {
        return $this->data;
    }

    public function write(array $data): void
    {
        throw new \Pacific\Licentra\Exceptions\LicentraException('read-only');
    }
}

/** Serves a prepared zip as if the license server had streamed it. */
final class FakeDownloader implements \Pacific\Licentra\Http\Downloader
{
    public ?string $zip = null;

    public int $status = 200;

    public ?array $error = null;

    /** @var list<array{url: string, body: array}> */
    public array $sent = [];

    public function download(string $url, array $body, string $destination, int $timeout): Response
    {
        $this->sent[] = compact('url', 'body');
        if ($this->status !== 200) {
            return new Response($this->status, $this->error);
        }
        copy($this->zip, $destination);

        return new Response(200, null);
    }
}

/** Records hook calls; can be told to fail in finish() to exercise rollback. */
final class RecordingHooks implements \Pacific\Licentra\Update\UpdateHooks
{
    public array $calls = [];

    public bool $failFinish = false;

    public ?string $warnOnApply = null;

    public function beforeApply(\Pacific\Licentra\Update\Updater $updater): void
    {
        $this->calls[] = 'beforeApply';
        if ($this->warnOnApply) {
            $updater->warn($this->warnOnApply);
        }
    }

    public function finish(\Pacific\Licentra\Update\Updater $updater): void
    {
        $this->calls[] = 'finish';
        if ($this->failFinish) {
            throw new \RuntimeException('migration exploded');
        }
    }

    public function afterRollback(\Pacific\Licentra\Update\Updater $updater): void
    {
        $this->calls[] = 'afterRollback';
    }
}

/** Records module hook calls; can be told to fail migrations. */
final class RecordingModuleHooks implements \Pacific\Licentra\Modules\ModuleHooks
{
    public array $calls = [];

    public ?string $failMigrate = null;

    public function migrate(string $slug, string $migrationsPath): void
    {
        $this->calls[] = ['migrate', $slug, $migrationsPath];
        if ($this->failMigrate) {
            throw new \RuntimeException($this->failMigrate);
        }
    }

    public function rollback(string $slug, string $migrationsPath): void
    {
        $this->calls[] = ['rollback', $slug, $migrationsPath];
    }

    public function changed(): void
    {
        $this->calls[] = ['changed'];
    }

    public function names(): array
    {
        return array_column($this->calls, 0);
    }
}

/**
 * Writes a module folder (module.json, provider, route, migration, public assets) and can zip
 * it like `licentra-release module` does. Every fixture gets its own PHP namespace, because
 * tests share one process and a class can only be declared once.
 */
final class ModuleFixture
{
    public readonly string $namespace;

    public function __construct(
        public readonly string $slug = 'slotara-hello',
        public readonly string $version = '1.0.0',
        public array $manifest = [],
        public string $bootModule = '$this->loadModuleRoutes();',
    ) {
        $this->namespace = 'Modules\\Fx' . bin2hex(random_bytes(5)) . '\\';
    }

    public function write(string $dir, array $extraFiles = []): string
    {
        $files = [
            'module.json' => json_encode($this->manifest + [
                'manifest' => 1, 'slug' => $this->slug, 'name' => 'Hello', 'version' => $this->version,
                'product' => explode('-', $this->slug)[0], 'requires' => ['product' => '^2.0'],
                'namespace' => $this->namespace, 'provider' => $this->namespace . 'ModuleServiceProvider',
            ]),
            'src/ModuleServiceProvider.php' => '<?php namespace ' . rtrim($this->namespace, '\\') . ';
                class ModuleServiceProvider extends \Pacific\Licentra\Modules\Laravel\ModuleServiceProvider {
                    public function bootModule(): void { ' . $this->bootModule . ' }
                }',
            'routes/web.php' => '<?php \Illuminate\Support\Facades\Route::get("/' . $this->slug . '", fn () => "hello from ' . $this->slug . '");',
            'database/migrations/2026_10_01_000000_create_hello.php' => '<?php return new class extends \Illuminate\Database\Migrations\Migration { public function up(): void {} };',
            'public/app.css' => 'body{}',
            'public/shell.php' => '<?php echo "pwned";',
        ] + $extraFiles;
        foreach ($files as $path => $content) {
            @mkdir(dirname($dir . '/' . $path), 0777, true);
            file_put_contents($dir . '/' . $path, $content);
        }

        return $dir;
    }

    /** @return array{path: string, sha256: string} */
    public function zip(string $tmp, array $extraFiles = []): array
    {
        $src = $this->write($tmp . '/src-' . bin2hex(random_bytes(3)), $extraFiles);
        $zipPath = $tmp . '/' . $this->slug . '-' . $this->version . '-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $zip->addFile($file->getPathname(), $this->slug . '/' . substr($file->getPathname(), strlen($src) + 1));
        }
        $zip->close();

        return ['path' => $zipPath, 'sha256' => hash_file('sha256', $zipPath)];
    }
}

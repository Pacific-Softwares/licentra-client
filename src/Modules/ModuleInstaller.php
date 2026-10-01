<?php

namespace Pacific\Licentra\Modules;

use Pacific\Licentra\Licentra;
use Pacific\Licentra\Update\Manifest;
use Pacific\Licentra\Update\ReleaseSignature;

/**
 * Installs and updates signed add-on modules into modules/{slug}, one step per call so no request
 * runs long on shared hosting (the Modules page polls step() like the Update page does).
 *
 *   start(slug, code) ─ activate add-on on the server (or reuse it for updates) ─▶ state.json
 *   startFromPackage(upload, code) ─ unpack a signed .licentra-module.zip, activate ─▶ (skips download)
 *     download ─▶ verify ─▶ extract ─▶ swap ─▶ migrate ─▶ finish ─▶ done
 *       zip       sha256 +   to work/   old folder   module      registry: enabled,
 *                 signature  new/, read aside, new   migrations  caches cleared
 *                 (slug +    module.json renamed     by path
 *                 version)   + checks    into place
 *     fail before swap: work files removed, site untouched
 *     fail in swap:     old folder renamed back
 *     fail in migrate:  new files kept, module "needs attention", Retry runs migrations again
 *
 * Every operation runs under one lock file shared with the product Updater, and neither starts
 * while the other has an unfinished operation, so a module swap can never land in the middle of
 * a product update.
 */
final class ModuleInstaller
{
    public const STEPS = ['download', 'verify', 'extract', 'swap', 'migrate', 'finish'];

    private const LABELS = [
        'download' => 'Downloading the module',
        'verify' => 'Checking the signature',
        'extract' => 'Unpacking and checking the module',
        'swap' => 'Installing the module files',
        'migrate' => 'Updating the database',
        'finish' => 'Switching the module on',
    ];

    /** Static files a module may publish to public/modules/{slug}. Never anything executable. */
    public const PUBLIC_EXTENSIONS = ['css', 'js', 'mjs', 'map', 'json', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf'];

    public function __construct(
        private readonly Licentra $licentra,
        private readonly Registry $registry,
        private readonly ModuleHooks $hooks,
        private readonly string $modulesPath,
        private readonly string $publicPath,
        private readonly string $workPath,
        private readonly string $lockPath,
        private readonly ?string $releasePublicKey,
        private readonly string $hostVersion,
        /** @var (\Closure(): bool)|null true while a product update is unfinished */
        private readonly ?\Closure $productUpdateRunning = null,
        /** @var (\Closure(string $action, string $slug, ?string $version): void)|null */
        private readonly ?\Closure $audit = null,
        /** modules-dev/ when developer mode is on (unsigned uploads allowed), otherwise null. */
        private readonly ?string $devPath = null,
    ) {
    }

    /**
     * Begin installing (or updating) a module. $purchaseCode: required for a paid add-on's first
     * install, null for free add-ons and for updates of an installed module.
     *
     * @throws ModuleException|\Pacific\Licentra\Exceptions\ActivationFailed|\Pacific\Licentra\Exceptions\ServerUnreachable
     */
    public function start(string $slug, ?string $purchaseCode = null): array
    {
        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $slug)) {
            throw ModuleException::installFailed("\"{$slug}\" is not a module name.");
        }
        if (!$this->releasePublicKey) {
            throw ModuleException::installFailed('This product has no release key configured, so it can\'t verify modules.');
        }

        return $this->locked(function () use ($slug, $purchaseCode) {
            if ($this->inProgress() || ($this->productUpdateRunning && ($this->productUpdateRunning)())) {
                throw ModuleException::busy();
            }

            $installed = $this->registry->get($slug);
            if (!empty($installed['dev'])) {
                throw ModuleException::installFailed("{$slug} is a developer-mode module; it isn't installed from the license server.");
            }
            $code = $purchaseCode !== null && trim($purchaseCode) !== '' ? trim($purchaseCode) : null;

            if ($code === null && !empty($installed['instance_id']) && !empty($installed['update']['download'])) {
                $activation = ['instance_id' => $installed['instance_id'], 'token' => $installed['token'] ?? null, 'update' => $installed['update']];
            } else {
                $activation = $this->licentra->activateAddon($slug, $code);
            }

            $update = $activation['update'] ?? null;
            if (empty($update['version']) || empty($update['download']['sha256'])) {
                throw ModuleException::installFailed("{$slug} has no published release to install yet.");
            }
            if ($installed && version_compare($update['version'], (string) $installed['version'], '<')) {
                throw ModuleException::downgrade($slug, (string) $installed['version'], $update['version']);
            }

            $this->cleanWorkDir();
            $this->writeState([
                'step' => 'download',
                'slug' => $slug,
                'version' => $update['version'],
                'from' => $installed['version'] ?? null,
                'sha256' => strtolower($update['download']['sha256']),
                'signature' => $update['download']['signature'] ?? null,
                'instance_id' => $activation['instance_id'],
                'token' => $activation['token'] ?? null,
                'license_status' => $activation['status'] ?? ($installed['license_status'] ?? 'valid'),
                'started_at' => time(),
            ]);
            $this->log('install_started', $slug, $update['version']);

            return $this->status();
        });
    }

    /**
     * Begin installing an uploaded offline package (ModulePackage). It goes through the same
     * signature check as a download, and the add-on is activated on the license server the
     * same way, so a paid add-on still needs its purchase code.
     *
     * @throws ModuleException|\Pacific\Licentra\Exceptions\ActivationFailed|\Pacific\Licentra\Exceptions\ServerUnreachable
     */
    public function startFromPackage(string $uploadedFile, ?string $purchaseCode = null): array
    {
        if (!$this->releasePublicKey) {
            throw ModuleException::installFailed('This product has no release key configured, so it can\'t verify modules.');
        }

        return $this->locked(function () use ($uploadedFile, $purchaseCode) {
            if ($this->inProgress() || ($this->productUpdateRunning && ($this->productUpdateRunning)())) {
                throw ModuleException::busy();
            }
            $this->cleanWorkDir();
            $meta = ModulePackage::open($uploadedFile, $this->workPath . '/module.zip');
            $installed = $this->registry->get($meta['slug']);
            if (!empty($installed['dev'])) {
                throw ModuleException::installFailed("{$meta['slug']} is a developer-mode module.");
            }
            if ($installed && version_compare($meta['version'], (string) $installed['version'], '<')) {
                @unlink($this->workPath . '/module.zip');
                throw ModuleException::downgrade($meta['slug'], (string) $installed['version'], $meta['version']);
            }

            $code = $purchaseCode !== null && trim($purchaseCode) !== '' ? trim($purchaseCode) : null;
            $activation = $code === null && !empty($installed['instance_id'])
                ? ['instance_id' => $installed['instance_id'], 'token' => $installed['token'] ?? null]
                : $this->licentra->activateAddon($meta['slug'], $code);

            $this->writeState([
                'step' => 'verify',
                'source' => 'upload',
                'slug' => $meta['slug'],
                'version' => $meta['version'],
                'from' => $installed['version'] ?? null,
                'sha256' => $meta['sha256'],
                'signature' => $meta['signature'],
                'instance_id' => $activation['instance_id'],
                'token' => $activation['token'] ?? null,
                'license_status' => $activation['status'] ?? ($installed['license_status'] ?? 'valid'),
                'started_at' => time(),
            ]);
            $this->log('upload_started', $meta['slug'], $meta['version']);

            return $this->status();
        });
    }

    /**
     * Developer mode only: put an unsigned module zip (your own custom module) into modules-dev/,
     * replacing an older copy, and run its migrations. It's never licensed or verified; the
     * Modules page marks it "Unsigned".
     *
     * @return string the module slug
     */
    public function installDev(string $zipFile): string
    {
        if ($this->devPath === null) {
            throw ModuleException::installFailed('Unsigned modules can only be uploaded in developer mode (LICENTRA_MODULES_DEV=true).');
        }

        return $this->locked(function () use ($zipFile) {
            if ($this->inProgress() || ($this->productUpdateRunning && ($this->productUpdateRunning)())) {
                throw ModuleException::busy();
            }
            $this->cleanWorkDir();
            $this->ensureDir($this->workPath);
            if (!@copy($zipFile, $this->workPath . '/module.zip')) {
                throw ModuleException::installFailed('Could not read the uploaded file.');
            }
            try {
                $this->extract(['slug' => null, 'version' => null]);
                $manifest = ModuleManifest::fromFile($this->packageRoot() . '/' . ModuleManifest::FILE);
                if ($this->registry->get($manifest->slug)) {
                    throw ModuleException::installFailed("{$manifest->slug} is already installed as a signed module.");
                }
                $target = rtrim($this->devPath, '/') . '/' . $manifest->slug;
                self::removeDir($target);
                self::move($this->packageRoot(), $target);
                $this->publishAssets($manifest->slug, $target);
                $this->runMigrations($manifest->slug, $target);
                $this->hooks->changed();
                $this->log('dev_uploaded', $manifest->slug, $manifest->version);

                return $manifest->slug;
            } finally {
                $this->cleanWorkDir();
            }
        });
    }

    /** Run the next step. On failure the status has step "failed" and an error. */
    public function step(): array
    {
        return $this->locked(function () {
            $state = $this->readState() ?? throw ModuleException::installFailed('No module install in progress.');
            if (in_array($state['step'], ['done', 'failed'], true)) {
                return $this->status();
            }

            try {
                $next = match ($state['step']) {
                    'download' => $this->download($state),
                    'verify' => $this->verify($state),
                    'extract' => $this->extract($state),
                    'swap' => $this->swap($state),
                    'migrate' => $this->migrate($state),
                    'finish' => $this->finish($state),
                    default => throw ModuleException::installFailed("Unknown step {$state['step']}."),
                };
            } catch (\Throwable $e) {
                // An install step is the boundary between this package and a downloaded module:
                // whatever went wrong, record it for the admin and leave the site as it was.
                $this->fail($state, $e);

                return $this->status();
            }

            $this->writeState(['step' => $next] + ($this->readState() ?? $state));

            return $this->status();
        });
    }

    /** Developer mode: switch a modules-dev/ module on or off (a ".disabled" file in its folder). */
    public function setDevEnabled(string $slug, bool $enabled): void
    {
        $this->locked(function () use ($slug, $enabled) {
            $dir = $this->devDir($slug);
            $enabled ? @unlink($dir . '/.disabled') : @touch($dir . '/.disabled');
            $this->hooks->changed();
            $this->log($enabled ? 'dev_enabled' : 'dev_disabled', $slug, null);
        });
    }

    /** Developer mode: delete a modules-dev/ module (and, with $deleteData, roll back its tables). */
    public function deleteDev(string $slug, bool $deleteData = false): void
    {
        $this->locked(function () use ($slug, $deleteData) {
            $dir = $this->devDir($slug);
            if ($deleteData && is_dir($dir . '/database/migrations')) {
                $this->hooks->rollback($slug, $dir . '/database/migrations');
            }
            self::removeDir($dir);
            self::removeDir($this->publicPath . '/' . $slug);
            $this->hooks->changed();
            $this->log($deleteData ? 'dev_deleted_with_data' : 'dev_deleted', $slug, null);
        });
    }

    private function devDir(string $slug): string
    {
        if ($this->devPath === null) {
            throw ModuleException::installFailed('Developer mode is off (LICENTRA_MODULES_DEV=true).');
        }
        $dir = rtrim($this->devPath, '/') . '/' . $slug;
        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $slug) || !is_file($dir . '/' . ModuleManifest::FILE)) {
            throw ModuleException::notInstalled($slug);
        }

        return $dir;
    }

    /** Developer mode is on (unsigned uploads go to modules-dev/). */
    public function devMode(): bool
    {
        return $this->devPath !== null;
    }

    /** Run every remaining step (CLI). */
    public function runToEnd(): array
    {
        do {
            $status = $this->step();
        } while (!in_array($status['step'], ['done', 'failed'], true));

        return $status;
    }

    public function status(): array
    {
        $state = $this->readState() ?? ['step' => null];
        unset($state['token']);
        if (isset(self::LABELS[$state['step'] ?? ''])) {
            $state['label'] = self::LABELS[$state['step']];
            $state['progress'] = (int) round(array_search($state['step'], self::STEPS, true) / count(self::STEPS) * 100);
        }

        return $state;
    }

    public function inProgress(): bool
    {
        $step = $this->readState()['step'] ?? null;

        return $step !== null && !in_array($step, ['done', 'failed'], true);
    }

    /** Forget a finished or failed install. Refuses while files are being swapped. */
    public function reset(): void
    {
        $this->locked(function () {
            if (in_array($this->readState()['step'] ?? null, ['swap', 'migrate', 'finish'], true)) {
                throw ModuleException::busy('The module is being installed and can\'t be cancelled now.');
            }
            $this->cleanWorkDir();
            @unlink($this->workPath . '/state.json');
        });
    }

    // ── Installed modules ───────────────────────────────────────────────────

    /** Run a module's migrations again after a failure ("needs attention" → Retry). */
    public function retryMigrations(string $slug): void
    {
        $this->locked(function () use ($slug) {
            $module = $this->registry->get($slug) ?? throw ModuleException::notInstalled($slug);
            $this->runMigrations($slug, (string) $module['path']);
            $this->registry->setStatus($slug, Registry::ENABLED);
            $this->hooks->changed();
            $this->log('migrations_retried', $slug, $module['version']);
        });
    }

    public function enable(string $slug, ?string $licenseProblem = null): void
    {
        $this->locked(function () use ($slug, $licenseProblem) {
            $module = $this->registry->get($slug) ?? throw ModuleException::notInstalled($slug);
            ModuleManifest::fromRegistry($module)->assertCompatible($this->licentra->config()->product, $this->hostVersion);
            if ($licenseProblem !== null) {
                throw ModuleException::incompatible($licenseProblem);
            }
            $this->registry->setStatus($slug, Registry::ENABLED);
            $this->hooks->changed();
            $this->log('enabled', $slug, $module['version']);
        });
    }

    public function disable(string $slug, string $reason = 'Switched off by an administrator.'): void
    {
        $this->locked(function () use ($slug, $reason) {
            $module = $this->registry->get($slug) ?? throw ModuleException::notInstalled($slug);
            $this->registry->setStatus($slug, Registry::DISABLED, $reason);
            $this->hooks->changed();
            $this->log('disabled', $slug, $module['version']);
        });
    }

    /**
     * Remove a module's files. Its tables and migration records stay unless $deleteData, so
     * installing it again later picks up where it left off.
     */
    public function uninstall(string $slug, bool $deleteData = false): void
    {
        $this->locked(function () use ($slug, $deleteData) {
            $module = $this->registry->get($slug) ?? throw ModuleException::notInstalled($slug);
            if (!empty($module['dev'])) {
                throw ModuleException::installFailed('Developer-mode modules are removed by deleting their folder in modules-dev/.');
            }
            if (($this->inProgress() && ($this->readState()['slug'] ?? null) === $slug)
                || ($this->productUpdateRunning && ($this->productUpdateRunning)())) {
                throw ModuleException::busy();
            }
            if ($deleteData && is_dir($module['path'] . '/database/migrations')) {
                $this->hooks->rollback($slug, $module['path'] . '/database/migrations');
            }
            $this->registry->remove($slug);
            self::removeDir($this->modulesPath . '/' . $slug);
            self::removeDir($this->publicPath . '/' . $slug);
            $this->hooks->changed();
            $this->log($deleteData ? 'uninstalled_with_data' : 'uninstalled', $slug, $module['version']);
        });
    }

    // ── Steps ───────────────────────────────────────────────────────────────

    private function download(array $state): string
    {
        $this->licentra->downloadModule($state['instance_id'], $state['version'], $this->workPath . '/module.zip');

        return 'verify';
    }

    private function verify(array $state): string
    {
        $zip = $this->workPath . '/module.zip';
        if (!is_file($zip) || !hash_equals($state['sha256'], hash_file('sha256', $zip))) {
            throw ModuleException::installFailed('The downloaded file is damaged (checksum mismatch). Try again.');
        }
        // The signature covers slug + version + sha256, so a validly signed zip of another module
        // or another version is refused too.
        if (!ReleaseSignature::verify($this->releasePublicKey, $state['signature'], $state['slug'], $state['version'], $state['sha256'])) {
            throw ModuleException::installFailed('The module failed its signature check, so it was not installed. Contact support.');
        }

        return 'extract';
    }

    private function extract(array $state): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw ModuleException::installFailed('PHP\'s zip extension is required to install modules. Ask your host to enable it.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($this->workPath . '/module.zip') !== true) {
            throw ModuleException::installFailed('Could not open the module file.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!Manifest::isSafePath(rtrim($name, '/'))) {
                $zip->close();
                throw ModuleException::installFailed("The module file contains an unsafe path: {$name}");
            }
        }
        self::removeDir($this->workPath . '/new');
        if (!$zip->extractTo($this->workPath . '/new')) {
            $zip->close();
            throw ModuleException::installFailed('Could not unpack the module. Check free disk space.');
        }
        $zip->close();

        $root = $this->packageRoot();
        $manifest = ModuleManifest::fromFile($root . '/' . ModuleManifest::FILE);
        if ($state['slug'] !== null && ($manifest->slug !== $state['slug'] || $manifest->version !== $state['version'])) {
            throw ModuleException::manifestInvalid("expected {$state['slug']} {$state['version']}, the package is {$manifest->slug} {$manifest->version}.");
        }
        $manifest->assertCompatible($this->licentra->config()->product, $this->hostVersion);
        if (is_dir($root . '/vendor')) {
            throw ModuleException::manifestInvalid('modules can\'t ship a vendor/ folder; they use the product\'s packages.');
        }

        return 'swap';
    }

    private function swap(array $state): string
    {
        $root = $this->packageRoot();
        $target = $this->modulesPath . '/' . $state['slug'];
        $old = $this->workPath . '/old';

        self::removeDir($old);
        if (is_dir($target)) {
            self::move($target, $old);
        }
        try {
            self::move($root, $target);
            $this->publishAssets($state['slug'], $target);
        } catch (\Throwable $e) {
            self::removeDir($target);
            if (is_dir($old)) {
                self::move($old, $target);
                $this->publishAssets($state['slug'], $target);
            }
            throw ModuleException::installFailed('Could not write the module files: ' . $e->getMessage(), $e);
        }

        // The registry learns about the module now (not yet enabled), so a failed migration
        // leaves a visible "needs attention" module instead of orphan files.
        $manifest = ModuleManifest::fromFile($target . '/' . ModuleManifest::FILE);
        $previous = $this->registry->get($state['slug']) ?? [];
        $this->registry->put($state['slug'], $manifest->toArray() + [
            'status' => Registry::NEEDS_ATTENTION,
            'reason' => 'Install not finished.',
            'path' => $target,
            'dev' => false,
            'instance_id' => $state['instance_id'],
            'token' => $state['token'] ?? ($previous['token'] ?? null),
            'license_status' => $state['license_status'] ?? 'valid',
            'license_problem_since' => null,
            'update' => null,
            'installed_at' => $previous['installed_at'] ?? time(),
            'updated_at' => time(),
        ]);

        return 'migrate';
    }

    private function migrate(array $state): string
    {
        $path = $this->modulesPath . '/' . $state['slug'];
        try {
            $this->runMigrations($state['slug'], $path);
        } catch (\Throwable $e) {
            $this->registry->setStatus($state['slug'], Registry::NEEDS_ATTENTION, 'Database update failed: ' . $e->getMessage());
            self::removeDir($this->workPath . '/old');
            $this->hooks->changed();
            throw ModuleException::installFailed('The module files are installed but its database update failed: ' . $e->getMessage() . ' Fix the cause, then use Retry.', $e);
        }

        return 'finish';
    }

    private function finish(array $state): string
    {
        $this->registry->setStatus($state['slug'], Registry::ENABLED);
        self::removeDir($this->workPath . '/old');
        self::removeDir($this->workPath . '/new');
        @unlink($this->workPath . '/module.zip');
        $this->hooks->changed();
        $this->log($state['from'] ? 'updated' : 'installed', $state['slug'], $state['version']);

        return 'done';
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function runMigrations(string $slug, string $path): void
    {
        if (is_dir($path . '/database/migrations')) {
            $this->hooks->migrate($slug, $path . '/database/migrations');
        }
    }

    /** Copy a module's public/ files (allowlisted types only) to public/modules/{slug}. */
    public function publishAssets(string $slug, string $moduleDir): void
    {
        $dest = $this->publicPath . '/' . $slug;
        self::removeDir($dest);
        $src = $moduleDir . '/public';
        if (!is_dir($src)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!$file->isFile() || !in_array($ext, self::PUBLIC_EXTENSIONS, true)) {
                continue; // .php, .phtml, .htaccess, ... are never published
            }
            $to = $dest . '/' . substr($file->getPathname(), strlen($src) + 1);
            if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0775, true) && !is_dir(dirname($to))) {
                throw ModuleException::installFailed("Cannot create {$dest}. Check that public/ is writable.");
            }
            if (!@copy($file->getPathname(), $to)) {
                throw ModuleException::installFailed("Cannot copy module assets to {$dest}.");
            }
        }
    }

    private function packageRoot(): string
    {
        $dir = $this->workPath . '/new';
        if (is_file($dir . '/' . ModuleManifest::FILE)) {
            return $dir;
        }
        // Zips built from a folder have one top-level directory.
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..', '__MACOSX']));
        if (count($entries) === 1 && is_dir($dir . '/' . $entries[0])) {
            return $dir . '/' . $entries[0];
        }

        return $dir;
    }

    private function fail(array $state, \Throwable $e): void
    {
        if (in_array($state['step'], ['download', 'verify', 'extract'], true)) {
            $this->cleanWorkDir();
        }
        $this->writeState(['step' => 'failed', 'failed_step' => $state['step'], 'error' => $e->getMessage()] + $state);
        $this->log('install_failed', $state['slug'], $state['version']);
    }

    private function log(string $action, string $slug, ?string $version): void
    {
        if ($this->audit) {
            ($this->audit)($action, $slug, $version);
        }
    }

    private function locked(callable $fn): mixed
    {
        $this->ensureDir(dirname($this->lockPath));
        $lock = @fopen($this->lockPath, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            throw ModuleException::busy();
        }
        try {
            @set_time_limit(0);

            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function cleanWorkDir(): void
    {
        self::removeDir($this->workPath . '/new');
        @unlink($this->workPath . '/module.zip');
    }

    private function readState(): ?array
    {
        $state = json_decode((string) @file_get_contents($this->workPath . '/state.json'), true);

        return is_array($state) ? $state : null;
    }

    private function writeState(array $state): void
    {
        $this->ensureDir($this->workPath);
        $tmp = $this->workPath . '/state.json.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
            || !@rename($tmp, $this->workPath . '/state.json')) {
            @unlink($tmp);
            throw ModuleException::installFailed("Cannot write to {$this->workPath}. Check folder permissions.");
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw ModuleException::installFailed("Cannot create {$dir}. Check folder permissions.");
        }
    }

    /** rename(), falling back to copy + delete across filesystems. */
    private static function move(string $from, string $to): void
    {
        if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0775, true) && !is_dir(dirname($to))) {
            throw ModuleException::installFailed('Cannot create ' . dirname($to) . '. Check folder permissions.');
        }
        if (@rename($from, $to)) {
            return;
        }
        self::copyDir($from, $to);
        self::removeDir($from);
    }

    private static function copyDir(string $from, string $to): void
    {
        @mkdir($to, 0775, true);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $dest = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                @mkdir($dest, 0775, true);
            } elseif (!@copy($item->getPathname(), $dest)) {
                throw ModuleException::installFailed("Cannot copy {$item->getPathname()}.");
            }
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

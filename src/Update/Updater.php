<?php

namespace Pacific\Licentra\Update;

use Pacific\Licentra\Exceptions\LicentraException;
use Pacific\Licentra\Licentra;

/**
 * One-click update of a licensed install, one step per call so no single request runs long:
 *
 *   start(v) ─▶ download ─▶ verify ─▶ extract ─▶ prepare ─▶ apply ─┬─▶ (next request) finish ─▶ done
 *                 zip       sha256 +   to work    plan +     hooks.  │     hooks.finish: migrate,
 *                           signature  dir        backup     before- │     clear caches, back up
 *                                                            Apply,  │
 *                                                            swap ───┴─ any error: restore files
 *                                                            files         from backup, afterRollback
 *
 * State lives in {workPath}/state.json, so a closed browser tab can resume, and the Laravel
 * adapter finishes an applied update on the next request even if nobody clicks anything.
 */
final class Updater
{
    public const STEPS = ['download', 'verify', 'extract', 'prepare', 'apply', 'finish'];

    private const LABELS = [
        'download' => 'Downloading the update',
        'verify' => 'Checking the signature',
        'extract' => 'Unpacking',
        'prepare' => 'Backing up files that will change',
        'apply' => 'Installing new files',
        'finish' => 'Updating the database and clearing caches',
    ];

    public function __construct(
        private readonly Licentra $licentra,
        private readonly UpdateHooks $hooks,
        private readonly string $basePath,
        private readonly string $workPath,
        private readonly ?string $releasePublicKey,
        /** @var list<string> paths never written, e.g. ".env", "storage/*" */
        private readonly array $preserve = [],
        /** @var list<string> JSON files merged instead of replaced; existing values win, e.g. "lang/*.json" */
        private readonly array $mergeJson = [],
    ) {
    }

    /** The update this install can apply now, or null (none, not newer, not downloadable, or no release key). */
    public function available(): ?array
    {
        $state = $this->licentra->state();
        $update = $state->update;

        if (!$this->releasePublicKey || !$state->isUsable() || !$state->updateAvailable() || empty($update['download']['sha256'])) {
            return null;
        }

        return $update;
    }

    public function status(): array
    {
        $state = $this->readState();
        if ($state && isset($state['step'])) {
            $state['label'] = self::LABELS[$state['step']] ?? null;
            $state['progress'] = $this->progress($state['step']);
        }

        return $state ?? ['step' => null];
    }

    public function inProgress(): bool
    {
        $step = $this->readState()['step'] ?? null;

        return $step !== null && !in_array($step, ['done', 'failed'], true);
    }

    /** True once files are swapped but finish() hasn't run: the site must not stay like this. */
    public function finishPending(): bool
    {
        return ($this->readState()['step'] ?? null) === 'finish';
    }

    public function start(string $version): array
    {
        if ($this->inProgress()) {
            throw new UpdateFailed('An update is already in progress.');
        }
        $update = $this->available();
        if ($update === null || $update['version'] !== $version) {
            throw new UpdateFailed("Version {$version} isn't available for this installation. Refresh and try again.");
        }

        $this->reset();
        $this->writeState([
            'step' => 'download',
            'version' => $version,
            'from' => $this->licentra->config()->productVersion,
            'sha256' => strtolower($update['download']['sha256']),
            'signature' => $update['download']['signature'] ?? null,
            'started_at' => time(),
        ]);

        return $this->status();
    }

    /** Run the next step. Returns the new status; on failure the status has step "failed" and an error. */
    public function step(): array
    {
        return $this->locked(function () {
            $state = $this->readState() ?? throw new UpdateFailed('No update in progress.');
            $step = $state['step'];
            if (in_array($step, ['done', 'failed'], true)) {
                return $this->status(); // nothing left to do; never turn "done" into "failed"
            }

            try {
                $next = match ($step) {
                    'download' => $this->download($state),
                    'verify' => $this->verify($state),
                    'extract' => $this->extract($state),
                    'prepare' => $this->prepare($state),
                    'apply' => $this->apply($state),
                    'finish' => $this->finish($state),
                    default => throw new UpdateFailed("Nothing to do (update is {$step})."),
                };
            } catch (\Throwable $e) {
                $this->fail($state, $e);

                return $this->status();
            }

            $this->writeState(['step' => $next] + ($this->readState() ?? $state));

            return $this->status();
        });
    }

    /** Run every remaining step in this process (CLI). finish() is left to a fresh process when possible. */
    public function runToFinish(bool $includeFinish = true): array
    {
        do {
            $status = $this->step();
        } while (!in_array($status['step'], ['finish', 'done', 'failed'], true));

        if ($includeFinish && $status['step'] === 'finish') {
            $status = $this->step();
        }

        return $status;
    }

    /** Finish an applied update if one is waiting (called early in a new request, with new code loaded). */
    public function finishIfPending(): void
    {
        if ($this->finishPending()) {
            $this->step();
        }
    }

    /** Forget a failed or finished update and remove its downloaded files. Refuses mid-swap. */
    public function reset(): void
    {
        $step = $this->readState()['step'] ?? null;
        if (in_array($step, ['apply', 'finish'], true)) {
            throw new UpdateFailed('The update is being installed and can\'t be cancelled now.');
        }
        $this->removeDir($this->workPath . '/new');
        foreach (glob($this->workPath . '/*.zip') ?: [] as $zip) {
            if (!str_starts_with(basename($zip), 'backup-')) {
                @unlink($zip);
            }
        }
        @unlink($this->workPath . '/plan.json');
        @unlink($this->workPath . '/state.json');
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    // ── Steps ────────────────────────────────────────────────────────────────

    private function download(array $state): string
    {
        $this->ensureWorkDir();
        $this->licentra->downloadRelease($state['version'], $this->zipPath($state));

        return 'verify';
    }

    private function verify(array $state): string
    {
        $zip = $this->zipPath($state);
        $actual = hash_file('sha256', $zip);
        if (!hash_equals($state['sha256'], $actual)) {
            @unlink($zip);
            throw new UpdateFailed('The downloaded file is damaged (checksum mismatch). Try again.');
        }

        $product = $this->licentra->config()->product;
        if (!ReleaseSignature::verify($this->releasePublicKey, $state['signature'], $product, $state['version'], $actual)) {
            @unlink($zip);
            throw new UpdateFailed('The update isn\'t signed by the author, so it was not installed. Contact support.');
        }

        return 'extract';
    }

    private function extract(array $state): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new UpdateFailed('PHP\'s zip extension is required to install updates. Ask your host to enable it.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($this->zipPath($state)) !== true) {
            throw new UpdateFailed('Could not open the update file.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!Manifest::isSafePath(rtrim($name, '/'))) {
                $zip->close();
                throw new UpdateFailed("The update file contains an unsafe path: {$name}");
            }
        }

        $dir = $this->workPath . '/new';
        $this->removeDir($dir);
        if (!$zip->extractTo($dir)) {
            $zip->close();
            throw new UpdateFailed('Could not unpack the update. Check free disk space.');
        }
        $zip->close();

        if (!Manifest::read($this->releaseRoot() . '/' . Manifest::FILE)) {
            throw new UpdateFailed('The update file has no manifest.');
        }

        return 'prepare';
    }

    /** Decide what changes, check it can be written, and back up every file that will be touched. */
    private function prepare(array $state): string
    {
        $root = $this->releaseRoot();
        $new = Manifest::read($root . '/' . Manifest::FILE);
        $old = Manifest::read($this->basePath . '/' . Manifest::FILE);

        $plan = ['write' => [], 'merge' => [], 'delete' => [], 'created' => [], 'kept' => [], 'overwrote_modified' => []];
        $bytes = 0;

        foreach ($new->files as $path => $sha) {
            if ($this->matches($path, $this->preserve)) {
                continue;
            }
            $target = $this->basePath . '/' . $path;
            $exists = is_file($target);

            if ($exists && $this->matches($path, $this->mergeJson)) {
                $plan['merge'][] = $path;
            } elseif (!$exists) {
                $plan['write'][] = $path;
                $plan['created'][] = $path;
                $bytes += (int) @filesize($root . '/' . $path);
            } elseif (hash_file('sha256', $target) !== $sha) {
                $plan['write'][] = $path;
                $bytes += (int) @filesize($root . '/' . $path);
                if ($old && isset($old->files[$path]) && hash_file('sha256', $target) !== $old->files[$path]) {
                    $plan['overwrote_modified'][] = $path; // buyer edited a core file; it's in the backup
                }
            }
        }

        // Files the previous release shipped and this one doesn't. Only delete them if unchanged.
        foreach ($old?->files ?? [] as $path => $sha) {
            if (isset($new->files[$path]) || $this->matches($path, $this->preserve) || !is_file($this->basePath . '/' . $path)) {
                continue;
            }
            if (hash_file('sha256', $this->basePath . '/' . $path) === $sha) {
                $plan['delete'][] = $path;
            } else {
                $plan['kept'][] = $path;
            }
        }

        $unwritable = array_values(array_filter(
            [...$plan['write'], ...$plan['merge'], ...$plan['delete']],
            fn (string $path) => !$this->writable($this->basePath . '/' . $path),
        ));
        if ($unwritable) {
            throw new UpdateFailed('These files can\'t be written by PHP, so nothing was changed: '
                . implode(', ', array_slice($unwritable, 0, 5)) . (count($unwritable) > 5 ? ' and ' . (count($unwritable) - 5) . ' more' : '')
                . '. Fix the file permissions and try again.');
        }

        $free = @disk_free_space($this->basePath);
        if ($free !== false && $free < $bytes * 2 + 50 * 1024 * 1024) {
            throw new UpdateFailed('Not enough free disk space to install the update safely.');
        }

        $this->backup($state, [...$plan['write'], ...$plan['merge'], ...$plan['delete']], $plan['created']);
        file_put_contents($this->workPath . '/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->writeState(['summary' => [
            'files' => count($plan['write']) + count($plan['merge']),
            'deleted' => count($plan['delete']),
            'overwrote_modified' => array_slice($plan['overwrote_modified'], 0, 20),
            'kept' => array_slice($plan['kept'], 0, 20),
        ]] + $state);

        return 'apply';
    }

    private function apply(array $state): string
    {
        $plan = $this->plan();
        $root = $this->releaseRoot();

        try {
            $this->hooks->beforeApply($this);

            foreach ($plan['write'] as $path) {
                $this->copyAtomic($root . '/' . $path, $this->basePath . '/' . $path);
            }
            foreach ($plan['merge'] as $path) {
                $this->mergeJsonFile($root . '/' . $path, $this->basePath . '/' . $path);
            }
            foreach ($plan['delete'] as $path) {
                if (!@unlink($this->basePath . '/' . $path)) {
                    throw new UpdateFailed("Could not delete {$path}.");
                }
            }
            Manifest::read($root . '/' . Manifest::FILE)->write($this->basePath . '/' . Manifest::FILE);
        } catch (\Throwable $e) {
            $this->restoreBackup($state);
            $this->hooks->afterRollback($this);
            throw new UpdateFailed('Installing files failed, so the previous files were restored: ' . $e->getMessage(), 0, $e);
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset(); // servers with opcache.validate_timestamps=0 would keep running old code
        }

        return 'finish';
    }

    private function finish(array $state): string
    {
        try {
            $this->hooks->finish($this);
        } catch (\Throwable $e) {
            // Files are new but e.g. a migration failed. Put the old files back so the site runs.
            $this->restoreBackup($state);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $this->hooks->afterRollback($this);
            throw new UpdateFailed('The update\'s database step failed, so the previous files were restored: ' . $e->getMessage()
                . ' If the database was partly changed, restore your database backup.', 0, $e);
        }

        $this->removeDir($this->workPath . '/new');
        @unlink($this->zipPath($state));
        @unlink($this->workPath . '/plan.json');
        $this->licentra->heartbeat(force: true); // report the new version right away

        return 'done';
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function backup(array $state, array $paths, array $created): void
    {
        $file = $this->workPath . '/backup-' . $state['from'] . '.zip';
        @unlink($file);

        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new UpdateFailed('Could not create the backup file. Check free disk space and permissions.');
        }
        foreach ($paths as $path) {
            if (!in_array($path, $created, true) && is_file($this->basePath . '/' . $path)) {
                $zip->addFile($this->basePath . '/' . $path, $path);
                $zip->setCompressionName($path, \ZipArchive::CM_STORE);
            }
        }
        if (is_file($this->basePath . '/' . Manifest::FILE)) {
            $zip->addFile($this->basePath . '/' . Manifest::FILE, Manifest::FILE);
        }
        $zip->addFromString('.licentra-backup.json', json_encode(['created' => $created, 'from' => $state['from'], 'to' => $state['version']]));
        if (!$zip->close()) {
            throw new UpdateFailed('Could not write the backup file. Check free disk space.');
        }
    }

    /** Put every backed-up file back and remove files the update created. */
    private function restoreBackup(array $state): void
    {
        $file = $this->workPath . '/backup-' . $state['from'] . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            throw new UpdateFailed("Rollback failed: backup {$file} is missing. Restore the site from your own backup.");
        }
        $meta = json_decode((string) $zip->getFromName('.licentra-backup.json'), true) ?: [];
        $hadManifest = $zip->locateName(Manifest::FILE) !== false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '.licentra-backup.json' || !Manifest::isSafePath($name)) {
                continue;
            }
            $target = $this->basePath . '/' . $name;
            @mkdir(dirname($target), 0775, true);
            file_put_contents($target, $zip->getFromIndex($i));
        }
        $zip->close();

        foreach ($meta['created'] ?? [] as $path) {
            if (Manifest::isSafePath($path)) {
                @unlink($this->basePath . '/' . $path);
            }
        }
        if (!$hadManifest) {
            @unlink($this->basePath . '/' . Manifest::FILE); // the install had none before this update
        }
    }

    private function copyAtomic(string $from, string $to): void
    {
        $dir = dirname($to);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UpdateFailed("Could not create folder {$dir}.");
        }
        $tmp = $to . '.licentra-tmp';
        if (!@copy($from, $tmp) || !@rename($tmp, $to)) {
            @unlink($tmp);
            throw new UpdateFailed("Could not write {$to}.");
        }
    }

    /** New keys from the release are added; values the site already has (e.g. edited translations) win. */
    private function mergeJsonFile(string $from, string $to): void
    {
        $new = json_decode((string) file_get_contents($from), true);
        $current = json_decode((string) file_get_contents($to), true);
        if (!is_array($new) || !is_array($current)) {
            if (!is_array($current)) {
                $this->copyAtomic($from, $to); // site's copy is broken: take the release's

                return;
            }

            return; // release copy is broken: keep the site's
        }

        $merged = $new;
        foreach ($current as $key => $value) {
            $merged[$key] = $value;
        }

        $tmp = $to . '.licentra-tmp';
        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($tmp, $json . "\n") === false || !@rename($tmp, $to)) {
            @unlink($tmp);
            throw new UpdateFailed("Could not write {$to}.");
        }
    }

    private function fail(array $state, \Throwable $e): void
    {
        $message = $e instanceof LicentraException ? $e->getMessage() : 'Unexpected error: ' . $e->getMessage();
        $this->writeState(['step' => 'failed', 'failed_step' => $state['step'], 'error' => $message] + $state);
    }

    /** Patterns: "storage/*" (anything under it), ".env", "lang/*.json". */
    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $underDir = str_ends_with($pattern, '/*') && str_starts_with($path, substr($pattern, 0, -1));
            if ($path === $pattern || $underDir || fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function writable(string $target): bool
    {
        if (file_exists($target)) {
            return is_writable($target);
        }
        $dir = dirname($target);
        while (!is_dir($dir) && $dir !== dirname($dir)) {
            $dir = dirname($dir);
        }

        return is_writable($dir);
    }

    private function releaseRoot(): string
    {
        $dir = $this->workPath . '/new';
        if (is_file($dir . '/' . Manifest::FILE)) {
            return $dir;
        }
        // Zips built from a folder ("slotara/...") have one top-level directory.
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..', '__MACOSX']));
        if (count($entries) === 1 && is_dir($dir . '/' . $entries[0])) {
            return $dir . '/' . $entries[0];
        }

        return $dir;
    }

    private function plan(): array
    {
        $plan = json_decode((string) @file_get_contents($this->workPath . '/plan.json'), true);

        return is_array($plan) ? $plan : throw new UpdateFailed('The update plan is missing. Start the update again.');
    }

    private function zipPath(array $state): string
    {
        return $this->workPath . '/update-' . $state['version'] . '.zip';
    }

    private function progress(string $step): int
    {
        $i = array_search($step, self::STEPS, true);

        return match (true) {
            $step === 'done' => 100,
            $i === false => 0,
            default => (int) round($i / count(self::STEPS) * 100),
        };
    }

    private function locked(callable $fn): mixed
    {
        $this->ensureWorkDir();
        $lock = fopen($this->workPath . '/update.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new UpdateFailed('Another update step is running. Wait a moment.');
        }
        try {
            @set_time_limit(0);

            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readState(): ?array
    {
        $data = json_decode((string) @file_get_contents($this->workPath . '/state.json'), true);

        return is_array($data) ? $data : null;
    }

    private function writeState(array $state): void
    {
        $this->ensureWorkDir();
        $tmp = $this->workPath . '/state.json.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->workPath . '/state.json');
    }

    private function ensureWorkDir(): void
    {
        if (!is_dir($this->workPath) && !@mkdir($this->workPath, 0775, true) && !is_dir($this->workPath)) {
            throw new UpdateFailed("Cannot create {$this->workPath}. Check that storage/ is writable.");
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}

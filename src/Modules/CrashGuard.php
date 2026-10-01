<?php

namespace Pacific\Licentra\Modules;

/**
 * Catches what try/catch can't: PHP fatals (parse errors, "cannot redeclare class", out of
 * memory) inside a module, at boot or later in a route, view, job or listener. A shutdown
 * handler reads error_get_last() and, when the failing file is inside a module's folder,
 * counts a crash for that module. LIMIT crashes within WINDOW seconds trip the module, and
 * the onTrip callback switches it off, so a broken module costs a few failed requests and an
 * admin notice instead of a white screen nobody can fix without FTP.
 *
 * Crash counts live in one small file per module ({dir}/{slug}.json), updated under flock, so
 * concurrent PHP-FPM workers never race (no per-request "booting" marker to misread).
 */
final class CrashGuard
{
    public const LIMIT = 3;

    public const WINDOW = 600;

    private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    /** @var array<string, string> slug => real module folder */
    private array $paths = [];

    /** @var (\Closure(string $slug, string $message): void)|null */
    private ?\Closure $onTrip = null;

    private bool $registered = false;

    public function __construct(private readonly string $dir)
    {
    }

    /** @param array<string, string> $paths slug => module folder */
    public function watch(array $paths, \Closure $onTrip): void
    {
        $this->paths = [];
        foreach ($paths as $slug => $path) {
            $this->paths[$slug] = rtrim(realpath($path) ?: $path, '/') . '/';
        }
        $this->onTrip = $onTrip;
        if (!$this->registered) {
            $this->registered = true;
            register_shutdown_function([$this, 'onShutdown']);
        }
    }

    /** @internal shutdown handler */
    public function onShutdown(): void
    {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], self::FATAL, true)) {
            return;
        }
        $slug = $this->attribute((string) $error['file']);
        if ($slug !== null) {
            $this->record($slug, "{$error['message']} in {$error['file']}:{$error['line']}");
        }
    }

    /** Which watched module a file belongs to, if any. */
    public function attribute(string $file): ?string
    {
        // Resolve symlinks the same way the watched folders were (e.g. /var → /private/var);
        // fall back to the folder when the file itself is gone (eval'd or deleted code).
        $file = realpath($file) ?: ((realpath(dirname($file)) ?: dirname($file)) . '/' . basename($file));
        foreach ($this->paths as $slug => $path) {
            if (str_starts_with($file, $path)) {
                return $slug;
            }
        }

        return null;
    }

    /** Count one crash. Returns true when this crash tripped the module. */
    public function record(string $slug, string $message, ?int $now = null): bool
    {
        $now ??= time();
        $tripped = false;
        $this->withFile($slug, function (array $data) use ($now, &$tripped) {
            $times = array_values(array_filter($data['crashes'] ?? [], fn ($t) => $t > $now - self::WINDOW));
            $times[] = $now;
            if (count($times) >= self::LIMIT) {
                $tripped = true;

                return ['crashes' => [], 'tripped' => true, 'tripped_at' => $now];
            }

            return ['crashes' => $times] + $data;
        });

        error_log("licentra: module {$slug} crashed: {$message}");
        if ($tripped && $this->onTrip) {
            ($this->onTrip)($slug, $message);
        }

        return $tripped;
    }

    /** Mark a module tripped now (it threw while starting; no need to wait for more crashes). */
    public function trip(string $slug, ?int $now = null): void
    {
        $this->withFile($slug, fn (array $data) => ['crashes' => [], 'tripped' => true, 'tripped_at' => $now ?? time()]);
    }

    /** Developer-mode modules aren't in the registry; the crash file itself keeps them off. */
    public function tripped(string $slug): bool
    {
        $data = json_decode((string) @file_get_contents($this->file($slug)), true);

        return is_array($data) && !empty($data['tripped']);
    }

    /** Forget crashes (the admin re-enabled the module, or a fixed version was installed). */
    public function reset(string $slug): void
    {
        @unlink($this->file($slug));
    }

    private function withFile(string $slug, callable $fn): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return;
        }
        $h = @fopen($this->file($slug), 'c+');
        if (!$h) {
            return;
        }
        try {
            flock($h, LOCK_EX);
            $data = json_decode((string) stream_get_contents($h), true);
            $data = $fn(is_array($data) ? $data : []);
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, (string) json_encode($data));
            fflush($h);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private function file(string $slug): string
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9-]/', '', $slug) . '.json';
    }
}

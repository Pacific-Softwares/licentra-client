<?php

namespace Pacific\Licentra\Modules;

use Pacific\Licentra\Exceptions\LicentraException;

/**
 * Installed modules: the single source of truth, kept in one PHP file that returns an array
 * (storage/app/licentra-modules.php). Written with temp + rename so a crash never leaves half a
 * file, and served from opcache, so reading it on every request costs nothing. No database:
 * modules must still load (or stay off) when the database is down.
 *
 *   modules[slug] = manifest fields (ModuleManifest::toArray) + {
 *     status:       enabled | disabled | needs_attention
 *     reason:       why it's not enabled (shown to the admin), or null
 *     path:         absolute path of the module folder
 *     dev:          true for unsigned developer-mode modules (modules-dev/)
 *     instance_id, token, license_status, license_problem_since, update   (from the server)
 *     installed_at, updated_at
 *   }
 */
final class Registry
{
    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    public const NEEDS_ATTENTION = 'needs_attention';

    private ?array $data = null;

    public function __construct(private readonly string $path)
    {
    }

    /** @return array<string, array> */
    public function all(): array
    {
        return $this->read()['modules'] ?? [];
    }

    public function get(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    public function put(string $slug, array $module): void
    {
        $data = $this->read();
        $data['modules'][$slug] = $module + ['slug' => $slug];
        ksort($data['modules']);
        $this->write($data);
    }

    /** Merge fields into an installed module's record. */
    public function update(string $slug, array $fields): void
    {
        $module = $this->get($slug) ?? throw ModuleException::notInstalled($slug);
        $this->put($slug, array_replace($module, $fields, ['updated_at' => time()]));
    }

    public function setStatus(string $slug, string $status, ?string $reason = null): void
    {
        $this->update($slug, ['status' => $status, 'reason' => $status === self::ENABLED ? null : $reason]);
    }

    public function remove(string $slug): void
    {
        $data = $this->read();
        unset($data['modules'][$slug]);
        $this->write($data);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Re-read from disk (another request may have changed it). */
    public function refresh(): void
    {
        $this->data = null;
    }

    private function read(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        if (!is_file($this->path)) {
            return $this->data = ['modules' => []];
        }
        try {
            $data = include $this->path;
        } catch (\ParseError $e) {
            // A hand-edited or truncated file: run with no modules rather than take the site down.
            error_log("licentra: {$this->path} is not valid PHP, ignoring installed modules: {$e->getMessage()}");
            $data = null;
        }

        return $this->data = is_array($data) && is_array($data['modules'] ?? null) ? $data : ['modules' => []];
    }

    private function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new LicentraException("Cannot create {$dir} for the module list.");
        }
        $php = "<?php\n\n// Written by pacific/licentra-client. Manage modules from the admin, not by editing this file.\n\nreturn "
            . var_export($data, true) . ";\n";
        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $php) === false || !@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new LicentraException("Cannot write the module list to {$this->path}. Check folder permissions.");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
        $this->data = $data;
    }
}

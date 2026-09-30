<?php

namespace Pacific\Licentra\Update;

/**
 * .licentra-manifest.json: every file a release ships, with its SHA-256. It rides inside the
 * signed zip. The updater uses it to write only changed files, and the previous release's copy
 * (kept in the install) to delete files a release removed without touching the buyer's own files.
 */
final class Manifest
{
    public const FILE = '.licentra-manifest.json';

    /** @param array<string, string> $files relative path => sha256 */
    public function __construct(public readonly array $files, public readonly ?string $version = null)
    {
    }

    public static function build(string $root, ?string $version = null): self
    {
        $root = rtrim($root, '/');
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($path !== self::FILE) {
                $files[$path] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);

        return new self($files, $version);
    }

    public static function read(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !is_array($data['files'] ?? null)) {
            return null;
        }
        foreach (array_keys($data['files']) as $file) {
            if (!self::isSafePath((string) $file)) {
                throw new UpdateFailed("Release manifest lists an unsafe path: {$file}");
            }
        }

        return new self($data['files'], $data['version'] ?? null);
    }

    public function write(string $path): void
    {
        $json = json_encode(['format' => 1, 'version' => $this->version, 'files' => $this->files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($path, $json) === false) {
            throw new UpdateFailed("Cannot write {$path}.");
        }
    }

    /** Relative, no "..", no absolute or drive paths: nothing can be written outside the install. */
    public static function isSafePath(string $path): bool
    {
        return $path !== ''
            && !str_starts_with($path, '/')
            && !str_contains($path, '\\')
            && !preg_match('#(^|/)\.\.(/|$)#', $path)
            && !preg_match('#^[a-zA-Z]:#', $path)
            && !str_contains($path, "\0");
    }
}

<?php

namespace Pacific\Licentra\Store;

use Pacific\Licentra\Exceptions\LicentraException;

final class FileStore implements Store
{
    public function __construct(private readonly string $path)
    {
    }

    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($this->path), true);

        return is_array($data) ? $data : [];
    }

    public function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new LicentraException("Cannot create directory {$dir} for license data.");
        }

        // Write-then-rename so a crash never leaves a half-written file.
        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
            || !@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new LicentraException("Cannot write license data to {$this->path}. Check folder permissions.");
        }
    }
}

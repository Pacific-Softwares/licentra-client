<?php

namespace Pacific\Licentra\Modules;

use Pacific\Licentra\Update\Manifest;

/**
 * An offline module package, for installing by upload instead of download:
 *
 *   slotara-whatsapp-1.2.0.licentra-module.zip
 *     ├─ module.zip       the exact zip that's uploaded to the license server
 *     └─ signature.json   {slug, version, sha256, signature}  (release key, as for downloads)
 *
 * Opening it only unpacks module.zip; the installer's normal verify step then checks the
 * sha256 and signature, so an uploaded package is trusted exactly as much as a download.
 */
final class ModulePackage
{
    public const SUFFIX = '.licentra-module.zip';

    public static function build(string $moduleZip, string $slug, string $version, string $sha256, string $signature, string $out): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($out, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw ModuleException::installFailed("Cannot create {$out}.");
        }
        $zip->addFile($moduleZip, 'module.zip');
        $zip->addFromString('signature.json', (string) json_encode(compact('slug', 'version', 'sha256', 'signature'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();
    }

    /** True if the file is an offline package (has module.zip + signature.json). */
    public static function isPackage(string $path): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $is = $zip->locateName('module.zip') !== false && $zip->locateName('signature.json') !== false;
        $zip->close();

        return $is;
    }

    /**
     * Unpack module.zip to $moduleZipOut and return what signature.json claims (verified later).
     *
     * @return array{slug: string, version: string, sha256: string, signature: string}
     */
    public static function open(string $path, string $moduleZipOut): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw ModuleException::installFailed('That file is not a zip.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (!Manifest::isSafePath(rtrim((string) $zip->getNameIndex($i), '/'))) {
                $zip->close();
                throw ModuleException::installFailed('The package contains an unsafe path.');
            }
        }
        $meta = json_decode((string) $zip->getFromName('signature.json'), true);
        $inner = $zip->getFromName('module.zip');
        $zip->close();

        if (!is_array($meta) || $inner === false) {
            throw ModuleException::installFailed('This is not a signed module package (it needs module.zip and signature.json). Download the .licentra-module.zip from your purchase.');
        }
        foreach (['slug', 'version', 'sha256', 'signature'] as $key) {
            if (!is_string($meta[$key] ?? null) || $meta[$key] === '') {
                throw ModuleException::installFailed("The package's signature.json has no {$key}.");
            }
        }
        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $meta['slug']) || !preg_match('/^\d+\.\d+\.\d+$/', $meta['version'])) {
            throw ModuleException::installFailed('The package names an invalid module or version.');
        }
        if (!is_dir(dirname($moduleZipOut)) && !@mkdir(dirname($moduleZipOut), 0775, true) && !is_dir(dirname($moduleZipOut))) {
            throw ModuleException::installFailed('Cannot create ' . dirname($moduleZipOut) . '.');
        }
        if (@file_put_contents($moduleZipOut, $inner) === false) {
            throw ModuleException::installFailed("Cannot write {$moduleZipOut}.");
        }

        return ['slug' => $meta['slug'], 'version' => $meta['version'], 'sha256' => strtolower($meta['sha256']), 'signature' => $meta['signature']];
    }
}

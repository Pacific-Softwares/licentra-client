<?php

namespace Pacific\Licentra;

final class Config
{
    /**
     * @param string $product        Product slug on the Licentra server, e.g. "quizora".
     * @param string $publicKey      Base64 Ed25519 public key from `php artisan licentra:keys`. Safe to ship.
     * @param string $storagePath    Writable JSON file for the token, e.g. storage/app/licentra.json.
     * @param string $appUrl         This install's URL (its domain is what gets licensed).
     */
    public function __construct(
        public readonly string $product,
        public readonly string $publicKey,
        public readonly string $storagePath,
        public readonly string $appUrl,
        public readonly string $productVersion = '0.0.0',
        public readonly string $serverUrl = 'https://licentra.pacificsoftwares.com',
        public readonly ?string $frameworkVersion = null,
        public readonly int $timeout = 8,
        public readonly int $heartbeatEveryHours = 24,
    ) {
    }
}

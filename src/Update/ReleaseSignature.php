<?php

namespace Pacific\Licentra\Update;

/**
 * Ed25519 signature over "product|version|sha256" of a release zip, made on the author's
 * machine with the release key. Products bundle the public half, so neither a hacked
 * license server nor anything in between can hand an install a different zip.
 */
final class ReleaseSignature
{
    private const PREFIX = 'licentra-release:v1';

    public static function message(string $product, string $version, string $sha256): string
    {
        return self::PREFIX . '|' . $product . '|' . $version . '|' . strtolower($sha256);
    }

    /** @return array{secret: string, public: string} base64 */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    public static function publicKeyFor(string $secretB64): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey(self::decode($secretB64, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)));
    }

    public static function sign(string $secretB64, string $product, string $version, string $sha256): string
    {
        $secret = self::decode($secretB64, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);

        return base64_encode(sodium_crypto_sign_detached(self::message($product, $version, $sha256), $secret));
    }

    public static function verify(?string $publicB64, ?string $signatureB64, string $product, string $version, string $sha256): bool
    {
        $key = base64_decode((string) $publicB64, true);
        $sig = base64_decode((string) $signatureB64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($sig, self::message($product, $version, $sha256), $key);
        } catch (\SodiumException) {
            return false;
        }
    }

    private static function decode(string $b64, int $length): string
    {
        $raw = base64_decode(trim($b64), true);
        if ($raw === false || strlen($raw) !== $length) {
            throw new \InvalidArgumentException('Not a valid base64 Ed25519 key.');
        }

        return $raw;
    }
}

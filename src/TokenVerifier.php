<?php

namespace Pacific\Licentra;

/**
 * Verifies tokens from licentra-server app/Licentra/TokenSigner.php:
 * base64url(json_payload) . "." . base64url(ed25519_signature)
 */
final class TokenVerifier
{
    public function __construct(private readonly string $publicKeyBase64)
    {
    }

    /** @return array<string, mixed>|null payload, or null if the token is malformed or the signature is wrong */
    public function verify(?string $token): ?array
    {
        if (!is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }

        [$body, $sig] = explode('.', $token);
        $key = base64_decode($this->publicKeyBase64, true);
        $signature = self::b64decode($sig);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        try {
            if (!sodium_crypto_sign_verify_detached($signature, $body, $key)) {
                return null;
            }
        } catch (\SodiumException) {
            return null;
        }

        $payload = json_decode((string) self::b64decode($body), true);

        return is_array($payload) ? $payload : null;
    }

    private static function b64decode(string $s): ?string
    {
        $out = base64_decode(strtr($s, '-_', '+/'), true);

        return $out === false ? null : $out;
    }
}

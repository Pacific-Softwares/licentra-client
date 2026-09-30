<?php

namespace Pacific\Licentra;

use Pacific\Licentra\Exceptions\ActivationFailed;
use Pacific\Licentra\Exceptions\LicentraException;
use Pacific\Licentra\Exceptions\ServerUnreachable;
use Pacific\Licentra\Http\StreamTransport;
use Pacific\Licentra\Http\Transport;
use Pacific\Licentra\Store\FileStore;
use Pacific\Licentra\Store\Store;

/**
 * Client for the Licentra license server.
 *
 *   Installer ──activate(code)──▶ server ──▶ signed token ──▶ Store
 *   Every request ──state()──▶ verify token offline (no network) ──▶ Status
 *   Daily ──heartbeat()──▶ server ──▶ refreshed token + update/support info
 *                              └─ unreachable: keep old token (valid until its exp, ~30 days)
 *
 * Nothing in here throws into the host app on network trouble except activate()/deactivate(),
 * which are user-initiated and need to show an error.
 */
final class Licentra
{
    /** Server verdicts that mean "stop working"; mapped to the local Status. */
    private const TERMINAL_ERRORS = [
        'license_revoked' => Status::Revoked,
        'license_blocked' => Status::Blocked,
        'activation_inactive' => Status::Deactivated,
        'unknown_instance' => Status::Deactivated,
        'domain_mismatch' => Status::DomainMismatch,
    ];

    private const RETRY_FAILED_HEARTBEAT_AFTER = 3600;

    private readonly Store $store;
    private readonly Transport $http;
    private readonly TokenVerifier $verifier;
    private ?LicenseState $cached = null;

    public function __construct(
        private readonly Config $config,
        ?Store $store = null,
        ?Transport $http = null,
    ) {
        $this->store = $store ?? new FileStore($config->storagePath);
        $this->http = $http ?? new StreamTransport();
        $this->verifier = new TokenVerifier($config->publicKey);
    }

    /**
     * Activate this install. Call from your installer / "Activate license" page.
     *
     * @throws ActivationFailed  show $e->getMessage() to the buyer
     * @throws ServerUnreachable show "Could not reach the license server, try again"
     */
    public function activate(string $purchaseCode): LicenseState
    {
        $data = $this->request('POST', '/api/v1/activate', [
            'product' => $this->config->product,
            'purchase_code' => trim($purchaseCode),
        ] + $this->installInfo());

        $this->save($data);

        return $this->state();
    }

    /**
     * Record the Host this install is actually served on. APP_URL is whatever the buyer typed
     * (often "localhost" in production); the real Host is what gets licensed when it's public.
     * Only writes when the host changes.
     */
    public function observeHost(?string $host): void
    {
        $host = Domain::normalize($host);
        $stored = $this->store->read();
        if ($host === null || ($stored['request_host'] ?? null) === $host) {
            return;
        }
        $stored['request_host'] = $host;
        $this->safeWrite($stored);
        $this->cached = null;
    }

    /** Offline license check. Cheap: call it on every admin request. */
    public function state(): LicenseState
    {
        return $this->cached ??= $this->computeState();
    }

    public function isValid(): bool
    {
        return $this->state()->isUsable();
    }

    /**
     * Ping the server if due. Never throws; returns false if skipped or failed.
     * Call from a daily scheduler, or after the response is sent (the Laravel adapter does this).
     */
    public function heartbeat(bool $force = false): bool
    {
        $stored = $this->store->read();
        if (empty($stored['instance_id']) || (!$force && !$this->heartbeatDue($stored))) {
            return false;
        }

        $stored['last_attempt_at'] = time();
        if (!$this->safeWrite($stored)) {
            // Can't record the attempt, so we'd retry on every request. Skip; the token still works.
            return false;
        }

        try {
            $data = $this->request('POST', '/api/v1/heartbeat', ['instance_id' => $stored['instance_id']] + $this->installInfo());
            $this->save($data);

            return true;
        } catch (ActivationFailed $e) {
            if (isset(self::TERMINAL_ERRORS[$e->errorCode])) {
                $stored['revoked'] = $e->errorCode;
                $stored['revoked_message'] = $e->getMessage();
                unset($stored['token']);
                $this->safeWrite($stored);
                $this->cached = null;
            }

            return false;
        } catch (LicentraException) {
            return false; // network trouble: keep the token we have
        }
    }

    /**
     * Release this domain's slot so the license can be used elsewhere.
     *
     * @throws ActivationFailed|ServerUnreachable
     */
    public function deactivate(string $purchaseCode): void
    {
        $stored = $this->store->read();
        if (empty($stored['instance_id'])) {
            throw new ActivationFailed(
                'not_activated',
                'This installation has no activation to release. To free a domain from an old server, contact support.',
            );
        }

        $this->request('POST', '/api/v1/deactivate', [
            'instance_id' => $stored['instance_id'],
            'purchase_code' => trim($purchaseCode),
        ]);

        $this->store->write(array_intersect_key($stored, ['request_host' => true]));
        $this->cached = null;
    }

    private function computeState(): LicenseState
    {
        $stored = $this->store->read();
        $meta = [
            'licenseType' => $stored['license']['type'] ?? null,
            'buyer' => $stored['license']['buyer'] ?? null,
            'supportedUntil' => self::date($stored['license']['supported_until'] ?? null),
            'renewUrl' => $stored['license']['renew_url'] ?? null,
            'update' => $stored['update'] ?? null,
            'currentVersion' => $this->config->productVersion,
        ];

        if (!empty($stored['revoked'])) {
            return new LicenseState(self::TERMINAL_ERRORS[$stored['revoked']] ?? Status::Invalid, ...$meta);
        }
        if (empty($stored['token'])) {
            return new LicenseState(Status::Missing, ...$meta);
        }

        $payload = $this->verifier->verify($stored['token']);
        if ($payload === null || ($payload['product'] ?? null) !== $this->config->product) {
            return new LicenseState(Status::Invalid, ...$meta);
        }

        $here = $this->currentDomain($stored);
        $status = match (true) {
            // A copy of a licensed site on localhost/staging is fine; a copy on another live domain isn't.
            $here !== null && $here !== $payload['domain'] && !Domain::isDev($here) => Status::DomainMismatch,
            ($payload['exp'] ?? 0) < time() => Status::Expired,
            ($payload['status'] ?? null) === 'pending' => Status::Pending,
            ($payload['status'] ?? null) === 'valid' => Status::Valid,
            default => Status::Invalid,
        };

        return new LicenseState($status, $payload['domain'] ?? null, ...$meta);
    }

    private function currentDomain(array $stored): ?string
    {
        $host = $stored['request_host'] ?? null;

        return $host !== null && !Domain::isDev($host) ? $host : Domain::normalize($this->config->appUrl);
    }

    private function heartbeatDue(array $stored): bool
    {
        $now = time();
        $last = (int) ($stored['last_heartbeat_at'] ?? 0);
        $attempt = (int) ($stored['last_attempt_at'] ?? 0);

        return $now - $last >= $this->config->heartbeatEveryHours * 3600
            && $now - $attempt >= self::RETRY_FAILED_HEARTBEAT_AFTER;
    }

    private function save(array $data): void
    {
        if (empty($data['token']) || $this->verifier->verify($data['token']) === null) {
            // Wrong public key in config, or something in between tampered with the response.
            throw new LicentraException('License server returned a token that fails signature check. Is the product\'s public key correct?');
        }

        $this->store->write([
            'instance_id' => $data['instance_id'],
            'token' => $data['token'],
            'license' => $data['license'] ?? null,
            'update' => $data['update'] ?? null,
            'last_heartbeat_at' => time(),
            'request_host' => $this->store->read()['request_host'] ?? null,
        ]);
        $this->cached = null;
    }

    private function safeWrite(array $data): bool
    {
        try {
            $this->store->write($data);

            return true;
        } catch (LicentraException) {
            return false; // an unwritable storage dir must not take the site down
        }
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $res = $this->http->send($method, rtrim($this->config->serverUrl, '/') . $path, $body, $this->config->timeout);

        if ($res->json === null || $res->status >= 500) {
            throw new ServerUnreachable("License server error (HTTP {$res->status}). Try again in a few minutes.");
        }
        if ($res->status === 429) {
            throw new ActivationFailed('rate_limited', 'Too many attempts. Wait a few minutes and try again.', 429);
        }
        if (($res->json['ok'] ?? false) !== true) {
            $message = $res->json['message'] ?? 'Activation failed.';
            if (isset($res->json['errors']) && is_array($res->json['errors'])) {
                $message = (string) (array_values($res->json['errors'])[0][0] ?? $message);
            }
            throw new ActivationFailed($res->json['error'] ?? 'validation_failed', $message, $res->status, $res->json['context'] ?? []);
        }

        return $res->json;
    }

    private function installInfo(): array
    {
        return array_filter([
            'app_url' => $this->config->appUrl,
            'request_host' => $this->store->read()['request_host'] ?? null,
            'product_version' => $this->config->productVersion,
            'php_version' => PHP_VERSION,
            'framework_version' => $this->config->frameworkVersion,
        ]);
    }

    private static function date(?string $iso): ?\DateTimeImmutable
    {
        try {
            return $iso ? new \DateTimeImmutable($iso) : null;
        } catch (\Exception) {
            return null;
        }
    }
}

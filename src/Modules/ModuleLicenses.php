<?php

namespace Pacific\Licentra\Modules;

use Pacific\Licentra\Licentra;
use Pacific\Licentra\TokenVerifier;

/**
 * Add-on licenses, checked offline like the product's own token. A module token is signed by the
 * same server key and must name: this module's slug, this install as its parent, and this install's
 * licensed domain. Tokens are refreshed inside the product's daily heartbeat (absorb()).
 *
 *   token ok ─────────────────────────────────────────────▶ runs
 *   revoked / blocked / released by the server ─▶ banner for GRACE_DAYS ─▶ off (data kept)
 *   no refresh (server unreachable) ─▶ runs until token exp (~30d) + GRACE_DAYS ─▶ off
 */
final class ModuleLicenses
{
    public const GRACE_DAYS = 7;

    private const PROBLEM_STATUSES = ['revoked', 'blocked', 'inactive'];

    private readonly TokenVerifier $verifier;

    public function __construct(private readonly Licentra $licentra)
    {
        $this->verifier = new TokenVerifier($licentra->config()->publicKey);
    }

    /** Why this module may not run, or null if it may. Developer-mode modules are never licensed. */
    public function problem(array $module, ?int $now = null): ?string
    {
        if (!empty($module['dev'])) {
            return null;
        }
        $now ??= time();
        $grace = self::GRACE_DAYS * 86400;

        if (!$this->tokenIsOurs($module['slug'] ?? '', $module['token'] ?? null, $payload)) {
            return 'This module is not activated on this installation. Activate it from Modules.';
        }

        $status = $module['license_status'] ?? 'valid';
        if (in_array($status, self::PROBLEM_STATUSES, true)) {
            $since = (int) ($module['license_problem_since'] ?? $now);

            return $now - $since > $grace ? self::problemMessage($status) : null;
        }

        if ((int) ($payload['exp'] ?? 0) + $grace < $now) {
            return 'The module license could not be refreshed for over a month. Check that this site can reach the license server.';
        }

        return null;
    }

    /** Shown in the admin while a module still runs but won't for long. */
    public function warning(array $module, ?int $now = null): ?string
    {
        if (!empty($module['dev']) || $this->problem($module, $now) !== null) {
            return null;
        }
        $status = $module['license_status'] ?? 'valid';
        if (in_array($status, self::PROBLEM_STATUSES, true)) {
            $left = self::GRACE_DAYS - intdiv(($now ?? time()) - (int) ($module['license_problem_since'] ?? time()), 86400);

            return self::problemMessage($status) . " It switches off in {$left} day(s).";
        }

        return null;
    }

    /**
     * Take the "modules" block of a product heartbeat into the registry. Only tokens that verify
     * for this module and this install are kept.
     *
     * @param array<string, array> $states slug => {instance_id, status, token?, update?}
     */
    public function absorb(array $states, Registry $registry): void
    {
        foreach ($registry->all() as $slug => $module) {
            if (!empty($module['dev']) || !isset($states[$slug]) || !is_array($states[$slug])) {
                continue;
            }
            $s = $states[$slug];
            $status = (string) ($s['status'] ?? 'valid');
            $fields = [
                'instance_id' => $s['instance_id'] ?? ($module['instance_id'] ?? null),
                'license_status' => $status,
                'license_problem_since' => in_array($status, self::PROBLEM_STATUSES, true)
                    ? ($module['license_problem_since'] ?? time())
                    : null,
                'update' => $s['update'] ?? null,
            ];
            if (isset($s['token']) && $this->tokenIsOurs($slug, $s['token'])) {
                $fields['token'] = $s['token'];
            }
            $registry->update($slug, $fields);
        }
    }

    /** A token signed by the license server for this module, this install and this domain. */
    public function tokenIsOurs(string $slug, ?string $token, ?array &$payload = null): bool
    {
        $payload = $this->verifier->verify($token);
        $parent = $this->licentra->instanceId();

        return $payload !== null
            && ($payload['product'] ?? null) === $slug
            && $parent !== null
            && ($payload['parent'] ?? null) === $parent
            && ($payload['domain'] ?? null) === $this->licentra->state()->domain;
    }

    private static function problemMessage(string $status): string
    {
        return match ($status) {
            'revoked' => 'The license for this module was refunded or cancelled.',
            'blocked' => 'The license for this module was blocked. Contact support.',
            default => 'This module was released from this installation (the product was moved or deactivated). Activate it again.',
        };
    }
}

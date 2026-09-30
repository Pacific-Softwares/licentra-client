<?php

namespace Pacific\Licentra;

/** Mirrors licentra-server app/Licentra/DomainRules.php (normalize, isLocal). Keep both in sync. */
final class Domain
{
    private const DEV_HOSTS = ['localhost', '127.0.0.1', '::1'];
    private const DEV_SUFFIXES = ['.test', '.local', '.localhost', '.invalid', '.example'];

    public static function normalize(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'http://' . $input;
        }
        $host = parse_url($input, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower(trim($host, '[].'));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return strlen($host) <= 253 ? $host : null;
    }

    /**
     * Hosts no customer can reach (localhost, private IPs, .test, ...). A copy of a licensed
     * site may run here. "staging.shop.com" is NOT local: it's a public site and must match
     * the domain in the token (the server licenses it for free next to shop.com).
     */
    public static function isDev(string $host): bool
    {
        if (in_array($host, self::DEV_HOSTS, true)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        foreach (self::DEV_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace Ishalabs\Licentra;

/** Mirrors licentra-server app/Licentra/DomainRules.php. Keep both in sync. */
final class Domain
{
    private const DEV_HOSTS = ['localhost', '127.0.0.1', '::1'];
    private const DEV_SUFFIXES = ['.test', '.local', '.localhost', '.invalid', '.example'];
    private const DEV_PREFIXES = ['staging.', 'stage.', 'dev.', 'test.', 'local.'];

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
        foreach (self::DEV_PREFIXES as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

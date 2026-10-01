<?php

namespace Pacific\Licentra\Modules;

/**
 * The small subset of Composer constraints module.json uses for "requires":
 * "^2.3", "~2.3", ">=2.3 <3.0", "2.4.1", "*". Space or comma = AND, "||" = OR.
 * No dependency on composer/semver: this package must install on hosts without Composer.
 */
final class VersionConstraint
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $version = self::normalize($version);
        foreach (explode('||', $constraint) as $alternative) {
            $parts = preg_split('/[\s,]+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($parts !== [] && array_reduce($parts, fn (bool $ok, string $p) => $ok && self::matches($version, $p), true)) {
                return true;
            }
        }

        return false;
    }

    public static function isValid(string $constraint): bool
    {
        foreach (explode('||', $constraint) as $alternative) {
            $parts = preg_split('/[\s,]+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($parts === []) {
                return false;
            }
            foreach ($parts as $part) {
                if ($part !== '*' && !preg_match('/^(\^|~|>=|<=|>|<|=|!=)?v?\d+(\.\d+){0,2}$/', $part)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function matches(string $version, string $part): bool
    {
        if ($part === '*') {
            return true;
        }
        if (!preg_match('/^(\^|~|>=|<=|>|<|=|!=)?v?(\d+(?:\.\d+){0,2})$/', $part, $m)) {
            return false;
        }
        [$op, $target] = [$m[1] ?: '=', $m[2]];
        $segments = substr_count($target, '.') + 1;
        $target = self::normalize($target);
        [$major, $minor] = array_map('intval', explode('.', $target));

        return match ($op) {
            // ^2.3 = >=2.3 <3.0;  ^0.3 = >=0.3 <0.4
            '^' => version_compare($version, $target, '>=')
                && version_compare($version, $major > 0 ? ($major + 1) . '.0.0' : "0." . ($minor + 1) . '.0', '<'),
            // ~2.3 = >=2.3 <3.0;  ~2.3.1 = >=2.3.1 <2.4
            '~' => version_compare($version, $target, '>=')
                && version_compare($version, $segments >= 3 ? "{$major}." . ($minor + 1) . '.0' : ($major + 1) . '.0.0', '<'),
            '=' => version_compare($version, $target, '=='),
            '!=' => version_compare($version, $target, '!='),
            default => version_compare($version, $target, $op),
        };
    }

    private static function normalize(string $version): string
    {
        $parts = explode('.', ltrim(preg_replace('/[-+].*$/', '', trim($version)) ?? '', 'v'));

        return implode('.', array_pad(array_slice($parts, 0, 3), 3, '0'));
    }
}

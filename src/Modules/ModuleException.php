<?php

namespace Pacific\Licentra\Modules;

use Pacific\Licentra\Exceptions\LicentraException;

/**
 * Every module failure the admin can see. $reason is stable (tests and the Modules page switch
 * on it); the message is written for the admin.
 */
final class ModuleException extends LicentraException
{
    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function manifestInvalid(string $why): self
    {
        return new self('manifest_invalid', "The module package is damaged or not a module: {$why}");
    }

    public static function incompatible(string $why): self
    {
        return new self('incompatible', $why);
    }

    public static function downgrade(string $slug, string $installed, string $offered): self
    {
        return new self('downgrade', "{$slug} {$installed} is installed; refusing to replace it with older version {$offered}.");
    }

    public static function busy(string $what = 'Another update or module install is running.'): self
    {
        return new self('busy', $what . ' Wait for it to finish, then try again.');
    }

    public static function installFailed(string $why, ?\Throwable $previous = null): self
    {
        return new self('install_failed', $why, $previous);
    }

    public static function notInstalled(string $slug): self
    {
        return new self('not_installed', "Module {$slug} is not installed.");
    }
}

<?php

namespace Pacific\Licentra;

enum Status: string
{
    case Valid = 'valid';
    /** Envato was unreachable during activation; works while Licentra re-checks. */
    case Pending = 'pending';
    case Missing = 'missing';
    case Expired = 'expired';
    case Invalid = 'invalid';
    case DomainMismatch = 'domain_mismatch';
    case Revoked = 'revoked';
    case Blocked = 'blocked';
    case Deactivated = 'deactivated';

    public function isUsable(): bool
    {
        return $this === self::Valid || $this === self::Pending;
    }

    public function message(): string
    {
        return match ($this) {
            self::Valid => 'License active.',
            self::Pending => 'License accepted. Verification with Envato is finishing in the background.',
            self::Missing => 'This installation is not activated yet. Enter your purchase code to activate it.',
            self::Expired => 'Could not reach the license server for too long. Check this server can make outbound HTTPS requests.',
            self::Invalid => 'The license data on this server is invalid. Activate again.',
            self::DomainMismatch => 'This license is registered to a different domain. Activate it for this domain.',
            self::Revoked => 'This purchase code is no longer valid (refunded or cancelled).',
            self::Blocked => 'This license has been blocked. Contact support.',
            self::Deactivated => 'This installation was deactivated. Activate it again.',
        };
    }
}

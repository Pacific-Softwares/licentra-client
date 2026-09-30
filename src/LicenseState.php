<?php

namespace Pacific\Licentra;

/** Everything a product needs to render license UI, computed offline from the stored token. */
final class LicenseState
{
    public function __construct(
        public readonly Status $status,
        public readonly ?string $domain = null,
        public readonly ?string $licenseType = null,
        public readonly ?string $buyer = null,
        public readonly ?\DateTimeImmutable $supportedUntil = null,
        public readonly ?string $renewUrl = null,
        /** @var array{version: string, released_at: ?string, changelog: ?string, url: ?string}|null */
        public readonly ?array $update = null,
        public readonly ?string $currentVersion = null,
    ) {
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    public function message(): string
    {
        return $this->status->message();
    }

    public function supportActive(): bool
    {
        return $this->supportedUntil !== null && $this->supportedUntil > new \DateTimeImmutable();
    }

    /** True when support ends within $days (or has ended). Use it to show a "Renew support" banner. */
    public function supportEndingSoon(int $days = 30): bool
    {
        return $this->supportedUntil !== null
            && $this->supportedUntil < (new \DateTimeImmutable())->modify("+{$days} days");
    }

    public function updateAvailable(): bool
    {
        return $this->update !== null
            && $this->currentVersion !== null
            && version_compare($this->update['version'], $this->currentVersion, '>');
    }
}

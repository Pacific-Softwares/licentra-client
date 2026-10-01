<?php

namespace Pacific\Licentra\Modules;

/**
 * module.json, format 1 (docs/designs/modules.md in licentra-server):
 *
 *   {
 *     "manifest": 1,
 *     "slug": "slotara-whatsapp",        one slug everywhere: Licentra product, release signature,
 *     "name": "WhatsApp Notifications",  folder modules/{slug}/, route names
 *     "version": "1.2.0",
 *     "product": "slotara",              must equal the host's licentra.product
 *     "requires": { "product": ">=2.3 <3.0", "licentra": "^1.5" },
 *     "namespace": "Modules\\SlotaraWhatsapp\\",   PSR-4 root for src/
 *     "provider": "Modules\\SlotaraWhatsapp\\ModuleServiceProvider",
 *     "envato_item_id": 12345,           display only; whether it's free is decided by the server
 *     "plan_feature": true,              multi-tenant hosts gate it per plan
 *     "capabilities": {...}              declared by the author, shown before install, NOT enforced
 *   }
 */
final class ModuleManifest
{
    public const FILE = 'module.json';

    public const FORMAT = 1;

    /** Version of the module API in this package; modules can require it ("requires.licentra"). */
    public const API_VERSION = '1.5.0';

    private const SLUG = '/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/';

    private const SEMVER = '/^\d+\.\d+\.\d+$/';

    private const NAMESPACE = '/^([A-Z][A-Za-z0-9_]*\\\\)+$/';

    private const CLASS_NAME = '/^([A-Z][A-Za-z0-9_]*\\\\)+[A-Z][A-Za-z0-9_]*$/';

    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $product,
        public readonly string $requiresProduct,
        public readonly ?string $requiresLicentra,
        public readonly string $namespace,
        public readonly string $provider,
        public readonly ?int $envatoItemId,
        public readonly bool $planFeature,
        public readonly array $capabilities,
        public readonly string $description,
    ) {
    }

    /** @throws ModuleException manifest_invalid */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw ModuleException::manifestInvalid('module.json is missing.');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw ModuleException::manifestInvalid('module.json is not valid JSON.');
        }

        return self::fromArray($data);
    }

    /** @throws ModuleException manifest_invalid */
    public static function fromArray(array $d): self
    {
        $str = fn (string $key) => is_string($d[$key] ?? null) && trim($d[$key]) !== '' ? trim($d[$key]) : null;
        $fail = fn (string $why) => throw ModuleException::manifestInvalid($why);

        if (($d['manifest'] ?? null) !== self::FORMAT) {
            $fail('unsupported "manifest" format (expected ' . self::FORMAT . ').');
        }
        $slug = $str('slug') ?? $fail('"slug" is required.');
        if (!preg_match(self::SLUG, $slug) || strlen($slug) > 64) {
            $fail("\"slug\" must look like \"{product}-{name}\" in lowercase (got \"{$slug}\").");
        }
        $product = $str('product') ?? $fail('"product" is required.');
        if (!str_starts_with($slug, $product . '-')) {
            $fail("\"slug\" must start with the product name (\"{$product}-...\").");
        }
        $version = $str('version') ?? $fail('"version" is required.');
        if (!preg_match(self::SEMVER, $version)) {
            $fail("\"version\" must be x.y.z (got \"{$version}\").");
        }
        $requires = is_array($d['requires'] ?? null) ? $d['requires'] : [];
        $requiresProduct = is_string($requires['product'] ?? null) ? trim($requires['product']) : '*';
        $requiresLicentra = is_string($requires['licentra'] ?? null) ? trim($requires['licentra']) : null;
        foreach (['requires.product' => $requiresProduct, 'requires.licentra' => $requiresLicentra] as $key => $c) {
            if ($c !== null && !VersionConstraint::isValid($c)) {
                $fail("\"{$key}\" is not a version constraint (got \"{$c}\").");
            }
        }
        $namespace = $str('namespace') ?? $fail('"namespace" is required.');
        if (!preg_match(self::NAMESPACE, $namespace)) {
            $fail('"namespace" must be a PSR-4 prefix ending in "\\\\", e.g. "Modules\\\\Whatsapp\\\\".');
        }
        $provider = $str('provider') ?? $fail('"provider" is required.');
        if (!preg_match(self::CLASS_NAME, $provider) || !str_starts_with($provider, $namespace)) {
            $fail('"provider" must be a class inside "namespace".');
        }
        $item = $d['envato_item_id'] ?? null;
        if ($item !== null && (!is_int($item) || $item <= 0)) {
            $fail('"envato_item_id" must be a number or null.');
        }

        return new self(
            slug: $slug,
            name: $str('name') ?? $slug,
            version: $version,
            product: $product,
            requiresProduct: $requiresProduct,
            requiresLicentra: $requiresLicentra,
            namespace: $namespace,
            provider: $provider,
            envatoItemId: $item,
            planFeature: (bool) ($d['plan_feature'] ?? false),
            capabilities: is_array($d['capabilities'] ?? null) ? $d['capabilities'] : [],
            description: $str('description') ?? '',
        );
    }

    /**
     * Can this module run on this host? Checked at install and again at every boot, so a product
     * update past "requires.product" switches the module off instead of crashing the site.
     *
     * @throws ModuleException incompatible
     */
    public function assertCompatible(string $hostProduct, string $hostVersion): void
    {
        if ($this->product !== $hostProduct) {
            throw ModuleException::incompatible("{$this->name} is a module for {$this->product}, not {$hostProduct}.");
        }
        if (!VersionConstraint::satisfies($hostVersion, $this->requiresProduct)) {
            throw ModuleException::incompatible("{$this->name} {$this->version} needs {$this->product} {$this->requiresProduct}; this site runs {$hostVersion}.");
        }
        if ($this->requiresLicentra !== null && !VersionConstraint::satisfies(self::API_VERSION, $this->requiresLicentra)) {
            throw ModuleException::incompatible("{$this->name} needs licentra-client {$this->requiresLicentra}; this site has " . self::API_VERSION . '. Update the product first.');
        }
    }

    /** What the registry keeps about an installed module. */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'version' => $this->version,
            'product' => $this->product,
            'requires' => array_filter(['product' => $this->requiresProduct, 'licentra' => $this->requiresLicentra]),
            'namespace' => $this->namespace,
            'provider' => $this->provider,
            'envato_item_id' => $this->envatoItemId,
            'plan_feature' => $this->planFeature,
            'capabilities' => $this->capabilities,
            'description' => $this->description,
        ];
    }

    /** Rebuild from what the registry stored (already validated at install). */
    public static function fromRegistry(array $module): self
    {
        return self::fromArray(['manifest' => self::FORMAT] + $module);
    }
}

<?php

namespace Pacific\Licentra\Tests;

use Pacific\Licentra\Modules\ModuleException;
use Pacific\Licentra\Modules\ModuleManifest;
use Pacific\Licentra\Modules\VersionConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleManifestTest extends TestCase
{
    private function valid(array $override = []): array
    {
        return array_replace([
            'manifest' => 1, 'slug' => 'slotara-whatsapp', 'name' => 'WhatsApp', 'version' => '1.2.0', 'product' => 'slotara',
            'requires' => ['product' => '>=2.3 <3.0'], 'namespace' => 'Modules\\Whatsapp\\', 'provider' => 'Modules\\Whatsapp\\Provider',
        ], $override);
    }

    public function test_valid_manifest_round_trips_through_the_registry_format(): void
    {
        $m = ModuleManifest::fromArray($this->valid(['envato_item_id' => 99, 'plan_feature' => true]));

        $again = ModuleManifest::fromRegistry($m->toArray() + ['status' => 'enabled', 'path' => '/x']);

        $this->assertSame('slotara-whatsapp', $again->slug);
        $this->assertSame('>=2.3 <3.0', $again->requiresProduct);
        $this->assertSame(99, $again->envatoItemId);
        $this->assertTrue($again->planFeature);
    }

    public static function invalid(): array
    {
        return [
            'wrong format' => [['manifest' => 2]],
            'missing slug' => [['slug' => '']],
            'slug without product prefix' => [['slug' => 'quizora-whatsapp']],
            'uppercase slug' => [['slug' => 'slotara-WhatsApp']],
            'slug with path' => [['slug' => 'slotara-../x']],
            'bad version' => [['version' => '1.2']],
            'bad constraint' => [['requires' => ['product' => 'banana']]],
            'namespace without trailing slash' => [['namespace' => 'Modules\\Whatsapp']],
            'provider outside namespace' => [['provider' => 'App\\Providers\\Evil']],
            'item id not a number' => [['envato_item_id' => '123']],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_manifests_are_refused_with_a_reason(array $override): void
    {
        $this->expectException(ModuleException::class);

        ModuleManifest::fromArray($this->valid($override));
    }

    public function test_missing_or_unparseable_file(): void
    {
        try {
            ModuleManifest::fromFile('/nope/module.json');
            $this->fail('expected exception');
        } catch (ModuleException $e) {
            $this->assertSame('manifest_invalid', $e->reason);
        }
    }

    public function test_compatibility_checks_product_and_version(): void
    {
        $m = ModuleManifest::fromArray($this->valid());

        $m->assertCompatible('slotara', '2.5.1');
        $this->addToAssertionCount(1);

        foreach ([['quizora', '2.5.0'], ['slotara', '2.2.9'], ['slotara', '3.0.0']] as [$product, $version]) {
            try {
                $m->assertCompatible($product, $version);
                $this->fail("{$product} {$version} should be incompatible");
            } catch (ModuleException $e) {
                $this->assertSame('incompatible', $e->reason);
            }
        }
    }

    public function test_module_can_require_a_newer_client(): void
    {
        $this->expectExceptionMessage('needs licentra-client');

        ModuleManifest::fromArray($this->valid(['requires' => ['product' => '*', 'licentra' => '^9.0']]))->assertCompatible('slotara', '2.5.0');
    }

    public static function constraints(): array
    {
        return [
            ['2.3.0', '^2.3', true], ['2.9.9', '^2.3', true], ['3.0.0', '^2.3', false], ['2.2.0', '^2.3', false],
            ['0.3.5', '^0.3', true], ['0.4.0', '^0.3', false],
            ['2.3.4', '~2.3.1', true], ['2.4.0', '~2.3.1', false], ['2.9.0', '~2.3', true],
            ['2.5.0', '>=2.3 <3.0', true], ['3.0.0', '>=2.3 <3.0', false], ['2.5.0', '>=2.3, <3.0', true],
            ['1.0.0', '^1.0 || ^2.0', true], ['2.1.0', '^1.0 || ^2.0', true], ['3.0.0', '^1.0 || ^2.0', false],
            ['2.4.1', '2.4.1', true], ['2.4.2', '2.4.1', false], ['2.4.2', '!=2.4.1', true],
            ['2.4.0-beta', '^2.4', true], ['v2.4', '^2.4', true], ['9.9.9', '*', true],
        ];
    }

    #[DataProvider('constraints')]
    public function test_version_constraints(string $version, string $constraint, bool $expected): void
    {
        $this->assertSame($expected, VersionConstraint::satisfies($version, $constraint));
    }
}

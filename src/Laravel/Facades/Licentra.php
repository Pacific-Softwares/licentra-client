<?php

namespace Ishalabs\Licentra\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Ishalabs\Licentra\LicenseState activate(string $purchaseCode)
 * @method static \Ishalabs\Licentra\LicenseState state()
 * @method static bool isValid()
 * @method static bool heartbeat(bool $force = false)
 * @method static void deactivate(string $purchaseCode)
 */
class Licentra extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ishalabs\Licentra\Licentra::class;
    }
}

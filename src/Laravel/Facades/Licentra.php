<?php

namespace Pacific\Licentra\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Pacific\Licentra\LicenseState activate(string $purchaseCode)
 * @method static \Pacific\Licentra\LicenseState state()
 * @method static bool isValid()
 * @method static bool heartbeat(bool $force = false)
 * @method static void deactivate(string $purchaseCode)
 */
class Licentra extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pacific\Licentra\Licentra::class;
    }
}

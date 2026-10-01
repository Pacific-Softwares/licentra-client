<?php

namespace Pacific\Licentra\Modules\Laravel;

/**
 * For a module's Filament resources and pages. Declare the module slug and Filament hides the
 * page (navigation included) unless the module is running and the tenant's plan includes it:
 *
 *   class InvoiceResource extends Resource
 *   {
 *       use GatedByModule;
 *       protected static string $module = 'slotara-invoices';
 *   }
 */
trait GatedByModule
{
    public static function canAccess(): bool
    {
        $slug = static::$module;

        return app(ModuleLoader::class)->isLoaded($slug)
            && app(TenantGate::class)->allows($slug, request())
            && parent::canAccess();
    }
}

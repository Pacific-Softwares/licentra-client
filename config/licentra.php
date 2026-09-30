<?php

return [
    /*
     * Hardcode these three in your product's published config/licentra.php.
     * Never read them from env(): a buyer's .env must not be able to point the
     * product at a different license server or key.
     */
    // Product slug registered in Licentra admin, e.g. "quizora".
    'product' => null,

    // Public key from `php artisan licentra:keys` on the server (safe to publish).
    'public_key' => null,

    // Your product's version, e.g. config('app.version') or trim(file_get_contents(base_path('VERSION'))).
    'product_version' => env('LICENTRA_PRODUCT_VERSION', '0.0.0'),

    // Public key of YOUR release signing key (`vendor/bin/licentra-release keygen`). Hardcode it too.
    // Without it, one-click updates are off and admins just see "version X is available".
    'release_public_key' => null,

    'server_url' => 'https://licentra.pacificsoftwares.com',

    'storage_path' => storage_path('app/licentra.json'),

    // Built-in activation page at /{route_prefix}. Set to false to use your own UI.
    'routes' => true,
    'route_prefix' => 'license',
    'route_middleware' => ['web', 'auth'],

    // Who may open the license page. Define this gate in your AppServiceProvider, e.g.
    //   Gate::define('manage-licentra', fn ($user) => $user->is_admin);
    // Until you do, the page is closed to everyone (safe default).
    'gate' => 'manage-licentra',

    // Where the `licentra` middleware sends admins when the license isn't usable.
    'redirect_route' => 'licentra.activate',

    // One-click updates from /{route_prefix}/update (or `php artisan licentra:update`).
    'update' => [
        // Downloads, unpacked release and the backup of replaced files.
        'work_path' => storage_path('app/licentra-update'),

        // Never written by an update, even if the release zip contains them.
        'preserve' => ['.env', 'storage/*', 'bootstrap/cache/*', 'public/storage', 'public/hot'],

        // JSON files merged instead of replaced: new keys are added, the site's own values win.
        // e.g. ['lang/*.json'] when admins can edit translations.
        'merge_json' => [],

        // PHP files returning an array, deep-merged the same way, e.g. ['lang/*/*.php'].
        'merge_php' => [],

        // Maintenance mode, migrations and cache clearing. Extend it to add a database backup.
        'hooks' => \Pacific\Licentra\Laravel\LaravelUpdateHooks::class,
    ],
];

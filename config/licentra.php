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

    'server_url' => 'https://licentra.ishalabs.com',

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
];

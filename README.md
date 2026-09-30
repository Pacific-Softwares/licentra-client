# pacific/licentra-client

License activation for Isha Labs products (Quizora, Slotara, Matterly, ...). Verifies Envato
purchase codes through the Licentra server and keeps a signed license token that is checked
**offline** on every request. No Envato token or secret ever ships in a product.

- PHP 8.1+, `ext-sodium` (bundled with PHP). No Guzzle; no dependency conflicts.
- Laravel auto-discovery: middleware, activation page, daily heartbeat.
- Works in plain PHP too.

## Laravel: adding it to a product (5 minutes)

```bash
composer require pacific/licentra-client
php artisan vendor:publish --tag=licentra-config
```

In the product's `config/licentra.php`, hardcode the product's identity. These are deliberately
not read from `.env`, so a buyer can't point the product at another server or key:

```php
'product'         => 'quizora',
'public_key'      => 'BASE64_PUBLIC_KEY_FROM_licentra:keys',
'product_version' => trim(file_get_contents(base_path('VERSION'))),
```

Protect **admin** routes only; the public site keeps working even when unlicensed:

```php
Route::middleware(['auth', 'licentra'])->prefix('admin')->group(function () { /* ... */ });
```

Say who may manage the license (the page is closed to everyone until you do):

```php
// AppServiceProvider::boot()
Gate::define('manage-licentra', fn ($user) => $user->is_admin);
```

Unlicensed admins are redirected to the built-in page at `/license` (enter code, view status,
release domain). To use your own installer step instead:

```php
use Pacific\Licentra\Exceptions\{ActivationFailed, ServerUnreachable};
use Pacific\Licentra\Laravel\Facades\Licentra;

try {
    $state = Licentra::activate($request->purchase_code, $request->email);
} catch (ActivationFailed $e) {
    // $e->getMessage() is buyer-friendly; $e->errorCode e.g. 'activation_limit_reached'
} catch (ServerUnreachable $e) {
    // "Could not reach the license server, try again"
}
```

Optional admin banner (update available / support ending):

```blade
@include('licentra::banner')
```

Anything else you need:

```php
$state = Licentra::state();          // offline, cheap
$state->status;                      // Status::Valid|Pending|Missing|Expired|Invalid|DomainMismatch|Revoked|Blocked|Deactivated
$state->isUsable();                  // Valid or Pending
$state->message();                   // text to show the admin
$state->buyer; $state->email;        // Envato username, contact email given at activation
$state->supportedUntil; $state->supportEndingSoon(30); $state->renewUrl;
$state->updateAvailable(); $state->update['version'];
```

The heartbeat runs daily through the scheduler, and also after the response on admin requests,
so buyers who never set up cron are still covered.

## Plain PHP

```php
$licentra = new Pacific\Licentra\Licentra(new Pacific\Licentra\Config(
    product: 'quizora',
    publicKey: 'BASE64_PUBLIC_KEY',
    storagePath: __DIR__ . '/storage/licentra.json',
    appUrl: 'https://' . $_SERVER['HTTP_HOST'],
    productVersion: '1.4.0',
));

if (! $licentra->isValid()) { /* show activation form, call $licentra->activate($code, $email) */ }
$licentra->heartbeat(); // once a day is plenty; it no-ops if called more often
```

## Behaviour

| Situation | Result |
|---|---|
| License server down | Keeps working on the stored token (valid ~30 days, refreshed daily) |
| Envato down during activation | Activates as `pending`; confirmed in the background |
| Site copied to another live domain | `DomainMismatch`: admin asks to activate for the new domain |
| Site copied to localhost / `*.test` / private IP | Works offline; activating it uses a free dev slot (max 3), never a production slot |
| Site copied to `staging.yourdomain.com` | Asks to activate again; free next to the licensed `yourdomain.com` |
| Site or token copied to someone else's `staging.*` / `dev.*` | Needs its own license, like any public domain |
| `APP_URL=localhost` but served on a real domain | The real Host is what's licensed |
| Refund / blocked by author | Admin locks at next heartbeat; public site unaffected |

## What gets sent

On activation: the purchase code and the contact email the admin enters (Envato doesn't share
buyer emails; it's used for license and update notices). On activation and the daily heartbeat:
domain, app URL, product version, PHP and Laravel versions, and the server's IP (seen by the server).
Nothing about your product's users. Say so in your product docs.

## Tests

```bash
composer install && vendor/bin/phpunit
```

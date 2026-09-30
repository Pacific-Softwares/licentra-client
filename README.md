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

## One-click updates

Admins update from `/license/update` (or `php artisan licentra:update`). Each release is signed
on **your** machine, so even a compromised license server can't push code to buyers' sites.

**Once per author** (the key signs releases of all your products; back it up):

```bash
vendor/bin/licentra-release keygen          # ~/.config/licentra/release.key, prints the public key
echo "TOKEN" > ~/.config/licentra/upload-token   # LICENTRA_UPLOAD_TOKEN from the license server's .env
```

**Once per product**, in `config/licentra.php`:

```php
'release_public_key' => 'PUBLIC_KEY_FROM_KEYGEN',
'update' => [
    'work_path' => storage_path('app/licentra-update'),
    'preserve' => ['.env', 'storage/*', 'bootstrap/cache/*', 'public/storage', 'public/hot'],
    'merge_json' => ['lang/*.json'],     // translations admins edit: new keys added, theirs kept
    'merge_php' => ['lang/*/*.php'],     // same for PHP array files (deep merge)
    'hooks' => \App\Support\UpdateHooks::class, // optional: extend LaravelUpdateHooks, e.g. DB backup
],
```

**Every release**, from your build script:

```bash
vendor/bin/licentra-release manifest build/myapp --version=1.8.0   # before zipping
vendor/bin/licentra-release upload dist/myapp-1.8.0.zip --product=myapp --version=1.8.0 --changelog=CHANGES.txt
```

Then click **Publish** in the license server's **Releases** page. Installs see it at their next daily
check. How an update runs:

1. Download (only for licensed installs), then check SHA-256 and your signature
2. Work out what changes from the manifests: only changed files are written, files a release
   dropped are deleted (unless the buyer edited them), `preserve` paths are never touched
3. Back up every file that will change, check they're all writable and there's disk space
4. Maintenance mode on (the admin keeps a bypass cookie), swap files, `opcache_reset()`
5. Next request, with the new code loaded: `migrate --force`, `optimize:clear`, `queue:restart`, `up`.
   This also runs automatically on the next request if the admin closed the tab

Any failure while swapping files or migrating puts the old files back and leaves maintenance mode.
Updates require a valid license, never active support: Envato buyers get updates for life.

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

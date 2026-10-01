<?php

namespace Pacific\Licentra\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Pacific\Licentra\Exceptions\ActivationFailed;
use Pacific\Licentra\Exceptions\LicentraException;
use Pacific\Licentra\Exceptions\ServerUnreachable;
use Pacific\Licentra\Laravel\LicentraServiceProvider;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Modules\CrashGuard;
use Pacific\Licentra\Modules\Laravel\ModuleLoader;
use Pacific\Licentra\Modules\ModuleException;
use Pacific\Licentra\Modules\ModuleInstaller;
use Pacific\Licentra\Modules\ModuleLicenses;
use Pacific\Licentra\Modules\Registry;

/**
 * Admin > Modules. Installs run step by step from the page's script (start → step … → done),
 * like the Update page. Every change asks for the admin's password again: installing a module
 * runs new code for the whole site (and every tenant).
 */
class ModulesController extends Controller
{
    public function show(Registry $registry, ModuleLoader $loader, ModuleLicenses $licenses, ModuleInstaller $installer, Licentra $licentra)
    {
        $installed = [];
        foreach ($registry->all() as $slug => $module) {
            $problem = $licenses->problem($module);
            $installed[$slug] = $module + [
                'running' => $loader->isLoaded($slug),
                'note' => $module['reason'] ?? $problem ?? ($loader->skipped()[$slug] ?? null),
                'warning' => $licenses->warning($module),
                'license_problem' => $problem,
            ];
        }
        foreach ($loader->loaded() as $slug => $provider) {
            $installed[$slug] ??= ['slug' => $slug, 'name' => $slug, 'version' => 'dev', 'status' => 'dev', 'dev' => true, 'running' => true, 'note' => 'Unsigned module from modules-dev/ (developer mode).'];
        }

        $available = [];
        $storeError = null;
        if ($licentra->state()->isUsable()) {
            try {
                $available = array_values(array_filter($licentra->addons(), fn (array $a) => !isset($installed[$a['slug'] ?? ''])));
            } catch (LicentraException) {
                $storeError = 'The add-on list could not be loaded right now. Installed modules are not affected.';
            }
        }

        return view('licentra::modules', [
            'installed' => $installed,
            'available' => $available,
            'storeError' => $storeError,
            'licensed' => $licentra->state()->isUsable(),
            'status' => $installer->status(),
            'config' => LicentraServiceProvider::modules(),
        ]);
    }

    public function start(Request $request, ModuleInstaller $installer): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/'],
            'purchase_code' => ['nullable', 'string', 'max:64'],
            'password' => ['required', 'string'],
        ]);
        if ($error = $this->wrongPassword($request)) {
            return response()->json(['step' => 'failed', 'error' => $error], 422);
        }

        try {
            return response()->json($installer->start($data['slug'], $data['purchase_code'] ?? null));
        } catch (ServerUnreachable) {
            return response()->json(['step' => 'failed', 'error' => 'Could not reach the license server. Try again in a few minutes.'], 503);
        } catch (ActivationFailed|ModuleException $e) {
            return response()->json(['step' => 'failed', 'error' => $e->getMessage()], 409);
        }
    }

    public function step(ModuleInstaller $installer): JsonResponse
    {
        try {
            return response()->json($installer->step());
        } catch (ModuleException $e) {
            // Another step is still running (lock held): the page waits and asks again.
            return response()->json(['step' => 'running', 'retry' => $e->reason === 'busy', 'error' => $e->getMessage()], 409);
        }
    }

    public function status(ModuleInstaller $installer): JsonResponse
    {
        return response()->json($installer->status());
    }

    public function reset(ModuleInstaller $installer)
    {
        try {
            $installer->reset();
        } catch (ModuleException $e) {
            return back()->withErrors(['modules' => $e->getMessage()]);
        }

        return back();
    }

    /** enable | disable | retry | uninstall, one installed module. */
    public function manage(Request $request, string $slug, ModuleInstaller $installer, Registry $registry, ModuleLicenses $licenses, CrashGuard $crashes)
    {
        $data = $request->validate([
            'action' => ['required', 'in:enable,disable,retry,uninstall'],
            'password' => ['required', 'string'],
            'delete_data' => ['nullable', 'boolean'],
            'confirm_slug' => ['nullable', 'string'],
        ]);
        if ($error = $this->wrongPassword($request)) {
            return back()->withErrors(['modules' => $error]);
        }
        $deleteData = (bool) ($data['delete_data'] ?? false);
        if ($data['action'] === 'uninstall' && $deleteData && ($data['confirm_slug'] ?? '') !== $slug) {
            return back()->withErrors(['modules' => "To delete the module's data, type {$slug} to confirm."]);
        }

        try {
            $module = $registry->get($slug) ?? throw ModuleException::notInstalled($slug);
            match ($data['action']) {
                'enable' => $installer->enable($slug, $licenses->problem($module)),
                'disable' => $installer->disable($slug),
                'retry' => $installer->retryMigrations($slug),
                'uninstall' => $installer->uninstall($slug, $deleteData),
            };
            if (in_array($data['action'], ['enable', 'retry'], true)) {
                $crashes->reset($slug);
            }
        } catch (LicentraException $e) {
            return back()->withErrors(['modules' => $e->getMessage()]);
        }

        $done = ['enable' => 'switched on', 'disable' => 'switched off', 'retry' => 'database updated and switched on', 'uninstall' => $deleteData ? 'removed with its data' : 'removed (its data is kept)'];

        return back()->with('licentra_modules', "{$module['name']}: {$done[$data['action']]}.");
    }

    private function wrongPassword(Request $request): ?string
    {
        $user = $request->user();
        if (!$user || !Hash::check((string) $request->input('password'), (string) $user->getAuthPassword())) {
            return 'That password is not correct.';
        }

        return null;
    }
}

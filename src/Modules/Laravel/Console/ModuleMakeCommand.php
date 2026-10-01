<?php

namespace Pacific\Licentra\Modules\Laravel\Console;

use Illuminate\Support\Str;
use Pacific\Licentra\Laravel\LicentraServiceProvider;
use Pacific\Licentra\Modules\ModuleManifest;

/**
 * Scaffold a working module in modules-dev/{product}-{name}/ (loaded when LICENTRA_MODULES_DEV=true):
 * module.json, a provider, a gated route + view, and a migration. Package and sign it with
 * `vendor/bin/licentra-release module modules-dev/{slug}` when it's ready to ship.
 */
class ModuleMakeCommand extends ModuleCommand
{
    protected $signature = 'module:make {name : e.g. "whatsapp" → slotara-whatsapp} {--force : overwrite an existing folder}';

    protected $description = 'Create a new add-on module skeleton in modules-dev/';

    public function handle(): int
    {
        $product = (string) config('licentra.product');
        $name = Str::slug((string) $this->argument('name'));
        if ($product === '' || $name === '') {
            $this->error('Set licentra.product and pass a module name.');

            return self::FAILURE;
        }
        $slug = str_starts_with($name, $product . '-') ? $name : "{$product}-{$name}";
        $studly = Str::studly($slug);
        $namespace = "Modules\\{$studly}\\";
        $dir = rtrim(LicentraServiceProvider::modules()['dev_path'], '/') . '/' . $slug;

        if (is_dir($dir) && !$this->option('force')) {
            $this->error("{$dir} already exists (use --force to overwrite).");

            return self::FAILURE;
        }

        $table = Str::snake(str_replace('-', '_', $slug)) . '_items';
        $files = [
            ModuleManifest::FILE => json_encode([
                'manifest' => ModuleManifest::FORMAT,
                'slug' => $slug,
                'name' => Str::headline($name),
                'description' => '',
                'version' => '0.1.0',
                'product' => $product,
                'requires' => ['product' => '^' . preg_replace('/^(\d+\.\d+).*/', '$1', (string) config('licentra.product_version')), 'licentra' => '^1.5'],
                'namespace' => $namespace,
                'provider' => $namespace . 'ModuleServiceProvider',
                'envato_item_id' => null,
                'plan_feature' => true,
                'capabilities' => ['tables' => [$table], 'outbound' => [], 'routes' => ["GET /{$slug}"], 'schedule' => false],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'src/ModuleServiceProvider.php' => <<<PHP
                <?php

                namespace Modules\\{$studly};

                use Pacific\\Licentra\\Modules\\Laravel\\ModuleServiceProvider as BaseProvider;

                class ModuleServiceProvider extends BaseProvider
                {
                    public function register(): void
                    {
                        // Container bindings only. Use the product's documented extension points
                        // (docs/modules.md), not its internals: those may change in any update.
                    }

                    public function bootModule(): void
                    {
                        \$this->loadModuleResources();   // views as "{$slug}::...", translations
                        \$this->loadModuleRoutes();      // routes/web.php under /{$slug}, gated per plan

                        // Hook into the product safely (a throwing callback never breaks it), e.g.:
                        // \$this->observe(\\App\\Models\\SomeModel::class, 'created', fn (\$model) => ...);
                        // \$this->schedule(fn (\$s) => \$s->call(fn () => ...)->daily());
                    }

                    /** Filament plugins per panel id, e.g. ['admin' => [...], 'tenant' => [...]][\$panel]. */
                    public function filamentPlugins(string \$panel): array
                    {
                        return [];
                    }
                }

                PHP,
            'routes/web.php' => <<<PHP
                <?php

                use Illuminate\\Support\\Facades\\Route;

                // Served at /{$slug} and named "{$slug}.index": loadModuleRoutes() adds the
                // /{$slug} prefix and the "{$slug}." name prefix to everything in this file.
                Route::get('/', fn () => view('{$slug}::index'))->name('index');

                PHP,
            'resources/views/index.blade.php' => "<h1>" . Str::headline($name) . "</h1>\n<p>The {$slug} module is running.</p>\n",
            'database/migrations/' . date('Y_m_d_His') . "_create_{$table}_table.php" => <<<PHP
                <?php

                use Illuminate\\Database\\Migrations\\Migration;
                use Illuminate\\Database\\Schema\\Blueprint;
                use Illuminate\\Support\\Facades\\Schema;

                return new class extends Migration
                {
                    public function up(): void
                    {
                        Schema::create('{$table}', function (Blueprint \$table) {
                            \$table->id();
                            \$table->string('name');
                            \$table->timestamps();
                        });
                    }

                    public function down(): void
                    {
                        Schema::dropIfExists('{$table}');
                    }
                };

                PHP,
        ];

        foreach ($files as $path => $content) {
            $file = $dir . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, $content);
        }

        $this->info("Created {$dir}");
        $this->line('  1. Set LICENTRA_MODULES_DEV=true in .env (developer mode loads modules-dev/).');
        $this->line("  2. php artisan module:migrate {$slug}");
        $this->line("  3. Open /{$slug}");
        $this->line("  4. Ship it: vendor/bin/licentra-release module {$dir} --upload");

        return self::SUCCESS;
    }
}

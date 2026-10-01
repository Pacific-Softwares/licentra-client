<?php

namespace Pacific\Licentra\Tests;

use Pacific\Licentra\Modules\CrashGuard;
use PHPUnit\Framework\TestCase;

final class CrashGuardTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/licentra-cg-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/modules/slotara-hello/src', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    public function test_fatals_are_attributed_to_the_module_folder_they_happened_in(): void
    {
        $guard = new CrashGuard($this->tmp . '/crashes');
        $guard->watch(['slotara-hello' => $this->tmp . '/modules/slotara-hello'], fn () => null);

        $this->assertSame('slotara-hello', $guard->attribute($this->tmp . '/modules/slotara-hello/src/Thing.php'));
        $this->assertNull($guard->attribute($this->tmp . '/modules/slotara-hello-two/src/Thing.php'));
        $this->assertNull($guard->attribute('/var/www/app/Http/Kernel.php'));
    }

    public function test_three_crashes_within_the_window_trip_the_module(): void
    {
        $tripped = [];
        $guard = new CrashGuard($this->tmp . '/crashes');
        $guard->watch(['slotara-hello' => $this->tmp . '/modules/slotara-hello'], function ($slug, $msg) use (&$tripped) {
            $tripped[] = $slug;
        });
        $t = 1_000_000;

        $this->assertFalse($guard->record('slotara-hello', 'boom', $t));
        $this->assertFalse($guard->record('slotara-hello', 'boom', $t + CrashGuard::WINDOW + 1)); // first one aged out
        $this->assertFalse($guard->record('slotara-hello', 'boom', $t + CrashGuard::WINDOW + 2));
        $this->assertTrue($guard->record('slotara-hello', 'boom', $t + CrashGuard::WINDOW + 3));

        $this->assertSame(['slotara-hello'], $tripped);
        $this->assertTrue($guard->tripped('slotara-hello'));
        $guard->reset('slotara-hello');
        $this->assertFalse($guard->tripped('slotara-hello'));
    }

    /** The real thing: a PHP fatal in a module file, in a separate process, is caught at shutdown. */
    public function test_a_real_fatal_in_a_module_is_counted_by_the_shutdown_handler(): void
    {
        file_put_contents($this->tmp . '/modules/slotara-hello/src/Broken.php', '<?php function licentra_fx_dup() {} function licentra_fx_dup() {}');
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $script = $this->tmp . '/run.php';
        file_put_contents($script, '<?php
            require ' . var_export($autoload, true) . ';
            $guard = new Pacific\Licentra\Modules\CrashGuard(' . var_export($this->tmp . '/crashes', true) . ');
            $guard->watch(["slotara-hello" => ' . var_export($this->tmp . '/modules/slotara-hello', true) . '], function ($slug, $msg) {
                file_put_contents(' . var_export($this->tmp . '/tripped', true) . ', $slug);
            });
            require ' . var_export($this->tmp . '/modules/slotara-hello/src/Broken.php', true) . ';
        ');

        for ($i = 0; $i < CrashGuard::LIMIT; $i++) {
            exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg($script) . ' 2>&1', $out, $code);
            $this->assertNotSame(0, $code, 'the module should have caused a fatal');
        }

        $this->assertSame('slotara-hello', @file_get_contents($this->tmp . '/tripped'));
    }
}

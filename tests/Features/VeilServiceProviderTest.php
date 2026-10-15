<?php

namespace SignDeck\Veil\Tests\Features;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignDeck\Veil\Commands\VeilExportCommand;
use SignDeck\Veil\Commands\VeilMakeTableCommand;
use SignDeck\Veil\Veil;
use SignDeck\Veil\VeilDryRun;
use SignDeck\Veil\VeilServiceProvider;

class VeilServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [VeilServiceProvider::class];
    }

    #[Test]
    public function it_registers_the_package_commands(): void
    {
        $commands = Artisan::all();

        $this->assertInstanceOf(VeilMakeTableCommand::class, $commands['veil:make-table']);
        $this->assertInstanceOf(VeilExportCommand::class, $commands['veil:export']);
    }

    #[Test]
    public function it_resolves_export_services_from_the_container(): void
    {
        $this->assertInstanceOf(Veil::class, $this->app->make(Veil::class));
        $this->assertInstanceOf(VeilDryRun::class, $this->app->make(VeilDryRun::class));
    }

    #[Test]
    public function it_loads_the_default_configuration_without_test_case_overrides(): void
    {
        $this->assertSame([
            'tables' => [],
            'disk' => 'local',
            'compress' => false,
            'connection' => null,
        ], config('veil'));
    }

    #[Test]
    public function it_preserves_application_configuration_when_merging_defaults(): void
    {
        config(['veil' => [
            'tables' => ['App\\Veil\\ApplicationUsersTable'],
            'disk' => 'snapshots',
            'compress' => true,
            'connection' => 'reporting',
        ]]);

        (new VeilServiceProvider($this->app))->register();

        $this->assertSame([
            'tables' => ['App\\Veil\\ApplicationUsersTable'],
            'disk' => 'snapshots',
            'compress' => true,
            'connection' => 'reporting',
        ], config('veil'));
    }

    #[Test]
    public function it_fills_missing_configuration_keys_without_replacing_overrides(): void
    {
        config(['veil' => ['disk' => 'snapshots']]);

        (new VeilServiceProvider($this->app))->register();

        $this->assertSame([
            'tables' => [],
            'disk' => 'snapshots',
            'compress' => false,
            'connection' => null,
        ], config('veil'));
    }

    #[Test]
    public function it_publishes_the_package_configuration_under_the_veil_config_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(VeilServiceProvider::class, 'veil-config');

        $this->assertCount(1, $paths);
        $source = array_key_first($paths);
        $this->assertSame(realpath(__DIR__ . '/../../config/veil.php'), realpath($source));
        $this->assertSame(config_path('veil.php'), $paths[$source]);
        $this->assertSame([], ServiceProvider::pathsToPublish(VeilServiceProvider::class, 'unrelated-tag'));
    }
}

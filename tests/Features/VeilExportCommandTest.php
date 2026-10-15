<?php

namespace SignDeck\Veil\Tests\Features;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Tests\TestCase;
use SignDeck\Veil\Tests\Tables\VeilUsersTable;

class VeilExportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('veil');
    }

    #[Test]
    public function it_shows_warning_when_no_tables_configured(): void
    {
        $this->artisan('veil:export')
            ->expectsOutput('No tables configured in veil.tables. Nothing to export.')
            ->assertSuccessful();
    }

    #[Test]
    #[DataProvider('invalidTableConfigurations')]
    public function it_reports_invalid_table_configuration_without_creating_files(string $tableClass, string $message, bool $dryRun): void
    {
        config(['veil.tables' => [$tableClass]]);

        $this->artisan('veil:export', ['--dry-run' => $dryRun])
            ->expectsOutput('Export failed: ' . $message)
            ->assertFailed();

        $this->assertSame([], Storage::disk('veil')->allFiles());
    }

    public static function invalidTableConfigurations(): array
    {
        $missingClass = 'App\\Veil\\MissingVeilTable';
        $cases = [];

        foreach ([false, true] as $dryRun) {
            $mode = $dryRun ? 'preview' : 'export';
            $cases["invalid contract {$mode}"] = [
                \stdClass::class,
                'stdClass must implement ' . VeilTable::class . ' interface.',
                $dryRun,
            ];
            $cases["missing class {$mode}"] = [
                $missingClass,
                "Target class [{$missingClass}] does not exist.",
                $dryRun,
            ];
        }

        return $cases;
    }

    #[Test]
    public function it_displays_tables_being_exported(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export')
            ->expectsOutput('Starting veiled export...')
            ->expectsOutput('Tables to export:')
            ->assertSuccessful();
    }

    #[Test]
    public function it_creates_export_file_on_configured_disk(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export')
            ->assertSuccessful();

        $files = Storage::disk('veil')->files();

        $this->assertNotEmpty($files);
        $this->assertStringStartsWith('veil_', $files[0]);
        $this->assertStringEndsWith('.sql', $files[0]);
    }

    #[Test]
    public function it_shows_success_message_with_file_info(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export')
            ->expectsOutputToContain('Export completed successfully!')
            ->assertSuccessful();
    }

    #[Test]
    public function it_uses_custom_name_when_provided(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export', ['--name' => 'staging-export'])
            ->assertSuccessful();

        $files = Storage::disk('veil')->files();

        $this->assertSame(['staging-export.sql'], $files);
    }

    #[Test]
    public function it_uses_timestamped_name_when_no_custom_name_provided(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);
        $this->seedUsers();

        $this->travelTo(Carbon::parse('2026-01-02 03:04:05'), function () {
            $this->artisan('veil:export')->assertSuccessful();

            $this->assertSame(
                ['veil_2026-01-02_03-04-05.sql'],
                Storage::disk('veil')->files()
            );
        });
    }

    #[Test]
    public function it_shows_preview_in_dry_run_mode(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export', ['--dry-run' => true])
            ->expectsOutput('🔍 DRY RUN MODE - No files will be created')
            ->expectsOutputToContain('Preview:')
            ->expectsOutputToContain('Table:')
            ->assertSuccessful();

        // Verify no file was created
        $files = Storage::disk('veil')->files();
        $this->assertEmpty($files);
    }

    #[Test]
    public function it_shows_columns_and_row_count_in_dry_run(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers(5);

        $this->artisan('veil:export', ['--dry-run' => true])
            ->expectsOutputToContain('Columns to export:')
            ->expectsOutputToContain('Estimated rows:')
            ->assertSuccessful();
    }

    #[Test]
    public function it_shows_progress_during_export(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export')
            ->expectsOutput('Exporting and anonymizing data...')
            ->assertSuccessful();
    }
}


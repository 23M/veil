<?php

// Invoked only by smoke-laravel13.sh against its disposable application.
declare(strict_types=1);

use App\Veil\SmokeExportTable;
use App\Veil\VeilSmokeRecordsTable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\VeilServiceProvider;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function readJson(string $path): array
{
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$mode = $argv[1] ?? '';
$appPath = $argv[2] ?? '';
check(is_file($appPath . '/artisan'), 'Expected a disposable Laravel application.');

if ($mode === 'configure') {
    $packagePath = realpath($argv[3] ?? '');
    check($packagePath !== false, 'Package path does not exist.');
    $package = readJson($packagePath . '/composer.json');
    check(($package['name'] ?? null) === 'signdeck/veil', 'Expected package signdeck/veil.');
    $composerPath = $appPath . '/composer.json';
    $composer = readJson($composerPath);
    $composer['repositories'] = [
        [
            'type' => 'path',
            'url' => $packagePath,
            'options' => [
                'symlink' => true,
                'versions' => ['signdeck/veil' => 'dev-smoke'],
            ],
        ],
    ];
    $composer['require']['signdeck/veil'] = 'dev-smoke';
    check(file_put_contents($composerPath, json_encode(
        $composer,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . "\n") !== false, 'Could not configure application Composer manifest.');
    exit(0);
}

require $appPath . '/vendor/autoload.php';
$app = require $appPath . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
check(explode('.', $app->version())[0] === '13', 'Application is not Laravel 13.');

switch ($mode) {
    case 'discovery':
        $manifest = require $appPath . '/bootstrap/cache/packages.php';
        check(in_array(VeilServiceProvider::class, $manifest['signdeck/veil']['providers'] ?? [], true),
            'Veil provider missing from automatic package discovery manifest.');
        check($app->getProvider(VeilServiceProvider::class) !== null, 'Discovered provider was not loaded.');
        $commands = Artisan::all();
        foreach (['veil:export', 'veil:make-table'] as $command) {
            check(isset($commands[$command]), "Missing Artisan command: {$command}");
        }
        $installedPath = $appPath . '/vendor/signdeck/veil';
        check(is_link($installedPath), 'Local package was not installed as a symlink.');
        check(realpath($installedPath) === realpath($argv[3]), 'Symlink does not point to PACKAGE_PATH.');
        echo "Discovery, commands, and local package symlink verified.\n";
        break;

    case 'prepare':
        // Keep the generated class untouched: linting alone would not catch a bad namespace/interface.
        check(class_exists(VeilSmokeRecordsTable::class), 'Generated class is not autoloadable.');
        $generated = $app->make(VeilSmokeRecordsTable::class);
        check($generated instanceof VeilTable, 'Generated class does not implement VeilTable.');
        check($generated->table() === 'smoke_records', 'Generated class has the wrong table name.');
        check(array_keys($generated->columns()) === ['id', 'email', 'name'], 'Unexpected generated columns.');
        check($generated->query() === null, 'Unexpected generated query.');
        check(copy(__DIR__ . '/smoke-laravel13-table.php', $appPath . '/app/Veil/SmokeExportTable.php'),
            'Could not install deterministic export fixture.');

        // Publish is checked separately before replacing only the disposable app's configuration.
        $config = [
            'tables' => [SmokeExportTable::class],
            'disk' => 'local',
            'compress' => false,
            'connection' => null,
        ];
        check(file_put_contents($appPath . '/config/veil.php', "<?php\n\nreturn " . var_export($config, true) . ";\n") !== false,
            'Could not configure export fixture.');
        check(config('database.default') === 'sqlite', 'Smoke database must use SQLite.');
        check(config('database.connections.sqlite.database') === $appPath . '/database/database.sqlite',
            'Refusing to seed a database outside the disposable app.');
        DB::statement('CREATE TABLE smoke_records (id INTEGER PRIMARY KEY, email TEXT NOT NULL, name TEXT NOT NULL, note TEXT NULL)');
        DB::table('smoke_records')->insert([
            ['id' => 1, 'email' => 'private-one@example.invalid', 'name' => 'ALPHA', 'note' => 'keep-me'],
            ['id' => 2, 'email' => 'private-two@example.invalid', 'name' => 'BETA', 'note' => null],
        ]);
        echo "Generated class verified and disposable SQLite rows seeded.\n";
        break;

    case 'verify':
        $disk = Storage::disk(config('veil.disk'));
        check($disk->exists('smoke-exact.sql'), 'Export did not create smoke-exact.sql.');
        $sql = $disk->get('smoke-exact.sql');
        $expected = "INSERT INTO `smoke_records` (`id`, `email`, `name`, `note`) VALUES "
            . "(1, 'redacted@example.invalid', 'masked-1-alpha', 'keep-me'), "
            . "(2, 'redacted@example.invalid', 'masked-2-beta', NULL);\n";
        check($sql === $expected, "Export differs from exact expected SQL. Actual:\n" . $sql);

        // Replay only into a second, in-memory database, never over the source rows.
        $replay = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $replay->exec('CREATE TABLE smoke_records (id INTEGER PRIMARY KEY, email TEXT, name TEXT, note TEXT)');
        $replay->exec($sql);
        check($replay->query('SELECT * FROM smoke_records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === [
            ['id' => 1, 'email' => 'redacted@example.invalid', 'name' => 'masked-1-alpha', 'note' => 'keep-me'],
            ['id' => 2, 'email' => 'redacted@example.invalid', 'name' => 'masked-2-beta', 'note' => null],
        ], 'Replayed export values do not match the deterministic transformations.');
        check(DB::table('smoke_records')->orderBy('id')->pluck('email')->all() === [
            'private-one@example.invalid', 'private-two@example.invalid',
        ], 'Export modified source records.');
        echo "Exact transformed SQL, replayed values, and unchanged source rows verified.\n";
        break;

    default:
        throw new InvalidArgumentException('Unknown smoke fixture mode: ' . $mode);
}

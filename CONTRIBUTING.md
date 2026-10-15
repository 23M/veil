# Local development and compatibility testing

## Requirements

- PHP 8.3 or newer. Use the same PHP executable for Composer and PHPUnit.
- Composer 2 (current stable recommended; Composer runtime API >= 2.2).
- PHP extensions: ctype, curl, dom, fileinfo, filter, hash, iconv, mbstring,
  openssl, PDO, pdo_sqlite, session, tokenizer, xml, xmlwriter, and zip; zlib for
  compressed exports. Composer checks the resolved packages' platform requirements.
- Bash and internet access to Packagist/GitHub for the application smoke test.

No MySQL server, `mysqldump`, or real application database is needed. The package
suite uses SQLite in memory and fake filesystem disks. Generated classes live in
Testbench's disposable application, not your application.

## Install and run

From the package checkout:

```bash
composer install
composer validate --strict
composer check-platform-reqs
vendor/bin/phpunit --display-all-issues --fail-on-warning --fail-on-deprecation
```

The committed lock file is resolved on PHP 8.3 with Laravel 13, Testbench 11, and
PHPUnit 12. `composer install` reproduces that environment; installing this package
in another application uses that application's lock, not this one.

To select a framework explicitly, use one of these commands (they update your
local lock file; do not commit an incidental matrix switch):

```bash
# Laravel 13 / Testbench 11 / PHPUnit 12
composer update --with='laravel/framework:^13.0' --with='orchestra/testbench:^11.0' --with='phpunit/phpunit:^12.5.8' --prefer-dist --no-interaction

# Laravel 12 / Testbench 10 / PHPUnit 11
composer update --with='laravel/framework:^12.0' --with='orchestra/testbench:^10.0' --with='phpunit/phpunit:^11.5.3' --prefer-dist --no-interaction

# Laravel 11 / Testbench 9 / PHPUnit 11 — see the security limitation below
composer update --with='laravel/framework:^11.0' --with='orchestra/testbench:^9.0' --with='phpunit/phpunit:^11.5.3' --prefer-dist --no-interaction

composer validate --strict
composer check-platform-reqs
vendor/bin/phpunit --display-all-issues --fail-on-warning --fail-on-deprecation
```

Testbench's actual Composer requirements/conflicts were checked: Testbench 9
selects Laravel 11, 10 selects Laravel 12, and 11 selects Laravel 13. PHPUnit 12
requires PHP >= 8.3; Testbench 11 supports PHPUnit >= 12.5.8 in the 12.x series.
The package permits PHPUnit 10.5/11/12 for older framework environments, while CI
explicitly selects PHPUnit 11 for Laravel 11/12 and PHPUnit 12 for Laravel 13.
No framework or PHPUnit major is inferred from the PHP version alone.

### Laravel 11 security limitation

As of 2026-10-15, Laravel 11.57.0 passes the package tests but has four reported
security advisory entries: `PKSA-d5tc-s1qs-h781`, `PKSA-m5cs-t1y6-qpcs`,
`PKSA-3r5d-mb8f-1qw9`, and `PKSA-mdq4-51ck-6kdq` (the latter two concern the same
email-validation issue). Current Composer 2.10.3 refuses to resolve Laravel 11
with its default security policy. The local compatibility run used the already
installed Composer 2.8.9, which reports advisories without blocking resolution;
this is **not** evidence that Laravel 11 is safe for production.

The CI matrix intentionally keeps current Composer security checks enabled and
will report this Laravel 11 dependency-resolution failure until upstream fixes
exist. It does not ignore advisories, downgrade Composer, or silently test Laravel
12 instead. Do not disable security blocking just to make this job green. Prefer
an up-to-date Laravel 12 or 13 application. `composer audit` reports the currently
installed environment's advisories.

## Real Laravel 13 application smoke test

```bash
bash scripts/smoke-laravel13.sh
```

The script uses `composer create-project laravel/laravel ... '^13.0'` outside the
source tree, adds a symlinked Composer path repository for this checkout, installs
Veil, checks platform requirements and automatic provider discovery, verifies
both commands, publishes config, loads a generated table class, and performs an
actual export. It checks exact transformed and unchanged values, SQL NULLs, file
output, and replay into a second in-memory SQLite database. It never seeds a
customer database. The generated application and database are deleted on exit,
including failure; normal Composer download caches remain.

Optional settings (replace example paths with your own existing paths):

```bash
PACKAGE_PATH=/absolute/path/to/veil TEMP_BASE=/tmp bash scripts/smoke-laravel13.sh
COMPOSER_BINARY=/absolute/path/to/composer.phar PHP_BINARY=/absolute/path/to/php bash scripts/smoke-laravel13.sh
bash scripts/smoke-laravel13.sh --help
```

`TEMP_BASE` must already exist outside the package checkout. If selecting another
PHP version, put its bin directory first in `PATH` as well so Composer's child
processes use the same runtime. No platform-requirement overrides are used.
The path repository assigns `dev-smoke` only to identify the local checkout; it
does not alias Laravel versions or override dependency requirements.

## Install this fork into an existing Laravel application

Add this repository to the **application's** `composer.json`, preserving any
existing repositories. Use your checkout's absolute path:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/absolute/path/to/veil",
            "options": {
                "symlink": true,
                "versions": {"signdeck/veil": "dev-local"}
            }
        }
    ]
}
```

Then, from that application:

```bash
composer require signdeck/veil:dev-local --with-all-dependencies
php artisan list veil
php artisan vendor:publish --tag=veil-config
```

The explicit path-repository version avoids depending on your local Git branch
name. It does not change other dependency constraints. Composer discovers the
provider automatically; no manual provider registration is necessary. Symlinking
means source edits are immediately visible to the application. Use only a local
application with disposable data when trying exports.

## MySQL/MariaDB integration tests

No separate MySQL integration suite/job was added in this compatibility phase;
there is no MySQL PHPUnit command yet. SQLite covers the exercised registration,
commands, query scopes, anonymization, SQL output, events, and filesystem paths.
MySQL/MariaDB-specific serialization and import behavior are **not verified** by
these checks. Do not point the existing suite at a real database: its base test
case deliberately forces SQLite in memory. A future MySQL suite should use a
separate test configuration and disposable service container/database, not change
those defaults or connect to a development/staging/production database.

## Verification record (2026-10-15)

Every row below ran all 66 tests / 175 assertions successfully, with warnings and
deprecations configured to fail:

| Laravel | PHP | Testbench | PHPUnit |
|---------|-----|-----------|---------|
| 11.57.0 | 8.3.21 | 9.18.0 | 11.5.57 |
| 12.70.0 | 8.3.21 | 10.12.0 | 11.5.57 |
| 13.36.0 | 8.3.21 | 11.3.0 | 12.5.39 |
| 13.36.0 | 8.4.25 | 11.3.0 | 12.5.39 |
| 13.36.0 | 8.5.10 | 11.3.0 | 12.5.39 |

The Laravel 13 application smoke test passed on PHP 8.3 with both Composer 2.8.9
and current Composer 2.10.3 (Laravel skeleton 13.11.0 / framework 13.36.0).
Composer validation and platform checks passed. Laravel 12/13 dependency updates
reported no security advisories. Current Composer's Laravel 11 security-resolution
failure was reproduced separately without modifying the lock file.

The original baseline was 52 tests / 144 assertions on Laravel 11 / PHPUnit 10.
Its two PHP 8.5 deprecations originated in Testbench's database configuration, not
Veil. The expanded Laravel 13 suite emitted no deprecations. The local PHP 8.4
installation emitted a startup warning about a stale OPcache extension path;
PHPUnit itself passed. No Xdebug/PCOV driver was available, so no line-coverage
percentage is claimed. GitHub-hosted Actions have not been executed locally.

The Laravel 13 upgrade guide was reviewed, including container nullable defaults,
query changes, collection serialization, and string factories. None required
production API changes for the paths Veil uses. The engine, SQL formatting,
policies, and export public API are unchanged; these tests do not establish that
arbitrary schemas/data are safe to anonymize or import in production.

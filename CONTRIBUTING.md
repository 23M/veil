# Local development and compatibility testing

## Requirements

- PHP 8.3 or newer. Use the same PHP executable for Composer and PHPUnit.
- Composer 2 (current stable recommended; Composer runtime API >= 2.2).
- PHP extensions: ctype, curl, dom, fileinfo, filter, hash, iconv, mbstring,
  openssl, PDO, pdo_sqlite, session, tokenizer, xml, xmlwriter, and zip; zlib for
  compressed exports. Composer checks the resolved packages' platform requirements.
- Internet access to Packagist/GitHub when installing dependencies.

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
local lock file; do not commit an incidental framework switch):

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
The package permits PHPUnit 10.5/11/12 for older framework environments.

Routine development uses the PHPUnit suite. The GitHub workflow tests PHP 8.3
and 8.4 using Composer's dependency selection; it does not separately enforce
each supported Laravel major. Use the commands above when checking a specific
framework version, especially before a release.

### Laravel 11 security limitation

As of 2026-10-15, Laravel 11.57.0 passes the package tests but has four reported
security advisory entries: `PKSA-d5tc-s1qs-h781`, `PKSA-m5cs-t1y6-qpcs`,
`PKSA-3r5d-mb8f-1qw9`, and `PKSA-mdq4-51ck-6kdq` (the latter two concern the same
email-validation issue). Current Composer 2.10.3 refuses to resolve Laravel 11
with its default security policy. The local compatibility run used the already
installed Composer 2.8.9, which reports advisories without blocking resolution;
this is **not** evidence that Laravel 11 is safe for production.

Prefer an up-to-date Laravel 12 or 13 application. `composer audit` reports the
currently installed environment's advisories. The restored original GitHub
workflow disables advisory blocking during dependency updates; a passing CI run
is therefore not a clean security audit.


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

A one-time fresh Laravel 13 application check passed on PHP 8.3 with both Composer
2.8.9 and Composer 2.10.3 (Laravel skeleton 13.11.0 / framework 13.36.0). It verified
local installation, discovery, commands, config publishing, class generation, and
an exact-value SQLite export. The temporary smoke-test tooling was subsequently
removed; this is a historical verification record, not an ongoing CI check.
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

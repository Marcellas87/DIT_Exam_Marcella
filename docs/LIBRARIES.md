# Libraries and pre-existing components

> Versions below are the ones installed on 22 November 2026 (`composer show`).
> They are frozen in `composer.lock`, so `composer install` gives the assessor the same versions.

## 1. Third-party libraries (installed with Composer)

| Name | Type | Version | License | Why it is used | Where | Docs |
|---|---|---|---|---|---|---|
| `vlucas/phpdotenv` | runtime | 5.8.0 | BSD-3-Clause | Reads database settings from a `.env` file, so the password is not written in the code or committed to GitHub. | `config.php` | https://github.com/vlucas/phpdotenv |
| `phpunit/phpunit` | development only | 12.5.45 | BSD-3-Clause | The standard unit-testing framework for PHP: test classes, assertions, expected exceptions. | `tests/phpunit/`, `phpunit.xml` | https://docs.phpunit.de |

Before using a library, its license was checked on packagist.org (both allow free use in this project).
PHPUnit is a development dependency (`require-dev`): it is not needed to run the app.
PHPUnit 12 needs **PHP 8.3 or newer**. On XAMPP with PHP 8.2, run `composer install --no-dev` to install only phpdotenv,
and run the PHPUnit tests with a PHP 8.3 command line.

Sub-libraries: phpdotenv brings its own dependencies, installed automatically by Composer:
`graham-campbell/result-type`, `phpoption/phpoption`, `symfony/polyfill-ctype`, `symfony/polyfill-mbstring`
and `symfony/polyfill-php80`. The project does not use them directly. `composer show --tree` lists the full tree.

### How they are managed
- `composer.json` lists what the project needs and the allowed version ranges.
- `composer.lock` freezes the exact versions (committed to GitHub, so the assessor gets the same versions).
- `vendor/` holds the downloaded code and is **not** committed (`.gitignore`); `composer install` recreates it.
- phpdotenv is optional at run time: if `vendor/` or `.env` is missing, `config.php` uses safe local defaults (XAMPP: user `root`, empty password).

### Commands (for the report)
    composer install              # install exactly what composer.lock says (PHP 8.3+)
    composer install --no-dev     # only the runtime library (works on PHP 8.2)
    composer show                 # list installed packages and versions
    composer show --tree          # dependencies of dependencies (sub-libraries)
    composer outdated             # which packages have newer versions
    composer update               # update within the ranges of composer.json, then re-run all tests
    composer audit                # check installed versions for known security problems
    vendor/bin/phpunit            # run the PHPUnit tests

### Update plan
1. Run `composer outdated` and `composer audit` regularly (for example before each release).
2. Run `composer update`, then run every test (`vendor/bin/phpunit` and the `php tests/...` runners).
3. Commit the new `composer.lock` only if all tests pass.

## 2. Pre-existing components that are part of PHP itself (no installation)

| Component | Used for |
|---|---|
| PDO + `pdo_mysql` | Database access with prepared statements (`src/Database.php` and the services). |
| `password_hash()` / `password_verify()` | Safe password storage (bcrypt). |
| Sessions (`session_start`, `session_regenerate_id`) | Login state. |
| `random_bytes()` / `hash_equals()` | CSRF tokens and random upload file names. |
| `getimagesize()` | Checks that an uploaded file really is a JPG or PNG image. |
| `DateTimeImmutable` | Date calculations (end date, extensions). |
| GD (`imagecreatetruecolor`, `imagepng`, `imagejpeg`) | Only in the tests: makes small real images to upload. |

## 3. Tools (not libraries)
XAMPP, phpMyAdmin, dbdiagram.io & draw.io (diagrams), Graphviz (renders `docs/class_diagram.dot`), Git and GitHub are tools used to build and share the project.
They are not code that the application loads, so they are not part of the library list.
# Garage Rental (PHP 8 + MySQL)

A web app for renting garages (bicycle, car, electric car) by the day or by the month.
Roles: **admin** (create garages, verify tenants, extend rentals, reports) and **tenant** (register, rent a garage).

## Setup (XAMPP)
1. Copy this folder to `C:\xampp\htdocs\garage_app`.
2. Start Apache and MySQL in XAMPP.
3. In phpMyAdmin, open the **Import** tab and import `garage_rental.sql`. It creates the `garage_rental` database
   (utf8mb4_unicode_ci), all tables, the three garage types and the first admin account.
4. Make sure the folder `storage/payment_proofs/` exists (uploaded payment proofs are saved there; Apache blocks direct access, see `storage/.htaccess`).
5. Open `http://localhost/garage_app/`.

The admin password is not stored in this repository; it is given to the assessor in the report.

## Requirements
- The app itself runs on PHP 8.1 or newer (XAMPP 8.2 is fine).
- `composer install` also installs PHPUnit 12, which needs **PHP 8.3 or newer**.
  On XAMPP 8.2 use `composer install --no-dev` (installs only phpdotenv), or run the PHPUnit tests with a PHP 8.3 CLI.
  The scenario runners in `tests/` need no Composer at all.

## Project structure
The code is split by responsibility. The key idea: **services talk to the database; rules and validators do not**,
so rules can be unit tested without MySQL.

| Group | Files in `src/` | Job |
|---|---|---|
| Services (use the database, always prepared statements) | `AuthService`, `TenantService`, `GarageService`, `RentalService`, `ExtensionService`, `ReportService` | One service per feature area. Multi-step saves run in a transaction. |
| Rules and validators (pure PHP, no database) | `RegistrationValidator`, `GarageBatchValidator`, `VehicleValidator`, `RentalRules`, `ReportInput`, `GarageId` | Input checks, prices, dates, garage IDs. Unit tested. |
| Domain classes (one class per file) | `Vehicle` (abstract), `Bicycle`, `PlatedVehicle` (abstract), `Motorbike`, `Car`, `ElectricCar` | Inheritance and polymorphism: each vehicle knows which garage types it may use. Requiring `Vehicle.php` loads the whole tree. |
| Infrastructure | `Database`, `helpers`, `PaymentProofStorage` | PDO connection, escaping/session/CSRF/form-error helpers, safe image upload. |

Pages in the project root (`login.php`, `rent.php`, `admin_reports.php`, ...) only handle the request
(session, CSRF, form fields) and call a service. They contain no business rules.

Other folders: `assets/` (CSS), `storage/` (payment proofs), `tests/`, `docs/` (class diagram, libraries, debugging notes),
`tools/check_docs.php` (checks that every function has a complete docblock).

## Pages
| Page | Who | Feature |
|---|---|---|
| `register.php`, `login.php`, `logout.php` | everyone | Tenant registration (status pending) and login |
| `admin_tenants.php` | admin | Accept or reject new tenants |
| `admin_garage.php` | admin | Create garages in groups (S/C/E + 3 digits) |
| `rent.php`, `my_rentals.php` | tenant | New rental with calculated total and payment proof; own rentals |
| `admin_extend.php` | admin | Extend a rental that runs today, with payment proof |
| `admin_reports.php` | admin | Reports per month, per day, per garage type, per tenant |
| `proof.php` | admin / owner | Shows a payment proof after checking who is asking |

## Tests (252 scenarios + 24 PHPUnit tests)
Run in a terminal from the project folder:

    php tests/run_tests.php              # 16  registration rules (no database)
    php tests/garage_unit_tests.php      # 26  garage batch rules (no database)
    php tests/rental_rules_tests.php     # 75  vehicles, prices, dates, extensions (no database)
    php tests/auth_flow_tests.php        # 13  register/login against the database
    php tests/tenant_flow_tests.php      # 10  admin accepts/rejects tenants
    php tests/garage_flow_tests.php      # 12  garage creation and numbering
    php tests/rental_flow_tests.php      # 46  renting, uploads, plate reuse, tenant isolation
    php tests/extension_flow_tests.php   # 25  extending rentals
    php tests/report_tests.php           # 29  the four reports
    vendor/bin/phpunit                   # 24  PHPUnit tests (no database, PHP 8.3+)
    php tools/check_docs.php             # documentation check (0 gaps expected)

The runners share `tests/test_helpers.php` (result table, temporary data, clean-up).
No password or environment variable is needed: the admin login test (AT-13) creates its own temporary admin account.
Integration tests create their own temporary data and delete only that data.

Debugging notes with real bugs found and fixed: `docs/DEBUGGING.md`.

## Coding conventions
Names follow the course rules: classes PascalCase nouns, one class per file with the same name;
methods and functions camelCase starting with a verb (`findAvailableGarages`, `calculateEndDate`, `addResult`);
constants UPPER_SNAKE_CASE, defined once (garage type names live only in `Vehicle::GARAGE_*`); descriptive variable names, also in the tests.
PHPUnit test methods are named `method_condition_expectedResult` (for example `testFormatId_number1000_throwsException`).
Every class has a doc comment with its purpose, its relations to other classes, `@author` and `@version`; every function has
`@param`, `@return` and `@throws` where they apply (`php tools/check_docs.php` checks `src/`, the pages, `tests/` and `tools/`).
Pages log errors with file, line and stack trace (`error_log((string)$e)`).

## Business rules (and where they live)
- Start date is always today (Asia/Jakarta). Daily rental: 1 to (days in the start month - 1) days; end = start + days. (`RentalRules::calculateEndDate`)
- Monthly rental: price = 25 x daily price; end = start + number of days in the start month.
- Electric car in an electric garage: charging fee once = battery kWh x garage kWh price. Everything is calculated in whole cents (no float). (`RentalRules::calculateChargingFeeCents`)
- Bicycle/motorbike: bicycle garage only. Car: car garage. Electric car: car or electric garage. (`Vehicle::getAllowedGarageTypes`)
- A plate already in the database is reused (not modified); the same plate cannot be in two garages at once.
- Extension: new end date must be later; extra amount = extra days x the daily price saved with the rental. (`RentalRules::calculateExtensionCents`)
- Payment proofs: JPG/PNG, real image, at most 2 MB, saved under a random name.

## Security notes
Prepared statements everywhere (EMULATE_PREPARES off), `password_hash`/`password_verify`, CSRF token on every
data-changing form of a logged-in user (logout is POST only), output escaped with `escapeHtml()`, session id regenerated at login,
tenant queries filtered by the session user id, uploads stored outside public access.

## Libraries (Composer)
Run once after cloning: `composer install` (creates `vendor/`; see Requirements). Details, licenses and the update plan: `docs/LIBRARIES.md`.
- `vlucas/phpdotenv`: settings from `.env` (copy `.env.example` to `.env`; optional, defaults work on XAMPP).
- `phpunit/phpunit` (dev): `vendor/bin/phpunit` runs `tests/phpunit/` (24 tests, no database needed).
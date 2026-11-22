# Debugging notes: Garage Rental

Method used for every bug: **Reproduce → Isolate → Identify → Fix → Verify**, comparing *expected* with *actual*.
Types of error (course material): syntax error (found when the file is loaded), runtime error (program stops with an exception),
logic error (program runs but the result is wrong; the hardest one, so unit tests were written to catch them).

## Where to look when something goes wrong
- Every page catches exceptions and shows a safe message to the user. The full error, with **file, line and stack trace**, is written to the PHP error log with `error_log((string)$e)`.
- XAMPP log files: `C:\xampp\apache\logs\error.log` and `C:\xampp\php\logs\php_error_log`.
- Check the syntax of a file: `php -l file.php`. Run the tests to reproduce a bug: `php tests/rental_rules_tests.php`.
- How to read a stack trace: the first line is the error type and message; the first `#0` line is where it happened (file:line); the lines below are the callers, from the innermost to the page.

## Bug 1: monthly end date was wrong (logic error)
| Step | What was done |
|---|---|
| Reproduce | Compared the calculated end dates with the assessor's written answers (31 Jan 2027 → 3 Mar 2027). The first version did not match. |
| Expected vs actual | Expected (assessor's rule: start date + number of days of the start month): **3 March 2027**. Actual (first version): **28 February 2027**, the last day of the next month. |
| Isolate | The pure function `RentalRules::calculateEndDate()` (no database), tested alone. |
| Identify | The first version added "one month" and clamped to the last day of the next month, which is a different rule from "+ days of the start month". |
| Fix | `return $start->modify('+' . (int)$start->format('t') . ' days');` |
| Verify | Tests RT-47 (31 Jan 2027 → 3 Mar 2027), RT-48 (leap year 2028 → 2 Mar), RT-63 to RT-65 added and passing. |

## Bug 2: "Invalid parameter number" (HY093, runtime error)
| Step | What was done |
|---|---|
| Reproduce | Found while practising before the exam (a book search app): a query that used the same named placeholder twice (`... LIKE :q OR ... LIKE :q`) threw `SQLSTATE[HY093]: Invalid parameter number`. The garage app uses the same connection settings, so the rule was applied here too. |
| Isolate | A 3-line PHP script with the same query, once with `:q` twice and once with `:q1` and `:q2`. |
| Identify | The connection uses `PDO::ATTR_EMULATE_PREPARES => false` (real prepared statements), and then one named placeholder cannot be used twice. |
| Fix | Every placeholder is used once: `RentalService`, `ExtensionService` and `ReportService` use positional `?`; `AuthService`, `TenantService` and `GarageService` use named placeholders, each name only once per query. |
| Verify | The script printed OK for the distinct names. All database tests of the garage app pass. |

## Bug 3: a test failed because the *expected* value was wrong (test bug)
| Step | What was done |
|---|---|
| Reproduce | `report_tests.php`: RP-26 failed. |
| Expected vs actual | Expected `4/340000.00/...`, actual `4/260000.00/...`. |
| Identify | First check the test (course rule: "is the expected value correct?"). By hand: 60,000 + 40,000 + 80,000 + 80,000 = **260,000**. The code was right; the expected value contained a calculation mistake. |
| Fix | Corrected the expected value in the test (260000.00 and total 300000.00). |
| Verify | `report_tests.php`: 29 of 29 pass. |

## Bug 4: PHPUnit called methods that had been renamed (runtime error)
| Step | What was done |
|---|---|
| Reproduce | `vendor/bin/phpunit`: 23 tests, 13 passed, 10 failed with `Error: Call to undefined method RentalRules::rentalCents()` (also `toCents`, `maxDailyDays`, `endDate`, `extensionCents`, `Vehicle::fromInput`). |
| Isolate | All failures were in `tests/phpunit/RentalRulesTest.php`; the other two test files passed. |
| Identify | The methods had been renamed to start with a verb (`calculateRentalCents`, `convertToCents`, `getMaxDailyDays`, `calculateEndDate`, `calculateExtensionCents`, `createFromInput`), but this test file still used the old names. Found by reading the first line of each message and the file:line under it (`RentalRulesTest.php:14`). |
| Fix | Updated the test file to the new names, renamed its test methods to `method_condition_expectedResult`, and split the extension test into two (one scenario each). |
| Verify | `OK (24 tests, 60 assertions)`. |
| Lesson | When a method is renamed, search the whole project for the old name, tests included, before running the suite. |

## Bug 5: PHPUnit could not open a required file (runtime error)
| Step | What was done |
|---|---|
| Reproduce | No PHPUnit test ran: `Failed opening required 'tests\phpunit/src/RentalRules.php'` at `RentalRulesTest.php:5`. |
| Identify | A `require_once __DIR__ . '/src/RentalRules.php'` had been added to the test to silence an editor warning. `__DIR__` is `tests/phpunit`, so the path pointed to a folder that does not exist. The class was already loaded by `tests/phpunit/bootstrap.php`. |
| Fix | Removed the line; the bootstrap loads every class under test. The editor warning is cosmetic (no namespace is used). |
| Verify | `OK (24 tests, 60 assertions)`. |
| Lesson | A relative path in `require_once` is resolved from the file's own folder (`__DIR__`), not from the project root. |

## Bug 6: MySQL import error #1030 in XAMPP (environment error)
| Step | What was done |
|---|---|
| Reproduce | Importing the SQL file in phpMyAdmin stopped with error #1030 (storage engine Aria) at the GRANT statement. |
| Identify | The XAMPP MySQL data folder had Aria log files that caused the error on GRANT; the GRANT statement was not needed for local work. |
| Fix | The GRANT was removed from `garage_rental.sql`; the app connects as `root` locally. Damaged `aria_log*` files can be deleted with MySQL stopped. |
| Verify | The import finished and the app connected. |
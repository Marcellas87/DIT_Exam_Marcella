<?php
declare(strict_types=1);

/**
 * Shared helpers for the scenario test runners in tests/.
 * Each runner collects rows [ID, scenario, expected, actual, status] and prints them at the end.
 * Integration helpers create temporary data and remove ONLY that data afterwards.
 *
 * @author Marcella
 * @version 1.0
 */

/**
 * Adds one result row and decides Pass or Fail by comparing expected with actual.
 *
 * @param array<int, array<int, string>> $results the result table, changed in place
 * @param string $testId the test ID, like "RT-01"
 * @param string $scenario what is being tested
 * @param string $expected the value the test expects
 * @param string $actual the value the code returned
 * @return void
 */
function addResult(array &$results, string $testId, string $scenario, string $expected, string $actual): void
{
    $results[] = [$testId, $scenario, $expected, $actual, $expected === $actual ? 'Pass' : 'Fail'];
}

/**
 * Prints the result table and the totals, then ends the script
 * (exit code 0 if every test passed, 1 otherwise).
 *
 * @param array<int, array<int, string>> $results the result table
 * @param int $scenarioWidth column width of the scenario text
 * @param int $valueWidth column width of the expected and actual values
 * @return never
 */
function printResultsAndExit(array $results, int $scenarioWidth, int $valueWidth): never
{
    $passedCount = 0;
    $failedCount = 0;
    $rowFormat = "%-6s %-{$scenarioWidth}s expected=%-{$valueWidth}s actual=%-{$valueWidth}s %s\n";
    foreach ($results as $resultRow) {
        $passedCount += $resultRow[4] === 'Pass' ? 1 : 0;
        $failedCount += $resultRow[4] === 'Fail' ? 1 : 0;
        printf($rowFormat, ...$resultRow);
    }
    echo "\nTotal: " . count($results) . " | Pass: $passedCount | Fail: $failedCount\n";
    exit($failedCount === 0 ? 0 : 1);
}

/**
 * Runs an action and returns its result as text, or the class name of the exception it threw.
 *
 * @param callable $action the code under test
 * @return string the result, or for example "InvalidArgumentException"
 */
function getResultOrException(callable $action): string
{
    try {
        return (string)$action();
    } catch (Throwable $exception) {
        return get_class($exception);
    }
}

/**
 * Turns validator errors into one word: "valid", or the first field that failed.
 *
 * @param array<string, string> $errors error messages by field
 * @return string "valid" or a field name
 */
function getFirstErrorField(array $errors): string
{
    return count($errors) === 0 ? 'valid' : (string)array_key_first($errors);
}

/**
 * Turns service errors into text: "no errors", or the failed field names joined by commas.
 *
 * @param array<string, string> $errors error messages by field
 * @return string "no errors" or for example "email,nik"
 */
function listErrorFields(array $errors): string
{
    return count($errors) === 0 ? 'no errors' : implode(',', array_keys($errors));
}

/**
 * Turns a condition into "yes" or "no", so it fits the expected/actual text columns.
 *
 * @param bool $condition the condition to show
 * @return string "yes" or "no"
 */
function formatYesNo(bool $condition): string
{
    return $condition ? 'yes' : 'no';
}

/**
 * Makes a short random tag, so temporary emails and plates never clash with real data.
 *
 * @return string 8 hexadecimal characters
 */
function createUniqueTag(): string
{
    return substr(md5((string)microtime(true)), 0, 8);
}

/**
 * Makes a random 16-digit NIK that starts with the given digit.
 *
 * @param string $firstDigit one digit, so different runners use different NIK ranges
 * @return string a NIK of 16 digits
 */
function createRandomNik(string $firstDigit): string
{
    return $firstDigit . str_pad((string)random_int(0, 999999999999999), 15, '0', STR_PAD_LEFT);
}

/**
 * Runs a query that returns one value and gives it back as text ("NULL" when there is none).
 *
 * @param PDO $pdo an open database connection
 * @param string $sql a SELECT with "?" placeholders
 * @param array<int, mixed> $args values for the placeholders
 * @return string the value, or "NULL"
 */
function fetchScalar(PDO $pdo, string $sql, array $args = []): string
{
    $statement = $pdo->prepare($sql);
    $statement->execute($args);
    $value = $statement->fetchColumn();
    return $value === false || $value === null ? 'NULL' : (string)$value;
}

/**
 * Registers a temporary tenant (status pending) and returns its user id.
 *
 * @param AuthService $auth the service that registers tenants
 * @param PDO $pdo an open database connection
 * @param string $email a unique email
 * @param string $nikFirstDigit first digit of the random NIK
 * @param string $fullName the name shown in lists and reports
 * @return int the new user id
 * @throws RuntimeException if the registration is refused
 */
function createTestTenant(AuthService $auth, PDO $pdo, string $email, string $nikFirstDigit, string $fullName): int
{
    $errors = $auth->register([
        'nik' => createRandomNik($nikFirstDigit), 'full_name' => $fullName, 'phone' => '081234567890',
        'email' => $email, 'password' => 'secret123', 'password_confirm' => 'secret123',
    ]);
    if (count($errors) > 0) {
        throw new RuntimeException('Setup failed: ' . listErrorFields($errors));
    }
    return (int)fetchScalar($pdo, 'SELECT id FROM users WHERE email = ?', [$email]);
}

/**
 * Finds the id of a garage type by its name.
 *
 * @param PDO $pdo an open database connection
 * @param string $typeName "bicycle", "car" or "electric car"
 * @return int the garage type id (0 if not found)
 */
function findGarageTypeId(PDO $pdo, string $typeName): int
{
    return (int)fetchScalar($pdo, 'SELECT id FROM garage_types WHERE name = ?', [$typeName]);
}

/**
 * Finds the highest garage number of a type (S013 gives 13), or 0 if the type has no garages.
 *
 * @param PDO $pdo an open database connection
 * @param string $typeName "bicycle", "car" or "electric car"
 * @return int the highest number
 */
function findLastGarageNumber(PDO $pdo, string $typeName): int
{
    return (int)fetchScalar(
        $pdo,
        'SELECT COALESCE(MAX(CAST(SUBSTRING(g.id, 2) AS UNSIGNED)), 0)
         FROM garages g JOIN garage_types t ON t.id = g.garage_type_id WHERE t.name = ?',
        [$typeName]
    );
}

/**
 * Makes a small real image file and returns it in the shape of one $_FILES entry.
 *
 * @param string $extension "png" or "jpg"
 * @param int $extraBytes bytes added after the image, to make an oversized file
 * @return array{name:string,tmp_name:string,error:int,size:int,type:string}
 */
function createImageUpload(string $extension = 'png', int $extraBytes = 0): array
{
    $path = (string)tempnam(sys_get_temp_dir(), 'img');
    $image = imagecreatetruecolor(4, 4);
    if ($extension === 'jpg') {
        imagejpeg($image, $path);
    } else {
        imagepng($image, $path);
    }
    if ($extraBytes > 0) {
        file_put_contents($path, str_repeat('0', $extraBytes), FILE_APPEND);
    }
    return [
        'name' => 'my receipt.' . $extension, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK,
        'size' => (int)filesize($path), 'type' => 'image/' . $extension,
    ];
}

/**
 * Removes the rentals, extensions, vehicles, tenants, users and garages created by a test.
 *
 * @param PDO $pdo an open database connection
 * @param array<int, int> $userIds the temporary users
 * @param array<int, string> $garageIds the temporary garages
 * @return void
 */
function deleteTestData(PDO $pdo, array $userIds, array $garageIds): void
{
    if ($userIds !== []) {
        $idList = implode(',', array_map('intval', $userIds));
        $pdo->exec("DELETE e FROM rental_extensions e JOIN rentals r ON r.id = e.rental_id WHERE r.tenant_id IN ($idList)");
        $vehicleIds = $pdo->query("SELECT DISTINCT vehicle_id FROM rentals WHERE tenant_id IN ($idList)")->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec("DELETE FROM rentals WHERE tenant_id IN ($idList)");
        foreach ($vehicleIds as $vehicleId) {
            $pdo->prepare('DELETE FROM vehicles WHERE id = ?')->execute([$vehicleId]);
        }
        $pdo->exec("DELETE FROM tenants WHERE user_id IN ($idList)");
        $pdo->exec("DELETE FROM users WHERE id IN ($idList)");
    }
    foreach ($garageIds as $garageId) {
        $pdo->prepare('DELETE FROM garages WHERE id = ?')->execute([$garageId]);
    }
}

/**
 * Deletes a temporary folder and the files in it.
 *
 * @param string $directory the folder made by the test
 * @return void
 */
function deleteDirectory(string $directory): void
{
    array_map('unlink', glob($directory . '/*') ?: []);
    @rmdir($directory);
}
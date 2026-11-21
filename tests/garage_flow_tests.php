<?php
declare(strict_types=1);

/**
 * Integration tests for GarageService (uses the real database).
 * Run in a terminal:  php tests/garage_flow_tests.php
 *
 * The test creates garages and deletes ONLY the ones it created
 * (numbers above what existed before), so your own garages stay.
 */
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/GarageService.php';

if (PHP_SAPI !== 'cli') { exit('Run this file from the terminal.'); }

$pdo = Database::connect();
$service = new GarageService($pdo);

/** Garage type id by name. */
function typeId(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare('SELECT id FROM garage_types WHERE name = ?');
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn();
}
/** Highest garage number of a type (0 if none). */
function lastNumber(PDO $pdo, int $typeId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(CAST(SUBSTRING(id, 2) AS UNSIGNED)), 0) FROM garages WHERE garage_type_id = ?');
    $stmt->execute([$typeId]);
    return (int)$stmt->fetchColumn();
}
function countRows(PDO $pdo, int $typeId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM garages WHERE garage_type_id = ?');
    $stmt->execute([$typeId]);
    return (int)$stmt->fetchColumn();
}

$bike = typeId($pdo, 'bicycle');
$car = typeId($pdo, 'car');
$ev = typeId($pdo, 'electric car');
$before = [$bike => lastNumber($pdo, $bike), $car => lastNumber($pdo, $car), $ev => lastNumber($pdo, $ev)];

$results = [];
function check(array &$results, string $id, string $scenario, string $expected, string $actual): void
{
    $results[] = [$id, $scenario, $expected, $actual, $expected === $actual ? 'Pass' : 'Fail'];
}
function errorKeys(array $r): string { return count($r['errors']) === 0 ? 'no errors' : implode(',', array_keys($r['errors'])); }
function id(string $prefix, int $n): string { return sprintf('%s%03d', $prefix, $n); }

try {
    // --- numbering ---
    $b = $before[$bike];
    $r1 = $service->createBatch(['garage_type_id' => (string)$bike, 'count' => '3', 'daily_price' => '20000', 'kwh_price' => '9999']);
    check($results, 'GF-01', 'First batch of 3 bicycle garages', id('S', $b + 1) . '-' . id('S', $b + 3), ($r1['first'] ?? '?') . '-' . ($r1['last'] ?? '?'));

    $r2 = $service->createBatch(['garage_type_id' => (string)$bike, 'count' => '2', 'daily_price' => '25000', 'kwh_price' => '']);
    check($results, 'GF-02', 'Second batch continues the numbering', id('S', $b + 4) . '-' . id('S', $b + 5), ($r2['first'] ?? '?') . '-' . ($r2['last'] ?? '?'));

    $stmt = $pdo->prepare("SELECT status, daily_price, kwh_price FROM garages WHERE id = ?");
    $stmt->execute([id('S', $b + 1)]);
    $row = $stmt->fetch();
    check($results, 'GF-03', 'New garage: status available, price saved, kWh empty for bicycle', 'available/20000.00/NULL', ($row['status'] ?? '?') . '/' . ($row['daily_price'] ?? '?') . '/' . ($row['kwh_price'] ?? 'NULL'));

    // --- other types ---
    $c = $before[$car];
    $r3 = $service->createBatch(['garage_type_id' => (string)$car, 'count' => '1', 'daily_price' => '80000', 'kwh_price' => '']);
    check($results, 'GF-04', 'Car garage gets the C prefix', id('C', $c + 1), $r3['first'] ?? '?');

    $r4 = $service->createBatch(['garage_type_id' => (string)$ev, 'count' => '2', 'daily_price' => '120000', 'kwh_price' => '']);
    check($results, 'GF-05', 'Electric batch without kWh price is refused', 'kwh_price', errorKeys($r4));

    $e = $before[$ev];
    $r5 = $service->createBatch(['garage_type_id' => (string)$ev, 'count' => '2', 'daily_price' => '120000', 'kwh_price' => '2500.50']);
    check($results, 'GF-06', 'Electric garages get the E prefix', id('E', $e + 1) . '-' . id('E', $e + 2), ($r5['first'] ?? '?') . '-' . ($r5['last'] ?? '?'));

    $stmt->execute([id('E', $e + 1)]);
    $row = $stmt->fetch();
    check($results, 'GF-07', 'Electric garage keeps its kWh price', '2500.50', (string)($row['kwh_price'] ?? '?'));

    // --- refused requests ---
    check($results, 'GF-08', 'Unknown garage type id', 'garage_type_id', errorKeys($service->createBatch(['garage_type_id' => '999', 'count' => '1', 'daily_price' => '1000', 'kwh_price' => ''])));
    check($results, 'GF-09', 'Count 1000 is refused', 'count', errorKeys($service->createBatch(['garage_type_id' => (string)$car, 'count' => '1000', 'daily_price' => '1000', 'kwh_price' => ''])));

    // --- the 999 limit per type (numbers are only 3 digits) ---
    $room = 999 - lastNumber($pdo, $bike);
    $rFill = $service->createBatch(['garage_type_id' => (string)$bike, 'count' => (string)$room, 'daily_price' => '10000', 'kwh_price' => '']);
    check($results, 'GF-10', 'Fill the type up to S999', 'S999', $rFill['last'] ?? '?');

    $rowsBefore = countRows($pdo, $bike);
    $rOver = $service->createBatch(['garage_type_id' => (string)$bike, 'count' => '1', 'daily_price' => '10000', 'kwh_price' => '']);
    check($results, 'GF-11', 'One more after S999 is refused', 'count', errorKeys($rOver));
    check($results, 'GF-12', 'Refused batch saves nothing', 'same', countRows($pdo, $bike) === $rowsBefore ? 'same' : 'changed');
} finally {
    // Remove only what this test created.
    foreach ([$bike, $car, $ev] as $type) {
        $pdo->prepare('DELETE FROM garages WHERE garage_type_id = ? AND CAST(SUBSTRING(id, 2) AS UNSIGNED) > ?')->execute([$type, $before[$type]]);
    }
}

$passed = 0; $failed = 0;
foreach ($results as $r) {
    $passed += $r[4] === 'Pass' ? 1 : 0;
    $failed += $r[4] === 'Fail' ? 1 : 0;
    printf("%-6s %-66s expected=%-24s actual=%-24s %s\n", ...$r);
}
echo "\nTotal: " . count($results) . " | Pass: $passed | Fail: $failed\n";
exit($failed === 0 ? 0 : 1);
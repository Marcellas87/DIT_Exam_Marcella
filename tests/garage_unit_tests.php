<?php
declare(strict_types=1);

/**
 * Unit tests for GarageId and GarageBatchValidator (no database needed).
 * Run in a terminal:  php tests/garage_unit_tests.php
 * Each case: ID, scenario, expected, actual, status.
 *
 * @author Marcella
 * @version 1.1
 */
require_once __DIR__ . '/../src/GarageId.php';
require_once __DIR__ . '/../src/GarageBatchValidator.php';
require_once __DIR__ . '/test_helpers.php';

$results = [];

// ---- GarageId ----
addResult($results, 'GU-01', 'Prefix of bicycle',                'S', getResultOrException(fn() => GarageId::getPrefixFor('bicycle')));
addResult($results, 'GU-02', 'Prefix of car',                    'C', getResultOrException(fn() => GarageId::getPrefixFor('car')));
addResult($results, 'GU-03', 'Prefix of electric car',           'E', getResultOrException(fn() => GarageId::getPrefixFor('electric car')));
addResult($results, 'GU-04', 'Unknown type name',                'InvalidArgumentException', getResultOrException(fn() => GarageId::getPrefixFor('truck')));
addResult($results, 'GU-05', 'First bicycle garage',             'S001', getResultOrException(fn() => GarageId::formatId('S', 1)));
addResult($results, 'GU-06', 'Tenth car garage',                 'C010', getResultOrException(fn() => GarageId::formatId('C', 10)));
addResult($results, 'GU-07', 'Last number allowed (999)',        'E999', getResultOrException(fn() => GarageId::formatId('E', 999)));
addResult($results, 'GU-08', 'Number 1000 is too big',           'InvalidArgumentException', getResultOrException(fn() => GarageId::formatId('E', 1000)));
addResult($results, 'GU-09', 'Number 0 is too small',            'InvalidArgumentException', getResultOrException(fn() => GarageId::formatId('S', 0)));
addResult($results, 'GU-10', 'Read number of C010',              '10', getResultOrException(fn() => GarageId::getNumberOf('C010')));
addResult($results, 'GU-11', 'Read number of a bad ID',          'InvalidArgumentException', getResultOrException(fn() => GarageId::getNumberOf('X010')));

// ---- GarageBatchValidator ----
$validBatch = ['count' => '10', 'daily_price' => '50000', 'kwh_price' => ''];
$validateBatch = fn(array $changes, string $typeName = 'car'): string
    => getFirstErrorField(GarageBatchValidator::validate(array_merge($validBatch, $changes), $typeName));

addResult($results, 'GU-12', 'Valid car batch',                         'valid', $validateBatch([]));
addResult($results, 'GU-13', 'Count 0',                                 'count', $validateBatch(['count' => '0']));
addResult($results, 'GU-14', 'Count 1 (minimum)',                       'valid', $validateBatch(['count' => '1']));
addResult($results, 'GU-15', 'Count 999 (maximum)',                     'valid', $validateBatch(['count' => '999']));
addResult($results, 'GU-16', 'Count 1000 (one too many)',               'count', $validateBatch(['count' => '1000']));
addResult($results, 'GU-17', 'Count is text',                           'count', $validateBatch(['count' => 'ten']));
addResult($results, 'GU-18', 'Daily price 0',                           'daily_price', $validateBatch(['daily_price' => '0']));
addResult($results, 'GU-19', 'Daily price negative',                    'daily_price', $validateBatch(['daily_price' => '-5000']));
addResult($results, 'GU-20', 'Daily price with 2 decimals',             'valid', $validateBatch(['daily_price' => '50000.50']));
addResult($results, 'GU-21', 'Daily price with 3 decimals',             'daily_price', $validateBatch(['daily_price' => '50000.505']));
addResult($results, 'GU-22', 'Daily price is text',                     'daily_price', $validateBatch(['daily_price' => 'abc']));
addResult($results, 'GU-23', 'Electric batch without kWh price',        'kwh_price', $validateBatch(['kwh_price' => ''], 'electric car'));
addResult($results, 'GU-24', 'Electric batch with kWh price',           'valid', $validateBatch(['kwh_price' => '2500'], 'electric car'));
addResult($results, 'GU-25', 'Car batch ignores an empty kWh price',    'valid', $validateBatch(['kwh_price' => ''], 'car'));
addResult($results, 'GU-26', 'Bicycle batch ignores a bad kWh price',   'valid', $validateBatch(['kwh_price' => 'abc'], 'bicycle'));

printResultsAndExit($results, 44, 26);
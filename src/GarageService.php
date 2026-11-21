<?php
declare(strict_types=1);

require_once __DIR__ . '/GarageId.php';
require_once __DIR__ . '/GarageBatchValidator.php';

/**
 * Creating garages in groups (admin feature).
 */
final class GarageService
{
    private PDO $pdo;

    /**
     * @param PDO $pdo an open database connection
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lists the garage types for the form dropdown.
     *
     * @return array<int, array{id:int|string,name:string}>
     */
    public function findTypes(): array
    {
        return $this->pdo->query('SELECT id, name FROM garage_types ORDER BY id')->fetchAll();
    }

    /**
     * Summary for the admin page: one row per type and price, with the ID range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(): array
    {
        return $this->pdo->query(
            'SELECT gt.name AS type_name, g.daily_price, g.kwh_price,
                    COUNT(*) AS total, MIN(g.id) AS first_id, MAX(g.id) AS last_id
             FROM garages g
             JOIN garage_types gt ON gt.id = g.garage_type_id
             GROUP BY gt.id, gt.name, g.daily_price, g.kwh_price
             ORDER BY gt.id, MIN(g.id)'
        )->fetchAll();
    }

    /**
     * Creates a group of garages of one type.
     *
     * IDs continue from the highest existing number of that type
     * (first bicycle garage S001; if S001 to S010 exist, the next batch starts at S011).
     * The type letter + the 3-digit number means a type can never exceed 999 garages in total.
     *
     * All garages of the batch are saved in ONE transaction: all or nothing.
     * The garage type row is locked (FOR UPDATE) while numbering, so two admins
     * creating garages of the same type at the same moment cannot get the same IDs.
     *
     * @param array<string, mixed> $input garage_type_id, count, daily_price, kwh_price
     * @return array{errors:array<string,string>,first:?string,last:?string,count:int}
     *         errors is empty on success; first/last are the new ID range
     * @throws PDOException on unexpected database errors
     */
    public function createBatch(array $input): array
    {
        $none = ['first' => null, 'last' => null, 'count' => 0];

        $typeId = filter_var($input['garage_type_id'] ?? null, FILTER_VALIDATE_INT);
        $typeName = null;
        foreach ($this->findTypes() as $type) {
            if ($typeId !== false && (int)$type['id'] === $typeId) {
                $typeName = $type['name'];
            }
        }
        if ($typeName === null) {
            return ['errors' => ['garage_type_id' => 'Please choose a garage type.']] + $none;
        }

        $errors = GarageBatchValidator::validate($input, $typeName);
        if (count($errors) > 0) {
            return ['errors' => $errors] + $none;
        }

        $count = (int)$input['count'];
        $dailyPrice = trim((string)$input['daily_price']);
        $kwhPrice = $typeName === GarageBatchValidator::ELECTRIC_TYPE ? trim((string)$input['kwh_price']) : null;
        $prefix = GarageId::prefixFor($typeName);

        $this->pdo->beginTransaction();
        try {
            // Lock this type so numbering cannot be done twice at the same time.
            $lock = $this->pdo->prepare('SELECT id FROM garage_types WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $typeId]);

            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(CAST(SUBSTRING(id, 2) AS UNSIGNED)), 0)
                 FROM garages WHERE garage_type_id = :id'
            );
            $stmt->execute(['id' => $typeId]);
            $last = (int)$stmt->fetchColumn();

            $room = GarageId::MAX_NUMBER - $last;
            if ($count > $room) {
                $this->pdo->rollBack();
                $message = $room <= 0
                    ? 'No more ' . $typeName . ' garages can be created (the limit is ' . GarageId::MAX_NUMBER . ' per type).'
                    : 'Only ' . $room . ' more ' . $typeName . ' garages can be created (the limit is ' . GarageId::MAX_NUMBER . ' per type).';
                return ['errors' => ['count' => $message]] + $none;
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO garages (id, garage_type_id, daily_price, kwh_price, status)
                 VALUES (:id, :type_id, :daily, :kwh, 'available')"
            );
            for ($n = $last + 1; $n <= $last + $count; $n++) {
                $insert->execute([
                    'id'      => GarageId::format($prefix, $n),
                    'type_id' => $typeId,
                    'daily'   => $dailyPrice,
                    'kwh'     => $kwhPrice,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'errors' => [],
            'first'  => GarageId::format($prefix, $last + 1),
            'last'   => GarageId::format($prefix, $last + $count),
            'count'  => $count,
        ];
    }
}
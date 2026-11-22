<?php
declare(strict_types=1);

/**
 * Admin decisions about tenant registrations.
 *
 * Relations: reads and updates the tenants table (rows created by AuthService::register()).
 *
 * @author Marcella
 * @version 1.0
 */
final class TenantService
{
    /** The only two decisions an admin can make. */
    public const DECISIONS = ['accepted', 'rejected'];

    private PDO $pdo;

    /**
     * @param PDO $pdo an open database connection
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lists tenants waiting for a decision, oldest registration first.
     *
     * @return array<int, array<string, mixed>> rows with user_id, nik, full_name, phone, email
     */
    public function findPending(): array
    {
        $stmt = $this->pdo->query(
            "SELECT t.user_id, t.nik, t.full_name, t.phone, u.email
             FROM tenants t
             JOIN users u ON u.id = t.user_id
             WHERE t.registration_status = 'pending'
             ORDER BY t.user_id"
        );
        return $stmt->fetchAll();
    }

    /**
     * Accepts or rejects a PENDING registration.
     *
     * The "AND registration_status = 'pending'" part means a decision can only be
     * made once. If two admins click at the same time, only the first one changes
     * the row; the second gets false.
     *
     * @param int $userId the tenant's user id
     * @param string $decision "accepted" or "rejected"
     * @return bool true if the registration was changed, false if it was not pending
     *              (already decided, or no such tenant)
     * @throws InvalidArgumentException if the decision is not accepted/rejected
     */
    public function decideRegistration(int $userId, string $decision): bool
    {
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException('Decision must be "accepted" or "rejected".');
        }

        $stmt = $this->pdo->prepare(
            "UPDATE tenants
             SET registration_status = :decision
             WHERE user_id = :id AND registration_status = 'pending'"
        );
        $stmt->execute(['decision' => $decision, 'id' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
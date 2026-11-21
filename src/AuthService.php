<?php
declare(strict_types=1);

require_once __DIR__ . '/RegistrationValidator.php';

/**
 * Registration and login rules.
 */
final class AuthService
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
     * Registers a new tenant with status "pending" (the admin decides later).
     *
     * Two rows are saved in ONE transaction: users (login data) and tenants (profile).
     * If the second insert fails, the first is rolled back, so we never get
     * a user without a tenant profile.
     *
     * @param array<string, mixed> $input nik, full_name, phone, email, password, password_confirm
     * @return array<string, string> errors per field; empty array means success
     * @throws PDOException on unexpected database errors
     */
    public function register(array $input): array
    {
        $errors = RegistrationValidator::validate($input);
        if (count($errors) > 0) {
            return $errors;
        }

        $email = strtolower(trim((string)$input['email']));
        $nik = trim((string)$input['nik']);

        if ($this->exists('SELECT 1 FROM users WHERE email = :v', $email)) {
            $errors['email'] = 'This email is already registered.';
        }
        if ($this->exists('SELECT 1 FROM tenants WHERE nik = :v', $nik)) {
            $errors['nik'] = 'This NIK is already registered.';
        }
        if (count($errors) > 0) {
            return $errors;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, :role)'
            );
            $stmt->execute([
                'email' => $email,
                'hash'  => password_hash((string)$input['password'], PASSWORD_DEFAULT),
                'role'  => 'tenant',
            ]);
            $userId = (int)$this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO tenants (user_id, nik, full_name, phone, registration_status)
                 VALUES (:user_id, :nik, :name, :phone, :status)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'nik'     => $nik,
                'name'    => trim((string)$input['full_name']),
                'phone'   => trim((string)$input['phone']),
                'status'  => 'pending',
            ]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ($e->getCode() === '23000') { // duplicate saved by someone else a moment ago
                return ['email' => 'This email or NIK is already registered.'];
            }
            throw $e;
        }

        return [];
    }

    /**
     * Checks an email and password.
     *
     * Result status:
     * - "ok"       : login allowed; "user" holds id, email, role, full_name
     * - "invalid"  : wrong email OR wrong password (same answer on purpose,
     *                so attackers cannot learn which emails exist)
     * - "pending"  : tenant registered, admin has not decided yet
     * - "rejected" : admin rejected the registration
     *
     * Only accepted tenants can log in. Admins have no tenant row, so they skip that check.
     *
     * @param string $email the email typed on the login form (not case sensitive)
     * @param string $password the password typed on the login form
     * @return array{status:string,user?:array<string,mixed>}
     */
    public function login(string $email, string $password): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.password_hash, u.role,
                    t.full_name, t.registration_status
             FROM users u
             LEFT JOIN tenants t ON t.user_id = u.id
             WHERE u.email = :email'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, $row['password_hash'])) {
            return ['status' => 'invalid'];
        }

        if ($row['role'] === 'tenant' && $row['registration_status'] !== 'accepted') {
            return ['status' => $row['registration_status']]; // pending or rejected
        }

        return [
            'status' => 'ok',
            'user'   => [
                'id'        => (int)$row['id'],
                'email'     => $row['email'],
                'role'      => $row['role'],
                'full_name' => $row['full_name'],
            ],
        ];
    }

    /**
     * Returns true if the query finds at least one row.
     *
     * @param string $sql a SELECT with one placeholder named :v
     * @param string $value the value for :v
     * @return bool true if a row exists
     */
    private function exists(string $sql, string $value): bool
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['v' => $value]);
        return $stmt->fetchColumn() !== false;
    }
}
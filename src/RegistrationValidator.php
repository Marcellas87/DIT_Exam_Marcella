<?php
declare(strict_types=1);

/**
 * Validates the tenant registration form. No database code, so it is easy to unit test.
 */
final class RegistrationValidator
{
    public const NIK_LENGTH = 16;
    public const MIN_PASSWORD_LENGTH = 8;
    public const MAX_PASSWORD_LENGTH = 72; // bcrypt only uses the first 72 bytes

    /**
     * Rules:
     * - nik: exactly 16 digits
     * - full_name: required, at most 100 characters
     * - phone: 8 to 15 digits, optional leading "+"
     * - email: valid format, at most 100 characters
     * - password: 8 to 72 characters, and equal to password_confirm
     *
     * @param array<string, mixed> $data nik, full_name, phone, email, password, password_confirm
     * @return array<string, string> error message per field; empty array if valid
     */
    public static function validate(array $data): array
    {
        $errors = [];

        $nik = trim((string)($data['nik'] ?? ''));
        if (preg_match('/^[0-9]{' . self::NIK_LENGTH . '}$/', $nik) !== 1) {
            $errors['nik'] = 'NIK must be exactly ' . self::NIK_LENGTH . ' digits.';
        }

        $name = trim((string)($data['full_name'] ?? ''));
        if ($name === '') {
            $errors['full_name'] = 'Full name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors['full_name'] = 'Full name must be at most 100 characters.';
        }

        $phone = trim((string)($data['phone'] ?? ''));
        if (preg_match('/^\+?[0-9]{8,15}$/', $phone) !== 1) {
            $errors['phone'] = 'Phone must be 8 to 15 digits (a leading + is allowed).';
        }

        $email = trim((string)($data['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 100) {
            $errors['email'] = 'Please enter a valid email (at most 100 characters).';
        }

        $password = (string)($data['password'] ?? '');
        $length = strlen($password);
        if ($length < self::MIN_PASSWORD_LENGTH || $length > self::MAX_PASSWORD_LENGTH) {
            $errors['password'] = 'Password must be ' . self::MIN_PASSWORD_LENGTH . ' to ' . self::MAX_PASSWORD_LENGTH . ' characters.';
        } elseif ($password !== (string)($data['password_confirm'] ?? '')) {
            $errors['password_confirm'] = 'Password confirmation does not match.';
        }

        return $errors;
    }
}

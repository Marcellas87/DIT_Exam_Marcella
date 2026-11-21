<?php
declare(strict_types=1);

/**
 * Escapes text for safe output in HTML (protects against XSS).
 *
 * @param mixed $value any value to print
 * @return string the escaped text
 */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Starts the PHP session once, with safer cookie settings.
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

/**
 * Returns the logged-in user, or null if nobody is logged in.
 *
 * @return array{id:int,email:string,role:string,full_name:?string}|null
 */
function currentUser(): ?array
{
    startSession();
    return $_SESSION['user'] ?? null;
}

/**
 * Page guard: sends visitors to the login page, and blocks the wrong role.
 *
 * @param string|null $role 'admin' or 'tenant'; null means any logged-in user
 * @return array the logged-in user
 */
function requireLogin(?string $role = null): array
{
    $user = currentUser();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }
    if ($role !== null && $user['role'] !== $role) {
        http_response_code(403);
        exit('You are not allowed to open this page.');
    }
    return $user;
}

/**
 * Returns the CSRF token of this session (creates it on first use).
 * Put it in every form that changes data, as a hidden field named "csrf".
 *
 * @return string a random token of 64 hex characters
 */
function csrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Checks the token sent by a form. hash_equals compares in constant time.
 * This stops other websites from making an admin's browser submit forms for them (CSRF).
 *
 * @param mixed $token the value of $_POST['csrf']
 * @return bool true if the token matches the one in the session
 */
function isValidCsrf($token): bool
{
    startSession();
    return isset($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}
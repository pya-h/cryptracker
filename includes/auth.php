<?php
/**
 * Authentication helpers.
 */

require_once __DIR__ . '/db.php';

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) return null;
    return dbGetUserById($_SESSION['user_id']);
}

function requireAuth(): array
{
    $user = currentUser();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function registerUser(string $username, string $email, string $password): array
{
    $errors = [];

    $username = trim($username);
    $email    = trim(strtolower($email));

    if (!preg_match('/^[a-zA-Z0-9_\-]{3,30}$/', $username)) {
        $errors[] = 'Username must be 3-30 characters (letters, numbers, _ or -).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if (dbGetUserByField('username', $username)) {
        $errors[] = 'Username already taken.';
    }
    if (dbGetUserByField('email', $email)) {
        $errors[] = 'Email already registered.';
    }

    if ($errors) return ['ok' => false, 'errors' => $errors];

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $id   = dbInsertUser($username, $email, $hash);

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $id;
    return ['ok' => true, 'user_id' => $id];
}

function loginUser(string $login, string $password): array
{
    $now = time();
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    $_SESSION['login_attempts'] = array_filter(
        $_SESSION['login_attempts'],
        fn($ts) => ($now - $ts) < 60
    );
    if (count($_SESSION['login_attempts']) >= 5) {
        return ['ok' => false, 'errors' => ['Too many login attempts. Please wait a minute.']];
    }

    $login = trim($login);
    $user  = dbGetUserByField('username', $login);
    if (!$user) $user = dbGetUserByField('email', $login);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $_SESSION['login_attempts'][] = $now;
        return ['ok' => false, 'errors' => ['Invalid credentials.']];
    }

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $user['id'];
    unset($_SESSION['login_attempts']);
    return ['ok' => true, 'user_id' => $user['id']];
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

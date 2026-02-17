<?php
/**
 * Authentication Tests
 */

function test_auth_register_success(): void
{
    dbPurgeAll();
    $_SESSION = [];

    $result = registerUser('validuser', 'valid@example.com', 'secret123');
    assert_true($result['ok'], 'Registration should succeed');
    assert_greater_than($result['user_id'], 0, 'User ID should be positive');

    // User should be in session
    assert_equals($result['user_id'], $_SESSION['user_id'], 'Session should contain user ID');

    // User should exist in DB
    $user = dbGetUserById($result['user_id']);
    assert_not_null($user, 'User should exist in DB');
    assert_equals('validuser', $user['username'], 'Username should match');
    assert_equals('valid@example.com', $user['email'], 'Email should be lowercased');

    // Password should be hashed
    assert_true(password_verify('secret123', $user['password_hash']), 'Password should verify');
    assert_false(password_verify('wrong', $user['password_hash']), 'Wrong password should not verify');

    dbPurgeAll();
    $_SESSION = [];
}

function test_auth_register_validation(): void
{
    dbPurgeAll();
    $_SESSION = [];

    // Too short username
    $r = registerUser('ab', 'valid@example.com', 'secret123');
    assert_false($r['ok'], 'Short username should fail');

    // Invalid username chars
    $r = registerUser('user name!', 'valid@example.com', 'secret123');
    assert_false($r['ok'], 'Username with spaces/special chars should fail');

    // Invalid email
    $r = registerUser('validuser', 'not-an-email', 'secret123');
    assert_false($r['ok'], 'Invalid email should fail');

    // Short password
    $r = registerUser('validuser', 'valid@example.com', '123');
    assert_false($r['ok'], 'Short password should fail');

    dbPurgeAll();
    $_SESSION = [];
}

function test_auth_register_duplicate(): void
{
    dbPurgeAll();
    $_SESSION = [];

    registerUser('alice', 'alice@example.com', 'password1');
    $_SESSION = [];

    // Duplicate username
    $r = registerUser('alice', 'different@example.com', 'password2');
    assert_false($r['ok'], 'Duplicate username should fail');
    assert_true(
        in_array('Username already taken.', $r['errors']),
        'Should report username taken'
    );

    // Duplicate email
    $r = registerUser('bob', 'alice@example.com', 'password2');
    assert_false($r['ok'], 'Duplicate email should fail');
    assert_true(
        in_array('Email already registered.', $r['errors']),
        'Should report email taken'
    );

    dbPurgeAll();
    $_SESSION = [];
}

function test_auth_login_success(): void
{
    dbPurgeAll();
    $_SESSION = [];

    registerUser('logintest', 'login@test.com', 'myPassword');
    $_SESSION = [];

    // Login by username
    $r = loginUser('logintest', 'myPassword');
    assert_true($r['ok'], 'Login by username should succeed');
    assert_true(isset($_SESSION['user_id']), 'Session should be set after login');

    $_SESSION = [];

    // Login by email
    $r = loginUser('login@test.com', 'myPassword');
    assert_true($r['ok'], 'Login by email should succeed');

    $_SESSION = [];
    dbPurgeAll();
}

function test_auth_login_failure(): void
{
    dbPurgeAll();
    $_SESSION = [];

    registerUser('logintest2', 'login2@test.com', 'correctPass');
    $_SESSION = [];

    // Wrong password
    $r = loginUser('logintest2', 'wrongPassword');
    assert_false($r['ok'], 'Wrong password should fail');
    assert_true(
        in_array('Invalid credentials.', $r['errors']),
        'Should report invalid credentials'
    );

    // Non-existent user
    $r = loginUser('nonexistent', 'anyPassword');
    assert_false($r['ok'], 'Non-existent user should fail');

    $_SESSION = [];
    dbPurgeAll();
}

function test_auth_rate_limiting(): void
{
    dbPurgeAll();
    $_SESSION = [];

    registerUser('ratelimit', 'rate@test.com', 'password');
    $_SESSION = [];

    // Simulate 5 failed login attempts
    for ($i = 0; $i < 5; $i++) {
        loginUser('ratelimit', 'wrongpassword');
    }

    // 6th attempt should be rate-limited
    $r = loginUser('ratelimit', 'password'); // correct password!
    assert_false($r['ok'], '6th attempt should be rate-limited');
    assert_true(
        str_contains($r['errors'][0] ?? '', 'Too many'),
        'Should report rate limiting'
    );

    $_SESSION = [];
    dbPurgeAll();
}

function test_auth_current_user(): void
{
    dbPurgeAll();
    $_SESSION = [];

    // No session = no user
    $user = currentUser();
    assert_null($user, 'No session should mean no user');

    // After login, currentUser should work
    registerUser('currenttest', 'cur@test.com', 'pass123');
    $user = currentUser();
    assert_not_null($user, 'After register, current user should exist');
    assert_equals('currenttest', $user['username'], 'Username should match');

    $_SESSION = [];
    dbPurgeAll();
}

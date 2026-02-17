<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (currentUser()) { header('Location: index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = registerUser(
        $_POST['username'] ?? '',
        $_POST['email']    ?? '',
        $_POST['password'] ?? ''
    );
    if ($res['ok']) { header('Location: index.php'); exit; }
    $errors = $res['errors'];
}

layoutHead('Register', true);
?>
    <div class="auth-card">
        <h1><?= e(APP_NAME) ?></h1>
        <h2>Create Account</h2>

        <?php if ($errors): ?>
            <div class="alert alert-error animate-slide-down">
                <?php foreach ($errors as $err): ?>
                    <p><?= e($err) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required minlength="3"
                   value="<?= e($_POST['username'] ?? '') ?>"
                   pattern="[a-zA-Z0-9_\-]{3,30}" title="3-30 characters: letters, numbers, _ or -">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" required
                   value="<?= e($_POST['email'] ?? '') ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="6">

            <button type="submit" class="btn btn-primary btn-block">Register</button>
        </form>

        <p class="auth-footer">Already have an account? <a href="login.php">Log In</a></p>
    </div>
</body>
</html>

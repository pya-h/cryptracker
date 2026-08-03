<?php
require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/helpers.php';

if (currentUser()) { header('Location: index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $errors[] = 'Invalid or missing CSRF token. Please refresh and try again.';
    } else {
        $res = loginUser($_POST['login'] ?? '', $_POST['password'] ?? '');
        if ($res['ok']) { header('Location: index.php'); exit; }
        $errors = $res['errors'];
    }
}

layoutHead('Login', true);
?>
    <div class="auth-card">
        <h1><?= e(APP_NAME) ?></h1>
        <h2>Sign In</h2>

        <?php if ($errors): ?>
            <div class="alert alert-error animate-slide-down">
                <?php foreach ($errors as $err): ?>
                    <p><?= e($err) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <?= csrfField() ?>
            <label for="login">Username or Email</label>
            <input type="text" id="login" name="login" required
                   value="<?= e($_POST['login'] ?? '') ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="btn btn-primary btn-block">Log In</button>
        </form>

        <p class="auth-footer">Don't have an account? <a href="register.php">Register</a></p>
    </div>
</body>
</html>

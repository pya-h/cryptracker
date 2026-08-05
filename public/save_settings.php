<?php
/**
 * Handle settings changes: theme, precision, username, password.
 * POST endpoint — stores preferences in session or updates user record.
 */

require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/helpers.php';

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

csrfGuard();

$action   = $_POST['action'] ?? '';
$redirect = $_POST['redirect'] ?? 'index.php';
$allowed  = ['index.php'];
if (preg_match('/^token\.php\?id=\d+$/', $redirect)) {
    $allowed[] = $redirect;
}
$target = in_array($redirect, $allowed) ? $redirect : 'index.php';

switch ($action) {

    case 'theme':
        $_SESSION['theme'] = (theme() === 'dark') ? 'light' : 'dark';
        flash('success', 'Theme switched to ' . ucfirst($_SESSION['theme']) . '.');
        break;

    case 'precision':
        $p = (int) ($_POST['precision'] ?? 2);
        $p = max(2, min(10, $p));
        $_SESSION['precision'] = $p;
        $_SESSION['worthless_zeros'] = isset($_POST['worthless_zeros']);
        flash('success', "Precision set to $p decimal digits.");
        break;

    case 'price_source':
        $src = strtolower(trim((string) ($_POST['price_source'] ?? 'coinmarketcap')));
        $allowedSources = ['coinmarketcap', 'coinlore', 'coingecko'];
        if (!in_array($src, $allowedSources, true)) {
            flash('error', 'Invalid price source selected.');
            break;
        }
        $_SESSION['price_source'] = $src;
        $_SESSION['price_source_fail'] = ['source' => $src, 'count' => 0, 'last_ts' => 0];
        flash('success', 'Price source changed to ' . priceSourceLabel($src) . '.');
        break;

    case 'username':
        $newName = trim($_POST['new_username'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_\-]{3,30}$/', $newName)) {
            flash('error', 'Username must be 3-30 characters (letters, numbers, _ or -).');
            break;
        }
        if (strtolower($newName) === strtolower($user['username'])) {
            flash('error', 'That is already your username.');
            break;
        }
        $existing = dbGetUserByField('username', $newName);
        if ($existing && (int) $existing['id'] !== (int) $user['id']) {
            flash('error', 'Username already taken.');
            break;
        }
        dbUpdateUser($user['id'], ['username' => $newName]);
        flash('success', 'Username updated to ' . $newName . '.');
        break;

    case 'password':
        $oldPw = $_POST['old_password'] ?? '';
        $newPw = $_POST['new_password'] ?? '';
        if (!password_verify($oldPw, $user['password_hash'])) {
            flash('error', 'Current password is incorrect.');
            break;
        }
        if (strlen($newPw) < 6) {
            flash('error', 'New password must be at least 6 characters.');
            break;
        }
        $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
        dbUpdateUser($user['id'], ['password_hash' => $hash]);
        // Rotate the session ID so any previously captured session cookie is
        // invalidated once the credentials change.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        flash('success', 'Password updated successfully.');
        break;

    default:
        flash('error', 'Unknown settings action.');
}

header('Location: ' . $target);
exit;

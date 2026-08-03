<?php
/**
 * Flash message helpers for session-based notifications.
 */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function renderFlashes(): string
{
    $html = '';
    foreach (getFlashes() as $f) {
        $cls = $f['type'] === 'error' ? 'alert-error' : 'alert-success';
        $html .= '<div class="alert ' . $cls . ' animate-slide-down"><p>' . e($f['message']) . '</p></div>';
    }
    return $html;
}

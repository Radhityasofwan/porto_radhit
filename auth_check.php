<?php
/**
 * Shared admin auth: session OR permanent remember-me cookie.
 * Include AFTER session_start() in every protected page.
 */
if (!defined('ADMIN_AUTH_SECRET')) {
    define('ADMIN_AUTH_SECRET', 'Rs_Adm!n_2025_' . substr(md5('salt_x9z'), 0, 16));
}

function admin_restore_from_cookie() {
    if (!isset($_COOKIE['admin_remember'])) return false;
    $parts = explode(':', $_COOKIE['admin_remember'], 2);
    if (count($parts) !== 2) return false;
    [$user, $sig] = $parts;
    if (!hash_equals(hash_hmac('sha256', $user, ADMIN_AUTH_SECRET), $sig)) return false;
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_name'] = $user;
    return true;
}

if (!isset($_SESSION['admin_logged_in'])) {
    admin_restore_from_cookie();
}
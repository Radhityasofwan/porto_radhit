<?php
session_start();
require_once 'i18n.php';
$lang = currentLang();
session_destroy(); // Hapus semua sesi
setcookie('admin_remember', '', time() - 3600, '/', '', false, true); // Clear remember-me cookie
header("Location: " . localizedUrl('login.php', $lang)); // Kembali ke login
exit;
?>

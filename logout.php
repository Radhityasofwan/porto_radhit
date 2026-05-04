<?php
session_start();
require_once 'i18n.php';
$lang = currentLang();
session_destroy(); // Hapus semua sesi
header("Location: " . localizedUrl('login.php', $lang)); // Kembali ke login
exit;
?>

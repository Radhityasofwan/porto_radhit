<?php
/**
 * db.php — kredensial dimuat dari file config di luar repo.
 *
 * Urutan pencarian:
 *   1. config.local.php      → development lokal (gitignored)
 *   2. porto-config.php      → produksi, se-dir (gitignored)
 *   3. ../porto-config.php   → produksi, di luar public_html (gitignored)
 *
 * Salin config.example.php untuk membuat salah satunya.
 */
$__cfg = null;
$__cfgFile = null;
foreach ([
    __DIR__ . '/config.local.php',
    __DIR__ . '/porto-config.php',
    dirname(__DIR__) . '/porto-config.php',
] as $__f) {
    if (is_file($__f)) { $__cfgFile = $__f; $__cfg = require $__f; break; }
}
if (!is_array($__cfg)) {
    die("Konfigurasi database tidak ditemukan. Salin config.example.php menjadi config.local.php atau porto-config.php (se-dir, atau satu level di atas public_html untuk produksi).");
}

// PHP 8.1+ membuat mysqli melempar exception, sehingga cek !$conn di bawah tak pernah tercapai
mysqli_report(MYSQLI_REPORT_OFF);

// Buat koneksi
$conn = mysqli_connect(
    $__cfg['host'], $__cfg['user'], $__cfg['pass'], $__cfg['db'],
    $__cfg['port'] ?? 3306, $__cfg['socket'] ?? null
);

// Cek koneksi
if (!$conn) {
    $__errno = mysqli_connect_errno();
    error_log("db.php: koneksi gagal ($__errno) via $__cfgFile");
    die("Koneksi database gagal (kode $__errno). Periksa host/user/pass/db di " . basename($__cfgFile) . ".");
}

// Set charset biar aman untuk UTF-8
mysqli_set_charset($conn, "utf8mb4");

// Create site_settings table if missing
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS site_settings (
    `setting_key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `setting_value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Create analytics table if missing
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS web_analytics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(32) NOT NULL,
    event_key VARCHAR(128) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    referer VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Trash / undo table
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS deleted_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(64) NOT NULL,
    row_data MEDIUMTEXT NOT NULL,
    profile_id INT DEFAULT NULL,
    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_del (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// CV history table
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cv_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_id INT NOT NULL DEFAULT 1,
    mode VARCHAR(16) NOT NULL DEFAULT 'generate',
    lang VARCHAR(4) NOT NULL DEFAULT 'id',
    label VARCHAR(120) DEFAULT NULL,
    cv_data MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cv_profile (profile_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Read a single site_settings value; null when the key is absent
function siteSetting($key) {
    global $conn;
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];

    $cache[$key] = null;
    $r = mysqli_query($conn, "SELECT setting_value FROM site_settings WHERE setting_key = '" . mysqli_real_escape_string($conn, $key) . "' LIMIT 1");
    if ($r && ($row = mysqli_fetch_assoc($r))) $cache[$key] = $row['setting_value'];

    return $cache[$key];
}

// Multi-profile migration
function _addCol($conn, $table, $col, $def) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0)
        mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
}
_addCol($conn, 'profile',        'is_active',    'TINYINT(1) NOT NULL DEFAULT 0');
_addCol($conn, 'profile',        'profile_label','VARCHAR(100) DEFAULT NULL');
_addCol($conn, 'projects',       'profile_id',   'INT NOT NULL DEFAULT 1');
_addCol($conn, 'skills',         'profile_id',   'INT NOT NULL DEFAULT 1');
_addCol($conn, 'experience',     'profile_id',   'INT NOT NULL DEFAULT 1');
_addCol($conn, 'education',      'profile_id',   'INT NOT NULL DEFAULT 1');
_addCol($conn, 'articles',       'profile_id',   'INT NOT NULL DEFAULT 1');
_addCol($conn, 'client_logos',   'profile_id',   'INT NOT NULL DEFAULT 1');
_addCol($conn, 'impact_metrics', 'profile_id',   'INT NOT NULL DEFAULT 1');
_addCol($conn, 'hero_chat',      'profile_id',   'INT NOT NULL DEFAULT 1');
_addCol($conn, 'messages',       'profile_id',   'INT NOT NULL DEFAULT 1');
_addCol($conn, 'web_analytics',  'profile_id',   'INT NOT NULL DEFAULT 1');
// Ensure the original profile row is the active one
mysqli_query($conn, "UPDATE profile SET is_active=1 WHERE id=1 AND is_active=0 AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM profile WHERE is_active=1 LIMIT 1) t)");
?>

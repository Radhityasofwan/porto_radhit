<?php
/**
 * db.php (Hostinger)
 * Domain: radhityasofwan.my.id
 * DB Name : u871240427_webporto
 * DB User : u871240427_sofwanr27
 */

$host = 'localhost';
$user = 'u830768701_radhit';
$pass = 'Sofwanr27_';
$db   = 'u830768701_porto';

// Buat koneksi
$conn = mysqli_connect($host, $user, $pass, $db);

// Cek koneksi
if (!$conn) {
    // Jangan tampilkan detail error di production kalau sudah live (opsional)
    die("Koneksi database gagal. Silakan cek konfigurasi db.php atau status database.");
}

// Set charset biar aman untuk UTF-8
mysqli_set_charset($conn, "utf8mb4");

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
?>

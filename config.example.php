<?php
/**
 * Template konfigurasi database.
 *
 * Salin file ini menjadi SALAH SATU dari:
 *   - config.local.php                     → dipakai saat development di lokal (gitignored)
 *   - ../porto-config.php                  → dipakai di produksi, DI LUAR public_html
 *
 * Jangan pernah commit file berisi kredensial asli.
 */

return [
    'host'   => 'localhost',
    'user'   => 'DB_USER',
    'pass'   => 'DB_PASSWORD',
    'db'     => 'DB_NAME',
    'port'   => 3306,
    // Hanya untuk MySQL lokal via Unix socket, mis. '/tmp/mysql.sock'.
    // Di produksi biarkan null.
    'socket' => null,
];

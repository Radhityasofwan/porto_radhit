<?php
require 'db.php';

// Setting Password Baru
$new_password = 'admin'; 
$username = 'admin';

// Enkripsi Password
$hash = password_hash($new_password, PASSWORD_DEFAULT);

// Update Database
$query = "UPDATE admin_users SET password = '$hash' WHERE username = '$username'";

if (mysqli_query($conn, $query)) {
    echo "<div style='font-family: sans-serif; text-align: center; padding-top: 50px;'>";
    echo "<h1 style='color: green;'>Password Berhasil Direset!</h1>";
    echo "<p>Silakan login dengan:</p>";
    echo "<p>Username: <b>admin</b></p>";
    echo "<p>Password: <b>admin</b></p>";
    echo "<br>";
    echo "<a href='login.php' style='background: blue; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Ke Halaman Login</a>";
    echo "<br><br><small style='color: red;'>Penting: Hapus file reset_password.php ini setelah Anda berhasil login.</small>";
    echo "</div>";
} else {
    echo "Gagal mereset password: " . mysqli_error($conn);
}
?>
<?php
session_start();
require_once 'i18n.php';
include 'db.php';

$lang = currentLang();

// Jika sudah login, lempar ke admin
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: " . localizedUrl('admin.php', $lang));
    exit;
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    $query = "SELECT * FROM admin_users WHERE username = '$username'";
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) === 1) {
        $row = mysqli_fetch_assoc($result);
        if (password_verify($password, $row['password'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_name'] = $row['username'];
            // Set permanent remember-me cookie (10 years, HMAC-signed)
            if (!defined('ADMIN_AUTH_SECRET')) define('ADMIN_AUTH_SECRET', 'Rs_Adm!n_2025_' . substr(md5('salt_x9z'), 0, 16));
            $_sig = hash_hmac('sha256', $row['username'], ADMIN_AUTH_SECRET);
            setcookie('admin_remember', $row['username'] . ':' . $_sig, time() + (86400 * 3650), '/', '', false, true);
            header("Location: " . localizedUrl('admin.php', $lang));
            exit;
        } else {
            $error = t('auth.error_password', [], $lang);
        }
    } else {
        $error = t('auth.error_username', [], $lang);
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('auth.login_title', [], $lang); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"></noscript>
    <link rel="preload" href="/styles.min.css?v=4" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="/styles.min.css?v=4"></noscript>
    <!-- FA CDN for icons not in subset (fa-user-shield, fa-lock, fa-exclamation-circle) -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"></noscript>
    <style>
        *,*::before,*::after{box-sizing:border-box}
        html,body{margin:0;padding:0}
        body {
            font-family: "Plus Jakarta Sans", system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            /* Dark gradient — always dark on admin login */
            background-color: #0f172a;
            background-image:
                radial-gradient(at 0% 0%,   hsla(253,16%,7%,1)  0, transparent 50%),
                radial-gradient(at 50% 0%,  hsla(225,39%,30%,1) 0, transparent 50%),
                radial-gradient(at 100% 0%, hsla(339,49%,30%,1) 0, transparent 50%);
            color: #e2e8f0;
            overflow-x: hidden;
        }
        @font-face{font-family:"PJSFallback";src:local("Arial");size-adjust:97%;ascent-override:94%;descent-override:25%;line-gap-override:0%}
    </style>
</head>
<body>

    <div class="w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" style="background:#1e293b;border:1px solid #334155">

        <!-- Lang switcher -->
        <div class="flex justify-end px-6 pt-5">
            <div class="flex items-center rounded-full p-1" style="border:1px solid #475569;background:#0f172a">
                <a href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>" class="px-3 py-1 rounded-full text-[11px] font-bold transition" style="<?php echo $lang === 'id' ? 'background:#fff;color:#0f172a' : 'color:#94a3b8'; ?>">ID</a>
                <a href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>" class="px-3 py-1 rounded-full text-[11px] font-bold transition" style="<?php echo $lang === 'en' ? 'background:#fff;color:#0f172a' : 'color:#94a3b8'; ?>">EN</a>
            </div>
        </div>

        <!-- Header -->
        <div class="p-8 text-center" style="border-bottom:1px solid #334155">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-4" style="background:#1d4ed8;opacity:0.85">
                <i class="fas fa-user-shield text-3xl" style="color:#93c5fd"></i>
            </div>
            <h2 class="text-2xl font-bold" style="color:#f1f5f9"><?php echo t('auth.welcome_back', [], $lang); ?></h2>
            <p class="text-sm mt-2" style="color:#94a3b8"><?php echo t('auth.login_subtitle', [], $lang); ?></p>
        </div>

        <!-- Error -->
        <?php if ($error): ?>
            <div class="text-sm mx-8 mt-5 p-4" style="background:rgba(239,68,68,0.12);border-left:4px solid #ef4444;color:#fca5a5;border-radius:0.375rem">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" class="p-8 pt-6">

            <!-- Username -->
            <div class="mb-5">
                <label class="block text-xs font-bold mb-2 uppercase tracking-wider" style="color:#cbd5e1"><?php echo t('auth.username', [], $lang); ?></label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center" style="color:#64748b">
                        <i class="fas fa-user"></i>
                    </span>
                    <input type="text" name="username"
                        style="width:100%;background:#0f172a;color:#f1f5f9;border:1px solid #475569;border-radius:0.5rem;padding:0.75rem 0.75rem 0.75rem 2.5rem;outline:none;transition:border-color .2s,box-shadow .2s;font-family:inherit;font-size:0.875rem"
                        onfocus="this.style.borderColor='#3b82f6';this.style.boxShadow='0 0 0 1px #3b82f6'"
                        onblur="this.style.borderColor='#475569';this.style.boxShadow='none'"
                        placeholder="<?php echo t('auth.username_placeholder', [], $lang); ?>" required>
                </div>
            </div>

            <!-- Password -->
            <div class="mb-8">
                <label class="block text-xs font-bold mb-2 uppercase tracking-wider" style="color:#cbd5e1"><?php echo t('auth.password', [], $lang); ?></label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center" style="color:#64748b">
                        <i class="fas fa-lock"></i>
                    </span>
                    <input type="password" name="password"
                        style="width:100%;background:#0f172a;color:#f1f5f9;border:1px solid #475569;border-radius:0.5rem;padding:0.75rem 0.75rem 0.75rem 2.5rem;outline:none;transition:border-color .2s,box-shadow .2s;font-family:inherit;font-size:0.875rem"
                        onfocus="this.style.borderColor='#3b82f6';this.style.boxShadow='0 0 0 1px #3b82f6'"
                        onblur="this.style.borderColor='#475569';this.style.boxShadow='none'"
                        placeholder="<?php echo t('auth.password_placeholder', [], $lang); ?>" required>
                </div>
            </div>

            <!-- Submit -->
            <button type="submit"
                style="width:100%;background:linear-gradient(to right,#2563eb,#7c3aed);color:#fff;font-weight:700;padding:0.75rem;border-radius:0.5rem;border:none;cursor:pointer;font-family:inherit;font-size:0.875rem;letter-spacing:0.01em;transition:filter .2s,transform .15s;box-shadow:0 4px 14px rgba(37,99,235,0.35)"
                onmouseover="this.style.filter='brightness(1.1)';this.style.transform='translateY(-1px)'"
                onmouseout="this.style.filter='';this.style.transform=''">
                <?php echo t('auth.login_button', [], $lang); ?> <i class="fas fa-arrow-right ml-2"></i>
            </button>
        </form>

        <!-- Footer -->
        <div class="p-4 text-center text-xs" style="background:#0f172a;border-top:1px solid #334155;color:#94a3b8">
            &copy; 2025 <?php echo t('auth.footer', [], $lang); ?>
        </div>
    </div>

</body>
</html>

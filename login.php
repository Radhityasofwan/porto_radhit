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
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #0f172a;
            background-image: radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), radial-gradient(at 50% 0%, hsla(225,39%,30%,1) 0, transparent 50%), radial-gradient(at 100% 0%, hsla(339,49%,30%,1) 0, transparent 50%);
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4 text-slate-200">

    <div class="w-full max-w-md bg-slate-800/80 backdrop-blur-md rounded-2xl shadow-2xl border border-slate-700 overflow-hidden">
        <div class="flex justify-end px-6 pt-6">
            <div class="flex items-center rounded-full border border-slate-600 p-1 bg-slate-900/40">
                <a href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>" class="px-3 py-1 rounded-full text-[11px] font-bold <?php echo $lang === 'id' ? 'bg-white text-slate-900' : 'text-slate-300'; ?>">ID</a>
                <a href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>" class="px-3 py-1 rounded-full text-[11px] font-bold <?php echo $lang === 'en' ? 'bg-white text-slate-900' : 'text-slate-300'; ?>">EN</a>
            </div>
        </div>
        
        <div class="p-8 text-center border-b border-slate-700">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-600/20 mb-4">
                <i class="fas fa-user-shield text-3xl text-blue-500"></i>
            </div>
            <h2 class="text-2xl font-bold text-white"><?php echo t('auth.welcome_back', [], $lang); ?></h2>
            <p class="text-slate-400 text-sm mt-2"><?php echo t('auth.login_subtitle', [], $lang); ?></p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-500/10 border-l-4 border-red-500 text-red-400 p-4 text-sm mx-8 mt-6">
                <i class="fas fa-exclamation-circle mr-2"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="p-8 pt-6">
            <div class="mb-5">
                <label class="block text-slate-400 text-xs font-bold mb-2 uppercase tracking-wider"><?php echo t('auth.username', [], $lang); ?></label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-500">
                        <i class="fas fa-user"></i>
                    </span>
                    <input type="text" name="username" class="w-full bg-slate-900/50 text-white border border-slate-600 rounded-lg py-3 pl-10 pr-3 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition placeholder-slate-600" placeholder="<?php echo t('auth.username_placeholder', [], $lang); ?>" required>
                </div>
            </div>

            <div class="mb-8">
                <label class="block text-slate-400 text-xs font-bold mb-2 uppercase tracking-wider"><?php echo t('auth.password', [], $lang); ?></label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-500">
                        <i class="fas fa-lock"></i>
                    </span>
                    <input type="password" name="password" class="w-full bg-slate-900/50 text-white border border-slate-600 rounded-lg py-3 pl-10 pr-3 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition" placeholder="<?php echo t('auth.password_placeholder', [], $lang); ?>" required>
                </div>
            </div>

            <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white font-bold py-3 rounded-lg shadow-lg transform transition hover:-translate-y-0.5">
                <?php echo t('auth.login_button', [], $lang); ?> <i class="fas fa-arrow-right ml-2"></i>
            </button>
        </form>
        
        <div class="bg-slate-900/50 p-4 text-center text-xs text-slate-500 border-t border-slate-700">
            &copy; 2025 <?php echo t('auth.footer', [], $lang); ?>
        </div>
    </div>

</body>
</html>

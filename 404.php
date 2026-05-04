<?php require_once 'i18n.php'; $lang = currentLang(); ?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo t('404.title', [], $lang); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-white flex items-center justify-center h-screen text-center px-6">
    <div>
        <h1 class="text-9xl font-extrabold text-indigo-500 opacity-20">404</h1>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
            <h2 class="text-3xl font-bold mb-4"><?php echo t('404.heading', [], $lang); ?></h2>
            <p class="text-slate-400 mb-8 max-w-md"><?php echo t('404.text', [], $lang); ?></p>
            <a href="<?php echo htmlspecialchars(localizedUrl('./', $lang)); ?>" class="px-8 py-3 bg-indigo-600 rounded-full font-bold hover:bg-indigo-500 transition"><?php echo t('404.home', [], $lang); ?></a>
        </div>
    </div>
</body>
</html>

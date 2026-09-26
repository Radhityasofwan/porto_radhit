<?php require_once 'i18n.php'; $lang = currentLang(); ?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <link rel="preload" href="/styles.min.css?v=4" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <title><?php echo t('404.title', [], $lang); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"></noscript>
    <link rel="stylesheet" href="/styles.min.css?v=4">

    <style>
        /* Critical: prevent background FOUC + font-display metric matching */
        html { background: #F8FAFC; }
        html.dark { background: #0F172A; }
        body {
            font-family: "Plus Jakarta Sans", "PJSFallback", system-ui, -apple-system, sans-serif;
            background: #F8FAFC;
            color: #1e293b;
            overflow-x: hidden;
            transition: background-color 0.5s ease;
            margin: 0;
        }
        html.dark body { background: #0F172A; color: #f1f5f4; }
        /* Font metric substitution: match Plus Jakarta Sans metrics with Arial fallback
           Reduces CLS when web font swaps in (font-display: swap) */
        @font-face {
            font-family: "PJSFallback";
            src: local("Arial");
            size-adjust: 97%;
            ascent-override: 94%;
            descent-override: 25%;
            line-gap-override: 0%;
        }
        /* Ensure navbar is hidden until JS/CSS loads to prevent layout flash */
        * { box-sizing: border-box; }
    </style>
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

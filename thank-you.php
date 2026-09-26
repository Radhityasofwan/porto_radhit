<?php
require_once 'i18n.php';
include 'db.php';
// db.php must load first: the default language lives in site_settings
$lang = currentLang();
$profile = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=1"));
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <link rel="preload" href="/styles.min.css?v=4" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('thankyou.title', [], $lang); ?> - <?php echo htmlspecialchars($profile['name']); ?></title>
    <!-- Masukkan Script Tracking Marketing di sini (Pixel/GTM) -->
    <?php echo $profile['custom_head_script']; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"></noscript>
    <link rel="stylesheet" href="/styles.min.css?v=4">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"></noscript>

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
<body class="bg-slate-50 flex items-center justify-center h-screen text-center px-4">

    <div class="max-w-md bg-white p-8 rounded-2xl shadow-xl border border-slate-100">
        <div class="w-20 h-20 bg-green-100 text-green-500 rounded-full flex items-center justify-center mx-auto mb-6 text-4xl">
            <i class="fas fa-check"></i>
        </div>
        <h1 class="text-2xl font-bold text-slate-800 mb-2"><?php echo t('thankyou.title', [], $lang); ?></h1>
        <p class="text-slate-500 mb-8"><?php echo t('thankyou.text', [], $lang); ?></p>
        
        <div class="flex flex-col gap-3">
            <a href="<?php echo htmlspecialchars(localizedUrl('index.php', $lang)); ?>" class="w-full bg-slate-900 text-white py-3 rounded-lg font-bold hover:bg-slate-800 transition"><?php echo t('thankyou.home', [], $lang); ?></a>
            <a href="<?php echo $profile['link_linkedin']; ?>" target="_blank" class="w-full bg-blue-50 text-blue-600 py-3 rounded-lg font-bold hover:bg-blue-100 transition border border-blue-100">
                <i class="fab fa-linkedin mr-2"></i> <?php echo t('thankyou.linkedin', [], $lang); ?>
            </a>
        </div>
    </div>

</body>
</html>

<?php 
require_once 'i18n.php';
include 'db.php'; 
$lang = currentLang();
$profile = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=1"));
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('thankyou.title', [], $lang); ?> - <?php echo htmlspecialchars($profile['name']); ?></title>
    <!-- Masukkan Script Tracking Marketing di sini (Pixel/GTM) -->
    <?php echo $profile['custom_head_script']; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

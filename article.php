<?php 
require_once 'i18n.php';

// 1. Optimasi GZIP untuk performa
if (!empty($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) ob_start("ob_gzhandler"); else ob_start();

$lang = currentLang();

include 'db.php'; 

// --- DEFINISI BASE URL ---
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$baseUrl = $protocol . "://" . $host . rtrim(str_replace('\\', '/', $scriptPath), '/') . '/';

// --- LOGIKA UTAMA: FETCH BY SLUG OR ID ---
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slug = isset($_GET['slug']) ? mysqli_real_escape_string($conn, $_GET['slug']) : '';

if ($slug) {
    $q = mysqli_query($conn, "SELECT * FROM articles WHERE slug='$slug'");
} else {
    $q = mysqli_query($conn, "SELECT * FROM articles WHERE id=$id");
}

$art = mysqli_fetch_assoc($q);
if (!$art) { 
    header("Location: " . localizedUrl('./', $lang)); 
    exit; 
}

// --- AUTO-REDIRECT KE CLEAN URL (301) ---
if (isset($_GET['id']) && !empty($art['slug'])) {
    $cleanUrl = $baseUrl . "blog/" . $art['slug'];
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: " . localizedUrl($cleanUrl, $lang));
    exit;
}

// Data Pendukung
$profile = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=1"));
$currentUrl = currentUrlWithLang($lang);
$articleTitle = localizedField($art, 'title', $lang);
$articleContent = localizedField($art, 'content', $lang);
$articleMeta = trim(localizedField($art, 'meta_desc', $lang));
if ($articleMeta === '') {
    $articleMeta = excerptText($articleContent, 150);
}
$profileBio = localizedField($profile, 'bio', $lang);
$profileRole = localizedField($profile, 'hero_role', $lang);

// Widget: Artikel Terbaru (Kecuali artikel ini)
$latestQ = mysqli_query($conn, "SELECT id, title, title_en, slug, image_url, created_at FROM articles WHERE id != {$art['id']} ORDER BY created_at DESC LIMIT 5");

// Helper Link
function getBlogLink($slug, $id, $lang) {
    global $baseUrl;
    if (!empty($slug)) return localizedUrl($baseUrl . "blog/" . $slug, $lang);
    return localizedUrl($baseUrl . "article.php?id=" . $id, $lang);
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($articleTitle); ?> - <?php echo htmlspecialchars($profile['name']); ?></title>
    
    <base href="<?php echo $baseUrl; ?>">

    <!-- SEO Meta Tags -->
    <meta name="description" content="<?php echo htmlspecialchars($articleMeta); ?>">
    <link rel="canonical" href="<?php echo $currentUrl; ?>">
    <link rel="alternate" hreflang="id" href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>">
    <link rel="alternate" hreflang="en" href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>">
    
    <!-- Open Graph -->
    <meta property="og:title" content="<?php echo htmlspecialchars($articleTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($articleMeta); ?>">
    <meta property="og:image" content="<?php echo $baseUrl . $art['image_url']; ?>">
    <meta property="og:type" content="article">

    <!-- Marketing Scripts -->
    <?php echo $profile['custom_head_script']; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <script> 
        tailwind.config = { 
            darkMode: 'class', 
            theme: { 
                extend: { 
                    colors: { 
                        darkbg: '#0F172A', darkcard: '#1E293B', 
                        lightbg: '#F8FAFC', lightcard: '#FFFFFF', 
                        primary: '#6366F1', secondary: '#8B5CF6' 
                    },
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                    }
                } 
            } 
        } 
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" rel="stylesheet">
    <style> 
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap'); 
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
    <script>if (localStorage.theme === 'light') { document.documentElement.classList.remove('dark'); } else { document.documentElement.classList.add('dark'); }</script>
</head>
<body class="bg-lightbg text-slate-800 dark:bg-darkbg dark:text-slate-100 selection:bg-primary selection:text-white">

    <!-- NAVBAR FLOATING -->
    <div class="fixed top-0 left-0 right-0 z-50 flex justify-center px-4 pt-4 transition-all duration-300">
        <nav class="bg-white/90 dark:bg-slate-900/90 backdrop-blur-md border border-slate-200 dark:border-white/10 rounded-full px-6 py-3 w-full max-w-7xl flex justify-between items-center shadow-xl">
            <a href="<?php echo htmlspecialchars(localizedUrl('./', $lang)); ?>" class="text-sm font-bold tracking-tight flex items-center gap-2 hover:text-primary transition group">
                <span class="w-8 h-8 rounded-full bg-slate-100 dark:bg-white/10 flex items-center justify-center group-hover:bg-primary group-hover:text-white transition"><i class="fas fa-arrow-left"></i></span>
                <span class="hidden sm:inline"><?php echo t('article.back_home', [], $lang); ?></span>
            </a>
            
            <div class="flex items-center gap-3">
                <span class="text-xs font-bold uppercase tracking-widest text-slate-400 hidden md:block border-r border-slate-200 dark:border-white/10 pr-4 mr-1">
                    <?php echo t('article.blog_insights', [], $lang); ?>
                </span>
                <div class="flex items-center rounded-full border border-slate-200 dark:border-white/10 p-1">
                    <a href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>" class="px-2.5 py-1 rounded-full text-[10px] font-bold <?php echo $lang === 'id' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-500 dark:text-slate-300'; ?>">ID</a>
                    <a href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>" class="px-2.5 py-1 rounded-full text-[10px] font-bold <?php echo $lang === 'en' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-500 dark:text-slate-300'; ?>">EN</a>
                </div>
                <button id="theme-toggle" class="w-9 h-9 rounded-full bg-slate-50 dark:bg-white/5 flex items-center justify-center text-yellow-500 dark:text-blue-300 hover:scale-110 transition border border-slate-200 dark:border-white/10">
                    <i class="fas fa-sun hidden dark:block"></i><i class="fas fa-moon block dark:hidden"></i>
                </button>
            </div>
        </nav>
    </div>

    <!-- HEADER SECTION (FULL WIDTH) -->
    <header class="relative pt-32 pb-12 lg:pt-40 lg:pb-20 px-6">
        <div class="max-w-7xl mx-auto">
            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-400 mb-6 justify-center lg:justify-start">
                <a href="<?php echo htmlspecialchars(localizedUrl('./', $lang)); ?>" class="hover:text-primary transition"><?php echo t('nav.home', [], $lang); ?></a>
                <i class="fas fa-chevron-right text-[10px]"></i>
                <a href="<?php echo htmlspecialchars(localizedUrl('./#blog', $lang)); ?>" class="hover:text-primary transition"><?php echo t('nav.articles', [], $lang); ?></a>
                <i class="fas fa-chevron-right text-[10px]"></i>
                <span class="text-primary truncate max-w-[150px]"><?php echo t('article.current', [], $lang); ?></span>
            </div>

            <h1 class="text-3xl md:text-5xl lg:text-6xl font-extrabold leading-tight mb-8 text-slate-900 dark:text-white text-center lg:text-left max-w-5xl">
                <?php echo htmlspecialchars($articleTitle); ?>
            </h1>

            <div class="flex flex-col lg:flex-row items-center gap-6 border-b border-slate-200 dark:border-white/10 pb-8">
                <div class="flex items-center gap-4">
                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-12 h-12 rounded-full border-2 border-white dark:border-white/10 shadow-md object-cover">
                    <div>
                        <div class="text-sm font-bold text-slate-900 dark:text-white"><?php echo $profile['name']; ?></div>
                        <div class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-2">
                            <span><?php echo formatLocalizedDate($art['created_at'], $lang); ?></span>
                            <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                            <span><?php echo ceil(str_word_count(strip_tags($articleContent)) / 200); ?> <?php echo t('article.min_read', [], $lang); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Share Buttons (Header) -->
                <div class="lg:ml-auto flex gap-3">
                    <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo urlencode($currentUrl); ?>" target="_blank" class="w-10 h-10 rounded-full bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-center text-[#0077b5] hover:bg-[#0077b5] hover:text-white hover:border-[#0077b5] transition-all" title="<?php echo t('article.share_linkedin', [], $lang); ?>"><i class="fab fa-linkedin-in"></i></a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($currentUrl); ?>" target="_blank" class="w-10 h-10 rounded-full bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-center text-[#1877F2] hover:bg-[#1877F2] hover:text-white hover:border-[#1877F2] transition-all" title="<?php echo t('article.share_facebook', [], $lang); ?>"><i class="fab fa-facebook-f"></i></a>
                    <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode($articleTitle); ?>&url=<?php echo urlencode($currentUrl); ?>" target="_blank" class="w-10 h-10 rounded-full bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-center text-slate-800 dark:text-white hover:bg-black hover:text-white hover:border-black transition-all" title="<?php echo t('article.share_x', [], $lang); ?>"><i class="fab fa-x-twitter"></i></a>
                    <a href="https://wa.me/?text=<?php echo urlencode($articleTitle . ' ' . $currentUrl); ?>" target="_blank" class="w-10 h-10 rounded-full bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-center text-[#25D366] hover:bg-[#25D366] hover:text-white hover:border-[#25D366] transition-all" title="<?php echo t('article.share_whatsapp', [], $lang); ?>"><i class="fab fa-whatsapp"></i></a>
                </div>
            </div>
        </div>
    </header>

    <div class="max-w-7xl mx-auto px-6 pb-24">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12">
            
            <!-- MAIN CONTENT (Left - 8 Columns) -->
            <article class="lg:col-span-8">
                <?php if($art['image_url']): ?>
                    <div class="rounded-3xl overflow-hidden mb-10 border border-slate-200 dark:border-white/10 shadow-2xl relative group">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent opacity-0 group-hover:opacity-100 transition duration-500"></div>
                        <img src="<?php echo $art['image_url']; ?>" class="w-full h-auto object-cover transform group-hover:scale-105 transition duration-700 ease-out">
                    </div>
                <?php endif; ?>

                <div class="prose prose-lg prose-slate dark:prose-invert max-w-none 
                    prose-headings:font-bold prose-headings:tracking-tight 
                    prose-a:text-primary prose-a:no-underline hover:prose-a:underline 
                    prose-img:rounded-2xl prose-img:shadow-lg
                    prose-blockquote:border-l-primary prose-blockquote:bg-slate-50 dark:prose-blockquote:bg-white/5 prose-blockquote:py-2 prose-blockquote:px-6 prose-blockquote:not-italic prose-blockquote:rounded-r-lg">
                    <?php echo $articleContent; ?>
                </div>

                <!-- Call to Action (Bottom Article) -->
                <div class="mt-16 bg-slate-900 dark:bg-primary/20 rounded-3xl p-8 text-center border border-slate-800 dark:border-primary/30 relative overflow-hidden">
                    <div class="absolute top-0 right-0 -mr-16 -mt-16 w-64 h-64 bg-primary/20 rounded-full blur-3xl"></div>
                    <h3 class="text-2xl font-bold text-white mb-2 relative z-10"><?php echo t('article.like', [], $lang); ?></h3>
                    <p class="text-slate-300 mb-6 relative z-10"><?php echo t('article.cta', [], $lang); ?></p>
                    <a href="https://wa.me/<?php echo $profile['whatsapp']; ?>" target="_blank" class="inline-block px-8 py-3 bg-white text-slate-900 rounded-full font-bold hover:bg-primary hover:text-white transition transform hover:-translate-y-1 shadow-lg relative z-10">
                        <?php echo t('article.contact', [], $lang); ?> <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            </article>

            <!-- SIDEBAR (Right - 4 Columns) -->
            <aside class="lg:col-span-4 space-y-8">
                
                <!-- Widget: Author -->
                <div class="bg-white dark:bg-darkcard p-6 rounded-3xl border border-slate-200 dark:border-white/5 shadow-lg sticky top-24">
                    <div class="flex items-center gap-4 mb-4">
                        <img src="<?php echo $profile['profile_photo']; ?>" class="w-16 h-16 rounded-full border-4 border-slate-50 dark:border-white/5 object-cover">
                        <div>
                            <h4 class="font-bold text-lg text-slate-900 dark:text-white"><?php echo $profile['name']; ?></h4>
                            <p class="text-xs font-bold text-primary uppercase tracking-wider"><?php echo htmlspecialchars($profileRole); ?></p>
                        </div>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-6 leading-relaxed">
                        <?php echo htmlspecialchars(excerptText($profileBio, 150)); ?>
                    </p>
                    <a href="<?php echo htmlspecialchars(localizedUrl('./#about', $lang)); ?>" class="block w-full py-3 text-center rounded-xl border border-slate-200 dark:border-white/10 text-sm font-bold hover:bg-slate-50 dark:hover:bg-white/5 transition"><?php echo t('article.full_profile', [], $lang); ?></a>
                </div>

                <!-- Widget: Latest Posts -->
                <div class="bg-white dark:bg-darkcard p-6 rounded-3xl border border-slate-200 dark:border-white/5 shadow-lg">
                    <h3 class="font-bold text-lg text-slate-900 dark:text-white mb-6 flex items-center gap-2">
                        <span class="w-1 h-6 bg-primary rounded-full"></span> <?php echo t('article.latest', [], $lang); ?>
                    </h3>
                    <div class="space-y-6">
                        <?php while($latest = mysqli_fetch_assoc($latestQ)): ?>
                        <a href="<?php echo getBlogLink($latest['slug'], $latest['id'] ?? 0, $lang); ?>" class="flex gap-4 group">
                            <?php if(!empty($latest['image_url'])): ?>
                            <div class="w-20 h-20 flex-shrink-0 rounded-xl overflow-hidden">
                                <img src="<?php echo $latest['image_url']; ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                            </div>
                            <?php endif; ?>
                            <div class="flex-1">
                                <h4 class="text-sm font-bold text-slate-800 dark:text-slate-200 leading-snug group-hover:text-primary transition line-clamp-2">
                                    <?php echo htmlspecialchars(localizedField($latest, 'title', $lang)); ?>
                                </h4>
                                <span class="text-[10px] font-bold text-slate-400 mt-2 block uppercase tracking-wider">
                                    <?php echo formatLocalizedDate($latest['created_at'], $lang); ?>
                                </span>
                            </div>
                        </a>
                        <?php endwhile; ?>
                    </div>
                </div>

                <!-- Widget: Connect -->
                <div class="bg-gradient-to-br from-indigo-600 to-purple-700 p-6 rounded-3xl text-white shadow-xl relative overflow-hidden">
                    <div class="relative z-10">
                        <h3 class="font-bold text-lg mb-2"><?php echo t('article.follow_updates', [], $lang); ?></h3>
                        <p class="text-indigo-100 text-sm mb-6"><?php echo t('article.follow_text', [], $lang); ?></p>
                        <div class="flex gap-3">
                            <a href="<?php echo $profile['link_linkedin']; ?>" target="_blank" class="w-10 h-10 rounded-full bg-white/20 backdrop-blur flex items-center justify-center hover:bg-white hover:text-indigo-600 transition"><i class="fab fa-linkedin-in"></i></a>
                            <a href="<?php echo $profile['link_instagram']; ?>" target="_blank" class="w-10 h-10 rounded-full bg-white/20 backdrop-blur flex items-center justify-center hover:bg-white hover:text-pink-600 transition"><i class="fab fa-instagram"></i></a>
                            <a href="<?php echo $profile['link_twitter']; ?>" target="_blank" class="w-10 h-10 rounded-full bg-white/20 backdrop-blur flex items-center justify-center hover:bg-white hover:text-black transition"><i class="fab fa-x-twitter"></i></a>
                        </div>
                    </div>
                    <div class="absolute -bottom-6 -right-6 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
                </div>

            </aside>
        </div>
    </div>

    <footer class="py-10 border-t border-slate-200 dark:border-white/5 text-center text-slate-500 text-sm bg-white dark:bg-black/20">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($profile['name']); ?>. <?php echo t('footer.rights', [], $lang); ?>
    </footer>

    <script>
        document.getElementById('theme-toggle').addEventListener('click', () => {
            if (document.documentElement.classList.contains('dark')) { document.documentElement.classList.remove('dark'); localStorage.theme = 'light'; } else { document.documentElement.classList.add('dark'); localStorage.theme = 'dark'; }
        });
    </script>
</body>
</html>

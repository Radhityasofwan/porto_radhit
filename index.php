<?php 
require_once 'i18n.php';

// 1. OPTIMASI: Aktifkan Kompresi GZIP
if (!empty($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) ob_start("ob_gzhandler"); else ob_start();

$lang = currentLang();

include 'db.php';
$profile = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE is_active=1 LIMIT 1"));
if (!$profile) $profile = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile ORDER BY id ASC LIMIT 1"));
$activeProfileId = (int)($profile['id'] ?? 1);
$siteTitle = localizedField($profile, 'site_title', $lang);
$profileBio = localizedField($profile, 'bio', $lang);
$heroRoleText = localizedField($profile, 'hero_role', $lang);
$availabilityText = localizedField($profile, 'availability_text', $lang);
$projectsDesc = localizedField($profile, 'projects_desc', $lang);
$contactDesc = localizedField($profile, 'contact_desc', $lang);
$seoKeywords = localizedField($profile, 'seo_keywords', $lang);
$firstName = explode(' ', trim((string) $profile['name']))[0] ?? $profile['name'];

$projects = [];
$projectsQ = mysqli_query($conn, "SELECT * FROM projects WHERE profile_id=$activeProfileId ORDER BY display_order ASC");
while($projectRow = mysqli_fetch_assoc($projectsQ)) {
    $projects[] = $projectRow;
}

// --- LOGIKA FETCH CHAT DATA ---
$chatData = [];
$cq = mysqli_query($conn, "SELECT * FROM hero_chat WHERE profile_id=$activeProfileId ORDER BY display_order ASC");
while($row = mysqli_fetch_assoc($cq)) {
    $chatData[] = $row;
}

// Konversi ke format JS
$jsScript = [];
$totalChat = count($chatData);

if ($totalChat > 0) {
    // 1. Step Awal
    $jsScript[] = [
        'question' => localizedField($chatData[0], 'question', $lang),
        'options' => [
            ['text' => t('chat.yes', [], $lang), 'next' => 1, 'variant' => 'primary'],
            ['text' => t('chat.no', [], $lang), 'action' => 'close', 'variant' => 'secondary']
        ]
    ];

    // 2. Loop sisa data
    for ($i = 0; $i < $totalChat; $i++) {
        $current = $chatData[$i];
        $nextIndex = $i + 1; 
        $nextRow = isset($chatData[$i+1]) ? $chatData[$i+1] : null;

        $options = [];
        if ($nextRow) {
            $options = [
                ['text' => t('chat.next', [], $lang), 'next' => $nextIndex + 1, 'variant' => 'primary'],
                ['text' => t('chat.enough', [], $lang), 'action' => 'close', 'variant' => 'secondary']
            ];
            $jsScript[$nextIndex] = [
                'answer' => localizedField($current, 'answer', $lang),
                'nextQuestion' => localizedField($nextRow, 'question', $lang),
                'options' => $options
            ];
        } elseif ($current['type'] == 'cta') {
            $jsScript[$nextIndex] = [
                'answer' => localizedField($current, 'answer', $lang), 
                'nextQuestion' => t('chat.interested', [], $lang), 
                'options' => [
                    ['text' => t('chat.view_detail', [], $lang), 'action' => 'cta', 'variant' => 'primary'],
                    ['text' => t('chat.close', [], $lang), 'action' => 'close', 'variant' => 'secondary']
                ]
            ];
        } else {
            $jsScript[$nextIndex] = [
                'answer' => localizedField($current, 'answer', $lang),
                'nextQuestion' => t('chat.thanks', [], $lang),
                'options' => [
                    ['text' => t('chat.close', [], $lang), 'action' => 'close', 'variant' => 'secondary']
                ]
            ];
        }
    }
}

// --- HELPER FUNCTIONS (SEO FRIENDLY URLS) ---
function getProjectLink($p, $lang = null) {
    if (!empty($p['slug'])) return localizedUrl("portfolio/" . $p['slug'], $lang ?? currentLang());
    return localizedUrl("project-details.php?id=" . $p['id'], $lang ?? currentLang());
}

function getBlogLink($b, $lang = null) {
    if (!empty($b['slug'])) return localizedUrl("blog/" . $b['slug'], $lang ?? currentLang());
    return localizedUrl("article.php?id=" . $b['id'], $lang ?? currentLang());
}

function getLogoUrl($url) {
    if (empty($url)) return '';
    if (preg_match('/^\d+x\d+/', $url)) return 'https://via.placeholder.com/' . $url;
    return $url;
}

$categoriesMap = [];
foreach($projects as $projectItem) {
    $localizedCategory = localizedField($projectItem, 'category', $lang);
    if (!empty($localizedCategory)) {
        $categoriesMap[$localizedCategory] = true;
    }
}
$categories = array_keys($categoriesMap);
sort($categories);

// Helper URL Dinamis
$currentUrl = currentUrlWithLang($lang);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($siteTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($profileBio); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($seoKeywords); ?>">
    <link rel="canonical" href="<?php echo $currentUrl; ?>">
    <link rel="alternate" hreflang="id" href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>">
    <link rel="alternate" hreflang="en" href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>">

    <!-- OG Tags (Social Media Sharing) -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo $currentUrl; ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($siteTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($profileBio); ?>">
    <meta property="og:image" content="<?php echo $profile['hero_image_url']; ?>">

    <!-- Marketing Scripts (GTM/Pixel/Analytics) -->
    <?php echo $profile['custom_head_script']; ?>

    <!-- Preload Hero Image (LCP Optimization) -->
    <?php if(!empty($profile['hero_image_url'])): ?>
    <link rel="preload" as="image" href="<?php echo $profile['hero_image_url']; ?>" fetchpriority="high">
    <?php endif; ?>
    
    <link rel="icon" href="<?php echo !empty($profile['profile_photo']) ? $profile['profile_photo'] : ''; ?>" type="image/x-icon">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"></noscript>
    <link rel="stylesheet" href="/styles.min.css?v=4">
    
    
    <style>
        /* Critical CSS – above-fold essentials while styles.min.css loads */
        *,*::before,*::after{box-sizing:border-box}
        html{background:#F8FAFC;scroll-behavior:smooth}
        html.dark{background:#0F172A}
        body{font-family:"Plus Jakarta Sans","PJSFallback",system-ui,-apple-system,sans-serif;background:#F8FAFC;color:#1e293b;overflow-x:hidden;margin:0}
        html.dark body{background:#0F172A;color:#f1f5f4}
        @font-face{font-family:"PJSFallback";src:local("Arial");size-adjust:97%;ascent-override:94%;descent-override:25%;line-gap-override:0%}
        #desktop-nav{position:fixed;top:0;left:0;right:0;z-index:50;display:none}
        @media(min-width:1024px){#desktop-nav{display:flex;justify-content:center;padding:1.5rem 2rem 1rem}}
        #mobile-sidebar{position:fixed;top:0;right:0;z-index:70;height:100%;width:280px}
        img{max-width:100%;height:auto}
        #home{min-height:95vh}
        .glass-pill{background:rgba(255,255,255,0.85);-webkit-backdrop-filter:blur(16px);backdrop-filter:blur(16px);border-radius:9999px;border:1px solid rgba(255,255,255,0.6)}
        html.dark .glass-pill{background:rgba(15,23,42,0.85);border-color:rgba(255,255,255,0.1)}
        /* Prevent double icons before Tailwind loads */
        .hidden{display:none}
        html.dark .dark\:block{display:block}
        html.dark .dark\:hidden{display:none}
        html.dark .dark\:flex{display:flex}
        /* Clients track */
        #clients-track,#clients-track-mobile{cursor:grab;user-select:none;will-change:transform;transition:none;display:flex;align-items:center;gap:8px}
        @media(min-width:640px){#clients-track,#clients-track-mobile{gap:10px}}
        @media(min-width:768px){#clients-track,#clients-track-mobile{gap:12px}}
        #clients-track:active,#clients-track-mobile:active{cursor:grabbing}
        .client-logo-item{flex-shrink:0;display:flex;align-items:center;justify-content:center;width:110px;height:56px}
        .client-logo-item img{max-width:86px;max-height:36px;width:auto;height:auto;object-fit:contain;filter:none;opacity:1;transition:transform .25s ease,opacity .2s ease}
        .client-logo-item:hover img{transform:scale(1.07);opacity:.85}
        @media(min-width:640px){.client-logo-item{width:124px;height:60px}.client-logo-item img{max-width:96px;max-height:40px}}
        @media(min-width:768px){.client-logo-item{width:140px;height:64px}.client-logo-item img{max-width:108px;max-height:44px}}
        #clients-desktop-section{display:none}
        @media(min-width:1024px){#clients-desktop-section{display:block}}
    </style>
    <script>if(localStorage.theme==='light'){document.documentElement.classList.remove('dark')}else{document.documentElement.classList.add('dark')}</script>
</head>
<body class="bg-lightbg text-slate-800 dark:bg-darkbg dark:text-slate-100 overflow-x-hidden">

    <div id="fireflies-container" class="fixed inset-0 pointer-events-none z-0 hidden dark:block"></div>
    <div class="fixed top-0 left-0 w-full h-full overflow-hidden -z-10 pointer-events-none">
        <div class="absolute top-[-10%] left-[-10%] w-[500px] h-[500px] bg-primary/20 rounded-full blur-[100px] opacity-30 animate-float"></div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[400px] h-[400px] bg-secondary/20 rounded-full blur-[100px] opacity-30 animate-float" style="animation-delay: 2s;"></div>
    </div>

    <!-- ========================================= -->
    <!-- 1. NAVIGATION (DESKTOP) - TETAP SAMA     -->
    <!-- ========================================= -->
    <div class="hidden lg:flex fixed top-0 left-0 right-0 z-50 justify-center pt-6 px-8 pb-4 transition-all duration-300" id="desktop-nav">
        <nav class="glass-pill rounded-full px-6 py-3 w-full max-w-5xl flex justify-between items-center shadow-lg hover:shadow-xl transition-all">
            <a href="#home" class="flex items-center gap-3 group">
                 <?php if(!empty($profile['profile_photo'])): ?>
                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-9 h-9 rounded-full object-cover border-2 border-slate-200 dark:border-white/10 group-hover:rotate-12 transition shadow-sm" width="36" height="36" decoding="async">
                <?php else: ?>
                    <span class="w-9 h-9 rounded-full bg-gradient-to-tr from-primary to-secondary flex items-center justify-center text-white text-xs group-hover:rotate-12 transition"><?php echo substr($profile['name'], 0, 1); ?></span>
                <?php endif; ?>
                <span class="font-bold text-lg tracking-tight">Radhitya<span class="text-primary">sofwan</span></span>
            </a>
            
            <div class="flex items-center gap-1">
                <a href="#about" class="px-5 py-2 rounded-full text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-primary hover:bg-slate-100 dark:hover:bg-white/10 transition"><?php echo t('nav.about', [], $lang); ?></a>
                <a href="#projects" class="px-5 py-2 rounded-full text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-primary hover:bg-slate-100 dark:hover:bg-white/10 transition"><?php echo t('nav.portfolio', [], $lang); ?></a>
                <a href="#skills" class="px-5 py-2 rounded-full text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-primary hover:bg-slate-100 dark:hover:bg-white/10 transition"><?php echo t('nav.skills', [], $lang); ?></a>
                <a href="#blog" class="px-5 py-2 rounded-full text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-primary hover:bg-slate-100 dark:hover:bg-white/10 transition"><?php echo t('nav.articles', [], $lang); ?></a>
            </div>

            <div class="flex items-center gap-3">
                <div class="flex items-center rounded-full border border-slate-200 dark:border-white/10 p-1">
                    <a href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>" class="px-3 py-1.5 rounded-full text-[11px] font-bold transition <?php echo $lang === 'id' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-500 dark:text-slate-300 hover:text-slate-700 dark:hover:text-white'; ?>">ID</a>
                    <a href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>" class="px-3 py-1.5 rounded-full text-[11px] font-bold transition <?php echo $lang === 'en' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-500 dark:text-slate-300 hover:text-slate-700 dark:hover:text-white'; ?>">EN</a>
                </div>
                <button id="theme-toggle-desktop" aria-label="<?php echo $lang === 'en' ? 'Toggle dark mode' : 'Ganti tema'; ?>" class="w-10 h-10 rounded-full bg-slate-100 dark:bg-white/5 flex items-center justify-center hover:scale-105 transition text-yellow-500 dark:text-slate-400 border border-slate-200 dark:border-white/10">
                    <i class="fas fa-sun hidden dark:block"></i><i class="fas fa-moon block dark:hidden"></i>
                </button>
                <a href="#contact" class="px-6 py-2.5 rounded-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-sm font-bold shadow-lg hover:shadow-xl transition transform hover:-translate-y-0.5">
                    <?php echo t('nav.contact', [], $lang); ?>
                </a>
            </div>
        </nav>
    </div>

    <!-- ========================================= -->
    <!-- 2. MOBILE HEADER & SIDEBAR (UPDATED)     -->
    <!-- ========================================= -->
    
    <!-- Mobile Header (Top) - NOW FLOATING CAPSULE -->
    <div class="lg:hidden fixed top-0 left-0 right-0 z-50 flex justify-center pt-4 px-4 transition-all">
        <nav class="glass-pill rounded-full px-5 py-3 w-full flex justify-between items-center shadow-lg hover:shadow-xl transition-all">
            <!-- Logo Left -->
            <a href="#home" class="flex items-center gap-2 group">
                <?php if(!empty($profile['profile_photo'])): ?>
                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-8 h-8 rounded-full object-cover border border-slate-200 dark:border-white/10" width="32" height="32" decoding="async">
                <?php else: ?>
                    <span class="w-8 h-8 rounded-full bg-gradient-to-tr from-primary to-secondary flex items-center justify-center text-white text-xs font-bold"><?php echo substr($profile['name'], 0, 1); ?></span>
                <?php endif; ?>
                <span class="font-bold text-base tracking-tight text-slate-900 dark:text-white">Radhitya<span class="text-primary">sofwan</span></span>
            </a>

            <!-- Right Actions (Theme + Hamburger) -->
            <div class="flex items-center gap-2">
                <button id="theme-toggle-mobile" aria-label="<?php echo $lang === 'en' ? 'Toggle dark mode' : 'Ganti tema'; ?>" class="w-9 h-9 rounded-full bg-slate-100 dark:bg-white/5 flex items-center justify-center text-yellow-500 dark:text-blue-300 border border-slate-200 dark:border-white/10">
                    <i class="fas fa-sun hidden dark:block text-xs"></i><i class="fas fa-moon block dark:hidden text-xs"></i>
                </button>
                <button id="mobile-menu-btn" aria-label="<?php echo $lang === 'en' ? 'Open navigation menu' : 'Buka menu navigasi'; ?>" aria-expanded="false" aria-controls="mobile-sidebar" class="w-9 h-9 flex items-center justify-center text-slate-700 dark:text-white bg-slate-100 dark:bg-white/5 rounded-full border border-slate-200 dark:border-white/10">
                    <i class="fas fa-bars text-lg"></i>
                </button>
            </div>
        </nav>
    </div>

    <!-- Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 z-[60] bg-black/50 backdrop-blur-sm hidden opacity-0 transition-opacity duration-300 lg:hidden" onclick="toggleSidebar()"></div>

    <!-- Sidebar Content - NOW GLASSY -->
    <div id="mobile-sidebar" class="fixed top-0 right-0 z-[70] h-full w-[280px] bg-white/90 dark:bg-slate-900/90 backdrop-blur-xl shadow-2xl border-l border-slate-200 dark:border-white/5 transform translate-x-full sidebar-transition lg:hidden flex flex-col">
        
        <!-- Sidebar Header -->
        <div class="p-6 flex items-center justify-between border-b border-slate-100 dark:border-white/5">
            <span class="font-bold text-lg text-slate-900 dark:text-white"><?php echo t('nav.menu', [], $lang); ?></span>
            <button onclick="toggleSidebar()" aria-label="<?php echo $lang === 'en' ? 'Close navigation menu' : 'Tutup menu navigasi'; ?>" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 dark:bg-white/5 text-slate-500 dark:text-slate-400 hover:text-red-500 transition">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Sidebar Links -->
        <div class="flex flex-col p-6 gap-2 overflow-y-auto flex-grow">
            <?php 
            $menuItems = [
                ['label' => t('nav.home', [], $lang), 'href' => '#home', 'icon' => 'fas fa-home'],
                ['label' => t('nav.about_me', [], $lang), 'href' => '#about', 'icon' => 'fas fa-user'],
                ['label' => t('nav.portfolio', [], $lang), 'href' => '#projects', 'icon' => 'fas fa-laptop-code'],
                ['label' => t('nav.skills', [], $lang), 'href' => '#skills', 'icon' => 'fas fa-tools'],
                ['label' => t('nav.articles', [], $lang), 'href' => '#blog', 'icon' => 'fas fa-newspaper'],
                ['label' => t('nav.contact', [], $lang), 'href' => '#contact', 'icon' => 'fas fa-paper-plane'],
            ];
            foreach($menuItems as $item): ?>
                <a href="<?php echo $item['href']; ?>" onclick="toggleSidebar()" class="flex items-center gap-4 px-4 py-3 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-primary/10 hover:text-primary dark:hover:bg-primary/20 transition font-medium group">
                    <span class="w-8 h-8 flex items-center justify-center rounded-lg bg-slate-100 dark:bg-white/5 group-hover:bg-white dark:group-hover:bg-white/10 transition text-slate-400 group-hover:text-primary text-sm">
                        <i class="<?php echo $item['icon']; ?>"></i>
                    </span>
                    <?php echo $item['label']; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Sidebar Footer -->
        <div class="p-6 border-t border-slate-100 dark:border-white/5 bg-slate-50/50 dark:bg-black/20">
            <div class="flex items-center justify-center gap-2 mb-4">
                <a href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>" class="px-3 py-1 rounded-full text-[10px] font-bold <?php echo $lang === 'id' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-white dark:bg-white/5 text-slate-500 dark:text-slate-300 border border-slate-200 dark:border-white/10'; ?>">ID</a>
                <a href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>" class="px-3 py-1 rounded-full text-[10px] font-bold <?php echo $lang === 'en' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-white dark:bg-white/5 text-slate-500 dark:text-slate-300 border border-slate-200 dark:border-white/10'; ?>">EN</a>
            </div>
            <p class="text-[10px] text-center text-slate-400 font-bold uppercase tracking-wider mb-4"><?php echo t('nav.connected', [], $lang); ?></p>
            <div class="flex justify-center gap-3">
                 <a href="https://wa.me/<?php echo $profile['whatsapp']; ?>" aria-label="WhatsApp" class="w-10 h-10 rounded-full bg-green-500 text-white flex items-center justify-center shadow hover:bg-green-600 transition-colors"><i class="fab fa-whatsapp" aria-hidden="true"></i></a>
                 <a href="mailto:<?php echo $profile['email']; ?>" aria-label="<?php echo $lang === 'en' ? 'Send Email' : 'Kirim Email'; ?>" class="w-10 h-10 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center hover:bg-primary hover:text-white transition-colors"><i class="far fa-envelope" aria-hidden="true"></i></a>
            </div>
        </div>
    </div>
    <!-- ========================================= -->
    <!-- END MOBILE NAVIGATION                     -->
    <!-- ========================================= -->


    <main id="main-content">

    <!-- HERO SECTION -->
    <section id="home" class="relative flex flex-col lg:flex-row items-center w-full overflow-hidden pt-24 lg:pt-40 bg-gradient-to-br from-white via-slate-100 to-slate-200 dark:from-darkbg dark:via-slate-950 dark:to-darkbg">
        <!-- Text Content -->
        <div class="w-full lg:w-1/2 px-6 lg:pl-20 text-center lg:text-left z-20 order-1">
            <div data-aos="fade-up">
                <div class="inline-flex items-center px-4 py-1.5 rounded-full border border-green-500/30 bg-green-500/10 text-green-600 dark:text-green-400 text-[10px] md:text-xs font-bold uppercase mb-6 tracking-wide shadow-sm mx-auto lg:mx-0">
                    <span class="w-2 h-2 rounded-full bg-green-500 mr-2 animate-pulse"></span> <?php echo htmlspecialchars($availabilityText); ?>
                </div>
                
                <h1 class="text-4xl md:text-6xl lg:text-7xl font-extrabold mb-6 leading-tight tracking-tight text-slate-900 dark:text-white text-balance">
                    <?php 
                    $roles = explode(' ', $heroRoleText);
                    $lastRole = array_pop($roles);
                    echo implode(' ', $roles); 
                    ?> 
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary to-secondary"><?php echo $lastRole; ?>.</span>
                </h1>
                
                <p class="text-slate-600 dark:text-slate-300 text-base md:text-lg mb-8 max-w-lg mx-auto lg:mx-0 leading-relaxed font-light"><?php echo nl2br(htmlspecialchars($profileBio)); ?></p>
                
                <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start items-center">
                    <a href="#projects" data-track-event="hero_cta_portfolio" class="px-8 py-3.5 bg-primary hover:bg-secondary text-white rounded-full font-bold shadow-lg shadow-primary/25 hover:shadow-primary/40 transition transform hover:-translate-y-1 w-full sm:w-auto text-center"><?php echo t('hero.cta_portfolio', [], $lang); ?></a>
                    <?php if($profile['cv_url']): ?><a href="<?php echo $profile['cv_url']; ?>" data-track-event="hero_cv_download" class="px-8 py-3.5 glass-pill rounded-full font-bold hover:bg-white/20 transition flex items-center justify-center gap-2 w-full sm:w-auto text-center"><i class="fas fa-download"></i> <?php echo t('hero.cta_cv', [], $lang); ?></a><?php endif; ?>
                </div>
                
            </div>
        </div>

        <!-- Hero Image & Chat -->
        <div id="hero-image-wrapper" class="w-full lg:w-1/2 h-[55vh] lg:h-[calc(100vh-10rem)] relative flex flex-col items-center order-2 -mt-4 lg:mt-0 transition-all duration-700 ease-in-out" data-aos="fade-left">

            <?php if($totalChat > 0): ?>
            <div id="hero-chat" class="shrink-0 relative z-40 w-[calc(100%-2rem)] max-w-[280px] lg:max-w-[350px] bg-white/95 dark:bg-slate-900/95 p-3 rounded-[1.75rem] shadow-2xl border border-slate-200/70 dark:border-slate-700/80 hidden animate-pop-in mt-4 mb-3 mx-auto">
                <div class="absolute -bottom-2 left-1/2 -translate-x-1/2 w-4 h-4 bg-white/95 dark:bg-slate-900/95 rotate-45 border-b border-r border-slate-200/70 dark:border-slate-700/80"></div>
                <div class="flex items-center gap-2 mb-2 pb-2 border-b border-slate-100/80 dark:border-slate-700">
                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-5 h-5 rounded-full object-cover">
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"><?php echo t('hero.typing', ['name' => $firstName], $lang); ?></span>
                </div>
                <div id="chat-text" class="text-xs md:text-sm font-medium text-slate-700 dark:text-slate-200 leading-relaxed mb-2 whitespace-pre-wrap break-words"></div>
                <div id="chat-options" class="flex gap-2 justify-end opacity-0 transition-opacity duration-300"></div>
            </div>
            <?php endif; ?>

            <!-- Image fills remaining flex space -->
            <div class="flex-1 min-h-0 w-full flex items-end justify-center relative">
                <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[90%] h-[75%] md:w-[700px] md:h-[700px] bg-gradient-to-t from-primary/40 via-purple-500/20 to-transparent rounded-full blur-[80px] animate-pulse -z-20"></div>

                <img src="<?php echo $profile['hero_image_url']; ?>"
                     class="h-full w-auto object-contain object-bottom drop-shadow-[0_10px_40px_rgba(0,0,0,0.4)] dark:drop-shadow-[0_10px_40px_rgba(255,255,255,0.15)] transition-transform duration-700 hover:scale-[1.02]"
                     alt="<?php echo t('hero.image_alt', [], $lang); ?>"
                     loading="eager"
                     fetchpriority="high"
                     decoding="sync">

                <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[160vw] h-32 lg:h-72 bg-gradient-to-t from-white via-white/80 to-transparent dark:from-darkbg dark:via-darkbg/90 z-20 pointer-events-none"></div>
                <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[80%] h-[50px] bg-black/20 dark:bg-black/40 blur-3xl rounded-[100%] -z-10"></div>
            </div>
        </div>
        <!-- Full-width seam cover: covers left-column empty space below CTA on desktop -->

    </section>

    <!-- CLIENTS (mobile only) -->
    <section class="pt-8 pb-10 bg-white dark:bg-darkbg overflow-hidden relative lg:hidden">
        <div class="max-w-5xl mx-auto px-6 mb-10">
            <div class="flex items-center gap-4">
                <div class="flex-1 h-px bg-slate-100 dark:bg-white/5"></div>
                <p class="text-[10px] uppercase tracking-[0.3em] text-slate-400 dark:text-slate-500 font-semibold whitespace-nowrap"><?php echo t('clients.trusted_by', [], $lang); ?></p>
                <div class="flex-1 h-px bg-slate-100 dark:bg-white/5"></div>
            </div>
        </div>
        <div class="relative">
            <div class="absolute inset-y-0 left-0 w-20 bg-gradient-to-r from-white dark:from-[#0F172A] to-transparent z-10 pointer-events-none"></div>
            <div class="absolute inset-y-0 right-0 w-20 bg-gradient-to-l from-white dark:from-[#0F172A] to-transparent z-10 pointer-events-none"></div>
            <div id="clients-track-mobile">
                <?php
                $logosMobile = [];
                $lQm = mysqli_query($conn, "SELECT * FROM client_logos WHERE profile_id=$activeProfileId ORDER BY display_order ASC");
                while($lm = mysqli_fetch_assoc($lQm)) $logosMobile[] = $lm;
                if(count($logosMobile) > 0) {
                    for($repeat = 0; $repeat < 2; $repeat++): foreach($logosMobile as $logo): ?>
                        <div class="client-logo-item">
                            <img src="<?php echo getLogoUrl($logo['logo_url']); ?>" alt="<?php echo htmlspecialchars($logo['client_name']); ?>" loading="lazy" decoding="async">
                        </div>
                    <?php endforeach; endfor;
                } else { ?>
                    <div class="w-full text-center text-slate-400 text-sm py-10"><?php echo t('clients.empty', [], $lang); ?></div>
                <?php } ?>
            </div>
        </div>
    </section>

    <?php if(!empty($profile["show_metrics"])): ?>
    <!-- METRICS -->
    <section class="py-16 px-4 md:px-6 bg-slate-50 dark:bg-slate-950">
        <div class="max-w-5xl mx-auto">
            <div class="grid grid-cols-2 md:grid-cols-4 border border-slate-200 dark:border-white/10 rounded-2xl divide-y md:divide-y-0 md:divide-x divide-slate-200 dark:divide-white/10 bg-white/90 dark:bg-slate-900/80 backdrop-blur-sm shadow-sm">
                <?php $mQ = mysqli_query($conn, "SELECT * FROM impact_metrics WHERE profile_id=$activeProfileId ORDER BY display_order ASC"); while($m=mysqli_fetch_assoc($mQ)): ?>
                <div class="p-4 md:p-6 flex flex-col items-center justify-center text-center group hover:bg-white dark:hover:bg-white/5 transition duration-300" data-aos="fade-up" data-aos-duration="600">
                    <div class="mb-2 text-slate-400 group-hover:text-primary group-hover:-translate-y-1 transition duration-300"><i class="<?php echo $m['icon']; ?> text-lg"></i></div>
                    <div class="w-full text-lg md:text-xl font-bold text-slate-800 dark:text-white mb-1 break-words leading-tight group-hover:text-slate-900 dark:group-hover:text-white transition"><?php echo htmlspecialchars(localizedField($m, 'metric_value', $lang)); ?></div>
                    <div class="w-full text-[10px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest break-words leading-snug"><?php echo htmlspecialchars(localizedField($m, 'metric_name', $lang)); ?></div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </section>

    <?php endif; ?>
    <!-- PROJECTS -->
    <section id="projects" class="py-20 px-6 bg-gradient-to-b from-white via-slate-50 to-slate-100 dark:from-slate-950 dark:via-slate-900 dark:to-darkbg">
        <div class="max-w-7xl mx-auto">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold mb-4 text-slate-900 dark:text-white"><?php echo t('projects.title', [], $lang); ?> <span class="text-primary"><?php echo t('projects.title_highlight', [], $lang); ?></span></h2>
                <p class="text-slate-600 mb-8 max-w-2xl mx-auto text-sm md:text-base"><?php echo nl2br(htmlspecialchars($projectsDesc)); ?></p>
                <div class="flex flex-wrap justify-center gap-2" id="project-filters">
                    <button class="filter-btn active px-5 py-2 rounded-full text-xs md:text-sm font-bold border border-slate-200 dark:border-white/10 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition" data-filter="all"><?php echo t('projects.filter_all', [], $lang); ?></button>
                    <?php foreach($categories as $cat): ?>
                    <button class="filter-btn px-5 py-2 rounded-full text-xs md:text-sm font-bold border border-slate-200 dark:border-white/10 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition" data-filter="<?php echo $cat; ?>"><?php echo $cat; ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8" id="projects-grid">
                <?php foreach($projects as $p): $projectCategory = localizedField($p, 'category', $lang); ?>
                <div class="project-card group relative bg-lightcard dark:bg-darkcard rounded-3xl overflow-hidden border border-black/5 dark:border-white/5 shadow-md hover:shadow-xl hover:shadow-primary/10 transition duration-500 flex flex-col h-full" data-category="<?php echo htmlspecialchars($projectCategory); ?>" data-aos="fade-up">
                    <div class="h-56 overflow-hidden relative flex-shrink-0">
                        <img src="<?php echo $p['image_url']; ?>" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110" width="600" height="224" loading="lazy" decoding="async">
                        <div class="absolute top-4 right-4"><span class="text-[10px] font-bold bg-white/90 dark:bg-black/70 text-slate-900 dark:text-white px-3 py-1 rounded-full backdrop-blur-md border border-white/10 shadow-sm"><?php echo htmlspecialchars($projectCategory); ?></span></div>
                    </div>
                    <div class="p-6 flex flex-col flex-grow">
                        <div class="flex items-center gap-2 mb-3"><span class="text-[10px] font-bold font-mono text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-500/10 px-2 py-0.5 rounded border border-green-200 dark:border-green-500/20"><i class="fas fa-chart-line mr-1"></i> <?php echo htmlspecialchars(localizedField($p, 'result_text', $lang)); ?></span></div>
                        <h3 class="text-xl font-bold mb-2 text-slate-900 dark:text-white group-hover:text-primary transition line-clamp-1"><?php echo htmlspecialchars(localizedField($p, 'title', $lang)); ?></h3>
                        <p class="text-slate-600 dark:text-slate-400 text-sm line-clamp-2 mb-6 leading-relaxed flex-grow"><?php echo htmlspecialchars(localizedField($p, 'description', $lang)); ?></p>
                        
                        <!-- Menggunakan Helper Link SEO Friendly -->
                        <div class="mt-auto pt-4 border-t border-slate-100 dark:border-white/5">
                            <a href="<?php echo getProjectLink($p, $lang); ?>" class="inline-flex items-center gap-2 text-sm font-bold text-primary hover:gap-3 transition-all"><?php echo t('projects.read_detail', [], $lang); ?> <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-12 text-center hidden" id="load-more-container">
                <button id="load-more-btn" class="px-8 py-3 rounded-full border border-slate-300 dark:border-white/20 text-slate-600 dark:text-slate-300 font-bold text-sm hover:bg-slate-900 hover:text-white dark:hover:bg-white dark:hover:text-black transition-all duration-300 shadow-sm hover:shadow-md hover:-translate-y-0.5"><?php echo t('projects.load_more', [], $lang); ?> <i class="fas fa-chevron-down ml-2"></i></button>
            </div>
        </div>
    </section>

    <!-- CLIENTS (desktop only) -->
    <section id="clients-desktop-section" class="pt-10 pb-10 md:pb-14 bg-white dark:bg-darkbg overflow-hidden relative">
        <div class="max-w-5xl mx-auto px-6 mb-10 md:mb-12">
            <div class="flex items-center gap-4 md:gap-6">
                <div class="flex-1 h-px bg-slate-100 dark:bg-white/5"></div>
                <p class="text-[10px] uppercase tracking-[0.3em] text-slate-400 dark:text-slate-500 font-semibold whitespace-nowrap"><?php echo t('clients.trusted_by', [], $lang); ?></p>
                <div class="flex-1 h-px bg-slate-100 dark:bg-white/5"></div>
            </div>
        </div>
        <div class="relative">
            <div class="absolute inset-y-0 left-0 w-20 sm:w-24 md:w-32 bg-gradient-to-r from-white dark:from-[#0F172A] to-transparent z-10 pointer-events-none"></div>
            <div class="absolute inset-y-0 right-0 w-20 sm:w-24 md:w-32 bg-gradient-to-l from-white dark:from-[#0F172A] to-transparent z-10 pointer-events-none"></div>
            <div id="clients-track">
                <?php
                $logos = [];
                $lQ = mysqli_query($conn, "SELECT * FROM client_logos WHERE profile_id=$activeProfileId ORDER BY display_order ASC");
                while($l = mysqli_fetch_assoc($lQ)) $logos[] = $l;
                if(count($logos) > 0) {
                    for($repeat = 0; $repeat < 2; $repeat++): foreach($logos as $logo): ?>
                        <div class="client-logo-item">
                            <img src="<?php echo getLogoUrl($logo['logo_url']); ?>" alt="<?php echo htmlspecialchars($logo['client_name']); ?>" loading="lazy" decoding="async">
                        </div>
                    <?php endforeach; endfor;
                } else { ?>
                    <div class="w-full text-center text-slate-400 text-sm py-10"><?php echo t('clients.empty', [], $lang); ?></div>
                <?php } ?>
            </div>
        </div>
    </section>

    <!-- SKILLS -->
    <section id="skills" class="py-20 px-6 bg-slate-50 dark:bg-slate-950">
        <div class="max-w-6xl mx-auto">
            <h2 class="text-3xl md:text-4xl font-bold mb-12 text-center text-slate-900 dark:text-white"><?php echo t('skills.title', [], $lang); ?> <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary to-secondary"><?php echo t('skills.title_highlight', [], $lang); ?></span></h2>
            <?php 
            $skillCategories = [];
            $skillCatQ = mysqli_query($conn, "SELECT DISTINCT category FROM skills WHERE profile_id=$activeProfileId ORDER BY category ASC");
            while($skillCatRow = mysqli_fetch_assoc($skillCatQ)) {
                if (!empty($skillCatRow['category'])) {
                    $skillCategories[] = $skillCatRow['category'];
                }
            }
            foreach($skillCategories as $cat):
                $catEsc = mysqli_real_escape_string($conn, $cat);
                $sQ = mysqli_query($conn, "SELECT * FROM skills WHERE profile_id=$activeProfileId AND category='$catEsc'");
                if(mysqli_num_rows($sQ) > 0):
            ?>
                <div class="mb-10" data-aos="fade-up">
                    <h3 class="text-lg font-bold text-slate-500 dark:text-slate-400 mb-4 border-l-4 border-primary pl-3"><?php echo translateSkillCategory($cat, $lang); ?></h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                        <?php while($s=mysqli_fetch_assoc($sQ)): ?>
                        <div class="p-4 bg-white dark:bg-white/5 rounded-2xl border border-black/5 dark:border-white/5 flex flex-col items-center justify-center gap-3 hover:bg-white dark:hover:bg-white/10 hover:shadow-lg hover:-translate-y-1 transition duration-300 group">
                            <?php if(!empty($s['icon_url'])): ?>
                                <img src="<?php echo htmlspecialchars($s['icon_url']); ?>" class="w-10 h-10 md:w-12 md:h-12 object-contain opacity-75 group-hover:opacity-100 group-hover:scale-110 transition-all duration-300" width="48" height="48" loading="lazy" decoding="async" alt="<?php echo htmlspecialchars(localizedField($s, 'skill_name', $lang)); ?>">
                            <?php else: ?>
                                <div class="w-10 h-10 md:w-12 md:h-12 rounded-full bg-primary/10 dark:bg-primary/20 flex items-center justify-center flex-shrink-0">
                                    <span class="text-primary font-bold text-base md:text-lg"><?php echo strtoupper(substr(localizedField($s, 'skill_name', $lang), 0, 1)); ?></span>
                                </div>
                            <?php endif; ?>
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-300 text-center leading-snug"><?php echo htmlspecialchars(localizedField($s, 'skill_name', $lang)); ?></span>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            <?php endif; endforeach; ?>
        </div>
    </section>

    <!-- EXPERIENCE & EDUCATION -->
    <section id="about" class="py-20 px-6 bg-gradient-to-br from-white via-slate-50 to-slate-100 dark:from-slate-950 dark:via-slate-900 dark:to-darkbg">
        <div class="max-w-5xl mx-auto grid md:grid-cols-2 gap-12">
            <div>
                <h3 class="text-2xl font-bold text-slate-900 dark:text-white mb-8 flex items-center gap-3"><i class="fas fa-briefcase text-primary"></i> <?php echo t('about.experience', [], $lang); ?></h3>
                <div class="space-y-8 relative pl-6 border-l-2 border-slate-200 dark:border-slate-700">
                    <?php $xQ = mysqli_query($conn, "SELECT * FROM experience WHERE profile_id=$activeProfileId ORDER BY id DESC"); $delay = 0; while($x=mysqli_fetch_assoc($xQ)): ?>
                    <div class="relative pl-6" data-aos="fade-up" data-aos-offset="50" data-aos-delay="<?php echo $delay; ?>">
                        <div class="absolute -left-[31px] top-1 w-4 h-4 rounded-full border-4 border-slate-50 dark:border-darkbg bg-primary"></div>
                        <span class="text-xs font-bold text-primary bg-primary/10 px-2 py-1 rounded mb-2 inline-block"><?php echo htmlspecialchars(localizedField($x, 'year_range', $lang)); ?></span>
                        <h4 class="text-lg font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars(localizedField($x, 'role', $lang)); ?></h4>
                        <div class="text-sm font-semibold text-slate-500 dark:text-slate-400 mb-2"><?php echo $x['company']; ?></div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed"><?php echo nl2br(htmlspecialchars(localizedField($x, 'description', $lang))); ?></p>
                    </div>
                    <?php $delay += 100; endwhile; ?>
                </div>
            </div>
            <div>
                <h3 class="text-2xl font-bold text-slate-900 dark:text-white mb-8 flex items-center gap-3"><i class="fas fa-graduation-cap text-secondary"></i> <?php echo t('about.education', [], $lang); ?></h3>
                <div class="space-y-6">
                    <?php $eQ = mysqli_query($conn, "SELECT * FROM education WHERE profile_id=$activeProfileId ORDER BY id DESC"); $delay = 0; while($e=mysqli_fetch_assoc($eQ)): ?>
                    <div class="bg-white dark:bg-white/5 p-6 rounded-2xl border border-black/5 dark:border-white/5 hover:border-primary/30 transition" data-aos="fade-up" data-aos-offset="50" data-aos-delay="<?php echo $delay; ?>">
                        <div class="flex justify-between items-start mb-2">
                            <h4 class="text-lg font-bold text-slate-900 dark:text-white"><?php echo $e['school_name']; ?></h4>
                            <span class="text-xs font-bold bg-slate-100 dark:bg-white/10 px-2 py-1 rounded"><?php echo htmlspecialchars(localizedField($e, 'year_range', $lang)); ?></span>
                        </div>
                        <p class="text-sm text-primary font-medium"><?php echo htmlspecialchars(localizedField($e, 'degree', $lang)); ?></p>
                    </div>
                    <?php $delay += 100; endwhile; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- BLOG -->
    <section id="blog" class="py-20 px-6 bg-slate-50 dark:bg-slate-950">
        <div class="max-w-6xl mx-auto">
            <h2 class="text-3xl md:text-4xl font-bold mb-12 text-center text-slate-900 dark:text-white"><?php echo t('blog.title', [], $lang); ?> <span class="text-primary"><?php echo t('blog.title_highlight', [], $lang); ?></span></h2>
            <div class="grid md:grid-cols-3 gap-6">
                <?php $bQ = mysqli_query($conn, "SELECT * FROM articles WHERE profile_id=$activeProfileId ORDER BY created_at DESC LIMIT 3");
                if(mysqli_num_rows($bQ) > 0): while($b=mysqli_fetch_assoc($bQ)): ?>
                    <!-- Menggunakan Helper Link SEO Friendly -->
                    <a href="<?php echo getBlogLink($b, $lang); ?>" class="group bg-white dark:bg-darkcard rounded-2xl overflow-hidden shadow-sm hover:shadow-xl hover:-translate-y-1 transition duration-300 border border-black/5 dark:border-white/5 block">
                        <div class="h-48 overflow-hidden">
                            <img src="<?php echo $b['image_url']; ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" width="400" height="192" loading="lazy" decoding="async">
                        </div>
                        <div class="p-6">
                            <div class="text-xs text-slate-400 mb-2"><?php echo formatLocalizedDate($b['created_at'], $lang); ?></div>
                            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2 group-hover:text-primary transition line-clamp-2"><?php echo htmlspecialchars(localizedField($b, 'title', $lang)); ?></h3>
                            <span class="text-sm text-primary font-bold"><?php echo t('blog.read_article', [], $lang); ?> <i class="fas fa-arrow-right ml-1"></i></span>
                        </div>
                    </a>
                <?php endwhile; else: ?>
                    <div class="col-span-3 text-center text-slate-500"><?php echo t('blog.empty', [], $lang); ?></div>
                <?php endif; ?>
            </div>
            
            <div class="mt-12 text-center" data-aos="fade-up">
                <a href="https://pastidigital.com/" target="_blank" class="inline-flex items-center gap-2 px-8 py-3 rounded-full border border-slate-300 dark:border-white/20 text-slate-600 dark:text-slate-300 font-bold text-sm hover:bg-slate-900 hover:text-white dark:hover:bg-white dark:hover:text-black transition-all duration-300 shadow-sm hover:shadow-md hover:-translate-y-0.5">
                    <?php echo t('blog.visit_portal', [], $lang); ?> <i class="fas fa-external-link-alt ml-2"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- CONTACT -->
    <section id="contact" class="py-24 px-6 bg-gradient-to-br from-slate-50 via-white to-slate-100 dark:from-slate-950 dark:via-slate-900 dark:to-darkbg">
        <div class="max-w-5xl mx-auto relative">
            <div class="glass-pill rounded-[2.5rem] p-8 md:p-16 relative z-10 grid md:grid-cols-2 gap-12 items-center">
                <div>
                    <h2 class="text-3xl md:text-4xl font-extrabold mb-6 text-slate-900 dark:text-white"><?php echo t('contact.title', [], $lang); ?></h2>
                    <p class="text-slate-600 dark:text-slate-300 mb-10 text-base leading-relaxed"><?php echo nl2br(htmlspecialchars($contactDesc)); ?></p>
                    <div class="space-y-4">
                        <div class="flex items-center gap-4 text-slate-600 dark:text-slate-400 hover:text-primary transition"><div class="w-10 h-10 rounded-full bg-slate-100 dark:bg-white/10 flex items-center justify-center"><i class="far fa-envelope"></i></div><span class="font-medium text-sm"><?php echo $profile['email']; ?></span></div>
                        <div class="flex items-center gap-4 text-slate-600 dark:text-slate-400 hover:text-green-500 transition"><div class="w-10 h-10 rounded-full bg-slate-100 dark:bg-white/10 flex items-center justify-center"><i class="fab fa-whatsapp"></i></div><span class="font-medium text-sm">+<?php echo $profile['whatsapp']; ?></span></div>
                    </div>
                </div>
                <form action="process.php" method="POST" class="space-y-4 bg-white/95 dark:bg-slate-900/90 p-6 md:p-8 rounded-3xl border border-white/10 shadow-inner">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="profile_id" value="<?= $activeProfileId ?>">
                    <input type="hidden" name="lang" value="<?php echo htmlspecialchars($lang); ?>">
                    <input name="name" class="w-full bg-white dark:bg-darkbg border border-slate-200 dark:border-white/10 rounded-xl p-4 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary transition" placeholder="<?php echo t('contact.placeholder_name', [], $lang); ?>" required>
                    <input name="email" class="w-full bg-white dark:bg-darkbg border border-slate-200 dark:border-white/10 rounded-xl p-4 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary transition" placeholder="<?php echo t('contact.placeholder_email', [], $lang); ?>" required>
                    <textarea name="message" class="w-full bg-white dark:bg-darkbg border border-slate-200 dark:border-white/10 rounded-xl p-4 text-sm h-32 outline-none focus:border-primary focus:ring-1 focus:ring-primary resize-none transition" placeholder="<?php echo t('contact.placeholder_message', [], $lang); ?>" required></textarea>
                    <button type="submit" data-track-event="contact_submit" class="w-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold py-4 rounded-xl hover:shadow-lg hover:scale-[1.02] transition duration-300"><?php echo t('contact.submit', [], $lang); ?></button>
                </form>
            </div>
        </div>
    </section>

    </main>

    <footer class="py-12 border-t border-slate-200 dark:border-white/5 bg-slate-50 dark:bg-black/20 pb-20 lg:pb-12">
        <div class="max-w-6xl mx-auto px-6 text-center">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-8"><?php echo t('footer.connected', [], $lang); ?></h3>
            <div class="flex flex-wrap justify-center gap-4 mb-8">
                <?php 
                $socials = [
                    ['url' => $profile['link_linkedin'], 'icon' => 'fab fa-linkedin', 'color' => 'hover:text-blue-600', 'label' => 'LinkedIn'],
                    ['url' => $profile['link_instagram'], 'icon' => 'fab fa-instagram', 'color' => 'hover:text-pink-500', 'label' => 'Instagram'],
                    ['url' => $profile['link_twitter'], 'icon' => 'fab fa-twitter', 'color' => 'hover:text-sky-500', 'label' => 'Twitter / X'],
                    ['url' => $profile['link_tiktok'], 'icon' => 'fab fa-tiktok', 'color' => 'hover:text-pink-400', 'label' => 'TikTok'],
                    ['url' => $profile['link_threads'] ?? '', 'icon' => 'fa-brands fa-threads', 'color' => 'hover:text-slate-500 dark:hover:text-white', 'label' => 'Threads'],
                    ['url' => $profile['link_facebook'], 'icon' => 'fab fa-facebook', 'color' => 'hover:text-blue-700', 'label' => 'Facebook'],
                    ['url' => $profile['link_youtube'], 'icon' => 'fab fa-youtube', 'color' => 'hover:text-red-600', 'label' => 'YouTube'],
                    ['url' => $profile['link_github'], 'icon' => 'fab fa-github', 'color' => 'hover:text-slate-900 dark:hover:text-white', 'label' => 'GitHub'],
                    ['url' => $profile['link_blog_pribadi'], 'icon' => 'fas fa-globe', 'color' => 'hover:text-green-500', 'label' => 'Blog'],
                ];
                foreach($socials as $soc): if(!empty($soc['url'])): ?>
                    <a href="<?php echo $soc['url']; ?>" target="_blank" aria-label="<?php echo htmlspecialchars($soc['label'] ?? $soc['icon']); ?>" class="w-10 h-10 rounded-full bg-white dark:bg-white/5 border border-black/5 dark:border-white/10 flex items-center justify-center text-slate-500 dark:text-slate-400 <?php echo $soc['color']; ?> text-lg transition-transform hover:scale-110"><i class="<?php echo $soc['icon']; ?>" aria-hidden="true"></i></a>
                <?php endif; endforeach; ?>
            </div>
            <div class="text-slate-600 text-sm font-medium">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteTitle); ?>. <?php echo t('footer.rights', [], $lang); ?></div>
        </div>
    </footer>

    <!-- Floating Actions -->
    <div class="fixed bottom-6 right-4 z-40 flex flex-col items-end gap-3 group">
        <a href="mailto:<?php echo htmlspecialchars($profile['email']); ?>" aria-label="<?php echo $lang === 'en' ? 'Send Email' : 'Kirim Email'; ?>" class="relative w-12 h-12 rounded-full bg-white dark:bg-slate-800 text-red-500 flex items-center justify-center text-xl shadow-lg border border-slate-100 dark:border-slate-700 hover:scale-110 transition-transform">
            <i class="far fa-envelope" aria-hidden="true"></i>
        </a>
    </div>

    <!-- 5. OPTIMASI: Defer script JS utama -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js" defer></script>
    <script defer>
        document.addEventListener("DOMContentLoaded", function() { 
            // Inisialisasi AOS setelah DOM siap sepenuhnya
            if(typeof AOS !== 'undefined') {
                AOS.init({ duration: 800, once: true, offset: 50, disable: function() { return window.innerWidth < 768; } }); 
            } else {
                // Fallback jika script defer belum load (jarang terjadi)
                window.onload = function() { AOS.init({ duration: 800, once: true, offset: 50, disable: function() { return window.innerWidth < 768; } }); }
            }
        });
        
        // Theme Toggle Logic
        const toggleTheme = () => {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark'); localStorage.theme = 'light';
                document.getElementById('fireflies-container').style.display = 'none';
            } else {
                document.documentElement.classList.add('dark'); localStorage.theme = 'dark';
                document.getElementById('fireflies-container').style.display = 'block';
            }
        };

        const desktopToggle = document.getElementById('theme-toggle-desktop');
        if(desktopToggle) desktopToggle.addEventListener('click', toggleTheme);

        const mobileToggle = document.getElementById('theme-toggle-mobile');
        if(mobileToggle) mobileToggle.addEventListener('click', toggleTheme);
        
        // --- MOBILE SIDEBAR LOGIC (NEW) ---
        const menuBtn = document.getElementById('mobile-menu-btn');
        const sidebar = document.getElementById('mobile-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        
        function toggleSidebar() {
            if(sidebar.classList.contains('translate-x-full')) {
                // Open
                sidebar.classList.remove('translate-x-full');
                overlay.classList.remove('hidden');
                setTimeout(() => overlay.classList.remove('opacity-0'), 10); // Fade in
                document.body.style.overflow = 'hidden'; // Prevent scrolling
            } else {
                // Close
                sidebar.classList.add('translate-x-full');
                overlay.classList.add('opacity-0');
                setTimeout(() => overlay.classList.add('hidden'), 300); // Wait for fade out
                document.body.style.overflow = ''; // Restore scrolling
            }
        }
        
        if(menuBtn) menuBtn.addEventListener('click', toggleSidebar);

        // Fireflies Logic
        if(localStorage.theme !== 'light' && window.innerWidth >= 1024) {
            const fireflies = document.getElementById('fireflies-container');
            fireflies.style.display = 'block';
            const createFireflies = () => {
                const frag = document.createDocumentFragment();
                for(let i = 0; i < 20; i++) {
                    let d = document.createElement('div');
                    d.className = 'firefly';
                    let size = Math.random() * 4 + 2;
                    d.style.cssText = `width:${size}px;height:${size}px;left:${Math.random()*100}vw;top:${Math.random()*100}vh;--mx:${(Math.random()*120-60)}px;--my:${(Math.random()*120-60)}px;animation-duration:${(Math.random()*6+4)}s;animation-delay:${(Math.random()*5)}s`;
                    frag.appendChild(d);
                }
                fireflies.appendChild(frag);
            };
            if ('requestIdleCallback' in window) {
                requestIdleCallback(createFireflies, { timeout: 2000 });
            } else {
                setTimeout(createFireflies, 1000);
            }
        }

        const script = <?php echo json_encode($jsScript); ?>;
        const chatContainer = document.getElementById('hero-chat');
        const chatText = document.getElementById('chat-text');
        const chatOptions = document.getElementById('chat-options');
        let typingTimer = null; let stepTimeout = null; 

        if(script.length > 0) { setTimeout(() => { chatContainer.classList.remove('hidden'); showQuestion(0); }, 1200); }

        function typeWriter(text, callback) {
            chatText.innerHTML = ''; chatText.classList.add('typing-cursor');
            let i = 0; const speed = 30; 
            if(typingTimer) clearTimeout(typingTimer);
            function type() {
                if (i < text.length) { chatText.innerHTML += text.charAt(i); i++; typingTimer = setTimeout(type, speed); } 
                else { chatText.classList.remove('typing-cursor'); if (callback) callback(); }
            }
            type();
        }

        function showQuestion(index) {
            if (!script[index]) return;
            const data = script[index];
            const textToShow = data.question ? data.question : data.answer; 
            chatOptions.innerHTML = ''; chatOptions.style.opacity = '0';
            if (stepTimeout) clearTimeout(stepTimeout);
            typeWriter(textToShow, () => {
                if (data.nextQuestion) {
                    stepTimeout = setTimeout(() => { typeWriter(data.nextQuestion, () => { showOptions(data.options); }); }, 500); 
                } else { showOptions(data.options); }
            });
        }

        function showOptions(options) {
            chatOptions.innerHTML = '';
            options.forEach(opt => {
                const btn = document.createElement('button');
                const isPrimary = opt.variant === 'primary' || opt.action === 'cta';
                btn.className = `px-4 py-1.5 rounded-full text-xs font-bold transition transform hover:scale-105 ${isPrimary ? 'bg-primary text-white hover:bg-secondary' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-300'}`;
                btn.innerText = opt.text;
                btn.onclick = () => {
                    if (opt.action === 'close') {
                        chatContainer.classList.add('opacity-0', 'scale-90');
                        setTimeout(() => chatContainer.remove(), 500);
                    } 
                    else if (opt.action === 'cta') { chatText.innerHTML = <?php echo json_encode(t('chat.final_cta', [], $lang)); ?>; chatOptions.innerHTML = '<a href="#projects" class="px-5 py-2 bg-slate-900 text-white rounded-full text-xs font-bold shadow-lg hover:bg-black transition"><?php echo addslashes(t('chat.final_cta_button', [], $lang)); ?></a>'; } 
                    else if (opt.next !== undefined) { showQuestion(opt.next); }
                };
                chatOptions.appendChild(btn);
            });
            chatOptions.style.opacity = '1';
        }

        const filterBtns = document.querySelectorAll('.filter-btn');
        const projectCards = document.querySelectorAll('.project-card');
        const loadMoreBtn = document.getElementById('load-more-btn');
        const loadMoreContainer = document.getElementById('load-more-container');
        let itemsToShow = 6; let activeFilter = 'all';

        function showProjects() {
            let visibleCount = 0; let totalMatch = 0;
            projectCards.forEach(card => {
                const category = card.getAttribute('data-category');
                const isMatch = (activeFilter === 'all' || category === activeFilter);
                if (isMatch) {
                    totalMatch++;
                    if (visibleCount < itemsToShow) { card.style.display = 'flex'; if(!card.classList.contains('aos-animate')) setTimeout(() => card.classList.add('aos-animate'), 50); visibleCount++; } 
                    else { card.style.display = 'none'; }
                } else { card.style.display = 'none'; }
            });
            if (visibleCount < totalMatch) loadMoreContainer.classList.remove('hidden'); else loadMoreContainer.classList.add('hidden');
            if(typeof AOS !== 'undefined') setTimeout(() => AOS.refresh(), 100);
        }
        showProjects();
        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                filterBtns.forEach(b => { b.classList.remove('bg-slate-900', 'text-white', 'dark:bg-white', 'dark:text-black'); b.classList.add('text-slate-600', 'dark:text-slate-300'); });
                btn.classList.add('bg-slate-900', 'text-white', 'dark:bg-white', 'dark:text-black'); btn.classList.remove('text-slate-600', 'dark:text-slate-300');
                activeFilter = btn.getAttribute('data-filter'); itemsToShow = 6; showProjects();
            });
        });
        loadMoreBtn.addEventListener('click', () => { itemsToShow += 6; showProjects(); });

        const _ACTIVE_PID = <?= $activeProfileId ?>;
        function reportAnalyticsEvent(type, key) {
            const url = 'process.php';
            const data = new FormData();
            data.append('action', 'track_event');
            data.append('event_type', type);
            data.append('event_key', key);
            data.append('profile_id', _ACTIVE_PID);
            if (navigator.sendBeacon) {
                const payload = new URLSearchParams();
                for (const pair of data) payload.append(pair[0], pair[1]);
                navigator.sendBeacon(url, payload);
            } else {
                fetch(url, { method: 'POST', body: data, keepalive: true }).catch(() => {});
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            reportAnalyticsEvent('page_view', 'home');
            document.querySelectorAll('[data-track-event]').forEach(el => {
                el.addEventListener('click', () => reportAnalyticsEvent('button_click', el.dataset.trackEvent || 'unknown'));
            });

            // Session duration tracking
            var _sessionStart = Date.now();
            var _sessionSent  = false;
            function sendDuration() {
                if (_sessionSent) return;
                _sessionSent = true;
                var secs = Math.round((Date.now() - _sessionStart) / 1000);
                if (secs < 2 || secs > 3600) return; // filter: < 2s bounce, > 1hr idle
                reportAnalyticsEvent('session_end', String(secs));
            }
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'hidden') sendDuration();
            });
            window.addEventListener('beforeunload', sendDuration);
        });
    </script>
    <script>
    function initClientsTrack(trackId) {
        var track = document.getElementById(trackId);
        if (!track) return;

        var dragging = false, startX = 0, currentX = 0, offsetX = 0;
        var loopW = 0;
        var autoSpeed = 0.55; // px per frame
        var autoPaused = false;
        var resumeTimer = null;
        var RESUME_DELAY = 2200; // ms pause after manual interaction

        function getLoopW() {
            if (!loopW) loopW = track.scrollWidth / 2;
            return loopW;
        }
        function wrapX(x) {
            var w = getLoopW(); if (!w) return x;
            x = x % w; if (x > 0) x -= w;
            return x;
        }
        function setX(x) { track.style.transform = 'translateX(' + x + 'px)'; }

        // Auto-scroll loop
        function tick() {
            if (!dragging && !autoPaused) {
                currentX = wrapX(currentX - autoSpeed);
                offsetX = currentX;
                setX(currentX);
            }
            requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);

        function pauseAuto() {
            autoPaused = true;
            clearTimeout(resumeTimer);
        }
        function scheduleResume() {
            clearTimeout(resumeTimer);
            resumeTimer = setTimeout(function() { autoPaused = false; }, RESUME_DELAY);
        }

        function startDrag(x) {
            dragging = true; startX = x;
            pauseAuto();
            var cur = track.style.transform;
            offsetX = cur ? parseFloat(cur.replace(/[^-\d.]/g,'')) || 0 : 0;
            currentX = offsetX;
        }
        function moveDrag(x) {
            if (!dragging) return;
            currentX = wrapX(offsetX + (x - startX));
            setX(currentX);
        }
        function endDrag() {
            if (!dragging) return;
            dragging = false; offsetX = currentX;
            scheduleResume();
        }

        track.addEventListener('mousedown', function(e){ startDrag(e.clientX); e.preventDefault(); }, {passive:false});
        document.addEventListener('mousemove', function(e){ moveDrag(e.clientX); });
        document.addEventListener('mouseup', endDrag);
        track.addEventListener('touchstart', function(e){ startDrag(e.touches[0].clientX); }, {passive:true});
        track.addEventListener('touchmove', function(e){ if(dragging){ e.preventDefault(); moveDrag(e.touches[0].clientX); } }, {passive:false});
        track.addEventListener('touchend', endDrag, {passive:true});
        track.addEventListener('touchcancel', endDrag, {passive:true});

        // Navigation buttons
        var outer = track.parentElement;
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative';
        outer.parentNode.insertBefore(wrap, outer);
        wrap.appendChild(outer);

        var isDark = document.documentElement.classList.contains('dark');
        var base = 'position:absolute;top:50%;transform:translateY(-50%);z-index:10;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:50%;cursor:pointer;border:none;outline:none;transition:opacity .2s,transform .15s;';
        var clr  = isDark ? 'background:rgba(30,41,59,0.92);box-shadow:0 2px 10px rgba(0,0,0,.35);color:#94a3b8;' : 'background:rgba(255,255,255,0.92);box-shadow:0 2px 10px rgba(0,0,0,.13);color:#475569;';

        function mkBtn(label, pts, delta) {
            var b = document.createElement('button');
            b.setAttribute('aria-label', label);
            b.style.cssText = base + clr + (delta > 0 ? 'left:20px' : 'right:20px');
            b.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="' + pts + '"/></svg>';
            b.style.opacity = '0.85';
            b.addEventListener('click', function(){
                pauseAuto();
                currentX = wrapX(currentX + delta);
                offsetX = currentX; setX(currentX);
                scheduleResume();
            });
            b.addEventListener('mouseenter', function(){ b.style.opacity='1'; b.style.transform='translateY(-50%) scale(1.12)'; });
            b.addEventListener('mouseleave', function(){ b.style.opacity='0.85'; b.style.transform='translateY(-50%)'; });
            return b;
        }
        wrap.appendChild(mkBtn('Scroll left',  '15,18 9,12 15,6',  200));
        wrap.appendChild(mkBtn('Scroll right', '9,18 15,12 9,6', -200));
    }
    initClientsTrack('clients-track');
    initClientsTrack('clients-track-mobile');
    </script>
</body>
</html>

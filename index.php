<?php 
require_once 'i18n.php';

// 1. OPTIMASI: Aktifkan Kompresi GZIP
if (!empty($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) ob_start("ob_gzhandler"); else ob_start();

$lang = currentLang();

include 'db.php'; 
$profile = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=1"));
$siteTitle = localizedField($profile, 'site_title', $lang);
$profileBio = localizedField($profile, 'bio', $lang);
$heroRoleText = localizedField($profile, 'hero_role', $lang);
$availabilityText = localizedField($profile, 'availability_text', $lang);
$projectsDesc = localizedField($profile, 'projects_desc', $lang);
$contactDesc = localizedField($profile, 'contact_desc', $lang);
$seoKeywords = localizedField($profile, 'seo_keywords', $lang);
$firstName = explode(' ', trim((string) $profile['name']))[0] ?? $profile['name'];

$projects = [];
$projectsQ = mysqli_query($conn, "SELECT * FROM projects ORDER BY display_order ASC");
while($projectRow = mysqli_fetch_assoc($projectsQ)) {
    $projects[] = $projectRow;
}

// --- LOGIKA FETCH CHAT DATA ---
$chatData = [];
$cq = mysqli_query($conn, "SELECT * FROM hero_chat ORDER BY display_order ASC");
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
    if (empty($url)) return 'https://via.placeholder.com/150x50?text=CLIENT';
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

    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Preload Hero Image (LCP Optimization) -->
    <?php if(!empty($profile['hero_image_url'])): ?>
    <link rel="preload" as="image" href="<?php echo $profile['hero_image_url']; ?>" fetchpriority="high">
    <?php endif; ?>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap">
    
    <link rel="icon" href="<?php echo !empty($profile['profile_photo']) ? $profile['profile_photo'] : 'https://via.placeholder.com/32'; ?>" type="image/x-icon">

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
                    animation: { 
                        'float': 'float 6s ease-in-out infinite',
                        'marquee': 'marquee 28s linear infinite', 
                        'pop-in': 'popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards',
                    },
                    keyframes: { 
                        float: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-20px)' } },
                        marquee: { '0%': { transform: 'translateX(0%)' }, '100%': { transform: 'translateX(-50%)' } },
                        popIn: { '0%': { opacity: '0', transform: 'scale(0.8) translateY(10px) translateX(-50%)' }, '100%': { opacity: '1', transform: 'scale(1) translateY(0) translateX(-50%)' } }
                    }
                } 
            } 
        } 
    </script>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"></noscript>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; transition: background-color 0.5s ease; }
        .glass-pill { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.6); }
        .dark .glass-pill { background: rgba(15, 23, 42, 0.85); border: 1px solid rgba(255, 255, 255, 0.1); }
        
        .firefly { position: absolute; border-radius: 50%; background: radial-gradient(circle, rgba(255,255,255,0.95) 0%, rgba(99,102,241,0.6) 70%, rgba(99,102,241,0.2) 100%); opacity: 0; box-shadow: 0 0 12px rgba(99,102,241,0.7), 0 0 6px rgba(255,255,255,0.7); animation: fly linear infinite; pointer-events: none; }
        @keyframes fly { 0% { opacity: 0; } 20% { opacity: 0.75; } 60% { opacity: 0.35; } 100% { transform: translate(var(--mx), var(--my)); opacity: 0; } }
        .filter-btn.active { background-color: #6366F1; color: white; border-color: #6366F1; }
        .marquee-mask { mask-image: linear-gradient(to right, transparent, black 10%, black 90%, transparent); }
        .text-balance { text-wrap: balance; }
        .typing-cursor::after { content: '|'; animation: blink 1s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }

        /* Sidebar Transition */
        .sidebar-transition { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    </style>
    <script>if (localStorage.theme === 'light') { document.documentElement.classList.remove('dark'); } else { document.documentElement.classList.add('dark'); }</script>
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
                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-9 h-9 rounded-full object-cover border-2 border-slate-200 dark:border-white/10 group-hover:rotate-12 transition shadow-sm" decoding="async">
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
                <div class="hidden md:flex items-center rounded-full border border-slate-200 dark:border-white/10 p-1">
                    <a href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>" class="px-3 py-1.5 rounded-full text-[11px] font-bold <?php echo $lang === 'id' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-500 dark:text-slate-300'; ?>">ID</a>
                    <a href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>" class="px-3 py-1.5 rounded-full text-[11px] font-bold <?php echo $lang === 'en' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-500 dark:text-slate-300'; ?>">EN</a>
                </div>
                <button id="theme-toggle-desktop" class="w-10 h-10 rounded-full bg-slate-100 dark:bg-white/5 flex items-center justify-center hover:scale-105 transition text-yellow-500 dark:text-slate-400 border border-slate-200 dark:border-white/10">
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
                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-8 h-8 rounded-full object-cover border border-slate-200 dark:border-white/10">
                <?php else: ?>
                    <span class="w-8 h-8 rounded-full bg-gradient-to-tr from-primary to-secondary flex items-center justify-center text-white text-xs font-bold"><?php echo substr($profile['name'], 0, 1); ?></span>
                <?php endif; ?>
                <span class="font-bold text-base tracking-tight text-slate-900 dark:text-white">Radhitya<span class="text-primary">sofwan</span></span>
            </a>

            <!-- Right Actions (Theme + Hamburger) -->
            <div class="flex items-center gap-2">
                 <button id="theme-toggle-mobile" class="w-9 h-9 rounded-full bg-slate-100 dark:bg-white/5 flex items-center justify-center text-yellow-500 dark:text-blue-300 border border-slate-200 dark:border-white/10">
                    <i class="fas fa-sun hidden dark:block text-xs"></i><i class="fas fa-moon block dark:hidden text-xs"></i>
                </button>
                <button id="mobile-menu-btn" class="w-9 h-9 flex items-center justify-center text-slate-700 dark:text-white bg-slate-100 dark:bg-white/5 rounded-full border border-slate-200 dark:border-white/10">
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
            <button onclick="toggleSidebar()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 dark:bg-white/5 text-slate-500 dark:text-slate-400 hover:text-red-500 transition">
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
                 <a href="https://wa.me/<?php echo $profile['whatsapp']; ?>" class="w-10 h-10 rounded-full bg-green-500 text-white flex items-center justify-center shadow hover:bg-green-600 transition"><i class="fab fa-whatsapp"></i></a>
                 <a href="mailto:<?php echo $profile['email']; ?>" class="w-10 h-10 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center hover:bg-primary hover:text-white transition"><i class="far fa-envelope"></i></a>
            </div>
        </div>
    </div>
    <!-- ========================================= -->
    <!-- END MOBILE NAVIGATION                     -->
    <!-- ========================================= -->


    <!-- HERO SECTION -->
    <section id="home" class="relative min-h-[95vh] flex flex-col lg:flex-row items-center justify-center w-full overflow-hidden pt-24 lg:pt-40 bg-gradient-to-br from-white via-slate-100 to-slate-200 dark:from-darkbg dark:via-slate-950 dark:to-darkbg">
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
        <!-- UPDATED: Added ID, dynamic mt-20 for mobile spacer, transition -->
        <div id="hero-image-wrapper" class="w-full lg:w-1/2 h-[60vh] lg:h-[calc(100vh-10rem)] relative flex items-end justify-center order-2 <?php echo ($totalChat > 0) ? 'mt-32' : '-mt-20'; ?> lg:mt-0 transition-all duration-700 ease-in-out" data-aos="fade-left">
            
            <?php if($totalChat > 0): ?>
            <div id="hero-chat" class="absolute top-0 sm:top-4 lg:top-500 lg:left-[60%] left-1/2 -translate-x-1/2 z-40 w-[92%] max-w-[280px] lg:max-w-[350px] min-w-[260px] bg-white/95 dark:bg-slate-900/95 p-3 rounded-[1.75rem] shadow-2xl border border-slate-200/70 dark:border-slate-700/80 hidden origin-top animate-pop-in">
                <div class="absolute -bottom-2 left-1/2 -translate-x-1/2 w-4 h-4 bg-white/95 dark:bg-slate-900/95 rotate-45 border-b border-r border-slate-200/70 dark:border-slate-700/80"></div>
                <div class="flex items-center gap-2 mb-2 pb-2 border-b border-slate-100/80 dark:border-slate-700">
                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-5 h-5 rounded-full object-cover">
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"><?php echo t('hero.typing', ['name' => $firstName], $lang); ?></span>
                </div>
                <div id="chat-text" class="text-xs md:text-sm font-medium text-slate-700 dark:text-slate-200 leading-relaxed mb-2 max-h-[10rem] overflow-y-auto whitespace-pre-wrap break-words"></div>
                <div id="chat-options" class="flex gap-2 justify-end opacity-0 transition-opacity duration-300"></div>
            </div>
            <?php endif; ?>

            <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[90%] h-[75%] md:w-[700px] md:h-[700px] bg-gradient-to-t from-primary/40 via-purple-500/20 to-transparent rounded-full blur-[80px] animate-pulse -z-20"></div>
            
            <img src="<?php echo $profile['hero_image_url']; ?>" 
                 class="h-[85%] lg:h-[90%] w-auto object-contain object-bottom drop-shadow-[0_10px_40px_rgba(0,0,0,0.4)] dark:drop-shadow-[0_10px_40px_rgba(255,255,255,0.15)] transition-transform duration-700 hover:scale-[1.02]" 
                 alt="<?php echo t('hero.image_alt', [], $lang); ?>"
                 loading="eager" 
                 fetchpriority="high"
                 decoding="sync">
            
            <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[160vw] h-56 lg:h-72 bg-gradient-to-t from-white/95 via-white/65 to-transparent dark:from-darkbg/95 dark:via-darkbg/90 z-20 pointer-events-none"></div>
            <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[80%] h-[50px] bg-black/20 dark:bg-black/40 blur-3xl rounded-[100%] -z-10 translate-y-4"></div>
        </div>

    </section>

    <!-- CLIENTS -->
    <section class="py-16 bg-gradient-to-br from-slate-50 via-white to-slate-100 dark:from-slate-950 dark:via-slate-900 dark:to-darkbg border-t border-b border-slate-200 dark:border-slate-800 overflow-hidden relative z-30">
        <div class="max-w-6xl mx-auto px-6">
            <div class="text-center mb-10">
                <p class="text-xs uppercase tracking-[0.32em] text-slate-500 dark:text-slate-400 font-bold mb-3"><?php echo t('clients.trusted_by', [], $lang); ?></p>
                <h2 class="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white"><?php echo t('clients.title', [], $lang); ?></h2>
            </div>

            <div class="overflow-hidden rounded-[2rem] border border-slate-200/70 dark:border-slate-700/70 bg-white/90 dark:bg-slate-900/75 shadow-2xl shadow-slate-950/10 dark:shadow-black/20">
                <div class="flex gap-4 md:gap-6 py-8 px-4 md:px-6 animate-marquee hover:[animation-play-state:paused]">
                    <?php 
                    $logos = []; 
                    $lQ = mysqli_query($conn, "SELECT * FROM client_logos ORDER BY display_order ASC"); 
                    while($l = mysqli_fetch_assoc($lQ)) $logos[] = $l;
                    if(count($logos) > 0) {
                        for($repeat = 0; $repeat < 2; $repeat++): foreach($logos as $logo): ?>
                            <div class="flex-shrink-0 flex items-center justify-center w-40 md:w-44 h-24 md:h-28 rounded-3xl bg-slate-100 dark:bg-slate-800/40 border border-slate-200/50 dark:border-white/10 shadow-md shadow-slate-950/5 dark:shadow-black/20 transition duration-300">
                                <img src="<?php echo getLogoUrl($logo['logo_url']); ?>" alt="<?php echo htmlspecialchars($logo['client_name']); ?>" class="max-h-14 md:max-h-16 w-auto object-contain opacity-80 group-hover:opacity-100" loading="lazy" decoding="async">
                            </div>
                        <?php endforeach; endfor; 
                    } else { ?>
                        <div class="w-full text-center text-slate-400 text-sm px-6 py-12">
                            <?php echo t('clients.empty', [], $lang); ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </section>

    <!-- METRICS -->
    <section class="py-16 px-4 md:px-6 bg-slate-50 dark:bg-slate-950">
        <div class="max-w-5xl mx-auto">
            <div class="grid grid-cols-2 md:grid-cols-4 border border-slate-200 dark:border-white/10 rounded-2xl divide-y md:divide-y-0 md:divide-x divide-slate-200 dark:divide-white/10 bg-white/90 dark:bg-slate-900/80 backdrop-blur-sm shadow-sm">
                <?php $mQ = mysqli_query($conn, "SELECT * FROM impact_metrics ORDER BY display_order ASC"); while($m=mysqli_fetch_assoc($mQ)): ?>
                <div class="p-4 md:p-6 flex flex-col items-center justify-center text-center group hover:bg-white dark:hover:bg-white/5 transition duration-300" data-aos="fade-up" data-aos-duration="600">
                    <div class="mb-2 text-slate-400 group-hover:text-primary group-hover:-translate-y-1 transition duration-300"><i class="<?php echo $m['icon']; ?> text-lg"></i></div>
                    <div class="w-full text-lg md:text-xl font-bold text-slate-800 dark:text-white mb-1 break-words leading-tight group-hover:text-slate-900 dark:group-hover:text-white transition"><?php echo htmlspecialchars(localizedField($m, 'metric_value', $lang)); ?></div>
                    <div class="w-full text-[10px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest break-words leading-snug"><?php echo htmlspecialchars(localizedField($m, 'metric_name', $lang)); ?></div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </section>

    <!-- PROJECTS -->
    <section id="projects" class="py-20 px-6 bg-gradient-to-b from-white via-slate-50 to-slate-100 dark:from-slate-950 dark:via-slate-900 dark:to-darkbg">
        <div class="max-w-7xl mx-auto">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold mb-4 text-slate-900 dark:text-white"><?php echo t('projects.title', [], $lang); ?> <span class="text-primary"><?php echo t('projects.title_highlight', [], $lang); ?></span></h2>
                <p class="text-slate-500 mb-8 max-w-2xl mx-auto text-sm md:text-base"><?php echo nl2br(htmlspecialchars($projectsDesc)); ?></p>
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
                        <img src="<?php echo $p['image_url']; ?>" class="w-full h-full object-cover transition duration-700 group-hover:scale-110" loading="lazy" decoding="async">
                        <div class="absolute top-4 right-4"><span class="text-[10px] font-bold bg-white/90 dark:bg-black/70 text-slate-900 dark:text-white px-3 py-1 rounded-full backdrop-blur-md border border-white/10 shadow-sm"><?php echo htmlspecialchars($projectCategory); ?></span></div>
                    </div>
                    <div class="p-6 flex flex-col flex-grow">
                        <div class="flex items-center gap-2 mb-3"><span class="text-[10px] font-bold font-mono text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-500/10 px-2 py-0.5 rounded border border-green-200 dark:border-green-500/20"><i class="fas fa-chart-line mr-1"></i> <?php echo htmlspecialchars(localizedField($p, 'result_text', $lang)); ?></span></div>
                        <h3 class="text-xl font-bold mb-2 text-slate-900 dark:text-white group-hover:text-primary transition line-clamp-1"><?php echo htmlspecialchars(localizedField($p, 'title', $lang)); ?></h3>
                        <p class="text-slate-500 dark:text-slate-400 text-sm line-clamp-2 mb-6 leading-relaxed flex-grow"><?php echo htmlspecialchars(localizedField($p, 'description', $lang)); ?></p>
                        
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

    <!-- SKILLS -->
    <section id="skills" class="py-20 px-6 bg-slate-50 dark:bg-slate-950">
        <div class="max-w-6xl mx-auto">
            <h2 class="text-3xl md:text-4xl font-bold mb-12 text-center text-slate-900 dark:text-white"><?php echo t('skills.title', [], $lang); ?> <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary to-secondary"><?php echo t('skills.title_highlight', [], $lang); ?></span></h2>
            <?php 
            $skillCategories = [];
            $skillCatQ = mysqli_query($conn, "SELECT DISTINCT category FROM skills ORDER BY category ASC");
            while($skillCatRow = mysqli_fetch_assoc($skillCatQ)) {
                if (!empty($skillCatRow['category'])) {
                    $skillCategories[] = $skillCatRow['category'];
                }
            }
            foreach($skillCategories as $cat): 
                $sQ = mysqli_query($conn, "SELECT * FROM skills WHERE category='$cat'");
                if(mysqli_num_rows($sQ) > 0):
            ?>
                <div class="mb-10" data-aos="fade-up">
                    <h3 class="text-lg font-bold text-slate-500 dark:text-slate-400 mb-4 border-l-4 border-primary pl-3"><?php echo translateSkillCategory($cat, $lang); ?></h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                        <?php while($s=mysqli_fetch_assoc($sQ)): ?>
                        <div class="p-4 bg-white dark:bg-white/5 rounded-2xl border border-black/5 dark:border-white/5 flex flex-col items-center justify-center gap-3 hover:bg-white dark:hover:bg-white/10 hover:shadow-lg hover:-translate-y-1 transition duration-300 group">
                            <?php if($s['icon_url']): ?>
                                <?php if(strpos($s['icon_url'], 'fa')===0): ?>
                                    <i class="<?php echo $s['icon_url']; ?> text-3xl text-slate-400 group-hover:text-primary transition"></i>
                                <?php else: ?>
                                    <img src="<?php echo $s['icon_url']; ?>" class="w-8 h-8 object-contain opacity-80 group-hover:opacity-100 transition" loading="lazy" decoding="async">
                                <?php endif; ?>
                            <?php else: ?>
                                <i class="fas fa-check-circle text-primary text-2xl"></i>
                            <?php endif; ?>
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-300 text-center"><?php echo htmlspecialchars(localizedField($s, 'skill_name', $lang)); ?></span>
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
                    <?php $xQ = mysqli_query($conn, "SELECT * FROM experience ORDER BY id DESC"); $delay = 0; while($x=mysqli_fetch_assoc($xQ)): ?>
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
                    <?php $eQ = mysqli_query($conn, "SELECT * FROM education ORDER BY id DESC"); $delay = 0; while($e=mysqli_fetch_assoc($eQ)): ?>
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
                <?php $bQ = mysqli_query($conn, "SELECT * FROM articles ORDER BY created_at DESC LIMIT 3"); 
                if(mysqli_num_rows($bQ) > 0): while($b=mysqli_fetch_assoc($bQ)): ?>
                    <!-- Menggunakan Helper Link SEO Friendly -->
                    <a href="<?php echo getBlogLink($b, $lang); ?>" class="group bg-white dark:bg-darkcard rounded-2xl overflow-hidden shadow-sm hover:shadow-xl hover:-translate-y-1 transition duration-300 border border-black/5 dark:border-white/5 block">
                        <div class="h-48 overflow-hidden">
                            <img src="<?php echo $b['image_url']; ?>" class="w-full h-full object-cover transition duration-500 group-hover:scale-110" loading="lazy" decoding="async">
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
                    <input type="hidden" name="lang" value="<?php echo htmlspecialchars($lang); ?>">
                    <input name="name" class="w-full bg-white dark:bg-darkbg border border-slate-200 dark:border-white/10 rounded-xl p-4 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary transition" placeholder="<?php echo t('contact.placeholder_name', [], $lang); ?>" required>
                    <input name="email" class="w-full bg-white dark:bg-darkbg border border-slate-200 dark:border-white/10 rounded-xl p-4 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary transition" placeholder="<?php echo t('contact.placeholder_email', [], $lang); ?>" required>
                    <textarea name="message" class="w-full bg-white dark:bg-darkbg border border-slate-200 dark:border-white/10 rounded-xl p-4 text-sm h-32 outline-none focus:border-primary focus:ring-1 focus:ring-primary resize-none transition" placeholder="<?php echo t('contact.placeholder_message', [], $lang); ?>" required></textarea>
                    <button type="submit" data-track-event="contact_submit" class="w-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold py-4 rounded-xl hover:shadow-lg hover:scale-[1.02] transition duration-300"><?php echo t('contact.submit', [], $lang); ?></button>
                </form>
            </div>
        </div>
    </section>

    <footer class="py-12 border-t border-slate-200 dark:border-white/5 bg-slate-50 dark:bg-black/20 pb-20 lg:pb-12">
        <div class="max-w-6xl mx-auto px-6 text-center">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-8"><?php echo t('footer.connected', [], $lang); ?></h3>
            <div class="flex flex-wrap justify-center gap-4 mb-8">
                <?php 
                $socials = [
                    ['url' => $profile['link_linkedin'], 'icon' => 'fab fa-linkedin', 'color' => 'hover:text-blue-600'],
                    ['url' => $profile['link_instagram'], 'icon' => 'fab fa-instagram', 'color' => 'hover:text-pink-500'],
                    ['url' => $profile['link_twitter'], 'icon' => 'fab fa-twitter', 'color' => 'hover:text-sky-500'],
                    ['url' => $profile['link_tiktok'], 'icon' => 'fab fa-tiktok', 'color' => 'hover:text-pink-400'],
                    ['url' => $profile['link_threads'] ?? '', 'icon' => 'fa-brands fa-threads', 'color' => 'hover:text-slate-500 dark:hover:text-white'],
                    ['url' => $profile['link_facebook'], 'icon' => 'fab fa-facebook', 'color' => 'hover:text-blue-700'],
                    ['url' => $profile['link_youtube'], 'icon' => 'fab fa-youtube', 'color' => 'hover:text-red-600'],
                    ['url' => $profile['link_github'], 'icon' => 'fab fa-github', 'color' => 'hover:text-slate-900 dark:hover:text-white'],
                    ['url' => $profile['link_blog_pribadi'], 'icon' => 'fas fa-globe', 'color' => 'hover:text-green-500'],
                ];
                foreach($socials as $soc): if(!empty($soc['url'])): ?>
                    <a href="<?php echo $soc['url']; ?>" target="_blank" class="w-10 h-10 rounded-full bg-white dark:bg-white/5 border border-black/5 dark:border-white/10 flex items-center justify-center text-slate-400 <?php echo $soc['color']; ?> text-lg transition transform hover:scale-110"><i class="<?php echo $soc['icon']; ?>"></i></a>
                <?php endif; endforeach; ?>
            </div>
            <div class="text-slate-500 text-sm font-medium">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteTitle); ?>. <?php echo t('footer.rights', [], $lang); ?></div>
        </div>
    </footer>

    <!-- Floating Actions -->
    <div class="fixed bottom-6 right-4 z-40 flex flex-col items-end gap-3 group">
        <a href="mailto:<?php echo htmlspecialchars($profile['email']); ?>" class="relative w-12 h-12 rounded-full bg-white dark:bg-slate-800 text-red-500 flex items-center justify-center text-xl shadow-lg border border-slate-100 dark:border-slate-700 hover:scale-110 transition">
            <i class="far fa-envelope"></i>
        </a>
        <a href="https://wa.me/<?php echo $profile['whatsapp']; ?>" target="_blank" class="relative w-12 h-12 rounded-full bg-green-500 text-white flex items-center justify-center text-xl shadow-lg hover:shadow-green-500/50 transition transform hover:scale-110">
            <span class="absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75 animate-ping"></span><i class="fab fa-whatsapp relative z-10"></i>
        </a>
    </div>

    <!-- 5. OPTIMASI: Defer script JS utama -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js" defer></script>
    <script defer>
        document.addEventListener("DOMContentLoaded", function() { 
            // Inisialisasi AOS setelah DOM siap sepenuhnya
            if(typeof AOS !== 'undefined') {
                AOS.init({ duration: 800, once: true, offset: 50 }); 
            } else {
                // Fallback jika script defer belum load (jarang terjadi)
                window.onload = function() { AOS.init({ duration: 800, once: true, offset: 50 }); }
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
        if(localStorage.theme !== 'light') {
            const fireflies = document.getElementById('fireflies-container');
            fireflies.style.display = 'block';
            for(let i = 0; i < 32; i++) {
                let d = document.createElement('div');
                d.className = 'firefly';
                let size = Math.random() * 4 + 2;
                d.style.width = size + 'px';
                d.style.height = size + 'px';
                d.style.left = Math.random() * 100 + 'vw';
                d.style.top = Math.random() * 100 + 'vh';
                d.style.opacity = Math.random() * 0.75 + 0.15;
                d.style.setProperty('--mx', (Math.random() * 120 - 60) + 'px');
                d.style.setProperty('--my', (Math.random() * 120 - 60) + 'px');
                d.style.animationDuration = (Math.random() * 6 + 4) + 's';
                d.style.animationDelay = (Math.random() * 5) + 's';
                fireflies.appendChild(d);
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
                btn.className = `px-4 py-1.5 rounded-full text-xs font-bold transition transform hover:scale-105 ${isPrimary ? 'bg-primary text-white hover:bg-secondary' : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-300'}`;
                btn.innerText = opt.text;
                btn.onclick = () => {
                    if (opt.action === 'close') { 
                        // ANIMASI CLOSE CHAT + NAIKKAN HERO IMAGE
                        chatContainer.classList.add('opacity-0', 'scale-90'); 
                        
                        // Logika Auto-Mendekat
                        const heroWrapper = document.getElementById('hero-image-wrapper');
                        if(heroWrapper) {
                            heroWrapper.classList.remove('mt-20');
                            heroWrapper.classList.add('-mt-12'); // Naik ke posisi semula
                        }

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

        function reportAnalyticsEvent(type, key) {
            const url = 'process.php';
            const data = new FormData();
            data.append('action', 'track_event');
            data.append('event_type', type);
            data.append('event_key', key);
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
        });
    </script>
</body>
</html>

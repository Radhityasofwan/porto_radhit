<?php 
require_once 'i18n.php';

// 1. Optimasi GZIP
if (!empty($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) ob_start("ob_gzhandler"); else ob_start();

$lang = currentLang();

include 'db.php'; 

// --- DEFINISI BASE URL (PENTING UNTUK REDIRECT) ---
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
// Deteksi folder tempat script berada
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
// Pastikan diakhiri slash '/' dan tidak double slash
$baseUrl = $protocol . "://" . $host . rtrim($scriptPath, '/\\') . '/';

// --- LOGIKA UTAMA: FETCH BY SLUG OR ID (HYBRID) ---
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slug = isset($_GET['slug']) ? mysqli_real_escape_string($conn, $_GET['slug']) : '';

if ($slug) {
    $q = mysqli_query($conn, "SELECT * FROM projects WHERE slug='$slug'");
} else {
    $q = mysqli_query($conn, "SELECT * FROM projects WHERE id=$id");
}

$project = mysqli_fetch_assoc($q);
if(!$project) { 
    // Redirect ke home jika project tidak ditemukan
    header("Location: " . localizedUrl('./', $lang)); 
    exit; 
}

// --- FITUR BARU: AUTO-REDIRECT KE CLEAN URL (SEO 301) ---
// Jika user akses via ID (misal: project-details.php?id=3) TAPI slug-nya ada di database,
// Maka paksa redirect ke URL cantik (misal: portfolio/judul-proyek)
if (isset($_GET['id']) && !empty($project['slug'])) {
    $cleanUrl = $baseUrl . "portfolio/" . $project['slug'];
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: " . localizedUrl($cleanUrl, $lang));
    exit;
}

$project_id = $project['id'];
$profile = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=1"));
$currentUrl = currentUrlWithLang($lang);
$projectTitle = localizedField($project, 'title', $lang);
$projectCategory = localizedField($project, 'category', $lang);
$projectDescription = localizedField($project, 'description', $lang);
$projectMeta = trim(localizedField($project, 'meta_desc', $lang));
if ($projectMeta === '') {
    $projectMeta = $projectDescription;
}
$projectDetails = localizedField($project, 'details', $lang);
$projectResult = localizedField($project, 'result_text', $lang);
$projectChartData = localizedField($project, 'chart_data_json', $lang);

// Fetch Gallery
$gallery = []; 
$gQuery = mysqli_query($conn, "SELECT * FROM project_images WHERE project_id=$project_id");
while($row = mysqli_fetch_assoc($gQuery)) { $gallery[] = $row; }

// Tech Stack Array
$techs = explode(',', $project['tech_stacks']);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($projectTitle); ?> - <?php echo t('project.detail', [], $lang); ?></title>
    
    <!-- PENTING: Base URL agar asset relatif aman -->
    <base href="<?php echo $baseUrl; ?>">

    <!-- SEO Meta Tags -->
    <meta name="description" content="<?php echo htmlspecialchars($projectMeta); ?>">
    <link rel="canonical" href="<?php echo $currentUrl; ?>">
    <link rel="alternate" hreflang="id" href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>">
    <link rel="alternate" hreflang="en" href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>">
    
    <!-- Open Graph -->
    <meta property="og:title" content="<?php echo htmlspecialchars($projectTitle); ?> | <?php echo t('project.detail', [], $lang); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($projectMeta); ?>">
    <meta property="og:image" content="<?php echo $baseUrl . $project['image_url']; ?>">
    <meta property="og:type" content="article">

    <!-- Styles & Scripts -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script> 
        tailwind.config = { 
            darkMode: 'class', 
            theme: { 
                extend: { 
                    colors: { 
                        darkbg: '#0F172A', darkcard: '#1E293B', 
                        lightbg: '#F8FAFC', lightcard: '#FFFFFF', 
                        primary: '#6366F1' 
                    } 
                } 
            } 
        } 
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap'); body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
    <script>if (localStorage.theme === 'light') { document.documentElement.classList.remove('dark'); } else { document.documentElement.classList.add('dark'); }</script>
</head>
<body class="bg-lightbg text-slate-800 dark:bg-darkbg dark:text-slate-100 selection:bg-primary selection:text-white">

    <div class="fixed top-6 left-0 right-0 z-50 flex justify-center px-4">
        <nav class="bg-white/80 dark:bg-slate-900/80 backdrop-blur border border-slate-200 dark:border-white/10 rounded-full px-6 py-3 w-full max-w-4xl flex justify-between items-center shadow-lg">
            <a href="<?php echo htmlspecialchars(localizedUrl('./#projects', $lang)); ?>" class="text-sm font-bold tracking-tight flex items-center gap-2 hover:text-primary transition group">
                <span class="w-8 h-8 rounded-full bg-slate-100 dark:bg-white/10 flex items-center justify-center group-hover:bg-primary group-hover:text-white transition"><i class="fas fa-arrow-left"></i></span>
                <span><?php echo t('project.back', [], $lang); ?></span>
            </a>
            <span class="hidden md:block text-sm font-bold text-slate-500"><?php echo t('project.detail', [], $lang); ?></span>
            <div class="flex items-center gap-2">
                <div class="flex items-center rounded-full border border-slate-200 dark:border-white/10 p-1">
                    <a href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>" class="px-2.5 py-1 rounded-full text-[10px] font-bold <?php echo $lang === 'id' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-500 dark:text-slate-300'; ?>">ID</a>
                    <a href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>" class="px-2.5 py-1 rounded-full text-[10px] font-bold <?php echo $lang === 'en' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-500 dark:text-slate-300'; ?>">EN</a>
                </div>
                <button id="theme-toggle" class="w-9 h-9 rounded-full bg-black/5 dark:bg-white/10 flex items-center justify-center text-yellow-500 dark:text-blue-300 hover:scale-110 transition"><i class="fas fa-sun hidden dark:block"></i><i class="fas fa-moon block dark:hidden"></i></button>
            </div>
        </nav>
    </div>

    <main class="pt-40 pb-20 px-6 max-w-6xl mx-auto">
        <div class="mb-12 text-center md:text-left">
            <span class="text-xs font-bold text-primary uppercase tracking-widest bg-primary/10 px-3 py-1.5 rounded-full border border-primary/20"><?php echo htmlspecialchars($projectCategory); ?></span>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold mt-6 mb-8 leading-tight text-slate-900 dark:text-white"><?php echo htmlspecialchars($projectTitle); ?></h1>
            <div class="flex flex-wrap justify-center md:justify-start gap-8 text-sm text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-white/5 pb-10">
                <div><span class="block text-xs font-bold text-slate-400 uppercase mb-1"><?php echo t('project.client', [], $lang); ?></span> <span class="text-slate-900 dark:text-white font-semibold"><?php echo htmlspecialchars($project['client_name']); ?></span></div>
                <div><span class="block text-xs font-bold text-slate-400 uppercase mb-1"><?php echo t('project.result', [], $lang); ?></span> <span class="text-green-600 dark:text-green-400 font-bold bg-green-100 dark:bg-green-500/20 px-2 py-0.5 rounded"><?php echo htmlspecialchars($projectResult); ?></span></div>
                <div>
                    <span class="block text-xs font-bold text-slate-400 uppercase mb-1"><?php echo t('project.tech_stack', [], $lang); ?></span> 
                    <div class="flex gap-1 flex-wrap">
                        <?php foreach($techs as $t): if(trim($t)!=''): ?>
                        <span class="bg-slate-100 dark:bg-white/10 px-2 py-0.5 rounded text-xs font-mono border border-slate-200 dark:border-white/5"><?php echo trim($t); ?></span>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid lg:grid-cols-3 gap-12">
            <div class="lg:col-span-2">
                <div class="rounded-3xl overflow-hidden border border-slate-200 dark:border-white/10 shadow-2xl mb-12 bg-slate-100 dark:bg-black">
                    <img src="<?php echo $project['image_url']; ?>" class="w-full h-auto object-cover">
                </div>

                <!-- Bagian Analisis -->
                <div class="prose prose-lg max-w-none text-slate-700 dark:text-slate-300 leading-8 prose-headings:text-slate-900 dark:prose-headings:text-white prose-strong:text-slate-900 dark:prose-strong:text-white">
                    <h3 class="text-2xl font-bold mb-6"><?php echo t('project.analysis', [], $lang); ?></h3>
                    <?php echo $projectDetails; // Support HTML dari admin ?>
                </div>

                <?php if($project['link_url']): ?>
                <div class="mt-12">
                    <a href="<?php echo $project['link_url']; ?>" target="_blank" class="inline-flex items-center gap-3 bg-slate-900 dark:bg-white text-white dark:text-slate-900 px-8 py-4 rounded-full font-bold hover:shadow-lg hover:scale-105 transition duration-300"><?php echo t('project.visit_live', [], $lang); ?> <i class="fas fa-external-link-alt"></i></a>
                </div>
                <?php endif; ?>
            </div>

            <div class="lg:col-span-1 space-y-8">
                <!-- CHART SECTION -->
                <?php if(!empty($projectChartData) && $projectChartData != 'NULL'): ?>
                <div class="bg-white dark:bg-darkcard border border-slate-200 dark:border-white/5 p-6 rounded-3xl shadow-lg">
                    <div class="flex justify-between items-center mb-6">
                        <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider"><?php echo t('project.stats', [], $lang); ?></h4>
                        <span class="flex h-3 w-3 relative"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span></span>
                    </div>
                    <canvas id="growthChart" height="250"></canvas>
                    <script>
                        const rawData = <?php echo $projectChartData; ?>;
                        if(rawData && rawData.labels) {
                            const isDark = document.documentElement.classList.contains('dark');
                            new Chart(document.getElementById('growthChart'), {
                                type: 'line',
                                data: {
                                    labels: rawData.labels,
                                    datasets: [{
                                        label: rawData.label || 'Growth',
                                        data: rawData.data,
                                        borderColor: '#6366F1',
                                        backgroundColor: 'rgba(99, 102, 241, 0.15)',
                                        borderWidth: 3, tension: 0.4, fill: true,
                                        pointBackgroundColor: isDark ? '#fff' : '#0F172A', pointRadius: 4
                                    }]
                                },
                                options: { responsive: true, plugins: { legend: { display: true, position: 'bottom' } }, scales: { y: { grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' }, ticks: { color: '#94a3b8' } }, x: { grid: { display: false }, ticks: { color: '#94a3b8' } } } }
                            });
                        }
                    </script>
                </div>
                <?php endif; ?>

                <!-- GALLERY SECTION -->
                <?php if(count($gallery)>0): ?>
                <div class="bg-white dark:bg-darkcard border border-slate-200 dark:border-white/5 p-6 rounded-3xl shadow-lg">
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-6 border-b border-slate-100 dark:border-white/5 pb-4"><?php echo t('project.gallery', [], $lang); ?></h4>
                    <div class="grid grid-cols-2 gap-3">
                        <?php foreach($gallery as $img): ?>
                        <div class="aspect-square rounded-2xl overflow-hidden border border-slate-200 dark:border-white/10 cursor-pointer group relative" onclick="window.open('<?php echo $img['image_url']; ?>','_blank')">
                            <img src="<?php echo $img['image_url']; ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <footer class="py-10 border-t border-slate-200 dark:border-white/5 text-center text-slate-500 text-sm">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($profile['name']); ?>. <?php echo t('footer.rights', [], $lang); ?>
    </footer>

    <script>
        document.getElementById('theme-toggle').addEventListener('click', () => {
            if (document.documentElement.classList.contains('dark')) { document.documentElement.classList.remove('dark'); localStorage.theme = 'light'; } else { document.documentElement.classList.add('dark'); localStorage.theme = 'dark'; } location.reload(); 
        });
    </script>
</body>
</html>

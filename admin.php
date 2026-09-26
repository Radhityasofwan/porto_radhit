<?php 
session_start(); 
require_once 'i18n.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Enable MySQLi error reporting as exceptions
$lang = currentLang();
require_once __DIR__ . '/auth_check.php';
if (!isset($_SESSION['admin_logged_in'])) { header("Location: " . localizedUrl('login.php', $lang)); exit; }
include 'db.php'; 

// Generate CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// --- 0. FETCH SETTINGS ---
$siteSettings = [];
$settingsQ = mysqli_query($conn, "SELECT setting_key, setting_value FROM site_settings");
while ($sr = mysqli_fetch_assoc($settingsQ)) { $siteSettings[$sr['setting_key']] = $sr['setting_value']; }

// Load multi-key list (new) with backward compat for single key (old)
$geminiKeysRaw = $siteSettings['gemini_api_keys'] ?? '';
$geminiKeys = [];
if ($geminiKeysRaw) {
    $decoded = json_decode($geminiKeysRaw, true);
    if (is_array($decoded)) $geminiKeys = array_values(array_filter(array_map('trim', $decoded)));
}
if (empty($geminiKeys) && !empty($siteSettings['gemini_api_key'])) {
    $geminiKeys = [trim($siteSettings['gemini_api_key'])];
}

// --- MULTI-PROFILE: resolve editing profile ---
$allProfiles = [];
$apQ = mysqli_query($conn, "SELECT id, name, profile_label, is_active FROM profile ORDER BY id ASC");
while ($r = mysqli_fetch_assoc($apQ)) $allProfiles[] = $r;

// Ensure editing_profile_id in session is valid
if (empty($_SESSION['editing_profile_id']) || !in_array($_SESSION['editing_profile_id'], array_column($allProfiles, 'id'))) {
    $activeP = array_filter($allProfiles, fn($p) => $p['is_active']);
    $_SESSION['editing_profile_id'] = $activeP ? reset($activeP)['id'] : ($allProfiles[0]['id'] ?? 1);
}
$editingProfileId = (int)$_SESSION['editing_profile_id'];

// --- 1. DATA FETCHING (Centralized) ---
$payload = [];

// Profile
$payload['profile'] = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=$editingProfileId"));
$siteTitleAdmin = localizedField($payload['profile'], 'site_title', $lang);

// Projects & Gallery
$projects = [];
$pQ = mysqli_query($conn, "SELECT * FROM projects WHERE profile_id=$editingProfileId ORDER BY display_order ASC");
while($row = mysqli_fetch_assoc($pQ)) {
    $gQ = mysqli_query($conn, "SELECT * FROM project_images WHERE project_id=" . $row['id']);
    $gallery = [];
    while($g = mysqli_fetch_assoc($gQ)) { $gallery[] = $g; }
    $row['gallery'] = $gallery;
    $projects[] = $row;
}
$payload['projects'] = $projects;

// Metrics
$metrics = []; $mQ = mysqli_query($conn, "SELECT * FROM impact_metrics WHERE profile_id=$editingProfileId ORDER BY display_order ASC");
while($r=mysqli_fetch_assoc($mQ)) $metrics[]=$r;
$payload['metrics'] = $metrics;

// Clients
$clients = []; $cQ = mysqli_query($conn, "SELECT * FROM client_logos WHERE profile_id=$editingProfileId ORDER BY display_order ASC");
while($r=mysqli_fetch_assoc($cQ)) $clients[]=$r;
$payload['clients'] = $clients;

// Skills
$skills = []; $sQ = mysqli_query($conn, "SELECT * FROM skills WHERE profile_id=$editingProfileId ORDER BY category, skill_name");
while($r=mysqli_fetch_assoc($sQ)) $skills[]=$r;
$payload['skills'] = $skills;

// Experience
$experience = []; $xQ = mysqli_query($conn, "SELECT * FROM experience WHERE profile_id=$editingProfileId ORDER BY id DESC");
while($r=mysqli_fetch_assoc($xQ)) $experience[]=$r;
$payload['experience'] = $experience;

// Education
$education = []; $eQ = mysqli_query($conn, "SELECT * FROM education WHERE profile_id=$editingProfileId ORDER BY id DESC");
while($r=mysqli_fetch_assoc($eQ)) $education[]=$r;
$payload['education'] = $education;

// Articles
$articles = []; $bQ = mysqli_query($conn, "SELECT * FROM articles WHERE profile_id=$editingProfileId ORDER BY created_at DESC");
while($r=mysqli_fetch_assoc($bQ)) $articles[]=$r;
$payload['articles'] = $articles;

// Messages
$messages = []; $msgQ = mysqli_query($conn, "SELECT * FROM messages WHERE profile_id=$editingProfileId ORDER BY created_at DESC");
while($r=mysqli_fetch_assoc($msgQ)) $messages[]=$r;
$payload['messages'] = $messages;

// Chat
$chats = []; $chatQ = mysqli_query($conn, "SELECT * FROM hero_chat WHERE profile_id=$editingProfileId ORDER BY display_order ASC");
while($r=mysqli_fetch_assoc($chatQ)) $chats[]=$r;
$payload['chats'] = $chats;

// Initialize analytics data
$analyticsSummary  = ['unique_visitors' => 0, 'impressions' => 0, 'button_clicks' => 0];
$analyticsToday    = ['unique_today' => 0, 'views_today' => 0, 'clicks_today' => 0];
$analyticsMonth    = ['unique_month' => 0, 'views_month' => 0, 'clicks_month' => 0];
$analyticsTop      = [];
$analyticsRecent   = [];

try {
    $tableExistsQuery = mysqli_query($conn, "SHOW TABLES LIKE 'web_analytics'");
    if ($tableExistsQuery && mysqli_num_rows($tableExistsQuery) > 0) {

        // All-time summary
        $r = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(DISTINCT ip_address) AS unique_visitors,
                    SUM(event_type='page_view')    AS impressions,
                    SUM(event_type='button_click') AS button_clicks
             FROM web_analytics WHERE profile_id=$editingProfileId"));
        if ($r) $analyticsSummary = $r;

        // Today
        $r = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(DISTINCT ip_address)             AS unique_today,
                    SUM(event_type='page_view')            AS views_today,
                    SUM(event_type='button_click')         AS clicks_today
             FROM web_analytics WHERE profile_id=$editingProfileId AND DATE(created_at) = CURDATE()"));
        if ($r) $analyticsToday = $r;

        // This month
        $r = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(DISTINCT ip_address)             AS unique_month,
                    SUM(event_type='page_view')            AS views_month,
                    SUM(event_type='button_click')         AS clicks_month
             FROM web_analytics
             WHERE profile_id=$editingProfileId AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())"));
        if ($r) $analyticsMonth = $r;

        // Top button clicks
        $topQuery = mysqli_query($conn,
            "SELECT event_key, COUNT(*) AS total
             FROM web_analytics WHERE profile_id=$editingProfileId AND event_type='button_click'
             GROUP BY event_key ORDER BY total DESC LIMIT 8");
        while($r = mysqli_fetch_assoc($topQuery)) $analyticsTop[] = $r;

        // Recent activity
        $recentQuery = mysqli_query($conn,
            "SELECT event_type, event_key, ip_address, created_at
             FROM web_analytics WHERE profile_id=$editingProfileId ORDER BY created_at DESC LIMIT 12");
        while($r = mysqli_fetch_assoc($recentQuery)) $analyticsRecent[] = $r;

        // Avg session duration (session_end events, filtered outliers)
        $durBase = "event_type='session_end' AND profile_id=$editingProfileId AND CAST(event_key AS UNSIGNED) BETWEEN 2 AND 3600";
        $durAll   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ROUND(AVG(CAST(event_key AS UNSIGNED))) AS avg_sec, COUNT(*) AS sessions FROM web_analytics WHERE $durBase"));
        $durToday = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ROUND(AVG(CAST(event_key AS UNSIGNED))) AS avg_sec FROM web_analytics WHERE $durBase AND DATE(created_at)=CURDATE()"));
        $durMonth = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ROUND(AVG(CAST(event_key AS UNSIGNED))) AS avg_sec FROM web_analytics WHERE $durBase AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())"));
    }
} catch (mysqli_sql_exception $e) {
    error_log("Analytics fetch error: " . $e->getMessage());
}

// Content counts
$contentCounts = [
    'projects' => count($payload['projects'] ?? []),
    'skills'   => count($payload['skills']   ?? []),
    'articles' => count($payload['articles'] ?? []),
    'clients'  => count($payload['clients']  ?? []),
    'messages' => count($payload['messages'] ?? []),
];

// Conversion rate helper
function ctr(int $clicks, int $views): string {
    return $views > 0 ? number_format($clicks / $views * 100, 1) . '%' : '—';
}
// Format seconds → "Xm Ys"
function fmtDur(?int $secs): string {
    if (!$secs || $secs <= 0) return '—';
    $m = intdiv($secs, 60); $s = $secs % 60;
    return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
}
// Trash / recently deleted
$recentTrash = [];
$trashQ = mysqli_query($conn, "SELECT id, table_name, row_data, deleted_at FROM deleted_items ORDER BY deleted_at DESC LIMIT 40");
while ($r = mysqli_fetch_assoc($trashQ)) {
    $r['row_parsed'] = json_decode($r['row_data'], true) ?: [];
    $recentTrash[] = $r;
}
$trashCount = count($recentTrash);

// Default duration vars if analytics table absent
$durAll   = $durAll   ?? ['avg_sec' => null, 'sessions' => 0];
$durToday = $durToday ?? ['avg_sec' => null];
$durMonth = $durMonth ?? ['avg_sec' => null];
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'profile';
$adminUi = [
    'saving' => t('admin.alert.saving', [], $lang),
    'success' => t('admin.alert.success', [], $lang),
    'failed' => t('admin.alert.failed', [], $lang),
    'error' => t('admin.alert.error', [], $lang),
    'connectionFailed' => t('admin.alert.connection_failed', [], $lang),
    'deleteTitle' => t('admin.alert.delete_title', [], $lang),
    'deleteConfirm' => t('admin.alert.delete_confirm', [], $lang),
    'galleryDelete' => t('admin.alert.gallery_delete', [], $lang),
];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo t('admin.panel', [], $lang); ?> - <?php echo htmlspecialchars($siteTitleAdmin); ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"></noscript>
    <link rel="stylesheet" href="/styles.min.css?v=4">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"></noscript>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
    
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #F8FAFC; color: #334155; -webkit-tap-highlight-color: transparent; }
        
        /* Modern Input */
        .input-modern { 
            width: 100%; background: #fff; border: 1px solid #E2E8F0; padding: 0.75rem 1rem; 
            border-radius: 0.75rem; outline: none; transition: all 0.2s; font-size: 14px; 
        }
        .input-modern:focus { border-color: #6366F1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        .input-code {
            width: 100%; background: #1E293B; border: 1px solid #334155; padding: 1rem; 
            border-radius: 0.75rem; outline: none; font-family: 'JetBrains Mono', monospace; 
            font-size: 12px; color: #E2E8F0; line-height: 1.6;
        }
        
        /* Buttons */
        .btn-modern {
            background: linear-gradient(135deg, #4F46E5 0%, #4338CA 100%); color: white;
            padding: 0.75rem 1.5rem; border-radius: 0.75rem; font-weight: 600; font-size: 0.9rem;
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            transition: transform 0.1s; cursor: pointer;
        }
        .btn-modern:active { transform: scale(0.98); }
        .btn-action {
            width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center;
            transition: all 0.2s; cursor: pointer;
        }
        .btn-edit { background: #EFF6FF; color: #3B82F6; }
        .btn-delete { background: #FEF2F2; color: #EF4444; }
        .btn-seed {
            padding: 0.5rem 1rem; border-radius: 0.75rem; font-weight: 600; font-size: 0.8rem;
            display: inline-flex; align-items: center; gap: 0.4rem; cursor: pointer;
            border: 1.5px dashed #A5B4FC; color: #6366F1; background: #EEF2FF;
            transition: background 0.15s;
        }
        .btn-seed:hover { background: #E0E7FF; }

        /* Sidebar */
        .sidebar-item {
            display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem;
            border-radius: 0.75rem; font-weight: 600; color: #64748B; cursor: pointer;
            font-size: 0.9rem; transition: all 0.2s;
        }
        .sidebar-item:hover, .sidebar-item.active { background: #EEF2FF; color: #4F46E5; }
        .sidebar-header {
            padding: 0 1rem; margin-top: 1.5rem; margin-bottom: 0.5rem;
            font-size: 0.65rem; font-weight: 700; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.05em;
        }

        /* --- MOBILE MODAL FIXES --- */
        .modal-wrapper {
            position: fixed; inset: 0; z-index: 50;
            display: flex; align-items: center; justify-content: center;
            background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);
            opacity: 0; pointer-events: none; transition: opacity 0.2s;
        }
        .modal-wrapper.show { opacity: 1; pointer-events: auto; }

        .modal-box {
            background: white; width: 100%; max-width: 800px;
            border-radius: 1rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            height: auto; max-height: 90vh;
            display: flex; flex-direction: column; overflow: hidden;
            transform: scale(0.95); transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .modal-wrapper.show .modal-box { transform: scale(1); }

        @media (max-width: 640px) {
            .modal-box {
                position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                width: 100%; height: 100dvh; max-height: 100dvh; max-width: 100%;
                border-radius: 0;
            }
        }

        .modal-header { flex: 0 0 auto; padding: 1rem; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center; background: white; }
        .modal-body { flex: 1 1 auto; overflow-y: auto; padding: 1.25rem; }
        .modal-footer { flex: 0 0 auto; padding: 1rem; border-top: 1px solid #F1F5F9; background: white; }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 4px; }

        /* AI spinner */
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .animate-spin { animation: spin 1s linear infinite; }

        /* AI button pulse */
        @keyframes ai-pulse { 0%,100% { box-shadow: 0 0 0 0 rgba(139,92,246,0.4); } 50% { box-shadow: 0 0 0 6px rgba(139,92,246,0); } }
        .btn-ai-pulse { animation: ai-pulse 2s infinite; }

        /* ── CV Preview — matches CV-radhit.pdf ── */
        /* ── CV PREVIEW (screen) — mirrors print proportions at 96dpi ── */
        .cv-paper {
            background: #fff;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12.67px; line-height: 1.42; color: #000;
            width: 794px;
            padding: 45px 68px;          /* 12mm × 18mm margins at 96dpi */
            margin: 0 auto;
            box-sizing: border-box;
            box-shadow: 0 2px 20px rgba(0,0,0,0.12);
        }
        .cv-header-block { text-align: center; margin-bottom: 6px; }
        .cv-name {
            font-size: 18.67px; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; margin: 0 0 2.5px; color: #000;
        }
        .cv-title { font-size: 13.3px; font-weight: 700; color: #111; margin: 0 0 2.5px; }
        .cv-contact { font-size: 12px; color: #333; margin: 0 0 1px; }
        .cv-section-title {
            font-size: 11.3px; font-weight: 800; letter-spacing: 0.08em;
            text-transform: uppercase; color: #000;
            border-bottom: 1px solid #000; padding-bottom: 1.5px;
            margin: 10px 0 4px;
        }
        .cv-summary { font-size: 12.67px; margin: 0; color: #000; line-height: 1.45; }
        .cv-skills-list { margin: 1.5px 0 0; padding-left: 14px; list-style: disc; }
        .cv-skills-list li { font-size: 12.67px; margin-bottom: 1.5px; line-height: 1.42; }
        .cv-exp-row { display: flex; justify-content: space-between; align-items: baseline; margin-top: 5px; }
        .cv-company { font-weight: 700; font-size: 13.3px; color: #000; }
        .cv-period { font-size: 12px; color: #444; white-space: nowrap; margin-left: 6px; font-style: italic; }
        .cv-role { font-weight: 700; font-size: 12.67px; color: #111; margin: 0.5px 0 2px; }
        .cv-bullets { margin: 1.5px 0 2px; padding-left: 14px; list-style: disc; }
        .cv-bullets li { font-size: 12.67px; margin-bottom: 1.5px; line-height: 1.42; color: #000; }
        .cv-proj-list { margin: 1.5px 0 0; padding-left: 14px; list-style: disc; }
        .cv-proj-list li { font-size: 12.67px; margin-bottom: 1.5px; line-height: 1.42; }
        .cv-proj-hasil { display: block; font-size: 12px; color: #1a3a6b; margin-top: 0.5px; }
        .cv-edu-row { display: flex; justify-content: space-between; align-items: baseline; margin-top: 5px; }
        .cv-institution { font-weight: 700; font-size: 13.3px; color: #000; }
        .cv-edu-year { font-size: 12px; color: #444; white-space: nowrap; margin-left: 6px; font-style: italic; }
        .cv-degree { font-size: 12px; color: #333; margin: 1px 0 0; }
        @media print { .cv-paper { box-shadow: none; } }
        /* Inline CV editing */
        #cv-preview [contenteditable] { outline: none; border-radius: 2px; cursor: text; }
        #cv-preview [contenteditable]:hover { background: rgba(79,70,229,0.05); }
        #cv-preview [contenteditable]:focus { outline: 1.5px dashed rgba(79,70,229,0.45); background: rgba(79,70,229,0.06); }
        /* Contact slots: real " • " separators (so they survive copy) + hint on empty slots */
        #cv-preview .cv-contact > .cv-sep { margin: 0 .35em; }
        #cv-preview .cv-contact .cv-slot:empty::after { content: attr(data-ph); color: #c3c8d4; font-style: italic; }
    </style>
</head>
<body class="flex h-screen bg-[#F8FAFC] overflow-hidden">

    <!-- SIDEBAR (Desktop) -->
    <aside class="hidden lg:flex w-64 bg-white border-r border-slate-200 flex-col h-full z-10 shadow-xl lg:shadow-none">
        <div class="p-6">
            <div class="flex items-center gap-2 text-xl font-bold text-slate-800">
                <div class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center shadow-lg shadow-indigo-200"><i class="fas fa-bolt"></i></div>
                <span class="tracking-tight"><?php echo t('admin.panel', [], $lang); ?></span>
            </div>
            <div class="mt-4 flex items-center rounded-full border border-slate-200 p-1 bg-slate-50">
                <a href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>" class="px-3 py-1 rounded-full text-[11px] font-bold <?php echo $lang === 'id' ? 'bg-slate-900 text-white' : 'text-slate-500'; ?>">ID</a>
                <a href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>" class="px-3 py-1 rounded-full text-[11px] font-bold <?php echo $lang === 'en' ? 'bg-slate-900 text-white' : 'text-slate-500'; ?>">EN</a>
            </div>
        </div>
        <nav class="flex-1 px-4 overflow-y-auto custom-scrollbar pb-4">
            <div class="sidebar-header"><?php echo t('admin.nav.main', [], $lang); ?></div>
            <div onclick="switchTab('profile')" class="sidebar-item active" id="nav-profile"><i class="fas fa-user-circle w-5"></i> <?php echo t('admin.nav.profile', [], $lang); ?></div>
            <div onclick="switchTab('dashboard')" class="sidebar-item" id="nav-dashboard"><i class="fas fa-tachometer-alt w-5"></i> <?php echo t('admin.nav.dashboard', [], $lang); ?></div>
            <div onclick="switchTab('metrics')" class="sidebar-item" id="nav-metrics"><i class="fas fa-chart-pie w-5"></i> <?php echo t('admin.nav.metrics', [], $lang); ?></div>
            <div onclick="switchTab('clients')" class="sidebar-item" id="nav-clients"><i class="fas fa-building w-5"></i> <?php echo t('admin.nav.clients', [], $lang); ?></div>
            <div onclick="switchTab('chat')" class="sidebar-item" id="nav-chat"><i class="fas fa-comments w-5"></i> <?php echo t('admin.nav.chat', [], $lang); ?></div>
            
            <div class="sidebar-header"><?php echo t('admin.nav.portfolio_group', [], $lang); ?></div>
            <div onclick="switchTab('projects')" class="sidebar-item" id="nav-projects"><i class="fas fa-briefcase w-5"></i> <?php echo t('admin.nav.projects', [], $lang); ?></div>
            <div onclick="switchTab('skills')" class="sidebar-item" id="nav-skills"><i class="fas fa-tools w-5"></i> <?php echo t('admin.nav.skills', [], $lang); ?></div>

            <div class="sidebar-header"><?php echo t('admin.nav.content', [], $lang); ?></div>
            <div onclick="switchTab('experience')" class="sidebar-item" id="nav-experience"><i class="fas fa-history w-5"></i> <?php echo t('admin.nav.experience', [], $lang); ?></div>
            <div onclick="switchTab('education')" class="sidebar-item" id="nav-education"><i class="fas fa-graduation-cap w-5"></i> <?php echo t('admin.nav.education', [], $lang); ?></div>
            <div onclick="switchTab('blog')" class="sidebar-item" id="nav-blog"><i class="fas fa-newspaper w-5"></i> <?php echo t('admin.nav.blog', [], $lang); ?></div>

            <div class="sidebar-header">Persona</div>
            <div onclick="switchTab('profiles')" class="sidebar-item" id="nav-profiles">
                <i class="fas fa-id-card w-5"></i>
                <span class="flex-1 truncate">Kelola Profil</span>
                <span class="text-[10px] font-bold bg-indigo-100 text-indigo-600 px-1.5 py-0.5 rounded-full shrink-0"><?php echo count($allProfiles); ?></span>
            </div>
            <?php
            $editingP = array_values(array_filter($allProfiles, fn($p) => $p['id'] == $editingProfileId));
            $epLabel  = $editingP ? ($editingP[0]['profile_label'] ?: $editingP[0]['name']) : '—';
            ?>
            <div class="mx-3 mb-2 px-3 py-2 rounded-xl bg-indigo-50 border border-indigo-100">
                <div class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold mb-0.5">Sedang diedit</div>
                <div class="text-xs font-bold text-indigo-700 truncate"><?php echo htmlspecialchars($epLabel); ?></div>
            </div>

            <div class="sidebar-header">System</div>
            <div onclick="switchTab('cv')" class="sidebar-item" id="nav-cv"><i class="fas fa-file-alt w-5"></i> CV Generator</div>
            <div onclick="switchTab('settings')" class="sidebar-item" id="nav-settings"><i class="fas fa-cog w-5"></i> Settings</div>
            <div onclick="switchTab('trash')" class="sidebar-item" id="nav-trash">
                <i class="fas fa-trash-restore w-5"></i> Sampah
                <?php if($trashCount > 0): ?><span class="ml-auto text-[10px] font-bold bg-slate-200 text-slate-600 px-1.5 py-0.5 rounded-full"><?php echo $trashCount; ?></span><?php endif; ?>
            </div>
        </nav>
        <div class="p-4 border-t bg-slate-50">
            <div onclick="switchTab('messages')" class="sidebar-item hover:bg-white border border-transparent hover:border-slate-200 mb-2" id="nav-messages">
                <i class="fas fa-envelope w-5 text-indigo-500"></i> <?php echo t('admin.nav.messages', [], $lang); ?>
            </div>
            <a href="<?php echo htmlspecialchars(localizedUrl('logout.php', $lang)); ?>" class="flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold text-red-600 bg-red-50 rounded-xl hover:bg-red-100 border border-red-100 transition">
                <i class="fas fa-sign-out-alt"></i> <?php echo t('admin.logout', [], $lang); ?>
            </a>
        </div>
    </aside>

    <!-- MOBILE HEADER -->
    <div class="lg:hidden fixed top-0 left-0 right-0 h-16 bg-white border-b border-slate-200 z-20 flex items-center justify-between px-4 shadow-sm">
        <div class="font-bold text-lg text-slate-800 flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg bg-indigo-600 text-white flex items-center justify-center"><i class="fas fa-bolt"></i></div>
            <?php echo t('admin.panel', [], $lang); ?>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex items-center rounded-full border border-slate-200 p-1 bg-slate-50">
                <a href="<?php echo htmlspecialchars(currentUrlWithLang('id')); ?>" class="px-2.5 py-1 rounded-full text-[10px] font-bold <?php echo $lang === 'id' ? 'bg-slate-900 text-white' : 'text-slate-500'; ?>">ID</a>
                <a href="<?php echo htmlspecialchars(currentUrlWithLang('en')); ?>" class="px-2.5 py-1 rounded-full text-[10px] font-bold <?php echo $lang === 'en' ? 'bg-slate-900 text-white' : 'text-slate-500'; ?>">EN</a>
            </div>
            <button onclick="document.getElementById('mobile-menu').classList.toggle('hidden')" class="p-2 rounded-lg bg-slate-50 text-slate-600 border border-slate-200"><i class="fas fa-bars"></i></button>
        </div>
    </div>
    <!-- MOBILE MENU DROPDOWN -->
    <div id="mobile-menu" class="hidden fixed inset-0 z-30 bg-black/50" onclick="this.classList.add('hidden')">
        <div class="bg-white w-64 h-full p-4 flex flex-col gap-2 overflow-y-auto" onclick="event.stopPropagation()">
            <div class="sidebar-header mt-0"><?php echo t('admin.mobile_menu', [], $lang); ?></div>
            <button onclick="switchTab('profile'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-slate-700 hover:bg-slate-50"><?php echo t('admin.nav.profile', [], $lang); ?></button>
            <button onclick="switchTab('dashboard'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-slate-700 hover:bg-slate-50"><?php echo t('admin.nav.dashboard', [], $lang); ?></button>
            <button onclick="switchTab('projects'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-slate-700 hover:bg-slate-50"><?php echo t('admin.nav.projects', [], $lang); ?></button>
            <button onclick="switchTab('metrics'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-slate-700 hover:bg-slate-50"><?php echo t('admin.nav.metrics', [], $lang); ?></button>
            <button onclick="switchTab('clients'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-slate-700 hover:bg-slate-50"><?php echo t('admin.nav.clients', [], $lang); ?></button>
            <button onclick="switchTab('skills'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-slate-700 hover:bg-slate-50"><?php echo t('admin.nav.skills', [], $lang); ?></button>
            <button onclick="switchTab('experience'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-slate-700 hover:bg-slate-50"><?php echo t('admin.nav.experience', [], $lang); ?></button>
            <button onclick="switchTab('blog'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-slate-700 hover:bg-slate-50"><?php echo t('admin.nav.blog', [], $lang); ?></button>
            <button onclick="switchTab('messages'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-indigo-600 bg-indigo-50"><?php echo t('admin.nav.messages', [], $lang); ?></button>
            <button onclick="switchTab('cv'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-slate-700 hover:bg-slate-50">CV Generator</button>
            <button onclick="switchTab('settings'); document.getElementById('mobile-menu').classList.add('hidden')" class="text-left p-3 rounded-lg font-bold text-slate-700 hover:bg-slate-50">Settings</button>
            <a href="<?php echo htmlspecialchars(localizedUrl('logout.php', $lang)); ?>" class="mt-auto p-3 text-red-600 font-bold border-t text-center"><?php echo t('admin.logout', [], $lang); ?></a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <main class="flex-1 overflow-y-auto pt-16 lg:pt-0 bg-[#F8FAFC]">
        <div class="max-w-7xl mx-auto p-4 lg:p-8 pb-32">
            
            <!-- 1. PROFILE TAB -->
            <section id="tab-profile" class="tab-content active">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                    <h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.profile_heading', [], $lang); ?></h2>
                    <div class="flex gap-2 items-center">
                        <?php if(count($allProfiles) > 1): ?><button type="button" onclick="seedData('profile','Info Profil')" class="btn-seed"><i class="fas fa-copy"></i> <span class="hidden sm:inline">Salin dari profil</span></button><?php endif; ?>
                        <button type="button" onclick="submitForm('form-profile')" class="hidden md:inline-flex btn-modern"><i class="fas fa-save"></i> <?php echo t('admin.save_changes', [], $lang); ?></button>
                    </div>
                </div>
                <form id="form-profile" class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="is_ajax" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="grid lg:grid-cols-2 gap-8">
                        <div class="space-y-6">
                            <h3 class="font-bold text-slate-400 uppercase text-xs tracking-wider border-b pb-2">Identitas Utama</h3>
                            <div><label class="block text-xs font-bold mb-1">Judul Website (ID)</label><input name="site_title" class="input-modern" value="<?php echo htmlspecialchars($payload['profile']['site_title']); ?>"></div>
                            <div><label class="block text-xs font-bold mb-1">Website Title (EN)</label><input name="site_title_en" class="input-modern" value="<?php echo htmlspecialchars($payload['profile']['site_title_en'] ?? ''); ?>"></div>
                            <div><label class="block text-xs font-bold mb-1">Nama Lengkap</label><input name="name" class="input-modern" value="<?php echo htmlspecialchars($payload['profile']['name']); ?>"></div>
                            <div><label class="block text-xs font-bold mb-1">Hero Role (ID)</label><input name="hero_role" class="input-modern" value="<?php echo htmlspecialchars($payload['profile']['hero_role']); ?>"></div>
                            <div><label class="block text-xs font-bold mb-1">Hero Role (EN)</label><input name="hero_role_en" class="input-modern" value="<?php echo htmlspecialchars($payload['profile']['hero_role_en'] ?? ''); ?>"></div>
                            <div><label class="block text-xs font-bold mb-1">Bio Singkat (ID)</label><textarea name="bio" class="input-modern h-28 leading-relaxed"><?php echo htmlspecialchars($payload['profile']['bio']); ?></textarea></div>
                            <div><label class="block text-xs font-bold mb-1">Short Bio (EN)</label><textarea name="bio_en" class="input-modern h-28 leading-relaxed"><?php echo htmlspecialchars($payload['profile']['bio_en'] ?? ''); ?></textarea></div>
                            
                            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 grid gap-4">
                                <div class="flex items-center gap-3">
                                    <input type="checkbox" name="is_available" value="1" class="w-5 h-5 text-indigo-600 rounded" <?php echo $payload['profile']['is_available'] ? 'checked' : ''; ?>>
                                    <span class="text-sm font-bold text-slate-700">Tampilkan "Available"</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <input type="checkbox" name="show_metrics" value="1" class="w-5 h-5 text-indigo-600 rounded" <?php echo !empty($payload["profile"]["show_metrics"]) ? "checked" : ""; ?>>
                                    <span class="text-sm font-bold text-slate-700">Tampilkan Section Metrics</span>
                                </div>
                                <div><label class="block text-xs font-bold mb-1">Teks Status (ID)</label><input name="availability_text" class="input-modern" value="<?php echo htmlspecialchars($payload['profile']['availability_text']); ?>"></div>
                                <div><label class="block text-xs font-bold mb-1">Availability Text (EN)</label><input name="availability_text_en" class="input-modern" value="<?php echo htmlspecialchars($payload['profile']['availability_text_en'] ?? ''); ?>"></div>
                                <div><label class="block text-xs font-bold mb-1">Limit Project Home</label><input type="number" name="projects_limit" class="input-modern" value="<?php echo htmlspecialchars($payload['profile']['projects_limit']); ?>"></div>
                            </div>

                            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 grid gap-4">
                                <div><label class="block text-xs font-bold mb-1">Deskripsi Portofolio (ID)</label><textarea name="projects_desc" class="input-modern h-24 leading-relaxed"><?php echo htmlspecialchars($payload['profile']['projects_desc'] ?? ''); ?></textarea></div>
                                <div><label class="block text-xs font-bold mb-1">Portfolio Description (EN)</label><textarea name="projects_desc_en" class="input-modern h-24 leading-relaxed"><?php echo htmlspecialchars($payload['profile']['projects_desc_en'] ?? ''); ?></textarea></div>
                                <div><label class="block text-xs font-bold mb-1">Deskripsi Kontak (ID)</label><textarea name="contact_desc" class="input-modern h-24 leading-relaxed"><?php echo htmlspecialchars($payload['profile']['contact_desc'] ?? ''); ?></textarea></div>
                                <div><label class="block text-xs font-bold mb-1">Contact Description (EN)</label><textarea name="contact_desc_en" class="input-modern h-24 leading-relaxed"><?php echo htmlspecialchars($payload['profile']['contact_desc_en'] ?? ''); ?></textarea></div>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <h3 class="font-bold text-slate-400 uppercase text-xs tracking-wider border-b pb-2">Media & Kontak</h3>
                            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 space-y-4">
                                <div>
                                    <label class="block text-xs font-bold mb-1">Hero Image</label>
                                    <input type="file" name="hero_image_file" class="input-modern text-xs">
                                    <input type="hidden" name="old_hero_image" value="<?php echo $payload['profile']['hero_image_url']; ?>">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold mb-1">Foto Profil</label>
                                    <input type="file" name="profile_photo_file" class="input-modern text-xs">
                                    <input type="hidden" name="old_profile_photo" value="<?php echo $payload['profile']['profile_photo']; ?>">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold mb-1">CV (PDF)</label>
                                    <input type="file" name="cv_file" class="input-modern text-xs">
                                    <input type="hidden" name="old_cv_url" value="<?php echo $payload['profile']['cv_url']; ?>">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4">
                                <div><label class="block text-xs font-bold mb-1">Email</label><input name="email" class="input-modern" value="<?php echo htmlspecialchars($payload['profile']['email']); ?>"></div>
                                <div><label class="block text-xs font-bold mb-1">WhatsApp</label><input name="whatsapp" class="input-modern" value="<?php echo htmlspecialchars($payload['profile']['whatsapp']); ?>"></div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="text-[10px] font-bold">LinkedIn</label><input name="link_linkedin" class="input-modern text-xs" value="<?php echo $payload['profile']['link_linkedin']; ?>"></div>
                                <div><label class="text-[10px] font-bold">Instagram</label><input name="link_instagram" class="input-modern text-xs" value="<?php echo $payload['profile']['link_instagram']; ?>"></div>
                                <div><label class="text-[10px] font-bold">Github</label><input name="link_github" class="input-modern text-xs" value="<?php echo $payload['profile']['link_github']; ?>"></div>
                                <div><label class="text-[10px] font-bold">TikTok</label><input name="link_tiktok" class="input-modern text-xs" value="<?php echo $payload['profile']['link_tiktok']; ?>"></div>
                                <div class="col-span-2"><label class="text-[10px] font-bold">Blog URL</label><input name="link_blog_pribadi" class="input-modern text-xs" value="<?php echo $payload['profile']['link_blog_pribadi']; ?>"></div>
                                <div class="col-span-2 grid grid-cols-2 gap-3">
                                    <div><label class="text-[10px] font-bold">Facebook</label><input name="link_facebook" class="input-modern text-xs" value="<?php echo $payload['profile']['link_facebook']; ?>"></div>
                                    <div><label class="text-[10px] font-bold">Twitter</label><input name="link_twitter" class="input-modern text-xs" value="<?php echo $payload['profile']['link_twitter']; ?>"></div>
                                    <div><label class="text-[10px] font-bold">Threads</label><input name="link_threads" class="input-modern text-xs" value="<?php echo $payload['profile']['link_threads']; ?>"></div>
                                    <div><label class="text-[10px] font-bold">YouTube</label><input name="link_youtube" class="input-modern text-xs" value="<?php echo $payload['profile']['link_youtube']; ?>"></div>
                                </div>
                            </div>

                            <h3 class="font-bold text-slate-400 uppercase text-xs tracking-wider border-b pb-2 pt-4">SEO & Scripts</h3>
                            <div><label class="block text-xs font-bold mb-1">SEO Keywords (ID)</label><textarea name="seo_keywords" class="input-modern h-20 text-xs"><?php echo htmlspecialchars($payload['profile']['seo_keywords']); ?></textarea></div>
                            <div><label class="block text-xs font-bold mb-1">SEO Keywords (EN)</label><textarea name="seo_keywords_en" class="input-modern h-20 text-xs"><?php echo htmlspecialchars($payload['profile']['seo_keywords_en'] ?? ''); ?></textarea></div>
                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="block text-[10px] font-bold mb-1">Google Script</label><textarea name="script_google" class="input-code h-16 text-xs"><?php echo $payload['profile']['script_google']; ?></textarea></div>
                                <div><label class="block text-[10px] font-bold mb-1">FB Pixel</label><textarea name="script_fb" class="input-code h-16 text-xs"><?php echo $payload['profile']['script_fb']; ?></textarea></div>
                                <div class="col-span-2"><label class="block text-[10px] font-bold mb-1">Other Scripts</label><textarea name="script_other" class="input-code h-16 text-xs"><?php echo $payload['profile']['script_other']; ?></textarea></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-8 pt-6 border-t md:hidden">
                        <button type="button" onclick="submitForm('form-profile')" class="btn-modern w-full shadow-lg"><?php echo t('admin.save_changes', [], $lang); ?></button>
                    </div>
                </form>
            </section>

            <!-- 2. DASHBOARD TAB -->
            <section id="tab-dashboard" class="tab-content">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.nav.dashboard', [], $lang); ?></h2>
                    <span class="text-xs text-slate-400 font-medium"><?php echo date('d M Y, H:i'); ?></span>
                </div>

                <!-- Content Summary -->
                <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3"><?php echo $lang==='id'?'Ringkasan Konten':'Content Summary'; ?></p>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-8">
                    <?php
                    $contentItems = [
                        ['icon'=>'fa-laptop-code','color'=>'text-indigo-500','bg'=>'bg-indigo-50','label'=>$lang==='id'?'Proyek':'Projects','val'=>$contentCounts['projects'],'tab'=>'projects'],
                        ['icon'=>'fa-tools',      'color'=>'text-violet-500','bg'=>'bg-violet-50','label'=>'Skills',                                  'val'=>$contentCounts['skills'],  'tab'=>'skills'],
                        ['icon'=>'fa-newspaper',  'color'=>'text-sky-500',   'bg'=>'bg-sky-50',   'label'=>$lang==='id'?'Artikel':'Articles',         'val'=>$contentCounts['articles'],'tab'=>'articles'],
                        ['icon'=>'fa-building',   'color'=>'text-amber-500', 'bg'=>'bg-amber-50', 'label'=>'Clients',                                 'val'=>$contentCounts['clients'], 'tab'=>'clients'],
                        ['icon'=>'fa-envelope',   'color'=>'text-rose-500',  'bg'=>'bg-rose-50',  'label'=>$lang==='id'?'Pesan Masuk':'Messages',     'val'=>$contentCounts['messages'],'tab'=>'messages'],
                    ];
                    foreach($contentItems as $ci): ?>
                    <div onclick="switchTab('<?php echo $ci['tab']; ?>')" class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-3 cursor-pointer hover:shadow-md hover:border-slate-300 transition group">
                        <div class="<?php echo $ci['bg']; ?> w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0">
                            <i class="fas <?php echo $ci['icon']; ?> <?php echo $ci['color']; ?> text-base"></i>
                        </div>
                        <div>
                            <div class="text-xl font-bold text-slate-800 leading-none"><?php echo $ci['val']; ?></div>
                            <div class="text-[10px] text-slate-500 font-semibold mt-0.5"><?php echo $ci['label']; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Analytics -->
                <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Analytics</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                    <!-- Unique Visitors -->
                    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                        <div class="flex items-center gap-2 mb-4">
                            <div class="w-8 h-8 bg-indigo-50 rounded-lg flex items-center justify-center"><i class="fas fa-users text-indigo-500 text-sm"></i></div>
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wide"><?php echo $lang==='id'?'Visitor Unik':'Unique Visitors'; ?></span>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div><div class="text-lg font-bold text-slate-800"><?php echo number_format($analyticsToday['unique_today']??0); ?></div><div class="text-[10px] text-slate-400 font-semibold mt-0.5"><?php echo $lang==='id'?'Hari Ini':'Today'; ?></div></div>
                            <div class="border-x border-slate-100"><div class="text-lg font-bold text-slate-800"><?php echo number_format($analyticsMonth['unique_month']??0); ?></div><div class="text-[10px] text-slate-400 font-semibold mt-0.5"><?php echo $lang==='id'?'Bulan Ini':'This Month'; ?></div></div>
                            <div><div class="text-lg font-bold text-slate-800"><?php echo number_format($analyticsSummary['unique_visitors']??0); ?></div><div class="text-[10px] text-slate-400 font-semibold mt-0.5"><?php echo $lang==='id'?'Total':'All Time'; ?></div></div>
                        </div>
                    </div>
                    <!-- Page Views -->
                    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                        <div class="flex items-center gap-2 mb-4">
                            <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center"><i class="fas fa-eye text-blue-500 text-sm"></i></div>
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wide"><?php echo $lang==='id'?'Page View':'Page Views'; ?></span>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div><div class="text-lg font-bold text-slate-800"><?php echo number_format($analyticsToday['views_today']??0); ?></div><div class="text-[10px] text-slate-400 font-semibold mt-0.5"><?php echo $lang==='id'?'Hari Ini':'Today'; ?></div></div>
                            <div class="border-x border-slate-100"><div class="text-lg font-bold text-slate-800"><?php echo number_format($analyticsMonth['views_month']??0); ?></div><div class="text-[10px] text-slate-400 font-semibold mt-0.5"><?php echo $lang==='id'?'Bulan Ini':'This Month'; ?></div></div>
                            <div><div class="text-lg font-bold text-slate-800"><?php echo number_format($analyticsSummary['impressions']??0); ?></div><div class="text-[10px] text-slate-400 font-semibold mt-0.5"><?php echo $lang==='id'?'Total':'All Time'; ?></div></div>
                        </div>
                    </div>
                    <!-- Button Clicks + CTR -->
                    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                        <div class="flex items-center gap-2 mb-4">
                            <div class="w-8 h-8 bg-green-50 rounded-lg flex items-center justify-center"><i class="fas fa-mouse-pointer text-green-500 text-sm"></i></div>
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wide"><?php echo $lang==='id'?'Klik & CTR':'Clicks & CTR'; ?></span>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div><div class="text-lg font-bold text-slate-800"><?php echo number_format($analyticsToday['clicks_today']??0); ?></div><div class="text-[10px] text-slate-400 font-semibold mt-0.5"><?php echo $lang==='id'?'Hari Ini':'Today'; ?></div></div>
                            <div class="border-x border-slate-100"><div class="text-lg font-bold text-slate-800"><?php echo number_format($analyticsMonth['clicks_month']??0); ?></div><div class="text-[10px] text-slate-400 font-semibold mt-0.5"><?php echo $lang==='id'?'Bulan Ini':'This Month'; ?></div></div>
                            <div><div class="text-lg font-bold text-emerald-600"><?php echo ctr((int)($analyticsSummary['button_clicks']??0), (int)($analyticsSummary['impressions']??0)); ?></div><div class="text-[10px] text-slate-400 font-semibold mt-0.5">CTR</div></div>
                        </div>
                    </div>
                </div>

                <!-- Session Duration -->
                <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm mb-8">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="w-8 h-8 bg-orange-50 rounded-lg flex items-center justify-center"><i class="fas fa-clock text-orange-500 text-sm"></i></div>
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wide"><?php echo $lang==='id'?'Rata-rata Durasi Per Sesi':'Avg Session Duration'; ?></span>
                        <span class="ml-auto text-[10px] text-slate-400"><?php echo number_format((int)($durAll['sessions']??0)); ?> <?php echo $lang==='id'?'sesi tercatat':'sessions recorded'; ?></span>
                    </div>
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <div class="text-2xl font-bold text-orange-500"><?php echo fmtDur((int)($durToday['avg_sec']??0)); ?></div>
                            <div class="text-[10px] text-slate-400 font-semibold mt-1"><?php echo $lang==='id'?'Hari Ini':'Today'; ?></div>
                        </div>
                        <div class="border-x border-slate-100">
                            <div class="text-2xl font-bold text-orange-400"><?php echo fmtDur((int)($durMonth['avg_sec']??0)); ?></div>
                            <div class="text-[10px] text-slate-400 font-semibold mt-1"><?php echo $lang==='id'?'Bulan Ini':'This Month'; ?></div>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-slate-700"><?php echo fmtDur((int)($durAll['avg_sec']??0)); ?></div>
                            <div class="text-[10px] text-slate-400 font-semibold mt-1"><?php echo $lang==='id'?'Semua Waktu':'All Time'; ?></div>
                        </div>
                    </div>
                    <?php if (($durAll['sessions']??0) == 0): ?>
                    <p class="text-center text-xs text-slate-400 mt-3 italic"><?php echo $lang==='id'?'Data durasi mulai terekam setelah pengunjung mengunjungi halaman ini.':'Duration data will appear after visitors browse the page.'; ?></p>
                    <?php endif; ?>
                </div>

                <!-- Top Clicks + Recent Activity -->
                <?php if (!empty($analyticsTop) || !empty($analyticsRecent)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php if (!empty($analyticsTop)): ?>
                    <div>
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3"><?php echo $lang==='id'?'Top Klik Tombol':'Top Button Clicks'; ?></p>
                        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                            <table class="w-full text-left">
                                <thead class="bg-slate-50 text-[10px] uppercase text-slate-500 font-bold border-b border-slate-200">
                                    <tr><th class="px-4 py-3">Event</th><th class="px-4 py-3 text-right">Klik</th></tr>
                                </thead>
                                <tbody class="text-sm divide-y divide-slate-100">
                                    <?php foreach($analyticsTop as $i => $top): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2.5 flex items-center gap-2">
                                            <span class="text-[10px] font-bold text-slate-400 w-4"><?php echo $i+1; ?></span>
                                            <span class="font-medium text-slate-700 text-xs truncate max-w-[180px]"><?php echo htmlspecialchars($top['event_key']); ?></span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-bold text-slate-800"><?php echo number_format($top['total']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($analyticsRecent)): ?>
                    <div>
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3"><?php echo $lang==='id'?'Aktivitas Terbaru':'Recent Activity'; ?></p>
                        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                            <table class="w-full text-left">
                                <thead class="bg-slate-50 text-[10px] uppercase text-slate-500 font-bold border-b border-slate-200">
                                    <tr><th class="px-4 py-3">Waktu</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3">Key</th></tr>
                                </thead>
                                <tbody class="text-xs divide-y divide-slate-100">
                                    <?php foreach($analyticsRecent as $recent): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2 whitespace-nowrap text-slate-500"><?php echo date('d/m H:i', strtotime($recent['created_at'])); ?></td>
                                        <td class="px-4 py-2"><span class="px-1.5 py-0.5 font-bold rounded text-[10px] <?php echo $recent['event_type']==='button_click'?'bg-green-100 text-green-700':'bg-blue-100 text-blue-700'; ?>"><?php echo $recent['event_type']==='button_click'?'click':'view'; ?></span></td>
                                        <td class="px-4 py-2 font-medium text-slate-700 truncate max-w-[120px]"><?php echo htmlspecialchars($recent['event_key']??'—'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>

            <!-- 3. PROJECTS TAB -->
            <section id="tab-projects" class="tab-content">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.projects_heading', [], $lang); ?></h2>
                    <div class="flex gap-2"><?php if(count($allProfiles) > 1): ?><button onclick="seedData('projects','Proyek')" class="btn-seed"><i class="fas fa-copy"></i> <span class="hidden sm:inline">Salin dari profil</span></button><?php endif; ?><button onclick="openModal('project')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add_project', [], $lang); ?></span></button></div>
                </div>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach($payload['projects'] as $p): ?>
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 flex flex-col relative group h-full">
                        <?php if($p['image_url']): ?>
                            <img src="<?php echo $p['image_url']; ?>" class="w-full h-40 object-cover rounded-xl mb-4 bg-slate-100 border border-slate-100">
                        <?php else: ?>
                            <div class="w-full h-40 bg-slate-100 rounded-xl mb-4 flex items-center justify-center text-slate-300"><i class="fas fa-image text-3xl"></i></div>
                        <?php endif; ?>
                        <span class="absolute top-7 right-7 bg-white/90 text-xs font-bold px-2 py-1 rounded text-slate-700 shadow-sm"><?php echo htmlspecialchars(translateSkillCategory(localizedField($p, 'category', $lang), $lang)); ?></span>
                        <h3 class="font-bold text-lg mb-1 text-slate-800 line-clamp-1"><?php echo htmlspecialchars(localizedField($p, 'title', $lang)); ?></h3>
                        <p class="text-xs text-slate-500 mb-4 flex-1 line-clamp-2"><?php echo htmlspecialchars(localizedField($p, 'description', $lang)); ?></p>
                        <div class="mt-auto pt-4 border-t flex justify-between items-center">
                            <span class="text-xs font-bold text-indigo-600 truncate max-w-[120px]"><?php echo $p['client_name']; ?></span>
                            <div class="flex gap-2">
                                <button onclick="openModal('project', <?php echo $p['id']; ?>)" class="btn-action btn-edit"><i class="fas fa-pen text-xs"></i></button>
                                <button onclick="deleteItem(<?php echo $p['id']; ?>, 'projects')" class="btn-action btn-delete"><i class="fas fa-trash text-xs"></i></button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 4. METRICS TAB -->
            <section id="tab-metrics" class="tab-content">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.metrics_heading', [], $lang); ?></h2>
                    <div class="flex gap-2"><?php if(count($allProfiles) > 1): ?><button onclick="seedData('metrics','Metrik')" class="btn-seed"><i class="fas fa-copy"></i> <span class="hidden sm:inline">Salin dari profil</span></button><?php endif; ?><button onclick="openModal('metric')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button></div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <?php foreach($payload['metrics'] as $m): ?>
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm text-center relative">
                        <i class="<?php echo $m['icon']; ?> text-3xl text-indigo-500 mb-2 block"></i>
                        <div class="text-xl font-bold"><?php echo htmlspecialchars(localizedField($m, 'metric_value', $lang)); ?></div>
                        <div class="text-[10px] uppercase text-slate-400 font-bold"><?php echo htmlspecialchars(localizedField($m, 'metric_name', $lang)); ?></div>
                        <div class="mt-4 flex justify-center gap-2">
                            <button onclick="openModal('metric', <?php echo $m['id']; ?>)" class="btn-action btn-edit w-8 h-8"><i class="fas fa-pen text-[10px]"></i></button>
                            <button onclick="deleteItem(<?php echo $m['id']; ?>, 'impact_metrics')" class="btn-action btn-delete w-8 h-8"><i class="fas fa-trash text-[10px]"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 5. CLIENTS TAB -->
            <section id="tab-clients" class="tab-content">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.clients_heading', [], $lang); ?></h2>
                    <div class="flex gap-2"><?php if(count($allProfiles) > 1): ?><button onclick="seedData('clients','Klien')" class="btn-seed"><i class="fas fa-copy"></i> <span class="hidden sm:inline">Salin dari profil</span></button><?php endif; ?><button onclick="openModal('client')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button></div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <?php foreach($payload['clients'] as $c): ?>
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col items-center justify-center h-32 relative">
                        <img src="<?php echo $c['logo_url']; ?>" class="max-h-12 max-w-full opacity-80 object-contain">
                        <div class="flex gap-2 mt-3">
                            <button onclick="openModal('client', <?php echo $c['id']; ?>)" class="text-blue-500 text-xs"><i class="fas fa-pen"></i></button>
                            <button onclick="deleteItem(<?php echo $c['id']; ?>, 'client_logos')" class="text-red-500 text-xs"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 6. CHAT TAB -->
            <section id="tab-chat" class="tab-content">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.chat_heading', [], $lang); ?></h2>
                    <div class="flex gap-2"><?php if(count($allProfiles) > 1): ?><button onclick="seedData('chat','Chat Bubble')" class="btn-seed"><i class="fas fa-copy"></i> <span class="hidden sm:inline">Salin dari profil</span></button><?php endif; ?><button onclick="openModal('chat')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button></div>
                </div>
                <div class="space-y-4 max-w-3xl">
                    <?php foreach($payload['chats'] as $chat): ?>
                    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex flex-col gap-2">
                        <div class="flex justify-between">
                            <span class="bg-indigo-100 text-indigo-600 font-bold h-6 w-6 flex items-center justify-center rounded-full text-xs"><?php echo $chat['display_order']; ?></span>
                            <div class="flex gap-2">
                                <button onclick="openModal('chat', <?php echo $chat['id']; ?>)" class="text-blue-500"><i class="fas fa-pen"></i></button>
                                <button onclick="deleteItem(<?php echo $chat['id']; ?>, 'hero_chat')" class="text-red-500"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <div class="font-bold text-slate-800 text-sm"><?php echo htmlspecialchars(localizedField($chat, 'question', $lang)); ?></div>
                        <div class="bg-slate-50 p-3 rounded-lg text-sm text-slate-600"><?php echo htmlspecialchars(localizedField($chat, 'answer', $lang)); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 7. SKILLS TAB -->
            <section id="tab-skills" class="tab-content">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.skills_heading', [], $lang); ?></h2>
                    <div class="flex gap-2"><?php if(count($allProfiles) > 1): ?><button onclick="seedData('skills','Skill')" class="btn-seed"><i class="fas fa-copy"></i> <span class="hidden sm:inline">Salin dari profil</span></button><?php endif; ?><button onclick="openModal('skill')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php foreach($payload['skills'] as $s): ?>
                    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between border-l-4 <?php echo $s['category']=='Technical'?'border-l-blue-500':'border-l-green-500'; ?>">
                        <div class="flex items-center gap-3">
                            <?php if(!empty($s['icon_url'])): ?>
                                <img src="<?php echo htmlspecialchars($s['icon_url']); ?>" class="w-8 h-8 object-contain flex-shrink-0" width="32" height="32" loading="lazy" alt="">
                            <?php else: ?>
                                <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center flex-shrink-0"><span class="text-slate-500 font-bold text-sm"><?php echo strtoupper(substr($s['skill_name'],0,1)); ?></span></div>
                            <?php endif; ?>
                            <div>
                                <div class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars(localizedField($s, 'skill_name', $lang)); ?></div>
                                <div class="text-[10px] text-slate-400 uppercase font-bold"><?php echo htmlspecialchars(translateSkillCategory($s['category'], $lang)); ?></div>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="openModal('skill', <?php echo $s['id']; ?>)" class="text-slate-400"><i class="fas fa-pen"></i></button>
                            <button onclick="deleteItem(<?php echo $s['id']; ?>, 'skills')" class="text-red-400"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 8. EXPERIENCE TAB -->
            <section id="tab-experience" class="tab-content">
                <div class="flex justify-between items-center mb-6"><h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.experience_heading', [], $lang); ?></h2><div class="flex gap-2"><?php if(count($allProfiles) > 1): ?><button onclick="seedData('experience','Pengalaman')" class="btn-seed"><i class="fas fa-copy"></i> <span class="hidden sm:inline">Salin dari profil</span></button><?php endif; ?><button onclick="openModal('experience')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button></div></div>
                <div class="space-y-4">
                    <?php foreach($payload['experience'] as $x): ?>
                    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm relative">
                        <h3 class="font-bold text-lg text-slate-800 pr-10"><?php echo htmlspecialchars(localizedField($x, 'role', $lang)); ?></h3>
                        <div class="text-indigo-600 font-semibold text-sm mb-2"><?php echo htmlspecialchars($x['company']); ?> - <?php echo htmlspecialchars(localizedField($x, 'year_range', $lang)); ?></div>
                        <p class="text-sm text-slate-600 leading-relaxed"><?php echo nl2br(htmlspecialchars(localizedField($x, 'description', $lang))); ?></p>
                        <div class="absolute top-5 right-5 flex gap-2">
                            <button onclick="openModal('experience', <?php echo $x['id']; ?>)" class="text-blue-500"><i class="fas fa-pen"></i></button>
                            <button onclick="deleteItem(<?php echo $x['id']; ?>, 'experience')" class="text-red-500"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 9. EDUCATION TAB -->
            <section id="tab-education" class="tab-content">
                <div class="flex justify-between items-center mb-6"><h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.education_heading', [], $lang); ?></h2><div class="flex gap-2"><?php if(count($allProfiles) > 1): ?><button onclick="seedData('education','Pendidikan')" class="btn-seed"><i class="fas fa-copy"></i> <span class="hidden sm:inline">Salin dari profil</span></button><?php endif; ?><button onclick="openModal('education')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button></div></div>
                <div class="space-y-3">
                    <?php foreach($payload['education'] as $e): ?>
                    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex justify-between items-center">
                        <div><h3 class="font-bold text-slate-800"><?php echo htmlspecialchars($e['school_name']); ?></h3><p class="text-sm text-slate-600"><?php echo htmlspecialchars(localizedField($e, 'degree', $lang)); ?> (<?php echo htmlspecialchars(localizedField($e, 'year_range', $lang)); ?>)</p></div>
                        <div class="flex gap-2">
                            <button onclick="openModal('education', <?php echo $e['id']; ?>)" class="text-blue-500"><i class="fas fa-pen"></i></button>
                            <button onclick="deleteItem(<?php echo $e['id']; ?>, 'education')" class="text-red-500"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 10. BLOG TAB -->
            <section id="tab-blog" class="tab-content">
                <div class="flex justify-between items-center mb-6"><h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.blog_heading', [], $lang); ?></h2><div class="flex gap-2"><?php if(count($allProfiles) > 1): ?><button onclick="seedData('articles','Artikel')" class="btn-seed"><i class="fas fa-copy"></i> <span class="hidden sm:inline">Salin dari profil</span></button><?php endif; ?><button onclick="openModal('article')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button></div></div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach($payload['articles'] as $b): ?>
                    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex gap-4">
                        <?php if($b['image_url']): ?><img src="<?php echo $b['image_url']; ?>" class="w-20 h-20 object-cover rounded-lg shrink-0"><?php endif; ?>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-sm line-clamp-2 mb-1"><?php echo htmlspecialchars(localizedField($b, 'title', $lang)); ?></h3>
                            <p class="text-[10px] text-slate-400 mb-2"><?php echo htmlspecialchars(formatLocalizedDate($b['created_at'], $lang)); ?></p>
                            <div class="flex gap-3">
                                <button onclick="openModal('article', <?php echo $b['id']; ?>)" class="text-xs font-bold text-blue-600"><?php echo t('admin.edit', [], $lang); ?></button>
                                <button onclick="deleteItem(<?php echo $b['id']; ?>, 'articles')" class="text-xs font-bold text-red-600"><?php echo t('admin.delete', [], $lang); ?></button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- PROFILES MANAGEMENT TAB -->
            <section id="tab-profiles" class="tab-content">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-800">Kelola Profil / Persona</h2>
                        <p class="text-sm text-slate-400 mt-1">Buat, edit, dan aktifkan profil untuk publik</p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="openCreateProfile()" class="btn-modern bg-slate-700 hover:bg-slate-800" style="background:linear-gradient(135deg,#475569,#334155)"><i class="fas fa-plus"></i> <span class="hidden sm:inline">Manual</span></button>
                        <button onclick="openAiProfileGenerator()" class="btn-modern" style="background:linear-gradient(135deg,#7C3AED,#4F46E5)"><i class="fas fa-magic"></i> <span class="hidden sm:inline">Buat Profil AI</span></button>
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <?php foreach ($allProfiles as $p):
                        $isEditing = ($p['id'] == $editingProfileId);
                        $isActive  = (bool)$p['is_active'];
                        $pLabel    = htmlspecialchars($p['profile_label'] ?: $p['name']);
                        $pName     = htmlspecialchars($p['name']);
                    ?>
                    <div class="bg-white rounded-2xl border <?php echo $isEditing ? 'border-indigo-300 shadow-indigo-100 shadow-md' : 'border-slate-200 shadow-sm'; ?> p-5 flex flex-col gap-4 relative">
                        <!-- Badges -->
                        <div class="flex items-start justify-between">
                            <div class="flex flex-wrap gap-1.5">
                                <?php if($isActive): ?>
                                <span class="inline-flex items-center gap-1 text-[11px] font-bold text-green-700 bg-green-50 border border-green-200 px-2 py-0.5 rounded-full"><span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>Aktif Publik</span>
                                <?php endif; ?>
                                <?php if($isEditing): ?>
                                <span class="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded-full"><i class="fas fa-pen text-[9px]"></i>Sedang Diedit</span>
                                <?php endif; ?>
                            </div>
                            <!-- Delete button (not for active profile) -->
                            <?php if (!$isActive): ?>
                            <button onclick="deleteProfile(<?php echo $p['id']; ?>, '<?php echo addslashes($pLabel); ?>')" class="w-8 h-8 flex items-center justify-center rounded-xl text-slate-300 hover:text-red-500 hover:bg-red-50 transition shrink-0" title="Hapus profil">
                                <i class="fas fa-trash text-xs"></i>
                            </button>
                            <?php else: ?>
                            <div class="w-8 h-8"></div>
                            <?php endif; ?>
                        </div>

                        <!-- Label & Name (editable inline) -->
                        <div>
                            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold mb-1">Label Profil</div>
                            <div class="flex items-center gap-2">
                                <span id="label-text-<?php echo $p['id']; ?>" class="font-bold text-slate-800 text-base flex-1 truncate"><?php echo $pLabel; ?></span>
                                <button onclick="editProfileLabel(<?php echo $p['id']; ?>)" class="shrink-0 text-slate-300 hover:text-indigo-500 transition" title="Ubah nama"><i class="fas fa-pen text-xs"></i></button>
                            </div>
                            <div class="text-xs text-slate-400 mt-0.5"><?php echo $pName; ?></div>
                        </div>

                        <!-- Action buttons -->
                        <div class="flex flex-col gap-2 mt-auto pt-2 border-t border-slate-100">
                            <?php if (!$isEditing): ?>
                            <button onclick="switchProfile(<?php echo $p['id']; ?>)" class="w-full py-2 text-sm font-bold text-indigo-600 bg-indigo-50 rounded-xl hover:bg-indigo-100 transition border border-indigo-200 flex items-center justify-center gap-2">
                                <i class="fas fa-edit text-xs"></i> Edit Konten
                            </button>
                            <?php else: ?>
                            <div class="w-full py-2 text-sm font-bold text-slate-400 bg-slate-50 rounded-xl text-center border border-slate-200">
                                <i class="fas fa-check-circle text-xs text-indigo-400 mr-1"></i> Konten sedang diedit
                            </div>
                            <?php endif; ?>
                            <?php if (!$isActive): ?>
                            <button onclick="activateProfile(<?php echo $p['id']; ?>)" class="w-full py-2 text-sm font-bold text-green-600 bg-green-50 rounded-xl hover:bg-green-100 transition border border-green-200 flex items-center justify-center gap-2">
                                <i class="fas fa-globe text-xs"></i> Publish ke Publik
                            </button>
                            <?php else: ?>
                            <div class="w-full py-2 text-sm font-bold text-green-700 bg-green-50 rounded-xl text-center border border-green-200">
                                <i class="fas fa-globe text-xs mr-1"></i> Tampil di website
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Add manual card -->
                    <button onclick="openCreateProfile()" class="bg-white rounded-2xl border-2 border-dashed border-slate-200 hover:border-slate-400 hover:bg-slate-50 transition p-5 flex flex-col items-center justify-center gap-3 text-slate-400 hover:text-slate-600 min-h-[160px]">
                        <div class="w-10 h-10 rounded-xl border-2 border-dashed border-current flex items-center justify-center">
                            <i class="fas fa-plus"></i>
                        </div>
                        <span class="text-sm font-bold">Buat Manual</span>
                    </button>

                    <!-- Add AI card -->
                    <button onclick="openAiProfileGenerator()" class="rounded-2xl border-2 border-dashed border-violet-200 hover:border-violet-400 bg-violet-50/40 hover:bg-violet-50 transition p-5 flex flex-col items-center justify-center gap-3 text-violet-400 hover:text-violet-600 min-h-[160px]">
                        <div class="w-10 h-10 rounded-xl border-2 border-dashed border-current flex items-center justify-center">
                            <i class="fas fa-magic"></i>
                        </div>
                        <span class="text-sm font-bold">Buat Profil AI</span>
                        <span class="text-[11px] text-violet-400 text-center leading-snug">Generate semua section<br>dari Job Description</span>
                    </button>
                </div>
            </section>

            <!-- TRASH TAB -->
            <section id="tab-trash" class="tab-content">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-slate-800">Sampah <span class="text-base font-normal text-slate-400">(30 hari terakhir)</span></h2>
                </div>
                <?php if(empty($recentTrash)): ?>
                <div class="text-center py-20 text-slate-400"><i class="fas fa-trash-restore text-5xl mb-4 block opacity-30"></i><p>Tidak ada data yang dihapus.</p></div>
                <?php else: ?>
                <div class="space-y-3 max-w-3xl">
                    <?php
                    $tableLabels = ['projects'=>'Proyek','skills'=>'Skill','experience'=>'Pengalaman','education'=>'Pendidikan','articles'=>'Artikel','impact_metrics'=>'Metrik','client_logos'=>'Klien','hero_chat'=>'Chat Bubble','messages'=>'Pesan'];
                    foreach($recentTrash as $t):
                        $rd = $t['row_parsed'];
                        $label = $tableLabels[$t['table_name']] ?? $t['table_name'];
                        $title = $rd['title'] ?? $rd['name'] ?? $rd['role'] ?? $rd['school_name'] ?? $rd['question'] ?? $rd['metric_name'] ?? $rd['client_name'] ?? $rd['skill_name'] ?? ('ID #' . ($rd['id'] ?? '?'));
                    ?>
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center shrink-0 text-slate-400">
                            <i class="fas fa-trash text-sm"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-bold text-slate-800 truncate text-sm"><?php echo htmlspecialchars($title); ?></div>
                            <div class="text-[11px] text-slate-400 mt-0.5">
                                <span class="bg-slate-100 text-slate-600 font-bold px-2 py-0.5 rounded-full mr-1"><?php echo $label; ?></span>
                                <?php echo date('d M Y H:i', strtotime($t['deleted_at'])); ?>
                            </div>
                        </div>
                        <button onclick="restoreItem(<?php echo $t['id']; ?>, this)" class="shrink-0 px-3 py-1.5 text-xs font-bold text-indigo-600 bg-indigo-50 rounded-xl hover:bg-indigo-100 border border-indigo-200 transition whitespace-nowrap">
                            <i class="fas fa-undo mr-1"></i> Pulihkan
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <!-- 11. INBOX TAB -->
            <section id="tab-messages" class="tab-content">
                <div class="flex justify-between items-center mb-6"><h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.messages_heading', [], $lang); ?></h2></div>
                <div class="space-y-4">
                    <?php foreach($payload['messages'] as $m): ?>
                    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <div><h4 class="font-bold text-slate-800"><?php echo htmlspecialchars($m['name']); ?></h4><p class="text-xs text-indigo-600"><?php echo htmlspecialchars($m['email']); ?></p></div>
                            <span class="text-[10px] text-slate-400"><?php echo date('d/m', strtotime($m['created_at'])); ?></span>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg text-sm text-slate-600 mb-3 border border-slate-100"><?php echo nl2br(htmlspecialchars($m['message'])); ?></div>
                        <div class="flex gap-2">
                            <a href="mailto:<?php echo $m['email']; ?>" class="flex-1 btn-action bg-indigo-50 text-indigo-600 h-10 w-full rounded-lg text-xs font-bold gap-2"><i class="fas fa-reply"></i> <?php echo $lang === 'en' ? 'Reply' : 'Balas'; ?></a>
                            <button onclick="deleteItem(<?php echo $m['id']; ?>, 'messages')" class="btn-action btn-delete w-12 h-10 rounded-lg"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- TAB: SETTINGS -->
            <section id="tab-settings" class="tab-content">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-slate-800">Settings</h2>
                </div>
                <div class="max-w-2xl space-y-6">

                    <!-- Google AI Multi-Key Manager -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background:linear-gradient(135deg,#4285F4,#34A853)">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15v-4H7l5-8v4h4l-5 8z" fill="white"/></svg>
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-800">Google AI (Gemini) API Keys</h3>
                                    <p class="text-xs text-slate-500">Multi-key dengan auto-rotate otomatis jika kuota habis</p>
                                </div>
                            </div>
                            <span id="keys-count-badge" class="text-xs font-bold px-2 py-1 rounded-full bg-indigo-100 text-indigo-700">
                                <?php echo count($geminiKeys); ?> key<?php echo count($geminiKeys) !== 1 ? 's' : ''; ?>
                            </span>
                        </div>

                        <div class="p-5 space-y-4">
                            <!-- Key list -->
                            <div id="gemini-keys-list" class="space-y-3">
                                <?php if (empty($geminiKeys)): ?>
                                <!-- empty placeholder handled by JS -->
                                <?php else: ?>
                                <?php foreach ($geminiKeys as $i => $k): ?>
                                <div class="gemini-key-row flex items-center gap-2" data-index="<?php echo $i; ?>">
                                    <div class="flex items-center justify-center w-7 h-7 rounded-lg bg-slate-100 text-xs font-bold text-slate-500 shrink-0"><?php echo $i + 1; ?></div>
                                    <div class="relative flex-1">
                                        <input type="password" class="gemini-key-input input-modern pr-10 text-sm font-mono"
                                            value="<?php echo htmlspecialchars($k); ?>"
                                            placeholder="Paste API key di sini...">
                                        <button type="button" onclick="toggleRowKey(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <button type="button" onclick="testSingleKey(this)" title="Test key ini"
                                        class="w-9 h-9 rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50 flex items-center justify-center shrink-0">
                                        <i class="fas fa-vial text-xs"></i>
                                    </button>
                                    <?php if ($i === 0): ?>
                                    <div class="w-9 h-9 rounded-xl bg-green-50 flex items-center justify-center shrink-0" title="Key utama">
                                        <i class="fas fa-star text-xs text-green-500"></i>
                                    </div>
                                    <?php else: ?>
                                    <button type="button" onclick="removeKeyRow(this)" title="Hapus key ini"
                                        class="w-9 h-9 rounded-xl bg-red-50 text-red-400 hover:bg-red-100 flex items-center justify-center shrink-0">
                                        <i class="fas fa-trash text-xs"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Add key button -->
                            <button type="button" onclick="addKeyRow()"
                                class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl border border-dashed border-slate-300 text-sm font-semibold text-slate-500 hover:border-indigo-400 hover:text-indigo-600 hover:bg-indigo-50 transition-all">
                                <i class="fas fa-plus"></i> Tambah API Key
                            </button>

                            <p class="text-xs text-slate-400">
                                Key #1 = utama. Jika kuota habis, sistem otomatis beralih ke key berikutnya.
                                Dapatkan API key gratis di <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-indigo-500 underline">Google AI Studio</a>
                            </p>

                            <!-- Save + test buttons -->
                            <div class="flex gap-2 items-center pt-1">
                                <button type="button" onclick="saveAllKeys()" class="btn-modern">
                                    <i class="fas fa-save"></i> Simpan Semua Key
                                </button>
                                <button type="button" onclick="testAllKeys()" id="btn-test-all-keys"
                                    class="px-4 py-2 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 flex items-center gap-2">
                                    <i class="fas fa-vial"></i> Test Semua
                                </button>
                            </div>

                            <!-- Hidden form for CSRF -->
                            <input type="hidden" id="settings-csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
                        </div>
                    </div>

                    <!-- Info box -->
                    <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-5">
                        <h4 class="font-bold text-indigo-800 mb-2 flex items-center gap-2"><i class="fas fa-info-circle"></i> Auto-Rotate API Key</h4>
                        <ul class="text-sm text-indigo-700 space-y-1 list-disc list-inside">
                            <li>Key #1 selalu dicoba pertama kali</li>
                            <li>Jika kuota habis (<em>RESOURCE_EXHAUSTED</em>) → otomatis pakai key berikutnya</li>
                            <li>Jika key tidak valid → dilewati, coba key selanjutnya</li>
                            <li>Tambahkan sebanyak mungkin key untuk zero-downtime</li>
                        </ul>
                    </div>

                </div>
            </section>

            <!-- ── CV GENERATOR TAB ───────────────────────────────────────── -->
            <section id="tab-cv" class="tab-content">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-800">CV Generator</h2>
                        <p class="text-sm text-slate-500 mt-1">Generate · Rewrite · Tailor to JD · ATS Optimization — berdasarkan data profil aktif</p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="downloadCvPdf()" id="btn-download-cv"
                            class="px-4 py-2 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 flex items-center gap-2">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button onclick="downloadCvWord()" id="btn-download-word"
                            class="px-4 py-2 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 flex items-center gap-2">
                            <i class="fas fa-file-word"></i> Word
                        </button>
                    </div>
                </div>

                <div class="grid lg:grid-cols-5 gap-6">
                    <!-- Left: AI Controls -->
                    <div class="lg:col-span-2 space-y-4">

                        <!-- Mode selector -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Mode AI</h3>
                            <div class="grid grid-cols-2 gap-2">
                                <?php foreach ([
                                    ['generate','fas fa-magic','Generate','Buat CV dari data profil'],
                                    ['rewrite','fas fa-pen-nib','Rewrite','Perkuat bahasa & impact'],
                                    ['tailor','fas fa-crosshairs','Tailor to JD','Sesuaikan ke job desc'],
                                    ['ats','fas fa-robot','ATS Optimize','Maksimalkan keyword ATS'],
                                ] as [$mode,$icon,$label,$desc]): ?>
                                <button type="button" onclick="setCvMode('<?php echo $mode; ?>')"
                                    id="cv-mode-<?php echo $mode; ?>"
                                    class="cv-mode-btn flex flex-col items-start p-3 rounded-xl border border-slate-200 hover:border-indigo-400 hover:bg-indigo-50 transition text-left <?php echo $mode === 'generate' ? 'border-indigo-400 bg-indigo-50' : ''; ?>">
                                    <i class="<?php echo $icon; ?> text-indigo-500 mb-1"></i>
                                    <span class="text-xs font-bold text-slate-700"><?php echo $label; ?></span>
                                    <span class="text-[10px] text-slate-400 leading-tight"><?php echo $desc; ?></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Job description (shown for tailor/ats) -->
                        <div id="cv-jd-panel" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 hidden">
                            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Job Description</h3>
                            <textarea id="cv-jd-input" class="input-modern h-40 text-sm"
                                placeholder="Paste job description di sini...&#10;&#10;AI akan menyesuaikan CV agar relevan dengan posisi ini."></textarea>
                        </div>

                        <!-- CV language -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Bahasa CV</h3>
                            <div class="flex gap-2">
                                <button type="button" onclick="setCvLang('id')" id="cv-lang-id"
                                    class="cv-lang-btn flex-1 py-2 rounded-xl border border-indigo-400 bg-indigo-50 text-xs font-bold text-indigo-700">Bahasa Indonesia</button>
                                <button type="button" onclick="setCvLang('en')" id="cv-lang-en"
                                    class="cv-lang-btn flex-1 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50">English</button>
                            </div>
                        </div>

                        <!-- Generate button -->
                        <button type="button" onclick="runGenerateCv()" id="btn-generate-cv"
                            class="w-full btn-modern btn-ai-pulse flex items-center justify-center gap-2 py-3.5">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <span id="cv-btn-text">Generate CV</span>
                        </button>
                        <button type="button" onclick="cancelGenerateCv()" id="btn-cancel-cv"
                            class="hidden w-full flex items-center justify-center gap-2 py-2.5 rounded-xl border border-red-200 text-sm font-semibold text-red-500 bg-red-50 hover:bg-red-100 transition">
                            <i class="fas fa-times"></i> Batalkan
                        </button>

                        <!-- CV History -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider flex items-center gap-2">
                                    <i class="fas fa-history text-slate-400"></i> Riwayat CV
                                </span>
                                <span id="cv-history-count" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">0</span>
                            </div>
                            <div id="cv-history-list" class="divide-y divide-slate-100 max-h-64 overflow-y-auto custom-scrollbar">
                                <div class="p-4 text-xs text-slate-400 text-center">Belum ada riwayat</div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: CV Preview -->
                    <div class="lg:col-span-3">
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Preview CV</span>
                                    <span id="cv-edit-hint" class="hidden text-[10px] text-indigo-500 font-medium">• Klik teks untuk edit langsung</span>
                                </div>
                                <span id="cv-status-badge" class="text-[10px] font-semibold px-2 py-1 rounded-full bg-slate-100 text-slate-500">Belum digenerate</span>
                            </div>
                            <div class="overflow-auto max-h-[780px] p-2 bg-slate-100">
                                <div id="cv-preview" class="cv-paper">
                                    <!-- filled by JS after generate -->
                                    <div class="text-center py-20 text-slate-400">
                                        <i class="fas fa-file-alt text-4xl mb-3 block opacity-30"></i>
                                        <p class="text-sm font-semibold">Klik "Generate CV" untuk mulai</p>
                                        <p class="text-xs mt-1">AI akan mengisi CV berdasarkan data profil aktif</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

        </div>
    </main>

    <!-- ================================================================== -->
    <!-- MODALS (Full Features Restored)                                    -->
    <!-- ================================================================== -->

    <!-- 1. PROJECT MODAL -->
    <div id="modal-project" class="modal-wrapper">
        <div class="modal-box w-full max-w-4xl">
            <div class="modal-header">
                <h3 class="font-bold text-lg"><?php echo t('admin.project_editor', [], $lang); ?></h3>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="openAiModal()"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-white btn-ai-pulse"
                        style="background:linear-gradient(135deg,#8B5CF6,#6D28D9)">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        Generate AI
                    </button>
                    <button onclick="closeModal('project')" class="w-8 h-8 bg-slate-100 rounded-full text-slate-500"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <form id="form-project" class="modal-body space-y-5">
                <input type="hidden" name="action" value="save_project">
                <input type="hidden" name="is_ajax" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" id="proj_id">

                <div><label class="text-xs font-bold">Judul Proyek (ID)</label><input name="title" id="proj_title" class="input-modern" required></div>
                <div><label class="text-xs font-bold">Project Title (EN)</label><input name="title_en" id="proj_title_en" class="input-modern"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="text-xs font-bold">Kategori (ID)</label><input name="category" id="proj_category" class="input-modern" list="cat_list"><datalist id="cat_list"><option value="Web Development"><option value="Paid Ads"><option value="Social Media"></datalist></div>
                    <div><label class="text-xs font-bold">Category (EN)</label><input name="category_en" id="proj_category_en" class="input-modern"></div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="text-xs font-bold">Klien</label><input name="client_name" id="proj_client" class="input-modern"></div>
                    <div><label class="text-xs font-bold">Result Badge (ID)</label><input name="result_text" id="proj_result" class="input-modern"></div>
                </div>
                <div><label class="text-xs font-bold">Result Badge (EN)</label><input name="result_text_en" id="proj_result_en" class="input-modern"></div>
                <div><label class="text-xs font-bold">Deskripsi Singkat (ID)</label><textarea name="description" id="proj_desc" class="input-modern h-20"></textarea></div>
                <div><label class="text-xs font-bold">Short Description (EN)</label><textarea name="description_en" id="proj_desc_en" class="input-modern h-20"></textarea></div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="text-xs font-bold">Link URL</label><input name="link_url" id="proj_link" class="input-modern"></div>
                    <div><label class="text-xs font-bold">Tech Stacks (Koma)</label><input name="tech_stacks" id="proj_tech" class="input-modern"></div>
                </div>
                <div><label class="text-xs font-bold">SEO Slug</label><input name="slug" id="proj_slug" class="input-modern bg-slate-50"></div>
                <div><label class="text-xs font-bold">Meta Description (ID)</label><input name="meta_desc" id="proj_meta" class="input-modern"></div>
                <div><label class="text-xs font-bold">Meta Description (EN)</label><input name="meta_desc_en" id="proj_meta_en" class="input-modern"></div>
                
                <div><label class="text-xs font-bold">Masalah (Problem) - ID</label><textarea name="problem" id="proj_problem" class="input-modern h-20"></textarea></div>
                <div><label class="text-xs font-bold">Problem (EN)</label><textarea name="problem_en" id="proj_problem_en" class="input-modern h-20"></textarea></div>
                <div><label class="text-xs font-bold">Solusi (Solution) - ID</label><textarea name="solution" id="proj_solution" class="input-modern h-20"></textarea></div>
                <div><label class="text-xs font-bold">Solution (EN)</label><textarea name="solution_en" id="proj_solution_en" class="input-modern h-20"></textarea></div>

                <div><label class="text-xs font-bold text-slate-400">DETAIL LENGKAP (HTML) - ID</label><textarea name="details" id="proj_details" class="input-code h-40"></textarea></div>
                <div><label class="text-xs font-bold text-slate-400">FULL DETAIL (HTML) - EN</label><textarea name="details_en" id="proj_details_en" class="input-code h-40"></textarea></div>
                <div><label class="text-xs font-bold text-slate-400">CHART JSON (Raw Code)</label><textarea name="chart_data_json" id="proj_chart" class="input-code h-20" placeholder='{"labels":["A","B"],"data":[10,20]}'></textarea></div>
                <div><label class="text-xs font-bold text-slate-400">CHART JSON (Raw Code) - EN</label><textarea name="chart_data_json_en" id="proj_chart_en" class="input-code h-20" placeholder='{"labels":["A","B"],"data":[10,20]}'></textarea></div>
                
                <!-- Extra hidden fields preserved -->
                <input type="hidden" name="display_order" id="proj_order" value="0">

                <div class="border-t pt-4 space-y-4">
                    <div>
                        <label class="text-xs font-bold">Cover Image (Main)</label>
                        <input type="file" name="project_image_file" class="input-modern text-xs">
                        <input type="hidden" name="old_project_image" id="proj_old_img">
                    </div>
                    <div>
                        <label class="text-xs font-bold">Galeri Tambahan (Multiple)</label>
                        <input type="file" name="gallery_files[]" multiple class="input-modern text-xs">
                        <div id="gallery-preview" class="grid grid-cols-4 gap-2 mt-2"></div>
                    </div>
                </div>
            </form>
            <div class="modal-footer"><button type="button" onclick="submitForm('form-project')" class="btn-modern w-full"><?php echo t('admin.save_project', [], $lang); ?></button></div>
        </div>
    </div>

    <!-- AI TOPIC MODAL -->
    <div id="modal-ai-topic" class="modal-wrapper" style="z-index:60">
        <div class="modal-box max-w-lg">
            <div class="modal-header">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#8B5CF6,#6D28D9)">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="white"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    </div>
                    <h3 class="font-bold text-lg">Generate Studi Kasus dengan AI</h3>
                </div>
                <button onclick="closeAiModal()" class="w-8 h-8 bg-slate-100 rounded-full text-slate-500"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body space-y-4">
                <div class="bg-purple-50 border border-purple-100 rounded-xl p-4 text-sm text-purple-700 space-y-2">
                    <p><strong>Semakin detail brief-nya, semakin tajam hasilnya.</strong> Sebutkan industri klien, masalah bisnis, fitur/deliverable konkret, dan teknologi bila sudah ada.</p>
                    <p class="text-xs text-purple-600">Contoh: <em>"Redesign aplikasi mobile e-commerce fashion lokal (target wanita 20-35) untuk menurunkan cart abandonment. Fokus: AR try-on dan checkout satu langkah. Stack: React Native, Midtrans, Supabase."</em></p>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-bold text-slate-700">Topik / Deskripsi Proyek</label>
                        <span id="ai_topic_count" class="text-[11px] text-slate-400">0 / 2500</span>
                    </div>
                    <textarea id="ai_topic_input" class="input-modern h-40" maxlength="2500" oninput="document.getElementById('ai_topic_count').textContent = this.value.length + ' / 2500'"
                        placeholder="Contoh: Aplikasi mobile e-commerce fashion lokal dengan fitur AR try-on untuk meningkatkan konversi pelanggan..."></textarea>
                    <p class="text-[11px] text-slate-400 mt-1">Total konten yang dihasilkan AI dibatasi maksimal 3000 kata.</p>
                </div>
                <div id="ai-generating-state" class="hidden">
                    <div class="flex items-center gap-3 bg-slate-50 rounded-xl p-4">
                        <div class="w-5 h-5 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
                        <div>
                            <p class="text-sm font-semibold text-slate-700">Sedang generate konten...</p>
                            <p class="text-xs text-slate-400">Gemini AI sedang membuat studi kasus lengkap</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer flex gap-2">
                <button type="button" onclick="closeAiModal()" class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600">Batal</button>
                <button type="button" onclick="runAiGenerate()" id="btn-run-ai"
                    class="flex-1 btn-modern" style="background:linear-gradient(135deg,#8B5CF6,#6D28D9)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="mr-1"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    Generate Sekarang
                </button>
            </div>
        </div>
    </div>

    <!-- 2. METRIC MODAL -->
    <div id="modal-metric" class="modal-wrapper">
        <div class="modal-box max-w-md">
            <div class="modal-header"><h3 class="font-bold"><?php echo t('admin.metric', [], $lang); ?></h3><button onclick="closeModal('metric')"><i class="fas fa-times"></i></button></div>
            <form id="form-metric" class="modal-body space-y-4">
                <input type="hidden" name="action" value="save_metric"><input type="hidden" name="is_ajax" value="1"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" id="met_id">
                <div><label class="text-xs font-bold">Label (ID)</label><input name="metric_name" id="met_name" class="input-modern"></div>
                <div><label class="text-xs font-bold">Label (EN)</label><input name="metric_name_en" id="met_name_en" class="input-modern"></div>
                <div><label class="text-xs font-bold">Value (ID)</label><input name="metric_value" id="met_val" class="input-modern"></div>
                <div><label class="text-xs font-bold">Value (EN)</label><input name="metric_value_en" id="met_val_en" class="input-modern"></div>
                <div><label class="text-xs font-bold">Icon (FA)</label><input name="icon" id="met_icon" class="input-modern"></div>
                <div><label class="text-xs font-bold">Urutan</label><input type="number" name="display_order" id="met_order" class="input-modern"></div>
            </form>
            <div class="modal-footer"><button type="button" onclick="submitForm('form-metric')" class="btn-modern w-full"><?php echo t('admin.save', [], $lang); ?></button></div>
        </div>
    </div>

    <!-- 3. CLIENT MODAL -->
    <div id="modal-client" class="modal-wrapper">
        <div class="modal-box max-w-md">
            <div class="modal-header"><h3 class="font-bold"><?php echo t('admin.client', [], $lang); ?></h3><button onclick="closeModal('client')"><i class="fas fa-times"></i></button></div>
            <form id="form-client" class="modal-body space-y-4">
                <input type="hidden" name="action" value="save_client"><input type="hidden" name="is_ajax" value="1"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" id="client_id">
                <div><label class="text-xs font-bold">Nama</label><input name="client_name" id="client_name" class="input-modern"></div>
                <div>
                    <label class="text-xs font-bold block mb-1">Logo</label>
                    <div class="flex gap-2 mb-2">
                        <button type="button" onclick="clientLogoTab('file')" id="logo-tab-file" class="px-3 py-1 rounded-full text-[11px] font-bold bg-slate-800 text-white transition">Upload File</button>
                        <button type="button" onclick="clientLogoTab('url')" id="logo-tab-url" class="px-3 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600 transition">URL Gambar</button>
                    </div>
                    <div id="logo-input-file"><input type="file" name="logo_file" id="client_logo_file" class="input-modern text-xs" onchange="previewClientLogo(null, this)"></div>
                    <div id="logo-input-url" class="hidden"><input type="text" name="logo_url_input" id="client_logo_url" class="input-modern" placeholder="https://..." oninput="previewClientLogo(this.value, null)"></div>
                    <input type="hidden" name="old_logo_url" id="client_old_logo">
                    <div id="client_logo_preview" class="mt-2 hidden items-center gap-2">
                        <img id="client_logo_img" src="" class="h-10 max-w-[120px] object-contain border border-slate-200 rounded-lg p-1 bg-white" alt="preview">
                        <span class="text-xs text-slate-400">Preview</span>
                    </div>
                </div>
                <input type="hidden" name="display_order" value="0">
            </form>
            <div class="modal-footer"><button type="button" onclick="submitForm('form-client')" class="btn-modern w-full"><?php echo t('admin.save', [], $lang); ?></button></div>
        </div>
    </div>

    <!-- 4. CHAT MODAL -->
    <div id="modal-chat" class="modal-wrapper">
        <div class="modal-box max-w-md">
            <div class="modal-header"><h3 class="font-bold"><?php echo t('admin.chat_modal', [], $lang); ?></h3><button onclick="closeModal('chat')"><i class="fas fa-times"></i></button></div>
            <form id="form-chat" class="modal-body space-y-4">
                <input type="hidden" name="action" value="save_chat"><input type="hidden" name="is_ajax" value="1"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" id="chat_id">
                <div><label class="text-xs font-bold">Pertanyaan (ID)</label><input name="question" id="chat_q" class="input-modern"></div>
                <div><label class="text-xs font-bold">Question (EN)</label><input name="question_en" id="chat_q_en" class="input-modern"></div>
                <div><label class="text-xs font-bold">Jawaban (ID)</label><textarea name="answer" id="chat_a" class="input-modern h-20"></textarea></div>
                <div><label class="text-xs font-bold">Answer (EN)</label><textarea name="answer_en" id="chat_a_en" class="input-modern h-20"></textarea></div>
                <div><label class="text-xs font-bold">Tipe</label><select name="type" id="chat_type" class="input-modern"><option value="text">Text</option><option value="cta">CTA</option></select></div>
                <div><label class="text-xs font-bold">Urutan</label><input type="number" name="display_order" id="chat_order" class="input-modern"></div>
            </form>
            <div class="modal-footer"><button type="button" onclick="submitForm('form-chat')" class="btn-modern w-full"><?php echo t('admin.save', [], $lang); ?></button></div>
        </div>
    </div>

    <!-- 5. SKILL MODAL -->
    <div id="modal-skill" class="modal-wrapper">
        <div class="modal-box max-w-md">
            <div class="modal-header"><h3 class="font-bold"><?php echo t('admin.skill_modal', [], $lang); ?></h3><button onclick="closeModal('skill')"><i class="fas fa-times"></i></button></div>
            <form id="form-skill" class="modal-body space-y-4">
                <input type="hidden" name="action" value="save_skill"><input type="hidden" name="is_ajax" value="1"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" id="skill_id">
                <div><label class="text-xs font-bold">Nama Skill (ID)</label><input name="skill_name" id="skill_name" class="input-modern"></div>
                <div><label class="text-xs font-bold">Skill Name (EN)</label><input name="skill_name_en" id="skill_name_en" class="input-modern"></div>
                <div><label class="text-xs font-bold">Kategori</label><input name="category" id="skill_cat" class="input-modern" list="skill_list" placeholder="Pilih..."><datalist id="skill_list"><option value="Technical"><option value="Design"><option value="Marketing"><option value="Tools"></datalist></div>
                <div>
                    <label class="text-xs font-bold">Icon URL (gambar)</label>
                    <input name="icon_url" id="skill_icon" class="input-modern" placeholder="https://cdn.../icon.svg" oninput="previewSkillIcon(this.value)">
                    <div id="skill_icon_preview" class="mt-2 hidden items-center gap-2">
                        <img id="skill_icon_img" src="" class="w-10 h-10 object-contain border border-slate-200 rounded-lg p-1 bg-white" alt="preview">
                        <span class="text-xs text-slate-500">Preview</span>
                    </div>
                </div>
            </form>
            <div class="modal-footer"><button type="button" onclick="submitForm('form-skill')" class="btn-modern w-full"><?php echo t('admin.save', [], $lang); ?></button></div>
        </div>
    </div>

    <!-- 6. EXPERIENCE MODAL -->
    <div id="modal-experience" class="modal-wrapper">
        <div class="modal-box max-w-md">
            <div class="modal-header"><h3 class="font-bold"><?php echo t('admin.experience_heading', [], $lang); ?></h3><button onclick="closeModal('experience')"><i class="fas fa-times"></i></button></div>
            <form id="form-experience" class="modal-body space-y-4">
                <input type="hidden" name="action" value="save_experience"><input type="hidden" name="is_ajax" value="1"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" id="exp_id">
                <div><label class="text-xs font-bold">Role (ID)</label><input name="role" id="exp_role" class="input-modern"></div>
                <div><label class="text-xs font-bold">Role (EN)</label><input name="role_en" id="exp_role_en" class="input-modern"></div>
                <div><label class="text-xs font-bold">Perusahaan</label><input name="company" id="exp_comp" class="input-modern"></div>
                <div><label class="text-xs font-bold">Tahun (ID)</label><input name="year_range" id="exp_year" class="input-modern"></div>
                <div><label class="text-xs font-bold">Year Range (EN)</label><input name="year_range_en" id="exp_year_en" class="input-modern"></div>
                <div><label class="text-xs font-bold">Deskripsi (ID)</label><textarea name="description" id="exp_desc" class="input-modern h-24"></textarea></div>
                <div><label class="text-xs font-bold">Description (EN)</label><textarea name="description_en" id="exp_desc_en" class="input-modern h-24"></textarea></div>
            </form>
            <div class="modal-footer"><button type="button" onclick="submitForm('form-experience')" class="btn-modern w-full"><?php echo t('admin.save', [], $lang); ?></button></div>
        </div>
    </div>

    <!-- 7. EDUCATION MODAL -->
    <div id="modal-education" class="modal-wrapper">
        <div class="modal-box max-w-md">
            <div class="modal-header"><h3 class="font-bold"><?php echo t('admin.education_heading', [], $lang); ?></h3><button onclick="closeModal('education')"><i class="fas fa-times"></i></button></div>
            <form id="form-education" class="modal-body space-y-4">
                <input type="hidden" name="action" value="save_education"><input type="hidden" name="is_ajax" value="1"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" id="edu_id">
                <div><label class="text-xs font-bold">Sekolah</label><input name="school_name" id="edu_sch" class="input-modern"></div>
                <div><label class="text-xs font-bold">Gelar (ID)</label><input name="degree" id="edu_deg" class="input-modern"></div>
                <div><label class="text-xs font-bold">Degree (EN)</label><input name="degree_en" id="edu_deg_en" class="input-modern"></div>
                <div><label class="text-xs font-bold">Tahun (ID)</label><input name="year_range" id="edu_year" class="input-modern"></div>
                <div><label class="text-xs font-bold">Year Range (EN)</label><input name="year_range_en" id="edu_year_en" class="input-modern"></div>
            </form>
            <div class="modal-footer"><button type="button" onclick="submitForm('form-education')" class="btn-modern w-full"><?php echo t('admin.save', [], $lang); ?></button></div>
        </div>
    </div>

    <!-- 8. ARTICLE MODAL -->
    <div id="modal-article" class="modal-wrapper">
        <div class="modal-box w-full max-w-4xl">
            <div class="modal-header"><h3 class="font-bold"><?php echo t('admin.article_modal', [], $lang); ?></h3><button onclick="closeModal('article')"><i class="fas fa-times"></i></button></div>
            <form id="form-article" class="modal-body space-y-4">
                <input type="hidden" name="action" value="save_article"><input type="hidden" name="is_ajax" value="1"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" id="art_id">
                <div><label class="text-xs font-bold">Judul (ID)</label><input name="title" id="art_title" class="input-modern"></div>
                <div><label class="text-xs font-bold">Title (EN)</label><input name="title_en" id="art_title_en" class="input-modern"></div>
                <div><label class="text-xs font-bold">Slug</label><input name="slug" id="art_slug" class="input-modern bg-slate-50"></div>
                <div><label class="text-xs font-bold">Meta Desc (ID)</label><input name="meta_desc" id="art_meta" class="input-modern"></div>
                <div><label class="text-xs font-bold">Meta Desc (EN)</label><input name="meta_desc_en" id="art_meta_en" class="input-modern"></div>
                <div><label class="text-xs font-bold">Konten HTML (ID)</label><textarea name="content" id="art_content" class="input-code h-40"></textarea></div>
                <div><label class="text-xs font-bold">HTML Content (EN)</label><textarea name="content_en" id="art_content_en" class="input-code h-40"></textarea></div>
                <div><label class="text-xs font-bold">Cover</label><input type="file" name="article_image_file" class="input-modern text-xs"><input type="hidden" name="old_article_image" id="art_old_img"></div>
            </form>
            <div class="modal-footer"><button type="button" onclick="submitForm('form-article')" class="btn-modern w-full"><?php echo t('admin.save_article', [], $lang); ?></button></div>
        </div>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        const DB_DATA = <?php echo json_encode($payload); ?>;
        const ADMIN_UI = <?php echo json_encode($adminUi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        
        // Helper: Get Item from DB_DATA
        function getItem(category, id) {
            return DB_DATA[category].find(item => item.id == id);
        }

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.sidebar-item').forEach(el => el.classList.remove('active'));
            const target = document.getElementById('tab-' + tabId);
            const nav = document.getElementById('nav-' + tabId);
            if(target) target.classList.add('active');
            if(nav) nav.classList.add('active');
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.pushState({}, '', url);
            if (tabId === 'cv' && typeof loadCvHistory === 'function') loadCvHistory();
        }

        const urlParams = new URLSearchParams(window.location.search);
        switchTab(urlParams.get('tab') || 'dashboard'); // Default to dashboard

        function openModal(type, id = null) {
            const modal = document.getElementById('modal-' + type);
            const form = document.getElementById('form-' + type);
            if(!modal || !form) return console.error('Error: Modal/Form not found', type);

            form.reset();
            form.querySelectorAll('input[type="hidden"]').forEach(i => {
                if(!['action','is_ajax','csrf_token'].includes(i.name)) i.value = '';
            });
            const galDiv = document.getElementById('gallery-preview');
            if(galDiv) galDiv.innerHTML = '';

            if(id) {
                // Mapping logic similar to original file but using DB_DATA
                let dbKey = type + 's'; // default plural
                if(type === 'metric') dbKey = 'metrics';
                if(type === 'article') dbKey = 'articles';
                if(type === 'experience') dbKey = 'experience';
                if(type === 'education') dbKey = 'education';

                const data = getItem(dbKey, id);
                if(data) {
                    // Manual mapping based on field names in form
                    if(type === 'project') {
                        document.getElementById('proj_id').value = data.id;
                        document.getElementById('proj_title').value = data.title;
                        document.getElementById('proj_title_en').value = data.title_en || '';
                        document.getElementById('proj_category').value = data.category;
                        document.getElementById('proj_category_en').value = data.category_en || '';
                        document.getElementById('proj_client').value = data.client_name;
                        document.getElementById('proj_result').value = data.result_text;
                        document.getElementById('proj_result_en').value = data.result_text_en || '';
                        document.getElementById('proj_desc').value = data.description;
                        document.getElementById('proj_desc_en').value = data.description_en || '';
                        document.getElementById('proj_link').value = data.link_url;
                        document.getElementById('proj_tech').value = data.tech_stacks;
                        document.getElementById('proj_slug').value = data.slug;
                        document.getElementById('proj_meta').value = data.meta_desc;
                        document.getElementById('proj_meta_en').value = data.meta_desc_en || '';
                        document.getElementById('proj_problem').value = data.problem;
                        document.getElementById('proj_problem_en').value = data.problem_en || '';
                        document.getElementById('proj_solution').value = data.solution;
                        document.getElementById('proj_solution_en').value = data.solution_en || '';
                        document.getElementById('proj_details').value = data.details;
                        document.getElementById('proj_details_en').value = data.details_en || '';
                        document.getElementById('proj_old_img').value = data.image_url;
                        try { document.getElementById('proj_chart').value = typeof data.chart_data_json === 'string' ? data.chart_data_json : JSON.stringify(data.chart_data_json); } catch(e){}
                        try { document.getElementById('proj_chart_en').value = typeof data.chart_data_json_en === 'string' ? data.chart_data_json_en : (data.chart_data_json_en ? JSON.stringify(data.chart_data_json_en) : ''); } catch(e){}
                        
                        if(data.gallery && galDiv) {
                            data.gallery.forEach(img => {
                                galDiv.innerHTML += `<div class="relative"><img src="${img.image_url}" class="w-full h-12 object-cover rounded"><a href="process.php?delete=${img.id}&type=project_images" class="absolute top-0 right-0 bg-red-600 text-white text-[10px] px-1 rounded cursor-pointer" onclick="return confirm('${ADMIN_UI.galleryDelete}')">X</a></div>`;
                            });
                        }
                    } else if (type === 'metric') {
                        document.getElementById('met_id').value = data.id;
                        document.getElementById('met_name').value = data.metric_name;
                        document.getElementById('met_name_en').value = data.metric_name_en || '';
                        document.getElementById('met_val').value = data.metric_value;
                        document.getElementById('met_val_en').value = data.metric_value_en || '';
                        document.getElementById('met_icon').value = data.icon;
                        document.getElementById('met_order').value = data.display_order;
                    } else if (type === 'client') {
                        document.getElementById('client_id').value = data.id;
                        document.getElementById('client_name').value = data.client_name;
                        document.getElementById('client_old_logo').value = data.logo_url;
                        if(data.logo_url) {
                            clientLogoTab('url');
                            document.getElementById('client_logo_url').value = data.logo_url;
                            previewClientLogo(data.logo_url, null);
                        }
                    } else if (type === 'chat') {
                        document.getElementById('chat_id').value = data.id;
                        document.getElementById('chat_q').value = data.question;
                        document.getElementById('chat_q_en').value = data.question_en || '';
                        document.getElementById('chat_a').value = data.answer;
                        document.getElementById('chat_a_en').value = data.answer_en || '';
                        document.getElementById('chat_type').value = data.type;
                        document.getElementById('chat_order').value = data.display_order;
                    } else if (type === 'skill') {
                        document.getElementById('skill_id').value = data.id;
                        document.getElementById('skill_name').value = data.skill_name;
                        document.getElementById('skill_name_en').value = data.skill_name_en || '';
                        document.getElementById('skill_cat').value = data.category;
                        document.getElementById('skill_icon').value = data.icon_url;
                        previewSkillIcon(data.icon_url);
                    } else if (type === 'experience') {
                        document.getElementById('exp_id').value = data.id;
                        document.getElementById('exp_role').value = data.role;
                        document.getElementById('exp_role_en').value = data.role_en || '';
                        document.getElementById('exp_comp').value = data.company;
                        document.getElementById('exp_year').value = data.year_range;
                        document.getElementById('exp_year_en').value = data.year_range_en || '';
                        document.getElementById('exp_desc').value = data.description;
                        document.getElementById('exp_desc_en').value = data.description_en || '';
                    } else if (type === 'education') {
                        document.getElementById('edu_id').value = data.id;
                        document.getElementById('edu_sch').value = data.school_name;
                        document.getElementById('edu_deg').value = data.degree;
                        document.getElementById('edu_deg_en').value = data.degree_en || '';
                        document.getElementById('edu_year').value = data.year_range;
                        document.getElementById('edu_year_en').value = data.year_range_en || '';
                    } else if (type === 'article') {
                        document.getElementById('art_id').value = data.id;
                        document.getElementById('art_title').value = data.title;
                        document.getElementById('art_title_en').value = data.title_en || '';
                        document.getElementById('art_slug').value = data.slug;
                        document.getElementById('art_meta').value = data.meta_desc;
                        document.getElementById('art_meta_en').value = data.meta_desc_en || '';
                        document.getElementById('art_content').value = data.content;
                        document.getElementById('art_content_en').value = data.content_en || '';
                        document.getElementById('art_old_img').value = data.image_url;
                    }
                }
            }
            modal.classList.add('show');
        }

        function closeModal(type) {
            const modal = document.getElementById('modal-' + type);
            if(modal) modal.classList.remove('show');
            if(type === 'skill') previewSkillIcon('');
            if(type === 'client') { clientLogoTab('file'); previewClientLogo('', null); }
        }

        function clientLogoTab(tab) {
            const fileDiv = document.getElementById('logo-input-file');
            const urlDiv  = document.getElementById('logo-input-url');
            const btnFile = document.getElementById('logo-tab-file');
            const btnUrl  = document.getElementById('logo-tab-url');
            if(!fileDiv || !urlDiv) return;
            if(tab === 'url') {
                fileDiv.classList.add('hidden'); urlDiv.classList.remove('hidden');
                btnFile.className = 'px-3 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600 transition';
                btnUrl.className  = 'px-3 py-1 rounded-full text-[11px] font-bold bg-slate-800 text-white transition';
            } else {
                urlDiv.classList.add('hidden'); fileDiv.classList.remove('hidden');
                btnFile.className = 'px-3 py-1 rounded-full text-[11px] font-bold bg-slate-800 text-white transition';
                btnUrl.className  = 'px-3 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600 transition';
                document.getElementById('client_logo_url').value = '';
            }
        }

        function previewClientLogo(url, fileInput) {
            const wrap = document.getElementById('client_logo_preview');
            const img  = document.getElementById('client_logo_img');
            if(!wrap || !img) return;
            if(fileInput && fileInput.files && fileInput.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { img.src = e.target.result; wrap.classList.remove('hidden'); wrap.classList.add('flex'); };
                reader.readAsDataURL(fileInput.files[0]);
            } else if(url && url.trim()) {
                img.src = url.trim();
                wrap.classList.remove('hidden'); wrap.classList.add('flex');
                img.onerror = () => { wrap.classList.add('hidden'); wrap.classList.remove('flex'); };
            } else {
                wrap.classList.add('hidden'); wrap.classList.remove('flex'); img.src = '';
            }
        }

        function previewSkillIcon(url) {
            const wrap = document.getElementById('skill_icon_preview');
            const img  = document.getElementById('skill_icon_img');
            if(!wrap || !img) return;
            if(url && url.trim()) {
                img.src = url.trim();
                wrap.classList.remove('hidden');
                wrap.classList.add('flex');
                img.onerror = function(){ wrap.classList.add('hidden'); wrap.classList.remove('flex'); };
            } else {
                wrap.classList.add('hidden');
                wrap.classList.remove('flex');
                img.src = '';
            }
        }

        document.querySelectorAll('.modal-wrapper').forEach(el => {
            el.addEventListener('click', (e) => { if(e.target === el) el.classList.remove('show'); });
        });

        function submitForm(formId) {
            const form = document.getElementById(formId);
            const formData = new FormData(form);
            Swal.fire({ title: ADMIN_UI.saving, didOpen: () => Swal.showLoading() });
            fetch('process.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if(data.status === 'success') Swal.fire({ icon: 'success', title: ADMIN_UI.success, timer: 1000, showConfirmButton: false }).then(() => location.reload());
                else Swal.fire(ADMIN_UI.failed, data.message, 'error');
            })
            .catch(() => Swal.fire(ADMIN_UI.error, ADMIN_UI.connectionFailed, 'error'));
        }

        function deleteItem(id, type) {
            Swal.fire({ title: ADMIN_UI.deleteTitle, icon: 'warning', showCancelButton: true, confirmButtonText: ADMIN_UI.deleteConfirm })
            .then((res) => {
                if(res.isConfirmed) {
                    fetch(`process.php?delete=${id}&type=${type}&is_ajax=1`)
                    .then(r => r.json())
                    .then(data => {
                        if(data.status === 'success') {
                            sessionStorage.setItem('_admin_deleted_flash', '1');
                            location.reload();
                        } else {
                            Swal.fire(ADMIN_UI.failed, data.message, 'error');
                        }
                    });
                }
            });
        }

        function restoreItem(trashId, btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Memulihkan...';
            fetch('process.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=restore_deleted&trash_id=${trashId}`
            }).then(r => r.json()).then(d => {
                if (d.status === 'success') {
                    Swal.fire('Berhasil', 'Data berhasil dipulihkan.', 'success').then(() => location.reload());
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-undo mr-1"></i> Pulihkan';
                    Swal.fire('Error', d.message, 'error');
                }
            });
        }

        // --- AI GENERATE ---
        function openAiModal() {
            document.getElementById('ai_topic_input').value = '';
            document.getElementById('ai_topic_count').textContent = '0 / 2500';
            document.getElementById('ai-generating-state').classList.add('hidden');
            document.getElementById('btn-run-ai').disabled = false;
            document.getElementById('modal-ai-topic').classList.add('show');
        }

        function closeAiModal() {
            document.getElementById('modal-ai-topic').classList.remove('show');
        }

        async function runAiGenerate() {
            const topic = document.getElementById('ai_topic_input').value.trim();
            if (!topic) {
                Swal.fire('Topik kosong', 'Mohon isi topik proyek terlebih dahulu.', 'warning');
                return;
            }
            if (topic.length < 20) {
                Swal.fire('Brief terlalu singkat', 'Tambahkan industri klien, masalah bisnis, atau fitur yang ingin dibangun agar hasil AI lebih spesifik.', 'info');
                return;
            }

            document.getElementById('ai-generating-state').classList.remove('hidden');
            document.getElementById('btn-run-ai').disabled = true;

            const csrfToken = document.querySelector('#form-project input[name="csrf_token"]').value;
            const fd = new FormData();
            fd.append('action', 'ai_generate_project');
            fd.append('is_ajax', '1');
            fd.append('csrf_token', csrfToken);
            fd.append('topic', topic);

            try {
                const res = await fetch('process.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.status !== 'success') {
                    document.getElementById('ai-generating-state').classList.add('hidden');
                    document.getElementById('btn-run-ai').disabled = false;
                    const errMsg = data.message || 'Gagal generate';
                    if (errMsg.toLowerCase().includes('denied') || errMsg.toLowerCase().includes('solusi')) {
                        Swal.fire({
                            icon: 'error',
                            title: 'API Key Bermasalah',
                            html: '<p style="font-size:14px">Google menolak akses. Buat API key baru di <a href="https://aistudio.google.com/app/apikey" target="_blank" style="color:#6366F1">Google AI Studio</a> lalu simpan di menu <strong>Settings</strong>.</p>',
                            confirmButtonColor: '#6366F1',
                        });
                    } else {
                        Swal.fire('Gagal Generate', errMsg, 'error');
                    }
                    return;
                }

                const d = data.data;
                const setVal = (id, val) => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    // Normalize: if value is an object/array, JSON.stringify it
                    if (val !== null && typeof val === 'object') val = JSON.stringify(val);
                    el.value = (val != null) ? val : '';
                };

                setVal('proj_title',      d.title);
                setVal('proj_title_en',   d.title_en);
                setVal('proj_category',   d.category);
                setVal('proj_category_en',d.category_en);
                setVal('proj_client',     d.client_name);
                setVal('proj_result',     d.result_text);
                setVal('proj_result_en',  d.result_text_en);
                setVal('proj_desc',       d.description);
                setVal('proj_desc_en',    d.description_en);
                setVal('proj_link',       d.link_url || '');
                setVal('proj_tech',       d.tech_stacks);
                setVal('proj_slug',       d.slug);
                setVal('proj_meta',       d.meta_desc);
                setVal('proj_meta_en',    d.meta_desc_en);
                setVal('proj_problem',    d.problem);
                setVal('proj_problem_en', d.problem_en);
                setVal('proj_solution',   d.solution);
                setVal('proj_solution_en',d.solution_en);
                setVal('proj_details',    d.details);
                setVal('proj_details_en', d.details_en);
                setVal('proj_chart',      d.chart_data_json);
                setVal('proj_chart_en',   d.chart_data_json_en);

                closeAiModal();
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: 'Semua kolom telah diisi oleh AI. Silakan review dan simpan.',
                    timer: 3000,
                    showConfirmButton: false
                });

                // Scroll form to top
                const formBody = document.querySelector('#modal-project .modal-body');
                if (formBody) formBody.scrollTop = 0;

            } catch (e) {
                Swal.fire('Error', 'Gagal menghubungi server. Coba lagi.', 'error');
                document.getElementById('ai-generating-state').classList.add('hidden');
                document.getElementById('btn-run-ai').disabled = false;
            }
        }

        // ── Settings: Multi-Key Manager ──────────────────────────────────────

        function getKeyRows() {
            return [...document.querySelectorAll('#gemini-keys-list .gemini-key-row')];
        }

        function getAllKeyValues() {
            return getKeyRows().map(r => r.querySelector('.gemini-key-input').value.trim()).filter(Boolean);
        }

        function updateBadge() {
            const n = getAllKeyValues().length;
            const el = document.getElementById('keys-count-badge');
            if (el) el.textContent = n + ' key' + (n !== 1 ? 's' : '');
        }

        function addKeyRow(value = '') {
            const list = document.getElementById('gemini-keys-list');
            const idx  = list.querySelectorAll('.gemini-key-row').length;
            const div  = document.createElement('div');
            div.className = 'gemini-key-row flex items-center gap-2';
            div.dataset.index = idx;
            div.innerHTML = `
                <div class="flex items-center justify-center w-7 h-7 rounded-lg bg-slate-100 text-xs font-bold text-slate-500 shrink-0">${idx + 1}</div>
                <div class="relative flex-1">
                    <input type="password" class="gemini-key-input input-modern pr-10 text-sm font-mono"
                        value="${value.replace(/"/g,'&quot;')}"
                        placeholder="Paste API key di sini...">
                    <button type="button" onclick="toggleRowKey(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <button type="button" onclick="testSingleKey(this)" title="Test key ini"
                    class="w-9 h-9 rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50 flex items-center justify-center shrink-0">
                    <i class="fas fa-vial text-xs"></i>
                </button>
                <button type="button" onclick="removeKeyRow(this)" title="Hapus key ini"
                    class="w-9 h-9 rounded-xl bg-red-50 text-red-400 hover:bg-red-100 flex items-center justify-center shrink-0">
                    <i class="fas fa-trash text-xs"></i>
                </button>`;
            list.appendChild(div);
            div.querySelector('.gemini-key-input').focus();
            updateBadge();
        }

        function removeKeyRow(btn) {
            const rows = getKeyRows();
            if (rows.length <= 1) { Swal.fire('Info', 'Minimal satu API key harus ada.', 'info'); return; }
            btn.closest('.gemini-key-row').remove();
            // Re-number
            getKeyRows().forEach((r, i) => {
                r.dataset.index = i;
                const num = r.querySelector('.w-7');
                if (num) num.textContent = i + 1;
            });
            updateBadge();
        }

        function toggleRowKey(btn) {
            const inp = btn.closest('.relative').querySelector('input');
            const ico = btn.querySelector('i');
            inp.type = inp.type === 'password' ? 'text' : 'password';
            ico.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
        }

        // ── Single-key test ───────────────────────────────────────────────────
        async function testSingleKey(btn) {
            const row = btn.closest('.gemini-key-row');
            const key = row.querySelector('.gemini-key-input').value.trim();
            if (!key) { Swal.fire('Kosong', 'Isi API key terlebih dahulu.', 'warning'); return; }

            const origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner animate-spin text-xs"></i>';

            const res = await pingKey(key);
            btn.disabled = false;
            btn.innerHTML = origHtml;

            if (res.ok) {
                Swal.fire({ icon: 'success', title: 'Berhasil!', text: res.message, timer: 2000, showConfirmButton: false });
            } else {
                showKeyError(res.message);
            }
        }

        // ── Test all keys ─────────────────────────────────────────────────────
        async function testAllKeys() {
            const keys = getAllKeyValues();
            if (!keys.length) { Swal.fire('Kosong', 'Belum ada API key yang diisi.', 'warning'); return; }

            const btn = document.getElementById('btn-test-all-keys');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner animate-spin"></i> Testing...';

            const results = await Promise.all(keys.map((k, i) => pingKey(k).then(r => ({ i, ...r }))));

            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-vial"></i> Test Semua';

            const rows = getKeyRows();
            results.forEach(r => {
                const row = rows[r.i];
                if (!row) return;
                // Remove old status dot
                row.querySelector('.key-status-dot')?.remove();
                const dot = document.createElement('span');
                dot.className = 'key-status-dot text-xs font-bold ' + (r.ok ? 'text-green-500' : 'text-red-400');
                dot.innerHTML = r.ok ? '✓' : '✗';
                dot.title = r.message;
                row.querySelector('.w-7').insertAdjacentElement('afterend', dot);
            });

            const ok = results.filter(r => r.ok).length;
            Swal.fire({
                icon: ok > 0 ? 'success' : 'error',
                title: `${ok}/${keys.length} Key Valid`,
                text: ok > 0 ? `${ok} key siap digunakan.` : 'Tidak ada key yang valid. Cek API key Anda.',
                timer: 3000, showConfirmButton: false,
            });
        }

        // ── Save all keys ─────────────────────────────────────────────────────
        async function saveAllKeys() {
            const keys = getAllKeyValues();
            if (!keys.length) { Swal.fire('Kosong', 'Tambahkan minimal satu API key.', 'warning'); return; }

            Swal.fire({ title: 'Menyimpan...', didOpen: () => Swal.showLoading() });

            const csrf = document.getElementById('settings-csrf').value;
            const fd = new FormData();
            fd.append('action',        'save_setting');
            fd.append('is_ajax',       '1');
            fd.append('csrf_token',    csrf);
            fd.append('setting_key',   'gemini_api_keys');
            fd.append('setting_value', JSON.stringify(keys));

            try {
                const res = await fetch('process.php', { method: 'POST', body: fd }).then(r => r.json());
                if (res.status === 'success') {
                    updateBadge();
                    Swal.fire({ icon: 'success', title: `${keys.length} key disimpan!`, timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                }
            } catch (e) {
                Swal.fire('Error', 'Gagal menghubungi server.', 'error');
            }
        }

        // ── Helper: ping single key ───────────────────────────────────────────
        async function pingKey(key) {
            const csrf = document.getElementById('settings-csrf').value;
            const fd = new FormData();
            fd.append('action',    'test_ai_key');
            fd.append('is_ajax',   '1');
            fd.append('csrf_token', csrf);
            fd.append('api_key',   key);
            try {
                const res = await fetch('process.php', { method: 'POST', body: fd }).then(r => r.json());
                return { ok: res.status === 'success', message: res.message || (res.status === 'success' ? 'OK' : 'Gagal') };
            } catch (e) {
                return { ok: false, message: 'Network error' };
            }
        }

        function showKeyError(msg) {
            if (msg.toLowerCase().includes('denied') || msg.toLowerCase().includes('solusi')) {
                Swal.fire({
                    icon: 'error', title: 'API Key Ditolak',
                    html: '<p style="font-size:14px">Buat API key baru di <a href="https://aistudio.google.com/app/apikey" target="_blank" style="color:#6366F1">Google AI Studio</a> lalu tambahkan di Settings.</p>',
                    confirmButtonColor: '#6366F1',
                });
            } else {
                Swal.fire('Gagal', msg, 'error');
            }
        }

        // Init: jika list kosong, tambah satu row kosong
        (function () {
            const list = document.getElementById('gemini-keys-list');
            if (list && list.querySelectorAll('.gemini-key-row').length === 0) addKeyRow();
        })();

        // --- MULTI-PROFILE FUNCTIONS ---
        const allProfiles = <?php echo json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'label' => $p['profile_label'] ?: $p['name']], $allProfiles)); ?>;
        const editingProfileId = <?php echo $editingProfileId; ?>;

        function editProfileLabel(pid) {
            const cur = document.getElementById('label-text-' + pid)?.innerText || '';
            Swal.fire({
                title: 'Ubah Nama Profil',
                html: `<input id="swal-plabel" class="swal2-input" value="${cur.trim()}" placeholder="Label profil">`,
                showCancelButton: true, confirmButtonText: 'Simpan', cancelButtonText: 'Batal',
                didOpen: () => { const el = document.getElementById('swal-plabel'); el.focus(); el.select(); },
                preConfirm: () => {
                    const v = document.getElementById('swal-plabel').value.trim();
                    if (!v) { Swal.showValidationMessage('Label tidak boleh kosong'); return false; }
                    return v;
                }
            }).then(r => {
                if (!r.isConfirmed) return;
                fetch('process.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=rename_profile&profile_id=${pid}&profile_label=${encodeURIComponent(r.value)}&name=${encodeURIComponent(r.value)}`
                }).then(res => res.json()).then(d => {
                    if (d.status === 'success') location.reload();
                    else Swal.fire('Error', d.message, 'error');
                });
            });
        }

        function deleteProfile(pid, label) {
            Swal.fire({
                title: `Hapus "${label}"?`,
                html: `<p class="text-sm text-slate-600">Semua data konten profil ini (proyek, skill, pengalaman, dll) akan ikut terhapus permanen.</p>`,
                icon: 'warning', showCancelButton: true,
                confirmButtonText: 'Ya, Hapus', confirmButtonColor: '#EF4444',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (!r.isConfirmed) return;
                fetch('process.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=delete_profile&profile_id=${pid}`
                }).then(res => res.json()).then(d => {
                    if (d.status === 'success') location.reload();
                    else Swal.fire('Error', d.message, 'error');
                });
            });
        }

        function seedData(dataType, label) {
            const others = allProfiles.filter(p => p.id !== editingProfileId);
            if (others.length === 0) { Swal.fire('Info', 'Tidak ada profil lain sebagai sumber.', 'info'); return; }

            const pickSource = others.length === 1
                ? Promise.resolve(String(others[0].id))
                : Swal.fire({
                    title: 'Pilih profil sumber',
                    html: '<select id="swal-src" class="swal2-select">' + others.map(p => `<option value="${p.id}">${p.label}</option>`).join('') + '</select>',
                    showCancelButton: true, cancelButtonText: 'Batal', confirmButtonText: 'Pilih',
                    preConfirm: () => document.getElementById('swal-src').value
                  }).then(r => r.isConfirmed ? r.value : null);

            pickSource.then(sourceId => {
                if (!sourceId) return;
                const srcName = allProfiles.find(p => p.id == sourceId)?.label || 'Profil';
                Swal.fire({
                    title: `Salin ${label}?`,
                    html: `Data <b>${label}</b> dari <b>${srcName}</b> akan ditambahkan ke profil ini.<br><small class="text-slate-500">Data yang sudah ada tidak akan dihapus.</small>`,
                    icon: 'question', showCancelButton: true, confirmButtonText: 'Ya, Salin', cancelButtonText: 'Batal'
                }).then(r => {
                    if (!r.isConfirmed) return;
                    fetch('process.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=seed_profile_data&source_profile_id=${sourceId}&data_type=${dataType}`
                    }).then(r => r.json()).then(d => {
                        if (d.status === 'success') Swal.fire('Berhasil!', d.message, 'success').then(() => location.reload());
                        else Swal.fire('Error', d.message, 'error');
                    });
                });
            });
        }

        function switchProfile(pid) {
            fetch('process.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=switch_edit_profile&profile_id=' + pid
            }).then(r => r.json()).then(d => {
                if (d.status === 'success') location.reload();
                else Swal.fire('Error', d.message, 'error');
            });
        }

        function activateProfile(pid) {
            Swal.fire({
                title: 'Publish profil ini?',
                text: 'Profil ini akan tampil di website publik.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Publish',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (!r.isConfirmed) return;
                fetch('process.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=activate_profile&profile_id=' + pid
                }).then(r => r.json()).then(d => {
                    if (d.status === 'success') location.reload();
                    else Swal.fire('Error', d.message, 'error');
                });
            });
        }

        // ── CV Generator ──────────────────────────────────────────────────────

        let cvMode = 'generate';
        let cvLang = 'id';
        let cvData = null; // last generated CV data object

        function setCvMode(mode) {
            cvMode = mode;
            document.querySelectorAll('.cv-mode-btn').forEach(b => {
                b.classList.remove('border-indigo-400','bg-indigo-50');
                b.classList.add('border-slate-200');
            });
            const active = document.getElementById('cv-mode-' + mode);
            if (active) { active.classList.add('border-indigo-400','bg-indigo-50'); active.classList.remove('border-slate-200'); }
            const jdPanel = document.getElementById('cv-jd-panel');
            jdPanel.classList.toggle('hidden', !['tailor','ats'].includes(mode));
            const labels = {generate:'Generate CV', rewrite:'Rewrite CV', tailor:'Tailor to JD', ats:'ATS Optimize'};
            document.getElementById('cv-btn-text').textContent = labels[mode] || 'Generate CV';
        }

        function setCvLang(lang) {
            cvLang = lang;
            ['id','en'].forEach(l => {
                const btn = document.getElementById('cv-lang-' + l);
                if (l === lang) { btn.classList.add('border-indigo-400','bg-indigo-50','text-indigo-700'); btn.classList.remove('border-slate-200','text-slate-600'); }
                else { btn.classList.remove('border-indigo-400','bg-indigo-50','text-indigo-700'); btn.classList.add('border-slate-200','text-slate-600'); }
            });
        }

        let cvAbortController = null;

        function cancelGenerateCv() {
            if (cvAbortController) cvAbortController.abort();
        }

        function cvBtnIdle() {
            const btn = document.getElementById('btn-generate-cv');
            const cancelBtn = document.getElementById('btn-cancel-cv');
            const label = {generate:'Generate CV',rewrite:'Rewrite CV',tailor:'Tailor to JD',ats:'ATS Optimize'}[cvMode] || 'Generate CV';
            btn.disabled = false;
            btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg><span id="cv-btn-text">${label}</span>`;
            if (cancelBtn) cancelBtn.classList.add('hidden');
        }

        async function runGenerateCv() {
            const btn = document.getElementById('btn-generate-cv');
            const cancelBtn = document.getElementById('btn-cancel-cv');
            const badge = document.getElementById('cv-status-badge');
            const jd = document.getElementById('cv-jd-input')?.value?.trim() || '';

            if (['tailor','ats'].includes(cvMode) && !jd) {
                Swal.fire('Perlu JD', 'Paste job description dulu sebelum generate.', 'warning'); return;
            }

            cvAbortController = new AbortController();

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner animate-spin"></i> Generating...';
            if (cancelBtn) cancelBtn.classList.remove('hidden');
            badge.textContent = 'Generating...';
            badge.className = 'text-[10px] font-semibold px-2 py-1 rounded-full bg-yellow-100 text-yellow-700';

            const csrf = document.getElementById('settings-csrf').value;
            const fd = new FormData();
            fd.append('action', 'generate_cv');
            fd.append('is_ajax', '1');
            fd.append('csrf_token', csrf);
            fd.append('mode', cvMode);
            fd.append('lang', cvLang);
            fd.append('job_description', jd);
            if (cvData) fd.append('existing_cv', JSON.stringify(cvData));

            let res;
            try {
                res = await fetch('process.php', { method: 'POST', body: fd, signal: cvAbortController.signal }).then(r => r.json());
            } catch (e) {
                cvBtnIdle();
                if (e.name === 'AbortError') {
                    badge.textContent = 'Dibatalkan';
                    badge.className = 'text-[10px] font-semibold px-2 py-1 rounded-full bg-slate-100 text-slate-500';
                } else {
                    badge.textContent = 'Error';
                    badge.className = 'text-[10px] font-semibold px-2 py-1 rounded-full bg-red-100 text-red-600';
                }
                return;
            }

            cvBtnIdle();

            if (res.status !== 'success') {
                badge.textContent = 'Gagal';
                badge.className = 'text-[10px] font-semibold px-2 py-1 rounded-full bg-red-100 text-red-600';
                Swal.fire('Gagal', res.message || 'Error tidak diketahui', 'error'); return;
            }

            cvData = res.data;
            badge.textContent = 'Selesai ✓';
            badge.className = 'text-[10px] font-semibold px-2 py-1 rounded-full bg-green-100 text-green-700';
            renderCvPreview(cvData);
            loadCvHistory();
        }

        function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

        const CV_LABEL_DEFAULTS = {
            id: { section_summary: 'Profil Profesional', section_skills: 'Keahlian Teknis', section_experience: 'Pengalaman Kerja',
                  section_projects: 'Proyek Unggulan', section_education: 'Pendidikan', present: 'Sekarang',
                  portfolio: 'Portofolio', tech: 'Tech', results: 'Hasil',
                  ph_location: 'Lokasi', ph_phone: 'Telepon', ph_email: 'Email', ph_linkedin: 'LinkedIn', ph_portfolio: 'URL' },
            en: { section_summary: 'Professional Summary', section_skills: 'Technical Skills', section_experience: 'Work Experience',
                  section_projects: 'Key Projects', section_education: 'Education', present: 'Present',
                  portfolio: 'Portfolio', tech: 'Tech', results: 'Result',
                  ph_location: 'Location', ph_phone: 'Phone', ph_email: 'Email', ph_linkedin: 'LinkedIn', ph_portfolio: 'URL' }
        };

        // Merges defaults into d.labels so every label is editable and always has a value
        function cvLabels(d) {
            const base = CV_LABEL_DEFAULTS[d._cv_lang === 'en' ? 'en' : 'id'];
            d.labels = Object.assign({}, base, d.labels || {});
            return d.labels;
        }

        // One editable contact slot — empty slots stay visible via placeholder so they can be filled in
        function cvSlot(path, val, ph) {
            return `<span class="cv-slot" contenteditable="true" data-cv-path="${path}" data-ph="${esc(ph)}">${esc(val)}</span>`;
        }

        // A real text node, not a CSS pseudo-element, so the bullet is copyable and lands in
        // exported text exactly where it is displayed
        function cvSep() { return `<span class="cv-sep"> • </span>`; }

        // contenteditable keeps a stray <br> when the user clears it, which defeats :empty and
        // would leave the placeholder and the stored value disagreeing
        function _cvNormalizeSlot(el) {
            if (el.textContent.trim() === '' && el.innerHTML !== '') el.innerHTML = '';
        }

        // Bound once — #cv-preview persists, only its innerHTML is replaced
        function bindCvPreviewEvents() {
            const pv = document.getElementById('cv-preview');
            if (!pv || pv.dataset.cvBound) return;
            pv.dataset.cvBound = '1';
            pv.addEventListener('input', ev => {
                const slot = ev.target.closest ? ev.target.closest('.cv-slot') : null;
                if (slot) _cvNormalizeSlot(slot);
            });
        }

        // Drop scheme/www/tracking params so links read like "linkedin.com/in/name", never a share URL
        function cvCleanUrl(u) {
            return String(u || '')
                .replace(/^https?:\/\//i, '')
                .replace(/^www\./i, '')
                .split(/[?#]/)[0]
                .replace(/\/+$/, '')
                .trim();
        }

        function cvCleanPhone(p) {
            const raw = String(p || '').trim();
            const digits = raw.replace(/[^\d]/g, '');
            const m = digits.match(/^(?:62|0)(\d{8,13})$/);
            if (!m) return raw;
            return ('0' + m[1]).replace(/^(\d{4})(\d{4})(\d+)$/, '$1-$2-$3');
        }

        function cvCleanContact(d) {
            const p = d.personal || (d.personal = {});
            p.location  = String(p.location || '').trim();
            p.email     = String(p.email || '').trim();
            p.phone     = cvCleanPhone(p.phone);
            p.linkedin  = cvCleanUrl(p.linkedin);
            p.portfolio = cvCleanUrl(p.portfolio);
            return p;
        }

        function renderCvPreview(d) {
            const L = cvLabels(d);
            const p = cvCleanContact(d);

            // Contact lines — every slot always renders (empty ones as a grey hint), so the
            // separators are unconditional here; the PDF/Word builders drop empty slots instead
            const line1 = cvSlot('personal.location', p.location, L.ph_location) + cvSep()
                        + cvSlot('personal.phone', p.phone, L.ph_phone) + cvSep()
                        + cvSlot('personal.email', p.email, L.ph_email);
            const line2 = cvSlot('personal.linkedin', p.linkedin, L.ph_linkedin) + cvSep()
                        + `<span class="cv-portfolio">`
                        + `<span class="cv-slot" contenteditable="true" data-cv-path="labels.portfolio" data-ph="${esc(L.portfolio)}">${esc(L.portfolio)}</span>: `
                        + cvSlot('personal.portfolio', p.portfolio, L.ph_portfolio)
                        + `</span>`;

            // Skills — category label editable, skill list editable as one string
            let skillsHtml = '';
            (d.skills || []).forEach((s, si) => {
                skillsHtml += `<li><strong><span contenteditable="true" data-cv-path="skills.${si}.category_label">${esc(s.category)}</span>:</strong> <span contenteditable="true" data-cv-path="skills.${si}.skills_text">${esc((s.skills||[]).join(', '))}</span>.</li>`;
            });

            // Experience — all text fields editable
            let expHtml = '';
            (d.experience || []).forEach((ex, ei) => {
                const sd = esc(ex.start_date||'');
                const ed = ex.end_date ? esc(ex.end_date) : esc(L.present);
                const bullets = [...(ex.responsibilities||[]).map((b,bi)=>({b,path:`experience.${ei}.responsibilities.${bi}`})),
                                 ...(ex.achievements||[]).map((b,bi)=>({b,path:`experience.${ei}.achievements.${bi}`}))];
                expHtml += `
                    <div class="cv-exp-row">
                        <span class="cv-company" contenteditable="true" data-cv-path="experience.${ei}.company">${esc(ex.company||'')}</span>
                        <span class="cv-period"><span contenteditable="true" data-cv-path="experience.${ei}.start_date">${sd}</span> – <span contenteditable="true" data-cv-path="experience.${ei}.end_date">${ed}</span></span>
                    </div>
                    <div class="cv-role" contenteditable="true" data-cv-path="experience.${ei}.position">${esc(ex.position||'')}</div>
                    <ul class="cv-bullets">${bullets.map(({b,path}) => `<li contenteditable="true" data-cv-path="${path}">${esc(b)}</li>`).join('')}</ul>`;
            });

            // Projects — each text component editable separately
            let projHtml = '';
            (d.projects || []).forEach((pr, pi) => {
                const clientPart = pr.client
                    ? ` (<span contenteditable="true" data-cv-path="projects.${pi}.client">${esc(pr.client)}</span>)`
                    : '';
                const techPart = pr.technologies
                    ? ` <span contenteditable="true" data-cv-path="labels.tech">${esc(L.tech)}</span>: <span contenteditable="true" data-cv-path="projects.${pi}.technologies">${esc(pr.technologies)}</span>.`
                    : '';
                const hasilPart = pr.results
                    ? `<span class="cv-proj-hasil"><strong><span contenteditable="true" data-cv-path="labels.results">${esc(L.results)}</span>:</strong> <span contenteditable="true" data-cv-path="projects.${pi}.results">${esc(pr.results)}</span>.</span>`
                    : '';
                projHtml += `<li>
                    <strong><span contenteditable="true" data-cv-path="projects.${pi}.project_name">${esc(pr.project_name||'')}</span></strong>${clientPart}:
                    <span contenteditable="true" data-cv-path="projects.${pi}.description">${esc(pr.description||'')}</span>${techPart}${hasilPart}
                </li>`;
            });

            // Education — institution, degree, year editable
            let eduHtml = '';
            (d.education || []).forEach((edu, ki) => {
                const yr = edu.year || edu.graduation_year || '';
                eduHtml += `
                    <div class="cv-edu-row">
                        <span class="cv-institution" contenteditable="true" data-cv-path="education.${ki}.institution">${esc(edu.institution||'')}</span>
                        <span class="cv-edu-year" contenteditable="true" data-cv-path="education.${ki}.year">${esc(yr)}</span>
                    </div>
                    <div class="cv-degree" contenteditable="true" data-cv-path="education.${ki}.degree">${esc(edu.degree||'')}</div>`;
            });

            document.getElementById('cv-preview').innerHTML = `
                <div class="cv-header-block">
                    <div class="cv-name" contenteditable="true" data-cv-path="personal.full_name">${esc(p.full_name||'')}</div>
                    <div class="cv-title" contenteditable="true" data-cv-path="personal.professional_title">${esc(p.professional_title||'')}</div>
                    <div class="cv-contact">${line1}</div>
                    <div class="cv-contact">${line2}</div>
                </div>

                ${d.summary ? `<div class="cv-section-title" contenteditable="true" data-cv-path="labels.section_summary">${esc(L.section_summary)}</div><p class="cv-summary" contenteditable="true" data-cv-path="summary">${esc(d.summary)}</p>` : ''}

                ${skillsHtml ? `<div class="cv-section-title" contenteditable="true" data-cv-path="labels.section_skills">${esc(L.section_skills)}</div><ul class="cv-skills-list">${skillsHtml}</ul>` : ''}

                ${expHtml ? `<div class="cv-section-title" contenteditable="true" data-cv-path="labels.section_experience">${esc(L.section_experience)}</div>${expHtml}` : ''}

                ${projHtml ? `<div class="cv-section-title" contenteditable="true" data-cv-path="labels.section_projects">${esc(L.section_projects)}</div><ul class="cv-proj-list">${projHtml}</ul>` : ''}

                ${eduHtml ? `<div class="cv-section-title" contenteditable="true" data-cv-path="labels.section_education">${esc(L.section_education)}</div>${eduHtml}` : ''}
            `;

            bindCvPreviewEvents();

            // Show edit hint in header
            const hint = document.getElementById('cv-edit-hint');
            if (hint) hint.classList.remove('hidden');
        }

        function _setCvPath(obj, path, val) {
            const parts = path.split('.');
            let cur = obj;
            for (let i = 0; i < parts.length - 1; i++) {
                const k = isNaN(parts[i]) ? parts[i] : parseInt(parts[i]);
                if (cur == null) return;
                if (cur[k] == null) cur[k] = isNaN(parts[i+1]) ? {} : [];
                cur = cur[k];
            }
            const last = parts[parts.length - 1];
            if (cur != null) cur[isNaN(last) ? last : parseInt(last)] = val;
        }

        function syncCvEdits() {
            if (!cvData) return;
            document.querySelectorAll('#cv-preview [data-cv-path]').forEach(el => {
                const path = el.dataset.cvPath;
                _cvNormalizeSlot(el);
                const val  = el.textContent.trim();
                // skills_text needs to be split back to array
                if (path.endsWith('.skills_text')) {
                    const arrPath = path.replace('.skills_text', '.skills');
                    _setCvPath(cvData, arrPath, val.split(/\s*,\s*/).filter(Boolean));
                } else if (path.endsWith('.category_label')) {
                    const catPath = path.replace('.category_label', '.category');
                    _setCvPath(cvData, catPath, val);
                } else {
                    _setCvPath(cvData, path, val);
                }
            });
            cvCleanContact(cvData);
        }

        function downloadCvPdf() {
            if (!cvData) { Swal.fire('Belum ada CV', 'Generate CV dulu sebelum download.', 'warning'); return; }
            syncCvEdits();
            const html = buildCvPrintHtml(cvData);
            const win = window.open('', '_blank');
            if (!win) {
                Swal.fire('Popup Diblokir', 'Izinkan popup di browser ini lalu coba lagi.', 'warning');
                return;
            }
            win.document.open();
            win.document.write(html);
            win.document.close();
            win.focus();
            /* auto-scale from parent: wait for fonts, then measure child body height and zoom to fit A4 */
            setTimeout(function() {
                try {
                    var PAGE_H = (297 - 24) * 96 / 25.4;
                    var bd = win.document.body;
                    var h = bd.scrollHeight;
                    if (h > PAGE_H * 1.003) {
                        bd.style.zoom = (PAGE_H * 0.999 / h);
                    }
                } catch(ex) {}
                win.print();
            }, 500);
        }

        function buildCvPrintHtml(d) {
            const p = cvCleanContact(d);
            const L = cvLabels(d);
            const e = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            const sec = t => `<h2>${t}</h2>`;

            // HEADER — centered
            let body = `<header>
                <h1>${e(p.full_name||'')}</h1>
                <p class="cv-title">${e(p.professional_title||'')}</p>`;
            const l1 = [p.location, p.phone, p.email].filter(Boolean).join(' • ');
            const l2 = [p.linkedin, p.portfolio ? L.portfolio + ': ' + p.portfolio : ''].filter(Boolean).join(' • ');
            if (l1) body += `<p class="cv-contact">${e(l1)}</p>`;
            if (l2) body += `<p class="cv-contact">${e(l2)}</p>`;
            body += `</header>`;

            // PROFIL PROFESIONAL
            if (d.summary) {
                body += `<section aria-label="${e(L.section_summary)}">${sec(e(L.section_summary))}
                    <p class="summary">${e(d.summary)}</p></section>`;
            }

            // KEAHLIAN TEKNIS
            if ((d.skills||[]).length) {
                body += `<section aria-label="${e(L.section_skills)}">${sec(e(L.section_skills))}<ul>`;
                (d.skills||[]).forEach(s => {
                    body += `<li><strong>${e(s.category)}:</strong> ${e((s.skills||[]).join(', '))}.</li>`;
                });
                body += `</ul></section>`;
            }

            // PENGALAMAN KERJA
            if ((d.experience||[]).length) {
                body += `<section aria-label="${e(L.section_experience)}">${sec(e(L.section_experience))}`;
                (d.experience||[]).forEach(exp => {
                    const period = exp.end_date
                        ? `${e(exp.start_date)} – ${e(exp.end_date)}`
                        : `${e(exp.start_date)} – ${e(L.present)}`;
                    const bullets = [...(exp.responsibilities||[]), ...(exp.achievements||[])];
                    body += `<div class="exp-block">
                        <div class="exp-row">
                            <strong class="exp-company">${e(exp.company)}</strong>
                            <time class="exp-period">${period}</time>
                        </div>
                        <p class="exp-role">${e(exp.position)}</p>
                        <ul>${bullets.map(b=>`<li>${e(b)}</li>`).join('')}</ul>
                    </div>`;
                });
                body += `</section>`;
            }

            // PROYEK UNGGULAN
            if ((d.projects||[]).length) {
                body += `<section aria-label="${e(L.section_projects)}">${sec(e(L.section_projects))}<ul>`;
                (d.projects||[]).forEach(pr => {
                    const client = pr.client ? ` (${e(pr.client)})` : '';
                    const desc   = pr.description ? ` ${e(pr.description)}` : '';
                    const tech   = pr.technologies ? ` ${e(L.tech)}: ${e(pr.technologies)}.` : '';
                    const hasil  = pr.results
                        ? `<span class="proj-hasil"><strong>${e(L.results)}:</strong> ${e(pr.results)}.</span>`
                        : '';
                    body += `<li><strong>${e(pr.project_name)}</strong>${client}:${desc}${tech}${hasil}</li>`;
                });
                body += `</ul></section>`;
            }

            // PENDIDIKAN
            if ((d.education||[]).length) {
                body += `<section aria-label="${e(L.section_education)}">${sec(e(L.section_education))}`;
                (d.education||[]).forEach(edu => {
                    const yr = edu.year || edu.graduation_year || '';
                    body += `<div class="exp-block">
                        <div class="exp-row">
                            <strong class="exp-company">${e(edu.institution||'')}</strong>
                            <time class="exp-period">${e(yr)}</time>
                        </div>
                        <p class="edu-degree">${e(edu.degree||'')}</p>
                    </div>`;
                });
                body += `</section>`;
            }

            const name = e(p.full_name||'CV');
            return `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="description" content="CV – ${name}">
<title>CV – ${name}</title>
<style>
@page { size: A4 portrait; margin: 12mm 18mm 12mm 18mm; }
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { background: #fff; }
body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 9.5pt; line-height: 1.42; color: #000;
    width: 174mm; /* 210mm - 2×18mm — forces correct wrap width regardless of window size */
    margin: 0 auto;
}
header { text-align: center; margin-bottom: 5pt; }
h1 { font-size: 14pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.7pt; margin: 0 0 2pt; }
.cv-title  { font-size: 10pt; font-weight: 700; margin: 0 0 2pt; }
.cv-contact { font-size: 9pt; color: #333; margin: 0.5pt 0; }
h2 {
    font-size: 8.5pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8pt;
    border-bottom: 0.75pt solid #000; padding-bottom: 1pt; margin: 7.5pt 0 3pt;
    page-break-after: avoid;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
p.summary { font-size: 9.5pt; line-height: 1.45; }
ul  { padding-left: 11pt; margin: 1pt 0 0; }
li  { font-size: 9.5pt; margin-bottom: 1pt; line-height: 1.42; }
.exp-block { margin-top: 4pt; page-break-inside: avoid; }
.exp-row   { display: flex; justify-content: space-between; align-items: baseline; }
.exp-company { font-size: 10pt; font-weight: 700; }
.exp-period  { font-size: 9pt; color: #444; font-style: italic; white-space: nowrap; margin-left: 5pt; }
.exp-role    { font-weight: 700; font-size: 9.5pt; margin: 0.5pt 0 1.5pt; }
.proj-hasil  { display: block; font-size: 9pt; color: #1a3a6b; margin-top: 0.5pt; }
.edu-degree  { font-size: 9pt; color: #333; margin: 1pt 0 0; }
@media print {
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
.no-print { background: #1e3a5f; color: #fff; padding: 5px 16px; font-size: 9px; text-align: center; }
@media print { .no-print { display: none; } }
</style>
</head>
<body>
<div class="no-print">
    <strong>PDF ATS-Friendly:</strong> Di dialog cetak &rarr; Destination <strong>Save as PDF</strong> &rarr; More settings &rarr; matikan &ldquo;Headers and footers&rdquo; &rarr; <strong>Save</strong>
</div>
${body}
</body>
</html>`;
        }

        function downloadCvWord() {
            if (!cvData) { Swal.fire('Belum ada CV', 'Generate CV dulu sebelum download.', 'warning'); return; }
            syncCvEdits();
            const name = (cvData.personal?.full_name || 'CV').replace(/\s+/g, '_');
            const html = buildCvWordHtml(cvData);
            const blob = new Blob(['﻿' + html], { type: 'application/msword' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'CV_' + name + '.doc';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        }

        function buildCvWordHtml(d) {
            const p = cvCleanContact(d);
            const L = cvLabels(d);
            const e = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

            // Section title helper — matches PDF (8.5pt, tight margin)
            const sec = t => `<p style="font-size:8.5pt;font-weight:800;text-transform:uppercase;letter-spacing:0.8pt;border-bottom:0.75pt solid #000;padding-bottom:1pt;margin:7.5pt 0 3pt">${t}</p>`;

            // Row helper — company/institution LEFT + date RIGHT via 2-col table (Word-safe)
            const row = (left, right) => `<table style="width:100%;border-collapse:collapse;margin-top:4pt">
                <tr>
                    <td style="padding:0;vertical-align:bottom;font-weight:700;font-size:10pt">${left}</td>
                    <td style="padding:0;vertical-align:bottom;text-align:right;white-space:nowrap;font-size:9pt;font-style:italic;color:#444">${right}</td>
                </tr>
            </table>`;

            // HEADER — centered, matches PDF
            let body = `<div style="text-align:center;margin-bottom:5pt">
                <p style="font-size:14pt;font-weight:800;text-transform:uppercase;letter-spacing:0.7pt;margin:0 0 2pt">${e(p.full_name||'')}</p>
                <p style="font-size:10pt;font-weight:700;margin:0 0 2pt">${e(p.professional_title||'')}</p>`;
            const l1 = [p.location, p.phone, p.email].filter(Boolean).join(' • ');
            const l2 = [p.linkedin, p.portfolio ? L.portfolio + ': ' + p.portfolio : ''].filter(Boolean).join(' • ');
            if (l1) body += `<p style="font-size:9pt;color:#333;margin:0.5pt 0">${e(l1)}</p>`;
            if (l2) body += `<p style="font-size:9pt;color:#333;margin:0.5pt 0">${e(l2)}</p>`;
            body += `</div>`;

            // PROFIL PROFESIONAL
            if (d.summary) {
                body += sec(e(L.section_summary));
                body += `<p style="font-size:9.5pt;line-height:1.45;margin:0">${e(d.summary)}</p>`;
            }

            // KEAHLIAN TEKNIS
            if ((d.skills||[]).length) {
                body += sec(e(L.section_skills));
                body += `<ul style="margin:1pt 0 0;padding-left:11pt">`;
                (d.skills||[]).forEach(s => {
                    body += `<li style="font-size:9.5pt;margin-bottom:1pt;line-height:1.42"><strong>${e(s.category)}:</strong> ${e((s.skills||[]).join(', '))}.</li>`;
                });
                body += `</ul>`;
            }

            // PENGALAMAN KERJA
            if ((d.experience||[]).length) {
                body += sec(e(L.section_experience));
                (d.experience||[]).forEach(exp => {
                    const period = exp.end_date
                        ? `${e(exp.start_date)} – ${e(exp.end_date)}`
                        : `${e(exp.start_date)} – ${e(L.present)}`;
                    const bullets = [...(exp.responsibilities||[]), ...(exp.achievements||[])];
                    body += row(e(exp.company), period);
                    body += `<p style="font-weight:700;font-size:9.5pt;margin:0.5pt 0 1.5pt">${e(exp.position)}</p>`;
                    body += `<ul style="margin:1pt 0 2pt;padding-left:11pt">`;
                    bullets.forEach(b => { body += `<li style="font-size:9.5pt;margin-bottom:1pt;line-height:1.42">${e(b)}</li>`; });
                    body += `</ul>`;
                });
            }

            // PROYEK UNGGULAN
            if ((d.projects||[]).length) {
                body += sec(e(L.section_projects));
                body += `<ul style="margin:1pt 0 0;padding-left:11pt">`;
                (d.projects||[]).forEach(pr => {
                    const client = pr.client ? ` (${e(pr.client)})` : '';
                    const desc   = pr.description ? ` ${e(pr.description)}` : '';
                    const tech   = pr.technologies ? ` ${e(L.tech)}: ${e(pr.technologies)}.` : '';
                    const hasil  = pr.results
                        ? `<p style="font-size:9pt;color:#1a3a6b;margin:0.5pt 0 0;padding-left:2pt"><strong>${e(L.results)}:</strong> ${e(pr.results)}.</p>`
                        : '';
                    body += `<li style="font-size:9.5pt;margin-bottom:1.5pt;line-height:1.42"><strong>${e(pr.project_name)}</strong>${client}:${desc}${tech}${hasil}</li>`;
                });
                body += `</ul>`;
            }

            // PENDIDIKAN
            if ((d.education||[]).length) {
                body += sec(e(L.section_education));
                (d.education||[]).forEach(edu => {
                    const yr = edu.year || edu.graduation_year || '';
                    body += row(e(edu.institution||''), e(yr));
                    body += `<p style="font-size:9.5pt;color:#333;margin:1pt 0 0">${e(edu.degree||'')}</p>`;
                });
            }

            /* Word-compatible envelope.
               Margins: 15mm T/B × 20mm L/R — identical to PDF @page.
               Font: Calibri (Word default, ATS-safe).
               No display:flex (not Word-safe). Tables only for alignment. */
            return `<html xmlns:o='urn:schemas-microsoft-com:office:office'
     xmlns:w='urn:schemas-microsoft-com:office:word'
     xmlns='http://www.w3.org/TR/REC-html40'>
<head>
<meta charset='utf-8'>
<title>CV</title>
<!--[if gte mso 9]><xml>
<w:WordDocument>
  <w:View>Normal</w:View>
  <w:Zoom>100</w:Zoom>
  <w:DoNotOptimizeForBrowser/>
</w:WordDocument>
</xml><![endif]-->
<style>
@page {
    size: 210mm 297mm;
    margin: 15mm 20mm 15mm 20mm;
    mso-page-orientation: portrait;
}
* { box-sizing: border-box; }
body {
    font-family: Calibri, Arial, sans-serif;
    font-size: 10pt;
    color: #000;
    line-height: 1.46;
    background: #fff;
}
p  { margin: 0; }
table { width: 100%; border-collapse: collapse; }
ul { margin: 0; padding-left: 12pt; }
li { margin-bottom: 1.5pt; line-height: 1.46; }
</style>
</head>
<body>${body}</body>
</html>`;
        }

        // ── CV History ────────────────────────────────────────────────────────

        const cvModeLabels = { generate: 'Generate', rewrite: 'Rewrite', tailor: 'Tailor', ats: 'ATS' };
        const cvModeColors = { generate: 'bg-indigo-100 text-indigo-700', rewrite: 'bg-violet-100 text-violet-700', tailor: 'bg-blue-100 text-blue-700', ats: 'bg-emerald-100 text-emerald-700' };

        async function loadCvHistory() {
            const csrf = document.getElementById('settings-csrf').value;
            const fd = new FormData();
            fd.append('action', 'get_cv_history');
            fd.append('is_ajax', '1');
            fd.append('csrf_token', csrf);
            let res;
            try { res = await fetch('process.php', { method: 'POST', body: fd }).then(r => r.json()); }
            catch (e) { return; }
            if (res.status !== 'success') return;
            renderCvHistoryList(res.data || []);
        }

        function renderCvHistoryList(items) {
            const list = document.getElementById('cv-history-list');
            const badge = document.getElementById('cv-history-count');
            badge.textContent = items.length;
            if (!items.length) {
                list.innerHTML = '<div class="p-4 text-xs text-slate-400 text-center">Belum ada riwayat</div>';
                return;
            }
            list.innerHTML = items.map(item => {
                const modeClass = cvModeColors[item.mode] || 'bg-slate-100 text-slate-600';
                const modeLabel = cvModeLabels[item.mode] || item.mode;
                const langBadge = item.lang === 'en' ? '🇬🇧' : '🇮🇩';
                return `<div class="flex items-center gap-2 px-4 py-2.5 hover:bg-slate-50 group">
                    <div class="flex-1 min-w-0 cursor-pointer" onclick="loadCvHistoryItem(${item.id})">
                        <div class="text-xs font-semibold text-slate-700 truncate">${escHtml(item.label)}</div>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full ${modeClass}">${modeLabel}</span>
                            <span class="text-[10px]">${langBadge}</span>
                        </div>
                    </div>
                    <button onclick="renameCvHistory(${item.id}, this)" title="Rename"
                        class="opacity-0 group-hover:opacity-100 w-6 h-6 rounded-lg text-slate-400 hover:text-indigo-500 flex items-center justify-center transition">
                        <i class="fas fa-pencil-alt text-[9px]"></i>
                    </button>
                    <button onclick="deleteCvHistory(${item.id}, this)" title="Hapus"
                        class="opacity-0 group-hover:opacity-100 w-6 h-6 rounded-lg text-slate-400 hover:text-red-500 flex items-center justify-center transition">
                        <i class="fas fa-trash text-[9px]"></i>
                    </button>
                </div>`;
            }).join('');
        }

        function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        async function loadCvHistoryItem(id) {
            Swal.fire({ title: 'Memuat...', didOpen: () => Swal.showLoading() });
            const csrf = document.getElementById('settings-csrf').value;
            const fd = new FormData();
            fd.append('action', 'load_cv_history');
            fd.append('is_ajax', '1');
            fd.append('csrf_token', csrf);
            fd.append('history_id', id);
            let res;
            try { res = await fetch('process.php', { method: 'POST', body: fd }).then(r => r.json()); }
            catch (e) { Swal.fire('Error', 'Gagal memuat riwayat.', 'error'); return; }
            if (res.status !== 'success') { Swal.fire('Error', res.message, 'error'); return; }
            Swal.close();
            cvData = res.data;
            document.getElementById('cv-status-badge').textContent = res.message || 'Dimuat dari riwayat';
            document.getElementById('cv-status-badge').className = 'text-[10px] font-semibold px-2 py-1 rounded-full bg-blue-100 text-blue-700';
            renderCvPreview(cvData);
        }

        async function renameCvHistory(id, btn) {
            const row = btn.closest('.flex');
            const currentLabel = row.querySelector('.text-xs.font-semibold')?.textContent || '';
            const { value: newLabel } = await Swal.fire({
                title: 'Ubah nama riwayat',
                input: 'text', inputValue: currentLabel,
                showCancelButton: true, confirmButtonText: 'Simpan', cancelButtonText: 'Batal',
                confirmButtonColor: '#6366F1',
            });
            if (!newLabel?.trim()) return;
            const csrf = document.getElementById('settings-csrf').value;
            const fd = new FormData();
            fd.append('action', 'rename_cv_history');
            fd.append('is_ajax', '1');
            fd.append('csrf_token', csrf);
            fd.append('history_id', id);
            fd.append('label', newLabel.trim());
            try { await fetch('process.php', { method: 'POST', body: fd }); } catch {}
            loadCvHistory();
        }

        async function deleteCvHistory(id, btn) {
            const { isConfirmed } = await Swal.fire({
                title: 'Hapus riwayat ini?', icon: 'warning',
                showCancelButton: true, confirmButtonText: 'Hapus', cancelButtonText: 'Batal',
                confirmButtonColor: '#ef4444',
            });
            if (!isConfirmed) return;
            const csrf = document.getElementById('settings-csrf').value;
            const fd = new FormData();
            fd.append('action', 'delete_cv_history');
            fd.append('is_ajax', '1');
            fd.append('csrf_token', csrf);
            fd.append('history_id', id);
            try { await fetch('process.php', { method: 'POST', body: fd }); } catch {}
            btn.closest('.flex').remove();
            const count = document.getElementById('cv-history-count');
            count.textContent = Math.max(0, parseInt(count.textContent) - 1);
            if (parseInt(count.textContent) === 0) {
                document.getElementById('cv-history-list').innerHTML = '<div class="p-4 text-xs text-slate-400 text-center">Belum ada riwayat</div>';
            }
        }

        // ===== AI PROFILE GENERATOR =====
        let _aiDraft = null;

        function openAiProfileGenerator() {
            document.getElementById('ai-pg-overlay').style.display = 'block';
            document.body.style.overflow = 'hidden';
            _aiPgStep(1);
        }

        function closeAiPg() {
            document.getElementById('ai-pg-overlay').style.display = 'none';
            document.body.style.overflow = '';
        }

        function _aiPgStep(n) {
            document.getElementById('ai-pg-step1').style.display = n === 1 ? 'block' : 'none';
            document.getElementById('ai-pg-step2').style.display = n === 2 ? 'block' : 'none';
            [1,2,3].forEach(i => {
                const ind = document.getElementById('ai-step-ind-' + i);
                const active = i === n;
                ind.style.color = active ? '#7C3AED' : '#94A3B8';
                ind.style.borderBottomColor = active ? '#7C3AED' : 'transparent';
                ind.style.fontWeight = active ? '700' : '600';
            });
        }

        function aiPgBack() { _aiPgStep(1); }

        async function startAiGenerate() {
            const label = document.getElementById('ai-pg-label').value.trim();
            const role  = document.getElementById('ai-pg-role').value.trim();
            const jd    = document.getElementById('ai-pg-jd').value.trim();
            const base  = document.getElementById('ai-pg-base').value;
            const lang  = document.querySelector('input[name="ai-pg-lang"]:checked')?.value || 'id';

            if (!label) { Swal.fire('Lengkapi Data', 'Label profil wajib diisi.', 'warning'); return; }
            if (!role)  { Swal.fire('Lengkapi Data', 'Target role wajib diisi.', 'warning'); return; }
            if (!jd)    { Swal.fire('Lengkapi Data', 'Job description wajib diisi.', 'warning'); return; }

            const btn = document.getElementById('ai-pg-gen-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';

            const csrf = document.getElementById('settings-csrf').value;
            const fd = new FormData();
            fd.append('action', 'generate_profile_draft');
            fd.append('is_ajax', '1');
            fd.append('csrf_token', csrf);
            fd.append('profile_label', label);
            fd.append('target_role', role);
            fd.append('job_description', jd);
            fd.append('base_profile_id', base);
            fd.append('lang', lang);

            let res;
            try {
                res = await fetch('process.php', { method: 'POST', body: fd }).then(r => r.json());
            } catch(e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-magic"></i> Generate Profil AI';
                Swal.fire('Error', 'Koneksi gagal. Coba lagi.', 'error');
                return;
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-magic"></i> Generate Profil AI';

            if (res.status !== 'success') {
                Swal.fire('Gagal Generate', res.message || 'Error tidak diketahui', 'error');
                return;
            }

            _aiDraft = res.data;
            renderAiDraftPreview(_aiDraft, label, lang);
            _aiPgStep(2);
            document.getElementById('ai-pg-title').textContent = 'Review Profil: ' + label;
        }

        function _esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

        function renderAiDraftPreview(d, label, lang) {
            const p = d.profile || {};
            const skills = d.skills || [];
            const exps = d.experience || [];
            const chats = d.hero_chat || [];
            const arts = d.articles || [];

            let html = '';

            // ----- Section builder helper -----
            function section(icon, title, id, body) {
                return `<div style="border:1.5px solid #E2E8F0;border-radius:14px;margin-bottom:12px;overflow:hidden;">
                    <div onclick="_aiToggleSection('${id}')" style="padding:14px 18px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;background:#FAFAFA;">
                        <div style="display:flex;align-items:center;gap:10px;font-weight:700;font-size:14px;color:#1E293B;">
                            <span style="width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,#7C3AED20,#4F46E520);display:flex;align-items:center;justify-content:center;color:#7C3AED;font-size:13px;">${icon}</span>${title}
                        </div>
                        <i id="ai-sec-icon-${id}" class="fas fa-chevron-down" style="color:#94A3B8;font-size:12px;transition:transform .2s;"></i>
                    </div>
                    <div id="ai-sec-${id}" style="padding:18px;border-top:1px solid #F1F5F9;display:block;">${body}</div>
                </div>`;
            }

            function fld(id, label, value, multiline, rows) {
                const inputStyle = 'width:100%;padding:9px 12px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:13px;outline:none;box-sizing:border-box;';
                if (multiline) {
                    return `<div style="margin-bottom:12px;"><label style="font-size:11px;font-weight:700;color:#64748B;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">${_esc(label)}</label>
                    <textarea id="${id}" rows="${rows||3}" style="${inputStyle}resize:vertical;">${_esc(value)}</textarea></div>`;
                }
                return `<div style="margin-bottom:12px;"><label style="font-size:11px;font-weight:700;color:#64748B;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">${_esc(label)}</label>
                <input id="${id}" type="text" value="${_esc(value)}" style="${inputStyle}"></div>`;
            }

            // Profile section
            let profileBody = `<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">`;
            profileBody += fld('ai-p-hero_role', 'Hero Role (ID)', p.hero_role||'', false);
            profileBody += fld('ai-p-hero_role_en', 'Hero Role (EN)', p.hero_role_en||'', false);
            profileBody += '</div>';
            profileBody += fld('ai-p-bio', 'Bio (ID)', p.bio||'', true, 3);
            profileBody += fld('ai-p-bio_en', 'Bio (EN)', p.bio_en||'', true, 3);
            profileBody += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">`;
            profileBody += fld('ai-p-site_title', 'Site Title (ID)', p.site_title||'', false);
            profileBody += fld('ai-p-site_title_en', 'Site Title (EN)', p.site_title_en||'', false);
            profileBody += fld('ai-p-availability_text', 'Availability (ID)', p.availability_text||'', false);
            profileBody += fld('ai-p-availability_text_en', 'Availability (EN)', p.availability_text_en||'', false);
            profileBody += '</div>';
            html += section('<i class="fas fa-user"></i>', 'Informasi Profil', 'profile', profileBody);

            // Skills section
            let skillsBody = `<div id="ai-skills-list">`;
            skills.forEach((s, i) => {
                skillsBody += `<div style="display:grid;grid-template-columns:1.2fr 2fr 2fr;gap:8px;margin-bottom:8px;align-items:center;">
                    <input data-si="${i}" data-sf="category" type="text" value="${_esc(s.category||'')}" placeholder="Kategori" style="padding:8px 10px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:12px;outline:none;font-weight:700;">
                    <input data-si="${i}" data-sf="skill_name" type="text" value="${_esc(s.skill_name||'')}" placeholder="Nama Skill (ID)" style="padding:8px 10px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:12px;outline:none;">
                    <div style="display:flex;gap:6px;">
                        <input data-si="${i}" data-sf="skill_name_en" type="text" value="${_esc(s.skill_name_en||'')}" placeholder="Nama Skill (EN)" style="flex:1;padding:8px 10px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:12px;outline:none;">
                        <button onclick="_aiRemoveSkill(${i})" style="width:30px;height:30px;border-radius:8px;border:1.5px solid #FEE2E2;background:#FFF5F5;color:#EF4444;cursor:pointer;font-size:12px;flex-shrink:0;">✕</button>
                    </div>
                </div>`;
            });
            skillsBody += `</div>
            <button onclick="_aiAddSkill()" style="margin-top:8px;padding:8px 16px;border-radius:8px;border:1.5px dashed #7C3AED;background:transparent;color:#7C3AED;font-size:12px;font-weight:700;cursor:pointer;"><i class="fas fa-plus"></i> Tambah Skill</button>`;
            html += section('<i class="fas fa-tools"></i>', `Keahlian (${skills.length} skill)`, 'skills', skillsBody);

            // Experience section
            let expBody = '';
            exps.forEach((e, i) => {
                expBody += `<div style="padding:14px;background:#F8FAFC;border-radius:10px;margin-bottom:10px;border:1px solid #E2E8F0;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Perusahaan</label>
                        <input data-ei="${i}" data-ef="company" type="text" value="${_esc(e.company||'')}" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:13px;font-weight:700;outline:none;box-sizing:border-box;"></div>
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Periode</label>
                        <input data-ei="${i}" data-ef="year_range" type="text" value="${_esc(e.year_range||'')}" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:13px;outline:none;box-sizing:border-box;"></div>
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Role (ID)</label>
                        <input data-ei="${i}" data-ef="role" type="text" value="${_esc(e.role||'')}" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:13px;outline:none;box-sizing:border-box;"></div>
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Role (EN)</label>
                        <input data-ei="${i}" data-ef="role_en" type="text" value="${_esc(e.role_en||'')}" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:13px;outline:none;box-sizing:border-box;"></div>
                    </div>
                    <div style="margin-top:8px;"><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Deskripsi (ID)</label>
                    <textarea data-ei="${i}" data-ef="description" rows="3" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:12px;line-height:1.6;resize:vertical;outline:none;box-sizing:border-box;">${_esc(e.description||'')}</textarea></div>
                    <div style="margin-top:6px;"><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Deskripsi (EN)</label>
                    <textarea data-ei="${i}" data-ef="description_en" rows="3" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:12px;line-height:1.6;resize:vertical;outline:none;box-sizing:border-box;">${_esc(e.description_en||'')}</textarea></div>
                </div>`;
            });
            html += section('<i class="fas fa-briefcase"></i>', `Pengalaman (${exps.length} entri)`, 'experience', expBody);

            // Hero Chat section
            let chatBody = '';
            chats.forEach((c, i) => {
                chatBody += `<div style="padding:12px;background:#F8FAFC;border-radius:10px;margin-bottom:8px;border:1px solid #E2E8F0;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:6px;">
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Pertanyaan (ID)</label>
                        <input data-ci="${i}" data-cf="question" type="text" value="${_esc(c.question||'')}" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:13px;outline:none;box-sizing:border-box;"></div>
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Pertanyaan (EN)</label>
                        <input data-ci="${i}" data-cf="question_en" type="text" value="${_esc(c.question_en||'')}" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:13px;outline:none;box-sizing:border-box;"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Jawaban (ID)</label>
                        <textarea data-ci="${i}" data-cf="answer" rows="2" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:12px;line-height:1.5;resize:vertical;outline:none;box-sizing:border-box;">${_esc(c.answer||'')}</textarea></div>
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Jawaban (EN)</label>
                        <textarea data-ci="${i}" data-cf="answer_en" rows="2" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:12px;line-height:1.5;resize:vertical;outline:none;box-sizing:border-box;">${_esc(c.answer_en||'')}</textarea></div>
                    </div>
                </div>`;
            });
            html += section('<i class="fas fa-comments"></i>', `Hero Chat (${chats.length} Q&A)`, 'chat', chatBody);

            // Articles section
            let artBody = '';
            arts.forEach((a, i) => {
                artBody += `<div style="padding:12px;background:#F8FAFC;border-radius:10px;margin-bottom:8px;border:1px solid #E2E8F0;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Judul (ID)</label>
                        <input data-ari="${i}" data-arf="title" type="text" value="${_esc(a.title||'')}" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:13px;font-weight:600;outline:none;box-sizing:border-box;"></div>
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Judul (EN)</label>
                        <input data-ari="${i}" data-arf="title_en" type="text" value="${_esc(a.title_en||'')}" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:13px;font-weight:600;outline:none;box-sizing:border-box;"></div>
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Meta Desc (ID)</label>
                        <input data-ari="${i}" data-arf="meta_desc" type="text" value="${_esc(a.meta_desc||'')}" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:12px;outline:none;box-sizing:border-box;"></div>
                        <div><label style="font-size:10px;font-weight:700;color:#64748B;display:block;margin-bottom:3px;text-transform:uppercase;">Meta Desc (EN)</label>
                        <input data-ari="${i}" data-arf="meta_desc_en" type="text" value="${_esc(a.meta_desc_en||'')}" style="width:100%;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:12px;outline:none;box-sizing:border-box;"></div>
                    </div>
                </div>`;
            });
            html += section('<i class="fas fa-newspaper"></i>', `Artikel Blog (${arts.length} artikel — stub, isi konten nanti)`, 'articles', artBody);

            document.getElementById('ai-pg-sections').innerHTML = html;
        }

        function _aiToggleSection(id) {
            const el = document.getElementById('ai-sec-' + id);
            const ic = document.getElementById('ai-sec-icon-' + id);
            const hidden = el.style.display === 'none';
            el.style.display = hidden ? 'block' : 'none';
            ic.style.transform = hidden ? '' : 'rotate(-90deg)';
        }

        function _aiRemoveSkill(idx) {
            if (!_aiDraft || !_aiDraft.skills) return;
            _aiDraft.skills.splice(idx, 1);
            renderAiDraftPreview(_aiDraft,
                document.getElementById('ai-pg-label').value,
                document.querySelector('input[name="ai-pg-lang"]:checked')?.value || 'id');
        }

        function _aiAddSkill() {
            if (!_aiDraft) return;
            if (!_aiDraft.skills) _aiDraft.skills = [];
            _aiDraft.skills.push({ category: '', skill_name: '', skill_name_en: '' });
            renderAiDraftPreview(_aiDraft,
                document.getElementById('ai-pg-label').value,
                document.querySelector('input[name="ai-pg-lang"]:checked')?.value || 'id');
            // scroll skills section into view
            const el = document.getElementById('ai-sec-skills');
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function _aiCollectDraft() {
            // Collect profile fields
            const pFields = ['hero_role','hero_role_en','bio','bio_en','site_title','site_title_en','availability_text','availability_text_en'];
            const prof = {};
            pFields.forEach(f => {
                const el = document.getElementById('ai-p-' + f);
                if (el) prof[f] = el.value;
            });

            // Collect skills
            const skillInputs = document.querySelectorAll('#ai-skills-list [data-si]');
            const skillsMap = {};
            skillInputs.forEach(el => {
                const i = el.dataset.si, f = el.dataset.sf;
                if (!skillsMap[i]) skillsMap[i] = {};
                skillsMap[i][f] = el.value;
            });
            const skills = Object.values(skillsMap).filter(s => s.skill_name);

            // Collect experience
            const expInputs = document.querySelectorAll('[data-ei]');
            const expMap = {};
            expInputs.forEach(el => {
                const i = el.dataset.ei, f = el.dataset.ef;
                if (!expMap[i]) expMap[i] = {};
                expMap[i][f] = el.value;
            });
            const experience = Object.values(expMap).filter(e => e.company);

            // Collect hero_chat
            const chatInputs = document.querySelectorAll('[data-ci]');
            const chatMap = {};
            chatInputs.forEach(el => {
                const i = el.dataset.ci, f = el.dataset.cf;
                if (!chatMap[i]) chatMap[i] = {};
                chatMap[i][f] = el.value;
            });
            const hero_chat = Object.values(chatMap).filter(c => c.question);

            // Collect articles
            const artInputs = document.querySelectorAll('[data-ari]');
            const artMap = {};
            artInputs.forEach(el => {
                const i = el.dataset.ari, f = el.dataset.arf;
                if (!artMap[i]) artMap[i] = {};
                artMap[i][f] = el.value;
            });
            const articles = Object.values(artMap).filter(a => a.title);

            return { profile: prof, skills, experience, hero_chat, articles };
        }

        async function saveAiProfile() {
            const label      = document.getElementById('ai-pg-label').value.trim();
            const base       = document.getElementById('ai-pg-base').value;
            const copyProj   = document.getElementById('ai-pg-copy-proj').checked ? '1' : '0';
            const finalDraft = _aiCollectDraft();

            if (!label) { Swal.fire('Error', 'Label profil tidak boleh kosong.', 'warning'); return; }
            if (!finalDraft.skills.length && !finalDraft.experience.length) {
                Swal.fire('Periksa Data', 'Skills dan pengalaman tidak boleh kosong.', 'warning'); return;
            }

            const saveBtn = document.getElementById('ai-pg-save-btn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';

            const csrf = document.getElementById('settings-csrf').value;
            const fd = new FormData();
            fd.append('action', 'save_profile_draft');
            fd.append('is_ajax', '1');
            fd.append('csrf_token', csrf);
            fd.append('profile_label', label);
            fd.append('base_profile_id', base);
            fd.append('copy_projects', copyProj);
            fd.append('draft', JSON.stringify(finalDraft));

            let res;
            try {
                res = await fetch('process.php', { method: 'POST', body: fd }).then(r => r.json());
            } catch(e) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Simpan Profil Baru';
                Swal.fire('Error', 'Koneksi gagal. Coba lagi.', 'error');
                return;
            }

            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save"></i> Simpan Profil Baru';

            if (res.status !== 'success') {
                Swal.fire('Gagal Simpan', res.message || 'Error tidak diketahui.', 'error');
                return;
            }

            closeAiPg();
            sessionStorage.setItem('_admin_goto_tab', 'profiles');
            location.reload();
        }
        // ===== END AI PROFILE GENERATOR =====

        function openCreateProfile() {
            Swal.fire({
                title: 'Buat Profil Baru',
                html: `<div style="text-align:left">
                    <label style="font-size:12px;font-weight:bold;display:block;margin-bottom:4px">Label Profil <span style="color:#EF4444">*</span></label>
                    <input id="swal-label" class="swal2-input" placeholder="cth: UI/UX Designer, Backend Dev">
                    <label style="font-size:12px;font-weight:bold;display:block;margin:12px 0 4px">Nama Lengkap <span style="color:#EF4444">*</span></label>
                    <input id="swal-name" class="swal2-input" placeholder="Nama yang tampil di profil ini">
                    <label style="font-size:12px;font-weight:bold;display:block;margin:12px 0 4px">Hero Role</label>
                    <input id="swal-role" class="swal2-input" placeholder="cth: Product Designer">
                    <p style="font-size:11px;color:#94A3B8;margin-top:10px">Profil baru dibuat kosong. Isi konten via tab masing-masing.</p>
                </div>`,
                showCancelButton: true, confirmButtonText: 'Buat Profil', cancelButtonText: 'Batal',
                preConfirm: () => {
                    const label = document.getElementById('swal-label').value.trim();
                    const name  = document.getElementById('swal-name').value.trim();
                    const role  = document.getElementById('swal-role').value.trim();
                    if (!label || !name) { Swal.showValidationMessage('Label dan Nama wajib diisi'); return false; }
                    return {label, name, role};
                }
            }).then(r => {
                if (!r.isConfirmed) return;
                const {label, name, role} = r.value;
                fetch('process.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=create_profile&profile_label=${encodeURIComponent(label)}&name=${encodeURIComponent(name)}&hero_role=${encodeURIComponent(role)}`
                }).then(r => r.json()).then(d => {
                    if (d.status === 'success') {
                        sessionStorage.setItem('_admin_goto_tab', 'profiles');
                        location.reload();
                    } else Swal.fire('Error', d.message, 'error');
                });
            });
        }
    </script>

    <!-- ===== AI PROFILE GENERATOR OVERLAY ===== -->
    <div id="ai-pg-overlay" style="display:none;position:fixed;inset:0;z-index:1200;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);overflow-y:auto;padding:32px 16px;">
      <div style="max-width:720px;margin:0 auto;background:#fff;border-radius:24px;box-shadow:0 24px 80px rgba(0,0,0,0.25);overflow:hidden;">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#7C3AED,#4F46E5);padding:24px 28px;display:flex;align-items:center;justify-content:space-between;">
          <div>
            <div style="color:rgba(255,255,255,0.7);font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:4px">AI Profile Generator</div>
            <div style="color:#fff;font-size:18px;font-weight:800" id="ai-pg-title">Generate Profil dari Job Description</div>
          </div>
          <button onclick="closeAiPg()" style="width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,0.15);border:none;color:#fff;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-times"></i></button>
        </div>

        <!-- Step indicators -->
        <div style="display:flex;gap:0;border-bottom:1px solid #E2E8F0;">
          <div id="ai-step-ind-1" style="flex:1;padding:12px;text-align:center;font-size:12px;font-weight:700;color:#7C3AED;border-bottom:2px solid #7C3AED;">1. Input JD</div>
          <div id="ai-step-ind-2" style="flex:1;padding:12px;text-align:center;font-size:12px;font-weight:600;color:#94A3B8;border-bottom:2px solid transparent;">2. Review & Edit</div>
          <div id="ai-step-ind-3" style="flex:1;padding:12px;text-align:center;font-size:12px;font-weight:600;color:#94A3B8;border-bottom:2px solid transparent;">3. Simpan</div>
        </div>

        <!-- STEP 1: Input form -->
        <div id="ai-pg-step1" style="padding:28px;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div>
              <label style="font-size:12px;font-weight:700;color:#475569;display:block;margin-bottom:6px">Label Profil <span style="color:#EF4444">*</span></label>
              <input id="ai-pg-label" type="text" placeholder="cth: Backend Engineer, UI/UX Designer" style="width:100%;padding:10px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;outline:none;box-sizing:border-box;" onfocus="this.style.borderColor='#7C3AED'" onblur="this.style.borderColor='#E2E8F0'">
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:#475569;display:block;margin-bottom:6px">Target Role <span style="color:#EF4444">*</span></label>
              <input id="ai-pg-role" type="text" placeholder="cth: Senior Backend Developer" style="width:100%;padding:10px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;outline:none;box-sizing:border-box;" onfocus="this.style.borderColor='#7C3AED'" onblur="this.style.borderColor='#E2E8F0'">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div>
              <label style="font-size:12px;font-weight:700;color:#475569;display:block;margin-bottom:6px">Profil Dasar (data referensi)</label>
              <select id="ai-pg-base" style="width:100%;padding:10px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;outline:none;box-sizing:border-box;background:#fff;">
                <?php foreach ($allProfiles as $p): ?>
                <option value="<?php echo $p['id']; ?>"<?php echo $p['id']==$editingProfileId?' selected':''; ?>><?php echo htmlspecialchars($p['profile_label'] ?: $p['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:#475569;display:block;margin-bottom:6px">Bahasa Output</label>
              <div style="display:flex;gap:8px;padding-top:4px;">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:14px;font-weight:600;">
                  <input type="radio" name="ai-pg-lang" value="id" checked style="accent-color:#7C3AED;width:16px;height:16px;"> Bahasa Indonesia
                </label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:14px;font-weight:600;">
                  <input type="radio" name="ai-pg-lang" value="en" style="accent-color:#7C3AED;width:16px;height:16px;"> English
                </label>
              </div>
            </div>
          </div>
          <div style="margin-bottom:20px;">
            <label style="font-size:12px;font-weight:700;color:#475569;display:block;margin-bottom:6px">Job Description <span style="color:#EF4444">*</span> <span style="font-weight:400;color:#94A3B8">(maks 2500 karakter)</span></label>
            <textarea id="ai-pg-jd" rows="9" maxlength="4000" placeholder="Paste job description lengkap di sini..." style="width:100%;padding:12px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:13px;line-height:1.6;resize:vertical;outline:none;box-sizing:border-box;" onfocus="this.style.borderColor='#7C3AED'" onblur="this.style.borderColor='#E2E8F0'" oninput="document.getElementById('ai-pg-jd-count').textContent=this.value.length"></textarea>
            <div style="font-size:11px;color:#94A3B8;margin-top:4px;text-align:right"><span id="ai-pg-jd-count">0</span>/4000</div>
          </div>
          <div style="display:flex;gap:12px;justify-content:flex-end;">
            <button onclick="closeAiPg()" style="padding:11px 20px;border-radius:10px;border:1.5px solid #E2E8F0;background:#fff;font-size:14px;font-weight:600;color:#64748B;cursor:pointer;">Batal</button>
            <button id="ai-pg-gen-btn" onclick="startAiGenerate()" style="padding:11px 24px;border-radius:10px;border:none;background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;"><i class="fas fa-magic"></i> Generate Profil AI</button>
          </div>
        </div>

        <!-- STEP 2: Review & Edit -->
        <div id="ai-pg-step2" style="display:none;padding:28px;">
          <p style="font-size:13px;color:#64748B;margin:0 0 20px;padding:12px 16px;background:#F8FAFC;border-radius:10px;border-left:3px solid #7C3AED;">
            <i class="fas fa-info-circle" style="color:#7C3AED;margin-right:6px;"></i>Review hasil generate. Klik tiap section untuk edit sebelum menyimpan.
          </p>
          <div id="ai-pg-sections"></div>

          <!-- Copy projects toggle -->
          <div style="margin-top:20px;padding:16px;background:#F8FAFC;border-radius:12px;border:1.5px solid #E2E8F0;display:flex;align-items:center;gap:12px;">
            <input type="checkbox" id="ai-pg-copy-proj" checked style="width:18px;height:18px;accent-color:#7C3AED;cursor:pointer;">
            <div>
              <div style="font-size:13px;font-weight:700;color:#334155;">Salin proyek dari profil dasar</div>
              <div style="font-size:12px;color:#94A3B8;">Proyek dari profil referensi akan disalin ke profil baru</div>
            </div>
          </div>

          <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px;">
            <button onclick="aiPgBack()" style="padding:11px 20px;border-radius:10px;border:1.5px solid #E2E8F0;background:#fff;font-size:14px;font-weight:600;color:#64748B;cursor:pointer;"><i class="fas fa-arrow-left" style="margin-right:6px;"></i>Kembali</button>
            <button id="ai-pg-save-btn" onclick="saveAiProfile()" style="padding:11px 24px;border-radius:10px;border:none;background:linear-gradient(135deg,#059669,#047857);color:#fff;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;"><i class="fas fa-save"></i> Simpan Profil Baru</button>
          </div>
        </div>

      </div>
    </div>

    <!-- DELETE FLASH TOAST -->
    <div id="undo-toast" style="display:none; position:fixed; bottom:24px; left:50%; transform:translateX(-50%); z-index:9999; background:#1E293B; color:white; padding:14px 20px; border-radius:16px; align-items:center; gap:14px; box-shadow:0 8px 32px rgba(0,0,0,0.35); min-width:280px; max-width:90vw;">
        <i class="fas fa-trash-restore text-slate-400 shrink-0"></i>
        <span style="flex:1; font-size:14px; font-weight:500;">Data dihapus. Bisa dipulihkan kapan saja.</span>
        <button onclick="switchTab('trash'); document.getElementById('undo-toast').style.display='none';" style="background:#4F46E5; border:none; padding:6px 14px; border-radius:10px; font-weight:700; font-size:12px; color:white; cursor:pointer; white-space:nowrap;">
            Lihat Sampah
        </button>
    </div>
    <script>
        if (sessionStorage.getItem('_admin_deleted_flash')) {
            sessionStorage.removeItem('_admin_deleted_flash');
            const t = document.getElementById('undo-toast');
            t.style.display = 'flex';
            setTimeout(() => { t.style.display = 'none'; }, 6000);
        }
        const _gotoTab = sessionStorage.getItem('_admin_goto_tab');
        if (_gotoTab) {
            sessionStorage.removeItem('_admin_goto_tab');
            switchTab(_gotoTab);
        }
    </script>
</body>
</html>

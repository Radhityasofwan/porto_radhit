<?php 
session_start(); 
require_once 'i18n.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Enable MySQLi error reporting as exceptions
$lang = currentLang();
if (!isset($_SESSION['admin_logged_in'])) { header("Location: " . localizedUrl('login.php', $lang)); exit; }
include 'db.php'; 

// Generate CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// --- 1. DATA FETCHING (Centralized) ---
$payload = [];

// Profile
$payload['profile'] = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=1"));
$siteTitleAdmin = localizedField($payload['profile'], 'site_title', $lang);

// Projects & Gallery
$projects = []; 
$pQ = mysqli_query($conn, "SELECT * FROM projects ORDER BY display_order ASC");
while($row = mysqli_fetch_assoc($pQ)) {
    $gQ = mysqli_query($conn, "SELECT * FROM project_images WHERE project_id=" . $row['id']);
    $gallery = []; 
    while($g = mysqli_fetch_assoc($gQ)) { $gallery[] = $g; }
    $row['gallery'] = $gallery;
    $projects[] = $row;
}
$payload['projects'] = $projects;

// Metrics
$metrics = []; $mQ = mysqli_query($conn, "SELECT * FROM impact_metrics ORDER BY display_order ASC"); 
while($r=mysqli_fetch_assoc($mQ)) $metrics[]=$r;
$payload['metrics'] = $metrics;

// Clients
$clients = []; $cQ = mysqli_query($conn, "SELECT * FROM client_logos ORDER BY display_order ASC"); 
while($r=mysqli_fetch_assoc($cQ)) $clients[]=$r;
$payload['clients'] = $clients;

// Skills
$skills = []; $sQ = mysqli_query($conn, "SELECT * FROM skills ORDER BY category, skill_name"); 
while($r=mysqli_fetch_assoc($sQ)) $skills[]=$r;
$payload['skills'] = $skills;

// Experience
$experience = []; $xQ = mysqli_query($conn, "SELECT * FROM experience ORDER BY id DESC"); 
while($r=mysqli_fetch_assoc($xQ)) $experience[]=$r;
$payload['experience'] = $experience;

// Education
$education = []; $eQ = mysqli_query($conn, "SELECT * FROM education ORDER BY id DESC"); 
while($r=mysqli_fetch_assoc($eQ)) $education[]=$r;
$payload['education'] = $education;

// Articles
$articles = []; $bQ = mysqli_query($conn, "SELECT * FROM articles ORDER BY created_at DESC"); 
while($r=mysqli_fetch_assoc($bQ)) $articles[]=$r;
$payload['articles'] = $articles;

// Messages
$messages = []; $msgQ = mysqli_query($conn, "SELECT * FROM messages ORDER BY created_at DESC"); 
while($r=mysqli_fetch_assoc($msgQ)) $messages[]=$r;
$payload['messages'] = $messages;

// Chat
$chats = []; $chatQ = mysqli_query($conn, "SELECT * FROM hero_chat ORDER BY display_order ASC"); 
while($r=mysqli_fetch_assoc($chatQ)) $chats[]=$r;
$payload['chats'] = $chats;

// Initialize analytics data
$analyticsSummary = ['unique_visitors' => 0, 'impressions' => 0, 'button_clicks' => 0];
$analyticsTop = [];
$analyticsRecent = [];

try {
    // Check if the web_analytics table exists before attempting to query it
    // This is a safeguard, though db.php should create it.
    $tableExistsQuery = mysqli_query($conn, "SHOW TABLES LIKE 'web_analytics'");
    if ($tableExistsQuery && mysqli_num_rows($tableExistsQuery) > 0) {
        // Fetching Analytics Data
        $summaryQuery = mysqli_query($conn, "SELECT COUNT(DISTINCT ip_address) AS unique_visitors, SUM(CASE WHEN event_type='page_view' THEN 1 ELSE 0 END) AS impressions, SUM(CASE WHEN event_type='button_click' THEN 1 ELSE 0 END) AS button_clicks FROM web_analytics");
        $fetchedSummary = mysqli_fetch_assoc($summaryQuery);
        if ($fetchedSummary) {
            $analyticsSummary = $fetchedSummary;
        }
    
        $topQuery = mysqli_query($conn, "SELECT event_key, COUNT(*) AS total FROM web_analytics WHERE event_type='button_click' GROUP BY event_key ORDER BY total DESC LIMIT 8");
        while($r = mysqli_fetch_assoc($topQuery)) {
            $analyticsTop[] = $r;
        }
    
        $recentQuery = mysqli_query($conn, "SELECT event_type, event_key, ip_address, created_at FROM web_analytics ORDER BY created_at DESC LIMIT 12");
        while($r = mysqli_fetch_assoc($recentQuery)) {
            $analyticsRecent[] = $r;
        }
    }
} catch (mysqli_sql_exception $e) {
    // Log the exception for debugging, but continue with default empty data
    error_log("MySQLi Exception in analytics fetching: " . $e->getMessage());
    // Variables are already initialized to default values, so no need to reset here.
}
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
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
                    <button type="button" onclick="submitForm('form-profile')" class="hidden md:inline-flex btn-modern"><i class="fas fa-save"></i> <?php echo t('admin.save_changes', [], $lang); ?></button>
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
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-center items-center">
                        <i class="fas fa-users text-4xl text-indigo-500 mb-4 block"></i>
                        <div class="text-3xl font-bold text-slate-800"><?php echo number_format($analyticsSummary['unique_visitors'] ?? 0); ?></div>
                        <div class="text-sm font-bold text-slate-500 uppercase mt-1"><?php echo $lang === 'id' ? 'Unik Audience (Per IP)' : 'Unique Visitors (Per IP)'; ?></div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-center items-center">
                        <i class="fas fa-eye text-4xl text-blue-500 mb-4 block"></i>
                        <div class="text-3xl font-bold text-slate-800"><?php echo number_format($analyticsSummary['impressions'] ?? 0); ?></div>
                        <div class="text-sm font-bold text-slate-500 uppercase mt-1"><?php echo $lang === 'id' ? 'Jumlah Impression' : 'Total Impressions'; ?></div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-center items-center">
                        <i class="fas fa-mouse-pointer text-4xl text-green-500 mb-4 block"></i>
                        <div class="text-3xl font-bold text-slate-800"><?php echo number_format($analyticsSummary['button_clicks'] ?? 0); ?></div>
                        <div class="text-sm font-bold text-slate-500 uppercase mt-1"><?php echo $lang === 'id' ? 'Jumlah Klik Tombol' : 'Total Button Clicks'; ?></div>
                    </div>
                </div>

                <?php if (!empty($analyticsTop)): ?>
                <div class="mt-8">
                    <h3 class="text-lg font-bold text-slate-800 mb-4"><?php echo $lang === 'id' ? 'Top Klik Tombol' : 'Top Button Clicks'; ?></h3>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 font-bold border-b border-slate-200">
                                <tr>
                                    <th class="px-6 py-3">Event Key</th>
                                    <th class="px-6 py-3">Total</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm text-slate-700 divide-y divide-slate-100">
                                <?php foreach($analyticsTop as $top): ?>
                                <tr>
                                    <td class="px-6 py-3 font-medium text-slate-800"><?php echo htmlspecialchars($top['event_key']); ?></td>
                                    <td class="px-6 py-3 font-bold"><?php echo number_format($top['total']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($analyticsRecent)): ?>
                <div class="mt-8">
                    <h3 class="text-lg font-bold text-slate-800 mb-4"><?php echo $lang === 'id' ? 'Aktivitas Terbaru' : 'Recent Activities'; ?></h3>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-x-auto">
                        <table class="w-full text-left min-w-[600px]">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 font-bold border-b border-slate-200">
                                <tr>
                                    <th class="px-6 py-3">Waktu</th>
                                    <th class="px-6 py-3">IP Address</th>
                                    <th class="px-6 py-3">Tipe</th>
                                    <th class="px-6 py-3">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm text-slate-700 divide-y divide-slate-100">
                                <?php foreach($analyticsRecent as $recent): ?>
                                <tr>
                                    <td class="px-6 py-3 whitespace-nowrap"><?php echo date('d M Y H:i', strtotime($recent['created_at'])); ?></td>
                                    <td class="px-6 py-3 text-slate-500"><?php echo htmlspecialchars($recent['ip_address']); ?></td>
                                    <td class="px-6 py-3">
                                        <span class="px-2 py-1 text-xs font-bold rounded-full <?php echo $recent['event_type'] === 'button_click' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'; ?>">
                                            <?php echo htmlspecialchars($recent['event_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 font-medium"><?php echo htmlspecialchars($recent['event_key'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </section>

            <!-- 3. PROJECTS TAB -->
            <section id="tab-projects" class="tab-content">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.projects_heading', [], $lang); ?></h2>
                    <button onclick="openModal('project')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add_project', [], $lang); ?></span></button>
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
                    <button onclick="openModal('metric')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button>
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
                    <button onclick="openModal('client')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button>
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
                    <button onclick="openModal('chat')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button>
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
                    <button onclick="openModal('skill')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php foreach($payload['skills'] as $s): ?>
                    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between border-l-4 <?php echo $s['category']=='Technical'?'border-l-blue-500':'border-l-green-500'; ?>">
                        <div class="flex items-center gap-3">
                            <i class="<?php echo strpos($s['icon_url'], 'fa')!==false ? $s['icon_url'] : 'fas fa-check'; ?> text-slate-600 text-xl"></i>
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
                <div class="flex justify-between items-center mb-6"><h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.experience_heading', [], $lang); ?></h2><button onclick="openModal('experience')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button></div>
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
                <div class="flex justify-between items-center mb-6"><h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.education_heading', [], $lang); ?></h2><button onclick="openModal('education')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button></div>
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
                <div class="flex justify-between items-center mb-6"><h2 class="text-2xl font-bold text-slate-800"><?php echo t('admin.blog_heading', [], $lang); ?></h2><button onclick="openModal('article')" class="btn-modern bg-slate-800"><i class="fas fa-plus"></i> <span class="hidden md:inline"><?php echo t('admin.add', [], $lang); ?></span></button></div>
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
                <button onclick="closeModal('project')" class="w-8 h-8 bg-slate-100 rounded-full text-slate-500"><i class="fas fa-times"></i></button>
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
                <div><label class="text-xs font-bold">Logo</label><input type="file" name="logo_file" class="input-modern text-xs"><input type="hidden" name="old_logo_url" id="client_old_logo"></div>
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
                <div><label class="text-xs font-bold">Icon Class</label><input name="icon_url" id="skill_icon" class="input-modern"></div>
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
                        if(data.status === 'success') location.reload();
                        else Swal.fire(ADMIN_UI.failed, data.message, 'error');
                    });
                }
            });
        }
    </script>
</body>
</html>

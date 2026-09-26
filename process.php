<?php
ob_start(); // Buffer all output — prevents PHP notices/warnings from polluting JSON responses
session_start();
require_once __DIR__ . '/auth_check.php';
mysqli_report(MYSQLI_REPORT_OFF); // Disable mysqli exceptions; errors handled manually via !$r checks
include 'db.php';

// --- HELPER: Response JSON ---
function jsonResponse($status, $message, $data = []) {
    while (ob_get_level()) ob_end_clean(); // Discard all buffered output levels before JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// Catch any uncaught exception/error and return JSON instead of HTML
set_exception_handler(function(Throwable $e) {
    jsonResponse('error', 'Server error: ' . $e->getMessage());
});

// --- SECURITY: CSRF Check ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && !in_array($_POST['action'], ['send_message', 'track_event', 'create_profile', 'switch_edit_profile', 'activate_profile', 'seed_profile_data', 'restore_deleted', 'rename_profile', 'delete_profile'], true)) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            if(isset($_POST['is_ajax'])) jsonResponse('error', 'Token kedaluwarsa. Silakan refresh halaman.');
            die('Security Error: Invalid CSRF Token.');
        }
    }
}

$action  = isset($_POST['action']) ? $_POST['action'] : '';
$delete  = isset($_GET['delete']) ? (int)$_GET['delete'] : 0;
$type    = isset($_GET['type']) ? $_GET['type'] : '';
$is_ajax = isset($_POST['is_ajax']) || isset($_GET['is_ajax']);

// Helper: get current editing profile_id (from session or active profile)
function getEditingProfileId($conn) {
    if (!empty($_SESSION['editing_profile_id'])) return (int)$_SESSION['editing_profile_id'];
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM profile WHERE is_active=1 LIMIT 1"));
    return $r ? (int)$r['id'] : 1;
}

// --- PROFILE MANAGEMENT ACTIONS ---
if ($action === 'rename_profile') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $pid   = (int)$_POST['profile_id'];
    $label = mysqli_real_escape_string($conn, trim($_POST['profile_label'] ?? ''));
    $name  = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
    if (!$label && !$name) jsonResponse('error', 'Label tidak boleh kosong');
    $sets = [];
    if ($label !== '') $sets[] = "profile_label='$label'";
    if ($name  !== '') $sets[] = "name='$name'";
    mysqli_query($conn, "UPDATE profile SET " . implode(',', $sets) . " WHERE id=$pid");
    jsonResponse('success', 'Nama profil diperbarui');
}

if ($action === 'delete_profile') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $pid = (int)$_POST['profile_id'];
    // Prevent deleting last profile or active profile
    $total = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM profile"))['c'];
    if ($total <= 1) jsonResponse('error', 'Tidak bisa menghapus satu-satunya profil');
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT is_active FROM profile WHERE id=$pid"));
    if (!$row) jsonResponse('error', 'Profil tidak ditemukan');
    if ($row['is_active']) jsonResponse('error', 'Profil yang sedang aktif di publik tidak bisa dihapus. Aktifkan profil lain dulu.');
    // Delete all content belonging to this profile
    foreach (['projects','skills','experience','education','articles','impact_metrics','client_logos','hero_chat'] as $t) {
        mysqli_query($conn, "DELETE FROM `$t` WHERE profile_id=$pid");
    }
    mysqli_query($conn, "DELETE FROM profile WHERE id=$pid");
    if ((int)($_SESSION['editing_profile_id'] ?? 0) === $pid) unset($_SESSION['editing_profile_id']);
    jsonResponse('success', 'Profil berhasil dihapus');
}

if ($action === 'switch_edit_profile') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $pid = (int)$_POST['profile_id'];
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM profile WHERE id=$pid"));
    if (!$r) jsonResponse('error', 'Profil tidak ditemukan');
    $_SESSION['editing_profile_id'] = $pid;
    jsonResponse('success', 'Profil aktif diubah', ['profile_id' => $pid]);
}

if ($action === 'activate_profile') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $pid = (int)$_POST['profile_id'];
    mysqli_query($conn, "UPDATE profile SET is_active=0");
    mysqli_query($conn, "UPDATE profile SET is_active=1 WHERE id=$pid");
    jsonResponse('success', 'Profil dipublikasikan');
}

if ($action === 'create_profile') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $label = mysqli_real_escape_string($conn, $_POST['profile_label'] ?? 'Profil Baru');
    $role  = mysqli_real_escape_string($conn, $_POST['hero_role']    ?? '');
    $name  = mysqli_real_escape_string($conn, $_POST['name']         ?? '');
    $r = mysqli_query($conn, "INSERT INTO profile
        (name, profile_label, hero_role, hero_role_en, is_active,
         bio, bio_en, whatsapp, email, site_title, site_title_en,
         hero_image_url, profile_photo, availability_text, availability_text_en,
         is_available, show_metrics, cv_url,
         projects_desc, projects_desc_en, contact_desc, contact_desc_en,
         projects_limit, seo_keywords, seo_keywords_en,
         link_github, link_linkedin, link_instagram, link_facebook,
         link_youtube, link_tiktok, link_threads, link_twitter, link_blog_pribadi,
         script_google, script_fb, script_other, custom_head_script)
        VALUES
        ('$name', '$label', '$role', '$role', 0,
         '', '', '', '', '$name', '$name',
         '', '', 'OPEN FOR PROJECTS', 'OPEN FOR PROJECTS',
         1, 0, '',
         '', '', '', '',
         12, '', '',
         '', '', '', '',
         '', '', '', '', '',
         '', '', '', '')");
    if (!$r) jsonResponse('error', mysqli_error($conn));
    $newId = (int)mysqli_insert_id($conn);
    $_SESSION['editing_profile_id'] = $newId;
    jsonResponse('success', 'Profil baru dibuat', ['profile_id' => $newId]);
}

// --- GENERATE PROFILE DRAFT (AI) ---
if ($action === 'generate_profile_draft') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    @set_time_limit(180);

    $lang          = ($_POST['lang'] ?? 'id') === 'en' ? 'en' : 'id';
    $jd            = mb_substr(trim($_POST['job_description'] ?? ''), 0, 4000);
    $targetRole    = mb_substr(trim($_POST['target_role'] ?? ''), 0, 200);
    $baseProfileId = (int)($_POST['base_profile_id'] ?? 1);

    if (empty($jd) || empty($targetRole)) jsonResponse('error', 'Target role dan job description wajib diisi.');

    // Load API keys (same pattern as generate_cv)
    $keysRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT setting_value FROM site_settings WHERE setting_key = 'gemini_api_keys' LIMIT 1"));
    $apiKeys = [];
    if (!empty($keysRow['setting_value'])) {
        $dec = json_decode($keysRow['setting_value'], true);
        if (is_array($dec)) $apiKeys = array_values(array_filter(array_map('trim', $dec)));
    }
    if (empty($apiKeys)) {
        $lr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT setting_value FROM site_settings WHERE setting_key = 'gemini_api_key' LIMIT 1"));
        if (!empty($lr['setting_value'])) $apiKeys = [trim($lr['setting_value'])];
    }
    if (empty($apiKeys)) jsonResponse('error', 'API key Gemini belum diatur. Silakan set di menu Settings.');

    // Load base profile
    $prof = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=$baseProfileId"));
    if (!$prof) jsonResponse('error', 'Base profile tidak ditemukan.');

    // Load skills
    $skillRows = []; $sr = mysqli_query($conn, "SELECT * FROM skills WHERE profile_id=$baseProfileId ORDER BY category");
    while ($r = mysqli_fetch_assoc($sr)) $skillRows[] = $r;

    // Load experience
    $expRows = []; $er = mysqli_query($conn, "SELECT * FROM experience WHERE profile_id=$baseProfileId ORDER BY id DESC");
    while ($r = mysqli_fetch_assoc($er)) $expRows[] = $r;

    // Load top projects as context
    $projRows = []; $pr = mysqli_query($conn, "SELECT title, description, tech_stacks, result_text FROM projects WHERE profile_id=$baseProfileId ORDER BY display_order ASC LIMIT 8");
    while ($r = mysqli_fetch_assoc($pr)) $projRows[] = $r;

    // Build context strings
    $skillsCtx = [];
    foreach ($skillRows as $s) $skillsCtx[] = ($s['category'] ?: 'General') . ': ' . $s['skill_name'];

    $expCtx = [];
    foreach ($expRows as $e) $expCtx[] = "{$e['company']} | {$e['year_range']} | {$e['role']}\n{$e['description']}";

    $projCtx = [];
    foreach ($projRows as $p) $projCtx[] = "• {$p['title']} ({$p['tech_stacks']}): {$p['description']}";

    $outputNote = $lang === 'en'
        ? 'Write ALL text content in English (both the primary field and _en variant). Professional international tone.'
        : 'Tulis SEMUA konten teks dalam Bahasa Indonesia profesional (field utama). Field _en boleh sama dengan field utama.';

    $contextBlock = "Nama: {$prof['name']}\nProfesi Dasar: {$prof['hero_role']}\nBio: {$prof['bio']}\n\n"
        . "Skills:\n- " . implode("\n- ", $skillsCtx) . "\n\n"
        . "Pengalaman:\n" . implode("\n\n", $expCtx) . "\n\n"
        . "Proyek (konteks):\n" . implode("\n", $projCtx);

    $promptDraft = <<<PROMPT
TARGET ROLE: {$targetRole}
OUTPUT LANGUAGE: {$outputNote}

JOB DESCRIPTION:
===START===
{$jd}
===END===

DATA PROFIL ASLI — SUMBER FAKTA (hanya gunakan data di sini, jangan karang fakta baru):
{$contextBlock}

===

LANGKAH 1 — Baca JD dan identifikasi:
- 5-7 keyword teknis utama yang dicari perusahaan
- Tanggung jawab inti posisi {$targetRole}
- Soft skill / nilai yang diharapkan

LANGKAH 2 — Buat output JSON yang menghubungkan keyword JD dengan fakta profil nyata:

RULES WAJIB:
• hero_role: maks 8 kata, cantumkan "{$targetRole}" + value proposition yang membedakan kandidat.
• bio: TEPAT 3 kalimat padat. [1] Siapa + spesialisasi relevan posisi. [2] Tool/teknologi konkret yang dikuasai (ambil dari skills nyata) yang relevan JD. [3] Impact/value proposition + angka konkret jika tersedia dari data.
• skills: HANYA skill dari data profil yang relevan JD — susun dari paling ke kurang relevan. Maks 5 kategori, maks 6 item/kategori. Tiap item = satu entri dalam array.
• experience: Tulis ulang bullets agar menonjolkan poin yang relevan JD. Setiap bullet harus dimulai dengan kata kerja aktif. Format action → task → result. 3-4 bullet per entri. Gunakan newline (\n) antar bullet.
• hero_chat: 5 Q&A yang benar-benar akan ditanya recruiter/hiring manager untuk posisi ini. Jawaban harus menunjukkan bukti konkret dari data profil (bukan generik).
• articles: 3 judul artikel yang membangun personal branding sebagai {$targetRole}. Judul harus spesifik + mengandung keyword JD. meta_desc maks 155 karakter, actionable.
• DILARANG: menambah perusahaan, skill, atau angka yang tidak ada di data profil.
• Output HANYA JSON valid. Tanpa markdown, tanpa teks di luar JSON.

OUTPUT JSON (struktur wajib):
{
  "profile": {
    "hero_role": "string",
    "hero_role_en": "string",
    "bio": "string",
    "bio_en": "string",
    "site_title": "string",
    "site_title_en": "string",
    "availability_text": "string",
    "availability_text_en": "string"
  },
  "skills": [
    {"category": "string", "skill_name": "string", "skill_name_en": "string"}
  ],
  "experience": [
    {
      "company": "string",
      "role": "string",
      "role_en": "string",
      "year_range": "string",
      "year_range_en": "string",
      "description": "string",
      "description_en": "string"
    }
  ],
  "hero_chat": [
    {
      "question": "string",
      "question_en": "string",
      "answer": "string",
      "answer_en": "string",
      "type": "text"
    }
  ],
  "articles": [
    {
      "title": "string",
      "title_en": "string",
      "meta_desc": "string",
      "meta_desc_en": "string"
    }
  ]
}
PROMPT;

    $payloadDraft = json_encode([
        'system_instruction' => ['parts' => [['text' => 'Kamu adalah senior career strategist yang mengedit profil nyata — bukan AI yang membuat konten generik. Setiap kalimat harus bisa dilacak ke data sumber. Output HANYA JSON valid, tanpa markdown, tanpa teks di luar JSON.']]],
        'contents'           => [['parts' => [['text' => $promptDraft]]]],
        'generationConfig'   => ['temperature' => 0.55, 'maxOutputTokens' => 6000, 'responseMimeType' => 'application/json'],
    ]);

    $resultDraft = callGemini($apiKeys, $payloadDraft, 120);
    if (!$resultDraft['ok']) jsonResponse('error', $resultDraft['error']);

    $rawDraft = trim($resultDraft['text']);
    $rawDraft = preg_replace('/^```(?:json)?\s*/iu', '', $rawDraft);
    $rawDraft = preg_replace('/\s*```$/u', '', trim($rawDraft));

    $draft = json_decode($rawDraft, true);
    if (!is_array($draft)) {
        $extracted = extractFirstJsonObject($rawDraft);
        if ($extracted !== null) $draft = json_decode($extracted, true);
    }
    if (!is_array($draft)) jsonResponse('error', 'Gagal parse JSON dari Gemini. Coba generate ulang.');

    jsonResponse('success', 'Draft profil berhasil digenerate.', $draft);
}

// --- SAVE PROFILE DRAFT (AI) ---
if ($action === 'save_profile_draft') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');

    $draftRaw      = $_POST['draft'] ?? '';
    $label         = mb_substr(trim($_POST['profile_label'] ?? 'Profil AI'), 0, 100);
    $baseProfileId = (int)($_POST['base_profile_id'] ?? 1);
    $copyProjects  = ($_POST['copy_projects'] ?? '1') === '1';

    $draft = json_decode($draftRaw, true);
    if (!is_array($draft)) jsonResponse('error', 'Data draft tidak valid.');

    $profDraft  = is_array($draft['profile']    ?? null) ? $draft['profile']    : [];
    $skillsDraft = is_array($draft['skills']    ?? null) ? $draft['skills']     : [];
    $expDraft   = is_array($draft['experience'] ?? null) ? $draft['experience'] : [];
    $chatDraft  = is_array($draft['hero_chat']  ?? null) ? $draft['hero_chat']  : [];
    $artDraft   = is_array($draft['articles']   ?? null) ? $draft['articles']   : [];

    // Load base profile for personal/contact info
    $base = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=$baseProfileId"));
    if (!$base) jsonResponse('error', 'Base profile tidak ditemukan.');

    $name    = mysqli_real_escape_string($conn, $base['name']);
    $email   = mysqli_real_escape_string($conn, $base['email']);
    $wa      = mysqli_real_escape_string($conn, $base['whatsapp']);
    $linkedin= mysqli_real_escape_string($conn, $base['link_linkedin']);
    $github  = mysqli_real_escape_string($conn, $base['link_github']);
    $photo   = mysqli_real_escape_string($conn, $base['profile_photo']);
    $heroImg = mysqli_real_escape_string($conn, $base['hero_image_url']);
    $labelE  = mysqli_real_escape_string($conn, $label);

    $hero_role    = mysqli_real_escape_string($conn, $profDraft['hero_role']           ?? $base['hero_role']);
    $hero_role_en = mysqli_real_escape_string($conn, $profDraft['hero_role_en']        ?? $hero_role);
    $bio          = mysqli_real_escape_string($conn, $profDraft['bio']                 ?? '');
    $bio_en       = mysqli_real_escape_string($conn, $profDraft['bio_en']              ?? $bio);
    $site_title   = mysqli_real_escape_string($conn, $profDraft['site_title']          ?? $base['site_title']);
    $site_title_en= mysqli_real_escape_string($conn, $profDraft['site_title_en']       ?? $site_title);
    $avail        = mysqli_real_escape_string($conn, $profDraft['availability_text']   ?? 'OPEN FOR PROJECTS');
    $avail_en     = mysqli_real_escape_string($conn, $profDraft['availability_text_en']?? $avail);

    // 1. Create new profile
    $r = mysqli_query($conn, "INSERT INTO profile
        (name, profile_label, hero_role, hero_role_en, is_active,
         bio, bio_en, whatsapp, email, site_title, site_title_en,
         hero_image_url, profile_photo, availability_text, availability_text_en,
         is_available, show_metrics, cv_url,
         projects_desc, projects_desc_en, contact_desc, contact_desc_en,
         projects_limit, seo_keywords, seo_keywords_en,
         link_github, link_linkedin, link_instagram, link_facebook,
         link_youtube, link_tiktok, link_threads, link_twitter, link_blog_pribadi,
         script_google, script_fb, script_other, custom_head_script)
        VALUES
        ('$name','$labelE','$hero_role','$hero_role_en',0,
         '$bio','$bio_en','$wa','$email','$site_title','$site_title_en',
         '$heroImg','$photo','$avail','$avail_en',
         1,0,'','','','','',12,'','',
         '$github','$linkedin','','','','','','','',
         '','','','')");
    if (!$r) jsonResponse('error', 'Gagal membuat profil: ' . mysqli_error($conn));
    $newId = (int)mysqli_insert_id($conn);

    // 2. Insert skills
    foreach ($skillsDraft as $s) {
        $cat  = mysqli_real_escape_string($conn, (string)($s['category']     ?? ''));
        $sn   = mysqli_real_escape_string($conn, (string)($s['skill_name']   ?? ''));
        $snen = mysqli_real_escape_string($conn, (string)($s['skill_name_en']?? $sn));
        if (!$sn) continue;
        mysqli_query($conn, "INSERT INTO skills (category,skill_name,skill_name_en,profile_id) VALUES ('$cat','$sn','$snen',$newId)");
    }

    // 3. Insert experience
    foreach ($expDraft as $e) {
        $co   = mysqli_real_escape_string($conn, (string)($e['company']       ?? ''));
        $ro   = mysqli_real_escape_string($conn, (string)($e['role']          ?? ''));
        $roen = mysqli_real_escape_string($conn, (string)($e['role_en']       ?? $ro));
        $yr   = mysqli_real_escape_string($conn, (string)($e['year_range']    ?? ''));
        $yren = mysqli_real_escape_string($conn, (string)($e['year_range_en'] ?? $yr));
        $de   = mysqli_real_escape_string($conn, (string)($e['description']   ?? ''));
        $deen = mysqli_real_escape_string($conn, (string)($e['description_en']?? $de));
        if (!$co) continue;
        mysqli_query($conn, "INSERT INTO experience (company,role,role_en,year_range,year_range_en,description,description_en,profile_id)
            VALUES ('$co','$ro','$roen','$yr','$yren','$de','$deen',$newId)");
    }

    // 4. Insert hero_chat
    foreach ($chatDraft as $idx => $c) {
        $q    = mysqli_real_escape_string($conn, (string)($c['question']    ?? ''));
        $qen  = mysqli_real_escape_string($conn, (string)($c['question_en'] ?? $q));
        $a    = mysqli_real_escape_string($conn, (string)($c['answer']      ?? ''));
        $aen  = mysqli_real_escape_string($conn, (string)($c['answer_en']   ?? $a));
        if (!$q) continue;
        $ord = $idx + 1;
        mysqli_query($conn, "INSERT INTO hero_chat (question,question_en,answer,answer_en,type,display_order,profile_id)
            VALUES ('$q','$qen','$a','$aen','text',$ord,$newId)");
    }

    // 5. Insert article stubs (no content yet)
    $ts = time();
    foreach ($artDraft as $idx => $ar) {
        $t    = mysqli_real_escape_string($conn, (string)($ar['title']       ?? ''));
        $ten  = mysqli_real_escape_string($conn, (string)($ar['title_en']    ?? $t));
        $md   = mysqli_real_escape_string($conn, (string)($ar['meta_desc']   ?? ''));
        $mden = mysqli_real_escape_string($conn, (string)($ar['meta_desc_en']?? $md));
        if (!$t) continue;
        $rawSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $t)));
        $slug = mysqli_real_escape_string($conn, $rawSlug . '-' . ($ts + $idx));
        mysqli_query($conn, "INSERT INTO articles (title,title_en,slug,meta_desc,meta_desc_en,content,content_en,image_url,profile_id)
            VALUES ('$t','$ten','$slug','$md','$mden','','','',$newId)");
    }

    // 6. Copy projects from base profile
    if ($copyProjects) {
        $cpTs = time() + 1;
        mysqli_query($conn, "INSERT INTO projects
            (category,category_en,title,title_en,slug,meta_desc,meta_desc_en,client_name,
             description,description_en,details,details_en,problem,problem_en,
             solution,solution_en,chart_data_json,chart_data_json_en,
             image_url,link_url,tech_stacks,result_text,result_text_en,display_order,profile_id)
            SELECT category,category_en,title,title_en,CONCAT(slug,'-',$cpTs),meta_desc,meta_desc_en,client_name,
             description,description_en,details,details_en,problem,problem_en,
             solution,solution_en,chart_data_json,chart_data_json_en,
             image_url,link_url,tech_stacks,result_text,result_text_en,display_order,$newId
            FROM projects WHERE profile_id=$baseProfileId");
    }

    $_SESSION['editing_profile_id'] = $newId;
    jsonResponse('success', 'Profil AI berhasil dibuat!', ['profile_id' => $newId]);
}

if ($action === 'seed_profile_data') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $source   = (int)$_POST['source_profile_id'];
    $target   = getEditingProfileId($conn);
    $dataType = $_POST['data_type'] ?? '';
    if ($source === $target) jsonResponse('error', 'Tidak bisa menyalin dari profil yang sama');
    $ts = time();

    switch ($dataType) {
        case 'projects':
            $r = mysqli_query($conn, "INSERT INTO projects (category,category_en,title,title_en,slug,meta_desc,meta_desc_en,client_name,description,description_en,details,details_en,problem,problem_en,solution,solution_en,chart_data_json,chart_data_json_en,image_url,link_url,tech_stacks,result_text,result_text_en,display_order,profile_id)
                SELECT category,category_en,title,title_en,CONCAT(slug,'-',$ts),meta_desc,meta_desc_en,client_name,description,description_en,details,details_en,problem,problem_en,solution,solution_en,chart_data_json,chart_data_json_en,image_url,link_url,tech_stacks,result_text,result_text_en,display_order,$target FROM projects WHERE profile_id=$source");
            break;
        case 'metrics':
            $r = mysqli_query($conn, "INSERT INTO impact_metrics (metric_name,metric_name_en,metric_value,metric_value_en,icon,display_order,profile_id)
                SELECT metric_name,metric_name_en,metric_value,metric_value_en,icon,display_order,$target FROM impact_metrics WHERE profile_id=$source");
            break;
        case 'clients':
            $r = mysqli_query($conn, "INSERT INTO client_logos (client_name,logo_url,display_order,profile_id)
                SELECT client_name,logo_url,display_order,$target FROM client_logos WHERE profile_id=$source");
            break;
        case 'skills':
            $r = mysqli_query($conn, "INSERT INTO skills (category,skill_name,skill_name_en,icon_url,profile_id)
                SELECT category,skill_name,skill_name_en,icon_url,$target FROM skills WHERE profile_id=$source");
            break;
        case 'experience':
            $r = mysqli_query($conn, "INSERT INTO experience (role,role_en,company,year_range,year_range_en,description,description_en,profile_id)
                SELECT role,role_en,company,year_range,year_range_en,description,description_en,$target FROM experience WHERE profile_id=$source");
            break;
        case 'education':
            $r = mysqli_query($conn, "INSERT INTO education (school_name,degree,degree_en,year_range,year_range_en,profile_id)
                SELECT school_name,degree,degree_en,year_range,year_range_en,$target FROM education WHERE profile_id=$source");
            break;
        case 'articles':
            $r = mysqli_query($conn, "INSERT INTO articles (title,title_en,slug,meta_desc,meta_desc_en,content,content_en,image_url,profile_id)
                SELECT title,title_en,CONCAT(slug,'-',$ts),meta_desc,meta_desc_en,content,content_en,image_url,$target FROM articles WHERE profile_id=$source");
            break;
        case 'chat':
            $r = mysqli_query($conn, "INSERT INTO hero_chat (question,question_en,answer,answer_en,type,display_order,profile_id)
                SELECT question,question_en,answer,answer_en,type,display_order,$target FROM hero_chat WHERE profile_id=$source");
            break;
        case 'profile':
            $src = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=$source"));
            if (!$src) jsonResponse('error', 'Profil sumber tidak ditemukan');
            // Backup target profile ke trash sebelum ditimpa
            $targetRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=$target"));
            if ($targetRow) {
                $bkJson = mysqli_real_escape_string($conn, json_encode($targetRow, JSON_UNESCAPED_UNICODE));
                mysqli_query($conn, "INSERT INTO deleted_items (table_name, row_data, profile_id) VALUES ('profile', '$bkJson', $target)");
            }
            $flds = ['site_title','site_title_en','hero_role','hero_role_en','availability_text','availability_text_en','bio','bio_en','projects_desc','projects_desc_en','contact_desc','contact_desc_en','projects_limit','is_available','show_metrics','email','whatsapp','link_linkedin','link_github','link_instagram','link_facebook','link_twitter','link_tiktok','link_threads','link_youtube','link_blog_pribadi','hero_image_url','profile_photo','cv_url','seo_keywords','seo_keywords_en'];
            $sets = [];
            foreach ($flds as $f) { if (array_key_exists($f, $src)) { $v = mysqli_real_escape_string($conn, (string)$src[$f]); $sets[] = "`$f`='$v'"; } }
            $r = mysqli_query($conn, "UPDATE profile SET " . implode(',', $sets) . " WHERE id=$target");
            break;
        default:
            jsonResponse('error', 'Tipe data tidak valid');
    }
    if (!$r) jsonResponse('error', mysqli_error($conn));
    $count = mysqli_affected_rows($conn);
    jsonResponse('success', "$count item berhasil disalin", ['count' => $count]);
}

// --- HELPER: Upload File ---
function uploadFile($fileInputName, $prefix) {
    if (!empty($_FILES[$fileInputName]['name'])) {
        $fileTmp = $_FILES[$fileInputName]['tmp_name'];
        $target_dir = "uploads/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        $ext = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
        $valid_ext = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'pdf'];
        if(in_array($ext, $valid_ext)) {
            $filename = time() . '_' . $prefix . '.' . $ext;
            $target_file = $target_dir . $filename;
            if(move_uploaded_file($fileTmp, $target_file)) return $target_file;
        }
    }
    return null;
}

// --- HELPER: Extract first valid JSON object from arbitrary text ---
// Handles Gemini responses that wrap JSON in prose or trailing notes
function extractFirstJsonObject($text) {
    $start = strpos($text, '{');
    if ($start === false) return null;
    $depth = 0; $inStr = false; $esc = false;
    for ($i = $start, $len = strlen($text); $i < $len; $i++) {
        $c = $text[$i];
        if ($esc)                { $esc = false; continue; }
        if ($c === '\\' && $inStr) { $esc = true;  continue; }
        if ($c === '"')            { $inStr = !$inStr; continue; }
        if ($inStr)                continue;
        if ($c === '{')            $depth++;
        elseif ($c === '}')        { if (--$depth === 0) return substr($text, $start, $i - $start + 1); }
    }
    return null;
}

// --- HELPER: Slug ---
function createSlug($string, $conn, $table, $id = null) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string)));
    if (empty($slug)) $slug = 'item-' . time();
    $query = "SELECT COUNT(*) as count FROM $table WHERE slug = '$slug'";
    if($id) $query .= " AND id != $id";
    $result = mysqli_fetch_assoc(mysqli_query($conn, $query));
    if ($result['count'] > 0) $slug = $slug . '-' . time();
    return $slug;
}

// --- 1. UPDATE PROFILE ---
if ($action == 'update_profile') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    
    // Basic Info
    $site_title = mysqli_real_escape_string($conn, $_POST['site_title']);
    $site_title_en = mysqli_real_escape_string($conn, $_POST['site_title_en'] ?? '');
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $hero_role = mysqli_real_escape_string($conn, $_POST['hero_role']);
    $hero_role_en = mysqli_real_escape_string($conn, $_POST['hero_role_en'] ?? '');
    $availability_text = mysqli_real_escape_string($conn, $_POST['availability_text']);
    $availability_text_en = mysqli_real_escape_string($conn, $_POST['availability_text_en'] ?? '');
    $bio = mysqli_real_escape_string($conn, $_POST['bio']);
    $bio_en = mysqli_real_escape_string($conn, $_POST['bio_en'] ?? '');
    $projects_desc = mysqli_real_escape_string($conn, $_POST['projects_desc'] ?? '');
    $projects_desc_en = mysqli_real_escape_string($conn, $_POST['projects_desc_en'] ?? '');
    $contact_desc = mysqli_real_escape_string($conn, $_POST['contact_desc'] ?? '');
    $contact_desc_en = mysqli_real_escape_string($conn, $_POST['contact_desc_en'] ?? '');
    $projects_limit = (int)$_POST['projects_limit'];
    $is_available = isset($_POST['is_available']) ? 1 : 0;
    $show_metrics = isset($_POST["show_metrics"]) ? 1 : 0;
    
    // Contact & Social
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $whatsapp = mysqli_real_escape_string($conn, $_POST['whatsapp']);
    $link_linkedin = mysqli_real_escape_string($conn, $_POST['link_linkedin']);
    $link_github = mysqli_real_escape_string($conn, $_POST['link_github']);
    $link_instagram = mysqli_real_escape_string($conn, $_POST['link_instagram']);
    $link_facebook = mysqli_real_escape_string($conn, $_POST['link_facebook']);
    $link_twitter = mysqli_real_escape_string($conn, $_POST['link_twitter']);
    $link_tiktok = mysqli_real_escape_string($conn, $_POST['link_tiktok']);
    $link_threads = mysqli_real_escape_string($conn, $_POST['link_threads']);
    $link_youtube = mysqli_real_escape_string($conn, $_POST['link_youtube']);
    $link_blog_pribadi = mysqli_real_escape_string($conn, $_POST['link_blog_pribadi']);

    // SEO & Scripts
    $seo_keywords = mysqli_real_escape_string($conn, $_POST['seo_keywords']);
    $seo_keywords_en = mysqli_real_escape_string($conn, $_POST['seo_keywords_en'] ?? '');
    $script_google = mysqli_real_escape_string($conn, $_POST['script_google'] ?? '');
    $script_fb = mysqli_real_escape_string($conn, $_POST['script_fb'] ?? '');
    $script_other = mysqli_real_escape_string($conn, $_POST['script_other'] ?? '');
    $combined_script = $script_google . "\n" . $script_fb . "\n" . $script_other;
    $custom_head_script = mysqli_real_escape_string($conn, $combined_script);
    
    // Uploads
    $hero_img = $_POST['old_hero_image']; if($new = uploadFile('hero_image_file', 'hero')) $hero_img = $new;
    $prof_photo = $_POST['old_profile_photo']; if($new = uploadFile('profile_photo_file', 'photo')) $prof_photo = $new;
    $cv_url = $_POST['old_cv_url']; if($new = uploadFile('cv_file', 'cv')) $cv_url = $new;

    $query = "UPDATE profile SET 
        site_title='$site_title', site_title_en='$site_title_en', name='$name', hero_role='$hero_role', hero_role_en='$hero_role_en', availability_text='$availability_text', availability_text_en='$availability_text_en', 
        bio='$bio', bio_en='$bio_en', projects_desc='$projects_desc', projects_desc_en='$projects_desc_en', contact_desc='$contact_desc', contact_desc_en='$contact_desc_en', projects_limit='$projects_limit', is_available='$is_available', show_metrics='$show_metrics',
        email='$email', whatsapp='$whatsapp', link_linkedin='$link_linkedin', link_github='$link_github', 
        link_instagram='$link_instagram', link_facebook='$link_facebook', link_twitter='$link_twitter', 
        link_tiktok='$link_tiktok', link_threads='$link_threads', link_youtube='$link_youtube', link_blog_pribadi='$link_blog_pribadi',
        hero_image_url='$hero_img', profile_photo='$prof_photo', cv_url='$cv_url',
        seo_keywords='$seo_keywords', seo_keywords_en='$seo_keywords_en', script_google='$script_google', script_fb='$script_fb', script_other='$script_other', custom_head_script='$custom_head_script'
        WHERE id=" . getEditingProfileId($conn);

    if(mysqli_query($conn, $query)) {
        if($is_ajax) jsonResponse('success', 'Profil berhasil diperbarui!');
        else header("Location: admin.php?tab=profile&status=success");
    } else {
        if($is_ajax) jsonResponse('error', mysqli_error($conn));
    }
}

// --- 2. SAVE PROJECT ---
if ($action == 'save_project') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    
    $id = (int)$_POST['id'];
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $category_en = mysqli_real_escape_string($conn, $_POST['category_en'] ?? '');
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $title_en = mysqli_real_escape_string($conn, $_POST['title_en'] ?? '');
    $client_name = mysqli_real_escape_string($conn, $_POST['client_name']);
    $tech_stacks = mysqli_real_escape_string($conn, $_POST['tech_stacks']);
    $result_text = mysqli_real_escape_string($conn, $_POST['result_text']);
    $result_text_en = mysqli_real_escape_string($conn, $_POST['result_text_en'] ?? '');
    $link_url = mysqli_real_escape_string($conn, $_POST['link_url']);
    $display_order = (int)$_POST['display_order'];
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $description_en = mysqli_real_escape_string($conn, $_POST['description_en'] ?? '');
    $details = mysqli_real_escape_string($conn, $_POST['details']);
    $details_en = mysqli_real_escape_string($conn, $_POST['details_en'] ?? '');
    $problem = mysqli_real_escape_string($conn, $_POST['problem']);
    $problem_en = mysqli_real_escape_string($conn, $_POST['problem_en'] ?? '');
    $solution = mysqli_real_escape_string($conn, $_POST['solution']);
    $solution_en = mysqli_real_escape_string($conn, $_POST['solution_en'] ?? '');
    $meta_desc = mysqli_real_escape_string($conn, $_POST['meta_desc']);
    $meta_desc_en = mysqli_real_escape_string($conn, $_POST['meta_desc_en'] ?? '');
    
    // Handle Chart JSON (Raw Input)
    $chart_data_json = mysqli_real_escape_string($conn, $_POST['chart_data_json']); 
    if(empty(trim($chart_data_json))) $chart_data_json = 'NULL'; else $chart_data_json = "'$chart_data_json'";
    $chart_data_json_en = mysqli_real_escape_string($conn, $_POST['chart_data_json_en'] ?? '');
    if(empty(trim($chart_data_json_en))) $chart_data_json_en = 'NULL'; else $chart_data_json_en = "'$chart_data_json_en'";

    $raw_slug = !empty($_POST['slug']) ? $_POST['slug'] : $title;
    $slug = createSlug($raw_slug, $conn, 'projects', $id);
    $img = $_POST['old_project_image']; if($new = uploadFile('project_image_file', 'proj')) $img = $new;

    $epid = getEditingProfileId($conn);
    if($id > 0){
        $q = "UPDATE projects SET category='$category', category_en='$category_en', title='$title', title_en='$title_en', slug='$slug', meta_desc='$meta_desc', meta_desc_en='$meta_desc_en',
              client_name='$client_name', description='$description', description_en='$description_en', details='$details', details_en='$details_en', problem='$problem', problem_en='$problem_en', solution='$solution', solution_en='$solution_en',
              chart_data_json=$chart_data_json, chart_data_json_en=$chart_data_json_en, image_url='$img', link_url='$link_url', tech_stacks='$tech_stacks', result_text='$result_text', result_text_en='$result_text_en', display_order='$display_order'
              WHERE id=$id";
    } else {
        $q = "INSERT INTO projects (category, category_en, title, title_en, slug, meta_desc, meta_desc_en, client_name, description, description_en, details, details_en, problem, problem_en, solution, solution_en, chart_data_json, chart_data_json_en, image_url, link_url, tech_stacks, result_text, result_text_en, display_order, profile_id)
              VALUES ('$category', '$category_en', '$title', '$title_en', '$slug', '$meta_desc', '$meta_desc_en', '$client_name', '$description', '$description_en', '$details', '$details_en', '$problem', '$problem_en', '$solution', '$solution_en', $chart_data_json, $chart_data_json_en, '$img', '$link_url', '$tech_stacks', '$result_text', '$result_text_en', '$display_order', $epid)";
    }

    if(mysqli_query($conn, $q)) {
        if($is_ajax) jsonResponse('success', 'Project saved!');
        else header("Location: admin.php?tab=projects");
    } else {
        if($is_ajax) jsonResponse('error', mysqli_error($conn));
    }
}

// --- 3. METRIC ---
if ($action == 'save_metric') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $id = (int)$_POST['id'];
    $name = mysqli_real_escape_string($conn, $_POST['metric_name']);
    $name_en = mysqli_real_escape_string($conn, $_POST['metric_name_en'] ?? '');
    $val = mysqli_real_escape_string($conn, $_POST['metric_value']);
    $val_en = mysqli_real_escape_string($conn, $_POST['metric_value_en'] ?? '');
    $icon = mysqli_real_escape_string($conn, $_POST['icon']);
    $order = (int)$_POST['display_order'];
    $epid = getEditingProfileId($conn);
    $q = $id ? "UPDATE impact_metrics SET metric_name='$name', metric_name_en='$name_en', metric_value='$val', metric_value_en='$val_en', icon='$icon', display_order='$order' WHERE id=$id" : "INSERT INTO impact_metrics (metric_name, metric_name_en, metric_value, metric_value_en, icon, display_order, profile_id) VALUES ('$name', '$name_en', '$val', '$val_en', '$icon', '$order', $epid)";
    if(mysqli_query($conn, $q)) jsonResponse('success', 'Metric saved');
}

// --- 4. CLIENT ---
if ($action == 'save_client') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $id = (int)$_POST['id'];
    $name = mysqli_real_escape_string($conn, $_POST['client_name']);
    $order = (int)$_POST['display_order'];
    $logo = $_POST['old_logo_url'];
    if($new = uploadFile('logo_file', 'client')) {
        $logo = $new;
    } elseif (!empty($_POST['logo_url_input'])) {
        $logo = mysqli_real_escape_string($conn, $_POST['logo_url_input']);
    }
    $epid = getEditingProfileId($conn);
    $q = $id ? "UPDATE client_logos SET client_name='$name', logo_url='$logo', display_order='$order' WHERE id=$id" : "INSERT INTO client_logos (client_name, logo_url, display_order, profile_id) VALUES ('$name', '$logo', '$order', $epid)";
    if(mysqli_query($conn, $q)) jsonResponse('success', 'Client saved');
}

// --- 5. SKILL ---
if ($action == 'save_skill') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $id = (int)$_POST['id'];
    $cat = mysqli_real_escape_string($conn, $_POST['category']);
    $name = mysqli_real_escape_string($conn, $_POST['skill_name']);
    $name_en = mysqli_real_escape_string($conn, $_POST['skill_name_en'] ?? '');
    $icon = mysqli_real_escape_string($conn, $_POST['icon_url']);
    $epid = getEditingProfileId($conn);
    $q = $id ? "UPDATE skills SET category='$cat', skill_name='$name', skill_name_en='$name_en', icon_url='$icon' WHERE id=$id" : "INSERT INTO skills (category, skill_name, skill_name_en, icon_url, profile_id) VALUES ('$cat', '$name', '$name_en', '$icon', $epid)";
    if(mysqli_query($conn, $q)) jsonResponse('success', 'Skill saved');
}

// --- 6. EXPERIENCE ---
if ($action == 'save_experience') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $id = (int)$_POST['id'];
    $role = mysqli_real_escape_string($conn, $_POST['role']);
    $comp = mysqli_real_escape_string($conn, $_POST['company']);
    $year = mysqli_real_escape_string($conn, $_POST['year_range']);
    $year_en = mysqli_real_escape_string($conn, $_POST['year_range_en'] ?? '');
    $role_en = mysqli_real_escape_string($conn, $_POST['role_en'] ?? '');
    $desc = mysqli_real_escape_string($conn, $_POST['description']);
    $desc_en = mysqli_real_escape_string($conn, $_POST['description_en'] ?? '');
    $epid = getEditingProfileId($conn);
    $q = $id ? "UPDATE experience SET role='$role', role_en='$role_en', company='$comp', year_range='$year', year_range_en='$year_en', description='$desc', description_en='$desc_en' WHERE id=$id" : "INSERT INTO experience (role, role_en, company, year_range, year_range_en, description, description_en, profile_id) VALUES ('$role', '$role_en', '$comp', '$year', '$year_en', '$desc', '$desc_en', $epid)";
    if(mysqli_query($conn, $q)) jsonResponse('success', 'Experience saved');
}

// --- 7. EDUCATION ---
if ($action == 'save_education') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $id = (int)$_POST['id'];
    $sch = mysqli_real_escape_string($conn, $_POST['school_name']);
    $deg = mysqli_real_escape_string($conn, $_POST['degree']);
    $deg_en = mysqli_real_escape_string($conn, $_POST['degree_en'] ?? '');
    $year = mysqli_real_escape_string($conn, $_POST['year_range']);
    $year_en = mysqli_real_escape_string($conn, $_POST['year_range_en'] ?? '');
    $epid = getEditingProfileId($conn);
    $q = $id ? "UPDATE education SET school_name='$sch', degree='$deg', degree_en='$deg_en', year_range='$year', year_range_en='$year_en' WHERE id=$id" : "INSERT INTO education (school_name, degree, degree_en, year_range, year_range_en, profile_id) VALUES ('$sch', '$deg', '$deg_en', '$year', '$year_en', $epid)";
    if(mysqli_query($conn, $q)) jsonResponse('success', 'Education saved');
}

// --- 8. ARTICLE ---
if ($action == 'save_article') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $id = (int)$_POST['id'];
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $title_en = mysqli_real_escape_string($conn, $_POST['title_en'] ?? '');
    $cont = mysqli_real_escape_string($conn, $_POST['content']);
    $cont_en = mysqli_real_escape_string($conn, $_POST['content_en'] ?? '');
    $raw_slug = !empty($_POST['slug']) ? $_POST['slug'] : $title;
    $slug = createSlug($raw_slug, $conn, 'articles', $id);
    $meta_desc = mysqli_real_escape_string($conn, $_POST['meta_desc']);
    $meta_desc_en = mysqli_real_escape_string($conn, $_POST['meta_desc_en'] ?? '');
    $img = $_POST['old_article_image']; if($new = uploadFile('article_image_file', 'blog')) $img = $new;
    $epid = getEditingProfileId($conn);
    $q = $id ? "UPDATE articles SET title='$title', title_en='$title_en', slug='$slug', meta_desc='$meta_desc', meta_desc_en='$meta_desc_en', content='$cont', content_en='$cont_en', image_url='$img' WHERE id=$id" : "INSERT INTO articles (title, title_en, slug, meta_desc, meta_desc_en, content, content_en, image_url, profile_id) VALUES ('$title', '$title_en', '$slug', '$meta_desc', '$meta_desc_en', '$cont', '$cont_en', '$img', $epid)";
    if(mysqli_query($conn, $q)) jsonResponse('success', 'Article saved');
}

// --- 9. CHAT ---
if ($action == 'save_chat') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $id = (int)$_POST['id'];
    $question = mysqli_real_escape_string($conn, $_POST['question']);
    $question_en = mysqli_real_escape_string($conn, $_POST['question_en'] ?? '');
    $answer = mysqli_real_escape_string($conn, $_POST['answer']);
    $answer_en = mysqli_real_escape_string($conn, $_POST['answer_en'] ?? '');
    $type = mysqli_real_escape_string($conn, $_POST['type']);
    $order = (int)$_POST['display_order'];
    $epid = getEditingProfileId($conn);
    $q = $id ? "UPDATE hero_chat SET question='$question', question_en='$question_en', answer='$answer', answer_en='$answer_en', type='$type', display_order='$order' WHERE id=$id" : "INSERT INTO hero_chat (question, question_en, answer, answer_en, type, display_order, profile_id) VALUES ('$question', '$question_en', '$answer', '$answer_en', '$type', '$order', $epid)";
    if(mysqli_query($conn, $q)) jsonResponse('success', 'Chat saved');
}

// --- ANALYTICS TRACKING ---
if ($action === 'track_event') {
    $event_type = mysqli_real_escape_string($conn, $_POST['event_type'] ?? 'unknown');
    $event_key = mysqli_real_escape_string($conn, $_POST['event_key'] ?? 'unknown');
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT'] ?? '');
    $referer = mysqli_real_escape_string($conn, $_SERVER['HTTP_REFERER'] ?? null);
    $profile_id = (int)($_POST['profile_id'] ?? 1);
    $q = "INSERT INTO web_analytics (event_type, event_key, ip_address, user_agent, referer, profile_id) VALUES ('$event_type', '$event_key', '$ip_address', '$user_agent', " . ($referer !== null ? "'$referer'" : 'NULL') . ", $profile_id)";
    mysqli_query($conn, $q);
    if($is_ajax) jsonResponse('success', 'Tracked');
    header('Content-Type: application/json'); echo json_encode(['status'=>'success']); exit;
}

// --- DELETE HANDLER ---
if ($delete > 0 && $type) {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $validTables = ['projects', 'messages', 'skills', 'experience', 'education', 'articles', 'impact_metrics', 'client_logos', 'hero_chat'];
    if(in_array($type, $validTables)){
        // Save to trash before deleting
        $trashId = null;
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM `$type` WHERE id=$delete"));
        if ($row) {
            $rowJson  = mysqli_real_escape_string($conn, json_encode($row, JSON_UNESCAPED_UNICODE));
            $rowPid   = isset($row['profile_id']) ? (int)$row['profile_id'] : 'NULL';
            mysqli_query($conn, "INSERT INTO deleted_items (table_name, row_data, profile_id) VALUES ('$type', '$rowJson', $rowPid)");
            $trashId  = (int)mysqli_insert_id($conn);
            // Auto-purge trash older than 30 days
            mysqli_query($conn, "DELETE FROM deleted_items WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        }

        if($type == 'projects') {
            mysqli_query($conn, "DELETE FROM projects WHERE id=$delete");
            mysqli_query($conn, "DELETE FROM project_images WHERE project_id=$delete");
        } else {
            mysqli_query($conn, "DELETE FROM `$type` WHERE id=$delete");
        }
        jsonResponse('success', 'Data berhasil dihapus', ['trash_id' => $trashId]);
    }
}

// --- RESTORE DELETED ---
if ($action === 'restore_deleted') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $trashId = (int)$_POST['trash_id'];
    $item = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM deleted_items WHERE id=$trashId"));
    if (!$item) jsonResponse('error', 'Data tidak ditemukan atau sudah dipulihkan');

    $tbl     = $item['table_name'];
    $rowData = json_decode($item['row_data'], true);
    if (!$rowData) jsonResponse('error', 'Data rusak, tidak bisa dipulihkan');

    // profile table uses UPDATE (singleton row), all others use INSERT
    if ($tbl === 'profile') {
        $rowId = (int)($rowData['id'] ?? 0);
        if (!$rowId) jsonResponse('error', 'ID profil tidak valid');
        $sets = [];
        foreach ($rowData as $col => $val) {
            if ($col === 'id') continue;
            $sets[] = "`$col`='" . mysqli_real_escape_string($conn, (string)$val) . "'";
        }
        $r = mysqli_query($conn, "UPDATE profile SET " . implode(',', $sets) . " WHERE id=$rowId");
        if (!$r) jsonResponse('error', 'Gagal memulihkan: ' . mysqli_error($conn));
    } else {
        // Build INSERT (try with original id first)
        $insertRow = function($data) use ($conn, $tbl) {
            $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($data)));
            $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$v) . "'", array_values($data)));
            return mysqli_query($conn, "INSERT INTO `$tbl` ($cols) VALUES ($vals)");
        };

        $r = $insertRow($rowData);
        if (!$r) {
            // Duplicate id — retry without id
            unset($rowData['id']);
            $r = $insertRow($rowData);
            if (!$r) jsonResponse('error', 'Gagal memulihkan: ' . mysqli_error($conn));
        }
    }

    mysqli_query($conn, "DELETE FROM deleted_items WHERE id=$trashId");
    jsonResponse('success', 'Data berhasil dipulihkan');
}

// --- SAVE SETTING (API Keys, etc.) ---
if ($action == 'save_setting') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $key   = mysqli_real_escape_string($conn, $_POST['setting_key'] ?? '');
    $value = mysqli_real_escape_string($conn, $_POST['setting_value'] ?? '');
    if (empty($key)) jsonResponse('error', 'Setting key kosong.');
    mysqli_query($conn, "INSERT INTO site_settings (setting_key, setting_value) VALUES ('$key', '$value')
        ON DUPLICATE KEY UPDATE setting_value = '$value'");
    jsonResponse('success', 'Setting berhasil disimpan.');
}

// --- HELPER: one cURL call to Gemini ---
function geminiRequest($apiKey, $url, $body, $timeout) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    return [$response, $httpCode, $curlErr];
}

// --- HELPER: call Gemini API — multi-key rotation + auto-discover working model ---
// $apiKeys can be a single string or an array of strings.
// On quota/rate-limit (RESOURCE_EXHAUSTED / 429) the next key is tried automatically.
function callGemini($apiKeys, $requestBody, $timeout = 30) {
    // Normalize to array
    if (is_string($apiKeys)) $apiKeys = [trim($apiKeys)];
    $apiKeys = array_values(array_filter(array_map('trim', $apiKeys)));
    if (empty($apiKeys)) return ['ok' => false, 'error' => 'Tidak ada API key Gemini yang tersedia.'];

    $globalErrors = [];

    foreach ($apiKeys as $keyIndex => $apiKey) {
        // Step 1: Ask Google which models are available for this key (GET request)
        $listCh = curl_init("https://generativelanguage.googleapis.com/v1beta/models?pageSize=100");
        curl_setopt_array($listCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => ['x-goog-api-key: ' . $apiKey],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $listResp = curl_exec($listCh);
        $listCode = curl_getinfo($listCh, CURLINFO_HTTP_CODE);

        $availableModels = [];
        if ($listCode === 200 && $listResp) {
            $listData = json_decode($listResp, true);
            foreach (($listData['models'] ?? []) as $m) {
                $name = $m['name'] ?? ''; // e.g. "models/gemini-3.6-flash"
                if (stripos($name, 'flash') !== false &&
                    in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) {
                    $availableModels[] = str_replace('models/', '', $name);
                }
            }
            usort($availableModels, function($a, $b) {
                $score = function($n) {
                    $s = 0;
                    if (stripos($n, 'preview') !== false || stripos($n, 'exp') !== false) $s -= 10;
                    if (stripos($n, 'lite') !== false) $s -= 5;
                    if (stripos($n, 'latest') !== false) $s += 20;
                    if (preg_match('/gemini-(\d+)\.(\d+)/', $n, $m)) $s += (int)$m[1] * 10 + (int)$m[2];
                    return $s;
                };
                return $score($b) - $score($a);
            });
        }

        if (empty($availableModels)) {
            $availableModels = ['gemini-3.6-flash', 'gemini-flash-latest', 'gemini-3.5-flash'];
        }

        $keyLabel  = 'Key #' . ($keyIndex + 1);
        $keyErrors = [];
        $quotaExhausted = false;

        foreach ($availableModels as $model) {
            foreach (['v1beta', 'v1'] as $ver) {
                $url = "https://generativelanguage.googleapis.com/{$ver}/models/{$model}:generateContent";
                [$response, $httpCode, $curlErr] = geminiRequest($apiKey, $url, $requestBody, $timeout);

                if (!$response) {
                    $keyErrors[] = "{$model}: cURL - " . ($curlErr ?: 'no response');
                    continue;
                }

                $data   = json_decode($response, true);
                $text   = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                $errMsg = $data['error']['message'] ?? null;

                if ($httpCode === 200 && $text !== null) {
                    return ['ok' => true, 'text' => $text, 'model' => "{$ver}/{$model}", 'key_index' => $keyIndex];
                }

                // Quota exhausted — mark and break to try next key
                if ($httpCode === 429 ||
                    stripos((string)$errMsg, 'RESOURCE_EXHAUSTED') !== false ||
                    stripos((string)$errMsg, 'quota') !== false) {
                    $quotaExhausted = true;
                    $keyErrors[] = "{$keyLabel} quota habis";
                    break 2; // break both foreach loops, move to next key
                }

                // Auth/permission errors — try next key (one bad key shouldn't block others)
                if (in_array($httpCode, [401, 403]) ||
                    stripos((string)$errMsg, 'denied') !== false ||
                    stripos((string)$errMsg, 'API key not valid') !== false ||
                    stripos((string)$errMsg, 'PERMISSION_DENIED') !== false) {
                    $keyErrors[] = "{$keyLabel}: " . geminiUserFriendlyError($errMsg);
                    break 2; // try next key
                }

                if ($errMsg) $keyErrors[] = "{$ver}/{$model}: {$errMsg}";
                break; // non-404 API error on v1beta — skip v1 for this model
            }
        }

        $globalErrors = array_merge($globalErrors, $keyErrors);

        // If quota exhausted and there are more keys, continue silently
        // Otherwise (no quota issue, no more models worked), keep trying remaining keys
    }

    $summary = implode(' | ', array_slice($globalErrors, 0, 5));
    $keyCount = count($apiKeys);
    if ($keyCount > 1) {
        return ['ok' => false, 'error' => "Semua {$keyCount} API key tidak dapat digunakan. Detail: {$summary}"];
    }
    return ['ok' => false, 'error' => "Tidak ada model yang tersedia. Detail: {$summary}"];
}

// --- HELPER: friendly error message ---
function geminiUserFriendlyError($msg) {
    if (stripos($msg, 'denied') !== false || stripos($msg, 'PERMISSION_DENIED') !== false) {
        return "API key ditolak Google: \"{$msg}\"\n\nSolusi: Buka https://aistudio.google.com/app/apikey → buat API key baru → simpan di Settings.";
    }
    if (stripos($msg, 'API key not valid') !== false || stripos($msg, 'INVALID_ARGUMENT') !== false) {
        return "API key tidak valid. Pastikan key disalin lengkap dari Google AI Studio.";
    }
    if (stripos($msg, 'quota') !== false || stripos($msg, 'RESOURCE_EXHAUSTED') !== false) {
        return "Kuota API habis untuk hari ini. Coba lagi besok atau upgrade plan Google AI.";
    }
    return $msg;
}

// --- TEST AI KEY (lightweight ping) ---
if ($action == 'test_ai_key') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $testKey = trim($_POST['api_key'] ?? '');
    if (empty($testKey)) jsonResponse('error', 'API key kosong.');

    $testBody = json_encode([
        'contents'        => [['parts' => [['text' => 'Reply with exactly one word: OK']]]],
        'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 5],
    ]);

    $result = callGemini($testKey, $testBody, 15);

    if ($result['ok']) {
        jsonResponse('success', 'Koneksi berhasil! Model: ' . $result['model']);
    } else {
        jsonResponse('error', $result['error']);
    }
}

// --- AI GENERATE PROJECT ---
if ($action == 'ai_generate_project') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');

    @set_time_limit(180); // override PHP max_execution_time for this heavy request

    $topic = trim($_POST['topic'] ?? '');
    if (empty($topic)) jsonResponse('error', 'Topik tidak boleh kosong.');
    // Cap brief length so a huge paste does not blow the output budget
    $topic = mb_substr($topic, 0, 2500);

    // Load multi-key array; fall back to legacy single-key setting
    $keysRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT setting_value FROM site_settings WHERE setting_key = 'gemini_api_keys' LIMIT 1"));
    $apiKeys = [];
    if (!empty($keysRow['setting_value'])) {
        $decoded = json_decode($keysRow['setting_value'], true);
        if (is_array($decoded)) $apiKeys = array_values(array_filter(array_map('trim', $decoded)));
    }
    if (empty($apiKeys)) {
        $legacyRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT setting_value FROM site_settings WHERE setting_key = 'gemini_api_key' LIMIT 1"));
        $legacy = trim($legacyRow['setting_value'] ?? '');
        if ($legacy) $apiKeys = [$legacy];
    }
    if (empty($apiKeys)) jsonResponse('error', 'API key Gemini belum diatur. Silakan set di menu Settings.');

    $prompt = <<<PROMPT
Kamu adalah senior copywriter & UX writer portfolio digital agency Asia Tenggara dengan 10 tahun pengalaman menulis studi kasus untuk klien B2B.
Keahlianmu: menulis studi kasus yang menjual — bukan deskripsi teknis, melainkan narasi yang membuat calon klien merasa "ini persis yang saya butuhkan."

=== BRIEF PROYEK DARI USER (sumber kebenaran tunggal) ===
{$topic}
=== AKHIR BRIEF ===

LANGKAH 0 — ANALISIS BRIEF (lakukan di kepalamu, JANGAN tampilkan hasilnya):
Tetapkan dulu, dan patuhi konsisten sampai field terakhir:
- Industri & model bisnis klien (mis. SaaS B2B, klinik gigi, logistik last-mile)
- Pelanggan akhir yang dilayani klien tersebut
- Masalah bisnis paling mahal yang diselesaikan proyek ini
- Deliverable konkret yang dibangun (bukan kategori, tapi wujudnya)
- 2 metrik paling relevan untuk industri itu → dipakai di chart
- Tumpukan teknologi yang masuk akal untuk deliverable tersebut
ATURAN SUMBER: jika brief menyebut nama, angka, fitur, teknologi, atau segmen spesifik — pakai APA ADANYA, jangan diganti atau digeneralisasi.
Jika brief ambigu: pilih SATU interpretasi paling spesifik lalu konsisten. Jangan pernah memilih jawaban generik yang cocok untuk semua industri.

ATURAN PENULISAN (wajib):
1. KESETIAAN TOPIK: setiap section WAJIB menyebut minimal satu istilah konkret dari brief (nama fitur, segmen, teknologi, atau metrik khas industri itu). Jika sebuah kalimat bisa dipindah ke studi kasus industri lain tanpa perubahan, kalimat itu salah — tulis ulang.
2. DILARANG KLAIM TANPA ANGKA: setiap section HTML minimal memuat 1 angka spesifik (persen, durasi, volume, biaya, jumlah). Gunakan angka tidak bulat yang terasa terukur (contoh: "turun dari 4.100 menjadi 2.540 tiket/bulan", bukan "turun drastis").
3. KLAUSA TERLARANG — jangan pernah menulis frasa ini (ID maupun padanan EN-nya): solusi inovatif, meningkatkan efisiensi, user-friendly, seamless, cutting-edge, state-of-the-art, game-changer, robust, leverage, unlock potential, transformasi digital, one-stop solution, mudah digunakan, modern dan responsif, solusi terbaik, sesuai kebutuhan, tidak hanya... tetapi juga. Ganti selalu dengan detail konkret.
4. ANGKA REALISTIS: kenaikan/penurunan harus wajar untuk industrinya (umumnya 10–80%, bukan 500%). Tidak semua metrik naik — sisipkan satu trade-off atau biaya yang muncul (mis. "waktu produksi konten naik 20% di awal").
5. Tone: profesional tapi tidak kaku, seperti bicara langsung ke decision maker. Variasikan panjang kalimat — hindari tiga kalimat beruntun dengan pola sama.
6. Bahasa Indonesia: natural, bukan terjemahan mesin. Idiom bisnis Indonesia yang wajar, tanpa kata asing yang tidak perlu.
7. Bahasa Inggris: DITULIS ULANG, bukan diterjemahkan kata per kata. Nada marketing internasional yang lebih ringkas. Angka tetap identik dengan versi ID.
8. client_name: nama perusahaan fiktif yang terdengar nyata dan cocok industrinya (bukan "PT ABC", bukan "Company X"). Sertakan bentuk badan usaha yang wajar.
9. result_text: badge metrik yang tajam, 2–5 kata, pakai angka (contoh: "Churn -38%", "ROI 4.2x", "Deploy 10x Lebih Cepat").
10. slug: lowercase, pisah tanda hubung, tanpa kata depan, maks 6 kata, unik dan deskriptif.
11. Kutip hanya tanda kutip lurus (") dan hindari karakter non-ASCII selain tanda hubung dan tanda persen. Jangan pakai emoji.
12. JSON harus valid: setiap tanda kutip di dalam nilai di-escape sebagai \". Jangan menambahkan key di luar daftar.

ANGGARAN KATA — TOTAL SEMUA FIELD (ID + EN) MAKSIMAL 3000 KATA. Patuhi batas per field ini:
- title + title_en: 16 kata
- description + description_en: 85 kata
- problem: 300 kata (3 paragraf) | problem_en: 250 kata (3 paragraf)
- solution: 300 kata (3 paragraf) | solution_en: 250 kata (3 paragraf)
- details: 850 kata | details_en: 700 kata
- meta_desc + meta_desc_en: 45 kata
- result_text + result_text_en: 12 kata
- category, category_en, client_name, tech_stacks, slug: 25 kata
Isi setiap field mendekati batasnya — jangan berhenti di separuh anggaran. Bila total mendekati 3000 kata, padatkan kalimat, jangan hapus section.

STRUKTUR FIELD HTML (details & details_en) — 5 section berurutan, heading persis seperti ini:
ID: <h3>Latar Belakang & Tantangan</h3> → <h3>Strategi & Pendekatan</h3> → <h3>Implementasi Teknis</h3> → <h3>Hasil & Dampak Bisnis</h3> → <h3>Pelajaran & Insight</h3>
EN: <h3>Background & Challenge</h3> → <h3>Strategy & Approach</h3> → <h3>Technical Implementation</h3> → <h3>Results & Business Impact</h3> → <h3>Lessons & Insights</h3>
Aturan isi HTML:
- Setiap section: 1 paragraf <p> (3–5 kalimat) + <ul> dengan 3–5 <li> konkret. Section "Hasil & Dampak Bisnis" wajib memuat 3 <li> berisi angka sebelum → sesudah.
- Gunakan HANYA tag <h3>, <p>, <ul>, <li>, <strong>. Tanpa <div>, <span>, class, atau style.
- Setiap <li>: satu fakta spesifik dengan angka atau nama fitur. Bukan kalimat slogan.
- Section "Pelajaran & Insight" boleh 1 paragraf tanpa <ul>, tetapi harus menyebut satu keputusan konkret yang akan diubah di proyek berikutnya.

CHART: chart_data_json berisi 2–4 label yang menamai metrik NYATA proyek ini (bukan "Sebelum/Sesudah" saja) dengan angka wajar. Nilai "data" harus angka (number), bukan string. Versi EN memakai label bahasa Inggris tetapi angka identik.

Sebelum menulis output, verifikasi diam-diam: (a) total kata ≤ 3000; (b) tiap section punya angka dan istilah dari brief; (c) tidak ada frasa terlarang; (d) JSON valid tanpa koma menggantung.

OUTPUT: kembalikan HANYA satu JSON object valid. Tanpa teks sebelum/sesudah. Tanpa markdown. Tanpa kode blok. Langsung mulai dengan karakter { dan akhiri dengan }.

FORMAT JSON (ikuti persis key berikut, jangan ubah nama key):
{
  "title": "Judul proyek — spesifik, mencerminkan hasil, maks 8 kata",
  "title_en": "Project title in English — outcome-focused, max 8 words",
  "category": "Kategori (pilih: Web Development / Mobile App / UI/UX Design / Digital Marketing / SEO / Branding / E-commerce / SaaS / Automation / Data Analytics)",
  "category_en": "Category in English (same options, translated)",
  "client_name": "Nama perusahaan fiktif yang relevan dengan industri",
  "result_text": "Badge hasil: angka + dampak, maks 5 kata (contoh: Konversi Naik 67%)",
  "result_text_en": "Result badge in English: metric + impact, max 5 words",
  "description": "2 kalimat: kalimat 1 = konteks bisnis klien + tantangan utama. Kalimat 2 = apa yang dibangun dan dampaknya.",
  "description_en": "2 sentences: sentence 1 = business context + core challenge. Sentence 2 = what was built and its impact.",
  "link_url": "",
  "tech_stacks": "Teknologi dipisah koma, spesifik dan relevan (contoh: Next.js, Tailwind CSS, Supabase, Midtrans, Vercel)",
  "slug": "slug-url-dari-judul-maks-6-kata",
  "meta_desc": "Meta SEO ID: mulai dengan kata kerja aktif, sertakan manfaat utama, maks 155 karakter",
  "meta_desc_en": "SEO meta EN: start with active verb, include main benefit, max 155 characters",
  "problem": "3 paragraf naratif. §1: kondisi bisnis klien sebelum proyek (spesifik industrinya). §2: pain point teknis/operasional yang paling kritis. §3: dampak kerugian yang terjadi akibat masalah tersebut (kuantitatif jika memungkinkan).",
  "problem_en": "3 narrative paragraphs. §1: client's business context before project. §2: the critical technical/operational pain point. §3: cost of the problem — quantified where possible.",
  "solution": "3 paragraf naratif. §1: pendekatan strategis yang dipilih dan alasannya. §2: implementasi teknis kunci yang membedakan dari solusi generik. §3: hasil akhir dan nilai bisnis yang dicapai.",
  "solution_en": "3 narrative paragraphs. §1: strategic approach chosen and why. §2: key technical implementation differentiators. §3: final outcome and business value delivered.",
  "details": "<h3>Latar Belakang & Tantangan</h3><p>...</p><ul><li>...</li></ul><h3>Strategi & Pendekatan</h3><p>...</p><ul><li>...</li></ul><h3>Implementasi Teknis</h3><p>...</p><ul><li>...</li></ul><h3>Hasil & Dampak Bisnis</h3><p>...</p><ul><li>...</li></ul><h3>Pelajaran & Insight</h3><p>...</p>",
  "details_en": "<h3>Background & Challenge</h3><p>...</p><ul><li>...</li></ul><h3>Strategy & Approach</h3><p>...</p><ul><li>...</li></ul><h3>Technical Implementation</h3><p>...</p><ul><li>...</li></ul><h3>Results & Business Impact</h3><p>...</p><ul><li>...</li></ul><h3>Lessons & Insights</h3><p>...</p>",
  "chart_data_json": "{\"labels\":[\"Label metrik sebelum\",\"Label metrik sesudah\"],\"data\":[angka_sebelum,angka_sesudah]}",
  "chart_data_json_en": "{\"labels\":[\"Metric label before\",\"Metric label after\"],\"data\":[same_before,same_after]}"
}
PROMPT;

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => 'Kamu senior copywriter portfolio digital agency. Tulis studi kasus yang spesifik pada brief user — bukan template generik yang bisa dipakai industri lain. Total seluruh field maksimal 3000 kata. Output HANYA JSON valid, tanpa teks atau markdown di luar JSON.']]],
        'contents'           => [['parts' => [['text' => $prompt]]]],
        'generationConfig'   => [
            'temperature'      => 0.9,
            'topP'             => 0.95,
            'maxOutputTokens'  => 8192,
            'responseMimeType' => 'application/json',
        ],
    ]);

    $result = callGemini($apiKeys, $payload, 120);

    if (!$result['ok']) {
        jsonResponse('error', $result['error']);
    }

    $rawText = trim($result['text']);
    // Strip markdown code fences
    $rawText = preg_replace('/^```(?:json)?\s*/iu', '', $rawText);
    $rawText = preg_replace('/\s*```$/u', '', trim($rawText));
    $rawText = trim($rawText);

    $projectData = json_decode($rawText, true);

    // Truncated JSON recovery: find last valid closing brace
    if (!is_array($projectData)) {
        $lastBrace = strrpos($rawText, '}');
        if ($lastBrace !== false) {
            $truncated = substr($rawText, 0, $lastBrace + 1);
            $projectData = json_decode($truncated, true);
        }
    }

    if (!is_array($projectData)) {
        jsonResponse('error', 'Gagal parse JSON dari Gemini. Coba generate ulang. Preview: ' . mb_substr($rawText, 0, 300));
    }

    // Normalize chart JSON fields — ensure they are plain strings
    foreach (['chart_data_json', 'chart_data_json_en'] as $chartKey) {
        if (isset($projectData[$chartKey]) && is_array($projectData[$chartKey])) {
            $projectData[$chartKey] = json_encode($projectData[$chartKey]);
        }
    }

    // Plain-text fields: strip any stray markup the model produced
    foreach (['title', 'title_en', 'category', 'category_en', 'client_name', 'result_text',
              'result_text_en', 'description', 'description_en', 'tech_stacks',
              'meta_desc', 'meta_desc_en', 'problem', 'problem_en', 'solution', 'solution_en'] as $plainKey) {
        if (!isset($projectData[$plainKey]) || !is_string($projectData[$plainKey])) continue;
        $val = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $projectData[$plainKey]));
        $val = trim(preg_replace('/[ \t]+/', ' ', $val));
        $projectData[$plainKey] = $val;
    }

    // HTML fields: keep only the tags the detail template renders
    foreach (['details', 'details_en'] as $htmlKey) {
        if (!isset($projectData[$htmlKey]) || !is_string($projectData[$htmlKey])) continue;
        $html = strip_tags($projectData[$htmlKey], '<h3><p><ul><li><strong>');
        $html = preg_replace('/<(?!\/?(?:h3|p|ul|li|strong)\b)[^>]*>/i', '', $html);
        $projectData[$htmlKey] = trim($html);
    }

    // Meta descriptions must respect the 155-char SEO limit
    foreach (['meta_desc', 'meta_desc_en'] as $metaKey) {
        if (!empty($projectData[$metaKey])) {
            $projectData[$metaKey] = mb_substr($projectData[$metaKey], 0, 155);
        }
    }

    // Slug: keep it URL-safe and short
    if (!empty($projectData['slug'])) {
        $slug = strtolower($projectData['slug']);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim(preg_replace('/-+/', '-', $slug), '-');
        $projectData['slug'] = implode('-', array_slice(explode('-', $slug), 0, 6));
    }

    // Chart payloads must hold numeric data arrays, otherwise the chart breaks
    foreach (['chart_data_json', 'chart_data_json_en'] as $chartKey) {
        $chart = json_decode($projectData[$chartKey] ?? '', true);
        if (!is_array($chart) || empty($chart['labels']) || empty($chart['data'])) {
            unset($projectData[$chartKey]);
            continue;
        }
        $chart['data'] = array_values(array_map('floatval', $chart['data']));
        $projectData[$chartKey] = json_encode($chart);
    }

    jsonResponse('success', 'Berhasil generate konten AI.', $projectData);
}

// --- GENERATE CV ---
if ($action == 'generate_cv') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    @set_time_limit(180);

    $mode   = in_array($_POST['mode'] ?? '', ['generate','rewrite','tailor','ats']) ? $_POST['mode'] : 'generate';
    $lang   = ($_POST['lang'] ?? 'id') === 'en' ? 'en' : 'id';
    $jd     = mb_substr(trim($_POST['job_description'] ?? ''), 0, 2000);
    $existingCv = $_POST['existing_cv'] ?? '';
    $profileId  = (int)($_SESSION['editing_profile_id'] ?? 1);

    // Load multi-key array
    $keysRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT setting_value FROM site_settings WHERE setting_key = 'gemini_api_keys' LIMIT 1"));
    $apiKeys = [];
    if (!empty($keysRow['setting_value'])) {
        $dec = json_decode($keysRow['setting_value'], true);
        if (is_array($dec)) $apiKeys = array_values(array_filter(array_map('trim', $dec)));
    }
    if (empty($apiKeys)) {
        $lr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT setting_value FROM site_settings WHERE setting_key = 'gemini_api_key' LIMIT 1"));
        if (!empty($lr['setting_value'])) $apiKeys = [trim($lr['setting_value'])];
    }
    if (empty($apiKeys)) jsonResponse('error', 'API key Gemini belum diatur. Silakan set di menu Settings.');

    // ── Gather profile data ──────────────────────────────────────────────
    $prof = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM profile WHERE id=$profileId"));

    $expRows = []; $er = mysqli_query($conn, "SELECT * FROM experience WHERE profile_id=$profileId ORDER BY id DESC");
    while ($r = mysqli_fetch_assoc($er)) $expRows[] = $r;

    $skillRows = []; $sr = mysqli_query($conn, "SELECT * FROM skills WHERE profile_id=$profileId ORDER BY category");
    while ($r = mysqli_fetch_assoc($sr)) $skillRows[] = $r;

    // Top 6 projects only, enough for CV — avoid token bloat
    $projRows = []; $pr = mysqli_query($conn, "SELECT * FROM projects WHERE profile_id=$profileId ORDER BY display_order ASC LIMIT 6");
    while ($r = mysqli_fetch_assoc($pr)) $projRows[] = $r;

    $eduRows = []; $edr = mysqli_query($conn, "SELECT * FROM education WHERE profile_id=$profileId ORDER BY id DESC LIMIT 3");
    while ($r = mysqli_fetch_assoc($edr)) $eduRows[] = $r;

    // ── Build structured context strings ────────────────────────────────
    $skillsByCategory = [];
    foreach ($skillRows as $s) {
        $cat = $s['category'] ?: 'General';
        $skillsByCategory[$cat][] = $lang === 'en' ? ($s['skill_name_en'] ?: $s['skill_name']) : $s['skill_name'];
    }
    $skillsContext = [];
    foreach ($skillsByCategory as $cat => $names) $skillsContext[] = "  {$cat}: " . implode(', ', $names);

    // Experience: pass the EXACT description from DB as "source bullets"
    // AI must rewrite/strengthen them — never invent new facts
    $expContext = [];
    foreach ($expRows as $e) {
        $role = $lang === 'en' ? ($e['role_en'] ?: $e['role']) : $e['role'];
        $desc = $lang === 'en' ? ($e['description_en'] ?: $e['description']) : $e['description'];
        $yr   = $lang === 'en' ? ($e['year_range_en'] ?: $e['year_range']) : $e['year_range'];
        $expContext[] = "  Perusahaan : {$e['company']}\n"
            . "  Periode    : {$yr}\n"
            . "  Jabatan    : {$role}\n"
            . "  Deskripsi  :\n" . preg_replace('/^/m', '    ', trim($desc));
    }

    // Projects: title, client, tech, result badge — NO solution text (too long)
    $projContext = [];
    foreach ($projRows as $idx => $p) {
        $ttl = $lang === 'en' ? ($p['title_en'] ?: $p['title']) : $p['title'];
        $res = $lang === 'en' ? ($p['result_text_en'] ?: $p['result_text']) : $p['result_text'];
        $projContext[] = "  " . ($idx+1) . ". {$ttl} | Klien: {$p['client_name']} | Tech: {$p['tech_stacks']} | Hasil: {$res}";
    }

    // Education
    $eduContext = [];
    foreach ($eduRows as $e) {
        $deg = $lang === 'en' ? ($e['degree_en'] ?: $e['degree']) : $e['degree'];
        $yr  = $lang === 'en' ? ($e['year_range_en'] ?: $e['year_range']) : $e['year_range'];
        $eduContext[] = "  {$e['school_name']} | {$deg} | {$yr}";
    }

    $heroRole = $lang === 'en' ? ($prof['hero_role_en'] ?: $prof['hero_role']) : $prof['hero_role'];
    $bio      = $lang === 'en' ? ($prof['bio_en']      ?: $prof['bio'])      : $prof['bio'];

    $profileContext = "IDENTITAS:\n"
        . "  Nama      : {$prof['name']}\n"
        . "  Profesi   : {$heroRole}\n"
        . "  Bio       : {$bio}\n"
        . "  Email     : {$prof['email']}\n"
        . "  WhatsApp  : {$prof['whatsapp']}\n"
        . "  LinkedIn  : {$prof['link_linkedin']}\n"
        . "  Portfolio : {$prof['link_github']}\n\n"
        . "SKILLS (gunakan nama persis ini — jangan ubah ejaan):\n"
        . implode("\n", $skillsContext) . "\n\n"
        . "PENGALAMAN KERJA (gunakan data ini sebagai sumber — jangan tambah fakta baru):\n"
        . implode("\n\n", $expContext) . "\n\n"
        . "PROYEK UNGGULAN (" . count($projRows) . " proyek):\n"
        . implode("\n", $projContext)
        . (count($eduContext) ? "\n\nPENDIDIKAN:\n" . implode("\n", $eduContext) : "");

    // ── Language & mode setup ────────────────────────────────────────────
    $isEn = $lang === 'en';

    if ($isEn) {
        $outputNote = "LANGUAGE: Write every text field in natural English. Do NOT translate literally from Indonesian — rewrite idioms in international professional English.";
        $bannedNote = "BANNED WORDS/PHRASES (never use): passionate, dynamic, results-driven, synergy, leveraged, spearheaded, innovative solutions, fast learner, team player, responsible for, involved in, worked on. Use specific verbs and concrete numbers instead.";
    } else {
        $outputNote = "BAHASA: Tulis semua teks dalam Bahasa Indonesia profesional dan natural. Bukan terjemahan kaku dari Inggris.";
        $bannedNote = "KATA/FRASA TERLARANG (jangan pakai): berdedikasi, proaktif, passionate, inovatif (tanpa bukti), bertanggung jawab atas, terlibat dalam, memiliki kemampuan komunikasi yang baik, fast learner, team player, berpengalaman di bidangnya. Ganti dengan kata kerja aksi dan angka konkret.";
    }

    $modeInstruction = match($mode) {
        'rewrite' => $isEn
            ? "MODE: REWRITE\nStrengthen every bullet with active verbs and specific metrics ALREADY in the source data. Do NOT add facts that don't exist in the data. Remove filler words and clichés."
            : "MODE: REWRITE\nPerkuat setiap bullet dengan kata kerja aktif dan metrik yang SUDAH ADA di data sumber. JANGAN tambah fakta yang tidak ada. Hilangkan kata klise dan pengisi.",
        'tailor'  => ($isEn
            ? "MODE: TAILOR TO JD\nReorder and reframe existing bullets to highlight what's most relevant to the JD. Use JD keywords where they authentically match the candidate's real experience. Do NOT invent new experience.\n\nJOB DESCRIPTION:\n===\n{$jd}\n==="
            : "MODE: TAILOR TO JD\nUrutkan ulang dan parafrase bullet yang ada agar paling relevan dengan JD. Gunakan keyword dari JD hanya di tempat yang benar-benar cocok dengan pengalaman nyata. JANGAN karang pengalaman baru.\n\nJOB DESCRIPTION:\n===\n{$jd}\n==="),
        'ats'     => ($isEn
            ? "MODE: ATS OPTIMIZE\nEmbed exact phrases from the JD naturally into summary and bullets — only where the candidate genuinely has that experience. At least 1 JD phrase must appear in the summary. Keep plain text, no special characters.\n\nJOB DESCRIPTION:\n===\n{$jd}\n==="
            : "MODE: ATS OPTIMIZE\nSisipkan exact phrase dari JD secara natural di summary dan bullet — hanya di tempat yang benar-benar sesuai dengan pengalaman. Minimal 1 phrase JD harus muncul di summary. Plain text, tanpa karakter khusus.\n\nJOB DESCRIPTION:\n===\n{$jd}\n==="),
        default   => $isEn
            ? "MODE: GENERATE\nBuild a complete, honest CV from the profile data provided."
            : "MODE: GENERATE\nBuat CV lengkap dan jujur dari data profil yang diberikan.",
    };

    $existingCvContext = '';
    if ($existingCv && $mode !== 'generate') {
        $ctxLimit = in_array($mode, ['tailor','ats']) ? 1200 : 2000;
        $existingCvContext = $isEn
            ? "\n\nPREVIOUS CV (reference only — use to preserve already-good phrasing):\n" . substr($existingCv, 0, $ctxLimit)
            : "\n\nCV SEBELUMNYA (referensi saja — gunakan untuk pertahankan frasa yang sudah baik):\n" . substr($existingCv, 0, $ctxLimit);
    }

    // Compute total experience years from experience data for summary hint
    $firstYearHint = '';
    if (!empty($expRows)) {
        $allYears = [];
        foreach ($expRows as $e) {
            if (preg_match('/(\d{4})/', $e['year_range'], $m)) $allYears[] = (int)$m[1];
        }
        if ($allYears) {
            $startYear = min($allYears);
            $yearsExp  = date('Y') - $startYear;
            $firstYearHint = $isEn
                ? "Experience start year from data: {$startYear} (approx {$yearsExp} years). Use this for the summary — do NOT invent a different number."
                : "Tahun mulai kerja dari data: {$startYear} (sekitar {$yearsExp} tahun pengalaman). Gunakan angka ini di summary — JANGAN karang angka lain.";
        }
    }

    $sysprompt = $isEn
        ? 'You are a senior CV editor who edits real CVs — not an AI that generates placeholder content. Your rule: every fact in the CV must be traceable to the source data. Output valid JSON only.'
        : 'Kamu adalah editor CV senior yang mengedit CV nyata — bukan AI yang membuat konten placeholder. Aturanmu: setiap fakta di CV harus bisa dilacak ke data sumber. Output JSON valid saja.';

    $prompt = <<<PROMPT
{$modeInstruction}

SOURCE DATA (single source of truth — no fabrication allowed):
{$profileContext}
{$existingCvContext}

RULES:
1. {$outputNote}
2. {$bannedNote}
3. ANTI-HALLUCINATION: Only use facts, names, numbers, and technologies that appear in SOURCE DATA. If a metric is not in the data, omit it — do not invent.
4. {$firstYearHint}
5. summary: exactly 3 sentences. S1 = who + specialisation + years of experience (from data). S2 = top technical skills (from skills list). S3 = strongest value proposition + business impact (from experience/projects data).
6. skills: max 5 categories, max 5 items each. Use EXACT skill names from SOURCE DATA — do not rename or add new ones.
7. experience: max 3 entries. Per entry: responsibilities = exactly 3 bullets (rewrite/strengthen from source description — active verb + specific detail). achievements = 1 bullet (the single strongest result from source data, with metric if available in data).  Max 14 words per bullet.
8. projects: choose 6 from source list. description = 1 sentence max 18 words (based on source). results = use the badge from source data exactly or compress it.
9. professional_title: max 6 words, value proposition (e.g. "Digital Marketer & Growth Engineer | AI Tools").
10. Output: valid JSON only. No markdown. No text outside JSON.

OUTPUT JSON:
{
  "personal": {
    "full_name": "string",
    "professional_title": "string",
    "location": "string",
    "phone": "string",
    "email": "string",
    "linkedin": "string",
    "portfolio": "string"
  },
  "summary": "string — exactly 3 sentences",
  "skills": [
    {"category": "string", "skills": ["skill1","skill2","skill3","skill4","skill5"]}
  ],
  "experience": [
    {
      "company": "string",
      "start_date": "string",
      "end_date": "string or null",
      "position": "string",
      "responsibilities": ["bullet 1", "bullet 2", "bullet 3"],
      "achievements": ["single strongest achievement"]
    }
  ],
  "projects": [
    {
      "project_name": "string",
      "client": "string",
      "description": "string — 1 sentence max 18 words",
      "technologies": "string — comma separated",
      "results": "string — compact badge"
    }
  ],
  "education": [
    {
      "institution": "string",
      "degree": "string",
      "year": "string"
    }
  ]
}
PROMPT;

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $sysprompt]]],
        'contents'           => [['parts' => [['text' => $prompt]]]],
        'generationConfig'   => ['temperature' => 0.45, 'maxOutputTokens' => 4096, 'responseMimeType' => 'application/json'],
    ]);

    $result = callGemini($apiKeys, $payload, 120);
    if (!$result['ok']) jsonResponse('error', $result['error']);

    $rawText = trim($result['text']);
    // Strip markdown code fences
    $rawText = preg_replace('/^```(?:json)?\s*/iu', '', $rawText);
    $rawText = preg_replace('/\s*```$/u', '', trim($rawText));

    $cvJson = json_decode($rawText, true);
    if (!is_array($cvJson)) {
        // Use brace-counting extractor — handles prose before/after JSON and } inside string values
        $extracted = extractFirstJsonObject($rawText);
        if ($extracted !== null) $cvJson = json_decode($extracted, true);
    }
    if (!is_array($cvJson)) jsonResponse('error', 'Gagal parse JSON dari Gemini. Coba generate ulang.');

    // Save to cv_history
    $histLabel = date('d M Y, H:i') . ' — ' . strtoupper($mode) . ' (' . strtoupper($lang) . ')';
    $histData  = mysqli_real_escape_string($conn, json_encode($cvJson, JSON_UNESCAPED_UNICODE));
    $histLabel = mysqli_real_escape_string($conn, $histLabel);
    mysqli_query($conn, "INSERT INTO cv_history (profile_id, mode, lang, label, cv_data) VALUES ($profileId, '$mode', '$lang', '$histLabel', '$histData')");
    $histId = (int)mysqli_insert_id($conn);

    jsonResponse('success', 'CV berhasil digenerate.', array_merge($cvJson, ['_history_id' => $histId, '_history_label' => stripslashes($histLabel), '_cv_lang' => $lang]));
}

// --- CV HISTORY: GET ---
if ($action === 'get_cv_history') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $profileId = (int)($_SESSION['editing_profile_id'] ?? 1);
    $rows = [];
    $q = mysqli_query($conn, "SELECT id, mode, lang, label, created_at FROM cv_history WHERE profile_id=$profileId ORDER BY created_at DESC LIMIT 30");
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    jsonResponse('success', '', $rows);
}

// --- CV HISTORY: LOAD ---
if ($action === 'load_cv_history') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $profileId = (int)($_SESSION['editing_profile_id'] ?? 1);
    $hid = (int)($_POST['history_id'] ?? 0);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT cv_data, label, mode, lang FROM cv_history WHERE id=$hid AND profile_id=$profileId LIMIT 1"));
    if (!$r) jsonResponse('error', 'Riwayat tidak ditemukan.');
    $cv = json_decode($r['cv_data'], true);
    if (!is_array($cv)) jsonResponse('error', 'Data CV rusak.');
    jsonResponse('success', $r['label'], array_merge($cv, ['_history_id' => $hid, '_history_label' => $r['label'], '_cv_lang' => $r['lang'] ?: 'id']));
}

// --- CV HISTORY: RENAME ---
if ($action === 'rename_cv_history') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $profileId = (int)($_SESSION['editing_profile_id'] ?? 1);
    $hid   = (int)($_POST['history_id'] ?? 0);
    $label = mysqli_real_escape_string($conn, trim($_POST['label'] ?? ''));
    if (!$label) jsonResponse('error', 'Label tidak boleh kosong.');
    mysqli_query($conn, "UPDATE cv_history SET label='$label' WHERE id=$hid AND profile_id=$profileId");
    jsonResponse('success', 'Label diperbarui.');
}

// --- CV HISTORY: DELETE ---
if ($action === 'delete_cv_history') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $profileId = (int)($_SESSION['editing_profile_id'] ?? 1);
    $hid = (int)($_POST['history_id'] ?? 0);
    mysqli_query($conn, "DELETE FROM cv_history WHERE id=$hid AND profile_id=$profileId");
    jsonResponse('success', 'Riwayat dihapus.');
}

// --- PUBLIC MESSAGE ---
if ($action == 'send_message') {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $msg = mysqli_real_escape_string($conn, $_POST['message']);
    $lang = isset($_POST['lang']) && in_array($_POST['lang'], ['id', 'en'], true) ? $_POST['lang'] : 'id';
    $profile_id = (int)($_POST['profile_id'] ?? 1);

    if(filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mysqli_query($conn, "INSERT INTO messages (name, email, message, profile_id) VALUES ('$name', '$email', '$msg', $profile_id)");
        header("Location: thank-you.php?lang=$lang"); 
    } else { 
        header("Location: index.php?lang=$lang&status=error#contact"); 
    }
}
?>

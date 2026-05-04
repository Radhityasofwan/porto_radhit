<?php
session_start();
include 'db.php';

// --- HELPER: Response JSON ---
function jsonResponse($status, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

// --- SECURITY: CSRF Check ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && !in_array($_POST['action'], ['send_message', 'track_event'], true)) {
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
        bio='$bio', bio_en='$bio_en', projects_desc='$projects_desc', projects_desc_en='$projects_desc_en', contact_desc='$contact_desc', contact_desc_en='$contact_desc_en', projects_limit='$projects_limit', is_available='$is_available',
        email='$email', whatsapp='$whatsapp', link_linkedin='$link_linkedin', link_github='$link_github', 
        link_instagram='$link_instagram', link_facebook='$link_facebook', link_twitter='$link_twitter', 
        link_tiktok='$link_tiktok', link_threads='$link_threads', link_youtube='$link_youtube', link_blog_pribadi='$link_blog_pribadi',
        hero_image_url='$hero_img', profile_photo='$prof_photo', cv_url='$cv_url',
        seo_keywords='$seo_keywords', seo_keywords_en='$seo_keywords_en', script_google='$script_google', script_fb='$script_fb', script_other='$script_other', custom_head_script='$custom_head_script'
        WHERE id=1";
        
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

    if($id > 0){
        $q = "UPDATE projects SET category='$category', category_en='$category_en', title='$title', title_en='$title_en', slug='$slug', meta_desc='$meta_desc', meta_desc_en='$meta_desc_en',
              client_name='$client_name', description='$description', description_en='$description_en', details='$details', details_en='$details_en', problem='$problem', problem_en='$problem_en', solution='$solution', solution_en='$solution_en',
              chart_data_json=$chart_data_json, chart_data_json_en=$chart_data_json_en, image_url='$img', link_url='$link_url', tech_stacks='$tech_stacks', result_text='$result_text', result_text_en='$result_text_en', display_order='$display_order' 
              WHERE id=$id";
    } else {
        $q = "INSERT INTO projects (category, category_en, title, title_en, slug, meta_desc, meta_desc_en, client_name, description, description_en, details, details_en, problem, problem_en, solution, solution_en, chart_data_json, chart_data_json_en, image_url, link_url, tech_stacks, result_text, result_text_en, display_order) 
              VALUES ('$category', '$category_en', '$title', '$title_en', '$slug', '$meta_desc', '$meta_desc_en', '$client_name', '$description', '$description_en', '$details', '$details_en', '$problem', '$problem_en', '$solution', '$solution_en', $chart_data_json, $chart_data_json_en, '$img', '$link_url', '$tech_stacks', '$result_text', '$result_text_en', '$display_order')";
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
    $q = $id ? "UPDATE impact_metrics SET metric_name='$name', metric_name_en='$name_en', metric_value='$val', metric_value_en='$val_en', icon='$icon', display_order='$order' WHERE id=$id" : "INSERT INTO impact_metrics (metric_name, metric_name_en, metric_value, metric_value_en, icon, display_order) VALUES ('$name', '$name_en', '$val', '$val_en', '$icon', '$order')";
    if(mysqli_query($conn, $q)) jsonResponse('success', 'Metric saved');
}

// --- 4. CLIENT ---
if ($action == 'save_client') {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $id = (int)$_POST['id'];
    $name = mysqli_real_escape_string($conn, $_POST['client_name']);
    $order = (int)$_POST['display_order'];
    $logo = $_POST['old_logo_url']; if($new = uploadFile('logo_file', 'client')) $logo = $new;
    $q = $id ? "UPDATE client_logos SET client_name='$name', logo_url='$logo', display_order='$order' WHERE id=$id" : "INSERT INTO client_logos (client_name, logo_url, display_order) VALUES ('$name', '$logo', '$order')";
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
    $q = $id ? "UPDATE skills SET category='$cat', skill_name='$name', skill_name_en='$name_en', icon_url='$icon' WHERE id=$id" : "INSERT INTO skills (category, skill_name, skill_name_en, icon_url) VALUES ('$cat', '$name', '$name_en', '$icon')";
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
    $q = $id ? "UPDATE experience SET role='$role', role_en='$role_en', company='$comp', year_range='$year', year_range_en='$year_en', description='$desc', description_en='$desc_en' WHERE id=$id" : "INSERT INTO experience (role, role_en, company, year_range, year_range_en, description, description_en) VALUES ('$role', '$role_en', '$comp', '$year', '$year_en', '$desc', '$desc_en')";
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
    $q = $id ? "UPDATE education SET school_name='$sch', degree='$deg', degree_en='$deg_en', year_range='$year', year_range_en='$year_en' WHERE id=$id" : "INSERT INTO education (school_name, degree, degree_en, year_range, year_range_en) VALUES ('$sch', '$deg', '$deg_en', '$year', '$year_en')";
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
    $q = $id ? "UPDATE articles SET title='$title', title_en='$title_en', slug='$slug', meta_desc='$meta_desc', meta_desc_en='$meta_desc_en', content='$cont', content_en='$cont_en', image_url='$img' WHERE id=$id" : "INSERT INTO articles (title, title_en, slug, meta_desc, meta_desc_en, content, content_en, image_url) VALUES ('$title', '$title_en', '$slug', '$meta_desc', '$meta_desc_en', '$cont', '$cont_en', '$img')";
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
    $q = $id ? "UPDATE hero_chat SET question='$question', question_en='$question_en', answer='$answer', answer_en='$answer_en', type='$type', display_order='$order' WHERE id=$id" : "INSERT INTO hero_chat (question, question_en, answer, answer_en, type, display_order) VALUES ('$question', '$question_en', '$answer', '$answer_en', '$type', '$order')";
    if(mysqli_query($conn, $q)) jsonResponse('success', 'Chat saved');
}

// --- ANALYTICS TRACKING ---
if ($action === 'track_event') {
    $event_type = mysqli_real_escape_string($conn, $_POST['event_type'] ?? 'unknown');
    $event_key = mysqli_real_escape_string($conn, $_POST['event_key'] ?? 'unknown');
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT'] ?? '');
    $referer = mysqli_real_escape_string($conn, $_SERVER['HTTP_REFERER'] ?? null);
    $q = "INSERT INTO web_analytics (event_type, event_key, ip_address, user_agent, referer) VALUES ('$event_type', '$event_key', '$ip_address', '$user_agent', " . ($referer !== null ? "'$referer'" : 'NULL') . ")";
    mysqli_query($conn, $q);
    if($is_ajax) jsonResponse('success', 'Tracked');
    header('Content-Type: application/json'); echo json_encode(['status'=>'success']); exit;
}

// --- DELETE HANDLER ---
if ($delete > 0 && $type) {
    if (!isset($_SESSION['admin_logged_in'])) jsonResponse('error', 'Unauthorized');
    $validTables = ['projects', 'messages', 'skills', 'experience', 'education', 'articles', 'impact_metrics', 'client_logos', 'hero_chat'];
    if(in_array($type, $validTables)){
        if($type == 'projects') { 
            mysqli_query($conn, "DELETE FROM projects WHERE id=$delete"); 
            mysqli_query($conn, "DELETE FROM project_images WHERE project_id=$delete"); 
        } else { 
            mysqli_query($conn, "DELETE FROM $type WHERE id=$delete"); 
        }
        jsonResponse('success', 'Data berhasil dihapus');
    }
}

// --- PUBLIC MESSAGE ---
if ($action == 'send_message') {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $msg = mysqli_real_escape_string($conn, $_POST['message']);
    $lang = isset($_POST['lang']) && in_array($_POST['lang'], ['id', 'en'], true) ? $_POST['lang'] : 'id';

    if(filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mysqli_query($conn, "INSERT INTO messages (name, email, message) VALUES ('$name', '$email', '$msg')");
        header("Location: thank-you.php?lang=$lang"); 
    } else { 
        header("Location: index.php?lang=$lang&status=error#contact"); 
    }
}
?>

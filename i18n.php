<?php

const SITE_SUPPORTED_LANGS = ['id', 'en'];
const SITE_DEFAULT_LANG = 'en';

function currentLang(): string
{
    static $lang = null;

    if ($lang !== null) {
        return $lang;
    }

    $requested = $_GET['lang'] ?? $_COOKIE['site_lang'] ?? SITE_DEFAULT_LANG;
    if (!in_array($requested, SITE_SUPPORTED_LANGS, true)) {
        $requested = SITE_DEFAULT_LANG;
    }

    if (isset($_GET['lang']) && (!isset($_COOKIE['site_lang']) || $_COOKIE['site_lang'] !== $requested)) {
        setcookie('site_lang', $requested, time() + (86400 * 365), '/');
        $_COOKIE['site_lang'] = $requested;
    }

    $lang = $requested;
    return $lang;
}

function otherLang(?string $lang = null): string
{
    $lang = $lang ?? currentLang();
    return $lang === 'en' ? 'id' : 'en';
}

function localizedField(array $row, string $field, ?string $lang = null): string
{
    $lang = $lang ?? currentLang();

    if ($lang === 'en') {
        $englishField = $field . '_en';
        if (array_key_exists($englishField, $row)) {
            $englishValue = trim((string) ($row[$englishField] ?? ''));
            if ($englishValue !== '') {
                return (string) $row[$englishField];
            }
        }
    }

    return (string) ($row[$field] ?? '');
}

function isExternalUrl(string $url): bool
{
    if ($url === '' || str_starts_with($url, '#')) {
        return false;
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return false;
    }

    if (!isset($parts['scheme']) && !isset($parts['host'])) {
        return false;
    }

    if (isset($parts['scheme']) && in_array($parts['scheme'], ['mailto', 'tel', 'javascript'], true)) {
        return true;
    }

    if (!isset($parts['host'])) {
        return false;
    }

    if (!isset($_SERVER['HTTP_HOST'])) {
        return true;
    }

    $serverHost = parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST) ?: $_SERVER['HTTP_HOST'];
    $serverPort = parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_PORT);

    if (strcasecmp($parts['host'], $serverHost) !== 0) {
        return true;
    }

    if (isset($parts['port'])) {
        return $serverPort !== null ? (int) $parts['port'] !== (int) $serverPort : false;
    }

    return false;
}

function localizedUrl(string $url, ?string $lang = null): string
{
    $lang = $lang ?? currentLang();

    if ($url === '' || str_starts_with($url, '#') || isExternalUrl($url)) {
        return $url;
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return $url;
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $query['lang'] = $lang;

    $rebuilt = '';
    if (isset($parts['scheme'])) {
        $rebuilt .= $parts['scheme'] . '://';
    }
    if (isset($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (isset($parts['pass'])) {
            $rebuilt .= ':' . $parts['pass'];
        }
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) {
        $rebuilt .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }

    $path = $parts['path'] ?? '';
    $rebuilt .= $path;

    $queryString = http_build_query($query);
    if ($queryString !== '') {
        $rebuilt .= '?' . $queryString;
    }
    if (isset($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
}

function currentUrlWithLang(string $lang): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $currentUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    return localizedUrl($currentUrl, $lang);
}

function formatLocalizedDate(string $date, ?string $lang = null): string
{
    $lang = $lang ?? currentLang();
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $months = [
        'id' => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
        'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    ];

    $monthIndex = (int) date('n', $timestamp) - 1;
    $monthName = $months[$lang][$monthIndex] ?? $months[SITE_DEFAULT_LANG][$monthIndex];

    return date('d', $timestamp) . ' ' . $monthName . ' ' . date('Y', $timestamp);
}

function excerptText(string $text, int $length = 150): string
{
    $plain = trim(strip_tags($text));

    if ($plain === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($plain) <= $length) {
            return $plain;
        }

        return rtrim(mb_substr($plain, 0, $length)) . '...';
    }

    if (strlen($plain) <= $length) {
        return $plain;
    }

    return rtrim(substr($plain, 0, $length)) . '...';
}

function translateSkillCategory(string $category, ?string $lang = null): string
{
    $lang = $lang ?? currentLang();
    $map = [
        'Technical' => ['id' => 'Teknis', 'en' => 'Technical'],
        'Tech' => ['id' => 'Teknis', 'en' => 'Tech'],
        'Design' => ['id' => 'Desain', 'en' => 'Design'],
        'Marketing' => ['id' => 'Marketing', 'en' => 'Marketing'],
        'Tools' => ['id' => 'Tools', 'en' => 'Tools'],
    ];

    return $map[$category][$lang] ?? $category;
}

function t(string $key, array $replace = [], ?string $lang = null): string
{
    static $translations = [
        'id' => [
            'lang.id' => 'ID',
            'lang.en' => 'EN',
            'lang.switcher' => 'Bahasa',
            'nav.home' => 'Beranda',
            'nav.about' => 'Tentang',
            'nav.about_me' => 'Tentang Saya',
            'nav.portfolio' => 'Portofolio',
            'nav.skills' => 'Keahlian',
            'nav.articles' => 'Artikel',
            'nav.contact' => 'Hubungi Saya',
            'nav.menu' => 'Menu',
            'nav.connected' => 'Terhubung',
            'hero.cta_portfolio' => 'Lihat Portofolio',
            'hero.cta_cv' => 'Unduh CV',
            'hero.image_alt' => 'Potret Hero',
            'hero.typing' => '{{name}} sedang mengetik...',
            'clients.trusted_by' => 'Dipercaya Oleh',
            'clients.title' => 'Klien yang Mempercayai',
            'clients.empty' => 'Belum ada klien ditambahkan.',
            'projects.title' => 'Studi Kasus',
            'projects.title_highlight' => 'Terpilih',
            'projects.filter_all' => 'Semua',
            'projects.read_detail' => 'Baca Detail',
            'projects.load_more' => 'Lihat Lebih Banyak',
            'skills.title' => 'Keahlian',
            'skills.title_highlight' => 'Teknis',
            'about.experience' => 'Pengalaman Kerja',
            'about.education' => 'Pendidikan',
            'blog.title' => 'Insight',
            'blog.title_highlight' => 'Terbaru',
            'blog.read_article' => 'Baca Artikel',
            'blog.empty' => 'Belum ada artikel yang dipublish.',
            'blog.visit_portal' => 'Kunjungi portal berita saya untuk lihat lebih lengkap',
            'contact.title' => 'Siap Meningkatkan <br>Performa Bisnis?',
            'contact.placeholder_name' => 'Nama Lengkap',
            'contact.placeholder_email' => 'Email Bisnis',
            'contact.placeholder_message' => 'Ceritakan kebutuhan proyek Anda...',
            'contact.submit' => 'Kirim Pesan',
            'footer.connected' => 'Terhubung di Platform Lain',
            'footer.rights' => 'All rights reserved.',
            'chat.yes' => 'Ya, tentu',
            'chat.no' => 'Tidak dulu',
            'chat.next' => 'Ya, lanjut',
            'chat.enough' => 'Cukup',
            'chat.interested' => 'Tertarik melihat detailnya?',
            'chat.view_detail' => 'Lihat Detail',
            'chat.close' => 'Tutup',
            'chat.thanks' => 'Terima kasih sudah mengobrol!',
            'chat.final_cta' => '<span class="text-primary font-bold">Terima kasih!</span> Silakan cek detailnya di bawah.',
            'chat.final_cta_button' => 'Lihat Detail Portfolio',
            'article.back_home' => 'Kembali ke Beranda',
            'article.blog_insights' => 'Blog & Insight',
            'article.current' => 'Saat Ini',
            'article.min_read' => 'min baca',
            'article.share_linkedin' => 'Bagikan ke LinkedIn',
            'article.share_facebook' => 'Bagikan ke Facebook',
            'article.share_x' => 'Bagikan ke X',
            'article.share_whatsapp' => 'Bagikan via WhatsApp',
            'article.like' => 'Suka dengan artikel ini?',
            'article.cta' => 'Mari berdiskusi lebih lanjut tentang bagaimana saya bisa membantu bisnis Anda.',
            'article.contact' => 'Hubungi Saya',
            'article.full_profile' => 'Lihat Profil Lengkap',
            'article.latest' => 'Artikel Terbaru',
            'article.follow_updates' => 'Ikuti Update Terbaru',
            'article.follow_text' => 'Dapatkan tips dan insight seputar digital marketing langsung di feed sosial media Anda.',
            'project.back' => 'Kembali',
            'project.detail' => 'Studi Kasus Detail',
            'project.client' => 'Klien',
            'project.result' => 'Hasil',
            'project.tech_stack' => 'Tech Stack',
            'project.analysis' => 'Analisis Mendalam',
            'project.visit_live' => 'Kunjungi Proyek Langsung',
            'project.stats' => 'Statistik Proyek',
            'project.gallery' => 'Galeri Bukti',
            'thankyou.title' => 'Pesan Terkirim!',
            'thankyou.text' => 'Terima kasih sudah menghubungi. Saya akan membalas pesan Anda ke email yang tercantum dalam waktu 1x24 jam.',
            'thankyou.home' => 'Kembali ke Beranda',
            'thankyou.linkedin' => 'Terhubung di LinkedIn',
            '404.title' => 'Halaman Tidak Ditemukan',
            '404.heading' => 'Ups! Halaman Hilang',
            '404.text' => 'Sepertinya halaman yang Anda cari sudah dipindahkan atau tidak pernah ada.',
            '404.home' => 'Kembali ke Homepage',
            'auth.login_title' => 'Login Administrator',
            'auth.welcome_back' => 'Selamat Datang Kembali',
            'auth.login_subtitle' => 'Masuk untuk mengelola portfolio Anda',
            'auth.username' => 'Username',
            'auth.username_placeholder' => 'Username Anda',
            'auth.password' => 'Password',
            'auth.password_placeholder' => 'Masukkan password',
            'auth.login_button' => 'Masuk Dashboard',
            'auth.footer' => 'Creative Portfolio Admin System',
            'auth.error_password' => 'Password salah!',
            'auth.error_username' => 'Username tidak ditemukan!',
            'admin.panel' => 'Admin Panel',
            'admin.logout' => 'Keluar',
            'admin.nav.main' => 'Utama',
            'admin.nav.dashboard' => 'Dashboard',
            'admin.nav.portfolio_group' => 'Portofolio',
            'admin.nav.content' => 'Konten',
            'admin.nav.profile' => 'Profil & SEO',
            'admin.nav.metrics' => 'Metrics',
            'admin.nav.clients' => 'Klien',
            'admin.nav.chat' => 'Chat Hero',
            'admin.nav.projects' => 'Studi Kasus',
            'admin.nav.skills' => 'Keahlian',
            'admin.nav.experience' => 'Pengalaman',
            'admin.nav.education' => 'Pendidikan',
            'admin.nav.blog' => 'Artikel Blog',
            'admin.nav.messages' => 'Inbox',
            'admin.mobile_menu' => 'Menu Navigasi',
            'admin.profile_heading' => 'Edit Profil & SEO',
            'admin.projects_heading' => 'Studi Kasus',
            'admin.metrics_heading' => 'Metrics',
            'admin.clients_heading' => 'Klien',
            'admin.chat_heading' => 'Chat',
            'admin.skills_heading' => 'Skill',
            'admin.experience_heading' => 'Pengalaman',
            'admin.education_heading' => 'Pendidikan',
            'admin.blog_heading' => 'Blog',
            'admin.messages_heading' => 'Inbox',
            'admin.save_changes' => 'Simpan Perubahan',
            'admin.add_project' => 'Tambah Proyek',
            'admin.add' => 'Tambah',
            'admin.edit' => 'Edit',
            'admin.delete' => 'Hapus',
            'admin.project_editor' => 'Editor Proyek',
            'admin.metric' => 'Metric',
            'admin.client' => 'Client',
            'admin.chat_modal' => 'Chat',
            'admin.skill_modal' => 'Skill',
            'admin.article_modal' => 'Artikel Blog',
            'admin.save_project' => 'Simpan Proyek',
            'admin.save_article' => 'Simpan Artikel',
            'admin.save' => 'Simpan',
            'admin.alert.saving' => 'Menyimpan...',
            'admin.alert.success' => 'Berhasil',
            'admin.alert.failed' => 'Gagal',
            'admin.alert.error' => 'Error',
            'admin.alert.connection_failed' => 'Koneksi gagal',
            'admin.alert.delete_title' => 'Hapus data?',
            'admin.alert.delete_confirm' => 'Ya',
            'admin.alert.gallery_delete' => 'Hapus?',
        ],
        'en' => [
            'lang.id' => 'ID',
            'lang.en' => 'EN',
            'lang.switcher' => 'Language',
            'nav.home' => 'Home',
            'nav.about' => 'About',
            'nav.about_me' => 'About Me',
            'nav.portfolio' => 'Portfolio',
            'nav.skills' => 'Skills',
            'nav.articles' => 'Articles',
            'nav.contact' => 'Contact',
            'nav.menu' => 'Menu',
            'nav.connected' => 'Stay Connected',
            'hero.cta_portfolio' => 'View Portfolio',
            'hero.cta_cv' => 'Download CV',
            'hero.image_alt' => 'Hero Portrait',
            'hero.typing' => '{{name}} is typing...',
            'clients.trusted_by' => 'Trusted By',
            'clients.title' => 'Trusted Clients',
            'clients.empty' => 'No clients have been added yet.',
            'projects.title' => 'Featured',
            'projects.title_highlight' => 'Case Studies',
            'projects.filter_all' => 'All',
            'projects.read_detail' => 'Read Details',
            'projects.load_more' => 'Load More',
            'skills.title' => 'Technical',
            'skills.title_highlight' => 'Skills',
            'about.experience' => 'Work Experience',
            'about.education' => 'Education',
            'blog.title' => 'Latest',
            'blog.title_highlight' => 'Insights',
            'blog.read_article' => 'Read Article',
            'blog.empty' => 'No published articles yet.',
            'blog.visit_portal' => 'Visit my news portal for the full archive',
            'contact.title' => 'Ready to Improve <br>Your Business Performance?',
            'contact.placeholder_name' => 'Full Name',
            'contact.placeholder_email' => 'Business Email',
            'contact.placeholder_message' => 'Tell me about your project needs...',
            'contact.submit' => 'Send Message',
            'footer.connected' => 'Connect on Other Platforms',
            'footer.rights' => 'All rights reserved.',
            'chat.yes' => 'Yes, sure',
            'chat.no' => 'Not now',
            'chat.next' => 'Yes, continue',
            'chat.enough' => 'That is enough',
            'chat.interested' => 'Interested in seeing the details?',
            'chat.view_detail' => 'View Details',
            'chat.close' => 'Close',
            'chat.thanks' => 'Thanks for the chat!',
            'chat.final_cta' => '<span class="text-primary font-bold">Thank you!</span> Feel free to explore the details below.',
            'chat.final_cta_button' => 'View Portfolio Details',
            'article.back_home' => 'Back to Home',
            'article.blog_insights' => 'Blog & Insights',
            'article.current' => 'Current',
            'article.min_read' => 'min read',
            'article.share_linkedin' => 'Share to LinkedIn',
            'article.share_facebook' => 'Share to Facebook',
            'article.share_x' => 'Share on X',
            'article.share_whatsapp' => 'Share via WhatsApp',
            'article.like' => 'Enjoyed this article?',
            'article.cta' => 'Let us talk about how I can help your business move faster.',
            'article.contact' => 'Contact Me',
            'article.full_profile' => 'View Full Profile',
            'article.latest' => 'Latest Articles',
            'article.follow_updates' => 'Follow the Latest Updates',
            'article.follow_text' => 'Get digital marketing tips and insights directly in your social feed.',
            'project.back' => 'Back',
            'project.detail' => 'Case Study Detail',
            'project.client' => 'Client',
            'project.result' => 'Result',
            'project.tech_stack' => 'Tech Stack',
            'project.analysis' => 'Deep Analysis',
            'project.visit_live' => 'Visit Live Project',
            'project.stats' => 'Project Stats',
            'project.gallery' => 'Evidence Gallery',
            'thankyou.title' => 'Message Sent!',
            'thankyou.text' => 'Thank you for reaching out. I will reply to your email within 24 hours.',
            'thankyou.home' => 'Back to Home',
            'thankyou.linkedin' => 'Connect on LinkedIn',
            '404.title' => 'Page Not Found',
            '404.heading' => 'Oops! The Page Is Missing',
            '404.text' => 'It looks like the page you are looking for has moved or never existed.',
            '404.home' => 'Back to Homepage',
            'auth.login_title' => 'Administrator Login',
            'auth.welcome_back' => 'Welcome Back',
            'auth.login_subtitle' => 'Sign in to manage your portfolio',
            'auth.username' => 'Username',
            'auth.username_placeholder' => 'Your username',
            'auth.password' => 'Password',
            'auth.password_placeholder' => 'Enter your password',
            'auth.login_button' => 'Enter Dashboard',
            'auth.footer' => 'Creative Portfolio Admin System',
            'auth.error_password' => 'Incorrect password!',
            'auth.error_username' => 'Username not found!',
            'admin.panel' => 'Admin Panel',
            'admin.logout' => 'Log Out',
            'admin.nav.main' => 'Main',
            'admin.nav.dashboard' => 'Dashboard',
            'admin.nav.portfolio_group' => 'Portfolio',
            'admin.nav.content' => 'Content',
            'admin.nav.profile' => 'Profile & SEO',
            'admin.nav.metrics' => 'Metrics',
            'admin.nav.clients' => 'Clients',
            'admin.nav.chat' => 'Hero Chat',
            'admin.nav.projects' => 'Case Studies',
            'admin.nav.skills' => 'Skills',
            'admin.nav.experience' => 'Experience',
            'admin.nav.education' => 'Education',
            'admin.nav.blog' => 'Blog Articles',
            'admin.nav.messages' => 'Inbox',
            'admin.mobile_menu' => 'Navigation Menu',
            'admin.profile_heading' => 'Edit Profile & SEO',
            'admin.projects_heading' => 'Case Studies',
            'admin.metrics_heading' => 'Metrics',
            'admin.clients_heading' => 'Clients',
            'admin.chat_heading' => 'Chat',
            'admin.skills_heading' => 'Skills',
            'admin.experience_heading' => 'Experience',
            'admin.education_heading' => 'Education',
            'admin.blog_heading' => 'Blog',
            'admin.messages_heading' => 'Inbox',
            'admin.save_changes' => 'Save Changes',
            'admin.add_project' => 'Add Project',
            'admin.add' => 'Add',
            'admin.edit' => 'Edit',
            'admin.delete' => 'Delete',
            'admin.project_editor' => 'Project Editor',
            'admin.metric' => 'Metric',
            'admin.client' => 'Client',
            'admin.chat_modal' => 'Chat',
            'admin.skill_modal' => 'Skill',
            'admin.article_modal' => 'Blog Article',
            'admin.save_project' => 'Save Project',
            'admin.save_article' => 'Save Article',
            'admin.save' => 'Save',
            'admin.alert.saving' => 'Saving...',
            'admin.alert.success' => 'Success',
            'admin.alert.failed' => 'Failed',
            'admin.alert.error' => 'Error',
            'admin.alert.connection_failed' => 'Connection failed',
            'admin.alert.delete_title' => 'Delete this item?',
            'admin.alert.delete_confirm' => 'Yes',
            'admin.alert.gallery_delete' => 'Delete?',
        ],
    ];

    $lang = $lang ?? currentLang();
    $text = $translations[$lang][$key] ?? $translations[SITE_DEFAULT_LANG][$key] ?? $key;

    foreach ($replace as $replaceKey => $replaceValue) {
        $text = str_replace('{{' . $replaceKey . '}}', (string) $replaceValue, $text);
    }

    return $text;
}

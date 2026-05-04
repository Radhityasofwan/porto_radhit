<?php
require_once 'i18n.php';
include 'db.php';

header("Content-Type: application/xml; charset=utf-8");

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');
$baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . ($basePath !== '' ? $basePath . '/' : '/');

function xmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function sitemapUrl(string $baseUrl, string $path, string $lang): string
{
    $fullUrl = $baseUrl . ltrim($path, '/');
    return localizedUrl($fullUrl, $lang);
}

function renderSitemapEntry(string $locId, string $locEn, string $changefreq, string $priority, ?string $lastmod = null): void
{
    echo "    <url>\n";
    echo '        <loc>' . xmlEscape($locId) . "</loc>\n";
    echo '        <xhtml:link rel="alternate" hreflang="id" href="' . xmlEscape($locId) . "\" />\n";
    echo '        <xhtml:link rel="alternate" hreflang="en" href="' . xmlEscape($locEn) . "\" />\n";
    echo '        <xhtml:link rel="alternate" hreflang="x-default" href="' . xmlEscape($locId) . "\" />\n";
    if (!empty($lastmod)) {
        echo '        <lastmod>' . xmlEscape(date('c', strtotime($lastmod))) . "</lastmod>\n";
    }
    echo '        <priority>' . xmlEscape($priority) . "</priority>\n";
    echo '        <changefreq>' . xmlEscape($changefreq) . "</changefreq>\n";
    echo "    </url>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
<?php
renderSitemapEntry(
    sitemapUrl($baseUrl, '', 'id'),
    sitemapUrl($baseUrl, '', 'en'),
    'daily',
    '1.0'
);

$articlesQ = mysqli_query($conn, "SELECT id, slug, created_at FROM articles ORDER BY created_at DESC");
while ($row = mysqli_fetch_assoc($articlesQ)) {
    $path = !empty($row['slug']) ? 'blog/' . $row['slug'] : 'article.php?id=' . (int) $row['id'];
    renderSitemapEntry(
        sitemapUrl($baseUrl, $path, 'id'),
        sitemapUrl($baseUrl, $path, 'en'),
        'weekly',
        '0.8',
        $row['created_at'] ?? null
    );
}

$projectsQ = mysqli_query($conn, "SELECT id, slug, created_at FROM projects ORDER BY id DESC");
while ($row = mysqli_fetch_assoc($projectsQ)) {
    $path = !empty($row['slug']) ? 'portfolio/' . $row['slug'] : 'project-details.php?id=' . (int) $row['id'];
    renderSitemapEntry(
        sitemapUrl($baseUrl, $path, 'id'),
        sitemapUrl($baseUrl, $path, 'en'),
        'monthly',
        '0.8',
        $row['created_at'] ?? null
    );
}
?>
</urlset>

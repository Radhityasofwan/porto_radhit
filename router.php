<?php

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$filePath = __DIR__ . DIRECTORY_SEPARATOR . ltrim($requestPath, '/');

if ($requestPath !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    return false;
}

if ($requestPath === '/' || $requestPath === '/index.php') {
    require __DIR__ . '/index.php';
    return;
}

if ($requestPath === '/sitemap.xml' || $requestPath === '/sitemap.php') {
    require __DIR__ . '/sitemap.php';
    return;
}

if (preg_match('#^/portfolio/([^/]+)/?$#', $requestPath, $matches)) {
    $_GET['slug'] = urldecode($matches[1]);
    require __DIR__ . '/project-details.php';
    return;
}

if (preg_match('#^/blog/([^/]+)/?$#', $requestPath, $matches)) {
    $_GET['slug'] = urldecode($matches[1]);
    require __DIR__ . '/article.php';
    return;
}

if (file_exists($filePath) && !is_dir($filePath)) {
    return false;
}

http_response_code(404);
require __DIR__ . '/404.php';

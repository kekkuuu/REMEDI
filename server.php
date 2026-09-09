<?php

/**
 * Serverless function entry point for the classic vercel-php runtime
 * (see vercel.json). Vercel's PHP runtime does not serve /public's static
 * assets on its own, so this checks the request path against a real file
 * in /public first and serves it directly; everything else falls through
 * to Laravel's own front controller.
 *
 * Forecasting (both Python pipelines, the nightly cron) does not run under
 * this runtime -- there is no Python alongside PHP here, unlike the
 * Docker/Railway deployment documented in docs/DEPLOY-RAILWAY.md.
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

if ($uri !== '/' && file_exists($file = __DIR__.'/public'.$uri) && ! is_dir($file)) {
    header('Content-Type: '.get_mime_type($file).'; charset=UTF-8');
    readfile($file);
} else {
    require __DIR__.'/public/index.php';
}

function get_mime_type(string $filename): string
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $mimes = [
        'txt' => 'text/plain',
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'png' => 'image/png',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'ico' => 'image/vnd.microsoft.icon',
        'svg' => 'image/svg+xml',
        'svgz' => 'image/svg+xml',
        'ttf' => 'font/ttf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'otf' => 'font/otf',
        'map' => 'application/json',
    ];

    return $mimes[$extension] ?? 'application/octet-stream';
}

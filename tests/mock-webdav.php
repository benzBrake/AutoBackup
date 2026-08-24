<?php

$root = getenv('AUTOBACKUP_WEBDAV_ROOT');
if (!$root) {
    http_response_code(500);
    exit('Missing AUTOBACKUP_WEBDAV_ROOT');
}

if (!isset($_SERVER['PHP_AUTH_USER']) || $_SERVER['PHP_AUTH_USER'] !== 'backup'
    || !isset($_SERVER['PHP_AUTH_PW']) || $_SERVER['PHP_AUTH_PW'] !== 'secret') {
    header('WWW-Authenticate: Basic realm="AutoBackup Test"');
    http_response_code(401);
    exit('Unauthorized');
}

$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (strpos($requestPath, '/dav/') !== 0) {
    http_response_code(404);
    exit('Not Found');
}

$relativePath = trim(substr($requestPath, strlen('/dav/')), '/');
$segments = $relativePath === '' ? [] : preg_split('#/+#', $relativePath);
foreach ($segments as $segment) {
    if ($segment === '.' || $segment === '..') {
        http_response_code(400);
        exit('Invalid Path');
    }
}
$target = rtrim($root, '/\\') . ($segments ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments) : '');
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'MKCOL') {
    if (is_dir($target)) {
        http_response_code(405);
    } elseif (!is_dir(dirname($target))) {
        http_response_code(409);
    } elseif (mkdir($target)) {
        http_response_code(201);
    } else {
        http_response_code(500);
    }
    exit;
}

if ($method === 'PROPFIND') {
    http_response_code(is_dir($target) ? 207 : 404);
    exit;
}

if ($method === 'PUT') {
    if (!is_dir(dirname($target))) {
        http_response_code(409);
        exit('Missing Parent');
    }
    $input = fopen('php://input', 'rb');
    $output = fopen($target, 'wb');
    stream_copy_to_stream($input, $output);
    fclose($input);
    fclose($output);
    http_response_code(201);
    exit;
}

http_response_code(405);

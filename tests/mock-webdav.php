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
    if (!is_dir($target)) {
        http_response_code(404);
        exit;
    }
    $responses = [];
    $entries = [$target];
    if (isset($_SERVER['HTTP_DEPTH']) && $_SERVER['HTTP_DEPTH'] === '1') {
        $children = scandir($target);
        foreach ($children as $child) {
            if ($child !== '.' && $child !== '..') $entries[] = $target . DIRECTORY_SEPARATOR . $child;
        }
    }
    foreach ($entries as $entry) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry, strlen(rtrim($root, '/\\'))));
        $href = '/dav' . $relative . (is_dir($entry) ? '/' : '');
        $responses[] = '<d:response><d:href>' . htmlspecialchars($href, ENT_XML1, 'UTF-8') . '</d:href><d:propstat><d:prop>'
            . (is_dir($entry) ? '<d:resourcetype><d:collection/></d:resourcetype>' : '<d:resourcetype/><d:getcontentlength>' . filesize($entry) . '</d:getcontentlength>')
            . '<d:getlastmodified>' . gmdate('D, d M Y H:i:s', filemtime($entry)) . ' GMT</d:getlastmodified></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
    }
    header('Content-Type: application/xml; charset="utf-8"');
    http_response_code(207);
    echo '<?xml version="1.0" encoding="UTF-8"?><d:multistatus xmlns:d="DAV:">' . implode('', $responses) . '</d:multistatus>';
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

<?php

require_once dirname(__DIR__) . '/WebDavClient.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php WebDavClientIntegrationTest.php <base-url> <storage-root>\n");
    exit(2);
}

$baseUrl = $argv[1];
$storageRoot = rtrim($argv[2], '/\\');
$source = __FILE__;
$directory = 'AutoBackup/中文 空格';
$remoteName = 'AutoBackup-test.sql';
$target = $storageRoot . DIRECTORY_SEPARATOR . 'AutoBackup' . DIRECTORY_SEPARATOR . '中文 空格'
    . DIRECTORY_SEPARATOR . $remoteName;

$client = new AutoBackup_WebDavClient($baseUrl, 'backup', 'secret', true);
$client->upload($source, $directory, $remoteName);
$client->upload($source, $directory, $remoteName);

if (!is_file($target)) {
    fwrite(STDERR, "Uploaded file was not found: {$target}\n");
    exit(1);
}
if (file_get_contents($target) !== file_get_contents($source)) {
    fwrite(STDERR, "Uploaded file content does not match the source\n");
    exit(1);
}

echo "WebDAV integration test passed\n";

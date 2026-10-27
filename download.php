<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

if (empty($_SESSION['authenticated'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$id = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['id'] ?? ''));
$job = (new Store())->get($id);
if (!$job || $job['status'] !== 'completed') {
    http_response_code(404);
    exit('File not found');
}
$path = ($job['destination_dir'] ?? STORAGE_PATH) . '/' . $job['filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found');
}
header('Content-Type: ' . ($job['content_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($job['filename']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
$handle = fopen($path, 'rb');
while (!feof($handle)) {
    echo fread($handle, 1024 * 1024);
    flush();
}
fclose($handle);

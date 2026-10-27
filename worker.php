<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$store = new Store();
$downloader = new Downloader($store, $config);
$limit = isset($argv[1]) ? max(1, min(100, (int) $argv[1])) : 25;
for ($i = 0; $i < $limit; $i++) {
    $now = time();
    $queued = array_values(array_filter($store->all(), static fn(array $job): bool =>
        $job['status'] === 'queued' || ($job['status'] === 'downloading' && (int) ($job['lease_until'] ?? 0) <= $now)
    ));
    if (!$queued) break;
    usort($queued, static fn(array $a, array $b): int => $a['created_at'] <=> $b['created_at']);
    try {
        $job = $downloader->process($queued[0]['id']);
        fwrite(STDOUT, sprintf("[%s] %s: %s\n", date('c'), $job['filename'], $job['status']));
    } catch (Throwable $error) {
        fwrite(STDERR, sprintf("[%s] %s\n", date('c'), $error->getMessage()));
    }
}

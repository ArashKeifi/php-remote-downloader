<?php
declare(strict_types=1);

return [
    'app_name' => 'بارگیر',
    'timezone' => 'Asia/Tehran',
    // 0 means unlimited. The real limit is the hosting account's available disk/quota.
    'max_file_size' => 0,
    'min_free_space' => 512 * 1024 * 1024,      // keep 512 MB free for PHP/cPanel operations
    'chunk_size' => 4 * 1024 * 1024,            // initial/fallback size per connection
    'min_chunk_size' => 1 * 1024 * 1024,
    'max_chunk_size' => 16 * 1024 * 1024,
    'target_chunk_seconds' => 8,
    'request_timeout' => 25,
    'connect_timeout' => 8,
    'max_redirects' => 5,
    'default_concurrency' => 3,
    'max_concurrency' => 6,
    'default_multipart_connections' => 1,
    'max_multipart_connections' => 6,
    'allow_private_networks' => false,
    'user_agent' => 'Bargir/1.0 (+self-hosted download manager)',
    // null means auto-detect from DOCUMENT_ROOT on shared hosting.
    'cpanel_home_root' => null,
    'public_html_root' => null,
    // Custom download paths are restricted to the cPanel home by default.
    // Add other absolute roots here only when PHP is intentionally allowed to write there.
    'allowed_download_roots' => [],
    // Full backups may only be read from these roots. The account home is added automatically.
    'local_backup_roots' => [],
    'restore_files_per_step' => 80,
    'restore_sql_statements_per_step' => 20,
    'restore_max_archive_entries' => 250000,
];

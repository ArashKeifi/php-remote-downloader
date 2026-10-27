<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$settings = app_settings();
$action = $_GET['action'] ?? '';
$input = request_data();

try {
    if ($action === 'setup') {
        if (!empty($settings['password_hash'])) json_response(['ok' => false, 'error' => 'راه‌اندازی قبلاً انجام شده است.'], 409);
        $password = (string) ($input['password'] ?? '');
        $passwordLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
        if ($passwordLength < 10) json_response(['ok' => false, 'error' => 'رمز باید دست‌کم ۱۰ نویسه باشد.'], 422);
        save_app_settings(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => time()]);
        if (!session_regenerate_id(true)) throw new RuntimeException('ایجاد نشست ورود ناموفق بود.');
        $_SESSION['authenticated'] = true;
        $_SESSION['authenticated_at'] = time();
        $csrf = csrf_token();
        if (!session_write_close()) throw new RuntimeException('ذخیره نشست ورود ناموفق بود.');
        json_response(['ok' => true, 'csrf' => $csrf]);
    }

    if ($action === 'login') {
        if (empty($settings['password_hash'])) json_response(['ok' => false, 'error' => 'ابتدا برنامه را راه‌اندازی کنید.'], 409);
        if (!password_verify((string) ($input['password'] ?? ''), $settings['password_hash'])) {
            usleep(350000);
            json_response(['ok' => false, 'error' => 'رمز عبور نادرست است.'], 401);
        }
        if (!session_regenerate_id(true)) throw new RuntimeException('ایجاد نشست ورود ناموفق بود.');
        $_SESSION['authenticated'] = true;
        $_SESSION['authenticated_at'] = time();
        $csrf = csrf_token();
        if (!session_write_close()) throw new RuntimeException('ذخیره نشست ورود ناموفق بود.');
        json_response(['ok' => true, 'csrf' => $csrf]);
    }

    require_auth();
    if ($action === 'session') json_response(['ok' => true, 'csrf' => csrf_token()]);
    $store = new Store();
    $downloader = new Downloader($store, $config);
    $restore = new RestoreManager($store, $downloader, $config);

    if ($action === 'list') {
        $jobs = array_values($store->all());
        usort($jobs, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        $free = @disk_free_space(STORAGE_PATH);
        $total = @disk_total_space(STORAGE_PATH);
        json_response(['ok' => true, 'jobs' => $jobs, 'disk' => ['free' => $free, 'total' => $total], 'csrf' => csrf_token()]);
    }

    if ($action === 'restore_list') json_response(['ok' => true, 'tasks' => $restore->all(), 'csrf' => csrf_token()]);

    require_csrf();
    if ($action === 'browse_dirs') {
        json_response(['ok' => true] + Security::listPublicDirectories(isset($input['path']) ? (string) $input['path'] : '', $config));
    }
    if ($action === 'add') {
        $conflictAction = array_key_exists('conflict_action', $input) ? (string) $input['conflict_action'] : null;
        try {
            $job = $downloader->create((string) ($input['url'] ?? ''), isset($input['filename']) ? (string) $input['filename'] : null, isset($input['destination']) ? (string) $input['destination'] : null, $conflictAction);
        } catch (FileConflictException $error) {
            json_response([
                'ok' => false,
                'error' => $error->getMessage(),
                'code' => 'file_exists',
                'conflict' => ['filename' => $error->filename],
            ], 409);
        }
        json_response(['ok' => true, 'job' => $job], 201);
    }
    if ($action === 'restore_create') {
        $task = $restore->create((string) ($input['source_type'] ?? ''), (string) ($input['source'] ?? ''));
        json_response(['ok' => true, 'task' => $task], 201);
    }

    $restoreId = preg_replace('/[^a-f0-9]/', '', (string) ($input['restore_id'] ?? ''));
    if (str_starts_with($action, 'restore_')) {
        if (strlen($restoreId) !== 16) json_response(['ok' => false, 'error' => 'شناسه عملیات بازیابی نامعتبر است.'], 422);
        if ($action === 'restore_inspect') json_response(['ok' => true, 'task' => $restore->inspect($restoreId)]);
        if ($action === 'restore_start') json_response(['ok' => true, 'task' => $restore->start($restoreId, (array) ($input['sections'] ?? []))]);
        if ($action === 'restore_step') json_response(['ok' => true, 'task' => $restore->step($restoreId, (array) ($input['databases'] ?? []), isset($input['cpanel']) && is_array($input['cpanel']) ? $input['cpanel'] : null)]);
    }

    $id = preg_replace('/[^a-f0-9]/', '', (string) ($input['id'] ?? ''));
    if (strlen($id) !== 16) json_response(['ok' => false, 'error' => 'شناسه دانلود نامعتبر است.'], 422);

    if ($action === 'process') {
        try {
            json_response(['ok' => true, 'job' => $downloader->process($id)]);
        } catch (Throwable $error) {
            $store->update($id, static function (array $job) use ($error): array {
                if (($job['status'] ?? null) === 'downloading') {
                    $job['status'] = 'failed';
                    $job['error'] = $error->getMessage();
                    $job['downloaded'] = (int) ($job['committed_downloaded'] ?? $job['downloaded'] ?? 0);
                    $job['active_chunks'] = [];
                    $job['lease_until'] = 0;
                    $job['worker_token'] = null;
                    $job['speed'] = 0;
                    $job['updated_at'] = time();
                }
                return $job;
            });
            throw $error;
        }
    }
    if ($action === 'set_connections') {
        $maximum = max(1, (int) ($config['max_multipart_connections'] ?? 6));
        $requested = max(1, min($maximum, (int) ($input['connections'] ?? 1)));
        $job = $store->update($id, static function (array $job) use ($requested): array {
            $job['connections'] = !empty($job['range_supported']) ? $requested : 1;
            $job['updated_at'] = time();
            return $job;
        });
        json_response(['ok' => true, 'job' => $job]);
    }
    if ($action === 'pause') {
        $existing = $store->get($id);
        if ($existing && empty($existing['range_supported'])) {
            json_response(['ok' => false, 'error' => 'سرور مبدأ از توقف و ادامه دانلود پشتیبانی نمی‌کند.'], 409);
        }
        $job = $store->update($id, static function (array $job): array {
            if (in_array($job['status'], ['queued', 'downloading'], true)) $job['status'] = 'paused';
            if (isset($job['committed_downloaded'])) $job['downloaded'] = (int) $job['committed_downloaded'];
            $job['active_chunks'] = [];
            $job['lease_until'] = 0;
            $job['worker_token'] = null;
            $job['updated_at'] = time();
            return $job;
        });
        json_response(['ok' => true, 'job' => $job]);
    }
    if ($action === 'resume') {
        $job = $store->update($id, static function (array $job): array {
            if (in_array($job['status'], ['paused', 'failed'], true)) {
                $job['status'] = 'queued';
                $job['error'] = null;
                $job['updated_at'] = time();
            }
            return $job;
        });
        json_response(['ok' => true, 'job' => $job]);
    }
    if ($action === 'retry') {
        $job = $store->update($id, static function (array $job): array {
            $part = ($job['destination_dir'] ?? STORAGE_PATH) . '/' . $job['filename'] . '.part';
            if (!$job['range_supported'] && is_file($part)) @unlink($part);
            if (!$job['range_supported']) {
                $job['downloaded'] = 0;
                $job['committed_downloaded'] = 0;
            }
            $job['status'] = 'queued';
            $job['error'] = null;
            $job['active_chunks'] = [];
            $job['lease_until'] = 0;
            $job['worker_token'] = null;
            $job['updated_at'] = time();
            return $job;
        });
        json_response(['ok' => true, 'job' => $job]);
    }
    if ($action === 'delete') {
        $job = $store->delete($id);
        if (!$job) json_response(['ok' => false, 'error' => 'دانلود پیدا نشد.'], 404);
        $directory = $job['destination_dir'] ?? STORAGE_PATH;
        foreach ([$directory . '/' . $job['filename'], $directory . '/' . $job['filename'] . '.part'] as $path) {
            if (is_file($path)) @unlink($path);
        }
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'عملیات ناشناخته است.'], 404);
} catch (Throwable $error) {
    json_response(['ok' => false, 'error' => $error->getMessage()], 422);
}

<?php
declare(strict_types=1);

final class RestoreManager
{
    private string $tasksPath;
    private string $workRoot;
    private string $safetyRoot;

    public function __construct(private Store $downloads, private Downloader $downloader, private array $config)
    {
        $this->tasksPath = DATA_PATH . '/restore-tasks.json';
        $this->workRoot = STORAGE_PATH . '/restore-work';
        $this->safetyRoot = STORAGE_PATH . '/pre-restore';
        foreach ([$this->workRoot, $this->safetyRoot] as $directory) {
            if (!is_dir($directory)) mkdir($directory, 0750, true);
        }
        if (!is_file($this->tasksPath)) file_put_contents($this->tasksPath, '{}', LOCK_EX);
    }

    public function all(): array
    {
        $tasks = array_values($this->locked(false, static fn(array $tasks): array => $tasks));
        usort($tasks, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        return array_map([$this, 'publicTask'], $tasks);
    }

    public function create(string $type, string $value): array
    {
        if (!in_array($type, ['url', 'local', 'download'], true)) throw new InvalidArgumentException('نوع منبع بکاپ معتبر نیست.');
        $id = bin2hex(random_bytes(8));
        $archive = null;
        $downloadId = null;
        if ($type === 'url') {
            $job = $this->downloader->create($value);
            $downloadId = $job['id'];
        } elseif ($type === 'download') {
            $job = $this->downloads->get(preg_replace('/[^a-f0-9]/', '', $value));
            if (!$job || $job['status'] !== 'completed') throw new RuntimeException('دانلود انتخاب‌شده هنوز کامل نشده است.');
            $archive = ($job['destination_dir'] ?? STORAGE_PATH) . '/' . $job['filename'];
            $downloadId = $job['id'];
        } else {
            $archive = $this->validateLocalArchive($value);
        }
        $task = [
            'id' => $id, 'source_type' => $type, 'source_label' => $value,
            'archive' => $archive, 'download_id' => $downloadId,
            'status' => $archive ? 'ready_to_scan' : 'waiting_download',
            'manifest' => null, 'progress' => [], 'selected' => [],
            'log' => [], 'created_at' => time(), 'updated_at' => time(),
        ];
        $this->put($task);
        return $this->publicTask($task);
    }

    public function inspect(string $id): array
    {
        $task = $this->getRequired($id);
        $archive = $this->resolveArchive($task);
        $this->assertArchive($archive);
        $manifest = ['public_html' => ['files' => 0, 'bytes' => 0], 'databases' => [], 'email_data' => ['files' => 0, 'bytes' => 0], 'email_accounts' => []];
        $phar = new PharData($archive);
        $count = 0;
        foreach (new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if (++$count > $this->config['restore_max_archive_entries']) throw new RuntimeException('تعداد فایل‌های آرشیو از سقف بررسی بیشتر است.');
            if ($entry->isDir() || $entry->isLink()) continue;
            $relative = $this->relativeArchivePath($entry->getPathname(), $archive);
            $size = (int) $entry->getSize();
            if ($this->sectionRelative($relative, 'public_html') !== null) {
                $manifest['public_html']['files']++; $manifest['public_html']['bytes'] += $size;
            }
            if (($db = $this->databaseName($relative)) !== null) $manifest['databases'][$db] = ['file' => $relative, 'bytes' => $size];
            if ($this->sectionRelative($relative, 'email_data') !== null) {
                $manifest['email_data']['files']++; $manifest['email_data']['bytes'] += $size;
            }
            if (preg_match('~(?:^|/)homedir/etc/([^/]+)/shadow$~', $relative, $match)) {
                $contents = @file_get_contents($entry->getPathname());
                foreach (preg_split('/\R/', (string) $contents) as $line) {
                    if ($line === '' || !str_contains($line, ':')) continue;
                    [$user, $hash] = array_pad(explode(':', $line, 3), 3, '');
                    if ($user !== '' && $hash !== '') $manifest['email_accounts'][] = ['email' => $user . '@' . $match[1], 'user' => $user, 'domain' => $match[1], 'password_hash' => $hash];
                }
            }
        }
        ksort($manifest['databases']);
        $task['archive'] = $archive;
        $task['manifest'] = $manifest;
        $task['status'] = 'inspected';
        $task['updated_at'] = time();
        $task['log'][] = ['time' => time(), 'type' => 'success', 'message' => 'ساختار بکاپ با موفقیت بررسی شد.'];
        $this->put($task);
        return $this->publicTask($task);
    }

    public function start(string $id, array $sections): array
    {
        $allowed = ['public_html', 'databases', 'email_data', 'email_accounts'];
        $selected = array_values(array_intersect($allowed, array_map('strval', $sections)));
        if (!$selected) throw new InvalidArgumentException('حداقل یک بخش را برای بازیابی انتخاب کنید.');
        $task = $this->getRequired($id);
        if (!$task['manifest']) throw new RuntimeException('ابتدا بکاپ را بررسی کنید.');
        $task['selected'] = $selected;
        $task['status'] = 'restoring';
        $task['progress'] = [];
        foreach ($selected as $section) $task['progress'][$section] = ['cursor' => 0, 'done' => false];
        foreach (array_keys($task['manifest']['databases']) as $db) $task['progress']['database_import'][$db] = ['offset' => 0, 'done' => false];
        $task['updated_at'] = time();
        $task['log'][] = ['time' => time(), 'type' => 'info', 'message' => 'عملیات بازیابی آغاز شد.'];
        $this->put($task);
        return $this->publicTask($task);
    }

    public function step(string $id, array $databaseMappings = [], ?array $cpanel = null): array
    {
        $task = $this->getRequired($id);
        if ($task['status'] !== 'restoring') return $this->publicTask($task);
        foreach ($task['selected'] as $section) {
            if (!empty($task['progress'][$section]['done'])) continue;
            if (in_array($section, ['public_html', 'email_data'], true)) {
                $this->copySectionStep($task, $section);
            } elseif ($section === 'databases') {
                $this->databaseStep($task, $databaseMappings);
            } elseif ($section === 'email_accounts') {
                $this->emailAccountsStep($task, $cpanel);
            }
            $this->put($task);
            return $this->publicTask($task);
        }
        $task['status'] = 'completed';
        $task['updated_at'] = time();
        $task['log'][] = ['time' => time(), 'type' => 'success', 'message' => 'تمام بخش‌های انتخاب‌شده پردازش شدند.'];
        $this->put($task);
        return $this->publicTask($task);
    }

    private function copySectionStep(array &$task, string $section): void
    {
        $archive = $task['archive'];
        $cursor = (int) ($task['progress'][$section]['cursor'] ?? 0);
        $limit = (int) $this->config['restore_files_per_step'];
        $matched = 0; $copied = 0; $hasMore = false;
        $destinationRoot = $section === 'public_html' ? $this->publicRoot() : $this->homeRoot() . '/mail';
        $appRoot = realpath(ROOT_PATH) ?: ROOT_PATH;
        $phar = new PharData($archive);
        foreach (new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if ($entry->isDir() || $entry->isLink()) continue;
            $archiveRelative = $this->relativeArchivePath($entry->getPathname(), $archive);
            $relative = $this->sectionRelative($archiveRelative, $section);
            if ($relative === null || !$this->safeRelative($relative)) continue;
            if ($matched++ < $cursor) continue;
            if ($copied >= $limit) { $hasMore = true; break; }
            $target = $destinationRoot . '/' . $relative;
            if ($section === 'public_html' && $this->pathInside($target, $appRoot)) {
                $task['log'][] = ['time' => time(), 'type' => 'warning', 'message' => 'فایل متعلق به خود سامانه محافظت و رد شد: ' . $relative];
                $copied++; continue;
            }
            $this->backupExisting($task['id'], $section, $relative, $target);
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) throw new RuntimeException('ساخت پوشه مقصد ناموفق بود: ' . $parent);
            $source = fopen($entry->getPathname(), 'rb'); $dest = fopen($target, 'wb');
            if (!$source || !$dest) throw new RuntimeException('کپی فایل ناموفق بود: ' . $relative);
            stream_copy_to_stream($source, $dest); fclose($source); fclose($dest);
            @chmod($target, 0640);
            $copied++;
        }
        $task['progress'][$section]['cursor'] = $cursor + $copied;
        $task['progress'][$section]['done'] = !$hasMore;
        $task['updated_at'] = time();
        if (!$hasMore) $task['log'][] = ['time' => time(), 'type' => 'success', 'message' => ($section === 'public_html' ? 'فایل‌های public_html' : 'پیام‌های ایمیل') . ' بازیابی شد.'];
    }

    private function databaseStep(array &$task, array $mappings): void
    {
        $databases = $task['manifest']['databases'];
        if (!$databases) { $task['progress']['databases']['done'] = true; return; }
        foreach ($databases as $sourceName => $meta) {
            if (!empty($task['progress']['database_import'][$sourceName]['done'])) continue;
            $mapping = $mappings[$sourceName] ?? null;
            if (!is_array($mapping) || empty($mapping['database']) || empty($mapping['user'])) throw new RuntimeException('مشخصات دیتابیس مقصد برای «' . $sourceName . '» کامل نیست.');
            $dump = $this->extractDatabaseDump($task, $sourceName, $meta['file']);
            $offset = (int) $task['progress']['database_import'][$sourceName]['offset'];
            $result = $this->importSqlChunk($dump, $offset, $mapping);
            $task['progress']['database_import'][$sourceName] = $result;
            if ($result['done']) $task['log'][] = ['time' => time(), 'type' => 'success', 'message' => 'دیتابیس ' . $sourceName . ' وارد شد.'];
            $allDone = array_reduce($task['progress']['database_import'], static fn(bool $carry, array $item): bool => $carry && !empty($item['done']), true);
            $task['progress']['databases']['done'] = $allDone;
            $task['updated_at'] = time();
            return;
        }
        $task['progress']['databases']['done'] = true;
    }

    private function emailAccountsStep(array &$task, ?array $cpanel): void
    {
        $accounts = $task['manifest']['email_accounts'];
        if (!$accounts) { $task['progress']['email_accounts']['done'] = true; return; }
        if (!$cpanel || empty($cpanel['host']) || empty($cpanel['username']) || empty($cpanel['token'])) throw new RuntimeException('برای ساخت حساب‌های ایمیل، میزبان، نام کاربری و API Token سی‌پنل لازم است.');
        $cursor = (int) $task['progress']['email_accounts']['cursor'];
        $end = min(count($accounts), $cursor + 5);
        for ($i = $cursor; $i < $end; $i++) {
            $account = $accounts[$i];
            $result = $this->cpanelCall($cpanel, 'Email', 'add_pop', ['email' => $account['user'], 'domain' => $account['domain'], 'password_hash' => $account['password_hash'], 'quota' => 0]);
            if (empty($result['status'])) {
                $error = implode(' ', (array) ($result['errors'] ?? []));
                if (!preg_match('/exist|already/i', $error)) throw new RuntimeException('ساخت ' . $account['email'] . ' ناموفق بود: ' . $error);
                $task['log'][] = ['time' => time(), 'type' => 'warning', 'message' => 'حساب موجود رد شد: ' . $account['email']];
            }
        }
        $task['progress']['email_accounts']['cursor'] = $end;
        $task['progress']['email_accounts']['done'] = $end >= count($accounts);
        if ($end >= count($accounts)) $task['log'][] = ['time' => time(), 'type' => 'success', 'message' => 'حساب‌های ایمیل از طریق UAPI بررسی و ایجاد شدند.'];
        $task['updated_at'] = time();
    }

    private function importSqlChunk(string $path, int $offset, array $mapping): array
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) throw new RuntimeException('افزونه pdo_mysql روی این هاست فعال نیست.');
        $host = trim((string) ($mapping['host'] ?? 'localhost')) ?: 'localhost';
        $port = max(1, min(65535, (int) ($mapping['port'] ?? 3306)));
        $database = preg_replace('/[^a-zA-Z0-9_$-]/', '', (string) $mapping['database']);
        $charset = in_array(($mapping['charset'] ?? ''), ['utf8mb4', 'utf8'], true) ? $mapping['charset'] : 'utf8mb4';
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset={$charset}", (string) $mapping['user'], (string) ($mapping['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        $handle = fopen($path, 'rb'); if (!$handle) throw new RuntimeException('فایل SQL قابل خواندن نیست.');
        fseek($handle, $offset); $executed = 0; $statement = ''; $started = microtime(true);
        while (($line = fgets($handle)) !== false) {
            $trim = trim($line);
            if ($statement === '' && ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#') || str_starts_with($trim, '/*'))) continue;
            if ($statement === '' && preg_match('/^(CREATE DATABASE|USE |LOCK TABLES|UNLOCK TABLES)/i', $trim)) continue;
            $statement .= $line;
            if (!$this->sqlStatementComplete($statement)) continue;
            $pdo->exec($statement); $statement = ''; $executed++;
            $offset = ftell($handle);
            if ($executed >= $this->config['restore_sql_statements_per_step'] || microtime(true) - $started > 8) break;
        }
        $done = feof($handle) && trim($statement) === '';
        fclose($handle);
        return ['offset' => $offset, 'done' => $done];
    }

    private function sqlStatementComplete(string $sql): bool
    {
        $single = $double = $backtick = $escape = false;
        for ($i = 0, $length = strlen($sql); $i < $length; $i++) {
            $char = $sql[$i];
            if ($escape) { $escape = false; continue; }
            if ($char === '\\') { $escape = true; continue; }
            if (!$double && !$backtick && $char === "'") $single = !$single;
            elseif (!$single && !$backtick && $char === '"') $double = !$double;
            elseif (!$single && !$double && $char === '`') $backtick = !$backtick;
        }
        return !$single && !$double && !$backtick && str_ends_with(rtrim($sql), ';');
    }

    private function extractDatabaseDump(array $task, string $name, string $archiveFile): string
    {
        $directory = $this->workRoot . '/' . $task['id'] . '/mysql';
        if (!is_dir($directory)) mkdir($directory, 0750, true);
        $target = $directory . '/' . Security::safeFilename($name) . '.sql';
        if (is_file($target)) return $target;
        $phar = new PharData($task['archive']);
        foreach (new RecursiveIteratorIterator($phar) as $entry) {
            if ($this->relativeArchivePath($entry->getPathname(), $task['archive']) !== $archiveFile) continue;
            $source = fopen($entry->getPathname(), 'rb'); $dest = fopen($target, 'wb');
            if (!$source || !$dest) throw new RuntimeException('استخراج dump دیتابیس ناموفق بود.');
            stream_copy_to_stream($source, $dest); fclose($source); fclose($dest); return $target;
        }
        throw new RuntimeException('فایل SQL در آرشیو پیدا نشد.');
    }

    private function cpanelCall(array $cpanel, string $module, string $function, array $params): array
    {
        $host = rtrim((string) $cpanel['host'], '/');
        if (!preg_match('~^https://[a-zA-Z0-9.-]+(?::2083)?$~', $host)) throw new InvalidArgumentException('نشانی cPanel باید HTTPS و روی درگاه 2083 باشد.');
        $url = $host . '/execute/' . rawurlencode($module) . '/' . rawurlencode($function) . '?' . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $cpanel['username'] . ':' . $cpanel['token'], 'Accept: application/json'], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $body = curl_exec($ch); $error = curl_error($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($body === false || $status !== 200) throw new RuntimeException('ارتباط با UAPI ناموفق بود: ' . ($error ?: 'HTTP ' . $status));
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['result'])) throw new RuntimeException('پاسخ UAPI معتبر نیست.');
        return $json['result'];
    }

    private function resolveArchive(array $task): string
    {
        if ($task['archive'] && is_file($task['archive'])) return $task['archive'];
        if ($task['download_id']) {
            $job = $this->downloads->get($task['download_id']);
            if (!$job || $job['status'] !== 'completed') throw new RuntimeException('دانلود فایل بکاپ هنوز کامل نشده است.');
            return ($job['destination_dir'] ?? STORAGE_PATH) . '/' . $job['filename'];
        }
        throw new RuntimeException('فایل بکاپ در دسترس نیست.');
    }

    private function validateLocalArchive(string $path): string
    {
        $real = realpath(trim($path));
        if (!$real || !is_file($real) || !is_readable($real)) throw new InvalidArgumentException('فایل محلی قابل خواندن نیست.');
        $roots = array_merge([$this->homeRoot(), STORAGE_PATH], (array) $this->config['local_backup_roots']);
        foreach ($roots as $root) {
            $rootReal = realpath($root);
            if ($rootReal && $this->pathInside($real, $rootReal)) { $this->assertArchive($real); return $real; }
        }
        throw new InvalidArgumentException('مسیر فایل خارج از محدوده مجاز حساب است.');
    }

    private function assertArchive(string $path): void
    {
        if (!preg_match('/\.(?:tar|tar\.gz|tgz)$/i', $path)) throw new InvalidArgumentException('فقط آرشیوهای tar، tar.gz و tgz پشتیبانی می‌شوند.');
        if (!is_file($path) || filesize($path) < 64) throw new InvalidArgumentException('فایل بکاپ معتبر نیست.');
        try { new PharData($path); } catch (Throwable) { throw new InvalidArgumentException('آرشیو بکاپ خراب یا پشتیبانی‌نشده است.'); }
    }

    private function relativeArchivePath(string $entryPath, string $archive): string
    {
        $entry = str_replace('\\', '/', $entryPath);
        $archive = str_replace('\\', '/', $archive);
        $prefix = 'phar://' . $archive . '/';
        $relative = str_starts_with($entry, $prefix) ? substr($entry, strlen($prefix)) : $entry;
        return ltrim($relative, '/');
    }

    private function sectionRelative(string $path, string $section): ?string
    {
        $patterns = [
            'public_html' => '~(?:^|/)homedir/public_html/(.+)$~',
            'email_data' => '~(?:^|/)homedir/mail/(.+)$~',
        ];
        if (!isset($patterns[$section]) || !preg_match($patterns[$section], $path, $match)) return null;
        return $match[1];
    }

    private function databaseName(string $path): ?string
    {
        return preg_match('~(?:^|/)mysql/([^/]+)\.sql$~i', $path, $match) ? $match[1] : null;
    }

    private function safeRelative(string $path): bool
    {
        return $path !== '' && !str_contains($path, "\0") && !preg_match('~(?:^|/)\.\.(?:/|$)~', str_replace('\\', '/', $path)) && !preg_match('~^[a-zA-Z]:|^/~', $path);
    }

    private function backupExisting(string $taskId, string $section, string $relative, string $target): void
    {
        if (!is_file($target)) return;
        $backup = $this->safetyRoot . '/' . $taskId . '/' . $section . '/' . $relative;
        if (is_file($backup)) return;
        if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0750, true);
        copy($target, $backup);
    }

    private function homeRoot(): string
    {
        if (!empty($this->config['cpanel_home_root'])) return rtrim((string) $this->config['cpanel_home_root'], '/\\');
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(ROOT_PATH);
        return rtrim(dirname($documentRoot), '/\\');
    }

    private function publicRoot(): string
    {
        if (!empty($this->config['public_html_root'])) return rtrim((string) $this->config['public_html_root'], '/\\');
        return rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(ROOT_PATH)), '/\\');
    }

    private function pathInside(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
        $root = strtolower(str_replace('\\', '/', rtrim($root, '/\\')));
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function publicTask(array $task): array
    {
        unset($task['archive']);
        if (isset($task['manifest']['email_accounts'])) {
            $task['manifest']['email_accounts_count'] = count($task['manifest']['email_accounts']);
            $task['manifest']['email_accounts'] = array_map(static fn(array $item): array => ['email' => $item['email']], $task['manifest']['email_accounts']);
        }
        return $task;
    }

    private function getRequired(string $id): array
    {
        $task = $this->locked(false, static fn(array $tasks): ?array => $tasks[$id] ?? null);
        if (!$task) throw new RuntimeException('عملیات بازیابی پیدا نشد.');
        return $task;
    }

    private function put(array $task): void
    {
        $this->locked(true, static function (array &$tasks) use ($task): void { $tasks[$task['id']] = $task; });
    }

    private function locked(bool $write, callable $callback): mixed
    {
        $handle = fopen($this->tasksPath, 'c+'); if (!$handle) throw new RuntimeException('فایل عملیات بازیابی در دسترس نیست.');
        try {
            flock($handle, $write ? LOCK_EX : LOCK_SH); rewind($handle);
            $tasks = json_decode(stream_get_contents($handle) ?: '{}', true); if (!is_array($tasks)) $tasks = [];
            $result = $callback($tasks);
            if ($write) { rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); fflush($handle); }
            flock($handle, LOCK_UN); return $result;
        } finally { fclose($handle); }
    }
}

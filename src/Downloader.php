<?php
declare(strict_types=1);

final class FileConflictException extends RuntimeException
{
    public function __construct(public readonly string $filename)
    {
        parent::__construct('فایلی با نام «' . $filename . '» در مسیر مقصد وجود دارد.');
    }
}

final class Downloader
{
    public function __construct(private Store $store, private array $config) {}

    public function create(string $url, ?string $requestedName = null, ?string $destination = null, ?string $conflictAction = 'rename'): array
    {
        if (!in_array($conflictAction, [null, 'rename', 'overwrite'], true)) {
            throw new InvalidArgumentException('روش برخورد با فایل هم‌نام معتبر نیست.');
        }
        $target = Security::validateUrl($url, $this->config);
        $destinationInfo = Security::resolveDownloadDirectory($destination, $this->config);
        $meta = $this->probe($target['url']);
        $pathName = basename((string) (parse_url($meta['final_url'], PHP_URL_PATH) ?: ''));
        $requestedFilename = Security::safeFilename($requestedName ?: ($meta['filename'] ?: $pathName ?: 'download.bin'));
        $finalPath = $destinationInfo['path'] . '/' . $requestedFilename;
        $partPath = $finalPath . '.part';
        $hasConflict = file_exists($finalPath) || is_link($finalPath) || file_exists($partPath) || is_link($partPath);
        if ($hasConflict && $conflictAction === null) throw new FileConflictException($requestedFilename);
        if ($hasConflict && $conflictAction === 'overwrite') {
            foreach ([$finalPath, $partPath] as $existingPath) {
                if (is_dir($existingPath) && !is_link($existingPath)) {
                    throw new RuntimeException('مسیر هم‌نام یک پوشه است و قابل جایگزینی نیست.');
                }
                if ((file_exists($existingPath) || is_link($existingPath)) && !unlink($existingPath)) {
                    throw new RuntimeException('حذف فایل هم‌نام برای جایگزینی امکان‌پذیر نیست.');
                }
            }
        }
        $filename = $hasConflict && $conflictAction === 'rename'
            ? Security::uniqueFilename($requestedFilename, $destinationInfo['path'])
            : $requestedFilename;
        $maxFileSize = (int) ($this->config['max_file_size'] ?? 0);
        if ($maxFileSize > 0 && $meta['size'] !== null && $meta['size'] > $maxFileSize) {
            throw new RuntimeException('حجم فایل از سقف تنظیم‌شده در config.php بیشتر است.');
        }
        $freeSpace = @disk_free_space($destinationInfo['path']);
        $reservedSpace = (int) ($this->config['min_free_space'] ?? 0);
        if ($meta['size'] !== null && $freeSpace !== false && $meta['size'] + $reservedSpace > $freeSpace) {
            throw new RuntimeException('فضای آزاد هاست برای این فایل کافی نیست؛ حجم فایل ' . format_bytes($meta['size']) . ' و فضای آزاد ' . format_bytes($freeSpace) . ' است.');
        }
        $id = bin2hex(random_bytes(8));
        $now = time();
        return $this->store->put([
            'id' => $id,
            'url' => $url,
            'final_url' => $meta['final_url'],
            'filename' => $filename,
            'destination_dir' => $destinationInfo['path'],
            'destination_label' => $destinationInfo['label'],
            'size' => $meta['size'],
            'downloaded' => 0,
            'committed_downloaded' => 0,
            'status' => 'queued',
            'range_supported' => $meta['range_supported'],
            'connections' => $meta['range_supported'] ? max(1, min((int) ($this->config['max_multipart_connections'] ?? 6), (int) ($this->config['default_multipart_connections'] ?? 1))) : 1,
            'adaptive_chunk_size' => (int) ($this->config['chunk_size'] ?? 4 * 1024 * 1024),
            'active_chunks' => [],
            'content_type' => $meta['content_type'],
            'speed' => 0,
            'error' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'completed_at' => null,
            'lease_until' => 0,
            'worker_token' => null,
        ]);
    }

    public function process(string $id): array
    {
        $token = bin2hex(random_bytes(8));
        $now = time();
        $job = $this->store->update($id, static function (array $job) use ($token, $now): array {
            if (!in_array($job['status'], ['queued', 'downloading'], true)) return $job;
            if (($job['lease_until'] ?? 0) > $now) return $job;
            if (isset($job['committed_downloaded'])) $job['downloaded'] = (int) $job['committed_downloaded'];
            $job['status'] = 'downloading';
            $job['worker_token'] = $token;
            $job['lease_until'] = $now + 40;
            $job['error'] = null;
            return $job;
        });
        if (!$job) throw new RuntimeException('دانلود پیدا نشد.');
        if (($job['worker_token'] ?? null) !== $token) return $job;

        $destinationInfo = Security::resolveDownloadDirectory((string) ($job['destination_dir'] ?? ''), $this->config);
        $destinationDir = $destinationInfo['path'];
        $start = (int) ($job['committed_downloaded'] ?? $job['downloaded'] ?? 0);
        $maximumConnections = max(1, (int) ($this->config['max_multipart_connections'] ?? 6));
        $requestedConnections = max(1, min($maximumConnections, (int) ($job['connections'] ?? 1)));
        $connections = !empty($job['range_supported']) && $job['size'] !== null ? $requestedConnections : 1;
        $chunk = $this->adaptiveChunkSize($job, $connections);
        if ($job['size'] !== null) {
            $remaining = max(1, (int) $job['size'] - $start);
            $chunk = min($chunk, (int) ceil($remaining / $connections));
        }
        $ranges = [];
        for ($index = 0; $index < $connections; $index++) {
            $rangeStart = $start + ($index * $chunk);
            if ($job['size'] !== null && $rangeStart >= (int) $job['size']) break;
            $rangeEnd = empty($job['range_supported']) && $job['size'] !== null
                ? (int) $job['size'] - 1
                : $rangeStart + $chunk - 1;
            if ($job['size'] !== null) $rangeEnd = min($rangeEnd, (int) $job['size'] - 1);
            $ranges[] = ['start' => $rangeStart, 'end' => $rangeEnd];
        }
        if (!$ranges) throw new RuntimeException('بازه‌ای برای ادامه دانلود باقی نمانده است.');

        $initialChunks = array_map(static fn(array $range, int $index): array => [
            'index' => $index + 1,
            'start' => $range['start'],
            'end' => $range['end'],
            'downloaded' => 0,
            'status' => 'downloading',
        ], $ranges, array_keys($ranges));
        $this->store->update($id, static function (array $current) use ($token, $initialChunks, $chunk): array {
            if (($current['worker_token'] ?? null) !== $token) return $current;
            $current['active_chunks'] = $initialChunks;
            $current['adaptive_chunk_size'] = $chunk;
            return $current;
        });

        $freeSpace = @disk_free_space($destinationDir);
        $reservedSpace = (int) ($this->config['min_free_space'] ?? 0);
        $batchBytes = array_sum(array_map(static fn(array $range): int => $range['end'] - $range['start'] + 1, $ranges));
        if ($freeSpace !== false && $freeSpace < $batchBytes + $reservedSpace) {
            return $this->store->update($id, static function (array $current): array {
                $current['status'] = 'failed';
                $current['error'] = 'فضای آزاد هاست برای ادامه دانلود کافی نیست.';
                $current['lease_until'] = 0;
                $current['worker_token'] = null;
                $current['updated_at'] = time();
                return $current;
            }) ?? throw new RuntimeException('به‌روزرسانی صف ناموفق بود.');
        }

        $partPath = $destinationDir . '/' . $job['filename'] . '.part';
        $initializer = fopen($partPath, $start === 0 ? 'wb' : 'c+b');
        if (!$initializer) throw new RuntimeException('فایل مقصد قابل نوشتن نیست.');
        fclose($initializer);

        $written = 0;
        $started = microtime(true);
        $lastReportedBytes = 0;
        $lastReportedAt = $started;
        $multipart = count($ranges) > 1;
        $rangeWritten = array_fill(0, count($ranges), 0);
        $reportProgress = function (int $rangeIndex, int $saved) use (&$written, &$rangeWritten, &$lastReportedBytes, &$lastReportedAt, $started, $start, $id, $token, $multipart, $job, $ranges): void {
            $written += $saved;
            $rangeWritten[$rangeIndex] += $saved;
            $now = microtime(true);
            if (($written - $lastReportedBytes) < 524288 && ($now - $lastReportedAt) < 1.0) return;
            $downloaded = $start + $written;
            if ($job['size'] !== null) $downloaded = min($downloaded, (int) $job['size']);
            $speed = (int) round($written / max(0.001, $now - $started));
            $chunks = array_map(static function (array $range, int $index) use ($rangeWritten): array {
                $size = $range['end'] - $range['start'] + 1;
                $received = min($size, $rangeWritten[$index]);
                return ['index' => $index + 1, 'start' => $range['start'], 'end' => $range['end'], 'downloaded' => $received, 'status' => $received >= $size ? 'completed' : 'downloading'];
            }, $ranges, array_keys($ranges));
            $this->store->update($id, static function (array $current) use ($token, $downloaded, $speed, $multipart, $chunks): array {
                if (($current['worker_token'] ?? null) !== $token || ($current['status'] ?? null) !== 'downloading') return $current;
                $current['downloaded'] = $downloaded;
                if (!$multipart) $current['committed_downloaded'] = $downloaded;
                $current['active_chunks'] = $chunks;
                $current['speed'] = $speed;
                $current['updated_at'] = time();
                $current['lease_until'] = time() + 40;
                return $current;
            });
            $lastReportedBytes = $written;
            $lastReportedAt = $now;
        };

        $result = null;
        try {
            if ($multipart) {
                $parallel = $this->fetchParallelRanges($job['final_url'], $ranges, $partPath, $reportProgress);
                $result = ['status' => 206, 'total' => $parallel['total']];
            } else {
                $file = fopen($partPath, 'c+b');
                if (!$file) throw new RuntimeException('فایل مقصد قابل نوشتن نیست.');
                if (fseek($file, $start) !== 0) {
                    fclose($file);
                    throw new RuntimeException('تنظیم محل نوشتن فایل ناموفق بود.');
                }
                try {
                    $range = $ranges[0];
                    $result = $this->fetch($job['final_url'], $range['start'], $range['end'], function (string $data) use ($file, $reportProgress): int {
                        $saved = fwrite($file, $data);
                        if ($saved === false) return 0;
                        $reportProgress(0, $saved);
                        return $saved;
                    });
                } finally {
                    fclose($file);
                }
            }
        } catch (Throwable $error) {
            if ($multipart) {
                $truncate = fopen($partPath, 'c+b');
                if ($truncate) {
                    ftruncate($truncate, $start);
                    fclose($truncate);
                }
                $this->store->update($id, static function (array $current) use ($token, $start): array {
                    if (($current['worker_token'] ?? null) !== $token) return $current;
                    $current['downloaded'] = $start;
                    $current['committed_downloaded'] = $start;
                    $current['active_chunks'] = [];
                    $current['speed'] = 0;
                    return $current;
                });
            }
            throw $error;
        }
        $elapsed = max(0.001, microtime(true) - $started);

        if ($start > 0 && $result['status'] !== 206) {
            $this->store->update($id, static function (array $current): array {
                $current['status'] = 'failed';
                $current['error'] = 'سرور مبدأ از ادامه دانلود پشتیبانی نمی‌کند.';
                $current['lease_until'] = 0;
                $current['worker_token'] = null;
                return $current;
            });
            throw new RuntimeException('سرور مبدأ از ادامه دانلود پشتیبانی نمی‌کند.');
        }
        if (!in_array($result['status'], [200, 206], true)) throw new RuntimeException('پاسخ نامعتبر از سرور مبدأ: HTTP ' . $result['status']);

        $newOffset = $start + $written;
        $knownSize = $job['size'];
        if ($knownSize === null && $result['total'] !== null) $knownSize = $result['total'];
        $complete = ($knownSize !== null && $newOffset >= $knownSize) || $written === 0;

        $completedChunks = array_map(static function (array $range, int $index): array {
            $size = $range['end'] - $range['start'] + 1;
            return ['index' => $index + 1, 'start' => $range['start'], 'end' => $range['end'], 'downloaded' => $size, 'status' => 'completed'];
        }, $ranges, array_keys($ranges));
        return $this->store->update($id, function (array $current) use ($token, $newOffset, $written, $elapsed, $knownSize, $complete, $partPath, $completedChunks, $connections): array {
            if (($current['worker_token'] ?? null) !== $token) return $current;
            $current['downloaded'] = $newOffset;
            $current['committed_downloaded'] = $newOffset;
            $current['size'] = $knownSize;
            $current['speed'] = (int) round($written / $elapsed);
            $current['active_chunks'] = $completedChunks;
            $current['last_batch_connections'] = $connections;
            $current['updated_at'] = time();
            $current['lease_until'] = 0;
            $current['worker_token'] = null;
            if ($complete) {
                $final = ($current['destination_dir'] ?? STORAGE_PATH) . '/' . $current['filename'];
                if (!rename($partPath, $final)) throw new RuntimeException('نهایی‌سازی فایل ناموفق بود.');
                $current['status'] = 'completed';
                $current['completed_at'] = time();
                $current['speed'] = 0;
            } else {
                // Keep the visible state stable between chunks. A zero lease means
                // the browser or CLI worker may immediately claim the next chunk.
                $current['status'] = 'downloading';
            }
            return $current;
        }) ?? throw new RuntimeException('به‌روزرسانی صف ناموفق بود.');
    }

    private function adaptiveChunkSize(array $job, int $connections): int
    {
        $minimum = max(262144, (int) ($this->config['min_chunk_size'] ?? 1024 * 1024));
        $maximum = max($minimum, (int) ($this->config['max_chunk_size'] ?? 16 * 1024 * 1024));
        $fallback = max($minimum, min($maximum, (int) ($this->config['chunk_size'] ?? 4 * 1024 * 1024)));
        $speed = (int) ($job['speed'] ?? 0);
        if ($speed <= 0) return $fallback;

        $previousConnections = max(1, (int) ($job['last_batch_connections'] ?? $job['connections'] ?? $connections));
        $perConnectionSpeed = $speed / $previousConnections;
        $timeoutTarget = max(2.0, ((int) ($this->config['request_timeout'] ?? 25)) * 0.5);
        $targetSeconds = min($timeoutTarget, max(2.0, (float) ($this->config['target_chunk_seconds'] ?? 8)));
        $calculated = (int) round(($perConnectionSpeed * $targetSeconds) / 262144) * 262144;
        return max($minimum, min($maximum, $calculated));
    }

    private function fetchParallelRanges(string $url, array $ranges, string $partPath, callable $onWrite): array
    {
        $target = Security::validateUrl($url, $this->config);
        $ip = $target['ips'][0];
        $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $multi = curl_multi_init();
        $handles = [];
        $files = [];
        $headers = [];
        $written = [];
        try {
            foreach ($ranges as $index => $range) {
                $file = fopen($partPath, 'c+b');
                if (!$file || fseek($file, $range['start']) !== 0) {
                    if ($file) fclose($file);
                    throw new RuntimeException('آماده‌سازی بخش هم‌زمان فایل ناموفق بود.');
                }
                $files[$index] = $file;
                $headers[$index] = [];
                $written[$index] = 0;
                $handle = curl_init($url);
                curl_setopt_array($handle, [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'],
                    CURLOPT_TIMEOUT => $this->config['request_timeout'],
                    CURLOPT_USERAGENT => $this->config['user_agent'],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_RANGE => $range['start'] . '-' . $range['end'],
                    CURLOPT_HTTPHEADER => ['Accept-Encoding: identity'],
                    CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $resolveIp],
                    CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, $index): int {
                        $length = strlen($line);
                        $parts = explode(':', $line, 2);
                        if (count($parts) === 2) $headers[$index][strtolower(trim($parts[0]))] = trim($parts[1]);
                        return $length;
                    },
                    CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$files, &$written, $index, $onWrite): int {
                        $saved = fwrite($files[$index], $data);
                        if ($saved === false) return 0;
                        $written[$index] += $saved;
                        $onWrite($index, $saved);
                        return $saved;
                    },
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $handles[$index] = $handle;
                curl_multi_add_handle($multi, $handle);
            }

            do {
                $multiStatus = curl_multi_exec($multi, $running);
                if ($multiStatus !== CURLM_OK) throw new RuntimeException('اجرای دانلود چندبخشی ناموفق بود.');
                if ($running > 0 && curl_multi_select($multi, 1.0) === -1) usleep(10000);
            } while ($running > 0);

            $total = null;
            foreach ($handles as $index => $handle) {
                $errorNumber = curl_errno($handle);
                $error = curl_error($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                if ($errorNumber !== 0) throw new RuntimeException('خطا در یکی از اتصال‌های هم‌زمان: ' . $error);
                if ($status !== 206) throw new RuntimeException('سرور مبدأ دانلود چندبخشی را نپذیرفت (HTTP ' . $status . ').');
                $expected = $ranges[$index]['end'] - $ranges[$index]['start'] + 1;
                if ($written[$index] !== $expected) throw new RuntimeException('یکی از بخش‌های هم‌زمان ناقص دریافت شد.');
                if (isset($headers[$index]['content-range']) && preg_match('/\/([0-9]+)$/', $headers[$index]['content-range'], $match)) {
                    $rangeTotal = (int) $match[1];
                    if ($total !== null && $total !== $rangeTotal) throw new RuntimeException('اندازه اعلام‌شده فایل در اتصال‌های هم‌زمان یکسان نیست.');
                    $total = $rangeTotal;
                }
            }
            return ['written' => array_sum($written), 'total' => $total];
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            foreach ($files as $file) {
                fflush($file);
                fclose($file);
            }
            curl_multi_close($multi);
        }
    }

    private function probe(string $url): array
    {
        $received = 0;
        $result = $this->fetch($url, 0, 0, static function (string $data) use (&$received): int {
            $received += strlen($data);
            return $received > 65536 ? 0 : strlen($data);
        }, true);
        if (!in_array($result['status'], [200, 206], true)) throw new RuntimeException('فایل مبدأ در دسترس نیست (HTTP ' . $result['status'] . ').');
        return [
            'final_url' => $result['final_url'],
            'size' => $result['total'],
            'range_supported' => $result['status'] === 206,
            'content_type' => $result['content_type'],
            'filename' => $result['filename'],
        ];
    }

    private function fetch(string $url, int $start, int $end, callable $writer, bool $allowWriteAbort = false): array
    {
        $redirects = 0;
        while (true) {
            $target = Security::validateUrl($url, $this->config);
            $headers = [];
            $ch = curl_init($url);
            $ip = $target['ips'][0];
            $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'],
                CURLOPT_TIMEOUT => $this->config['request_timeout'],
                CURLOPT_USERAGENT => $this->config['user_agent'],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RANGE => $start . '-' . $end,
                CURLOPT_HTTPHEADER => ['Accept-Encoding: identity'],
                CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $resolveIp],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    $length = strlen($line);
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static fn($curl, string $data): int => $writer($data),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $ok = curl_exec($ch);
            $error = curl_error($ch);
            $errorNumber = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
            curl_close($ch);

            if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                if (++$redirects > $this->config['max_redirects']) throw new RuntimeException('تعداد تغییر مسیر بیش از حد است.');
                $url = $this->absoluteUrl($url, $headers['location']);
                continue;
            }
            if ($ok === false && !($allowWriteAbort && $status > 0 && $errorNumber === CURLE_WRITE_ERROR)) {
                throw new RuntimeException('خطا در ارتباط با مبدأ: ' . ($error ?: 'دریافت ناقص پاسخ'));
            }
            $total = null;
            if (isset($headers['content-range']) && preg_match('/\/([0-9]+)$/', $headers['content-range'], $match)) $total = (int) $match[1];
            elseif ($status === 200 && isset($headers['content-length'])) $total = (int) $headers['content-length'];
            $filename = null;
            if (isset($headers['content-disposition']) && preg_match('/filename\*?=(?:UTF-8\'\')?["\']?([^"\';]+)/i', $headers['content-disposition'], $match)) $filename = rawurldecode(trim($match[1]));
            return compact('status', 'total', 'contentType', 'filename') + ['content_type' => $contentType, 'final_url' => $url];
        }
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location)) return $location;
        $parts = parse_url($base);
        $root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) return $root . $location;
        $directory = rtrim(str_replace('\\', '/', dirname($parts['path'] ?? '/')), '/');
        return $root . ($directory ? $directory . '/' : '/') . $location;
    }
}

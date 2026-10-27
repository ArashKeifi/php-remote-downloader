<?php
declare(strict_types=1);

final class Store
{
    private string $path;

    public function __construct()
    {
        $this->path = DATA_PATH . '/jobs.json';
        if (!is_file($this->path)) file_put_contents($this->path, '{}', LOCK_EX);
    }

    public function all(): array
    {
        return $this->locked(false, static fn(array $jobs): array => $jobs);
    }

    public function get(string $id): ?array
    {
        return $this->locked(false, static fn(array $jobs): ?array => $jobs[$id] ?? null);
    }

    public function put(array $job): array
    {
        return $this->locked(true, static function (array &$jobs) use ($job): array {
            $jobs[$job['id']] = $job;
            return $job;
        });
    }

    public function update(string $id, callable $callback): ?array
    {
        return $this->locked(true, static function (array &$jobs) use ($id, $callback): ?array {
            if (!isset($jobs[$id])) return null;
            $jobs[$id] = $callback($jobs[$id]) ?? $jobs[$id];
            return $jobs[$id];
        });
    }

    public function delete(string $id): ?array
    {
        return $this->locked(true, static function (array &$jobs) use ($id): ?array {
            if (!isset($jobs[$id])) return null;
            $job = $jobs[$id];
            unset($jobs[$id]);
            return $job;
        });
    }

    private function locked(bool $write, callable $callback): mixed
    {
        $handle = fopen($this->path, 'c+');
        if (!$handle) throw new RuntimeException('فایل صف قابل دسترسی نیست.');
        try {
            if (!flock($handle, $write ? LOCK_EX : LOCK_SH)) throw new RuntimeException('قفل صف در دسترس نیست.');
            rewind($handle);
            $raw = stream_get_contents($handle);
            $jobs = json_decode($raw ?: '{}', true);
            if (!is_array($jobs)) $jobs = [];
            $result = $callback($jobs);
            if ($write) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($jobs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                fflush($handle);
            }
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }
}

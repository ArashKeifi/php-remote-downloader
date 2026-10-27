<?php
declare(strict_types=1);

final class Security
{
    public static function validateUrl(string $url, array $config): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 4096 || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('نشانی واردشده معتبر نیست.');
        }
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('فقط نشانی‌های HTTP و HTTPS مجاز هستند.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) throw new InvalidArgumentException('نشانی دارای نام کاربری یا رمز مجاز نیست.');
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443, 8080, 8443], true)) throw new InvalidArgumentException('درگاه این نشانی مجاز نیست.');

        $ips = self::resolveHost($parts['host']);
        if (!$config['allow_private_networks']) {
            foreach ($ips as $ip) {
                $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
                if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) throw new InvalidArgumentException('دسترسی به نشانی‌های محلی یا خصوصی مسدود است.');
            }
        }
        return ['url' => $url, 'host' => $parts['host'], 'port' => $port, 'ips' => $ips, 'scheme' => strtolower($parts['scheme'])];
    }

    private static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            if (!empty($record['ip'])) $ips[] = $record['ip'];
            if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
        }
        if (!$ips) throw new InvalidArgumentException('نام میزبان قابل شناسایی نیست.');
        return array_values(array_unique($ips));
    }

    public static function safeFilename(string $name): string
    {
        $name = rawurldecode($name);
        $name = preg_replace('~[\x00-\x1F\x7F/\\\\<>:"|?*]+~u', '-', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', " .\t\n\r\0\x0B");
        if ($name === '' || in_array(strtolower($name), ['.', '..', '.htaccess', 'web.config'], true)) $name = 'download-' . date('Ymd-His') . '.bin';
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = function_exists('mb_substr') ? mb_substr($base, 0, 150) : substr($base, 0, 150);
        $extension = preg_replace('/[^a-zA-Z0-9]+/', '', $extension) ?: '';
        $extension = function_exists('mb_substr') ? mb_substr($extension, 0, 15) : substr($extension, 0, 15);
        return $base . ($extension !== '' ? '.' . $extension : '');
    }

    public static function uniqueFilename(string $name, string $directory = STORAGE_PATH): string
    {
        $candidate = self::safeFilename($name);
        $base = pathinfo($candidate, PATHINFO_FILENAME);
        $ext = pathinfo($candidate, PATHINFO_EXTENSION);
        $i = 1;
        while (file_exists($directory . '/' . $candidate) || is_link($directory . '/' . $candidate) || file_exists($directory . '/' . $candidate . '.part') || is_link($directory . '/' . $candidate . '.part')) {
            $candidate = $base . '-' . $i++ . ($ext ? '.' . $ext : '');
        }
        return $candidate;
    }

    public static function resolveDownloadDirectory(?string $input, array $config): array
    {
        $input = trim((string) $input);
        if ($input === '') return ['path' => STORAGE_PATH, 'label' => 'storage'];
        if (str_contains($input, "\0")) throw new InvalidArgumentException('مسیر ذخیره‌سازی معتبر نیست.');

        $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(ROOT_PATH));
        $homeRoot = rtrim((string) ($config['cpanel_home_root'] ?: dirname($documentRoot)), '/\\');
        $isAbsolute = str_starts_with($input, '/') || (bool) preg_match('~^[a-zA-Z]:[\\\\/]~', $input);
        $segments = preg_split('~[\\\\/]+~', $input) ?: [];
        if (in_array('..', $segments, true)) throw new InvalidArgumentException('استفاده از .. در مسیر ذخیره‌سازی مجاز نیست.');
        $candidate = $isAbsolute ? $input : $homeRoot . DIRECTORY_SEPARATOR . $input;
        $candidate = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate), DIRECTORY_SEPARATOR);

        $allowedRoots = array_merge([$homeRoot], (array) ($config['allowed_download_roots'] ?? []));
        $allowedRealRoots = [];
        foreach ($allowedRoots as $root) {
            $real = realpath((string) $root);
            if ($real !== false) $allowedRealRoots[] = $real;
        }
        if (!$allowedRealRoots) throw new RuntimeException('ریشه مجاز ذخیره‌سازی در دسترس نیست.');

        $parent = $candidate;
        while (!file_exists($parent) && dirname($parent) !== $parent) $parent = dirname($parent);
        $parentReal = realpath($parent);
        if ($parentReal === false || !self::insideAnyRoot($parentReal, $allowedRealRoots)) {
            throw new InvalidArgumentException('مسیر ذخیره‌سازی خارج از محدوده مجاز حساب است.');
        }
        if (!is_dir($candidate) && !mkdir($candidate, 0750, true) && !is_dir($candidate)) {
            throw new RuntimeException('امکان ساخت پوشه مقصد وجود ندارد.');
        }
        $realCandidate = realpath($candidate);
        if ($realCandidate === false || !self::insideAnyRoot($realCandidate, $allowedRealRoots)) {
            throw new InvalidArgumentException('مسیر ذخیره‌سازی از طریق پیوند نمادین از محدوده مجاز خارج می‌شود.');
        }
        if (!is_writable($realCandidate)) throw new RuntimeException('پوشه مقصد قابل نوشتن نیست.');

        $label = $realCandidate;
        $homeReal = realpath($homeRoot);
        if ($homeReal !== false && self::pathInside($realCandidate, $homeReal)) {
            $relative = ltrim(substr(str_replace('\\', '/', $realCandidate), strlen(str_replace('\\', '/', $homeReal))), '/');
            $label = $relative === '' ? '~/' : '~/' . $relative;
        }
        return ['path' => $realCandidate, 'label' => $label];
    }

    public static function listPublicDirectories(?string $relativePath, array $config): array
    {
        $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(ROOT_PATH));
        $configuredRoot = trim((string) ($config['public_html_root'] ?? ''));
        $publicRoot = realpath($configuredRoot !== '' ? $configuredRoot : $documentRoot);
        if ($publicRoot === false || !is_dir($publicRoot) || !is_readable($publicRoot)) {
            throw new RuntimeException('پوشه public_html قابل دسترسی نیست.');
        }

        $relative = trim(str_replace('\\', '/', (string) $relativePath), '/');
        if (str_contains($relative, "\0")) throw new InvalidArgumentException('مسیر پوشه معتبر نیست.');
        $segments = $relative === '' ? [] : explode('/', $relative);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new InvalidArgumentException('مسیر پوشه معتبر نیست.');
        }

        $candidate = $publicRoot . ($relative !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative) : '');
        $current = realpath($candidate);
        if ($current === false || !is_dir($current) || !self::pathInside($current, $publicRoot)) {
            throw new InvalidArgumentException('پوشه انتخاب‌شده در محدوده public_html نیست.');
        }

        $directories = [];
        foreach (new DirectoryIterator($current) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || str_starts_with($entry->getFilename(), '.')) continue;
            $childReal = realpath($entry->getPathname());
            if ($childReal === false || !self::pathInside($childReal, $publicRoot)) continue;
            $childRelative = ltrim(str_replace('\\', '/', substr($childReal, strlen($publicRoot))), '/');
            $directories[] = [
                'name' => $entry->getFilename(),
                'path' => $childRelative,
                'label' => 'public_html/' . $childRelative,
            ];
        }
        usort($directories, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $parent = null;
        if ($relative !== '') {
            $parentValue = str_replace('\\', '/', dirname($relative));
            $parent = $parentValue === '.' ? '' : trim($parentValue, '/');
        }
        return [
            'current' => [
                'path' => $relative,
                'label' => 'public_html' . ($relative !== '' ? '/' . $relative : ''),
                'value' => $current,
            ],
            'parent' => $parent,
            'directories' => $directories,
        ];
    }

    private static function insideAnyRoot(string $path, array $roots): bool
    {
        foreach ($roots as $root) if (self::pathInside($path, $root)) return true;
        return false;
    }

    private static function pathInside(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
        $root = strtolower(str_replace('\\', '/', rtrim($root, '/\\')));
        return $path === $root || str_starts_with($path, $root . '/');
    }
}

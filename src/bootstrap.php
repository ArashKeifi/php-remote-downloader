<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
date_default_timezone_set($config['timezone']);

const ROOT_PATH = __DIR__ . '/..';
const DATA_PATH = ROOT_PATH . '/data';
const STORAGE_PATH = ROOT_PATH . '/storage';
const SESSION_PATH = DATA_PATH . '/sessions';

foreach ([DATA_PATH, STORAGE_PATH] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('امکان ساخت پوشه داده وجود ندارد.');
    }
}
if (!is_dir(SESSION_PATH) && !mkdir(SESSION_PATH, 0700, true) && !is_dir(SESSION_PATH)) {
    throw new RuntimeException('امکان ساخت پوشه نشست‌ها وجود ندارد.');
}
if (!is_writable(SESSION_PATH)) throw new RuntimeException('پوشه نشست‌های برنامه قابل نوشتن نیست.');

if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_save_path(SESSION_PATH);
    session_name('bargir_session');
    $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $cookiePath = $scriptDirectory === '/' || $scriptDirectory === '.' ? '/' : rtrim($scriptDirectory, '/') . '/';
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    session_set_cookie_params([
        'path' => $cookiePath,
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => $secureCookie,
    ]);
    if (!session_start()) throw new RuntimeException('شروع نشست کاربری ناموفق بود.');
}

require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Downloader.php';
require_once __DIR__ . '/RestoreManager.php';

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array
{
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($type, 'application/json')) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function format_bytes(int|float|null $bytes): string
{
    if ($bytes === null || $bytes < 0) return 'نامشخص';
    $units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
    $value = (float) $bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return number_format($value, $i === 0 ? 0 : ($value >= 10 ? 1 : 2), '.', ',') . ' ' . $units[$i];
}

function app_settings(): array
{
    $path = DATA_PATH . '/settings.json';
    if (!is_file($path)) return [];
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function save_app_settings(array $settings): void
{
    $tmp = DATA_PATH . '/settings.tmp';
    file_put_contents($tmp, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, DATA_PATH . '/settings.json');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function require_auth(): void
{
    if (PHP_SAPI === 'cli') return;
    if (empty($_SESSION['authenticated'])) json_response(['ok' => false, 'error' => 'نیاز به ورود دارید.'], 401);
}

function require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) json_response(['ok' => false, 'error' => 'نشست نامعتبر است؛ صفحه را تازه کنید.'], 419);
}

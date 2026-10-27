<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$settings = app_settings();
$isSetup = !empty($settings['password_hash']);
$authenticated = !empty($_SESSION['authenticated']);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%230d1a2b'/%3E%3Cpath d='M32 13v27m0 0 11-11M32 40 21 29M17 51h30' fill='none' stroke='%2322bdf5' stroke-width='6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
    <title>بارگیر — مدیریت دانلودها</title>
    <link rel="stylesheet" href="assets/app.css?v=8">
</head>
<body data-authenticated="<?= $authenticated ? '1' : '0' ?>" data-setup="<?= $isSetup ? '1' : '0' ?>">
<svg aria-hidden="true" class="sprite">
    <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12m0 0 5-5m-5 5-5-5M5 19h14"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-pause" viewBox="0 0 24 24"><path d="M9 5v14M15 5v14"/></symbol>
    <symbol id="i-play" viewBox="0 0 24 24"><path d="m8 5 11 7-11 7Z"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"/></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 7v5h-5M4 17v-5h5m10.3-3A8 8 0 0 0 5.8 6L4 9m16 6-1.8 3A8 8 0 0 1 4.7 15"/></symbol>
    <symbol id="i-link" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.2 1.2M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.2-1.2"/></symbol>
    <symbol id="i-file" viewBox="0 0 24 24"><path d="M6 3h8l4 4v14H6zM14 3v5h5"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M10 5H5v14h5m4-4 4-3-4-3m4 3H9"/></symbol>
    <symbol id="i-archive" viewBox="0 0 24 24"><path d="M4 7h16v14H4zM3 3h18v4H3zm6 8h6"/></symbol>
    <symbol id="i-database" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7"/></symbol>
    <symbol id="i-mail" viewBox="0 0 24 24"><path d="M3 5h18v14H3zM3 6l9 7 9-7"/></symbol>
    <symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 6h7l2 2h9v11H3z"/></symbol>
</svg>

<div id="authView" class="auth-view" <?= $authenticated ? 'hidden' : '' ?>>
    <form id="authForm" class="auth-card">
        <div class="brand large"><span class="brand-mark"><svg><use href="#i-download"/></svg></span><span>بارگیر</span></div>
        <h1><?= $isSetup ? 'ورود به مدیریت دانلود' : 'راه‌اندازی اولیه' ?></h1>
        <p><?= $isSetup ? 'برای مشاهده و مدیریت فایل‌ها رمز مدیر را وارد کنید.' : 'یک رمز امن برای محافظت از دانلودهای هاست انتخاب کنید.' ?></p>
        <label for="password">رمز عبور</label>
        <input id="password" name="password" type="password" minlength="10" autocomplete="<?= $isSetup ? 'current-password' : 'new-password' ?>" required autofocus>
        <button class="primary wide" type="submit"><?= $isSetup ? 'ورود' : 'ساخت حساب مدیر' ?></button>
        <div id="authError" class="form-error" role="alert"></div>
    </form>
</div>

<div id="appView" class="app-shell" <?= !$authenticated ? 'hidden' : '' ?>>
    <header class="topbar">
        <div class="brand"><span class="brand-mark"><svg><use href="#i-download"/></svg></span><span>بارگیر</span></div>
        <nav class="app-tabs" aria-label="بخش‌های برنامه">
            <button class="active" type="button" data-view="downloadsView">دانلودها</button>
            <button type="button" data-view="restoreView">بازیابی بکاپ</button>
        </nav>
        <div class="top-actions">
            <label class="concurrency">دانلود موازی
                <select id="concurrencySelect" aria-label="تعداد دانلود موازی">
                    <?php for ($i = 1; $i <= $config['max_concurrency']; $i++): ?><option value="<?= $i ?>" <?= $i === $config['default_concurrency'] ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?>
                </select>
            </label>
            <button id="logoutBtn" class="icon-text ghost" type="button"><svg><use href="#i-logout"/></svg><span>خروج</span></button>
        </div>
    </header>

    <main>
        <div id="downloadsView" class="app-view active">
        <section class="intro">
            <div><h1>مدیریت دانلودها</h1><p>فایل‌ها را مستقیم روی هاست ذخیره کنید</p></div>
        </section>

        <form id="addForm" class="add-panel">
            <div class="url-field"><svg><use href="#i-link"/></svg><input id="urlInput" name="url" type="url" dir="ltr" placeholder="نشانی مستقیم فایل را وارد کنید…" autocomplete="url" required></div>
            <div class="destination-field">
                <input id="destinationInput" name="destination" type="hidden">
                <button id="openDirectoryPicker" class="destination-picker-button" type="button"><svg><use href="#i-folder"/></svg><span id="destinationPickerLabel">انتخاب مسیر ذخیره…</span><b>انتخاب</b></button>
            </div>
            <div class="name-field"><input id="filenameInput" name="filename" type="text" placeholder="نام فایل (اختیاری)" maxlength="170"></div>
            <button class="primary" type="submit"><svg><use href="#i-plus"/></svg><span>افزودن به صف</span></button>
        </form>
        <div id="formError" class="form-error"></div>

        <section class="stats" aria-label="خلاصه دانلودها">
            <article><span class="stat-icon cyan"><svg><use href="#i-download"/></svg></span><div><span>در حال دانلود</span><strong id="statActive">۰</strong></div></article>
            <article><span class="stat-icon amber">⌛</span><div><span>در صف</span><strong id="statQueued">۰</strong></div></article>
            <article><span class="stat-icon green"><svg><use href="#i-check"/></svg></span><div><span>تکمیل‌شده</span><strong id="statCompleted">۰</strong></div></article>
            <article class="disk-stat"><span class="stat-icon violet">◉</span><div><span>فضای آزاد</span><strong id="statDisk">—</strong><small id="diskRail"><i></i></small></div></article>
        </section>

        <section class="downloads-panel">
            <div class="panel-head">
                <div><h2>دانلودهای اخیر</h2><span id="resultCount">۰ مورد</span></div>
                <div class="panel-tools">
                    <div class="search-wrap"><input id="searchInput" type="search" placeholder="جست‌وجوی نام فایل…"></div>
                    <div id="filters" class="filters" role="tablist">
                        <button class="active" data-filter="all" type="button">همه</button>
                        <button data-filter="active" type="button">فعال</button>
                        <button data-filter="completed" type="button">تکمیل‌شده</button>
                        <button data-filter="failed" type="button">ناموفق</button>
                    </div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <colgroup><col class="col-file"><col class="col-path"><col class="col-size"><col class="col-progress"><col class="col-speed"><col class="col-connections"><col class="col-status"><col class="col-actions"></colgroup>
                    <thead><tr><th>نام فایل</th><th>مسیر ذخیره</th><th>اندازه</th><th>پیشرفت</th><th>سرعت</th><th title="تعداد اتصال‌های هم‌زمان برای این فایل">اتصالات</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                    <tbody id="jobsBody"></tbody>
                </table>
                <div id="emptyState" class="empty-state" hidden><span><svg><use href="#i-download"/></svg></span><h3>هنوز دانلودی ندارید</h3><p>یک نشانی مستقیم در کادر بالا وارد کنید تا اولین دانلود شروع شود.</p></div>
            </div>
        </section>
        </div>

        <div id="restoreView" class="app-view" hidden>
            <section class="intro restore-intro">
                <div><h1>بازیابی بکاپ cPanel</h1><p>بخش‌های موردنیاز را از Full Backup روی همین حساب برگردانید</p></div>
                <span class="safety-note">پیش از جایگزینی، نسخه نجات ساخته می‌شود</span>
            </section>

            <section class="restore-warning">
                <strong>بازیابی سطح کاربر</strong>
                <p>این ابزار جایگزین Full Account Restore در WHM نیست. دامنه‌ها باید از قبل روی حساب مقصد تعریف شده باشند و برای ساخت حساب‌های ایمیل به API Token cPanel نیاز است.</p>
            </section>

            <section class="restore-grid">
                <form id="restoreSourceForm" class="restore-card source-card">
                    <div class="step-title"><span>۱</span><div><h2>انتخاب فایل بکاپ</h2><p>فایل محلی، دانلود قبلی یا URL مستقیم</p></div></div>
                    <div class="source-tabs" role="radiogroup">
                        <label><input type="radio" name="source_type" value="local" checked><span>مسیر روی هاست</span></label>
                        <label><input type="radio" name="source_type" value="download"><span>دانلود تکمیل‌شده</span></label>
                        <label><input type="radio" name="source_type" value="url"><span>آدرس URL</span></label>
                    </div>
                    <div id="sourceLocal" class="source-input"><label>مسیر کامل فایل روی هاست</label><input id="restoreLocalPath" dir="ltr" placeholder="/home/user/backup.tar.gz"></div>
                    <div id="sourceDownload" class="source-input" hidden><label>فایل دانلودشده</label><select id="restoreDownloadSelect"></select></div>
                    <div id="sourceUrl" class="source-input" hidden><label>آدرس مستقیم بکاپ</label><input id="restoreUrl" type="url" dir="ltr" placeholder="https://remote.example/backup.tar.gz"></div>
                    <button class="primary" type="submit"><svg><use href="#i-archive"/></svg>ثبت و بررسی منبع</button>
                    <div id="restoreSourceError" class="form-error"></div>
                </form>

                <section id="restorePlan" class="restore-card plan-card" hidden>
                    <div class="step-title"><span>۲</span><div><h2>انتخاب بخش‌ها</h2><p id="restorePlanStatus">در انتظار بررسی آرشیو</p></div></div>
                    <button id="inspectRestoreBtn" class="secondary" type="button">بررسی ساختار بکاپ</button>
                    <div id="restoreManifest" class="manifest" hidden>
                        <label class="manifest-item"><input type="checkbox" value="public_html" checked><span class="manifest-icon cyan"><svg><use href="#i-file"/></svg></span><span><b>فایل‌های public_html</b><small id="manifestFiles">—</small></span></label>
                        <label class="manifest-item"><input type="checkbox" value="databases"><span class="manifest-icon violet"><svg><use href="#i-database"/></svg></span><span><b>دیتابیس‌ها</b><small id="manifestDatabases">—</small></span></label>
                        <label class="manifest-item"><input type="checkbox" value="email_accounts"><span class="manifest-icon amber"><svg><use href="#i-mail"/></svg></span><span><b>حساب‌های ایمیل</b><small id="manifestAccounts">—</small></span></label>
                        <label class="manifest-item"><input type="checkbox" value="email_data"><span class="manifest-icon green"><svg><use href="#i-mail"/></svg></span><span><b>پیام‌های ایمیل</b><small id="manifestMail">—</small></span></label>
                    </div>
                </section>
            </section>

            <section id="restoreCredentials" class="restore-card credentials-card" hidden>
                <div class="step-title"><span>۳</span><div><h2>اتصال‌های مقصد</h2><p>رمزها فقط در همین صفحه نگه داشته می‌شوند و روی سرور ذخیره نمی‌شوند</p></div></div>
                <div id="databaseMappings" class="mapping-list"></div>
                <div id="cpanelCredentials" class="credential-group" hidden>
                    <h3>دسترسی UAPI برای ساخت ایمیل‌ها</h3>
                    <div class="field-grid three"><label>نشانی cPanel<input id="cpanelHost" dir="ltr" placeholder="https://host.example.com:2083"></label><label>نام کاربری<input id="cpanelUser" dir="ltr" autocomplete="username"></label><label>API Token<input id="cpanelToken" type="password" dir="ltr" autocomplete="off"></label></div>
                </div>
                <label class="danger-confirm"><input id="restoreConfirm" type="checkbox"><span>از اطلاعات فعلی نسخه پشتیبان دارم و بازنویسی فایل‌ها و دیتابیس‌های انتخاب‌شده را تأیید می‌کنم.</span></label>
                <button id="startRestoreBtn" class="danger wide" type="button">شروع بازیابی انتخاب‌شده</button>
            </section>

            <section id="restoreProgress" class="restore-card progress-card" hidden>
                <div class="step-title"><span>۴</span><div><h2>گزارش بازیابی</h2><p id="restoreProgressText">در حال آماده‌سازی…</p></div></div>
                <div class="restore-progress-track"><i id="restoreProgressBar"></i></div>
                <div id="restoreLog" class="restore-log"></div>
            </section>

            <section class="restore-history downloads-panel">
                <div class="panel-head"><div><h2>عملیات‌های اخیر</h2><span id="restoreCount">۰ مورد</span></div></div>
                <div id="restoreTasks" class="restore-task-list"></div>
            </section>
        </div>
    </main>

    <footer class="statusbar"><span><i></i> پردازشگر فعال است</span><span id="footerSummary">۰ دانلود در لیست</span></footer>
</div>

<dialog id="deleteDialog">
    <form method="dialog"><h3>حذف دانلود؟</h3><p>فایل دانلودشده و اطلاعات این ردیف برای همیشه حذف می‌شود.</p><div><button value="cancel" class="ghost">انصراف</button><button id="confirmDelete" value="confirm" class="danger">حذف</button></div></form>
</dialog>
<dialog id="fileConflictDialog">
    <form method="dialog">
        <h3>فایل هم‌نام وجود دارد</h3>
        <p>فایل «<strong id="conflictFilename" dir="ltr"></strong>» از قبل در مسیر مقصد موجود است. چه کاری انجام شود؟</p>
        <div class="conflict-actions">
            <button value="cancel" class="ghost">لغو</button>
            <button value="rename" class="secondary">افزودن پسوند</button>
            <button value="overwrite" class="danger">جایگزینی</button>
        </div>
    </form>
</dialog>
<dialog id="directoryDialog" class="directory-dialog">
    <form method="dialog" class="directory-browser">
        <div class="directory-heading"><div><h3>انتخاب مسیر ذخیره</h3><p>پوشه موردنظر را از داخل public_html انتخاب کنید.</p></div><button id="directoryBack" class="action-btn" type="button" title="پوشه بالاتر">←</button></div>
        <div id="directoryCurrent" class="directory-current" dir="ltr">public_html</div>
        <div id="directoryList" class="directory-list" role="tree"></div>
        <div id="directoryError" class="form-error"></div>
        <div class="directory-actions"><button value="cancel" class="ghost">لغو</button><button id="selectDirectory" type="button" class="primary">انتخاب این پوشه</button></div>
    </form>
</dialog>
<div id="toast" class="toast" role="status"></div>
<script>window.BARGIR = <?= json_encode(['csrf' => $authenticated ? csrf_token() : '', 'isSetup' => $isSetup, 'maxMultipartConnections' => (int) ($config['max_multipart_connections'] ?? 6)], JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/app.js?v=12" defer></script>
</body>
</html>

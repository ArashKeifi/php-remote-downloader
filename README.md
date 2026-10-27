# PHP Remote Downloader (Bargir)

A self-hosted, resumable download manager and selective cPanel backup recovery tool for PHP shared hosting.

**فارسی:** بارگیر یک دانلود منیجر خودمیزبان برای PHP است که فایل‌های حجیم را مستقیماً از URL روی هاست ذخیره می‌کند و امکان بازیابی انتخابی بخش‌هایی از Full Backup سی‌پنل را بدون دسترسی root فراهم می‌کند.

> [!IMPORTANT]
> cPanel Full Account Restore is a WHM/root feature. Bargir performs a user-level, selective recovery and cannot restore server-level settings such as DNS, SSL, Apache/Exim configuration, or account ownership.

## Features

- Resumable and adaptive chunked downloads using HTTP Range
- Up to six parallel downloads and configurable multipart connections per job
- Live speed, progress, remaining time, and per-chunk progress details
- Pause, resume, retry, delete, and duplicate-file conflict handling
- Destination directory picker restricted to approved hosting-account paths
- Persistent queues that survive page refreshes and browser restarts
- Optional CLI worker for unattended Cron processing
- Secure administrator login, CSRF protection, safe filenames, and SSRF protection
- Selective inspection and recovery of cPanel Full Backup archives
- Recovery of `public_html`, Maildir messages, MySQL dumps, and email accounts
- No Composer, external database, or background service required
- Responsive Persian RTL interface

## Requirements

- PHP 8.1 or newer
- PHP extensions: `curl`, `phar`, and `pdo_mysql`
- `mbstring` is recommended
- Apache/cPanel hosting, or equivalent web-server rules that deny public access to `data`, `storage`, `src`, `config.php`, and `worker.php`
- Write permission for the `data` and `storage` directories

## Installation

1. Download the latest tagged release or clone the repository:

   ```bash
   git clone https://github.com/ArashKeifi/php-remote-downloader.git
   ```

2. Upload the project to a directory under `public_html`, for example `public_html/downloader`.
3. Ensure PHP can write to `data` and `storage` (commonly permission `750` or `770`, depending on the host).
4. Open the application URL and create the administrator password on first run.
5. Review [`config.php`](config.php) if you need to adjust timeouts, reserved disk space, allowed paths, or concurrency limits.

When updating an existing installation, replace the application source files but preserve the runtime contents of `data` and `storage`.

## Download behavior

The default maximum file size is unlimited (`max_file_size = 0`); the practical limits are disk quota, available space, network traffic, and PHP execution constraints.

Bargir starts with a 4 MiB chunk and dynamically adjusts future chunk sizes between 1 MiB and 16 MiB, targeting roughly eight seconds per chunk. Pause/resume and multipart downloading are enabled only when the source server correctly supports HTTP Range requests.

Custom destinations are restricted to the detected cPanel account home directory by default. Additional absolute roots must be explicitly added to `allowed_download_roots` in `config.php`.

## Unattended processing with Cron

Browser requests can process the queue while the dashboard is open. To continue downloads after closing the page, add a cPanel Cron Job:

```cron
*/2 * * * * /usr/bin/php /home/USER/public_html/downloader/worker.php 50 >/dev/null 2>&1
```

Replace the PHP binary and project path with the values used by your hosting account. Each run processes at most 50 work units.

## Selective cPanel backup recovery

The backup source can be:

- A completed download already managed by Bargir
- A direct URL, which is first added to the download queue
- A local `.tar.gz` file inside an approved account path

After inspecting the archive, select the sections to recover. Database imports require an existing destination database and user. Email-account creation requires a temporary cPanel API Token supplied during the recovery operation. Database passwords and cPanel tokens are not stored by the application.

### Recovery limitations

- Domains, addon domains, and subdomains must already exist on the destination account.
- Maildir messages can be copied and email accounts can be recreated through cPanel UAPI.
- Forwarders, DNS, SSL, Cron, and server-level Apache/Exim settings require WHM or hosting-provider assistance.
- SQL dumps containing complex procedures, triggers, or `DELIMITER` directives may require manual import.
- Files replaced during recovery are copied to `storage/pre-restore/RESTORE_ID`; keep this directory until the restored site is verified.

## Security

- Keep the application behind HTTPS.
- Never commit or publish the contents of `data` or `storage`.
- Private IP ranges, localhost, and unusual destination ports are blocked by default.
- On non-Apache servers, configure equivalent deny rules before exposing the application publicly.
- Remove cPanel API Tokens after a recovery if they are no longer needed.

Please report security issues privately to the repository owner instead of opening a public issue containing credentials or exploit details.

## Configuration

Important options in `config.php` include:

| Option | Default | Purpose |
| --- | ---: | --- |
| `max_file_size` | `0` | Maximum remote file size; `0` means unlimited |
| `min_free_space` | `512 MiB` | Disk space reserved for hosting operations |
| `chunk_size` | `4 MiB` | Initial/fallback chunk size |
| `min_chunk_size` | `1 MiB` | Minimum adaptive chunk size |
| `max_chunk_size` | `16 MiB` | Maximum adaptive chunk size |
| `target_chunk_seconds` | `8` | Target duration used for adaptive sizing |
| `max_concurrency` | `6` | Maximum simultaneous jobs |
| `max_multipart_connections` | `6` | Maximum connections for one supported source |
| `allow_private_networks` | `false` | Whether private-network URLs are allowed |

## Versioning

This project follows [Semantic Versioning](https://semver.org/). Changes are documented in [`CHANGELOG.md`](CHANGELOG.md), and release tags use the `vMAJOR.MINOR.PATCH` format.

## License

Released under the [MIT License](LICENSE).


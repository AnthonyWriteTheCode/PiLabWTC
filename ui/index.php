<?php
// WTC Pi Device & Web Management UI (Default landing page on Port 80 & Port 4485).
// Runs as user wtc-ui; all privileged actions execute via `sudo /usr/local/bin/wtc-manage`.
declare(strict_types=1);

const AUTH_FILE = '/etc/wtc-web/ui-auth';
const DROP_DIR = '/mnt/ramdisk/dropbox';
const STAGE_DIR = '/var/tmp/wtc-staging';
const SITES_DIR = '/opt/wtc-web/sites';
const MAX_UPLOAD = 10 * 1024 * 1024 * 1024; // 10GB (supports large multi-GB sites & 400k files)

@ini_set('memory_limit', '2048M');
@ini_set('max_execution_time', '3600');
@ini_set('max_input_time', '3600');

require_once __DIR__ . '/components/stacks.php';
require_once __DIR__ . '/components/db_helper.php';
require_once __DIR__ . '/components/cve_client.php';

session_name('wtcui');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Run wtc-manage with argument array (no shell). Returns [exitcode, stdout, stderr]. */
function wtc(array $args, ?string $stdin = null): array
{
    $cmd = array_merge(['/usr/bin/sudo', '-n', '/usr/local/bin/wtc-manage'], $args);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p))
        return [1, '', 'cannot start backend'];
    if ($stdin !== null)
        fwrite($pipes[0], $stdin . "\n");
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), (string) $out, trim((string) $err)];
}

function wj(string $view, bool $forceFresh = false): array
{
    static $cache = [];
    if (!$forceFresh && isset($cache[$view])) {
        return $cache[$view];
    }
    [$rc, $out] = wtc(['--json', $view]);
    $d = json_decode($out, true);
    $res = is_array($d) ? $d : [];
    $cache[$view] = $res;
    return $res;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'] = [$type, $msg];
}
function is_spa_request(): bool
{
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'spa')
        || (isset($_GET['spa']) && $_GET['spa'] === '1');
}
function go(string $page): never
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (is_spa_request()) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'page' => $page,
            'redirect' => str_starts_with($page, '/') ? $page : ('/?p=' . urlencode($page)),
            'flash' => $flash ? ['type' => $flash[0], 'msg' => $flash[1]] : null
        ]);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        exit;
    }
    header('Location: ' . (str_starts_with($page, '/') ? $page : ('/?p=' . urlencode($page))));
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}
function fmt_bytes(int|float $b): string
{
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($b < 1024 || $u === 'GB')
            return round($b, $u === 'B' ? 0 : 1) . ' ' . $u;
        $b /= 1024;
    }
    return '0 B';
}

function site_host(): string
{
    // Extract hostname from HTTP_HOST (stripping any port such as :4485)
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        $parsed = parse_url('http://' . $host, PHP_URL_HOST);
        if (!empty($parsed)) {
            return $parsed;
        }
        $parts = explode(':', $host);
        if (!empty($parts[0])) {
            return $parts[0];
        }
    }
    $sName = $_SERVER['SERVER_NAME'] ?? '';
    if ($sName !== '' && $sName !== '_') {
        return $sName;
    }
    $addr = $_SERVER['SERVER_ADDR'] ?? '';
    if ($addr !== '' && $addr !== '127.0.0.1' && $addr !== '::1') {
        return $addr;
    }
    return 'localhost';
}

function site_scheme(): string
{
    static $scheme = null;
    if ($scheme !== null) {
        return $scheme;
    }
    $set = wj('settings');
    $scheme = (($set['FORCE_HTTPS'] ?? 'no') === 'yes') ? 'https' : 'http';
    return $scheme;
}

function site_url(string $path = '/'): string
{
    $host = site_host();
    $path = '/' . ltrim($path, '/');
    return site_scheme() . '://' . $host . $path;
}

function site_port_url(int $port = 8080, string $path = '/'): string
{
    $host = site_host();
    $path = '/' . ltrim($path, '/');
    $scheme = site_scheme();
    if ($port === 8080 && $scheme === 'https') {
        return 'https://' . $host . $path;
    }
    return $scheme . '://' . $host . ':' . $port . $path;
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}
function valid_site(string $s): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $s);
}

/**
 * Safely extract an archive into a destination directory with strict ZipSlip and symlink traversal defense.
 */
function safe_php_extract(string $archiveFile, string $destDir, string $rawName): void
{
    if (!is_file($archiveFile)) {
        throw new RuntimeException("Uploaded archive file not found: $rawName");
    }
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0775, true);
    }

    if (preg_match('/\.zip$/i', $rawName)) {
        // Fast streaming extraction & ZipSlip pre-audit via system unzip (written in C, no PHP memory overhead)
        $unzipBin = is_executable('/usr/bin/unzip') ? '/usr/bin/unzip' : (is_executable('/bin/unzip') ? '/bin/unzip' : null);
        if ($unzipBin) {
            $chkCmd = $unzipBin . ' -Z1 ' . escapeshellarg($archiveFile) . ' 2>/dev/null | grep -E "(^|/)\.\.(/|$)|^\/|^\\\\" | head -n 1';
            $badPath = trim((string) exec($chkCmd, $chkOut, $chkRc));
            if ($badPath !== '') {
                throw new RuntimeException("Archive rejected: Malicious path traversal ('$badPath') detected in zip archive.");
            }
            exec($unzipBin . ' -qo ' . escapeshellarg($archiveFile) . ' -d ' . escapeshellarg($destDir), $uOut, $uRc);
            if ($uRc !== 0) {
                throw new RuntimeException('Failed to extract zip archive');
            }
        } else {
            $zip = new ZipArchive();
            if ($zip->open($archiveFile) !== true) {
                throw new RuntimeException('Failed to open zip archive');
            }
            // Pre-audit: Reject ZipSlip
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                if (str_contains($name, '../') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                    $zip->close();
                    throw new RuntimeException("Archive rejected: Malicious path traversal ('$name') detected in zip archive.");
                }
            }
            $zip->extractTo($destDir);
            $zip->close();
        }
    } else {
        // Tar archive: streaming ZipSlip check without buffering 400,000 lines into PHP memory
        $chkCmd = '/bin/tar -tf ' . escapeshellarg($archiveFile) . ' 2>/dev/null | grep -E "(^|/)\.\.(/|$)|^\/" | head -n 1';
        $badPath = trim((string) exec($chkCmd, $chkOut, $chkRc));
        if ($badPath !== '') {
            throw new RuntimeException("Archive rejected: Malicious path traversal ('$badPath') detected in tar archive.");
        }
        exec('/bin/tar -xf ' . escapeshellarg($archiveFile) . ' -C ' . escapeshellarg($destDir) . ' --no-same-owner --no-same-permissions', $tOut, $tRc);
        if ($tRc !== 0) {
            throw new RuntimeException('Failed to extract tar archive');
        }
    }

    // Post-audit: Remove any dangerous symlinks pointing outside destDir.
    // Uses native C find to locate symlinks only - prevents traversing 400,000 files in PHP userland.
    $realDest = realpath($destDir) ?: $destDir;
    if (is_dir($destDir)) {
        exec('/usr/bin/find ' . escapeshellarg($destDir) . ' -type l 2>/dev/null', $symlinks, $findRc);
        if ($findRc === 0 && !empty($symlinks)) {
            foreach ($symlinks as $linkPath) {
                $linkPath = trim((string) $linkPath);
                if ($linkPath === '') continue;
                $linkTarget = @readlink($linkPath);
                $resolved = @realpath($linkPath);
                if ($linkTarget !== false) {
                    if (str_starts_with($linkTarget, '/') || !$resolved || !str_starts_with($resolved, $realDest)) {
                        @unlink($linkPath);
                    }
                }
            }
        }
    }
}
function backend_msg(array $r, string $ok): void
{
    if ($r[0] === 0) {
        flash('ok', $ok);
        return;
    }
    $err = '';
    if (!empty($r[2])) {
        $lines = array_filter(array_map('trim', explode("\n", (string) $r[2])));
        // Look for line containing error keywords, searching from bottom up
        foreach (array_reverse($lines) as $l) {
            if (preg_match('/(error|emerg|fail|abort|cannot|fatal)/i', $l)) {
                $err = $l;
                break;
            }
        }
        if ($err === '' && !empty($lines)) {
            $err = end($lines);
        }
        $err = preg_replace('/^\S+ \S+ \[[^\]]+\] (?:ERROR:\s*)?/', '', $err);
    }
    flash('err', 'Failed: ' . ($err !== '' ? $err : 'see log'));
}

/**
 * Match a relative file path against glob/wildcard patterns.
 * Supports exact matches, prefix/directory wildcards (foo/**, foo/*, foo/), and fnmatch globs.
 */
function matches_protect_pattern(string $relPath, array $patterns): bool
{
    $relPath = ltrim(str_replace('\\', '/', $relPath), '/');
    foreach ($patterns as $pattern) {
        $pattern = trim(ltrim(str_replace('\\', '/', $pattern), '/'));
        if ($pattern === '')
            continue;
        if ($relPath === $pattern)
            return true;
        if (str_ends_with($pattern, '/**')) {
            $prefix = substr($pattern, 0, -3);
            if (str_starts_with($relPath, $prefix . '/') || $relPath === $prefix)
                return true;
        }
        if (str_ends_with($pattern, '/*')) {
            $prefix = substr($pattern, 0, -2);
            if (str_starts_with($relPath, $prefix . '/'))
                return true;
        }
        if (str_ends_with($pattern, '/')) {
            if (str_starts_with($relPath, $pattern))
                return true;
        }
        if (fnmatch($pattern, $relPath, FNM_PATHNAME | FNM_PERIOD) || fnmatch($pattern, basename($relPath), FNM_PERIOD)) {
            return true;
        }
    }
    return false;
}

/**
 * Parse .gitignore or .wtcignore lines into an array of clean patterns.
 */
function parse_ignore_patterns(string $filePath): array
{
    if (!is_file($filePath))
        return [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $patterns = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!'))
            continue;
        $patterns[] = ltrim($line, '/');
    }
    return $patterns;
}

/**
 * Seed $stageDir with the existing site's files (from live RAM or stored .tar.gz)
 * so incremental uploads can safely overlay only recently modified files.
 */
function seed_stage_from_existing(string $siteName, string $stageDir): bool
{
    $slug = strtolower((string) preg_replace('/[^a-z0-9_-]/', '', $siteName));
    $liveRamDir = '/mnt/ramdisk/sites/' . $slug;
    $existingArchive = SITES_DIR . '/' . $siteName . '.tar.gz';
    $existingSlugArchive = SITES_DIR . '/' . $slug . '.tar.gz';
    $archivePath = is_file($existingArchive) ? $existingArchive : (is_file($existingSlugArchive) ? $existingSlugArchive : null);

    if (!is_dir($liveRamDir) && $archivePath === null) {
        return false;
    }

    if (is_dir($stageDir)) {
        exec('/bin/rm -rf ' . escapeshellarg($stageDir));
    }
    @mkdir($stageDir, 0775, true);

    if (is_dir($liveRamDir)) {
        exec('/bin/cp -a ' . escapeshellarg($liveRamDir) . '/. ' . escapeshellarg($stageDir) . '/');
        return true;
    }

    if ($archivePath !== null) {
        safe_php_extract($archivePath, $stageDir, basename($archivePath));
        return true;
    }

    return false;
}

/**
 * Protect and preserve existing working site configurations (.env, database credentials,
 * SQLite databases, database backups, media uploads, and user-specified protected patterns)
 * so that re-uploading an archive or dropping project files does not overwrite them.
 */
function apply_site_protection(string $siteName, string $stageDir, array $userPatterns = [], string $envContent = '', bool $overlayOnly = false): array
{
    $slug = strtolower((string) preg_replace('/[^a-z0-9_-]/', '', $siteName));
    $liveRamDir = '/mnt/ramdisk/sites/' . $slug;
    $existingArchive = SITES_DIR . '/' . $siteName . '.tar.gz';
    $existingSlugArchive = SITES_DIR . '/' . $slug . '.tar.gz';

    $sourceDir = null;
    $tempInspectDir = null;

    if (is_dir($liveRamDir)) {
        $sourceDir = $liveRamDir;
    } elseif (!$overlayOnly && (is_file($existingArchive) || is_file($existingSlugArchive))) {
        $archivePath = is_file($existingArchive) ? $existingArchive : $existingSlugArchive;
        $tempInspectDir = STAGE_DIR . '/.inspect-' . $slug . '-' . bin2hex(random_bytes(4));
        try {
            safe_php_extract($archivePath, $tempInspectDir, basename($archivePath));
            if (is_dir($tempInspectDir)) {
                $sourceDir = $tempInspectDir;
            }
        } catch (Throwable $e) {
            // Ignore extraction errors during protection check
        }
    }

    // Default core patterns protected across all stacks
    $allPatterns = [
        '.env',
        '.env.*',
        '.wtc-database.sql',
        '.wtc-db.sql',
        '.wtc-db.php',
        'nginx.conf',
        'wtc.json',
        'wp-config.php',
        'database/*.sqlite',
        '*.sqlite',
        '*.db',
        'storage/app/**',
    ];

    // Merge user-specified patterns
    foreach ($userPatterns as $up) {
        $up = trim((string) $up);
        if ($up !== '' && !in_array($up, $allPatterns, true)) {
            $allPatterns[] = $up;
        }
    }

    // Inspect .gitignore and .wtcignore if present in stageDir or existing site
    $ignoreFiles = [
        $stageDir . '/.gitignore',
        $stageDir . '/.wtcignore',
    ];
    if ($sourceDir) {
        $ignoreFiles[] = $sourceDir . '/.gitignore';
        $ignoreFiles[] = $sourceDir . '/.wtcignore';
    }
    foreach ($ignoreFiles as $ig) {
        if (is_file($ig)) {
            foreach (parse_ignore_patterns($ig) as $ip) {
                if (preg_match('/(\.env|\.sql|\.sqlite|\.db|storage|config|secret|key|upload)/i', $ip)) {
                    if (!in_array($ip, $allPatterns, true)) {
                        $allPatterns[] = $ip;
                    }
                }
            }
        }
    }

    // Save .wtc-protected.json into stageDir so bash backend also adheres to it
    @file_put_contents($stageDir . '/.wtc-protected.json', json_encode(array_values(array_unique($allPatterns)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $restoredFiles = [];

    if ($overlayOnly) {
        if ($envContent !== '') {
            file_put_contents($stageDir . '/.env', $envContent);
        } elseif ($sourceDir && is_dir($sourceDir)) {
            $liveEnvPath = $sourceDir . '/.env';
            if (is_file($liveEnvPath)) {
                if (!is_file($stageDir . '/.env')) {
                    $restoredFiles[] = '.env (Preserved in RAM)';
                } else {
                    $liveEnv = (string) file_get_contents($liveEnvPath);
                    $stagedEnv = (string) file_get_contents($stageDir . '/.env');
                    $merged = $liveEnv;
                    $newKeysAppended = 0;
                    foreach (explode("\n", $stagedEnv) as $sLine) {
                        $trimmed = trim($sLine);
                        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '='))
                            continue;
                        $k = trim(explode('=', $trimmed, 2)[0]);
                        if (!preg_match('/^[A-Za-z0-9_]+$/', $k))
                            continue;
                        if (!preg_match('/^' . preg_quote($k, '/') . '=/m', $liveEnv)) {
                            $merged .= "\n" . $trimmed;
                            $newKeysAppended++;
                        }
                    }
                    file_put_contents($stageDir . '/.env', $merged);
                    $restoredFiles[] = '.env (Server credentials protected' . ($newKeysAppended > 0 ? ", +$newKeysAppended new keys appended" : '') . ')';
                }
            }

            // Remove any protected files from stageDir that already exist in live RAM so they are never overwritten
            try {
                $iter = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($stageDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iter as $item) {
                    if ($item->isFile()) {
                        $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($stageDir))), '/');
                        if ($rel === '.env' || $rel === '.wtc-protected.json')
                            continue;
                        if (matches_protect_pattern($rel, $allPatterns)) {
                            $liveMatch = $sourceDir . '/' . $rel;
                            if (is_file($liveMatch) && filesize($liveMatch) > 0) {
                                @unlink($item->getPathname());
                                if (!in_array($rel, $restoredFiles, true)) {
                                    $restoredFiles[] = $rel . ' (Preserved in RAM)';
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // directory iterator safety
            }
        }
        return [
            'patterns' => $allPatterns,
            'restored' => $restoredFiles,
        ];
    }

    // Full staging mode (non-overlay): copy protected files into stageDir
    if ($sourceDir && is_dir($sourceDir)) {
        // 1. Preserve & Merge .env
        $liveEnvPath = $sourceDir . '/.env';
        if ($envContent === '') {
            if (is_file($liveEnvPath)) {
                $liveEnv = (string) file_get_contents($liveEnvPath);
                if (!is_file($stageDir . '/.env')) {
                    // Upload had no .env -> preserve existing working server .env completely
                    file_put_contents($stageDir . '/.env', $liveEnv);
                    $restoredFiles[] = '.env (Restored from server)';
                } else {
                    // Upload had its own .env -> Server .env is authoritative!
                    // Retain server's working keys (database credentials, APP_KEY, etc.), only appending genuinely new keys
                    $stagedEnv = (string) file_get_contents($stageDir . '/.env');
                    $merged = $liveEnv;
                    $stagedLines = explode("\n", $stagedEnv);
                    $newKeysAppended = 0;
                    foreach ($stagedLines as $sLine) {
                        $trimmed = trim($sLine);
                        if ($trimmed === '' || str_starts_with($trimmed, '#'))
                            continue;
                        if (!str_contains($trimmed, '='))
                            continue;
                        $k = trim(explode('=', $trimmed, 2)[0]);
                        if (!preg_match('/^[A-Za-z0-9_]+$/', $k))
                            continue;
                        if (!preg_match('/^' . preg_quote($k, '/') . '=/m', $liveEnv)) {
                            $merged .= "\n" . $trimmed;
                            $newKeysAppended++;
                        }
                    }
                    file_put_contents($stageDir . '/.env', $merged);
                    $restoredFiles[] = '.env (Server credentials protected' . ($newKeysAppended > 0 ? ", +$newKeysAppended new keys appended" : '') . ')';
                }
            }
        } else {
            file_put_contents($stageDir . '/.env', $envContent);
        }

        // 2. Preserve .wtc-db.php
        if (is_file($sourceDir . '/.wtc-db.php') && !is_file($stageDir . '/.wtc-db.php')) {
            @copy($sourceDir . '/.wtc-db.php', $stageDir . '/.wtc-db.php');
            $restoredFiles[] = '.wtc-db.php';
        }

        // 3. Preserve database snapshot dump (.wtc-database.sql / .wtc-db.sql)
        if (is_file($sourceDir . '/.wtc-database.sql') && !is_file($stageDir . '/.wtc-database.sql')) {
            @copy($sourceDir . '/.wtc-database.sql', $stageDir . '/.wtc-database.sql');
            $restoredFiles[] = '.wtc-database.sql';
        } elseif (is_file($sourceDir . '/.wtc-db.sql') && !is_file($stageDir . '/.wtc-db.sql')) {
            @copy($sourceDir . '/.wtc-db.sql', $stageDir . '/.wtc-db.sql');
            $restoredFiles[] = '.wtc-db.sql';
        }

        // 4. Preserve SQLite databases
        $sqliteGlobs = ['database/database.sqlite', 'database/*.sqlite', 'storage/*.sqlite', '*.sqlite', '*.db'];
        foreach ($sqliteGlobs as $sg) {
            $matched = glob($sourceDir . '/' . $sg) ?: [];
            foreach ($matched as $mPath) {
                $rel = ltrim(substr($mPath, strlen($sourceDir)), '/');
                $target = $stageDir . '/' . $rel;
                if (!is_file($target) || filesize($target) === 0) {
                    @mkdir(dirname($target), 0775, true);
                    @copy($mPath, $target);
                    $restoredFiles[] = $rel;
                }
            }
        }

        // 5. Preserve user uploads & persisted media (storage/app)
        if (is_dir($sourceDir . '/storage/app')) {
            @mkdir($stageDir . '/storage/app', 0775, true);
            exec('/bin/cp -rn ' . escapeshellarg($sourceDir . '/storage/app') . '/* ' . escapeshellarg($stageDir . '/storage/app') . '/ 2>/dev/null');
            $restoredFiles[] = 'storage/app/**';
        }

        // 6. Check all remaining custom/protected patterns against existing files
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $item) {
                if ($item->isFile()) {
                    $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($sourceDir))), '/');
                    if (matches_protect_pattern($rel, $allPatterns)) {
                        $target = $stageDir . '/' . $rel;
                        if (!is_file($target) || filesize($target) === 0) {
                            @mkdir(dirname($target), 0775, true);
                            @copy($item->getPathname(), $target);
                            if (!in_array($rel, $restoredFiles, true)) {
                                $restoredFiles[] = $rel;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // directory iterator safety
        }
    }

    if ($tempInspectDir && is_dir($tempInspectDir)) {
        exec('/bin/rm -rf ' . escapeshellarg($tempInspectDir));
    }

    return [
        'patterns' => $allPatterns,
        'restored' => $restoredFiles,
    ];
}

/* ------------------------------------------------------------ actions (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) {
        if (!empty($_POST['is_ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bad CSRF token']);
            exit;
        }
        http_response_code(400);
        exit('Bad CSRF token');
    }
    $a = (string) ($_POST['action'] ?? '');
    $site = (string) ($_POST['site'] ?? '');
    $back = (string) ($_POST['back'] ?? 'dashboard');

    switch ($a) {
        case 'activate':
            $isPrim = !empty($_POST['primary']) ? '1' : '0';
            // Default to primary if no other active sites or requested
            if (isset($_POST['primary'])) {
                valid_site($site) ? backend_msg(wtc(['--activate', $site, $isPrim]), "Activated '$site' in RAM" . ($isPrim === '1' ? ' (Primary)' : '')) : flash('err', 'Bad site name');
            } else {
                valid_site($site) ? backend_msg(wtc(['--activate', $site, '1']), "Activated '$site' in RAM") : flash('err', 'Bad site name');
            }
            go($back);
        case 'deactivate':
            valid_site($site) ? backend_msg(wtc(['--deactivate', $site]), "Deactivated '$site' from RAM") : flash('err', 'Bad site name');
            go($back);
        case 'set_primary':
            valid_site($site) ? backend_msg(wtc(['--set', $site]), "Primary active site set to '$site'") : flash('err', 'Bad site name');
            go($back);
        case 'build':
            $cmd = trim((string) ($_POST['cmd'] ?? ''));
            if ($cmd !== '') {
                valid_site($site) ? backend_msg(wtc(['--build', $site, $cmd]), "Build job started: $cmd") : flash('err', 'Bad site name');
            } else {
                valid_site($site) ? backend_msg(wtc(['--build', $site]), "Background build job started for '$site'") : flash('err', 'Bad site name');
            }
            go($back);
        case 'site_rename':
            $newSite = strtolower(trim((string) ($_POST['new_name'] ?? '')));
            if (!valid_site($site)) {
                flash('err', 'Invalid existing site name');
            } elseif (!valid_site($newSite)) {
                flash('err', 'New site name must contain only lowercase letters, numbers, dash and underscore');
            } else {
                backend_msg(wtc(['--site-rename', $site, $newSite]), "Renamed site '$site' to '$newSite'");
            }
            go($back);
        case 'site_clone':
        case 'site_duplicate':
            $newSite = strtolower(trim((string) ($_POST['new_name'] ?? '')));
            $port = trim((string) ($_POST['port'] ?? ''));
            $dbMode = trim((string) ($_POST['db_mode'] ?? 'clone'));
            $activate = !empty($_POST['activate']) ? '1' : '0';

            if (!valid_site($site)) {
                flash('err', 'Invalid source site name');
            } elseif (!valid_site($newSite)) {
                flash('err', 'Instance name must contain only lowercase letters, numbers, dash and underscore');
            } elseif ($site === $newSite) {
                flash('err', 'Target instance name must be different from source system');
            } else {
                backend_msg(wtc(['--site-clone', $site, $newSite, $port, $dbMode, $activate]), "Duplicated '$site' into new instance '$newSite'" . ($port !== '' ? " on dedicated port $port" : ""));
            }
            go($back);
        case 'site_stack':
            $stack = strtolower(trim((string) ($_POST['stack'] ?? '')));
            if (!valid_site($site)) {
                flash('err', 'Invalid site name');
            } elseif ($stack === '') {
                flash('err', 'Please select a valid stack');
            } else {
                backend_msg(wtc(['--site-stack', $site, $stack]), "Assigned stack '$stack' to '$site'");
            }
            go($back);
        case 'site_port':
            $port = trim((string) ($_POST['port'] ?? ''));
            if (!valid_site($site)) {
                flash('err', 'Invalid site name');
            } else {
                backend_msg(wtc(['--site-port', $site, $port]), $port !== '' ? "Dedicated port $port configured for '$site'" : "Dedicated port removed for '$site'");
            }
            go($back);
        case 'storage_link':
            $targetSite = trim((string) ($_POST['site'] ?? ''));
            if ($targetSite === '') {
                $statusData = wj('status');
                $targetSite = (string) ($statusData['active'] ?? '');
            }
            if (!valid_site($targetSite)) {
                flash('err', 'Invalid site name');
            } else {
                backend_msg(wtc(['--storage-link', $targetSite]), "Storage link verified and ensured for '$targetSite'");
            }
            go($back);
        case 'rollback':
            $ver = trim((string) ($_POST['version'] ?? ''));
            if (!valid_site($site)) {
                flash('err', 'Bad site name');
            } elseif ($ver !== '' && $ver !== 'legacy' && !preg_match('/^[a-zA-Z0-9._-]+\.tar\.gz$/', $ver)) {
                flash('err', 'Invalid rollback version');
            } else {
                $args = $ver !== '' ? ['--rollback', $site, $ver] : ['--rollback', $site];
                backend_msg(wtc($args), "Rolled back '$site'" . ($ver !== '' ? " to $ver" : ''));
            }
            go($back);
        case 'rollback_delete':
            $ver = trim((string) ($_POST['version'] ?? ''));
            if (!valid_site($site) || ($ver !== 'legacy' && !preg_match('/^[a-zA-Z0-9._-]+\.tar\.gz$/', $ver))) {
                flash('err', 'Invalid site or rollback version');
            } else {
                backend_msg(wtc(['--rollback-delete', $site, $ver]), "Deleted rollback '$ver' for '$site'");
            }
            go($back);
        case 'rollback_clear':
            if ($site === '__ALL__') {
                backend_msg(wtc(['--rollback-clear', '--all']), 'Cleared all rollback snapshots');
            } elseif (valid_site($site)) {
                backend_msg(wtc(['--rollback-clear', $site]), "Cleared rollback snapshots for '$site'");
            } else {
                flash('err', 'Bad site name');
            }
            go($back);
        case 'delete':
            valid_site($site) ? backend_msg(wtc(['--delete', $site]), "Deleted '$site'") : flash('err', 'Bad site name');
            go($back);
        case 'create':
            valid_site($site) ? backend_msg(wtc(['--create', $site]), "Created skeleton '$site'") : flash('err', 'Site name must be lowercase letters, numbers, dash');
            go($back);
        case 'ingest':
            backend_msg(wtc(['--ingest']), 'Dropbox archives processed');
            go($back);
        case 'save_state':
            backend_msg(wtc(['--save-state']), 'Live RAM site & database state snapshot saved to SD storage');
            go($back);
        case 'device':
            $do = (string) ($_POST['do'] ?? '');
            backend_msg(wtc(['--device', $do]), "Device command '$do' sent");
            go('dashboard');
        case 'reload_ui':
            backend_msg(wtc(['--reload-ui']), 'Management UI successfully reloaded from SD storage into RAM drive!');
            go($back);
        case 'rescan_sites':
            backend_msg(wtc(['--rescan-sites']), 'Deep scan of site archives completed; metadata refreshed.');
            go($back);
        case 'rescan_stack':
            backend_msg(wtc(['--rescan-stack']), 'Installed package status scanned and cached.');
            go($back);
        case 'workers':
            $do = (string) ($_POST['do'] ?? 'restart');
            if (in_array($do, ['start', 'stop', 'restart'], true)) {
                backend_msg(wtc(['--workers', $do]), "Worker action '$do' executed");
            } else {
                flash('err', 'Invalid worker action');
            }
            go($back);
        case 'upload':
            $f = $_FILES['archive'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                flash('err', 'Upload failed (code ' . ($f['error'] ?? '?') . ')');
                go($back);
            }
            $name = basename((string) $f['name']);
            if (!preg_match('/^[A-Za-z0-9._-]+\.(tar\.gz|tgz|tar|zip)$/', $name) || $f['size'] > MAX_UPLOAD) {
                flash('err', 'Use a .tar.gz / .tgz / .tar / .zip archive under 10GB');
                go($back);
            }
            $siteName = preg_replace('/(\.tar\.gz|\.tgz|\.tar|\.zip)$/i', '', $name);
            $siteName = strtolower((string) preg_replace('/[^a-z0-9_-]/', '-', $siteName));
            $siteName = trim($siteName, '-');
            if (!valid_site($siteName))
                $siteName = 'site-' . date('Ymd-His');
            if (!is_dir(STAGE_DIR))
                @mkdir(STAGE_DIR, 0775, true);
            $dest = STAGE_DIR . '/' . $name;
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                flash('err', 'Could not save archive into staging area');
                go($back);
            }
            if (!empty($_POST['protected_patterns'])) {
                $up = is_array($_POST['protected_patterns']) ? $_POST['protected_patterns'] : json_decode((string) $_POST['protected_patterns'], true);
                if (is_array($up)) {
                    @file_put_contents(STAGE_DIR . '/.wtc-protected.json', json_encode(array_values(array_unique($up)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
            [$rc, $out, $err] = wtc(['--store-site', $siteName, $dest]);
            if ($rc === 0) {
                flash('ok', "Uploaded '$siteName' and saved package to sites repository");
            } else {
                flash('err', "Packaging failed: " . ($err !== '' ? $err : $out));
            }
            go($back);
        case 'setting':
            $k = (string) ($_POST['key'] ?? '');
            $v = (string) ($_POST['value'] ?? '');
            backend_msg(wtc(['--setting', $k, $v]), "$k updated");
            go($back);
        case 'fan_test':
            $duration = (string) ($_POST['duration'] ?? '5');
            backend_msg(wtc(['--fan', 'test', $duration]), "Tested fan alert for {$duration}s");
            go('settings');
        case 'transfer':
            $w = (string) ($_POST['what'] ?? '');
            $v = (string) ($_POST['value'] ?? '');
            backend_msg(wtc(['--transfer', $w, $v]), "$w set to $v");
            go('transfer');
        case 'samba_password':
            $pw = (string) ($_POST['password'] ?? '');
            backend_msg(wtc(['--transfer', 'samba-password'], $pw), 'Samba password updated');
            go('transfer');
        case 'ftp_password':
            $pw = (string) ($_POST['password'] ?? '');
            backend_msg(wtc(['--transfer', 'ftp-password'], $pw), 'FTP password updated');
            go('transfer');
        case 'drop_upload':
            $isAjax = !empty($_POST['is_ajax']);
            $spinUp = !empty($_POST['spin_up']);
            $isIncremental = !empty($_POST['incremental_upload']);
            $recentMinutes = max(0, (int) ($_POST['recent_minutes'] ?? 0));
            $uploadedFileCount = max(0, (int) ($_POST['uploaded_file_count'] ?? 0));
            $siteCustom = trim((string) ($_POST['site'] ?? ''));
            $detectedStack = (string) ($_POST['detected_stack'] ?? '');
            $envContent = (string) ($_POST['env_content'] ?? '');
            $deployMode = (string) ($_POST['deploy_mode'] ?? 'auto');
            $userProtectedPatterns = [];
            if (!empty($_POST['protected_patterns'])) {
                if (is_array($_POST['protected_patterns'])) {
                    $userProtectedPatterns = $_POST['protected_patterns'];
                } else {
                    $decoded = json_decode((string) $_POST['protected_patterns'], true);
                    $userProtectedPatterns = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode("\n", (string) $_POST['protected_patterns'])));
                }
            }

            $stageDir = '';
            $destArchive = '';
            try {
                $f = $_FILES['archive'] ?? null;
                $files = $_FILES['files'] ?? null;
                $paths = $_POST['paths'] ?? [];

                $siteName = '';
                $hasIndex = false;
                $uploadType = '';
                $rawName = '';
                $detectedPrefix = null;
                $uploadBytes = 0;

                if ($f && !empty($f['name']) && $f['error'] === UPLOAD_ERR_OK) {
                    $uploadType = 'archive';
                    $rawName = basename((string) $f['name']);
                    if (!preg_match('/^[A-Za-z0-9._-]+\.(tar\.gz|tgz|tar|zip)$/i', $rawName) || $f['size'] > MAX_UPLOAD) {
                        throw new RuntimeException('Archive must be .tar.gz, .tgz, .tar, or .zip under 10GB');
                    }
                    $uploadBytes = (int) ($f['size'] ?? 0);
                    $siteName = $siteCustom !== '' ? $siteCustom : preg_replace('/(\.tar\.gz|\.tgz|\.tar|\.zip)$/i', '', $rawName);
                } elseif ($files && !empty($files['name']) && is_array($files['name']) && count($files['name']) > 0) {
                    $uploadType = 'files';
                    $firstRel = !empty($paths[0]) ? (string) $paths[0] : '';
                    $firstParts = explode('/', str_replace('\\', '/', $firstRel));
                    if (count($firstParts) > 1) {
                        $candidate = $firstParts[0];
                        $allMatch = true;
                        foreach ($paths as $p) {
                            $norm = str_replace('\\', '/', (string) $p);
                            if (!str_starts_with($norm, $candidate . '/')) {
                                $allMatch = false;
                                break;
                            }
                        }
                        if ($allMatch)
                            $detectedPrefix = $candidate;
                    }
                    foreach (($files['size'] ?? []) as $sz) {
                        $uploadBytes += (int) $sz;
                    }
                    $siteName = $siteCustom !== '' ? $siteCustom : ($detectedPrefix ?: 'site-' . date('Ymd-His'));
                } else {
                    throw new RuntimeException('No files or folder detected in drop');
                }

                $siteName = strtolower((string) preg_replace('/[^a-z0-9_-]/', '-', $siteName));
                $siteName = trim($siteName, '-');
                if (!valid_site($siteName))
                    $siteName = 'site-' . date('Ymd-His');

                $siteSlug = strtolower((string) preg_replace('/[^a-z0-9_-]/', '', $siteName));
                $liveRamDir = '/mnt/ramdisk/sites/' . $siteSlug;
                $existsInRam = is_dir($liveRamDir);
                $existsOnSd = is_file(SITES_DIR . '/' . $siteName . '.tar.gz');
                $isUpdate = $existsOnSd || $existsInRam;

                $statusData = wj('status');
                $curPrimary = (string) ($statusData['active'] ?? '');
                $isCurrentlyPrimary = ($curPrimary === $siteName || $curPrimary === '');
                $isCurrentlyActive = $isCurrentlyPrimary || $existsInRam;
                if (!$isCurrentlyActive) {
                    $allSites = wj('sites');
                    foreach ($allSites as $s) {
                        if (($s['name'] ?? '') === $siteName && !empty($s['active'])) {
                            $isCurrentlyActive = true;
                            break;
                        }
                    }
                }
                $shouldDeployLive = $spinUp || $isCurrentlyActive;
                $makePrimary = ($isCurrentlyPrimary || !empty($_POST['primary'])) ? '1' : '0';
                $directOverlayMode = $shouldDeployLive && ($existsInRam || ($isIncremental && $existsOnSd));

                // Stage directly in RAM tmpfs when free RAM allows so reuploads skip SD staging I/O
                $ramTmpBase = '/mnt/ramdisk/php-tmp';
                $ramFree = @disk_free_space('/mnt/ramdisk');
                $useRamStage = $shouldDeployLive && is_dir($ramTmpBase) && is_writable($ramTmpBase)
                    && $ramFree !== false && $ramFree > ($uploadBytes * 3 + 64 * 1024 * 1024);

                $stageBase = $useRamStage ? $ramTmpBase : STAGE_DIR;
                if (!is_dir($stageBase))
                    @mkdir($stageBase, 0775, true);

                $stageDir = $shouldDeployLive
                    ? ($stageBase . '/.wtc-up-' . $siteSlug . '-' . bin2hex(random_bytes(4)))
                    : (STAGE_DIR . '/' . $siteName);

                $seededExisting = (!$shouldDeployLive && $isIncremental) && seed_stage_from_existing($siteName, $stageDir);
                if (!is_dir($stageDir))
                    @mkdir($stageDir, 0775, true);

                if ($uploadType === 'archive') {
                    $destArchive = $stageBase . '/.arc-' . $siteSlug . '-' . bin2hex(random_bytes(4)) . '-' . $rawName;
                    if (!move_uploaded_file($f['tmp_name'], $destArchive)) {
                        throw new RuntimeException('Could not save uploaded archive into staging area');
                    }

                    if ($seededExisting) {
                        $patchDir = $stageBase . '/.patch-' . $siteName . '-' . bin2hex(random_bytes(4));
                        @mkdir($patchDir, 0775, true);
                        safe_php_extract($destArchive, $patchDir, $rawName);
                        @unlink($destArchive);
                        if (is_dir($patchDir . '/__MACOSX')) {
                            exec('/bin/rm -rf ' . escapeshellarg($patchDir . '/__MACOSX'));
                        }
                        exec('/bin/cp -a ' . escapeshellarg($patchDir) . '/. ' . escapeshellarg($stageDir) . '/');
                        exec('/bin/rm -rf ' . escapeshellarg($patchDir));
                        $hasIndex = is_file($stageDir . '/index.php') || is_file($stageDir . '/index.html') || is_file($stageDir . '/index.htm') || is_file($stageDir . '/public/index.php');
                    } else {
                        safe_php_extract($destArchive, $stageDir, $rawName);
                        @unlink($destArchive);

                        if (is_dir($stageDir . '/__MACOSX')) {
                            exec('/bin/rm -rf ' . escapeshellarg($stageDir . '/__MACOSX'));
                        }

                        // Unwrap single project wrapper directory for full archives (never unwrap standard app subdirs or incremental patches)
                        if (!$isIncremental) {
                            $rawEntries = array_diff(scandir($stageDir) ?: [], ['.', '..']);
                            $meaningfulEntries = array_filter($rawEntries, fn($e) => !in_array($e, ['__MACOSX', '.DS_Store', 'Thumbs.db', 'desktop.ini']));
                            $hasRootEntry = is_file($stageDir . '/index.php') || is_file($stageDir . '/index.html') || is_file($stageDir . '/index.htm') || is_file($stageDir . '/artisan');

                            if (!$hasRootEntry && count($meaningfulEntries) === 1) {
                                $singleName = (string) reset($meaningfulEntries);
                                $singleChild = $stageDir . '/' . $singleName;
                                $appSubdirs = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'storage', 'tests', 'vendor', 'node_modules', 'src', 'dist', 'build', 'out', 'assets', 'css', 'js', 'views', 'wp-content', 'wp-includes', 'wp-admin'];
                                $isAppSubdir = in_array(strtolower($singleName), $appSubdirs, true);
                                $childHasRoot = is_file($singleChild . '/artisan') || is_file($singleChild . '/public/index.php') || is_file($singleChild . '/index.php') || is_file($singleChild . '/index.html') || is_file($singleChild . '/wp-config.php');

                                if (is_dir($singleChild) && (!$isAppSubdir || $childHasRoot)) {
                                    foreach (array_diff(scandir($singleChild) ?: [], ['.', '..']) as $item) {
                                        rename($singleChild . '/' . $item, $stageDir . '/' . $item);
                                    }
                                    @rmdir($singleChild);
                                }
                            }
                        }

                        $hasIndex = is_file($stageDir . '/index.php') || is_file($stageDir . '/index.html') || is_file($stageDir . '/index.htm') || is_file($stageDir . '/public/index.php');
                    }
                } else {
                    $fileCount = count($files['name']);
                    for ($i = 0; $i < $fileCount; $i++) {
                        if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK)
                            continue;
                        $relPath = !empty($paths[$i]) ? (string) $paths[$i] : (string) $files['name'][$i];
                        $relParts = explode('/', str_replace('\\', '/', $relPath));
                        if ($detectedPrefix && count($relParts) > 1 && $relParts[0] === $detectedPrefix) {
                            array_shift($relParts);
                        }
                        $cleanParts = array_filter($relParts, fn($p) => $p !== '' && $p !== '.' && $p !== '..');
                        $cleanRel = implode('/', $cleanParts);
                        if ($cleanRel === '')
                            continue;

                        if (preg_match('/^index\.(html|htm|php)$/i', $cleanRel)) {
                            $hasIndex = true;
                        }

                        $targetPath = $stageDir . '/' . $cleanRel;
                        $targetDir = dirname($targetPath);
                        if (!is_dir($targetDir))
                            @mkdir($targetDir, 0775, true);
                        @move_uploaded_file($files['tmp_name'][$i], $targetPath);
                    }
                    if ($seededExisting || $directOverlayMode) {
                        $hasIndex = $hasIndex || is_file($stageDir . '/index.php') || is_file($stageDir . '/index.html') || is_file($stageDir . '/index.htm') || is_file($stageDir . '/public/index.php');
                    }
                }

                // Apply comprehensive site protection: preserve .env (authoritative DB creds & APP_KEY),
                // database seeders/dumps, SQLite DBs, storage/app, and user/gitignore protected patterns
                $protResult = apply_site_protection($siteName, $stageDir, $userProtectedPatterns, $envContent, $directOverlayMode);

                // Only scaffold brand-new site entrypoints/shims when NOT overlaying onto an existing site
                if (!$directOverlayMode) {
                    if ($detectedStack === 'laravel' || is_file($stageDir . '/public/index.php')) {
                        if (!is_file($stageDir . '/index.php')) {
                            file_put_contents($stageDir . '/index.php', "<?php\n// Forward to Laravel public/ webroot\nrequire_once __DIR__ . '/public/index.php';\n");
                            $hasIndex = true;
                        }
                        if ($envContent === '' && !is_file($stageDir . '/.env')) {
                            $ip = explode(' ', $statusData['ips'] ?? '')[0] ?? '127.0.0.1';
                            file_put_contents($stageDir . '/.env', generate_laravel_env($siteName, $ip));
                        }
                        if ($deployMode === 'auto_shim' || !is_file($stageDir . '/vendor/autoload.php')) {
                            generate_laravel_shim($stageDir, $siteName);
                        }

                        @mkdir($stageDir . '/storage/app/public', 0775, true);
                        @mkdir($stageDir . '/storage/framework/views', 0775, true);
                        @mkdir($stageDir . '/storage/framework/sessions', 0775, true);
                        @mkdir($stageDir . '/storage/framework/cache/data', 0775, true);
                        @mkdir($stageDir . '/storage/logs', 0775, true);
                        @mkdir($stageDir . '/bootstrap/cache', 0775, true);

                        if (is_dir($stageDir . '/public')) {
                            $pubStorage = $stageDir . '/public/storage';
                            if (is_link($pubStorage)) {
                                $target = @readlink($pubStorage);
                                if (!file_exists($pubStorage) || str_contains((string)$target, '.new.') || str_contains((string)$target, '/tmp/') || str_starts_with((string)$target, '/')) {
                                    @unlink($pubStorage);
                                }
                            } elseif (is_dir($pubStorage)) {
                                $pubFiles = @scandir($pubStorage) ?: [];
                                foreach ($pubFiles as $pf) {
                                    if ($pf === '.' || $pf === '..') continue;
                                    @rename($pubStorage . '/' . $pf, $stageDir . '/storage/app/public/' . $pf);
                                }
                                @rmdir($pubStorage);
                            }
                            if (!file_exists($pubStorage) && !is_link($pubStorage)) {
                                @symlink('../storage/app/public', $pubStorage);
                            }
                        }
                    }

                    if (
                        $detectedStack === 'react' ||
                        $detectedStack === 'static' ||
                        is_dir($stageDir . '/dist') ||
                        is_dir($stageDir . '/build') ||
                        is_dir($stageDir . '/out') ||
                        is_file($stageDir . '/vite.config.js') ||
                        is_file($stageDir . '/vite.config.ts') ||
                        is_file($stageDir . '/vite.config.mjs')
                    ) {
                        setup_react_spa($stageDir, $siteName);
                        $hasIndex = true;
                    }

                    if (!$hasIndex) {
                        file_put_contents($stageDir . '/index.html', "<!doctype html><html><head><meta charset=\"utf-8\"><title>" . h($siteName) . "</title></head><body><h1>" . h($siteName) . "</h1><p>Site loaded directly in RAM.</p></body></html>");
                    }
                }

                // If a database seeder SQL file was uploaded with the drop
                $seederSql = '';
                if (!empty($_FILES['seeder_file']) && $_FILES['seeder_file']['error'] === UPLOAD_ERR_OK) {
                    $seederSql = (string) file_get_contents($_FILES['seeder_file']['tmp_name']);
                } elseif (!empty($_POST['seeder_sql_content'])) {
                    $seederSql = (string) $_POST['seeder_sql_content'];
                }

                // Auto-create site database if needed
                if ($detectedStack === 'laravel' || $seederSql !== '') {
                    wtc(['--db-create', $siteName]);
                    if ($seederSql !== '') {
                        wtc(['--db-import', $siteName], $seederSql);
                    }
                }

                $catalogRebuilding = false;
                if ($shouldDeployLive) {
                    // 1. Replace files directly in RAM immediately for quickest site access
                    [$rc, $out, $err] = wtc(['--direct-deploy', $siteName, $stageDir, $makePrimary, '--no-catalog']);
                    if ($rc !== 0)
                        throw new RuntimeException("Backend deployment failed: " . ($err !== '' ? $err : $out));

                    $isArtisanApp = (!empty($_POST['detected_stack']) && $_POST['detected_stack'] === 'laravel') ||
                                    is_file('/mnt/ramdisk/webroot/artisan') ||
                                    is_file('/mnt/ramdisk/sites/' . $siteSlug . '/artisan') ||
                                    is_file('/mnt/ramdisk/sites/' . $siteName . '/artisan');

                    $runRouteClear = isset($_POST['run_route_clear']) ? !empty($_POST['run_route_clear']) : $isArtisanApp;
                    $runOptimizeClear = isset($_POST['run_optimize_clear']) ? !empty($_POST['run_optimize_clear']) : $isArtisanApp;
                    $runStorageLink = isset($_POST['run_storage_link']) ? !empty($_POST['run_storage_link']) : $isArtisanApp;
                    $runZiggyGenerate = isset($_POST['run_ziggy_generate']) ? !empty($_POST['run_ziggy_generate']) : $isArtisanApp;
                    $runMigrations = !empty($_POST['run_migrations']);
                    $runSeeds = !empty($_POST['run_seeds']);
                    $artisanLog = [];

                    $hasZiggy = is_dir('/mnt/ramdisk/sites/' . $siteSlug . '/vendor/tightenco/ziggy') ||
                                is_dir('/mnt/ramdisk/sites/' . $siteName . '/vendor/tightenco/ziggy') ||
                                ($makePrimary === '1' && is_dir('/mnt/ramdisk/webroot/../vendor/tightenco/ziggy'));

                    if ($isArtisanApp || $runRouteClear || $runOptimizeClear || $runStorageLink || ($runZiggyGenerate && $hasZiggy) || $runMigrations || $runSeeds) {
                        if ($runStorageLink) {
                            [$stRc, $stOut, $stErr] = wtc(['--artisan', $siteName, 'storage:link --force']);
                            $artisanLog[] = [
                                'command' => 'php artisan storage:link --force',
                                'ok' => ($stRc === 0),
                                'output' => trim($stOut !== '' ? $stOut : $stErr)
                            ];
                        }

                        if ($runRouteClear) {
                            [$rRc, $rOut, $rErr] = wtc(['--artisan', $siteName, 'route:clear']);
                            $artisanLog[] = [
                                'command' => 'php artisan route:clear',
                                'ok' => ($rRc === 0),
                                'output' => trim($rOut !== '' ? $rOut : $rErr)
                            ];
                        }

                        if ($runOptimizeClear) {
                            [$oRc, $oOut, $oErr] = wtc(['--artisan', $siteName, 'optimize:clear']);
                            $artisanLog[] = [
                                'command' => 'php artisan optimize:clear',
                                'ok' => ($oRc === 0),
                                'output' => trim($oOut !== '' ? $oOut : $oErr)
                            ];
                        }

                        if ($runZiggyGenerate && $hasZiggy) {
                            [$zRc, $zOut, $zErr] = wtc(['--ziggy', $siteName]);
                            $artisanLog[] = [
                                'command' => 'php artisan ziggy:generate',
                                'ok' => ($zRc === 0),
                                'output' => trim($zOut !== '' ? $zOut : $zErr)
                            ];
                        }

                        if ($runMigrations) {
                            [$mRc, $mOut, $mErr] = wtc(['--artisan', $siteName, 'migrate --force']);
                            $artisanLog[] = [
                                'command' => 'php artisan migrate --force',
                                'ok' => ($mRc === 0),
                                'output' => trim($mOut !== '' ? $mOut : $mErr)
                            ];
                        }

                        if ($runSeeds) {
                            [$sRc, $sOut, $sErr] = wtc(['--artisan', $siteName, 'db:seed --force']);
                            $artisanLog[] = [
                                'command' => 'php artisan db:seed --force',
                                'ok' => ($sRc === 0),
                                'output' => trim($sOut !== '' ? $sOut : $sErr)
                            ];
                        }
                    }

                    // 2. Rebuild the SD card catalog in the background after RAM & Artisan hooks are complete
                    [$catRc] = wtc(['--rebuild-catalog', $siteName]);
                    $catalogRebuilding = ($catRc === 0);

                } else {
                    [$rc, $out, $err] = wtc(['--store-site', $siteName, $stageDir]);
                    if ($rc !== 0)
                        throw new RuntimeException("Failed to store site package: " . ($err !== '' ? $err : $out));
                }

                if ($isAjax) {
                    $dbInfo = json_decode(wtc(['--db-info', $siteName])[1] ?? '', true) ?: [];
                    $liveUrl = ($makePrimary === '1') ? site_url('/') : site_url('/' . $siteSlug . '/');
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok' => true,
                        'site' => $siteName,
                        'is_update' => $isUpdate,
                        'incremental_upload' => $isIncremental,
                        'recent_minutes' => $recentMinutes,
                        'uploaded_file_count' => $uploadedFileCount,
                        'spun_up' => $shouldDeployLive,
                        'catalog_rebuilding' => $catalogRebuilding,
                        'is_primary' => ($makePrimary === '1'),
                        'url' => $liveUrl,
                        'db' => $dbInfo,
                        'artisan_log' => $artisanLog ?? [],
                        'protected_patterns' => $protResult['patterns'] ?? [],
                        'restored_files' => $protResult['restored'] ?? []
                    ]);
                    exit;
                }
                $artisanSummary = '';
                if (!empty($artisanLog)) {
                    $artisanSummary = ' (' . implode(', ', array_map(fn($a) => $a['command'] . ': ' . ($a['ok'] ? 'OK' : 'Error'), $artisanLog)) . ')';
                }
                $actionWord = $isUpdate ? 'updated' : 'uploaded';
                flash('ok', "Site '$siteName' $actionWord" . ($shouldDeployLive ? " directly in RAM (rebuilding SD catalog in background)!" : "") . $artisanSummary);
                go($back);

            } catch (\Throwable $e) {
                if ($destArchive !== '' && is_file($destArchive)) {
                    @unlink($destArchive);
                }
                if ($stageDir !== '' && is_dir($stageDir)) {
                    exec('/bin/rm -rf ' . escapeshellarg($stageDir));
                }
                if ($isAjax) {
                    http_response_code(500);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                flash('err', 'Upload failed: ' . $e->getMessage());
                go($back);
            }
        case 'artisan':
            $site = trim((string) ($_POST['site'] ?? ''));
            if ($site === '') {
                $statusData = wj('status');
                $site = (string) ($statusData['active'] ?? '');
            }
            $cmd = trim((string) ($_POST['cmd'] ?? ''));
            $runMigrations = !empty($_POST['run_migrations']);
            $runSeeds = !empty($_POST['run_seeds']);
            $runFresh = !empty($_POST['run_fresh']);
            $runRouteClear = !empty($_POST['run_route_clear']);
            $runOptimizeClear = !empty($_POST['run_optimize_clear']);
            $runConfigClear = !empty($_POST['run_config_clear']);
            $runCacheClear = !empty($_POST['run_cache_clear']);
            $runViewClear = !empty($_POST['run_view_clear']);
            $runStorageLink = !empty($_POST['run_storage_link']);
            $runZiggyGenerate = !empty($_POST['run_ziggy_generate']);
            $runOptimize = !empty($_POST['run_optimize']);

            if (!valid_site($site)) {
                if ($isAjax) {
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => "Invalid site name: '$site'. Please ensure a site is currently active in RAM."]);
                    exit;
                }
                flash('err', "Invalid site name: '$site'. Please ensure a site is currently active in RAM.");
                go($back);
            }

            $cmds = [];
            if ($runRouteClear) $cmds[] = 'route:clear';
            if ($runConfigClear) $cmds[] = 'config:clear';
            if ($runCacheClear) $cmds[] = 'cache:clear';
            if ($runViewClear) $cmds[] = 'view:clear';
            if ($runOptimizeClear) $cmds[] = 'optimize:clear';
            if ($runZiggyGenerate) $cmds[] = 'ziggy:generate';
            if ($runStorageLink) $cmds[] = 'storage:link --force';

            if ($runFresh) {
                $cmds[] = $runSeeds ? 'migrate:fresh --seed --force' : 'migrate:fresh --force';
            } else {
                if ($runMigrations)
                    $cmds[] = 'migrate --force';
                if ($runSeeds)
                    $cmds[] = 'db:seed --force';
            }

            if ($runOptimize) $cmds[] = 'optimize';

            if ($cmd !== '') {
                // Strip leading 'php artisan' if user included it
                $cleanCmd = preg_replace('/^(php\s+)?(artisan\s+)?/i', '', $cmd);
                if ($cleanCmd !== '' && !in_array($cleanCmd, $cmds, true)) {
                    $cmds[] = $cleanCmd;
                }
            }

            if (empty($cmds)) {
                if ($isAjax) {
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'No artisan command or option selected']);
                    exit;
                }
                flash('err', 'No artisan command or option selected');
                go($back);
            }

            $results = [];
            $allOk = true;
            foreach ($cmds as $c) {
                [$rc, $out, $err] = wtc(['--artisan', $site, $c]);
                $txt = trim($out !== '' ? $out : $err);
                if (strlen($txt) > 1200)
                    $txt = '…' . substr($txt, -1200);
                $results[] = "[$c]: " . $txt;
                if ($rc !== 0)
                    $allOk = false;
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => $allOk,
                    'site' => $site,
                    'commands' => $cmds,
                    'results' => $results,
                    'output' => implode("\n", $results)
                ]);
                exit;
            }

            if ($allOk) {
                flash('ok', implode(' | ', $results));
            } else {
                flash('err', 'Artisan result: ' . implode(' | ', $results));
            }
            go($back);
        case 'db_create':
            $site = (string) ($_POST['site'] ?? '');
            backend_msg(wtc(['--db-create', $site]), "Database created for $site");
            if (!empty($_POST['is_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            go($back);
        case 'db_import':
            $site = (string) ($_POST['site'] ?? '');
            $sql = '';
            if (!empty($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
                $sql = (string) file_get_contents($_FILES['sql_file']['tmp_name']);
            } elseif (!empty($_POST['sql_content'])) {
                $sql = (string) $_POST['sql_content'];
            }
            backend_msg(wtc(['--db-import', $site], $sql), "Imported database for $site");
            if (!empty($_POST['is_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            go($back);
        case 'db_info':
            $site = (string) ($_GET['site'] ?? $_POST['site'] ?? '');
            header('Content-Type: application/json');
            echo wtc(['--db-info', $site])[1] ?? '{}';
            exit;
        case 'db_test':
            $site = (string) ($_GET['site'] ?? $_POST['site'] ?? '');
            [$rc, $out, $err] = wtc(['--db-test', $site]);
            if (!empty($_POST['is_ajax']) || !empty($_GET['is_ajax'])) {
                header('Content-Type: application/json');
                echo ($out !== '' ? $out : json_encode(['ok' => false, 'error' => $err ?: 'Connection failed']));
                exit;
            }
            $testRes = json_decode($out, true);
            if (!empty($testRes['ok'])) {
                flash('ok', "Database '{$testRes['database']}' connected successfully! ({$testRes['tables']} tables)");
            } else {
                flash('err', "Database connection failed: " . ($testRes['error'] ?? $err ?: 'Unknown error'));
            }
            go($back);
        case 'db_row_update':
            $site = (string) ($_POST['site'] ?? '');
            $table = (string) ($_POST['table'] ?? '');
            $whereJson = (string) ($_POST['where_json'] ?? '{}');
            $values = $_POST['values'] ?? [];
            $where = json_decode($whereJson, true) ?: [];
            $backUrl = (string) ($_POST['back_url'] ?? ("/?p=database&site=" . urlencode($site) . "&table=" . urlencode($table)));

            try {
                $dbErr = null;
                $pdo = get_db_connection($site, $dbErr);
                if (!$pdo)
                    throw new RuntimeException($dbErr ?: 'Database connection failed');
                $schema = get_table_schema($pdo, $table);
                $affected = update_table_row($pdo, $table, $schema, $where, (array) $values);
                flash('ok', "Record updated successfully in table '$table'.");
            } catch (\Throwable $e) {
                flash('err', "Failed to update record: " . $e->getMessage());
            }
            go($backUrl);

        case 'db_quick_activate':
            $site = (string) ($_POST['site'] ?? '');
            $table = (string) ($_POST['table'] ?? '');
            $whereJson = (string) ($_POST['where_json'] ?? '{}');
            $where = json_decode($whereJson, true) ?: [];
            $backUrl = (string) ($_POST['back_url'] ?? ("/?p=database&site=" . urlencode($site) . "&table=" . urlencode($table)));

            try {
                $dbErr = null;
                $pdo = get_db_connection($site, $dbErr);
                if (!$pdo)
                    throw new RuntimeException($dbErr ?: 'Database connection failed');
                $schema = get_table_schema($pdo, $table);
                $res = activate_account_row($pdo, $table, $schema, $where);
                flash('ok', "⚡ Account successfully activated! (Updated " . implode(', ', $res['fields']) . ")");
            } catch (\Throwable $e) {
                flash('err', "Activation failed: " . $e->getMessage());
            }
            go($backUrl);

        case 'db_row_insert':
            $site = (string) ($_POST['site'] ?? '');
            $table = (string) ($_POST['table'] ?? '');
            $values = $_POST['values'] ?? [];
            $backUrl = (string) ($_POST['back_url'] ?? ("/?p=database&site=" . urlencode($site) . "&table=" . urlencode($table)));

            try {
                $dbErr = null;
                $pdo = get_db_connection($site, $dbErr);
                if (!$pdo)
                    throw new RuntimeException($dbErr ?: 'Database connection failed');
                $schema = get_table_schema($pdo, $table);
                $newId = insert_table_row($pdo, $table, $schema, (array) $values);
                flash('ok', "New record created successfully in table '$table'" . ($newId ? " (ID: $newId)" : "") . ".");
            } catch (\Throwable $e) {
                flash('err', "Failed to insert record: " . $e->getMessage());
            }
            go($backUrl);

        case 'db_row_delete':
            $site = (string) ($_POST['site'] ?? '');
            $table = (string) ($_POST['table'] ?? '');
            $whereJson = (string) ($_POST['where_json'] ?? '{}');
            $where = json_decode($whereJson, true) ?: [];
            $backUrl = (string) ($_POST['back_url'] ?? ("/?p=database&site=" . urlencode($site) . "&table=" . urlencode($table)));

            try {
                $dbErr = null;
                $pdo = get_db_connection($site, $dbErr);
                if (!$pdo)
                    throw new RuntimeException($dbErr ?: 'Database connection failed');
                $schema = get_table_schema($pdo, $table);
                $affected = delete_table_row($pdo, $table, $schema, $where);
                flash('ok', "Record deleted from table '$table'.");
            } catch (\Throwable $e) {
                flash('err', "Failed to delete record: " . $e->getMessage());
            }
            go($backUrl);

        case 'db_query':
            $site = (string) ($_POST['site'] ?? '');
            $table = (string) ($_POST['table'] ?? '');
            $sql = (string) ($_POST['sql_query'] ?? '');
            $backUrl = (string) ($_POST['back_url'] ?? ("/?p=database&site=" . urlencode($site) . ($table !== '' ? "&table=" . urlencode($table) : '')));

            try {
                $dbErr = null;
                $pdo = get_db_connection($site, $dbErr);
                if (!$pdo)
                    throw new RuntimeException($dbErr ?: 'Database connection failed');
                $res = execute_custom_query($pdo, $sql);
                if ($res['type'] === 'select') {
                    flash('ok', "SQL query returned {$res['count']} rows in {$res['duration']}ms.");
                    $_SESSION['db_query_result'] = $res;
                } else {
                    flash('ok', "Statement executed: {$res['affected']} rows affected in {$res['duration']}ms.");
                }
            } catch (\Throwable $e) {
                flash('err', "SQL Error: " . $e->getMessage());
            }
            go($backUrl);
        case 'stack':
            $isAjax = !empty($_POST['is_ajax']);
            $do = (string) ($_POST['do'] ?? '');
            $id = (string) ($_POST['id'] ?? '');
            backend_msg(wtc(['--stack', $do, $id]), 'Package job started');
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            go('stack');
        case 'nginx_save':
            $target = (string) ($_POST['target'] ?? 'ram');
            $serviceAction = (string) ($_POST['service_action'] ?? 'restart');
            $content = (string) ($_POST['config_content'] ?? '');
            [$rc, $out, $err] = wtc(['--nginx-save', $target, $serviceAction], $content);
            if ($rc === 0) {
                flash('ok', "Nginx configuration ($target) saved successfully and service " . ($serviceAction === 'restart' ? 'restarted' : 'reloaded') . '!');
            } else {
                flash('err', "Nginx save failed: " . ($err !== '' ? $err : $out));
            }
            go('nginx');
        case 'nginx_test':
            $target = (string) ($_POST['target'] ?? 'ram');
            $content = (string) ($_POST['config_content'] ?? '');
            [$rc, $out, $err] = wtc(['--nginx-test', $target], $content);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => ($rc === 0),
                'output' => trim($out !== '' ? $out : $err)
            ]);
            exit;
        case 'nginx_ctl':
        case 'system_ctl':
            $do = (string) ($_POST['do'] ?? $_POST['service_action'] ?? 'restart');
            [$rc, $out, $err] = wtc(['--nginx-ctl', $do]);
            if ($rc === 0) {
                flash('ok', "Nginx $do completed successfully");
            } else {
                flash('err', "Nginx $do failed: " . ($err !== '' ? $err : $out));
            }
            go('nginx');
        case 'nginx_reset':
            [$rc, $out, $err] = wtc(['--nginx-reset']);
            if ($rc === 0) {
                flash('ok', "Nginx configuration reset to stack default and restarted");
            } else {
                flash('err', "Nginx reset failed: " . ($err !== '' ? $err : $out));
            }
            go('nginx');
        case 'firewall':
            $do = (string) ($_POST['do'] ?? 'status');
            $port = (string) ($_POST['port'] ?? '');
            $proto = (string) ($_POST['proto'] ?? 'tcp');
            $args = ['--firewall', $do];
            if ($port !== '')
                $args[] = $port;
            if ($proto !== '')
                $args[] = $proto;

            [$rc, $out, $err] = wtc($args);
            if ($rc === 0) {
                flash('ok', $out ?: "Firewall $do applied successfully");
            } else {
                flash('err', "Firewall action failed: " . ($err !== '' ? $err : $out));
            }
            go('firewall');
        case 'service_ctl':
            $svc = (string) ($_POST['service'] ?? '');
            $do = (string) ($_POST['do'] ?? 'status');
            $back = (string) ($_POST['back'] ?? 'services');
            $isAjax = !empty($_POST['is_ajax']);

            if (!preg_match('/^[a-zA-Z0-9_.@-]{1,80}$/', $svc)) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Invalid service unit name']);
                    exit;
                }
                flash('err', 'Invalid service unit name');
                go($back);
            }

            [$rc, $out, $err] = wtc(['--service', $do, $svc]);
            if ($rc === 0) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'service' => $svc, 'action' => $do, 'msg' => "Service '$svc' $do succeeded"]);
                    exit;
                }
                flash('ok', "Service '$svc' $do succeeded" . ($out ? ": $out" : ''));
            } else {
                $errMsg = $err !== '' ? $err : ($out !== '' ? $out : 'Unknown error');
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => "Action '$do' failed for '$svc': $errMsg"]);
                    exit;
                }
                flash('err', "Service '$svc' $do failed: $errMsg");
            }
            go($back);
        case 'service_patch':
            $svc = (string) ($_POST['service'] ?? '');
            $back = (string) ($_POST['back'] ?? 'services');
            $isAjax = !empty($_POST['is_ajax']);

            if (!preg_match('/^[a-zA-Z0-9_.@-]{1,80}$/', $svc)) {
                if ($isAjax) {
                    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Invalid service unit name']);
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    exit;
                }
                flash('err', 'Invalid service unit name');
                go($back);
            }

            [$rc, $out, $err] = wtc(['--service', 'patch', $svc]);
            $res = json_decode(trim($out), true);
            if (!is_array($res) && preg_match('/(\{.*\})/s', $out, $jm)) {
                $res = json_decode($jm[1], true);
            }
            if ($rc === 0 && is_array($res) && !empty($res['ok'])) {
                if ($isAjax) {
                    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                    header('Content-Type: application/json');
                    echo json_encode($res);
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    exit;
                }
                flash('ok', "Service '$svc' patched and restarted successfully.");
            } else {
                $errMsg = is_array($res) && !empty($res['error']) ? $res['error'] : ($err !== '' ? $err : ($out !== '' ? $out : 'Patching failed'));
                if ($isAjax) {
                    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => "Failed to patch service '$svc': $errMsg"]);
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    exit;
                }
                flash('err', "Failed to patch service '$svc': $errMsg");
            }
            go($back);
        case 'service_patch_all':
            $back = (string) ($_POST['back'] ?? 'services');
            $isAjax = !empty($_POST['is_ajax']);

            [$rc, $out, $err] = wtc(['--service', 'patch-all']);
            $res = json_decode(trim($out), true);
            if (!is_array($res) && preg_match('/(\{.*\})/s', $out, $jm)) {
                $res = json_decode($jm[1], true);
            }
            if ($rc === 0 && is_array($res) && !empty($res['ok'])) {
                if ($isAjax) {
                    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                    header('Content-Type: application/json');
                    echo json_encode($res);
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    exit;
                }
                flash('ok', 'All security updates successfully applied across services.');
            } else {
                $errMsg = is_array($res) && !empty($res['error']) ? $res['error'] : ($err !== '' ? $err : ($out !== '' ? $out : 'Failed to apply security patches'));
                if ($isAjax) {
                    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => $errMsg]);
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    exit;
                }
                flash('err', $errMsg);
            }
            go($back);
        case 'security_remediate':
            $rule = (string) ($_POST['rule'] ?? '');
            $back = (string) ($_POST['back'] ?? 'services');
            $isAjax = !empty($_POST['is_ajax']);

            if (!preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $rule)) {
                if ($isAjax) {
                    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Invalid remediation rule identifier']);
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    exit;
                }
                flash('err', 'Invalid remediation rule identifier');
                go($back);
            }

            [$rc, $out, $err] = wtc(['--service', 'remediate', $rule]);
            $res = json_decode($out, true);
            if ($rc === 0 && is_array($res) && !empty($res['ok'])) {
                if ($isAjax) {
                    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                    header('Content-Type: application/json');
                    echo json_encode($res);
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    exit;
                }
                flash('ok', $res['message'] ?? "Security remediation for '$rule' applied.");
            } else {
                $errMsg = $err !== '' ? $err : ($out !== '' ? $out : 'Remediation failed');
                if ($isAjax) {
                    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => $errMsg]);
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    exit;
                }
                flash('err', "Remediation failed: $errMsg");
            }
            go($back);
        case 'cve_lookup':
            $cve = (string) ($_POST['cve'] ?? $_POST['cve_id'] ?? '');
            header('Content-Type: application/json');
            echo json_encode(fetch_cve_details($cve), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        case 'cve_check':
            $unit = (string) ($_POST['unit'] ?? '');
            $ver = (string) ($_POST['version'] ?? 'installed');
            $cves = audit_service_cves($unit, $ver);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'unit' => $unit, 'version' => $ver, 'cves' => $cves], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        case 'stack_logs_clear':
            $source = trim((string) ($_POST['source'] ?? ''));
            $isAjax = !empty($_POST['is_ajax']);
            if (!preg_match('/^[a-zA-Z0-9_.:\/-]{1,220}$/', $source) || str_contains($source, '..')) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Invalid log source']);
                    exit;
                }
                flash('err', 'Invalid log source');
                go($back);
            }
            [$rc, $out, $err] = wtc(['--stack-logs', 'clear', $source]);
            $res = json_decode(trim($out), true);
            if (($rc !== 0 || !is_array($res)) && str_starts_with($source, 'ram:')) {
                $rel = ltrim(substr($source, 4), '/');
                $ramRoot = realpath('/mnt/ramdisk') ?: '/mnt/ramdisk';
                $target = realpath($ramRoot . '/' . $rel);
                if ($target && str_starts_with($target, $ramRoot . '/') && is_file($target) && is_writable($target)
                    && preg_match('/\.(log|error|txt)$/i', $target)) {
                    @file_put_contents($target, '');
                    $res = ['ok' => true, 'id' => $source, 'label' => $rel];
                    $rc = 0;
                }
            }
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(is_array($res) ? $res : ['ok' => ($rc === 0), 'error' => ($err !== '' ? $err : 'Unable to clear log')]);
                exit;
            }
            if ($rc === 0 && !empty($res['ok'])) {
                flash('ok', 'Cleared log file.');
            } else {
                flash('err', 'Failed to clear log.');
            }
            go($back);
    }
    go('dashboard');
}

/* ------------------------------------------------------------ download */
if (($_GET['download'] ?? '') !== '') {
    $siteToDownload = (string) $_GET['download'];
    if (!valid_site($siteToDownload)) {
        flash('err', 'Invalid site name for download');
        go('sites');
    }

    $token = (string) ($_GET['csrf'] ?? '');
    if (!hash_equals(csrf(), $token)) {
        http_response_code(403);
        flash('err', 'Security verification failed for download request. Please try again.');
        go('sites');
    }

    $path = SITES_DIR . '/' . $siteToDownload . '.tar.gz';
    // If archive package is missing on disk but site is running in RAM, snapshot state
    if (!is_file($path)) {
        [$rc, $out, $err] = wtc(['--save-state', $siteToDownload]);
    }

    if (is_file($path)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Description: File Transfer');
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $siteToDownload . '.tar.gz"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0, private');
        header('Pragma: public');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    } else {
        flash('err', "Package archive for '$siteToDownload' not found.");
        go('sites');
    }
}

if (!empty($_GET['download_ui_cert'])) {
    $certPath = null;
    foreach (['/etc/wtc-web/wtc-ui.crt', '/etc/ssl/wtc-ui/wtc-ui.crt'] as $candidate) {
        if (@is_file($candidate) && @is_readable($candidate)) {
            $certPath = $candidate;
            break;
        }
    }
    if ($certPath !== null) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Description: File Transfer');
        header('Content-Type: application/x-x509-ca-cert');
        header('Content-Disposition: attachment; filename="pilabwtc-ui.crt"');
        header('Content-Length: ' . filesize($certPath));
        header('X-Content-Type-Options: nosniff');
        readfile($certPath);
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "HTTPS certificate not found on server. Run sudo bash update.sh on the Pi first.";
    exit;
}

/* ------------------------------------------------------------ GET ajax utilities */
if (($_GET['action'] ?? '') === 'db_test') {
    $site = (string) ($_GET['site'] ?? '');
    [$rc, $out, $err] = wtc(['--db-test', $site]);
    header('Content-Type: application/json');
    echo ($out !== '' ? $out : json_encode(['ok' => false, 'error' => $err ?: 'Connection failed']));
    exit;
}
if (($_GET['action'] ?? '') === 'db_info') {
    $site = (string) ($_GET['site'] ?? '');
    header('Content-Type: application/json');
    echo wtc(['--db-info', $site])[1] ?? '{}';
    exit;
}
if (($_GET['action'] ?? '') === 'dash_live') {
    header('Content-Type: application/json');
    $clientId = substr(md5(session_id() ?: ($_SERVER['REMOTE_ADDR'] ?? 'client')), 0, 8);
    [$rc, $out, $err] = wtc(['--json', 'dash', $clientId]);
    $data = json_decode($out, true);
    if (!is_array($data) || empty($data['status'])) {
        // Fallback for development workstations / demo simulation
        $cpuCores = [];
        $totalUsage = 0;
        for ($i = 0; $i < 4; $i++) {
            $u = rand(4, 28);
            $totalUsage += $u;
            $cpuCores[] = ['id' => $i, 'usage' => $u];
        }
        $data = [
            'demo' => true,
            'status' => [
                'host' => gethostname() ?: 'wtc-pi',
                'model' => 'Raspberry Pi 4 Model B (Emulated)',
                'temp' => (42 + rand(0, 80) / 10) . '°C',
                'uptime' => '4 days, 2 hrs',
                'ips' => ($_SERVER['SERVER_ADDR'] ?? '192.168.1.150'),
                'active' => 'site-alpha',
                'activeSites' => [
                    ['name' => 'site-alpha', 'slug' => 'site-alpha', 'url' => '/site-alpha/']
                ],
                'nginx' => 'yes',
                'phpfpm' => 'yes',
                'mariadb' => 'yes',
                'samba' => 'yes',
                'ssh' => 'yes',
                'ramUsed' => 145000000 + rand(0, 5000000),
                'ramSize' => 536870912,
                'sysMemUsed' => 840000000 + rand(0, 10000000),
                'sysMemTotal' => 4050000000,
                'diskUsed' => 4500000000,
                'diskSize' => 31200000000,
                'pending' => 0,
                'rejected' => 0,
                'sites' => 3,
                'workers' => [
                    'service' => 'yes',
                    'has_artisan' => true,
                    'queue' => ['running' => true, 'pid' => '1024'],
                    'schedule' => ['running' => true, 'pid' => '1025']
                ]
            ],
            'cpu' => [
                'freq_mhz' => 1500,
                'total' => round($totalUsage / 4, 1),
                'cores' => $cpuCores,
                'count' => 4
            ],
            'build' => ['busy' => false, 'log' => ''],
            'loadavg' => ['0.14', '0.22', '0.19'],
            'throttling' => [
                'code' => '0x0',
                'under_voltage' => false,
                'throttled' => false,
                'freq_capped' => false,
                'temp_limit' => false,
                'under_voltage_occurred' => false
            ],
            'saved_state' => [
                'last_saved' => time() - 340,
                'is_dirty' => false
            ],
            'net_rates' => [
                'rx_bytes_sec' => rand(15000, 65000),
                'tx_bytes_sec' => rand(8000, 32000)
            ]
        ];
    } else {
        if (empty($data['cpu']) || !isset($data['cpu']['total'])) {
            [$sRc, $sOut] = wtc(['--json', 'sysinfo', $clientId]);
            $sData = json_decode($sOut, true);
            if (is_array($sData) && isset($sData['total'])) {
                $data['cpu'] = $sData;
            }
        }
    }
    echo json_encode($data);
    exit;
}
if (($_GET['action'] ?? '') === 'sysinfo') {
    header('Content-Type: application/json');
    $clientId = substr(md5(session_id() ?: ($_SERVER['REMOTE_ADDR'] ?? 'client')), 0, 8);
    [$rc, $out, $err] = wtc(['--json', 'sysinfo', $clientId]);
    $data = json_decode($out, true);
    if (!is_array($data) || empty($data['cores'])) {
        // Fallback for dev workstations / environments without /proc/stat
        $cores = [];
        $coreCount = 4;
        $totalUsage = 0;
        for ($i = 0; $i < $coreCount; $i++) {
            $u = rand(5, 25);
            $totalUsage += $u;
            $cores[] = ['id' => $i, 'usage' => $u];
        }
        $data = [
            'freq_mhz' => 1500,
            'total' => round($totalUsage / $coreCount, 1),
            'cores' => $cores,
            'count' => $coreCount
        ];
    }
    echo json_encode($data);
    exit;
}
if (($_GET['action'] ?? '') === 'processes') {
    header('Content-Type: application/json');
    [$rc, $out, $err] = wtc(['--json', 'processes']);
    $data = json_decode($out, true);
    if (!is_array($data)) {
        $data = [];
    }
    echo json_encode($data);
    exit;
}
if (($_GET['action'] ?? '') === 'net_monitor') {
    $limit = (int) ($_GET['limit'] ?? 30);
    if ($limit < 5)
        $limit = 5;
    if ($limit > 500)
        $limit = 500;
    header('Content-Type: application/json');
    [$rc, $out, $err] = wtc(['--json', 'net_monitor', (string) $limit]);
    $data = json_decode($out, true);
    if (!is_array($data) || empty($data['listeners'])) {
        $data = [
            'host' => gethostname() ?: '',
            'gateway' => '',
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'ifaces' => [],
            'listeners' => [],
            'requests' => []
        ];
    } else {
        $data['client_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    echo json_encode($data);
    exit;
}
if (($_GET['action'] ?? '') === 'service_logs') {
    $svc = (string) ($_GET['service'] ?? '');
    $lines = (int) ($_GET['lines'] ?? 50);
    if ($lines < 10) $lines = 10;
    if ($lines > 500) $lines = 500;
    header('Content-Type: application/json');
    if (!preg_match('/^[a-zA-Z0-9_.@-]{1,80}$/', $svc)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid service name']);
        exit;
    }
    [$rc, $out, $err] = wtc(['--service', 'logs', $svc, (string) $lines]);
    $logs = trim($out !== '' ? $out : $err);
    if ($logs === '' || $rc !== 0) {
        $logs = "No systemd journal entries available for $svc.";
    }
    echo json_encode(['ok' => true, 'service' => $svc, 'logs' => $logs]);
    exit;
}
if (($_GET['action'] ?? '') === 'stack_logs_list') {
    header('Content-Type: application/json');
    $siteHint = trim((string) ($_GET['site'] ?? ''));
    $pathHint = ltrim(str_replace(['\\', '..'], ['/', ''], (string) ($_GET['path'] ?? '')), '/');

    [$rc, $out] = wtc(['--json', 'stack_logs']);
    $sources = ($rc === 0) ? json_decode($out, true) : null;
    if (!is_array($sources)) {
        $sources = [];
    }

    // Augment / fallback: scan /mnt/ramdisk directly within open_basedir for .log / .error files
    $knownIds = [];
    foreach ($sources as $s) {
        if (!empty($s['id'])) $knownIds[$s['id']] = true;
    }

    $ramRoot = realpath('/mnt/ramdisk');
    if ($ramRoot && is_dir($ramRoot)) {
        $ramSitesDir = $ramRoot . '/sites';
        if (is_dir($ramSitesDir)) {
            $siteDirs = @scandir($ramSitesDir) ?: [];
            foreach ($siteDirs as $sd) {
                if ($sd === '.' || $sd === '..' || !is_dir($ramSitesDir . '/' . $sd)) continue;
                $sPath = $ramSitesDir . '/' . $sd;
                $candidates = [];
                foreach (['storage/logs/*.log', 'logs/*.log', 'log/*.log', '*.log'] as $globPat) {
                    foreach (glob($sPath . '/' . $globPat) ?: [] as $gf) {
                        if (is_file($gf)) $candidates[] = $gf;
                    }
                }
                foreach (array_unique($candidates) as $lf) {
                    $rel = ltrim(str_replace('\\', '/', substr($lf, strlen($ramRoot))), '/');
                    $id = 'ram:' . $rel;
                    if (isset($knownIds[$id])) continue;
                    $knownIds[$id] = true;
                    $fmt = str_contains($rel, 'storage/logs/') ? 'laravel' : 'auto';
                    $sources[] = [
                        'id' => $id,
                        'label' => $sd . ' / ' . basename($lf),
                        'category' => 'Application Stacks',
                        'stack' => $fmt === 'laravel' ? 'laravel' : 'app',
                        'site' => $sd,
                        'path' => $lf,
                        'relPath' => $rel,
                        'format' => $fmt,
                        'size' => @filesize($lf) ?: 0,
                        'mtime' => @filemtime($lf) ?: 0,
                        'exists' => true,
                        'clearable' => is_writable($lf),
                        'primary' => false
                    ];
                }
            }
        }
        $rejDir = $ramRoot . '/rejected';
        if (is_dir($rejDir)) {
            foreach (glob($rejDir . '/*.error') ?: [] as $rf) {
                if (!is_file($rf)) continue;
                $rel = ltrim(str_replace('\\', '/', substr($rf, strlen($ramRoot))), '/');
                $id = 'ram:' . $rel;
                if (isset($knownIds[$id])) continue;
                $knownIds[$id] = true;
                $sources[] = [
                    'id' => $id,
                    'label' => 'Rejected: ' . basename($rf),
                    'category' => 'RAM Drive Errors',
                    'stack' => 'dropbox',
                    'site' => '',
                    'path' => $rf,
                    'relPath' => $rel,
                    'format' => 'wtc',
                    'size' => @filesize($rf) ?: 0,
                    'mtime' => @filemtime($rf) ?: 0,
                    'exists' => true,
                    'clearable' => is_writable($rf),
                    'primary' => false
                ];
            }
        }
    }

    // If pathHint points directly to a file or site directory in RAM, pick best matching source
    $recommended = '';
    if ($pathHint !== '') {
        $exactId = 'ram:' . $pathHint;
        if (isset($knownIds[$exactId])) {
            $recommended = $exactId;
        } else {
            // Check if pathHint is inside sites/<slug>/... or webroot/...
            if (preg_match('#^sites/([^/]+)#', $pathHint, $pm)) {
                $siteHint = $siteHint !== '' ? $siteHint : $pm[1];
            }
            foreach ($sources as $s) {
                if (!empty($s['relPath']) && str_starts_with($s['relPath'], rtrim($pathHint, '/') . '/')) {
                    $recommended = $s['id'];
                    break;
                }
            }
        }
    }
    if ($recommended === '' && $siteHint !== '') {
        $siteSlugHint = strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '', $siteHint)));
        foreach ($sources as $s) {
            $sSite = strtolower((string) ($s['site'] ?? ''));
            $sRel = strtolower((string) ($s['relPath'] ?? ''));
            if ($sSite !== '' && ($sSite === strtolower($siteHint) || $sSite === $siteSlugHint || str_starts_with($sRel, 'sites/' . $siteSlugHint . '/'))) {
                $recommended = $s['id'];
                break;
            }
        }
        // Fallback for non-Laravel sites (SPA/static/PHP) -> prefer nginx-error or build log if relevant
        if ($recommended === '') {
            if (isset($knownIds['nginx-error'])) {
                $recommended = 'nginx-error';
            }
        }
    }
    if ($recommended === '') {
        foreach ($sources as $s) {
            if (!empty($s['primary']) && !empty($s['exists'])) {
                $recommended = $s['id'];
                break;
            }
        }
    }
    if ($recommended === '') {
        foreach ($sources as $s) {
            if (($s['category'] ?? '') === 'Application Stacks' && !empty($s['exists']) && (int) ($s['size'] ?? 0) > 0) {
                $recommended = $s['id'];
                break;
            }
        }
    }
    if ($recommended === '' && isset($knownIds['wtc-manage'])) {
        $recommended = 'wtc-manage';
    }

    echo json_encode([
        'ok' => true,
        'sources' => $sources,
        'recommended' => $recommended
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
if (($_GET['action'] ?? '') === 'stack_logs_read') {
    header('Content-Type: application/json');
    $source = trim((string) ($_GET['source'] ?? 'wtc-manage'));
    $lines = (int) ($_GET['lines'] ?? 200);
    if ($lines < 10) $lines = 10;
    if ($lines > 2000) $lines = 2000;

    if (!preg_match('/^[a-zA-Z0-9_.:\/-]{1,220}$/', $source) || str_contains($source, '..')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid log source identifier']);
        exit;
    }

    [$rc, $out, $err] = wtc(['--stack-logs', 'read', $source, (string) $lines]);
    $decoded = ($rc === 0) ? json_decode($out, true) : null;
    if (is_array($decoded) && isset($decoded['ok'])) {
        echo json_encode($decoded, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    // Fallback: direct safe tail read for /mnt/ramdisk/... files
    if (str_starts_with($source, 'ram:')) {
        $rel = ltrim(substr($source, 4), '/');
        $ramRoot = realpath('/mnt/ramdisk');
        $target = $ramRoot ? realpath($ramRoot . '/' . $rel) : false;
        if ($ramRoot && $target && str_starts_with($target, $ramRoot . '/') && is_file($target)
            && preg_match('/\.(log|error|txt)$/i', $target)) {
            $fp = @fopen($target, 'rb');
            $content = '';
            if ($fp) {
                fseek($fp, 0, SEEK_END);
                $pos = ftell($fp);
                $chunkSize = 8192;
                $lineCount = 0;
                $buf = '';
                while ($pos > 0 && $lineCount <= $lines) {
                    $readLen = min($chunkSize, $pos);
                    $pos -= $readLen;
                    fseek($fp, $pos, SEEK_SET);
                    $chunk = fread($fp, $readLen);
                    $buf = $chunk . $buf;
                    $lineCount = substr_count($buf, "\n");
                }
                fclose($fp);
                $allLines = explode("\n", str_replace("\r\n", "\n", $buf));
                if (count($allLines) > $lines) {
                    $allLines = array_slice($allLines, -$lines);
                }
                $content = implode("\n", $allLines);
            }
            echo json_encode([
                'ok' => true,
                'id' => $source,
                'label' => $rel,
                'path' => $target,
                'format' => str_contains($rel, 'storage/logs/') ? 'laravel' : 'auto',
                'exists' => true,
                'size' => @filesize($target) ?: 0,
                'mtime' => @filemtime($target) ?: 0,
                'clearable' => is_writable($target),
                'content' => $content
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            exit;
        }
    }

    echo json_encode([
        'ok' => false,
        'error' => $err !== '' ? $err : 'Log source unavailable or backend unreachable.'
    ]);
    exit;
}
if (($_GET['action'] ?? '') === 'services_json') {
    header('Content-Type: application/json');
    $data = wj('services', true);
    echo json_encode($data);
    exit;
}
if (($_GET['action'] ?? '') === 'service_security_scan') {
    header('Content-Type: application/json');
    $force = !empty($_GET['force']) ? '1' : '0';
    if ($force === '1') {
        @set_time_limit(180);
        get_cve_threat_catalog(true); // Update live OSV catalog on disk FIRST
    }
    [$rc, $out, $err] = wtc(['--security-scan', $force]);
    $data = json_decode($out, true);
    if (!is_array($data) || empty($data)) {
        echo json_encode(['ok' => false, 'error' => 'Live security scan unavailable: ' . ($err !== '' ? $err : 'backend returned no data')]);
        exit;
    }
    echo json_encode(['ok' => true, 'report' => $data], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
if (($_GET['action'] ?? '') === 'cve_lookup') {
    header('Content-Type: application/json');
    $cve = (string) ($_GET['cve'] ?? $_GET['cve_id'] ?? '');
    echo json_encode(fetch_cve_details($cve), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_GET['action'] ?? '') === 'cve_check') {
    header('Content-Type: application/json');
    $unit = (string) ($_GET['unit'] ?? '');
    $ver = (string) ($_GET['version'] ?? '');
    $force = !empty($_GET['force']);

    $detectedVer = resolve_service_version($unit);
    if ($detectedVer !== '') {
        $ver = $detectedVer;
    } elseif ($ver === 'installed') {
        $ver = '';
    }

    $cves = audit_service_cves($unit, $ver, [], $force);
    $pending = array_values(array_filter($cves, fn($c) => ($c['status'] ?? '') === 'pending' || !empty($c['is_affected'])));

    $badgeText = '🛡️ Hardened';
    $badgeType = 'clean';
    $primaryCve = null;

    if (!empty($pending)) {
        $primaryCve = $pending[0];
        foreach ($pending as $c) {
            if (!empty($c['is_downgraded'])) {
                $primaryCve = $c;
                break;
            }
            if (($c['cvss'] ?? 0) > ($primaryCve['cvss'] ?? 0)) {
                $primaryCve = $c;
            }
        }
        $moreCount = count($pending) - 1;
        if (!empty($primaryCve['is_downgraded'])) {
            $badgeText = '⚠️ Downgraded' . ($moreCount > 0 ? " (+$moreCount)" : '');
            $badgeType = 'downgraded';
        } else {
            $badgeText = '⚠️ ' . ($primaryCve['id'] ?? 'Vulnerable') . ($moreCount > 0 ? " (+$moreCount)" : '');
            $badgeType = 'vulnerable';
        }
    } elseif (!empty($cves)) {
        $badgeText = '🛡️ Patched';
        $badgeType = 'patched';
    }

    echo json_encode([
        'ok' => true,
        'unit' => $unit,
        'version' => $ver,
        'has_vulnerabilities' => !empty($pending),
        'vulnerabilities_count' => count($pending),
        'badge_text' => $badgeText,
        'badge_type' => $badgeType,
        'primary_cve' => $primaryCve,
        'pending_cves' => $pending,
        'cves' => $cves,
        'scanned_at' => date('H:i:s'),
        'scanned_timestamp' => date('Y-m-d H:i:s')
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/* ------------------------------------------------------------ routing & components */
$page = (string) ($_GET['p'] ?? 'dashboard');
$pages = [
    'dashboard' => '🖥️ Device Dashboard',
    'sites' => '📁 Sites & Apps',
    'services' => '⚙️ Service Manager',
    'database' => '🗄️ Database',
    'explorer' => '⚡ RAM Drive',
    'nginx' => '🌐 Nginx Config',
    'state' => '💾 Save State',
    'transfer' => '🔄 File Transfer',
    'firewall' => '🛡️ Firewall & Ports',
    'stack' => '📦 Stack Components',
    'stack_manager' => '📦 Stack Manager',
    'settings' => '⚙️ Settings',
    'logs' => '📜 Logs'
];
if ($page === 'stack-manager')
    $page = 'stack_manager';
if (!isset($pages[$page]))
    $page = 'dashboard';
$refresh = (($page === 'stack' && !empty(wj('stack')['busy'])) || ($page === 'stack_manager' && !empty(wj('build')['busy']))) ? 3 : 0;

function form(string $action, array $fields, string $btn, string $cls = '', string $confirm = ''): string
{
    $s = '<form method="post" action="/" data-action="' . h($action) . '" style="display:inline"' . ($confirm ? ' onsubmit="return confirm(\'' . h($confirm) . '\')"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($fields as $k => $v)
        $s .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    return $s . '<button class="' . h($cls) . '">' . h($btn) . '</button></form>';
}
function onoff(bool $b): string
{
    return $b ? '<span class="pill ok">running</span>' : '<span class="pill off">stopped</span>';
}

/* ------------------------------------------------------------ render UI */
if (is_spa_request()) {
    header('X-WTC-Page: ' . $page);
    header('X-WTC-Title-B64: ' . base64_encode('WTC Pi - ' . ($pages[$page] ?? 'Device Manager')));
    header('X-WTC-Title: WTC Pi - ' . preg_replace('/[^\x20-\x7E]/', '', (string) ($pages[$page] ?? 'Device Manager')));
    $tabFile = __DIR__ . '/components/tabs/' . $page . '.php';
    if (is_file($tabFile)) {
        require $tabFile;
    } else {
        echo '<div class="card"><p>Tab container "' . h($page) . '" not found.</p></div>';
    }
    exit;
}

require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/main.php';
require_once __DIR__ . '/components/footer.php';

/* ------------------------------------------------------------ CSS */
function css(): string
{
    return <<<CSS
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: #090d13;
    color: #e6edf3;
    line-height: 1.45;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
}

/* Enterprise Header Bar */
header {
    background: #0d1117;
    border-bottom: 1px solid #21262d;
    padding: 8px 20px;
    min-height: 52px;
    display: flex;
    align-items: center;
    gap: 14px 16px;
    flex-wrap: wrap;
    position: sticky;
    top: 0;
    z-index: 1000;
}
header .brand {
    font-size: 1.05rem;
    font-weight: 700;
    color: #f0f6fc;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.01em;
    padding-right: 12px;
    border-right: 1px solid #21262d;
    white-space: nowrap;
    height: 36px;
}
header .brand .brand-logo { color: #58a6ff; }
header .brand .brand-title { color: #f0f6fc; font-weight: 700; }
header .brand .brand-tag {
    background: #1f2937;
    color: #38ef7d;
    font-size: 0.65rem;
    padding: 2px 7px;
    border-radius: 3px;
    font-weight: 700;
    letter-spacing: 0.08em;
    border: 1px solid #2d3748;
}
nav {
    display: flex;
    gap: 4px;
    align-items: center;
    flex-wrap: wrap;
    flex: 1;
}
nav a {
    color: #8b949e;
    text-decoration: none;
    padding: 6px 12px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    font-size: 0.86rem;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    transition: color .15s ease, border-color .15s ease, background-color .15s ease;
    white-space: nowrap;
}
nav a:hover { color: #c9d1d9; border-bottom-color: #30363d; background: rgba(255,255,255,0.04); }
nav a.on { color: #58a6ff; border-bottom-color: #1f6feb; font-weight: 600; background: rgba(56,139,253,0.08); }

.sp { display: none; }
.header-actions { display: flex; gap: 8px; align-items: center; white-space: nowrap; margin-left: auto; }
.btn-live {
    background: #162436;
    color: #58a6ff;
    border: 1px solid #1f6feb;
    text-decoration: none;
    padding: 5px 12px;
    border-radius: 4px;
    font-weight: 600;
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .15s ease;
}
.btn-live:hover { background: #1f6feb; color: #fff; }
.btn-live.root-link {
    background: #142a1e;
    color: #3fb950;
    border: 1px solid #238636;
}
.btn-live.root-link:hover { background: #238636; color: #fff; }
.btn-live.btn-reload-ui {
    background: #1c2128;
    color: #e6edf3;
    border: 1px solid #30363d;
    cursor: pointer;
}
.btn-live.btn-reload-ui:hover {
    background: #21262d;
    color: #58a6ff;
    border-color: #58a6ff;
}

/* Full-width Screen Layout */
main, .main-container {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 16px 20px !important;
    width: 100% !important;
    flex: 1;
    box-sizing: border-box;
}
.tab-container { width: 100%; }

/* Enterprise Panel (Replaces bubbly cards) */
.card, .panel {
    background: #0d1117;
    border: 1px solid #21262d;
    border-radius: 4px;
    padding: 16px 18px;
    margin-bottom: 14px;
    width: 100%;
    box-sizing: border-box;
}
.card h3, .panel h3 {
    font-size: 0.94rem;
    font-weight: 600;
    color: #f0f6fc;
    letter-spacing: 0.02em;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid #1c2128;
    padding-bottom: 8px;
}
.card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 12px;
    border-bottom: 1px solid #1c2128;
    padding-bottom: 10px;
}
.card-head h3 { border-bottom: 0; padding-bottom: 0; margin-bottom: 0; }
.card-head .hl { color: #58a6ff; font-weight: 600; }
.card-active { border-color: #1f6feb !important; background: #0e1520 !important; }

/* Telemetry Ribbon / Banner */
.hero-device {
    background: #0d1117;
    border: 1px solid #21262d;
    border-radius: 4px;
    padding: 14px 18px;
    margin-bottom: 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
}
.device-info h2 {
    font-size: 1.12rem;
    font-weight: 600;
    color: #f0f6fc;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.device-info .badge {
    background: #161b22;
    font-size: 0.72rem;
    padding: 2px 8px;
    border-radius: 3px;
    color: #58a6ff;
    font-weight: 600;
    border: 1px solid #30363d;
    text-transform: uppercase;
}
.telemetry { display: flex; gap: 18px; flex-wrap: wrap; font-size: 0.82rem; color: #8b949e; }
.telemetry span b { color: #e6edf3; font-weight: 600; }
.quick-device-controls { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }

/* Dashboard KPI Tiles Grid & Cards */
.dash-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 14px;
    width: 100%;
}
.kpi-card {
    background: #0d1117;
    border: 1px solid #21262d;
    border-radius: 6px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 104px;
    box-sizing: border-box;
}
.kpi-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}
.kpi-title {
    font-size: 0.78rem;
    font-weight: 600;
    color: #8b949e;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.kpi-badge {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 3px;
}
.kpi-badge.ok { background: #13241b; color: #3fb950; border: 1px solid #238636; }
.kpi-badge.warn { background: #271f11; color: #d29922; border: 1px solid #9e6a03; }
.kpi-badge.danger { background: #2c1517; color: #f85149; border: 1px solid #da3633; }
.kpi-value-row {
    display: flex;
    align-items: baseline;
    gap: 6px;
}
.kpi-val {
    font-size: 1.25rem;
    font-weight: 700;
    color: #f0f6fc;
}
.kpi-sub {
    font-size: 0.76rem;
    color: #8b949e;
}
.kpi-spark-wrap {
    height: 32px;
    width: 100%;
    margin-top: 6px;
}
.kpi-spark {
    width: 100%;
    height: 32px;
    display: block;
}
.kpi-foot {
    font-size: 0.72rem;
    color: #8b949e;
    margin-top: 6px;
}

/* Alert Strip Items */
.dash-alert-item {
    padding: 10px 14px;
    border-radius: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 0.84rem;
}
.dash-alert-item.warn {
    background: #1c150b;
    border: 1px solid #9e6a03;
    color: #e3b341;
}
.dash-alert-item.danger {
    background: #230f12;
    border: 1px solid #da3633;
    color: #f85149;
}

/* Accordion Deep Inspectors */
.dash-accordion summary {
    outline: none;
    transition: color 0.15s ease;
}
.dash-accordion summary::-webkit-details-marker {
    display: none;
}
.dash-accordion summary:hover {
    color: #58a6ff;
}

/* Enterprise Grids */
.grid3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; margin-bottom: 14px; width: 100%; }
.grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 12px; margin-top: 14px; width: 100%; }

.stat { font-size: 1.05rem; font-weight: 600; color: #f0f6fc; margin: 6px 0 2px; }
.bar { height: 6px; background: #161b22; border-radius: 2px; overflow: hidden; margin-top: 6px; }
.bar i { display: block; height: 100%; background: #1f6feb; border-radius: 2px; }

/* Buttons & Controls */
.btn-ctrl {
    background: #161b22;
    color: #c9d1d9;
    border: 1px solid #30363d;
    padding: 5px 11px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.8rem;
    font-weight: 500;
    transition: all .15s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    line-height: 1.4;
}
.btn-ctrl:hover { background: #21262d; border-color: #8b949e; color: #f0f6fc; }
.btn-ctrl.danger { border-color: #da3633; color: #f85149; background: #1e1113; }
.btn-ctrl.danger:hover { background: #da3633; color: #fff; }

.btn-pri, button.btn-pri {
    background: #1f6feb;
    color: #fff;
    border: 1px solid #388bfd;
    padding: 6px 14px;
    border-radius: 4px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.84rem;
    transition: all .15s ease;
}
.btn-pri:hover { background: #1158c7; border-color: #58a6ff; }
.btn-swap {
    background: #161b22;
    color: #58a6ff;
    border: 1px solid #30363d;
    padding: 4px 10px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.8rem;
    font-weight: 500;
}
.btn-swap:hover { background: #1f6feb; color: #fff; border-color: #388bfd; }

button {
    background: #161b22;
    color: #c9d1d9;
    border: 1px solid #30363d;
    padding: 5px 12px;
    border-radius: 4px;
    cursor: pointer;
    font: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    transition: all .15s ease;
}
button:hover { background: #21262d; border-color: #8b949e; color: #f0f6fc; }
button.danger { background: #211315; border-color: #da3633; color: #f85149; }
button.danger:hover { background: #da3633; color: #fff; }

.btn {
    background: #161b22;
    color: #c9d1d9;
    border: 1px solid #30363d;
    text-decoration: none;
    padding: 5px 11px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all .15s ease;
}
.btn:hover { background: #21262d; border-color: #8b949e; color: #f0f6fc; }

/* Enterprise Data Tables */
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
    border: 1px solid #21262d;
    background: #0d1117;
}
thead {
    position: sticky;
    top: 0;
    z-index: 5;
    background: #161b22;
}
th {
    background: #161b22;
    color: #8b949e;
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    font-weight: 600;
    border-bottom: 2px solid #21262d;
    padding: 8px 12px;
    text-align: left;
    position: sticky;
    top: 0;
    z-index: 5;
}
td {
    padding: 8px 12px;
    border-bottom: 1px solid #1c2128;
    font-size: 0.84rem;
    color: #c9d1d9;
    vertical-align: middle;
}
tr:nth-child(even) td { background: #0b0f14; }
tr:hover td { background: #161f28; color: #f0f6fc; }
.act { text-align: right; white-space: nowrap; }

/* Status Badges & Pills */
.pill {
    padding: 2px 7px;
    border-radius: 3px;
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    display: inline-block;
    border: 1px solid transparent;
}
.pill.ok { background: #12281e; color: #3fb950; border-color: #238636; }
.pill.off { background: #1c2128; color: #8b949e; border-color: #30363d; }
.pill.err { background: #2a1215; color: #f85149; border-color: #da3633; }

/* System Alerts */
.flash {
    padding: 10px 16px;
    border-radius: 4px;
    margin-bottom: 14px;
    font-weight: 500;
    font-size: 0.86rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.flash.ok { background: #12281e; color: #3fb950; border: 1px solid #238636; }
.flash.err { background: #2a1215; color: #f85149; border: 1px solid #da3633; }

/* Inputs & Forms */
code { background: #161b22; padding: 2px 5px; border-radius: 3px; font-size: 0.82rem; color: #79c0ff; border: 1px solid #21262d; }
pre {
    background: #050a10;
    padding: 12px;
    border-radius: 4px;
    overflow: auto;
    max-height: 440px;
    font-size: 0.82rem;
    font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
    border: 1px solid #21262d;
    line-height: 1.45;
}
input, select, textarea {
    background: #0d1117;
    color: #f0f6fc;
    border: 1px solid #30363d;
    border-radius: 4px;
    padding: 5px 9px;
    font-size: 0.85rem;
}
input:focus, select:focus, textarea:focus {
    border-color: #58a6ff;
    outline: none;
    box-shadow: 0 0 0 2px rgba(88,166,255,0.2);
}

/* Dropzone & Quick Ingest */
.dropzone {
    border: 2px dashed #30363d;
    border-radius: 4px;
    background: #0d1117;
    padding: 24px 16px;
    text-align: center;
    cursor: pointer;
    transition: all .15s ease;
    margin-bottom: 14px;
}
.dropzone:hover, .dropzone.dragover {
    border-color: #58a6ff;
    background: #0e1520;
    box-shadow: 0 0 12px rgba(88,166,255,0.15);
}
.dropzone-icon { font-size: 2rem; margin-bottom: 4px; }
.dropzone-title { font-size: 1.05rem; font-weight: 600; color: #f0f6fc; margin-bottom: 2px; }
.dropzone-sub { color: #8b949e; font-size: 0.82rem; margin-bottom: 12px; }
.dropzone-buttons { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
.dropzone-options {
    display: flex;
    gap: 16px;
    align-items: center;
    flex-wrap: wrap;
    background: #0d1117;
    padding: 10px 14px;
    border-radius: 4px;
    margin-bottom: 14px;
    border: 1px solid #21262d;
}
.dropzone-options label { font-size: 0.84rem; color: #c9d1d9; display: flex; align-items: center; gap: 6px; }
.spin-label { color: #58a6ff !important; cursor: pointer; }
.dropzone-progress { background: #0d1117; border: 1px solid #21262d; border-radius: 4px; padding: 14px; margin-bottom: 14px; }
.progress-status { font-size: 0.84rem; color: #58a6ff; margin-top: 6px; text-align: center; font-weight: 500; }
.dropzone-success { background: #12281e; border: 1px solid #238636; border-radius: 4px; padding: 16px; margin-bottom: 14px; }
.success-banner h4 { color: #3fb950; font-size: 1.05rem; margin-bottom: 6px; }
.success-banner p { color: #c9d1d9; font-size: 0.86rem; margin-bottom: 12px; }
.success-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.copy-box { display: inline-flex; align-items: center; gap: 6px; background: #0d1117; padding: 3px 6px; border-radius: 4px; border: 1px solid #30363d; font-size: 0.82rem; }
.copy-btn { background: #21262d; color: #c9d1d9; border: 0; padding: 2px 6px; border-radius: 3px; font-size: 0.74rem; cursor: pointer; }
.copy-btn:hover { background: #30363d; color: #fff; }
.tip-box { background: #0d1117; padding: 10px 12px; border-radius: 4px; border: 1px solid #21262d; margin-top: 10px; font-size: 0.82rem; color: #8b949e; line-height: 1.5; }
.tip-box b { color: #58a6ff; }

/* Stack Panels */
.stack-banner { background: #0e1520; border: 1px solid #1f6feb; border-radius: 4px; padding: 12px 16px; margin-bottom: 14px; animation: fadeIn .2s ease; }
.stack-banner-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.stack-icon { font-size: 1.8rem; }
.stack-banner h4 { font-size: 1.02rem; color: #58a6ff; margin-bottom: 2px; }
.stack-banner p { font-size: 0.82rem; color: #8b949e; }
.stack-panel { background: #0d1117; border: 1px solid #21262d; border-radius: 4px; padding: 14px 16px; margin-bottom: 14px; }
.stack-panel h4 { font-size: 0.95rem; color: #f0f6fc; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
.req-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px; margin-top: 8px; }
.req-item { background: #090d13; border: 1px solid #21262d; padding: 8px 12px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem; }
.req-item.ok { border-color: #238636; }
.req-item.missing { border-color: #da3633; background: #1a0e10; }
.req-item.optional { border-color: #30363d; }
.env-nav { display: flex; gap: 4px; }
.env-nav .btn.on { background: #1f6feb; color: #fff; border-color: #388bfd; }
.env-guided-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 10px; margin-top: 8px; }
.env-guided-grid label { display: flex; flex-direction: column; gap: 3px; font-size: 0.8rem; color: #79c0ff; }
.env-guided-grid input, .env-guided-grid select { background: #090d13; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 5px 8px; font-size: 0.82rem; }
.env-textarea { width: 100%; background: #090d13; color: #3fb950; font-family: ui-monospace, SFMono-Regular, "SF Mono", monospace; border: 1px solid #30363d; border-radius: 4px; padding: 10px; font-size: 0.82rem; line-height: 1.45; resize: vertical; margin-top: 6px; }
.stack-catalog-box { background: #0d1117; border: 1px solid #21262d; border-radius: 4px; padding: 16px; margin-top: 16px; }
.catalog-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
.catalog-card { background: #090d13; border: 1px solid #21262d; border-radius: 4px; padding: 12px; display: flex; flex-direction: column; justify-content: space-between; }
.catalog-card.active-profile { border-color: #1f6feb; }
.catalog-card-header { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
.catalog-desc { font-size: 0.8rem; color: #8b949e; margin-bottom: 8px; flex: 1; }
.catalog-details { border-top: 1px solid #1c2128; padding-top: 6px; font-size: 0.78rem; line-height: 1.4; color: #c9d1d9; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

/* Modals */
.modal-backdrop { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.82); backdrop-filter: blur(2px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px; }
.modal-dialog { background: #0d1117; border: 1px solid #30363d; border-radius: 6px; box-shadow: 0 16px 48px rgba(0, 0, 0, 0.85); width: 100%; max-width: 760px; max-height: 90vh; display: flex; flex-direction: column; animation: modalFadeIn .15s ease-out; }
@keyframes modalFadeIn { from { opacity: 0; transform: scale(0.98) translateY(-6px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.modal-header { padding: 12px 18px; border-bottom: 1px solid #21262d; display: flex; justify-content: space-between; align-items: center; background: #161b22; border-top-left-radius: 5px; border-top-right-radius: 5px; }
.modal-header h3 { font-size: 1.05rem; color: #f0f6fc; margin: 0; display: flex; align-items: center; gap: 8px; }
.modal-close { background: transparent; border: 0; color: #8b949e; font-size: 1.2rem; cursor: pointer; padding: 2px 6px; border-radius: 4px; line-height: 1; }
.modal-close:hover { background: #21262d; color: #fff; }
.modal-body { padding: 16px 18px; overflow-y: auto; flex: 1; }
.modal-footer { padding: 12px 18px; border-top: 1px solid #21262d; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: #0d1117; border-bottom-left-radius: 5px; border-bottom-right-radius: 5px; }
.modal-info-box { background: #090d13; border: 1px solid #21262d; border-radius: 4px; padding: 10px 14px; margin-bottom: 12px; }
.modal-folder-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 8px; margin-top: 10px; max-height: 290px; overflow-y: auto; padding-right: 4px; }
.modal-folder-item { background: #090d13; border: 1px solid #21262d; border-radius: 4px; padding: 8px 10px; display: flex; align-items: center; gap: 8px; cursor: pointer; transition: all .12s ease; user-select: none; }
.modal-folder-item:hover { filter: brightness(1.1); }
.modal-folder-item.included { border-color: #1f6feb; background: #0e1520; }
.modal-folder-item.excluded { border-color: #da3633; background: #1a0e10; }

/* SPA Progress Bar */
.spa-progress-bar {
    position: fixed;
    top: 0;
    left: 0;
    height: 3px;
    width: 0%;
    background: linear-gradient(90deg, #1f6feb, #58a6ff, #38ef7d);
    z-index: 10000;
    transition: width 0.25s ease, opacity 0.3s ease;
    opacity: 0;
    pointer-events: none;
}
.spa-progress-bar.active {
    opacity: 1;
    width: 75%;
    animation: spaBarPulse 1.2s infinite ease-in-out alternate;
}
.spa-progress-bar.done {
    opacity: 1;
    width: 100%;
}
@keyframes spaBarPulse {
    from { width: 65%; }
    to { width: 92%; }
}

/* SPA Action Waiting Modal */
.spa-loading-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(4, 7, 12, 0.88);
    backdrop-filter: blur(4px);
    z-index: 10001;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    opacity: 0;
    transition: opacity .2s ease;
}
.spa-loading-modal-backdrop.visible {
    display: flex;
    opacity: 1;
}
.spa-loading-modal-dialog {
    background: #0d1117;
    border: 1px solid #30363d;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.9), 0 0 0 1px rgba(88, 166, 255, 0.15);
    border-radius: 8px;
    padding: 28px 24px;
    width: 100%;
    max-width: 440px;
    text-align: center;
    animation: modalFadeIn .18s ease-out;
}
.spa-modal-spinner-wrap {
    position: relative;
    width: 64px;
    height: 64px;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.spa-modal-spinner {
    position: absolute;
    inset: 0;
    border: 3px solid #21262d;
    border-top-color: #58a6ff;
    border-right-color: #38ef7d;
    border-radius: 50%;
    animation: spaSpin 0.8s linear infinite;
}
.spa-modal-icon {
    font-size: 1.7rem;
    position: relative;
    z-index: 2;
    animation: spaIconPulse 1.2s ease-in-out infinite alternate;
}
@keyframes spaSpin { to { transform: rotate(360deg); } }
@keyframes spaIconPulse { from { transform: scale(0.92); } to { transform: scale(1.08); } }
.spa-modal-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: #f0f6fc;
    margin-bottom: 6px;
}
.spa-modal-sub {
    font-size: 0.86rem;
    color: #8b949e;
    line-height: 1.45;
    margin-bottom: 16px;
}
.spa-modal-status-bar {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #161b22;
    border: 1px solid #21262d;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.78rem;
    color: #58a6ff;
    font-family: ui-monospace, SFMono-Regular, monospace;
}
.spa-pulse-dot {
    width: 8px;
    height: 8px;
    background: #38ef7d;
    border-radius: 50%;
    box-shadow: 0 0 6px #38ef7d;
    animation: dotPulse 1s infinite alternate;
}
@keyframes dotPulse { from { opacity: 0.4; } to { opacity: 1; } }
.spa-fade-in { animation: fadeIn 0.15s ease-out; }

/* Mobile Hamburger Toggle */
.mobile-nav-toggle {
    display: none;
    background: transparent;
    border: 1px solid #30363d;
    padding: 6px 8px;
    border-radius: 4px;
    cursor: pointer;
    margin-left: auto;
    flex-direction: column;
    gap: 4px;
    align-items: center;
    justify-content: center;
}
.mobile-nav-toggle .burger-bar {
    display: block;
    width: 18px;
    height: 2px;
    background: #c9d1d9;
    border-radius: 1px;
    transition: all 0.2s ease;
}
.mobile-nav-toggle:hover {
    background: #161b22;
    border-color: #58a6ff;
}

/* Responsive Overhauls */
/* CPU Multi-Core Monitor & Processes Styling */
.cpu-core-box {
    background: #11171f;
    border-radius: 5px;
    transition: transform 0.15s ease, border-color 0.15s ease;
}
.cpu-core-box:hover {
    transform: translateY(-1px);
    border-color: #388bfd !important;
}
.cpu-cores-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 10px;
}
.processes-card tr:hover td {
    background: rgba(255, 255, 255, 0.03);
}

@media (max-width: 900px) {
    header {
        padding: 8px 14px;
    }
    .mobile-nav-toggle {
        display: flex;
    }
    header .brand {
        flex: 1;
        border-right: 0;
        padding-right: 0;
    }
    header nav {
        display: none;
        width: 100%;
        flex-direction: column;
        align-items: stretch;
        gap: 2px;
        order: 3;
        background: #0d1117;
        border-top: 1px solid #21262d;
        padding-top: 8px;
        margin-top: 4px;
    }
    header nav.open {
        display: flex;
    }
    header nav a {
        padding: 8px 12px;
        border-bottom: 0;
        border-left: 3px solid transparent;
        border-radius: 4px;
    }
    header nav a.on {
        border-left-color: #1f6feb;
        background: rgba(56,139,253,0.12);
    }
    .header-actions {
        width: 100%;
        justify-content: flex-start;
        padding-top: 6px;
        border-top: 1px solid #1c2128;
        order: 2;
        overflow-x: auto;
    }
}

@media (max-width: 768px) {
    main, .main-container {
        padding: 12px 10px !important;
    }
    .hero-device {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    .quick-device-controls {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 6px;
    }
    .quick-device-controls form, .quick-device-controls button, .quick-device-controls a {
        width: 100%;
        justify-content: center;
        text-align: center;
    }
    .grid3, .grid2 {
        grid-template-columns: 1fr;
    }
    .card, .panel {
        padding: 12px 14px;
    }
    table {
        display: block;
        width: 100%;
        overflow-x: auto;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
    }
    th, td {
        padding: 8px 10px;
    }
    .card-head {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    .btn, button, .btn-ctrl, .btn-pri {
        min-height: 36px;
        padding: 6px 12px;
    }
    .modal-dialog {
        max-width: 96vw;
        max-height: 94vh;
    }
}

@media (max-width: 480px) {
    .telemetry {
        flex-direction: column;
        gap: 6px;
    }
    .quick-device-controls {
        grid-template-columns: 1fr;
    }
}

/* Site table row drag & drop auto-update styles */
tr.site-row {
    transition: background 0.15s ease, outline 0.15s ease;
}
tr.site-row.dragover {
    background: #11261d !important;
    outline: 2px dashed #2ea043 !important;
    outline-offset: -2px;
}
tr.site-row.dragover td {
    color: #7ee787 !important;
}
.row-drop-badge {
    display: inline-block;
    background: #238636;
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 6px;
    animation: rowPulse 1s infinite alternate;
}
@keyframes rowPulse { from { opacity: 0.7; transform: scale(0.98); } to { opacity: 1; transform: scale(1); } }

.btn-reupload {
    background: #13233a;
    border: 1px solid #1f6feb;
    color: #79c0ff;
    font-size: 0.82rem;
    font-weight: 600;
    padding: 5px 10px;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-decoration: none;
    transition: all .15s ease;
}
.btn-reupload:hover {
    background: #1f6feb;
    color: #ffffff;
    border-color: #58a6ff;
}

/* Stack Logs Viewer & Formatter (Zero-Library) */
.lv-root {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.lv-topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    background: #0d1117;
    border: 1px solid #21262d;
    border-radius: 6px;
    padding: 10px 12px;
}
.lv-src-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 240px;
    flex-wrap: wrap;
}
.lv-src-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #f0f6fc;
    white-space: nowrap;
}
.lv-src-select {
    flex: 1;
    min-width: 220px;
    max-width: 480px;
    background: #050a10;
    border: 1px solid #388bfd;
    color: #79c0ff;
    font-weight: 600;
    font-size: 0.84rem;
    padding: 5px 10px;
    border-radius: 5px;
    cursor: pointer;
}
.lv-ctrl-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.lv-line-select {
    background: #050a10;
    border: 1px solid #30363d;
    color: #c9d1d9;
    font-size: 0.8rem;
    padding: 4px 8px;
    border-radius: 4px;
}
.lv-btn {
    font-size: 0.78rem;
    padding: 4px 10px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-decoration: none;
}
.lv-live-active {
    background: #12281e !important;
    border-color: #2ea043 !important;
    color: #3fb950 !important;
}
.lv-infostrip {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 0.78rem;
    color: #8b949e;
    padding: 2px 4px;
}
.lv-info-left, .lv-info-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.lv-path-code {
    font-size: 0.76rem;
    color: #79c0ff;
    background: #050a10;
    padding: 2px 6px;
    border: 1px solid #21262d;
    border-radius: 4px;
}
.lv-fmt-pill {
    background: #13233a;
    color: #79c0ff;
    border-color: #1f6feb;
    font-size: 0.68rem;
}
.lv-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    background: #090d13;
    border: 1px solid #21262d;
    border-radius: 6px;
    padding: 8px 10px;
}
.lv-filter-pills {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.lv-pill {
    background: #161b22;
    color: #8b949e;
    border: 1px solid #30363d;
    border-radius: 14px;
    padding: 3px 10px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.12s ease;
}
.lv-pill:hover {
    color: #f0f6fc;
    border-color: #58a6ff;
}
.lv-pill.active {
    background: #1f6feb;
    color: #ffffff;
    border-color: #388bfd;
}
.lv-pill-error.active { background: #da3633; border-color: #f85149; }
.lv-pill-warn.active { background: #9e6a03; border-color: #d29922; }
.lv-pill-info.active { background: #1f6feb; border-color: #58a6ff; }
.lv-pill-debug.active { background: #6e40c9; border-color: #a371f7; }
.lv-pill-cnt {
    background: rgba(0, 0, 0, 0.35);
    padding: 0 5px;
    border-radius: 10px;
    font-size: 0.7rem;
}
.lv-search {
    flex: 1;
    min-width: 180px;
    max-width: 340px;
    background: #050a10;
    border: 1px solid #30363d;
    color: #f0f6fc;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 0.8rem;
}
.lv-right-controls {
    display: flex;
    gap: 6px;
    align-items: center;
}
.lv-list {
    background: #050a10;
    border: 1px solid #21262d;
    border-radius: 6px;
    max-height: 62vh;
    overflow-y: auto;
    font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
    font-size: 0.8rem;
}
.lv-raw-pre {
    background: #050a10;
    color: #c9d1d9;
    border: 1px solid #21262d;
    border-radius: 6px;
    max-height: 62vh;
    overflow-y: auto;
    padding: 12px;
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}
.lv-entry {
    border-bottom: 1px solid #131922;
    padding: 6px 10px;
    border-left: 3px solid transparent;
    transition: background 0.1s ease;
}
.lv-entry:hover {
    background: #0d1520;
}
.lv-entry.lv-lvl-error { border-left-color: #f85149; background: rgba(248, 81, 73, 0.04); }
.lv-entry.lv-lvl-warn { border-left-color: #d29922; background: rgba(210, 153, 34, 0.04); }
.lv-entry.lv-lvl-ok { border-left-color: #3fb950; }
.lv-entry.lv-lvl-info { border-left-color: #388bfd; }
.lv-entry.lv-lvl-debug { border-left-color: #8957e5; }
.lv-entry-section {
    background: #0e1928 !important;
    border-left-color: #58a6ff !important;
}
.lv-entry-head {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
    line-height: 1.45;
}
.lv-lnum {
    color: #484f58;
    font-size: 0.72rem;
    min-width: 28px;
    text-align: right;
    user-select: none;
}
.lv-ts {
    color: #8b949e;
    font-size: 0.75rem;
    white-space: nowrap;
}
.lv-badge {
    font-size: 0.68rem;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 3px;
    text-transform: uppercase;
    white-space: nowrap;
    letter-spacing: 0.02em;
}
.lv-badge-error { background: #3c1618; color: #ff7b72; border: 1px solid #da363366; }
.lv-badge-warn { background: #33240a; color: #e3b341; border: 1px solid #d2992266; }
.lv-badge-ok { background: #12281e; color: #56d364; border: 1px solid #23863666; }
.lv-badge-info { background: #13233a; color: #79c0ff; border: 1px solid #1f6feb66; }
.lv-badge-debug { background: #26193d; color: #d2a8ff; border: 1px solid #8957e566; }
.lv-method-get { background: #12281e; color: #56d364; border: 1px solid #23863666; }
.lv-method-post { background: #13233a; color: #79c0ff; border: 1px solid #1f6feb66; }
.lv-method-put, .lv-method-patch { background: #33240a; color: #e3b341; border: 1px solid #d2992266; }
.lv-method-delete { background: #3c1618; color: #ff7b72; border: 1px solid #da363366; }
.lv-tag {
    color: #a5d6ff;
    background: #111923;
    border: 1px solid #21262d;
    padding: 0 5px;
    border-radius: 3px;
    font-size: 0.73rem;
    white-space: nowrap;
}
.lv-msg {
    color: #e6edf3;
    word-break: break-word;
    flex: 1;
    min-width: 180px;
}
.lv-entry-toggles {
    margin-left: auto;
}
.lv-toggle-btn {
    background: #161b22;
    color: #79c0ff;
    border: 1px solid #30363d;
    border-radius: 4px;
    padding: 1px 7px;
    font-size: 0.72rem;
    cursor: pointer;
    white-space: nowrap;
}
.lv-toggle-btn:hover {
    background: #1f2937;
    border-color: #58a6ff;
}
.lv-entry-details {
    margin-top: 6px;
    margin-left: 36px;
    padding: 8px 10px;
    background: #0a1018;
    border: 1px solid #1f2937;
    border-radius: 5px;
}
.lv-meta-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.lv-meta-chip {
    background: #111720;
    border: 1px solid #21262d;
    border-radius: 4px;
    padding: 2px 7px;
    font-size: 0.74rem;
    display: inline-flex;
    gap: 4px;
}
.lv-meta-k { color: #8b949e; }
.lv-meta-v { color: #a5d6ff; word-break: break-all; }
.lv-json-block {
    background: #060a0f;
    color: #7ee787;
    border: 1px solid #1c2128;
    border-radius: 4px;
    padding: 8px;
    margin: 6px 0 0 0;
    font-size: 0.75rem;
    max-height: 240px;
    overflow: auto;
    white-space: pre-wrap;
}
.lv-trace-block {
    margin-top: 6px;
    max-height: 260px;
    overflow-y: auto;
    font-size: 0.74rem;
    line-height: 1.45;
}
.lv-trace-line {
    padding: 1px 4px;
    border-radius: 2px;
    word-break: break-all;
}
.lv-trace-line:hover { background: #121b26; }
.lv-trace-idx { color: #79c0ff; font-weight: 600; }
.lv-trace-file { color: #e3b341; }
.lv-trace-call { color: #c9d1d9; }
.lv-loading, .lv-empty, .lv-error {
    padding: 24px 16px;
    text-align: center;
    color: #8b949e;
    font-size: 0.85rem;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.lv-error {
    color: #ff7b72;
    background: rgba(248, 81, 73, 0.08);
}
.lv-modal-dialog {
    max-width: 1080px;
    width: 96vw;
}
.lv-modal-head-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
.lv-modal-body {
    padding: 14px 16px;
}

footer { text-align: center; color: #484f58; font-size: 0.78rem; padding: 16px 20px; border-top: 1px solid #21262d; margin-top: auto; }
CSS;
}

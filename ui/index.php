<?php
// WTC Pi Device & Web Management UI (Default landing page on Port 80 & Port 4485).
// Runs as user wtc-ui; all privileged actions execute via `sudo /usr/local/bin/wtc-manage`.
declare(strict_types=1);

const AUTH_FILE = '/etc/wtc-web/ui-auth';
const DROP_DIR = '/mnt/ramdisk/dropbox';
const STAGE_DIR = '/mnt/ramdisk/php-tmp/staging';
const SITES_DIR = '/opt/wtc-web/sites';
const MAX_UPLOAD = 1024 * 1024 * 1024; // 1GB

@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '600');
@ini_set('max_input_time', '600');

require_once __DIR__ . '/components/stacks.php';
require_once __DIR__ . '/components/db_helper.php';

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
    if (is_spa_request()) {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'page' => $page,
            'redirect' => str_starts_with($page, '/') ? $page : ('/?p=' . urlencode($page)),
            'flash' => $flash ? ['type' => $flash[0], 'msg' => $flash[1]] : null
        ]);
        exit;
    }
    header('Location: ' . (str_starts_with($page, '/') ? $page : ('/?p=' . urlencode($page))));
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

function site_url(string $path = '/'): string
{
    $host = site_host();
    $path = '/' . ltrim($path, '/');
    return 'http://' . $host . $path;
}

function site_port_url(int $port = 8080, string $path = '/'): string
{
    $host = site_host();
    $path = '/' . ltrim($path, '/');
    return 'http://' . $host . ':' . $port . $path;
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

/* ------------------------------------------------------------ actions (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) {
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
            valid_site($site) ? backend_msg(wtc(['--build', $site]), "Background build job started for '$site' (npm run build)") : flash('err', 'Bad site name');
            go($back);
        case 'rollback':
            valid_site($site) ? backend_msg(wtc(['--rollback', $site]), "Rolled back '$site'") : flash('err', 'Bad site name');
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
                flash('err', 'Use a .tar.gz / .tgz / .tar / .zip archive under 1GB');
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
                flash('err', 'Could not save archive into RAM tmpfs');
                go($back);
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
            $siteCustom = trim((string) ($_POST['site'] ?? ''));
            $detectedStack = (string) ($_POST['detected_stack'] ?? '');
            $envContent = (string) ($_POST['env_content'] ?? '');
            $deployMode = (string) ($_POST['deploy_mode'] ?? 'auto');

            try {
                $f = $_FILES['archive'] ?? null;
                $files = $_FILES['files'] ?? null;
                $paths = $_POST['paths'] ?? [];

                $siteName = '';
                $stageDir = '';
                $hasIndex = false;

                if ($f && !empty($f['name']) && $f['error'] === UPLOAD_ERR_OK) {
                    $rawName = basename((string) $f['name']);
                    if (!preg_match('/^[A-Za-z0-9._-]+\.(tar\.gz|tgz|tar|zip)$/i', $rawName) || $f['size'] > MAX_UPLOAD) {
                        throw new RuntimeException('Archive must be .tar.gz, .tgz, .tar, or .zip under 1GB');
                    }

                    $siteName = $siteCustom !== '' ? $siteCustom : preg_replace('/(\.tar\.gz|\.tgz|\.tar|\.zip)$/i', '', $rawName);
                    $siteName = strtolower((string) preg_replace('/[^a-z0-9_-]/', '-', $siteName));
                    $siteName = trim($siteName, '-');
                    if (!valid_site($siteName))
                        $siteName = 'site-' . date('Ymd-His');

                    $stageDir = STAGE_DIR . '/' . $siteName;
                    if (!is_dir($stageDir))
                        @mkdir($stageDir, 0775, true);

                    // Unpack directly in RAM tmpfs
                    $destArchive = STAGE_DIR . '/.' . $siteName . '-' . time() . '-' . $rawName;
                    if (!move_uploaded_file($f['tmp_name'], $destArchive)) {
                        throw new RuntimeException('Could not save uploaded archive into RAM staging');
                    }

                    if (preg_match('/\.zip$/i', $rawName)) {
                        $zip = new ZipArchive();
                        if ($zip->open($destArchive) === true) {
                            $zip->extractTo($stageDir);
                            $zip->close();
                        } else {
                            exec('/usr/bin/unzip -qo ' . escapeshellarg($destArchive) . ' -d ' . escapeshellarg($stageDir), $uOut, $uRc);
                            if ($uRc !== 0)
                                throw new RuntimeException('Failed to extract zip archive');
                        }
                    } else {
                        exec('/bin/tar -xf ' . escapeshellarg($destArchive) . ' -C ' . escapeshellarg($stageDir) . ' --no-same-owner --no-same-permissions', $tOut, $tRc);
                        if ($tRc !== 0)
                            throw new RuntimeException('Failed to extract tar archive');
                    }
                    @unlink($destArchive);

                    // Unwrap single top-level directory if present (e.g. zipped folder containing app/, public/, etc.)
                    // Filter out metadata and hidden files from the decision
                    $rawEntries = array_diff(scandir($stageDir) ?: [], ['.', '..']);
                    $meaningfulEntries = array_filter($rawEntries, fn($e) => !in_array($e, ['__MACOSX', '.DS_Store', 'Thumbs.db', 'desktop.ini']));

                    $hasRootEntry = is_file($stageDir . '/index.php') || is_file($stageDir . '/index.html') || is_file($stageDir . '/index.htm') || is_file($stageDir . '/artisan');

                    if (!$hasRootEntry && count($meaningfulEntries) === 1) {
                        $singleChild = $stageDir . '/' . reset($meaningfulEntries);
                        if (is_dir($singleChild)) {
                            // Move everything from the child directory up to stageDir
                            foreach (array_diff(scandir($singleChild) ?: [], ['.', '..']) as $item) {
                                rename($singleChild . '/' . $item, $stageDir . '/' . $item);
                            }
                            @rmdir($singleChild);
                        }
                    }

                    // Clean up any __MACOSX metadata folder
                    if (is_dir($stageDir . '/__MACOSX')) {
                        exec('/bin/rm -rf ' . escapeshellarg($stageDir . '/__MACOSX'));
                    }

                    $hasIndex = is_file($stageDir . '/index.php') || is_file($stageDir . '/index.html') || is_file($stageDir . '/index.htm');

                } elseif ($files && !empty($files['name']) && is_array($files['name']) && count($files['name']) > 0) {
                    // Check if all paths share a common top-level prefix
                    $firstRel = !empty($paths[0]) ? (string) $paths[0] : '';
                    $firstParts = explode('/', str_replace('\\', '/', $firstRel));
                    $detectedPrefix = null;
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

                    $siteName = $siteCustom !== '' ? $siteCustom : ($detectedPrefix ?: 'site-' . date('Ymd-His'));
                    $siteName = strtolower((string) preg_replace('/[^a-z0-9_-]/', '-', $siteName));
                    $siteName = trim($siteName, '-');
                    if (!valid_site($siteName))
                        $siteName = 'site-' . date('Ymd-His');

                    $stageDir = STAGE_DIR . '/' . $siteName;
                    if (!is_dir($stageDir))
                        @mkdir($stageDir, 0775, true);

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
                } else {
                    throw new RuntimeException('No files or folder detected in drop');
                }

                // If .env content was configured by the user, save it
                if ($envContent !== '') {
                    file_put_contents($stageDir . '/.env', $envContent);
                }

                // If Laravel or public/index.php is detected
                if ($detectedStack === 'laravel' || is_file($stageDir . '/public/index.php')) {
                    if (!is_file($stageDir . '/index.php')) {
                        file_put_contents($stageDir . '/index.php', "<?php\n// Forward to Laravel public/ webroot\nrequire_once __DIR__ . '/public/index.php';\n");
                        $hasIndex = true;
                    }
                    if ($envContent === '' && !is_file($stageDir . '/.env')) {
                        $ip = explode(' ', wj('status')['ips'] ?? '')[0] ?? '127.0.0.1';
                        file_put_contents($stageDir . '/.env', generate_laravel_env($siteName, $ip));
                    }
                    // Placeholder shim only when the framework (vendor/) is genuinely absent.
                    // Real bootstrap/app.php is backed up; `artisan` in the UI will composer-install and restore it.
                    if ($deployMode === 'auto_shim' || !is_file($stageDir . '/vendor/autoload.php')) {
                        generate_laravel_shim($stageDir, $siteName);
                    }

                    // If storage was excluded or missing, create standard minimal Laravel storage directory structure
                    if (!is_dir($stageDir . '/storage')) {
                        @mkdir($stageDir . '/storage/framework/views', 0775, true);
                        @mkdir($stageDir . '/storage/framework/sessions', 0775, true);
                        @mkdir($stageDir . '/storage/framework/cache', 0775, true);
                        @mkdir($stageDir . '/storage/logs', 0775, true);
                        @mkdir($stageDir . '/bootstrap/cache', 0775, true);
                    }
                }

                // If React / Vite / SPA detected or build/dist/out directory present
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

                if (!$hasIndex) {
                    file_put_contents($stageDir . '/index.html', "<!doctype html><html><head><meta charset=\"utf-8\"><title>" . h($siteName) . "</title></head><body><h1>" . h($siteName) . "</h1><p>Site loaded directly in RAM.</p></body></html>");
                }

                if ($spinUp) {
                    [$rc, $out, $err] = wtc(['--direct-deploy', $siteName, $stageDir]);
                    if ($rc !== 0)
                        throw new RuntimeException("Backend deployment failed: " . ($err !== '' ? $err : $out));

                    $runMigrations = !empty($_POST['run_migrations']);
                    $runSeeds = !empty($_POST['run_seeds']);
                    $artisanLog = [];

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

                } else {
                    [$rc, $out, $err] = wtc(['--store-site', $siteName, $stageDir]);
                    if ($rc !== 0)
                        throw new RuntimeException("Failed to store site package: " . ($err !== '' ? $err : $out));
                }

                if ($isAjax) {
                    $dbInfo = json_decode(wtc(['--db-info', $siteName])[1] ?? '', true) ?: [];
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok' => true,
                        'site' => $siteName,
                        'spun_up' => $spinUp,
                        'url' => site_url('/'),
                        'db' => $dbInfo,
                        'artisan_log' => $artisanLog ?? []
                    ]);
                    exit;
                }
                $artisanSummary = '';
                if (!empty($artisanLog)) {
                    $artisanSummary = ' (' . implode(', ', array_map(fn($a) => $a['command'] . ': ' . ($a['ok'] ? 'OK' : 'Error'), $artisanLog)) . ')';
                }
                flash('ok', "Site '$siteName' uploaded" . ($spinUp ? " and active in RAM!" : "") . $artisanSummary);
                go($back);

            } catch (\Throwable $e) {
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
            $cmd = (string) ($_POST['cmd'] ?? '');
            $runMigrations = !empty($_POST['run_migrations']);
            $runSeeds = !empty($_POST['run_seeds']);
            $runFresh = !empty($_POST['run_fresh']);
            if (!valid_site($site)) {
                flash('err', "Invalid site name: '$site'. Please ensure a site is currently active in RAM.");
                go($back);
            }

            $cmds = [];
            if ($runFresh) {
                $cmds[] = $runSeeds ? 'migrate:fresh --seed --force' : 'migrate:fresh --force';
            } else {
                if ($runMigrations)
                    $cmds[] = 'migrate --force';
                if ($runSeeds)
                    $cmds[] = 'db:seed --force';
            }
            if ($cmd !== '' && empty($cmds))
                $cmds[] = $cmd;

            if (empty($cmds)) {
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
    }
    go('dashboard');
}

/* ------------------------------------------------------------ download */
if (($_GET['download'] ?? '') !== '' && valid_site((string) $_GET['download'])) {
    $path = SITES_DIR . '/' . $_GET['download'] . '.tar.gz';
    if (is_file($path)) {
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $_GET['download'] . '.tar.gz"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
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
if (($_GET['action'] ?? '') === 'sysinfo') {
    header('Content-Type: application/json');
    [$rc, $out, $err] = wtc(['--json', 'sysinfo']);
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
    if (!is_array($data) || empty($data)) {
        // Fallback for dev workstations
        $data = [
            ['pid' => 540, 'user' => 'root', 'cpu' => 1.2, 'mem' => 0.4, 'stat' => 'Ss', 'comm' => 'nginx', 'cmd' => 'nginx: master process /usr/sbin/nginx'],
            ['pid' => 542, 'user' => 'www-data', 'cpu' => 3.5, 'mem' => 1.8, 'stat' => 'S', 'comm' => 'php-fpm', 'cmd' => 'php-fpm: pool www'],
            ['pid' => 610, 'user' => 'mysql', 'cpu' => 0.8, 'mem' => 4.2, 'stat' => 'Sl', 'comm' => 'mariadbd', 'cmd' => '/usr/sbin/mariadbd'],
            ['pid' => 840, 'user' => 'root', 'cpu' => 0.1, 'mem' => 0.2, 'stat' => 'Ss', 'comm' => 'smbd', 'cmd' => '/usr/sbin/smbd -F --no-process-group'],
            ['pid' => 912, 'user' => 'wtc-ui', 'cpu' => 0.4, 'mem' => 0.9, 'stat' => 'S', 'comm' => 'php', 'cmd' => 'php /usr/local/bin/wtc-workers']
        ];
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
        // Fallback for development workstations
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $mockReqs = [
            ['ip' => $clientIp, 'time' => date('d/M/Y:H:i:s O'), 'method' => 'GET', 'path' => '/?p=dashboard', 'status' => '200', 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'Browser'],
            ['ip' => '192.168.1.115', 'time' => date('d/M/Y:H:i:s O', time() - 25), 'method' => 'GET', 'path' => '/site/', 'status' => '200', 'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'],
            ['ip' => '192.168.1.115', 'time' => date('d/M/Y:H:i:s O', time() - 22), 'method' => 'GET', 'path' => '/site/api/users', 'status' => '200', 'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'],
            ['ip' => '192.168.1.102', 'time' => date('d/M/Y:H:i:s O', time() - 60), 'method' => 'POST', 'path' => '/site/login', 'status' => '302', 'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'],
            ['ip' => $clientIp, 'time' => date('d/M/Y:H:i:s O', time() - 90), 'method' => 'GET', 'path' => '/?action=sysinfo', 'status' => '200', 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'Browser'],
            ['ip' => '192.168.1.115', 'time' => date('d/M/Y:H:i:s O', time() - 110), 'method' => 'GET', 'path' => '/site/css/app.css', 'status' => '200', 'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)']
        ];
        $data = [
            'host' => gethostname() ?: 'pi',
            'gateway' => '192.168.1.1',
            'client_ip' => $clientIp,
            'ifaces' => [
                ['iface' => 'eth0', 'ip' => '192.168.1.50'],
                ['iface' => 'wlan0', 'ip' => '192.168.1.150']
            ],
            'listeners' => [
                ['port' => 80, 'proc' => 'nginx'],
                ['port' => 8080, 'proc' => 'nginx'],
                ['port' => 4485, 'proc' => 'nginx'],
                ['port' => 3306, 'proc' => 'mariadbd'],
                ['port' => 22, 'proc' => 'sshd'],
                ['port' => 445, 'proc' => 'smbd']
            ],
            'requests' => array_slice($mockReqs, 0, $limit)
        ];
    } else {
        $data['client_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    echo json_encode($data);
    exit;
}

/* ------------------------------------------------------------ routing & components */
$page = (string) ($_GET['p'] ?? 'dashboard');
$pages = [
    'dashboard' => '🖥️ Device Dashboard',
    'sites' => '📁 Sites & Apps',
    'database' => '🗄️ Database',
    'explorer' => '⚡ RAM Drive',
    'nginx' => '🌐 Nginx Config',
    'state' => '💾 Save State',
    'transfer' => '🔄 File Transfer',
    'firewall' => '🛡️ Firewall & Ports',
    'stack' => '📦 Stack Components',
    'settings' => '⚙️ Settings',
    'logs' => '📜 Logs'
];
if (!isset($pages[$page]))
    $page = 'dashboard';
$refresh = ($page === 'stack' && !empty(wj('stack')['busy'])) ? 3 : 0;

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

footer { text-align: center; color: #484f58; font-size: 0.78rem; padding: 16px 20px; border-top: 1px solid #21262d; margin-top: auto; }
CSS;
}

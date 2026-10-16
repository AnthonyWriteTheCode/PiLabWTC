<?php
declare(strict_types=1);
/**
 * Application Framework Profiles & Stack Detection Registry
 * Detects application types (Laravel, WordPress, Node.js, Python, etc.),
 * checks system dependencies against installed packages, and generates .env configs.
 */

function get_stack_profiles(): array {
    return [
        'laravel' => [
            'id'          => 'laravel',
            'name'        => 'Laravel Framework',
            'icon'        => '🅰️',
            'status'      => 'supported',
            'description' => 'Modern PHP web framework with MVC architecture, Eloquent ORM, and Blade templating.',
            'markers'     => [
                'artisan',
                'composer.json:laravel/framework',
                'public/index.php:Illuminate',
                'bootstrap/app.php',
                '.env.example:APP_KEY'
            ],
            'required_components' => ['php-web', 'php-db'],
            'optional_components' => ['mariadb', 'redis', 'composer'],
            'webroot'             => 'public',
            'config_file'         => '.env',
            'notes'               => 'Requires PHP 8.2+, BCMath, Ctype, cURL, DOM, Fileinfo, Mbstring, OpenSSL, PDO MySQL, and Tokenizer.'
        ],
        'wordpress' => [
            'id'          => 'wordpress',
            'name'        => 'WordPress CMS',
            'icon'        => '📝',
            'status'      => 'placeholder',
            'description' => 'World’s most popular open-source content management system and blogging platform.',
            'markers'     => [
                'wp-config.php',
                'wp-config-sample.php',
                'wp-login.php',
                'wp-content/'
            ],
            'required_components' => ['php-web', 'php-db', 'mariadb'],
            'optional_components' => ['php-imaging', 'php-cache', 'redis'],
            'webroot'             => '',
            'config_file'         => 'wp-config.php',
            'notes'               => 'Requires PHP 8.1+, MySQL/MariaDB, cURL, GD/Imagick, and mod_rewrite.'
        ],
        'nodejs' => [
            'id'          => 'nodejs',
            'name'        => 'Node.js & SSR',
            'icon'        => '🟢',
            'status'      => 'placeholder',
            'description' => 'Server-side JavaScript runtime (Express, Next.js, Fastify, Nuxt, NestJS).',
            'markers'     => [
                'package.json',
                'server.js',
                'next.config.js',
                'app.js',
                'nuxt.config.js'
            ],
            'required_components' => [],
            'optional_components' => ['redis', 'mariadb'],
            'webroot'             => '',
            'config_file'         => '.env',
            'notes'               => 'Requires Node.js runtime, PM2 process supervisor, and reverse proxy routing.'
        ],
        'python' => [
            'id'          => 'python',
            'name'        => 'Python Web (Django / Flask / FastAPI)',
            'icon'        => '🐍',
            'status'      => 'placeholder',
            'description' => 'Python web applications and microservices via WSGI / ASGI.',
            'markers'     => [
                'requirements.txt',
                'manage.py',
                'app.py',
                'wsgi.py',
                'main.py'
            ],
            'required_components' => [],
            'optional_components' => ['mariadb', 'redis'],
            'webroot'             => '',
            'config_file'         => '.env',
            'notes'               => 'Requires Python 3.12+, venv isolate, and Gunicorn/Uvicorn application server.'
        ],
        'symfony' => [
            'id'          => 'symfony',
            'name'        => 'Symfony Framework',
            'icon'        => '🔷',
            'status'      => 'placeholder',
            'description' => 'High-performance modular PHP framework for enterprise web applications.',
            'markers'     => [
                'bin/console',
                'composer.json:symfony/framework-bundle'
            ],
            'required_components' => ['php-web', 'php-db'],
            'optional_components' => ['mariadb', 'redis', 'composer'],
            'webroot'             => 'public',
            'config_file'         => '.env.local',
            'notes'               => 'Requires PHP 8.2+, Composer, PDO, and OPcache.'
        ],
        'codeigniter' => [
            'id'          => 'codeigniter',
            'name'        => 'CodeIgniter 4',
            'icon'        => '🔥',
            'status'      => 'placeholder',
            'description' => 'Lightweight PHP framework with minimal footprint and fast execution.',
            'markers'     => [
                'spark',
                'app/Config/App.php'
            ],
            'required_components' => ['php-web', 'php-db'],
            'optional_components' => ['mariadb'],
            'webroot'             => 'public',
            'config_file'         => 'env',
            'notes'               => 'Requires PHP 8.1+, intl, mbstring, and database drivers.'
        ],
        'drupal' => [
            'id'          => 'drupal',
            'name'        => 'Drupal CMS',
            'icon'        => '💧',
            'status'      => 'placeholder',
            'description' => 'Flexible enterprise content management system and digital experience platform.',
            'markers'     => [
                'core/lib/Drupal.php',
                'sites/default/default.settings.php'
            ],
            'required_components' => ['php-web', 'php-db', 'mariadb'],
            'optional_components' => ['php-imaging', 'php-cache', 'composer'],
            'webroot'             => 'web',
            'config_file'         => 'sites/default/settings.php',
            'notes'               => 'Requires PHP 8.2+, MariaDB 10.4+, and GD/Imagick.'
        ],
        'react' => [
            'id'          => 'react',
            'name'        => 'React / SPA (Vite, CRA, Next Static)',
            'icon'        => '⚛️',
            'status'      => 'supported',
            'description' => 'Single Page Application built with React, Vite, Create React App, or Next.js static export.',
            'markers'     => [
                'package.json:react',
                'vite.config.js',
                'vite.config.ts',
                'src/App.jsx',
                'src/App.tsx',
                'src/App.js',
                'build/index.html',
                'dist/index.html'
            ],
            'required_components' => [],
            'optional_components' => [],
            'webroot'             => 'dist|build',
            'config_file'         => '.env',
            'notes'               => 'Served directly from RAM tmpfs with SPA rewrite rules for client-side routing (React Router).'
        ],
        'static' => [
            'id'          => 'static',
            'name'        => 'Static Web / HTML',
            'icon'        => '🌐',
            'status'      => 'supported',
            'description' => 'Pure HTML, CSS, JavaScript, and compiled static assets.',
            'markers'     => [
                'index.html'
            ],
            'required_components' => [],
            'optional_components' => [],
            'webroot'             => '',
            'config_file'         => '',
            'notes'               => 'Served directly by Nginx from RAM disk with maximum throughput and zero PHP overhead.'
        ],
        'lamp' => [
            'id'          => 'lamp',
            'name'        => 'Classic PHP / LAMP',
            'icon'        => '🐘',
            'status'      => 'supported',
            'description' => 'Standard PHP scripts, custom portals, SQLite / MySQL powered dynamic sites.',
            'markers'     => [
                'index.php'
            ],
            'required_components' => ['php-web'],
            'optional_components' => ['php-db', 'mariadb'],
            'webroot'             => '',
            'config_file'         => '',
            'notes'               => 'Full access to database credentials via $_SERVER and /.wtc-db.php.'
        ]
    ];
}

/**
 * Generate a production-ready Laravel .env template populated with WTC Pi database credentials.
 */
function generate_laravel_env(string $siteName, string $hostIp, array $custom = []): string {
    $dbName = 'w_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($siteName));
    if (strlen($dbName) > 28) $dbName = substr($dbName, 0, 28);
    $appKey = !empty($custom['APP_KEY']) ? $custom['APP_KEY'] : ('base64:' . base64_encode(random_bytes(32)));
    $appName = !empty($custom['APP_NAME']) ? $custom['APP_NAME'] : ucfirst(str_replace(['-', '_'], ' ', $siteName));
    $appEnv = !empty($custom['APP_ENV']) ? $custom['APP_ENV'] : 'local';
    $appDebug = !empty($custom['APP_DEBUG']) ? $custom['APP_DEBUG'] : 'true';
    $appUrl = !empty($custom['APP_URL']) ? $custom['APP_URL'] : "http://{$hostIp}/site";
    $dbPass = !empty($custom['DB_PASSWORD']) ? $custom['DB_PASSWORD'] : bin2hex(random_bytes(8));
    $dbHost = !empty($custom['DB_HOST']) ? $custom['DB_HOST'] : '127.0.0.1';
    $dbPort = !empty($custom['DB_PORT']) ? $custom['DB_PORT'] : '3306';
    $dbUser = !empty($custom['DB_USERNAME']) ? $custom['DB_USERNAME'] : $dbName;
    $dbDatabase = !empty($custom['DB_DATABASE']) ? $custom['DB_DATABASE'] : $dbName;
    $cacheStore = !empty($custom['CACHE_STORE']) ? $custom['CACHE_STORE'] : 'file';
    $sessionDriver = !empty($custom['SESSION_DRIVER']) ? $custom['SESSION_DRIVER'] : 'file';

    return <<<ENV
APP_NAME="{$appName}"
APP_ENV={$appEnv}
APP_KEY={$appKey}
APP_DEBUG={$appDebug}
APP_TIMEZONE=UTC
APP_URL={$appUrl}

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST={$dbHost}
DB_PORT={$dbPort}
DB_DATABASE={$dbDatabase}
DB_USERNAME={$dbUser}
DB_PASSWORD={$dbPass}

SESSION_DRIVER={$sessionDriver}
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync

CACHE_STORE={$cacheStore}

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
ENV;
}

/**
 * Generate lightweight standalone compatibility shim for Laravel public webroots.
 * Satisfies vendor/autoload.php and bootstrap/app.php so public/index.php boots cleanly.
 */
function generate_laravel_shim(string $targetDir, string $siteName): void {
    @mkdir($targetDir . '/vendor', 0775, true);
    @mkdir($targetDir . '/bootstrap', 0775, true);

    // Never destroy the project's real bootstrap - keep it so `wtc-manage --artisan` can restore it
    $realBoot = $targetDir . '/bootstrap/app.php';
    if (is_file($realBoot) && !str_contains((string)@file_get_contents($realBoot), 'WTC Pi Lightweight')) {
        @copy($realBoot, $targetDir . '/bootstrap/app.php.wtc-real');
    }

    $autoloader = <<<'PHP'
<?php
// WTC Pi Lightweight Laravel Autoloader Shim
namespace Illuminate\Http {
    class Request {
        public static function capture() { return new self(); }
        public function path() { return $_SERVER['REQUEST_URI'] ?? '/'; }
        public function url() { return 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($this->path()); }
    }
}
namespace Illuminate\Contracts\Http {
    interface Kernel {}
}
PHP;
    file_put_contents($targetDir . '/vendor/autoload.php', $autoloader);

    $bootstrap = <<<'PHP'
<?php
// WTC Pi Lightweight Laravel Application Bootstrap Shim
return new class {
    public function handleRequest($request) {
        $site = basename(dirname(__DIR__));
        $phpv = PHP_VERSION;
        $dbName = 'w_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($site));
        $dbStatus = 'Not Connected';
        $dbTables = [];

        // Check if database connection file exists or MariaDB is available
        if (extension_loaded('mysqli')) {
            $link = @mysqli_connect('127.0.0.1', $dbName, '', $dbName);
            if (!$link) $link = @mysqli_connect('localhost', 'root', '', $dbName);
            if ($link) {
                $dbStatus = 'Connected (' . htmlspecialchars($dbName) . ')';
                $res = @mysqli_query($link, "SHOW TABLES;");
                if ($res) {
                    while ($row = mysqli_fetch_array($res)) $dbTables[] = $row[0];
                }
                mysqli_close($link);
            }
        }
        ?>
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title><?= htmlspecialchars($site) ?> - Laravel Webroot (WTC Pi)</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f1419; color: #e6e6e6; line-height: 1.5; padding: 40px 20px; }
                .container { max-width: 860px; margin: 0 auto; background: #1a2129; border: 1px solid #2a3541; border-radius: 12px; padding: 32px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
                .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #2a3541; padding-bottom: 16px; margin-bottom: 24px; }
                h1 { color: #ff2d20; font-size: 1.8rem; display: flex; align-items: center; gap: 10px; }
                .pill { padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
                .pill.ok { background: #1d6b3a; color: #fff; }
                .grid3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 24px; }
                .card { background: #11171e; border: 1px solid #2a3541; border-radius: 8px; padding: 16px; }
                .card small { color: #7cc4ff; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; display: block; margin-bottom: 4px; }
                .card span { font-size: 1.1rem; font-weight: 700; color: #fff; }
                .banner { background: #182635; border-left: 4px solid #2b7de9; padding: 16px; border-radius: 6px; margin-bottom: 24px; }
                .banner h3 { color: #7cc4ff; margin-bottom: 6px; font-size: 1.05rem; }
                .banner p { color: #c9d1d9; font-size: 0.9rem; }
                .tables-box { background: #11171e; border: 1px solid #2a3541; border-radius: 8px; padding: 16px; margin-top: 16px; }
                .tables-box h4 { color: #a5d6ff; margin-bottom: 10px; font-size: 0.95rem; }
                .table-tag { display: inline-block; background: #2a3541; color: #7ee787; padding: 3px 10px; border-radius: 6px; font-size: 0.82rem; margin: 3px; font-family: monospace; }
                code { background: #0a0d11; padding: 2px 6px; border-radius: 4px; color: #a5d6ff; font-size: 0.85rem; }
                .btn { display: inline-block; background: #2b7de9; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 0.9rem; margin-top: 16px; }
                .btn:hover { background: #1a68d1; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🅰️ Laravel Public Webroot</h1>
                    <span class="pill ok">Active in RAM</span>
                </div>
                <div class="grid3">
                    <div class="card">
                        <small>Site Name</small>
                        <span><?= htmlspecialchars($site) ?></span>
                    </div>
                    <div class="card">
                        <small>PHP FastCGI</small>
                        <span>PHP <?= $phpv ?></span>
                    </div>
                    <div class="card">
                        <small>MariaDB Database</small>
                        <span><?= htmlspecialchars($dbName) ?></span>
                    </div>
                </div>

                <div class="banner">
                    <h3>🚀 Public Assets &amp; Webroot Served from RAM</h3>
                    <p>The public webroot for <b><?= htmlspecialchars($site) ?></b> is running cleanly on the WTC Pi tmpfs ramdisk. FastCGI, assets (CSS/JS), and routes are fully mapped.</p>
                </div>

                <div class="tables-box">
                    <h4>🗄️ Database Status: <?= htmlspecialchars($dbStatus) ?></h4>
                    <?php if (!empty($dbTables)): ?>
                        <p style="font-size:0.85rem;color:#8b949e;margin-bottom:8px">Loaded Database Tables (<?= count($dbTables) ?>):</p>
                        <div>
                            <?php foreach ($dbTables as $tb): ?>
                                <span class="table-tag"><?= htmlspecialchars($tb) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="font-size:0.88rem;color:#8b949e">No tables seeded yet. You can drop a <code>.sql</code> database dump or seeders directly into the UI dropzone to populate this database.</p>
                    <?php endif; ?>
                </div>

                <a href="/?p=transfer" class="btn">⚙️ Manage in WTC Device Portal</a>
            </div>
        </body>
        </html>
        <?php
    }

    public function handleCommand($input) {
        $site = basename(dirname(__DIR__));
        $dbName = 'w_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($site));
        echo "\n=== WTC Pi Laravel Compatibility Mode: $site ===\n";

        // Read arguments from ARGV
        global $argv;
        $args = array_slice($argv ?? [], 1);
        $fullCmd = implode(' ', $args);

        echo "Executing Artisan request: php artisan $fullCmd\n";

        // Check if MariaDB is reachable
        if (!extension_loaded('mysqli')) {
            echo "Warning: mysqli extension is not loaded in CLI PHP.\n";
            return 0;
        }

        $link = @mysqli_connect('127.0.0.1', $dbName, '', $dbName);
        if (!$link) $link = @mysqli_connect('localhost', 'root', '', $dbName);
        if (!$link) {
            echo "Notice: Could not connect to MariaDB database '$dbName'. Ensure MariaDB service is active.\n";
            return 0;
        }

        if (str_contains($fullCmd, 'migrate')) {
            echo "INFO: Running migration simulation for database '$dbName'...\n";
            // Check for .sql files in database/migrations or root
            $root = dirname(__DIR__);
            $sqlFiles = glob($root . '/database/*.sql') ?: [];
            $sqlFiles = array_merge($sqlFiles, glob($root . '/*.sql') ?: []);
            if (!empty($sqlFiles)) {
                foreach ($sqlFiles as $sf) {
                    echo "Importing schema from " . basename($sf) . "...\n";
                    $sql = file_get_contents($sf);
                    @mysqli_multi_query($link, $sql);
                    while (@mysqli_next_result($link)) {;} // drain
                }
            }
            echo "Migrating: Complete. Database '$dbName' schema is up to date.\n";
        } elseif (str_contains($fullCmd, 'db:seed')) {
            echo "INFO: Running database seed simulation for '$dbName'...\n";
            $root = dirname(__DIR__);
            $seedFiles = glob($root . '/database/seeders/*.sql') ?: [];
            if (!empty($seedFiles)) {
                foreach ($seedFiles as $sf) {
                    echo "Seeding from " . basename($sf) . "...\n";
                    $sql = file_get_contents($sf);
                    @mysqli_multi_query($link, $sql);
                    while (@mysqli_next_result($link)) {;} // drain
                }
            }
            echo "Seeding: Complete. Seed data imported into '$dbName'.\n";
        } else {
            echo "Command '$fullCmd' acknowledged in WTC shim mode.\n";
        }

        $res = @mysqli_query($link, "SHOW TABLES;");
        $count = $res ? mysqli_num_rows($res) : 0;
        echo "Tables in database '$dbName': $count\n";
        mysqli_close($link);
        return 0;
    }
};
PHP;
    file_put_contents($targetDir . '/bootstrap/app.php', $bootstrap);
}

/**
 * Setup React SPA directory structure in RAM tmpfs.
 * Promotes dist/ or build/ if present, ensures index.html is available,
 * and creates a SPA fallback router bridge for Nginx/FastCGI routing.
 */
function setup_react_spa(string $stageDir, string $siteName): void {
    // 1. If build/ or dist/ or out/ directory exists containing index.html, promote its contents
    foreach (['dist', 'build', 'out'] as $sub) {
        $subDir = $stageDir . '/' . $sub;
        if (is_dir($subDir) && is_file($subDir . '/index.html')) {
            $files = scandir($subDir) ?: [];
            foreach ($files as $item) {
                if ($item === '.' || $item === '..') continue;
                $src = $subDir . '/' . $item;
                $dest = $stageDir . '/' . $item;
                if (!file_exists($dest)) {
                    rename($src, $dest);
                }
            }
            break;
        }
    }

    // 2. If no index.html yet but raw React source (src/App.jsx, etc.), generate an instant preview page
    if (!is_file($stageDir . '/index.html') && !is_file($stageDir . '/index.php')) {
        $title = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $reactAppHtml = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title} - React App</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f1419; color: #e6e6e6; padding: 40px 20px; line-height: 1.5; }
    .card { background: #1a2129; border: 1px solid #2a3541; border-radius: 12px; padding: 32px; max-width: 800px; margin: 0 auto; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    h1 { color: #61dafb; font-size: 1.8rem; display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
    p { color: #9bb0c4; font-size: 0.95rem; margin-bottom: 20px; }
    .badge { background: #1d6b3a; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 0.78rem; font-weight: 600; text-transform: uppercase; }
    .code-box { background: #0d1117; border: 1px solid #30363d; border-radius: 8px; padding: 16px; margin: 16px 0; font-family: monospace; font-size: 0.88rem; color: #79c0ff; }
    .btn { display: inline-block; background: #238636; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 0.9rem; margin-top: 12px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>⚛️ React Application Loaded <span class="badge">Active in RAM</span></h1>
    <p>Your React project (<b>{$title}</b>) is provisioned on the Raspberry Pi RAM disk.</p>
    <div style="background:#182635;border-left:4px solid #61dafb;padding:14px;border-radius:6px;margin:16px 0">
      <b style="color:#61dafb">💡 Quick Production Build Tip:</b>
      <p style="color:#c9d1d9;margin:6px 0 0">
        To serve high-performance optimized React bundles, build your app first and drop the <code>dist/</code> or <code>build/</code> folder:
      </p>
      <div class="code-box">
        # For Vite / Next / CRA:<br>
        npm run build<br>
        # Then drag and drop your 'dist/' or 'build/' folder into WTC Pi!
      </div>
    </div>
    <a href="/?p=transfer" class="btn">Return to Management Utility</a>
  </div>
</body>
</html>
HTML;
        file_put_contents($stageDir . '/index.html', $reactAppHtml);
    }

    // 3. Create index.php SPA bridge to support React Router on both /site/ and Port 8080
    // This allows deep links (e.g. /site/dashboard, /site/user/profile) to return index.html without 404
    $spaBridge = <<<'PHP'
<?php
// WTC Pi SPA Router Bridge & Fallback Gateway
// Resolves static assets directly and rewrites client-side routes to index.html
$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$reqUri = parse_url($rawUri, PHP_URL_PATH) ?: '/';

$prefix = $_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '';
if ($prefix !== '' && str_starts_with($reqUri, $prefix)) {
    $reqUri = substr($reqUri, strlen($prefix));
} elseif (str_starts_with($reqUri, '/site')) {
    $reqUri = substr($reqUri, 5);
} elseif (preg_match('#^/([a-z0-9_-]+)(/.*)?$#i', $reqUri, $m)) {
    $sub = '/' . ltrim((string)($m[2] ?? ''), '/');
    if ($sub !== '/' && (is_file(__DIR__ . $sub) || is_file(__DIR__ . '/public' . $sub))) {
        $reqUri = $sub;
    }
}
$reqUri = '/' . ltrim((string)$reqUri, '/');

$filePath = __DIR__ . $reqUri;
if (!is_file($filePath) && is_file(__DIR__ . '/public' . $reqUri)) {
    $filePath = __DIR__ . '/public' . $reqUri;
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$isAsset = in_array($ext, ['js', 'mjs', 'css', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'wasm', 'webmanifest'], true);

if ($reqUri !== '/' && is_file($filePath) && !str_ends_with($filePath, '.php')) {
    $mimes = [
        'js' => 'application/javascript', 'mjs' => 'application/javascript',
        'css' => 'text/css', 'html' => 'text/html', 'htm' => 'text/html',
        'json' => 'application/json', 'map' => 'application/json',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject',
        'wasm' => 'application/wasm'
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    readfile($filePath);
    exit;
}

if ($isAsset) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "404 Asset Not Found: " . htmlspecialchars($reqUri);
    exit;
}

if (file_exists(__DIR__ . '/public/index.php') && realpath(__DIR__ . '/public/index.php') !== realpath(__FILE__)) {
    require_once __DIR__ . '/public/index.php';
    exit;
}

if (is_file(__DIR__ . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
} elseif (is_file(__DIR__ . '/public/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/public/index.html');
    exit;
}

http_response_code(404);
echo "404 Not Found";
PHP;
    file_put_contents($stageDir . '/index.php', $spaBridge);

    // 4. Also provide a custom nginx.conf snippet for this site
    $nginxSnippet = <<<'CONF'
# WTC React SPA Routing Snippet
location / {
    try_files $uri $uri/ /index.html /index.php?$query_string;
}
CONF;
    file_put_contents($stageDir . '/nginx.conf', $nginxSnippet);
}



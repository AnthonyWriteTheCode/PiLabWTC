#!/usr/bin/env bash
# update.sh - Apply latest WTC scripts, binaries, and Web UI directly to the Pi
# Run on the Pi: sudo ./update.sh

set -Eeuo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: Must be run as root (e.g. sudo ./update.sh)" >&2
  exit 1
fi

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "==> Updating WTC Pi Web Server from $SRC"

# 1. Install libraries and binaries
install -d /usr/local/lib/wtc-web /usr/local/bin /opt/wtc-web/sites /opt/wtc-web/ui
install -m 0644 "$SRC/wtc-common.sh"       /usr/local/lib/wtc-web/common.sh
install -m 0755 "$SRC/03-site-service.sh"  /usr/local/bin/wtc-site-service
install -m 0755 "$SRC/02-manage-site.sh"   /usr/local/bin/wtc-manage
install -m 0755 "$SRC/wtc-workers"         /usr/local/bin/wtc-workers
install -m 0755 "$SRC/wtc-network"         /usr/local/bin/wtc-network

# 2. Update UI files
echo "==> Updating Web Management UI in /opt/wtc-web/ui"
cp -r "$SRC/ui/"* /opt/wtc-web/ui/
chmod -R u=rwX,go=rX /opt/wtc-web/ui

# 3. Ensure staging & spooling directories exist with proper permissions on physical disk & RAM
mkdir -p /var/tmp/wtc-staging /var/tmp/nginx-body /mnt/ramdisk/php-tmp /mnt/ramdisk/dropbox /mnt/ramdisk/rejected
chmod 1777 /var/tmp/wtc-staging /var/tmp/nginx-body /mnt/ramdisk/php-tmp 2>/dev/null || true
chown -R www-data:www-data /var/tmp/nginx-body /var/tmp/wtc-staging 2>/dev/null || true

# 4. Ensure /etc/nginx/mime.types includes mjs and wasm for ES modules & Vite
if [ -f /etc/nginx/mime.types ]; then
  if ! grep -q " mjs;" /etc/nginx/mime.types; then
    sed -i 's/application\/javascript[ \t]*js;/application\/javascript js mjs;/' /etc/nginx/mime.types 2>/dev/null || true
  fi
  if ! grep -q "text/javascript" /etc/nginx/mime.types; then
    sed -i '/application\/javascript/a \    text\/javascript                         js mjs;' /etc/nginx/mime.types 2>/dev/null || true
  fi
  if ! grep -q "application/wasm" /etc/nginx/mime.types; then
    sed -i '/types {/a \    application\/wasm                        wasm;' /etc/nginx/mime.types 2>/dev/null || true
  fi
fi

# 5. Configure PHP-FPM pools: single-thread UI and dedicated multi-worker live site pool
echo "==> Configuring PHP-FPM pools"
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo '8.2')"
mkdir -p "/etc/php/$PHPV/fpm/pool.d"

# Ensure wtc-ui pool exists with single worker
cat > "/etc/php/$PHPV/fpm/pool.d/wtc-ui.conf" <<EOF
[wtc-ui]
user = wtc-ui
group = wtc-ui
listen = /run/php/wtc-ui.sock
listen.owner = www-data
listen.group = www-data
pm = static
pm.max_children = 1
php_admin_value[session.save_path] = /mnt/ramdisk/php-tmp
php_admin_value[upload_tmp_dir] = /var/tmp
php_admin_value[upload_max_filesize] = 10240M
php_admin_value[post_max_size] = 10240M
php_admin_value[max_execution_time] = 3600
php_admin_value[max_input_time] = 3600
php_admin_value[memory_limit] = 2048M
php_admin_value[max_file_uploads] = 50000
php_admin_value[max_input_vars] = 50000
php_admin_value[open_basedir] = /opt/wtc-web:/mnt/ramdisk:/var/lib/wtc-web:/etc/wtc-web:/etc/nginx:/etc/php:/etc/ssl/wtc-ui:/etc/os-release:/tmp:/var/tmp
EOF

# Ensure wtc-site pool exists with dedicated multi-worker processes
cat > "/etc/php/$PHPV/fpm/pool.d/wtc-site.conf" <<EOF
[wtc-site]
user = www-data
group = www-data
listen = /run/php/wtc-site.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 8
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
php_admin_value[session.save_path] = /mnt/ramdisk/php-tmp
php_admin_value[upload_tmp_dir] = /var/tmp
php_admin_value[upload_max_filesize] = 10240M
php_admin_value[post_max_size] = 10240M
php_admin_value[max_execution_time] = 3600
php_admin_value[max_input_time] = 3600
php_admin_value[memory_limit] = 2048M
php_admin_value[max_file_uploads] = 50000
php_admin_value[max_input_vars] = 50000
EOF

# Disable default Debian www.conf pool to avoid duplicate resource consumption
[ -f "/etc/php/$PHPV/fpm/pool.d/www.conf" ] && mv "/etc/php/$PHPV/fpm/pool.d/www.conf" "/etc/php/$PHPV/fpm/pool.d/www.conf.disabled" 2>/dev/null || true

# Symlink sockets for universal compatibility
ln -sf /run/php/wtc-site.sock /run/php/php-fpm.sock 2>/dev/null || true
ln -sf /run/php/wtc-site.sock "/run/php/php${PHPV}-fpm.sock" 2>/dev/null || true

systemctl restart "php${PHPV}-fpm" 2>/dev/null || systemctl reload "php${PHPV}-fpm" 2>/dev/null || true

# 6. Update Nginx vhosts: Port 80/443 for live sites, Port 4485/4486 for Management UI
echo "==> Updating Nginx vhosts"
if [ ! -f /etc/nginx/wtc-force-https.conf ]; then
  cat > /etc/nginx/wtc-force-https.conf <<'EOF'
# Force HTTPS disabled
EOF
  chmod 0644 /etc/nginx/wtc-force-https.conf
fi
cat > /etc/nginx/sites-available/wtc <<'EOF'
# Primary Live Site Web Server (Port 80, 8080 & 443 HTTPS)
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    listen 8080;
    listen [::]:8080;
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    ssl_certificate /etc/ssl/wtc-ui/wtc-ui.crt;
    ssl_certificate_key /etc/ssl/wtc-ui/wtc-ui.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    server_name _;
    include /etc/nginx/wtc-force-https.conf;
    root /mnt/ramdisk/webroot;
    index index.php index.html index.htm;

    client_max_body_size 10240m;
    client_body_temp_path /var/tmp/nginx-body 1 2;
    client_body_timeout 3600s;
    send_timeout 3600s;

    include /etc/nginx/mime.types;
    types {
        image/jpeg jpg jpeg JPG JPEG;
        image/png png PNG;
        image/gif gif GIF;
        image/webp webp WEBP;
        image/svg+xml svg svgz SVG SVGZ;
        image/x-icon ico ICO;
        image/avif avif AVIF;
        text/css css;
        application/javascript js mjs;
        application/json json map;
        font/woff2 woff2;
        font/woff woff;
        font/ttf ttf;
    }
    default_type application/octet-stream;

    # Clean redirect to dedicated Management UI on Port 4485 (or 4486 over HTTPS)
    location = /manage {
        if ($scheme = https) {
            return 302 https://$host:4486/;
        }
        return 302 http://$host:4485/;
    }
    location = /manage/ {
        if ($scheme = https) {
            return 302 https://$host:4486/;
        }
        return 302 http://$host:4485/;
    }

    # Backward compatibility: redirect legacy /site and /site/ to root /
    location = /site {
        return 301 /;
    }
    location = /site/ {
        return 301 /;
    }
    location ^~ /site/ {
        rewrite ^/site/(.*)$ /$1 permanent;
    }

    # Multi-site concurrent hosting blocks (e.g. /slug/...)
    include /mnt/ramdisk/nginx-sites/*.conf;

    location ~ /\. { deny all; }

    # Include active site configuration snippet (defines location / or custom routes)
    include /etc/nginx/wtc-site.conf;

    # PHP FastCGI execution for live sites via dedicated multi-worker wtc-site pool
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
        fastcgi_param HTTP_X_FORWARDED_HOST $http_host;
        fastcgi_pass unix:/run/php/wtc-site.sock;
        fastcgi_connect_timeout 3600s;
        fastcgi_read_timeout 3600s;
        fastcgi_send_timeout 3600s;
    }
}
EOF

# Ensure self-signed TLS certificate exists for Management UI HTTPS (port 4486)
UI_SSL_DIR="/etc/ssl/wtc-ui"
if [ ! -f "$UI_SSL_DIR/wtc-ui.crt" ] || [ ! -f "$UI_SSL_DIR/wtc-ui.key" ]; then
  mkdir -p "$UI_SSL_DIR"
  HOST_NOW="$(hostname -s 2>/dev/null || echo pilabwtc)"
  IP_NOW="$(hostname -I 2>/dev/null | awk '{print $1}')"
  SAN_LIST="DNS:pilabwtc.local,DNS:${HOST_NOW}.local,DNS:localhost,IP:127.0.0.1"
  [ -n "$IP_NOW" ] && SAN_LIST="${SAN_LIST},IP:${IP_NOW}"
  openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
    -keyout "$UI_SSL_DIR/wtc-ui.key" \
    -out "$UI_SSL_DIR/wtc-ui.crt" \
    -subj "/CN=pilabwtc.local/O=WTC piLab/C=US" \
    -addext "basicConstraints=critical,CA:TRUE" \
    -addext "keyUsage=critical,keyCertSign,cRLSign,digitalSignature,keyEncipherment" \
    -addext "subjectAltName=${SAN_LIST}" >/dev/null 2>&1 || true
  chmod 600 "$UI_SSL_DIR/wtc-ui.key" 2>/dev/null || true
  chmod 644 "$UI_SSL_DIR/wtc-ui.crt" 2>/dev/null || true
fi
mkdir -p /etc/wtc-web
[ -f "$UI_SSL_DIR/wtc-ui.crt" ] && cp -f "$UI_SSL_DIR/wtc-ui.crt" /etc/wtc-web/wtc-ui.crt && chmod 644 /etc/wtc-web/wtc-ui.crt || true
if command -v ufw >/dev/null 2>&1; then
  ufw allow 443/tcp comment "HTTPS Web Server" >/dev/null 2>&1 || true
  ufw allow 4486/tcp comment "WTC Management UI (HTTPS)" >/dev/null 2>&1 || true
fi

cat > /etc/nginx/sites-available/wtc-ui <<'EOF'
# Management UI (Dedicated Port 4485 - Single Worker)
server {
    listen 4485;
    listen [::]:4485;
    server_name _;
    root /mnt/ramdisk/ui;
    client_max_body_size 10240m;
    client_body_temp_path /var/tmp/nginx-body 1 2;
    client_body_timeout 3600s;
    send_timeout 3600s;
    index index.php index.html;

    include /etc/nginx/mime.types;
    types {
        image/jpeg jpg jpeg JPG JPEG;
        image/png png PNG;
        image/gif gif GIF;
        image/webp webp WEBP;
        image/svg+xml svg svgz SVG SVGZ;
        image/x-icon ico ICO;
        image/avif avif AVIF;
        text/css css;
        application/javascript js mjs;
        application/json json map;
        font/woff2 woff2;
        font/woff woff;
        font/ttf ttf;
    }
    default_type application/octet-stream;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_pass unix:/run/php/wtc-ui.sock;
        fastcgi_connect_timeout 3600s;
        fastcgi_read_timeout 3600s;
        fastcgi_send_timeout 3600s;
    }
}

# Management UI HTTPS (Port 4486 - Self-Signed for pilabwtc.local)
server {
    listen 4486 ssl;
    listen [::]:4486 ssl;
    server_name _;
    ssl_certificate /etc/ssl/wtc-ui/wtc-ui.crt;
    ssl_certificate_key /etc/ssl/wtc-ui/wtc-ui.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    root /mnt/ramdisk/ui;
    client_max_body_size 10240m;
    client_body_temp_path /var/tmp/nginx-body 1 2;
    client_body_timeout 3600s;
    send_timeout 3600s;
    index index.php index.html;

    include /etc/nginx/mime.types;
    types {
        image/jpeg jpg jpeg JPG JPEG;
        image/png png PNG;
        image/gif gif GIF;
        image/webp webp WEBP;
        image/svg+xml svg svgz SVG SVGZ;
        image/x-icon ico ICO;
        image/avif avif AVIF;
        text/css css;
        application/javascript js mjs;
        application/json json map;
        font/woff2 woff2;
        font/woff woff;
        font/ttf ttf;
    }
    default_type application/octet-stream;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_pass unix:/run/php/wtc-ui.sock;
        fastcgi_connect_timeout 3600s;
        fastcgi_read_timeout 3600s;
        fastcgi_send_timeout 3600s;
    }
}
EOF

ln -sf /etc/nginx/sites-available/wtc /etc/nginx/sites-enabled/wtc
ln -sf /etc/nginx/sites-available/wtc-ui /etc/nginx/sites-enabled/wtc-ui
rm -f /etc/nginx/sites-enabled/default

# Clean up redundant legacy www symlink from RAM disk
[ -L /mnt/ramdisk/www ] && rm -f /mnt/ramdisk/www 2>/dev/null || true

# Ensure /mnt/ramdisk/webroot link exists for active primary site
if [ ! -L /mnt/ramdisk/webroot ] || [ ! -e /mnt/ramdisk/webroot ]; then
  active="$(/usr/local/bin/wtc-manage --active 2>/dev/null || true)"
  if [ -n "$active" ]; then
    slug="$(echo "$active" | tr '[:upper:]' '[:lower:]' | tr -d ' ' | tr -cd 'a-z0-9_-')"
    if [ -d "/mnt/ramdisk/sites/$slug/public" ]; then
      ln -sfn "/mnt/ramdisk/sites/$slug/public" /mnt/ramdisk/webroot
    elif [ -d "/mnt/ramdisk/sites/$slug" ]; then
      ln -sfn "/mnt/ramdisk/sites/$slug" /mnt/ramdisk/webroot
    fi
  fi
fi

if [ ! -s /mnt/ramdisk/nginx.conf ]; then
  cat > /mnt/ramdisk/nginx.conf <<'EOF'
# Active site RAM configuration
root /mnt/ramdisk/webroot;
index index.php index.html;
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
EOF
  chmod 0644 /mnt/ramdisk/nginx.conf
fi
ln -sfn /mnt/ramdisk/nginx.conf /etc/nginx/wtc-site.conf

# Ensure active site gateway exists in /mnt/ramdisk/www so /site/ never returns FastCGI 404
if [ -d /mnt/ramdisk/www ] && [ ! -f /mnt/ramdisk/www/index.php ]; then
  echo "==> Creating active site gateway in /mnt/ramdisk/www/index.php"
  cat > /mnt/ramdisk/www/index.php <<'EOF'
<?php
// WTC Pi SPA Router Bridge & Fallback Gateway
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

// Security Defense: Canonicalize path and prevent directory traversal (CWE-22)
$root = realpath(__DIR__) ?: __DIR__;
$publicRoot = realpath(__DIR__ . '/public') ?: ($root . '/public');

$candidate = $root . $reqUri;
$resolvedPath = realpath($candidate);

if (($resolvedPath === false || !is_file($resolvedPath)) && is_dir($publicRoot)) {
    $candidate = $publicRoot . $reqUri;
    $resolvedPath = realpath($candidate);
}

// Disallow traversal outside site root or public folder
$isValidPath = false;
if ($resolvedPath !== false && is_file($resolvedPath)) {
    if (str_starts_with($resolvedPath, $root) || (is_dir($publicRoot) && str_starts_with($resolvedPath, $publicRoot))) {
        // Reject hidden files, environment files, git, sqlite, and config files
        $base = basename($resolvedPath);
        if (!str_starts_with($base, '.') && !preg_match('/\.(env|git|htaccess|htpasswd|conf|ini|log|sqlite|sqlite3|db|sh|bak)$/i', $base)) {
            $isValidPath = true;
        }
    }
}

$ext = $isValidPath ? strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)) : '';
$isAsset = in_array($ext, ['js', 'mjs', 'css', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'wasm', 'webmanifest', 'txt', 'xml'], true);

if ($isValidPath && $reqUri !== '/' && $isAsset && !str_ends_with($resolvedPath, '.php')) {
    $mimes = [
        'js' => 'application/javascript', 'mjs' => 'application/javascript',
        'css' => 'text/css', 'html' => 'text/html', 'htm' => 'text/html',
        'json' => 'application/json', 'map' => 'application/json',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject',
        'wasm' => 'application/wasm', 'webmanifest' => 'application/manifest+json',
        'txt' => 'text/plain', 'xml' => 'application/xml'
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    readfile($resolvedPath);
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
EOF
  chown -h www-data:www-data /mnt/ramdisk/www/index.php 2>/dev/null || true
  chmod 0644 /mnt/ramdisk/www/index.php 2>/dev/null || true
fi

# Refresh multi-site Nginx configs for all active sites in RAM
if [ -d /mnt/ramdisk/sites ]; then
  echo "==> Refreshing multi-site Nginx blocks in /mnt/ramdisk/nginx-sites/"
  mkdir -p /mnt/ramdisk/nginx-sites
  # Source common helper to access generate_site_nginx_block & sync_force_https_conf
  # shellcheck source=/usr/local/lib/wtc-web/common.sh
  . /usr/local/lib/wtc-web/common.sh
  sync_force_https_conf
  cur_primary="$(active_site)"
  cur_primary_slug=""
  [ -n "$cur_primary" ] && cur_primary_slug="$(site_slug "$cur_primary")"
  for sdir in /mnt/ramdisk/sites/*; do
    [ -d "$sdir" ] || continue
    slug="$(basename "$sdir")"
    wroot="$sdir"
    if [ -d "$sdir/public" ] && { [ -f "$sdir/artisan" ] || [ -f "$sdir/public/index.php" ]; }; then
      wroot="$sdir/public"
    fi
    generate_site_nginx_block "$slug" "$slug" "$sdir" "$wroot" "/mnt/ramdisk/nginx-sites/$slug.conf"
    if [ -n "$cur_primary_slug" ] && [ "$slug" = "$cur_primary_slug" ]; then
      sport=""
      [ -f "$SITES_DIR/$cur_primary.meta.json" ] && sport="$(jq -r '.port // empty' "$SITES_DIR/$cur_primary.meta.json" 2>/dev/null || true)"
      if [ -z "$sport" ] || [ "$sport" = "null" ]; then
        configure_site_env "$sdir" "" "" "$cur_primary"
      fi
    fi
  done
fi

# 5. Install systemd units for two-phase boot (UI first, then SD-to-RAM site loader) & background workers
echo "==> Installing WTC Boot Loader & Background Workers Services"
FPM_UNIT="$(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}' | head -n1 || true)"
cat > /etc/systemd/system/wtc-site-loader.service <<EOF
[Unit]
Description=WTC Site RAM Loader (Transfers Sites from SD to RAM)
Requires=wtc-ramdisk.service
After=wtc-ramdisk.service nginx.service ${FPM_UNIT:+$FPM_UNIT }mariadb.service redis.service wtc-network.service
Wants=mariadb.service redis.service wtc-network.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/bin/wtc-site-service --boot-sites
TimeoutStartSec=300

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/wtc-workers.service <<'EOF'
[Unit]
Description=WTC Background Workers Daemon (Laravel Queue & Scheduler)
After=wtc-ramdisk.service wtc-site-loader.service mariadb.service redis.service
Wants=wtc-ramdisk.service wtc-site-loader.service

[Service]
Type=simple
User=root
ExecStart=/usr/local/bin/wtc-workers run
Restart=always
RestartSec=3s
TimeoutStopSec=10s

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable wtc-site-loader.service wtc-workers.service
systemctl restart wtc-workers.service || true

# 6. Install systemd unit and power management for Network Watchdog
echo "==> Configuring Network Resilience, Auto-Reconnect & Watchdog Service"
install -d /etc/NetworkManager/conf.d /etc/udev/rules.d
cat > /etc/NetworkManager/conf.d/default-wifi-powersave-disable.conf <<'EOF'
[connection]
wifi.powersave = 2
EOF

cat > /etc/udev/rules.d/70-wifi-powersave.rules <<'EOF'
ACTION=="add", SUBSYSTEM=="net", KERNEL=="wl*|wlan*", RUN+="/usr/bin/iw dev %k set power_save off"
EOF

cat > /etc/systemd/system/wtc-network.service <<'EOF'
[Unit]
Description=WTC Network Resilience, Auto-Reconnect & Fan Watchdog
After=local-fs.target
Wants=network.target
StartLimitIntervalSec=0

[Service]
Type=simple
User=root
Environment=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
ExecStart=/usr/local/bin/wtc-network daemon
Restart=always
RestartSec=5s
TimeoutStopSec=5s

[Install]
WantedBy=multi-user.target
EOF

install -d /etc/systemd/system/nginx.service.d
cat > /etc/systemd/system/nginx.service.d/wtc.conf <<'EOF'
[Unit]
Requires=wtc-ramdisk.service
After=wtc-ramdisk.service network.target
EOF

systemctl daemon-reload
systemctl enable wtc-network.service
systemctl restart wtc-network.service || true

echo "==> Optimizing boot performance & eliminating wait-online stalls"
# Disable wait-online services that stall boot for 60-120s if Wi-Fi associates slowly or Ethernet is unplugged
systemctl disable NetworkManager-wait-online.service 2>/dev/null || true
systemctl mask NetworkManager-wait-online.service 2>/dev/null || true
systemctl disable systemd-networkd-wait-online.service 2>/dev/null || true
systemctl mask systemd-networkd-wait-online.service 2>/dev/null || true

# Disable unused Bluetooth UART initialization (saves 15-25s on Pi boot)
systemctl disable hciuart.service 2>/dev/null || true
systemctl disable bluetooth.service 2>/dev/null || true

# Disable cloud-init if installed (common on Ubuntu Server Pi images; saves 30-60s)
if [ -d /etc/cloud ]; then
  touch /etc/cloud/cloud-init.disabled 2>/dev/null || true
  systemctl disable cloud-init.service cloud-init-local.service cloud-config.service cloud-final.service 2>/dev/null || true
fi

# 7. Tune PHP-FPM, open_basedir, timeouts, MariaDB loopback isolation, and reload services
echo "==> Applying server optimizations and reloading services"
mkdir -p /etc/mysql/mariadb.conf.d /etc/mysql/conf.d 2>/dev/null || true
cat > /etc/mysql/mariadb.conf.d/99-wtc-bind.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
EOF
chmod 0644 /etc/mysql/mariadb.conf.d/99-wtc-bind.cnf 2>/dev/null || true
[ -d /etc/mysql/conf.d ] && cp -f /etc/mysql/mariadb.conf.d/99-wtc-bind.cnf /etc/mysql/conf.d/99-wtc-bind.cnf 2>/dev/null || true
systemctl restart mariadb 2>/dev/null || systemctl restart mysql 2>/dev/null || true
/usr/local/bin/wtc-manage --server-tune
/usr/local/bin/wtc-manage --reload-ui
/usr/local/bin/wtc-manage --rescan-stack >/dev/null 2>&1 || true
/usr/local/bin/wtc-manage --rescan-sites >/dev/null 2>&1 || true

# 5. Verify backend responsiveness
echo "==> Available Sites in Repository:"
/usr/local/bin/wtc-manage --list || true

# 7. Ensure avahi-daemon (mDNS / .local hostname browsing) and unattended-upgrades are installed & running
if ! command -v pigz >/dev/null 2>&1; then
  echo "==> Installing pigz for multi-core archive extraction"
  export DEBIAN_FRONTEND=noninteractive
  apt-get install -y pigz 2>/dev/null || true
fi

if ! command -v avahi-daemon >/dev/null 2>&1; then
  echo "==> Installing avahi-daemon for .local hostname resolution"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq && apt-get install -y avahi-daemon || true
fi
systemctl enable --now avahi-daemon 2>/dev/null || true

if ! dpkg -s unattended-upgrades >/dev/null 2>&1; then
  echo "==> Installing unattended-upgrades for automated security patches"
  export DEBIAN_FRONTEND=noninteractive
  apt-get install -y unattended-upgrades || true
fi

HOST="$(hostname)"
IP="$(hostname -I | awk '{print $1}')"

echo
echo "================================================================"
echo " [OK] Update completed successfully!"
echo " - Management UI served live from RAM drive (/mnt/ramdisk/ui)"
echo " - Physical disk staging active (/var/tmp/wtc-staging) with 10GB upload support"
echo " - Inotify race condition eliminated"
echo " - Management Portal: http://$IP:4485/ or http://$HOST.local:4485/ (or http://$IP/manage)"
echo " - Active Site      : http://$IP/ or http://$HOST.local/ (Port 80)"
echo " - Port 8080 (App)  : http://$IP:8080/ or http://$HOST.local:8080/"
echo "================================================================"
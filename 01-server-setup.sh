#!/usr/bin/env bash
# 01-server-setup.sh - provision Ubuntu Server (recommended for the Pi) or Debian as a
# RAM-disk multi-site PHP web server.
# Usage: sudo ./01-server-setup.sh        (env: RAM_SIZE=512M ENABLE_SAMBA=yes)
set -Eeuo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)." >&2; exit 1; }
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RAM_SIZE="${RAM_SIZE:-512M}"
ENABLE_SAMBA="${ENABLE_SAMBA:-yes}"
ENABLE_FTP="${ENABLE_FTP:-yes}"
DROP_GROUP="wtc-drop"
UI_PORT=4485

# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in
  ubuntu|debian|raspbian) echo "==> Detected ${PRETTY_NAME:-$ID}" ;;
  *) echo "Warning: untested distro '${ID:-unknown}' (Ubuntu/Debian expected)" >&2 ;;
esac

echo "==> Installing packages"
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a            # Ubuntu: never prompt to restart services
APT=(apt-get -o DPkg::Lock::Timeout=300 -y)   # wait if unattended-upgrades holds the lock
if [ "${ID:-}" = ubuntu ]; then
  # composer, imagick, apcu etc. live in 'universe'
  command -v add-apt-repository >/dev/null && add-apt-repository -y universe || true
fi
"${APT[@]}" update
PKGS=(nginx mariadb-server php-fpm php-cli php-mysql php-sqlite3 php-curl php-mbstring php-xml php-zip rsync tar gzip unzip jq whiptail curl sudo openssh-server avahi-daemon)
[ "$ENABLE_SAMBA" = yes ] && PKGS+=(samba)
[ "$ENABLE_FTP" = yes ] && PKGS+=(vsftpd)
"${APT[@]}" install "${PKGS[@]}"
systemctl enable --now mariadb avahi-daemon
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "    PHP $PHPV"

echo "==> Users, groups, directories"
getent group "$DROP_GROUP" >/dev/null || groupadd --system "$DROP_GROUP"
id wtc-ui >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin -G "$DROP_GROUP" wtc-ui
id wtcdrop >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin -G "$DROP_GROUP" wtcdrop
if [ -n "${SUDO_USER:-}" ]; then usermod -aG "$DROP_GROUP" "$SUDO_USER" || true; fi
install -d /opt/wtc-web/sites/.previous /opt/wtc-web/ui /etc/wtc-web /var/log/wtc-web /var/lib/wtc-web/sessions /usr/local/lib/wtc-web /mnt/ramdisk
chmod 0755 /opt/wtc-web /opt/wtc-web/sites
chown wtc-ui:wtc-ui /var/lib/wtc-web/sessions && chmod 0700 /var/lib/wtc-web/sessions

if [ ! -f /etc/wtc-web/wtc.conf ]; then
  cat > /etc/wtc-web/wtc.conf <<EOF
# WTC web server settings (editable from the UI on port $UI_PORT)
RAM_SIZE=$RAM_SIZE
DROP_MAX_MB=100
AUTO_ACTIVATE_NEW=no
AUTO_RELOAD_ACTIVE=yes
KEEP_PROCESSED=no
SAVE_ON_SHUTDOWN=no
SAVE_ON_SWITCH=no
SAVE_INTERVAL_MIN=0
SAMBA_GUEST=yes
EOF
fi

echo "==> Installing scripts and UI"
install -m 0644 "$SRC/wtc-common.sh"       /usr/local/lib/wtc-web/common.sh
install -m 0755 "$SRC/03-site-service.sh"  /usr/local/bin/wtc-site-service
install -m 0755 "$SRC/02-manage-site.sh"   /usr/local/bin/wtc-manage
install -m 0755 "$SRC/wtc-workers"         /usr/local/bin/wtc-workers
mkdir -p /opt/wtc-web/ui
cp -r "$SRC/ui/"* /opt/wtc-web/ui/
chmod -R u=rwX,go=rX /opt/wtc-web/ui

# Only the UI user may call the (argument-validating) backend via sudo.
echo 'wtc-ui ALL=(root) NOPASSWD: /usr/local/bin/wtc-manage' > /etc/sudoers.d/wtc-ui
chmod 0440 /etc/sudoers.d/wtc-ui
visudo -cf /etc/sudoers.d/wtc-ui >/dev/null

# UI credentials (user "admin"); generated once.
if [ ! -f /etc/wtc-web/ui-auth ]; then
  UI_PASS="$(openssl rand -hex 12)"
  php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT), "\n";' "$UI_PASS" > /etc/wtc-web/ui-auth
  chown wtc-ui:wtc-ui /etc/wtc-web/ui-auth && chmod 0600 /etc/wtc-web/ui-auth
fi

echo "==> Seeding/updating starter sites"
# site-alpha: The Default Landing UI & Management Portal
  t="$(mktemp -d)"
  cat > "$t/index.php" <<'EOF'
<?php
$hostname = gethostname();
$php_ver = PHP_VERSION;
$ram_total = disk_total_space('/mnt/ramdisk');
$ram_free = disk_free_space('/mnt/ramdisk');
$ram_used = ($ram_total && $ram_free) ? ($ram_total - $ram_free) : 0;
$ram_pct = $ram_total ? round(($ram_used / $ram_total) * 100) : 0;

$db_status = 'Not configured';
$db_info = [];
if (file_exists(__DIR__ . '/.wtc-db.php')) {
    $db_info = include __DIR__ . '/.wtc-db.php';
    if (!empty($db_info['name']) && extension_loaded('mysqli')) {
        $link = @mysqli_connect('localhost', $db_info['user'], $db_info['pass'], $db_info['name']);
        if ($link) {
            $db_status = 'Connected (' . htmlspecialchars($db_info['name']) . ')';
            mysqli_close($link);
        } else {
            $db_status = 'Configured, connecting...';
        }
    }
}
function f_bytes($b) {
    if (!$b) return '0 MB';
    return round($b / 1024 / 1024, 1) . ' MB';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WTC Pi - Active Web Portal</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #0f1419; color: #e6e6e6; line-height: 1.5; }
    header { background: #1a2129; border-bottom: 1px solid #2a3541; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
    header .brand { font-size: 1.25rem; font-weight: 700; color: #7cc4ff; display: flex; align-items: center; gap: 8px; }
    header .brand span { background: #1d6b3a; color: #fff; font-size: 0.75rem; padding: 2px 8px; border-radius: 12px; text-transform: uppercase; }
    .btn { background: #2b7de9; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 0.9rem; transition: background 0.2s; display: inline-block; }
    .btn:hover { background: #1a68d1; }
    .btn-secondary { background: #2a3541; color: #ccc; }
    .btn-secondary:hover { background: #394757; color: #fff; }
    main { max-width: 1040px; margin: 32px auto; padding: 0 16px; }
    .hero { background: linear-gradient(135deg, #1c2633 0%, #161e27 100%); border: 1px solid #2a3541; border-radius: 12px; padding: 28px; margin-bottom: 24px; }
    .hero h1 { font-size: 1.8rem; margin-bottom: 8px; color: #fff; }
    .hero p { color: #9bb0c4; max-width: 700px; font-size: 1rem; margin-bottom: 20px; }
    .hero-actions { display: flex; gap: 12px; flex-wrap: wrap; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 24px; }
    .card { background: #1a2129; border: 1px solid #2a3541; border-radius: 10px; padding: 20px; }
    .card h3 { font-size: 1.1rem; color: #7cc4ff; margin-bottom: 14px; border-bottom: 1px solid #2a3541; padding-bottom: 8px; }
    .metric { margin-bottom: 12px; }
    .metric label { color: #8899a6; font-size: 0.85rem; display: block; }
    .metric value { font-size: 1rem; font-weight: 600; color: #eee; }
    .bar { height: 10px; background: #0a0d11; border-radius: 5px; overflow: hidden; margin-top: 6px; }
    .bar i { display: block; height: 100%; background: #2b7de9; border-radius: 5px; }
    code { background: #0a0d11; padding: 3px 6px; border-radius: 4px; font-size: 0.85rem; color: #a5d6ff; }
    footer { text-align: center; color: #657786; font-size: 0.85rem; margin: 40px 0 20px; }
  </style>
</head>
<body>
  <header>
    <div class="brand">
      WTC Pi Web Server <span>RAM-Disk Live</span>
    </div>
    <div>
      <a href="/manage/" class="btn">Open Management Utility</a>
      <a href="http://<?= $_SERVER['SERVER_NAME'] ?>:4485/" class="btn btn-secondary">Port 4485 UI</a>
    </div>
  </header>
  <main>
    <div class="hero">
      <h1>Active Application: site-alpha</h1>
      <p>This application is currently mounted and running entirely in volatile <strong>RAM drive</strong> (/mnt/ramdisk) for zero SD-card wear and maximum speed. Use the Management Utility to swap sites, install stacks, or save runtime state.</p>
      <div class="hero-actions">
        <a href="/manage/" class="btn">Manage & Swap Sites</a>
        <a href="http://<?= $_SERVER['SERVER_NAME'] ?>:4485/?p=transfer" class="btn btn-secondary">File Transfer / Dropbox</a>
      </div>
    </div>
    <div class="grid">
      <div class="card">
        <h3>RAM Disk Health</h3>
        <div class="metric">
          <label>Mount Point</label>
          <value><code>/mnt/ramdisk</code> (tmpfs)</value>
        </div>
        <div class="metric">
          <label>Memory Used</label>
          <value><?= f_bytes($ram_used) ?> of <?= f_bytes($ram_total) ?> (<?= $ram_pct ?>%)</value>
          <div class="bar"><i style="width: <?= $ram_pct ?>%"></i></div>
        </div>
        <div class="metric">
          <label>Runtime Host</label>
          <value><?= htmlspecialchars($hostname) ?> &bull; PHP <?= htmlspecialchars($php_ver) ?></value>
        </div>
      </div>
      <div class="card">
        <h3>Database & Stack</h3>
        <div class="metric">
          <label>Database Engine</label>
          <value>MariaDB 10.x</value>
        </div>
        <div class="metric">
          <label>Connection Status</label>
          <value><?= htmlspecialchars($db_status) ?></value>
        </div>
        <div class="metric">
          <label>Auto-Injected Config</label>
          <value><code>.wtc-db.php</code> / <code>$_SERVER['DB_*']</code></value>
        </div>
      </div>
      <div class="card">
        <h3>Deploy & Swap</h3>
        <div class="metric">
          <label>Windows Share (Samba)</label>
          <value><code>\\<?= $_SERVER['SERVER_ADDR'] ?? $hostname ?>\dropbox</code></value>
        </div>
        <div class="metric">
          <label>SFTP / SCP Ingest</label>
          <value><code>/mnt/ramdisk/dropbox</code></value>
        </div>
        <div class="metric">
          <label>How to Update</label>
          <value>Drop any <code>&lt;site&gt;.tar.gz</code> into the dropbox to auto-register it.</value>
        </div>
      </div>
    </div>
  </main>
  <footer>
    WTC Pi Web Server &bull; Serving from RAM on <?= htmlspecialchars($hostname) ?>
  </footer>
</body>
</html>
EOF
  tar -czf "/opt/wtc-web/sites/site-alpha.tar.gz" -C "$t" .
  chmod 0644 "/opt/wtc-web/sites/site-alpha.tar.gz"
  rm -rf "$t"

  # site-beta
  t="$(mktemp -d)"
  cat > "$t/index.php" <<EOF
<?php ?><!doctype html><html><head><meta charset="utf-8"><title>site-beta</title>
<style>body{font-family:sans-serif;background:#111;color:#eee;display:grid;place-items:center;height:100vh;margin:0;text-align:center}</style></head>
<body><div><h1>site-beta</h1><p>Alternate site running from RAM on <?= gethostname() ?>.</p><p><a href="/manage/" style="color:#7cc4ff">Return to Management Utility</a></p></div></body></html>
EOF
  tar -czf "/opt/wtc-web/sites/site-beta.tar.gz" -C "$t" .
  chmod 0644 "/opt/wtc-web/sites/site-beta.tar.gz"
  rm -rf "$t"

  # maintenance
  t="$(mktemp -d)"
  cat > "$t/index.php" <<EOF
<?php ?><!doctype html><html><head><meta charset="utf-8"><title>Maintenance</title>
<style>body{font-family:sans-serif;background:#111;color:#eee;display:grid;place-items:center;height:100vh;margin:0;text-align:center}</style></head>
<body><div><h1>Maintenance Mode</h1><p>This server is temporarily undergoing maintenance.</p><p><a href="/manage/" style="color:#7cc4ff">Management Access</a></p></div></body></html>
EOF
  tar -czf "/opt/wtc-web/sites/maintenance.tar.gz" -C "$t" .
  chmod 0644 "/opt/wtc-web/sites/maintenance.tar.gz"
  rm -rf "$t"

echo "==> Configuring PHP-FPM"
# 1. Management UI Pool: Single worker thread/process for lightweight, isolated administration
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
php_admin_value[upload_tmp_dir] = /mnt/ramdisk/php-tmp
php_admin_value[upload_max_filesize] = 1024M
php_admin_value[post_max_size] = 1024M
php_admin_value[max_execution_time] = 600
php_admin_value[max_input_time] = 600
php_admin_value[memory_limit] = 512M
php_admin_value[max_file_uploads] = 10000
php_admin_value[max_input_vars] = 50000
php_admin_value[open_basedir] = /opt/wtc-web/ui:/mnt/ramdisk/ui:/opt/wtc-web/sites:/mnt/ramdisk:/var/lib/wtc-web/sessions:/etc/wtc-web/ui-auth:/etc/nginx:/tmp
EOF

# 2. Live Site Pool: Dedicated multi-worker processes for served websites
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
php_admin_value[upload_tmp_dir] = /mnt/ramdisk/php-tmp
php_admin_value[upload_max_filesize] = 1024M
php_admin_value[post_max_size] = 1024M
php_admin_value[max_execution_time] = 600
php_admin_value[max_input_time] = 600
php_admin_value[memory_limit] = 512M
php_admin_value[max_file_uploads] = 10000
php_admin_value[max_input_vars] = 50000
EOF

# Disable default Debian www.conf pool to avoid duplicate resource consumption
[ -f "/etc/php/$PHPV/fpm/pool.d/www.conf" ] && mv "/etc/php/$PHPV/fpm/pool.d/www.conf" "/etc/php/$PHPV/fpm/pool.d/www.conf.disabled" 2>/dev/null || true

# Site pool: keep uploads/sessions of served sites in RAM too, with OPcache on.
cat > "/etc/php/$PHPV/fpm/conf.d/90-wtc.ini" <<'EOF'
upload_tmp_dir = /mnt/ramdisk/php-tmp
session.save_path = /mnt/ramdisk/php-tmp
upload_max_filesize = 1024M
post_max_size = 1024M
max_execution_time = 600
max_input_time = 600
memory_limit = 512M
max_file_uploads = 10000
max_input_vars = 50000
opcache.enable = 1
opcache.memory_consumption = 64
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1
EOF
FPM_UNIT="php$PHPV-fpm.service"
install -d "/etc/systemd/system/$FPM_UNIT.d"
cat > "/etc/systemd/system/$FPM_UNIT.d/wtc.conf" <<'EOF'
[Unit]
Requires=wtc-ramdisk.service
After=wtc-ramdisk.service
EOF

echo "==> Configuring nginx"
rm -f /etc/nginx/sites-enabled/*
rm -f /var/www/html/index.nginx-debian.html
mkdir -p /var/www/html
cat > /var/www/html/index.html <<'EOF'
<!doctype html><html><head><meta http-equiv="refresh" content="0; url=/"><title>Redirecting...</title></head>
<body><p>Redirecting to <a href="/">WTC Pi Web Server</a>...</p></body></html>
EOF
ln -sf /run/php/wtc-site.sock /run/php/php-fpm.sock
ln -sf /run/php/wtc-site.sock "/run/php/php${PHPV}-fpm.sock"

# Ensure /etc/nginx/mime.types includes mjs and wasm for ES modules & Vite
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

cat > /mnt/ramdisk/nginx.conf <<'EOF'
# Active site RAM configuration
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
EOF
chmod 0644 /mnt/ramdisk/nginx.conf
ln -sfn /mnt/ramdisk/nginx.conf /etc/nginx/wtc-site.conf
cat > /etc/nginx/sites-available/wtc <<'EOF'
# Primary Live Site Web Server (Port 80 & 8080)
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    listen 8080;
    listen [::]:8080;
    server_name _;
    root /mnt/ramdisk/webroot;
    index index.php index.html index.htm;

    client_max_body_size 1024m;
    client_body_temp_path /mnt/ramdisk/php-tmp/nginx-body 1 2;
    client_body_timeout 600s;
    send_timeout 600s;

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

    # Clean redirect to dedicated Management UI on Port 4485
    location = /manage {
        return 302 http://$host:4485/;
    }
    location = /manage/ {
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
        fastcgi_pass unix:/run/php/wtc-site.sock;
        fastcgi_connect_timeout 600s;
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
    }
}
EOF
cat > /etc/nginx/sites-available/wtc-ui <<EOF
# Management UI (Dedicated Port $UI_PORT - Single Worker)
server {
    listen $UI_PORT;
    listen [::]:$UI_PORT;
    server_name _;
    root /mnt/ramdisk/ui;
    client_max_body_size 1024m;
    client_body_temp_path /mnt/ramdisk/php-tmp/nginx-body 1 2;
    client_body_timeout 600s;
    send_timeout 600s;
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
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_pass unix:/run/php/wtc-ui.sock;
        fastcgi_connect_timeout 600s;
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
    }
}
EOF

# Link only wtc and wtc-ui; wtc has 'default_server' on port 80.
# Do NOT symlink default into sites-enabled because that would cause a duplicate default_server error.
ln -sf /etc/nginx/sites-available/wtc    /etc/nginx/sites-enabled/wtc
ln -sf /etc/nginx/sites-available/wtc-ui /etc/nginx/sites-enabled/wtc-ui
rm -f /etc/nginx/sites-enabled/default

echo "==> Installing systemd units"
cat > /etc/systemd/system/wtc-ramdisk.service <<'EOF'
[Unit]
Description=WTC RAM disk and active site loader
Before=nginx.service
After=local-fs.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/bin/wtc-site-service --boot
ExecStop=/usr/local/bin/wtc-site-service --shutdown

[Install]
WantedBy=multi-user.target
EOF
install -d /etc/systemd/system/nginx.service.d
cat > /etc/systemd/system/nginx.service.d/wtc.conf <<'EOF'
[Unit]
Requires=wtc-ramdisk.service
After=wtc-ramdisk.service
EOF
cat > /etc/systemd/system/wtc-dropbox.service <<'EOF'
[Unit]
Description=WTC dropbox ingest
After=wtc-ramdisk.service
Requires=wtc-ramdisk.service
StartLimitIntervalSec=0

[Service]
Type=oneshot
ExecStart=/usr/local/bin/wtc-site-service --ingest
EOF
cat > /etc/systemd/system/wtc-dropbox.path <<'EOF'
[Unit]
Description=Watch the WTC dropbox
After=wtc-ramdisk.service
Requires=wtc-ramdisk.service

[Path]
PathChanged=/mnt/ramdisk/dropbox
Unit=wtc-dropbox.service

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/systemd/system/wtc-save.service <<'EOF'
[Unit]
Description=WTC periodic state save
After=wtc-ramdisk.service

[Service]
Type=oneshot
ExecStart=/usr/local/bin/wtc-site-service --save-tick
EOF
cat > /etc/systemd/system/wtc-save.timer <<'EOF'
[Unit]
Description=WTC periodic state save check

[Timer]
OnCalendar=minutely
Persistent=false

[Install]
WantedBy=timers.target
EOF

cat > /etc/systemd/system/wtc-workers.service <<'EOF'
[Unit]
Description=WTC Background Workers Daemon (Laravel Queue & Scheduler)
After=wtc-ramdisk.service mariadb.service redis.service
Wants=wtc-ramdisk.service

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

if [ "$ENABLE_SAMBA" = yes ]; then
  echo "==> Configuring Samba"
  # shellcheck disable=SC1091
  . /usr/local/lib/wtc-web/common.sh
  write_samba_block
  systemctl enable --now smbd
fi

if [ "$ENABLE_FTP" = yes ]; then
  echo "==> Configuring FTP (vsftpd)"
  # shellcheck disable=SC1091
  . /usr/local/lib/wtc-web/common.sh
  write_ftp_block
  systemctl enable --now vsftpd
fi

echo "==> Enabling services"
systemctl daemon-reload
systemctl enable wtc-ramdisk.service wtc-dropbox.path wtc-save.timer wtc-workers.service nginx "$FPM_UNIT" ssh avahi-daemon
[ "$ENABLE_FTP" = yes ] && systemctl enable vsftpd || true

echo "==> Starting wtc-ramdisk service"
rm -f /run/wtc-web.lock 2>/dev/null || true
systemctl restart avahi-daemon 2>/dev/null || true
systemctl restart wtc-ramdisk.service || true

echo "==> Syncing UI into RAM"
/usr/local/bin/wtc-manage --reload-ui || true

echo "==> Restarting PHP-FPM and Workers"
systemctl restart "$FPM_UNIT" || true
systemctl restart wtc-workers.service 2>/dev/null || true

echo "==> Starting Nginx and Dropbox watchers"
nginx -t
systemctl restart nginx || true
systemctl start wtc-dropbox.path wtc-save.timer || true

echo "==> Loading default portal (site-alpha) into RAM"
/usr/local/bin/wtc-site-service --swap site-alpha || true

# Verification check
HTTP_STATUS="$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/ || echo "failed")"
echo "==> Self-test: Port 80 responded with HTTP $HTTP_STATUS"

IP="$(hostname -I | awk '{print $1}')"
HOST="$(hostname)"
echo
echo "================================================================"
echo " Device Management UI : http://$IP:$UI_PORT/ or http://$HOST.local:$UI_PORT/ (or /manage)"
echo " Active RAM-Disk Site : http://$IP/ or http://$HOST.local/ (Port 80)"
echo " Port 8080 (Direct)   : http://$IP:8080/ or http://$HOST.local:8080/"
echo " Dropbox (RAM)        : \\\\$HOST.local\\dropbox (or \\\\$IP\\dropbox)"
echo " Terminal Menu        : sudo wtc-manage"
echo " Log out/in so group '$DROP_GROUP' applies to your user."
echo "================================================================"

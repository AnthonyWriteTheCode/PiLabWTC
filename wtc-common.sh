#!/usr/bin/env bash
# Shared helpers for the WTC RAM-disk web server scripts.
# Installed to /usr/local/lib/wtc-web/common.sh and sourced by the other scripts.

WTC_CONF="${WTC_CONF:-/etc/wtc-web/wtc.conf}"
# shellcheck disable=SC1090
[ -f "$WTC_CONF" ] && . "$WTC_CONF"

# ---- user settings (overridable in /etc/wtc-web/wtc.conf) ----
: "${RAM_SIZE:=512M}"
: "${DROP_MAX_MB:=100}"
: "${AUTO_ACTIVATE_NEW:=no}"
: "${AUTO_RELOAD_ACTIVE:=yes}"
: "${KEEP_PROCESSED:=no}"
: "${SAVE_ON_SHUTDOWN:=no}"
: "${SAVE_ON_SWITCH:=no}"
: "${SAVE_INTERVAL_MIN:=0}"
: "${SAMBA_GUEST:=yes}"
: "${FTP_GUEST:=yes}"

# ---- paths ----
: "${SITES_DIR:=/opt/wtc-web/sites}"
: "${SD_UI_DIR:=/opt/wtc-web/ui}"
: "${PREV_DIR:=$SITES_DIR/.previous}"
: "${RAM_MOUNT:=/mnt/ramdisk}"
: "${RAM_UI_DIR:=$RAM_MOUNT/ui}"
: "${WWW_LINK:=$RAM_MOUNT/www}"
: "${RELEASES_DIR:=$RAM_MOUNT/.releases}"
: "${DROP_DIR:=$RAM_MOUNT/dropbox}"
: "${REJECT_DIR:=$RAM_MOUNT/rejected}"
: "${PROCESSED_DIR:=$RAM_MOUNT/processed}"
: "${PHP_TMP:=$RAM_MOUNT/php-tmp}"
: "${STAGE_DIR:=$PHP_TMP/staging}"
: "${DROP_GROUP:=wtc-drop}"
: "${SAMBA_USER:=wtcdrop}" 
: "${STATE_FILE:=/etc/wtc-web/active_site.conf}"
: "${ACTIVE_SITES_FILE:=/etc/wtc-web/active_sites.list}"
: "${RAM_SITES_DIR:=$RAM_MOUNT/sites}"
: "${RAM_NGINX_SITES:=$RAM_MOUNT/nginx-sites}"
: "${RAM_NGINX_CONF:=$RAM_MOUNT/nginx.conf}"
: "${NGINX_SNIPPET:=/etc/nginx/wtc-site.conf}"
: "${NGINX_PHP_SNIPPET:=/etc/nginx/wtc-site-php.conf}"
: "${DB_AUTO_CREATE:=yes}"
: "${DB_CRED_DIR:=/etc/wtc-web/db}"
: "${DB_BACKUP_DIR:=/opt/wtc-web/db-backups}"
: "${DB_BACKUP_KEEP:=5}"
: "${LOG_FILE:=/var/log/wtc-web/wtc.log}"
: "${LOCK_FILE:=/run/wtc-web.lock}"
: "${SAVE_STAMP:=/run/wtc-web.save-stamp}"
: "${SERVICE_BIN:=/usr/local/bin/wtc-site-service}"
: "${WEB_USER:=www-data}"

log() {
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
  echo "$(date '+%F %T') [$(basename "$0")] $*" | tee -a "$LOG_FILE" >&2
}

die() { log "ERROR: $*"; exit 1; }

need_root() {
  [ "$(id -u)" -eq 0 ] || die "Must be run as root (use sudo)."
}

# Site IDs: lowercase letters, digits, '-' and '_'.
valid_name() { [[ "${1:-}" =~ ^[a-z0-9][a-z0-9_-]{0,62}$ ]]; }

list_sites() {
  local f cur
  cur="$(active_site)"
  for f in "$SITES_DIR"/*.tar.gz; do
    [ -e "$f" ] || continue
    basename "$f" .tar.gz
  done
  if [ -n "$cur" ] && [ ! -f "$SITES_DIR/$cur.tar.gz" ]; then
    echo "$cur"
  fi
}

active_site() { cat "$STATE_FILE" 2>/dev/null || true; }

# Lowercase, no whitespace slug for site URL routing e.g. "My Site" -> "mysite"
site_slug() {
  local s="${1:-}"
  echo "$s" | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]' | tr -cd 'a-z0-9_-'
}

list_active_sites() {
  if [ -f "$ACTIVE_SITES_FILE" ]; then
    grep -v '^\s*$' "$ACTIVE_SITES_FILE" 2>/dev/null | sort -u || true
  else
    local cur; cur="$(active_site)"
    [ -n "$cur" ] && echo "$cur"
  fi
}

is_site_active() {
  local site="$1"
  [ -z "$site" ] && return 1
  if [ -f "$ACTIVE_SITES_FILE" ]; then
    grep -qx "$site" "$ACTIVE_SITES_FILE" 2>/dev/null && return 0
  fi
  [ "$(active_site)" = "$site" ]
}

add_active_site() {
  local site="$1"
  [ -z "$site" ] && return 0
  mkdir -p "$(dirname "$ACTIVE_SITES_FILE")"
  touch "$ACTIVE_SITES_FILE"
  if ! grep -qx "$site" "$ACTIVE_SITES_FILE" 2>/dev/null; then
    echo "$site" >> "$ACTIVE_SITES_FILE"
  fi
}

remove_active_site() {
  local site="$1"
  [ -z "$site" ] && return 0
  if [ -f "$ACTIVE_SITES_FILE" ]; then
    grep -vx "$site" "$ACTIVE_SITES_FILE" > "$ACTIVE_SITES_FILE.tmp" 2>/dev/null || true
    mv -f "$ACTIVE_SITES_FILE.tmp" "$ACTIVE_SITES_FILE" 2>/dev/null || true
  fi
}

ensure_ramdisk() {
  mkdir -p "$RAM_MOUNT"
  if ! mountpoint -q "$RAM_MOUNT"; then
    mount -t tmpfs -o "size=$RAM_SIZE,mode=0755,nosuid,nodev" tmpfs "$RAM_MOUNT"
    log "Mounted tmpfs ($RAM_SIZE) on $RAM_MOUNT"
  fi
  mkdir -p "$RELEASES_DIR" "$RAM_SITES_DIR" "$RAM_NGINX_SITES" "$DROP_DIR" "$REJECT_DIR" "$PHP_TMP" "$PHP_TMP/nginx-body" "$STAGE_DIR"
  if getent group "$DROP_GROUP" >/dev/null; then
    chgrp "$DROP_GROUP" "$DROP_DIR" "$REJECT_DIR"
  fi
  chmod 2775 "$DROP_DIR" "$REJECT_DIR"
  chmod 1777 "$PHP_TMP" "$STAGE_DIR"
  chown -R www-data:www-data "$PHP_TMP/nginx-body" 2>/dev/null || true
  chmod 0700 "$PHP_TMP/nginx-body" 2>/dev/null || true
  mkdir -p "$SITES_DIR" "$PREV_DIR" "$(dirname "$STATE_FILE")"
  if [ ! -f "$RAM_NGINX_CONF" ]; then
    cat > "$RAM_NGINX_CONF" <<'EOF'
# Active site RAM configuration
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
EOF
    chmod 0644 "$RAM_NGINX_CONF"
  fi
  # Ensure /etc/nginx/wtc-site.conf points to the active RAM disk configuration
  if [ ! -L "$NGINX_SNIPPET" ] || [ "$(readlink -f "$NGINX_SNIPPET" 2>/dev/null)" != "$RAM_NGINX_CONF" ]; then
    ln -sfn "$RAM_NGINX_CONF" "$NGINX_SNIPPET" 2>/dev/null || true
  fi
}

# Sync main Web Management UI from SD card (/opt/wtc-web/ui) into RAM drive (/mnt/ramdisk/ui)
reload_ui_ram() {
  ensure_ramdisk
  if [ -d "$SD_UI_DIR" ]; then
    mkdir -p "$RAM_UI_DIR"
    if command -v rsync >/dev/null 2>&1; then
      rsync -a --delete "$SD_UI_DIR/" "$RAM_UI_DIR/"
    else
      cp -a "$SD_UI_DIR/." "$RAM_UI_DIR/"
    fi
    chown -R wtc-ui:wtc-ui "$RAM_UI_DIR" 2>/dev/null || chown -R "$WEB_USER:$WEB_USER" "$RAM_UI_DIR" 2>/dev/null || true
    chmod -R u=rwX,go=rX "$RAM_UI_DIR" 2>/dev/null || true
    log "Synced Management UI from SD ($SD_UI_DIR) into RAM drive ($RAM_UI_DIR)"
  else
    log "Warning: SD UI directory $SD_UI_DIR does not exist"
  fi
}

# Pack <dir> into SITES_DIR/<name>.tar.gz atomically, keeping one previous copy.
# Also writes cached metadata in SITES_DIR/<name>.meta.json for instant UI listing.
# Sets EXISTED=1 if the site already existed.
store_dir_as_site() {
  local name="$1" dir="$2" out
  mkdir -p "$SITES_DIR" "$PREV_DIR"
  out="$(mktemp "$SITES_DIR/.new.XXXXXX")"
  if ! tar -czf "$out" -C "$dir" .; then rm -f "$out"; return 1; fi
  EXISTED=0
  if [ -f "$SITES_DIR/$name.tar.gz" ]; then
    EXISTED=1
    cp -f "$SITES_DIR/$name.tar.gz" "$PREV_DIR/$name.tar.gz"
  fi
  chmod 0644 "$out"
  mv -f "$out" "$SITES_DIR/$name.tar.gz"

  # Fast metadata generation directly from $dir without needing tar -tf later
  local stack="unknown" hasBuild="false"
  if [ -d "$dir" ]; then
    stack="$(detect_site_stack "$dir")"
    [ -f "$dir/package.json" ] && hasBuild="true"
  fi
  cat > "$SITES_DIR/$name.meta.json" <<EOF
{"stack":"$stack","hasBuild":$hasBuild,"mtime":$(date +%s)}
EOF
  chmod 0644 "$SITES_DIR/$name.meta.json" 2>/dev/null || true
  sync
}

# Serialise swaps, ingests and saves. Child processes spawned by a lock holder
# inherit WTC_LOCKED and skip re-locking.
acquire_lock() {
  local timeout="${1:-15}"
  if [ -z "${WTC_LOCKED:-}" ]; then
    mkdir -p "$(dirname "$LOCK_FILE")" 2>/dev/null || true
    exec 9>"$LOCK_FILE"
    if ! flock -w "$timeout" 9; then
      # If lock acquisition timed out, check if any active process is actually holding the file
      local lock_pids
      lock_pids="$(fuser "$LOCK_FILE" 2>/dev/null || true)"
      if [ -z "$lock_pids" ]; then
        log "Warning: $LOCK_FILE was unowned or stale; releasing lock file."
        rm -f "$LOCK_FILE"
        exec 9>"$LOCK_FILE"
        flock -w 5 9 || die "Could not obtain lock $LOCK_FILE after clearing stale lock"
      else
        die "Could not obtain lock $LOCK_FILE (held by PID: $lock_pids)"
      fi
    fi
    export WTC_LOCKED=1
  fi
}

# Persist a key=value in wtc.conf (callers must validate the value).
set_conf() {
  local k="$1" v="$2"
  if grep -q "^$k=" "$WTC_CONF" 2>/dev/null; then
    sed -i "s|^$k=.*|$k=$v|" "$WTC_CONF"
  else
    echo "$k=$v" >> "$WTC_CONF"
  fi
}

php_fpm_unit() {
  systemctl list-unit-files --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -n1
}

# ---------------------------------------------------------------- database (MariaDB)
# Each site gets its own database + user (w_<site>), random password, kept in
# /etc/wtc-web/db/<site>.env (root only). On every swap the credentials are handed to
# the live site as fastcgi params ($_SERVER['DB_NAME'] ...) and as an untracked
# /.wtc-db.php file in the RAM release (never stored in the site archive).

# site -> safe identifier (<=28 chars, [a-z0-9_]); hashed suffix keeps "a-b" and "a_b" distinct.
db_ident() {
  local s="${1//-/_}" h
  if [ "${#s}" -gt 26 ] || [[ "$1" == *-* ]]; then
    h="$(printf '%s' "$1" | md5sum | cut -c1-5)"
    s="${s:0:20}_$h"
  fi
  echo "w_$s"
}

db_available()  { command -v mariadb >/dev/null 2>&1 && systemctl is-active --quiet mariadb 2>/dev/null; }
db_sql()        { mariadb --batch --skip-column-names -e "$1"; }
db_cred_file()  { echo "$DB_CRED_DIR/$1.env"; }
db_load()       { local f; f="$(db_cred_file "$1")"; [ -f "$f" ] || return 1; . "$f"; }

# db_create <site> [rotate]  - create DB+user (idempotent). "rotate" sets a new password.
db_create() {
  local site="$1" mode="${2:-}" id pw f
  valid_name "$site" || return 1
  db_available || { log "MariaDB is not running/installed"; return 1; }
  f="$(db_cred_file "$site")"
  if [ -f "$f" ] && [ "$mode" != rotate ]; then return 0; fi
  id="$(db_ident "$site")"
  pw="$(openssl rand -hex 12)"
  db_sql "CREATE DATABASE IF NOT EXISTS \`$id\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$id'@'localhost' IDENTIFIED BY '$pw';
CREATE USER IF NOT EXISTS '$id'@'127.0.0.1' IDENTIFIED BY '$pw';
ALTER USER '$id'@'localhost' IDENTIFIED BY '$pw';
ALTER USER '$id'@'127.0.0.1' IDENTIFIED BY '$pw';
GRANT ALL PRIVILEGES ON \`$id\`.* TO '$id'@'localhost';
GRANT ALL PRIVILEGES ON \`$id\`.* TO '$id'@'127.0.0.1';
FLUSH PRIVILEGES;" || return 1
  install -d -m 0700 "$DB_CRED_DIR"
  cat > "$f" <<EOF
DB_HOST=localhost
DB_PORT=3306
DB_NAME=$id
DB_USER=$id
DB_PASS=$pw
DB_SOCKET=/run/mysqld/mysqld.sock
EOF
  chmod 0600 "$f"
  log "Database '$id' ready for site '$site'"
}

db_drop() {
  local site="$1" id
  valid_name "$site" || return 1
  db_available || return 1
  id="$(db_ident "$site")"
  db_sql "DROP DATABASE IF EXISTS \`$id\`; DROP USER IF EXISTS '$id'@'localhost'; DROP USER IF EXISTS '$id'@'127.0.0.1'; FLUSH PRIVILEGES;" || return 1
  rm -f "$(db_cred_file "$site")"
  log "Dropped database '$id'"
}

# Create on first activation when DB_AUTO_CREATE=yes.
db_ensure() {
  [ "$DB_AUTO_CREATE" = yes ] || return 0
  [ -f "$(db_cred_file "$1")" ] && return 0
  db_create "$1"
}

# Dump site MariaDB database to a SQL file safely
db_dump() {
  local site="$1" dest="$2" id f db_user db_pass db_host="127.0.0.1" db_port="3306" dump_cmd=""
  valid_name "$site" || return 1
  db_available || return 1
  id="$(db_ident "$site")"
  f="$(db_cred_file "$site")"

  if command -v mariadb-dump >/dev/null 2>&1; then
    dump_cmd="mariadb-dump"
  elif command -v mysqldump >/dev/null 2>&1; then
    dump_cmd="mysqldump"
  else
    log "Neither mariadb-dump nor mysqldump available"
    return 1
  fi

  if [ -f "$f" ]; then
    # shellcheck disable=SC1090
    . "$f"
    db_user="${DB_USER:-$id}"
    db_pass="${DB_PASS:-}"
    db_host="${DB_HOST:-127.0.0.1}"
    db_port="${DB_PORT:-3306}"
    id="${DB_NAME:-$id}"
  fi

  # Attempt dump using site credentials first, fallback to root unix socket
  if [ -n "${db_pass:-}" ]; then
    if "$dump_cmd" --single-transaction --quick --skip-lock-tables \
        -h "$db_host" -P "$db_port" -u "$db_user" -p"$db_pass" "$id" > "$dest" 2>/dev/null; then
      return 0
    fi
  fi

  # Fallback to root socket / default auth
  "$dump_cmd" --single-transaction --quick --skip-lock-tables "$id" > "$dest" 2>/dev/null
}

# db_write_files <site> <release-dir> : write .wtc-db.php, fastcgi snippet, and sync .env
db_write_files() {
  local site="$1" rel="$2" snip="$RAM_MOUNT/.phpsnip.new" k v
  : > "$snip"
  if db_load "$site"; then
    for k in HOST PORT NAME USER PASS SOCKET; do v="DB_$k"; echo "fastcgi_param DB_$k \"${!v}\";" >> "$snip"; done
    cat > "$rel/.wtc-db.php" <<EOF
<?php
// Generated by WTC at activation. Managed from the UI - do not edit or commit.
return ['host'=>'$DB_HOST','port'=>$DB_PORT,'name'=>'$DB_NAME','user'=>'$DB_USER','pass'=>'$DB_PASS','socket'=>'$DB_SOCKET'];
EOF
    chown "$WEB_USER:$WEB_USER" "$rel/.wtc-db.php"
    chmod 0640 "$rel/.wtc-db.php"

    # Sync into Laravel / standard .env if present
    if [ -f "$rel/.env" ]; then
      sed -i "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" "$rel/.env" 2>/dev/null || true
      sed -i "s|^DB_HOST=.*|DB_HOST=$DB_HOST|" "$rel/.env" 2>/dev/null || true
      sed -i "s|^DB_PORT=.*|DB_PORT=$DB_PORT|" "$rel/.env" 2>/dev/null || true
      sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" "$rel/.env" 2>/dev/null || true
      sed -i "s|^DB_USERNAME=.*|DB_USERNAME=$DB_USER|" "$rel/.env" 2>/dev/null || true
      sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" "$rel/.env" 2>/dev/null || true
      # Generate APP_KEY if empty in .env
      if grep -qE '^APP_KEY=\s*$' "$rel/.env" 2>/dev/null || ! grep -q 'APP_KEY=' "$rel/.env" 2>/dev/null; then
        local kgen
        kgen="base64:$(openssl rand -base64 32)"
        if grep -q '^APP_KEY=' "$rel/.env"; then
          sed -i "s|^APP_KEY=.*|APP_KEY=$kgen|" "$rel/.env" 2>/dev/null || true
        else
          echo "APP_KEY=$kgen" >> "$rel/.env"
        fi
      fi
    fi
  fi
}

# (Re)write the managed Samba shares. Honors SAMBA_GUEST (yes|no).
write_samba_block() {
  local smb=/etc/samba/smb.conf access
  [ -f "$smb" ] || return 0
  if [ "${SAMBA_GUEST:-yes}" = yes ]; then
    access="   guest ok = yes"
  else
    access=$'   guest ok = no\n   valid users = '"$SAMBA_USER"
  fi
  sed -i '/# WTC-BEGIN/,/# WTC-END/d' "$smb"
  cat >> "$smb" <<EOF
# WTC-BEGIN
[dropbox]
   path = $DROP_DIR
   comment = Drop site archives here
   browseable = yes
   writable = yes
$access
   force group = $DROP_GROUP
   create mask = 0664
   directory mask = 2775

[rejected]
   path = $REJECT_DIR
   comment = Rejected uploads and reasons
   read only = yes
$access
# WTC-END
EOF
  systemctl reload smbd 2>/dev/null || systemctl restart smbd 2>/dev/null || true
}

# (Re)write managed vsftpd config. Honors FTP_GUEST (yes|no).
write_ftp_block() {
  local conf=/etc/vsftpd.conf
  [ -f "$conf" ] || touch "$conf" 2>/dev/null || return 0
  local anon="NO"
  if [ "${FTP_GUEST:-yes}" = yes ]; then
    anon="YES"
  fi
  sed -i '/# WTC-BEGIN/,/# WTC-END/d' "$conf" 2>/dev/null || true
  cat >> "$conf" <<EOF
# WTC-BEGIN
listen=YES
listen_ipv6=NO
anonymous_enable=$anon
anon_root=$DROP_DIR
anon_upload_enable=YES
anon_mkdir_write_enable=YES
anon_other_write_enable=YES
no_anon_password=YES
local_enable=YES
write_enable=YES
local_umask=002
check_shell=NO
chroot_local_user=YES
allow_writeable_chroot=YES
local_root=$DROP_DIR
pasv_enable=YES
pasv_min_port=40000
pasv_max_port=40100
# WTC-END
EOF
  systemctl restart vsftpd 2>/dev/null || true
}

# ---------------------------------------------------------------- stack detection & nginx routing
detect_site_stack() {
  local dir="$1"
  [ -d "$dir" ] || { echo "unknown"; return 0; }
  if [ -f "$dir/artisan" ] || [ -f "$dir/public/index.php" ]; then
    echo "laravel"
  elif [ -f "$dir/package.json" ] && grep -qiE '"(react|vue|vite|@angular|svelte)"' "$dir/package.json" 2>/dev/null; then
    echo "spa"
  elif [ -f "$dir/index.html" ] && [ ! -f "$dir/index.php" ]; then
    echo "spa"
  elif [ -f "$dir/index.php" ] || [ -f "$dir/wp-config.php" ] || find "$dir" -maxdepth 2 -name '*.php' 2>/dev/null | grep -q .; then
    echo "php"
  else
    echo "static"
  fi
}

generate_stack_nginx_conf() {
  local stack="$1" rel="$2" out="${3:-$RAM_NGINX_CONF}"
  mkdir -p "$(dirname "$out")"

  case "$stack" in
    laravel)
      cat > "$out" <<'EOF'
# WTC Managed Configuration: Laravel Stack
# Project Webroot: /mnt/ramdisk/webroot (points to public/)
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ^~ /build/ {
    root /mnt/ramdisk/webroot;
    access_log off;
    expires max;
    add_header Cache-Control "public, max-age=31536000, immutable";
    types {
        text/javascript js mjs;
        application/javascript js mjs;
        application/wasm wasm;
        text/css css;
        application/json json map;
        image/svg+xml svg svgz;
        image/png png PNG;
        image/jpeg jpg jpeg JPG JPEG;
        image/webp webp WEBP;
        image/avif avif AVIF;
        image/x-icon ico ICO;
        font/woff2 woff2;
        font/woff woff;
        font/ttf ttf;
    }
}

location ^~ /assets/ {
    root /mnt/ramdisk/webroot;
    access_log off;
    expires max;
    types {
        text/javascript js mjs;
        application/javascript js mjs;
        application/wasm wasm;
        text/css css;
        application/json json map;
        image/svg+xml svg svgz;
        image/png png PNG;
        image/jpeg jpg jpeg JPG JPEG;
        image/webp webp WEBP;
        image/avif avif AVIF;
        image/x-icon ico ICO;
        font/woff2 woff2;
        font/woff woff;
        font/ttf ttf;
    }
}

location ^~ /storage/ {
    root /mnt/ramdisk/webroot;
    access_log off;
    expires 30d;
}

location ~* \.(?:css|js|mjs|map|jpe?g|gif|png|webp|ico|svg|woff2?|ttf|eot|json|wasm|webmanifest|avif)$ {
    root /mnt/ramdisk/webroot;
    try_files $uri =404;
    access_log off;
    types {
        text/javascript js mjs;
        application/javascript js mjs;
        application/wasm wasm;
        text/css css;
        application/json json map;
        image/svg+xml svg svgz;
        image/png png PNG;
        image/jpeg jpg jpeg JPG JPEG;
        image/webp webp WEBP;
        image/avif avif AVIF;
        image/x-icon ico ICO;
        font/woff2 woff2;
        font/woff woff;
        font/ttf ttf;
    }
}
EOF
      ;;

    spa)
      cat > "$out" <<'EOF'
# WTC Managed Configuration: Single Page Application (SPA / React / Vite / Vue)
location / {
    try_files $uri $uri/ /index.html /index.php?$query_string;
}

location ^~ /assets/ {
    root /mnt/ramdisk/webroot;
    access_log off;
    expires max;
}

location ^~ /static/ {
    root /mnt/ramdisk/webroot;
    access_log off;
    expires max;
}

location ~ \.(?:css|js|map|jpe?g|gif|png|webp|ico|svg|woff2?|ttf|eot|json)$ {
    root /mnt/ramdisk/webroot;
    try_files $uri =404;
    access_log off;
}
EOF
      ;;

    php)
      cat > "$out" <<'EOF'
# WTC Managed Configuration: Classic PHP Stack
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.(?:css|js|map|jpe?g|gif|png|webp|ico|svg|woff2?|ttf|eot|json)$ {
    root /mnt/ramdisk/webroot;
    try_files $uri =404;
    access_log off;
}
EOF
      ;;

    *)
      cat > "$out" <<'EOF'
# WTC Managed Configuration: Static HTML / Assets Stack
location / {
    try_files $uri $uri/ /index.html =404;
}

location ~ \.(?:css|js|map|jpe?g|gif|png|webp|ico|svg|woff2?|ttf|eot|json)$ {
    root /mnt/ramdisk/webroot;
    try_files $uri =404;
    access_log off;
}
EOF
      ;;
  esac
  chmod 0644 "$out"
}

# Generate dedicated Port 80 location blocks for multi-site concurrent hosting at /<slug>/
generate_site_nginx_block() {
  local site="$1" slug="$2" site_dir="$3" webroot="$4" out="$5"
  mkdir -p "$(dirname "$out")"

  cat > "$out" <<EOF
# WTC Managed Multi-Site: $site (slug: $slug)
location = /$slug {
    return 301 /$slug/;
}

location ^~ /$slug/ {
    alias $webroot/;
    index index.html index.php index.htm;

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

    # Inject base href so client-side SPA routers and relative assets resolve to /$slug/
    sub_filter_once on;
    sub_filter_types text/html;
    sub_filter '<head>' '<head><base href="/$slug/">';
    sub_filter '<HEAD>' '<HEAD><base href="/$slug/">';
    sub_filter '<base href="/"' '<base href="/$slug/"';

    # 1. Dedicated prefix blocks for Vite build assets and Laravel public storage
    location ^~ /$slug/build/ {
        alias $webroot/build/;
        access_log off;
        expires 30d;
        add_header Cache-Control "public, max-age=2592000, immutable";
        types {
            text/javascript js mjs;
            application/javascript js mjs;
            application/wasm wasm;
            text/css css;
            application/json json map;
            image/svg+xml svg svgz;
            image/png png PNG;
            image/jpeg jpg jpeg JPG JPEG;
            image/webp webp WEBP;
            image/avif avif AVIF;
            image/x-icon ico ICO;
            font/woff2 woff2;
            font/woff woff;
            font/ttf ttf;
        }
    }

    location ^~ /$slug/storage/ {
        alias $site_dir/storage/app/public/;
        access_log off;
        expires 30d;
    }

    # 2. Static assets: serve directly from webroot with proper caching (never return HTML fallback)
    location ~* ^/$slug/(.+\.(?:css|js|mjs|map|jpe?g|gif|png|webp|ico|svg|woff2?|ttf|eot|json|wasm|webmanifest|avif))\$ {
        alias $webroot/\$1;
        access_log off;
        expires 30d;
        types {
            text/javascript js mjs;
            application/javascript js mjs;
            application/wasm wasm;
            text/css css;
            application/json json map;
            image/svg+xml svg svgz;
            image/png png PNG;
            image/jpeg jpg jpeg JPG JPEG;
            image/webp webp WEBP;
            image/avif avif AVIF;
            image/x-icon ico ICO;
            font/woff2 woff2;
            font/woff woff;
            font/ttf ttf;
        }
    }

    # 3. PHP scripts under /$slug/
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_split_path_info ^/$slug/(.+\.php)(.*)\$;
        fastcgi_param SCRIPT_FILENAME $webroot/\$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME /$slug/\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        fastcgi_param DOCUMENT_ROOT $webroot;
        fastcgi_param HTTP_X_FORWARDED_PREFIX /$slug;
        fastcgi_param HTTP_X_FORWARDED_PROTO \$scheme;
        fastcgi_param HTTP_X_FORWARDED_HOST \$http_host;
        fastcgi_pass unix:/run/php/wtc-site.sock;
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
    }

    # 4. Non-asset SPA client routes / directories fallback
    try_files \$uri \$uri/ @${slug}_gateway;
}

location @${slug}_gateway {
    root $webroot;
    try_files /index.html @${slug}_fcgi;
}

location @${slug}_fcgi {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $webroot/index.php;
    fastcgi_param SCRIPT_NAME /$slug/index.php;
    fastcgi_param DOCUMENT_ROOT $webroot;
    fastcgi_param REQUEST_URI \$request_uri;
    fastcgi_param QUERY_STRING \$query_string;
    fastcgi_param HTTP_X_FORWARDED_PREFIX /$slug;
    fastcgi_param HTTP_X_FORWARDED_PROTO \$scheme;
    fastcgi_param HTTP_X_FORWARDED_HOST \$http_host;
    fastcgi_pass unix:/run/php/wtc-site.sock;
    fastcgi_read_timeout 600s;
    fastcgi_send_timeout 600s;
}
EOF
  chmod 0644 "$out"
}



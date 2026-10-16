#!/usr/bin/env bash
# 02-manage-site.sh -> /usr/local/bin/wtc-manage
# Privileged backend for the web UI (port 4485) and an SSH/terminal fallback.
# The web UI calls this through a sudoers whitelist, so EVERY argument is validated here.
#
#   wtc-manage                         interactive whiptail menu
#   --list | --current | --status | --logs [N]
#   --set S | --rollback S | --delete S | --create S | --ingest | --save-state
#   --json sites|status|dropbox|settings|transfer|stack
#   --setting KEY VALUE
#   --transfer samba on|off | ssh on|off | samba-guest yes|no | samba-password   (password on stdin)
#   --stack install|remove ID
set -Eeuo pipefail
# shellcheck disable=SC1091
. "${WTC_LIB:-/usr/local/lib/wtc-web/common.sh}"

if [ "$(id -u)" -ne 0 ]; then exec sudo -n -E "$0" "$@"; fi

STACK_IDS=(php-web php-db php-imaging php-cache mariadb redis memcached composer nodejs)
declare -A STACK_PKGS=(
  [php-web]="php-curl php-xml php-mbstring php-zip php-intl php-gd php-bcmath"
  [php-db]="php-mysql php-sqlite3"
  [php-imaging]="php-imagick"
  [php-cache]="php-apcu php-redis"
  [mariadb]="mariadb-server"
  [redis]="redis-server"
  [memcached]="memcached php-memcached"
  [composer]="composer"
  [nodejs]="nodejs npm"
)
declare -A STACK_LABELS=(
  [php-web]="PHP web extensions (curl, xml, mbstring, zip, intl, gd, bcmath)"
  [php-db]="PHP database drivers (MySQL/MariaDB, SQLite)"
  [php-imaging]="PHP Imagick"
  [php-cache]="PHP caching (APCu, Redis ext)"
  [mariadb]="MariaDB server"
  [redis]="Redis server"
  [memcached]="Memcached + PHP ext"
  [composer]="Composer"
  [nodejs]="Node.js & npm (for React builds)"
)
STACK_LOG=/var/log/wtc-web/stack-job.log
BUILD_LOG=/var/log/wtc-web/build-job.log

# ------------------------------------------------------------ helpers
b2j() { [ "$1" = yes ] && echo true || echo false; }
svc_active()  { systemctl is-active --quiet "$1" 2>/dev/null && echo yes || echo no; }
svc_enabled() { systemctl is-enabled --quiet "$1" 2>/dev/null && echo yes || echo no; }
# Ubuntu 24.04 uses socket activation: sshd may be idle (ssh.service inactive) while ssh.socket listens.
ssh_active()  { if systemctl is-active --quiet ssh.service || systemctl is-active --quiet ssh.socket; then echo yes; else echo no; fi; }
ssh_enabled() { if systemctl is-enabled --quiet ssh.service 2>/dev/null || systemctl is-enabled --quiet ssh.socket 2>/dev/null; then echo yes; else echo no; fi; }

# Fast dpkg status: single batch query rather than looping per package
STACK_CACHE_FILE="/mnt/ramdisk/.stack_status.json"
PKG_CACHE_FILE="/mnt/ramdisk/.installed_pkgs.txt"

refresh_pkg_cache() {
  local all_pkgs=()
  for id in "${STACK_IDS[@]}"; do
    # shellcheck disable=SC2206
    all_pkgs+=(${STACK_PKGS[$id]})
  done
  mkdir -p "$(dirname "$PKG_CACHE_FILE")"
  dpkg-query -W -f='${binary:Package} ${Status}\n' "${all_pkgs[@]}" 2>/dev/null \
    | awk '/install ok installed/ {print $1}' > "$PKG_CACHE_FILE.tmp" 2>/dev/null || true
  mv -f "$PKG_CACHE_FILE.tmp" "$PKG_CACHE_FILE" 2>/dev/null || true
}

pkg_installed() {
  local p="$1"
  if [ ! -f "$PKG_CACHE_FILE" ]; then
    refresh_pkg_cache
  fi
  grep -qx "$p" "$PKG_CACHE_FILE" 2>/dev/null
}

stack_installed() {
  local p
  for p in ${STACK_PKGS[$1]}; do pkg_installed "$p" || return 1; done
  return 0
}

# ------------------------------------------------------------ plain commands
cmd_list() {
  local cur s; cur="$(active_site)"
  while read -r s; do
    [ -z "$s" ] && continue
    if [ "$s" = "$cur" ]; then echo "* $s (active)"; else echo "  $s"; fi
  done < <(list_sites)
}

cmd_status() {
  echo "Active site : $(active_site)"
  echo "Nginx       : $(svc_active nginx)"
  echo "RAM disk    : $(df -h --output=used,size,pcent "$RAM_MOUNT" 2>/dev/null | tail -n1)"
  echo "Dropbox     : $(find "$DROP_DIR" -maxdepth 1 -type f 2>/dev/null | wc -l) pending"
  echo "Rejected    : $(find "$REJECT_DIR" -maxdepth 1 -name '*.error' 2>/dev/null | wc -l)"
  echo "Sites       : $(list_sites | wc -l)"
}

cmd_logs() {
  local n="${1:-50}"
  [[ "$n" =~ ^[0-9]{1,4}$ ]] || n=50
  tail -n "$n" "$LOG_FILE" 2>/dev/null || true
}

cmd_set() {
  valid_name "${1:-}" || die "Invalid site name"
  "$SERVICE_BIN" --swap "$1"
} 
cmd_activate() {
  valid_name "${1:-}" || die "Invalid site name"
  local make_primary="${2:-1}"
  "$SERVICE_BIN" --activate "$1" "$make_primary"
}

cmd_deactivate() {
  valid_name "${1:-}" || die "Invalid site name"
  "$SERVICE_BIN" --deactivate "$1"
}

cmd_rollback() {
  valid_name "${1:-}" || die "Invalid site name"
  [ -f "$PREV_DIR/$1.tar.gz" ] || die "No previous version of '$1'"
  acquire_lock
  cp -f "$SITES_DIR/$1.tar.gz" "$PREV_DIR/$1.tar.gz.swap"
  mv -f "$PREV_DIR/$1.tar.gz" "$SITES_DIR/$1.tar.gz"
  mv -f "$PREV_DIR/$1.tar.gz.swap" "$PREV_DIR/$1.tar.gz"
  log "Rolled back '$1'"
  if is_site_active "$1"; then "$SERVICE_BIN" --activate "$1"; fi
}

cmd_delete() {
  valid_name "${1:-}" || die "Invalid site name"
  is_site_active "$1" && die "Cannot delete an active site; stop/deactivate it first"
  [ -f "$SITES_DIR/$1.tar.gz" ] || die "No such site"
  rm -f "$SITES_DIR/$1.tar.gz" "$PREV_DIR/$1.tar.gz" "$SITES_DIR/$1.meta.json"
  log "Deleted site '$1'"
}

cmd_create() {
  valid_name "${1:-}" || die "Invalid site name (a-z 0-9 - _)"
  [ -f "$SITES_DIR/$1.tar.gz" ] && die "Site exists"
  local t; t="$(mktemp -d)"
  cat > "$t/index.php" <<EOF
<?php // skeleton for $1 ?>
<!doctype html><title>$1</title><h1>$1</h1>
<p>PHP <?= PHP_VERSION ?> - replace this site by uploading $1.tar.gz.</p>
EOF
  tar -czf "$SITES_DIR/$1.tar.gz" -C "$t" .
  chmod 0644 "$SITES_DIR/$1.tar.gz"
  rm -rf "$t"
  log "Created skeleton site '$1'"
}

cmd_direct_deploy() {
  local site="${1:-}" stageTarget="${2:-}" stageDir="" is_temp=0
  valid_name "$site" || die "Invalid site name"
  ensure_ramdisk

  if [ -d "$stageTarget" ]; then
    stageDir="$stageTarget"
  elif [ -f "$stageTarget" ]; then
    stageDir="$(mktemp -d "$STAGE_DIR/.deploy.XXXXXX")"
    is_temp=1
    case "$stageTarget" in
      *.tar.gz|*.tgz|*.tar)
        tar -xf "$stageTarget" -C "$stageDir" --no-same-owner --no-same-permissions || die "Tar extraction failed"
        ;;
      *.zip)
        unzip -qo "$stageTarget" -d "$stageDir" || die "Zip extraction failed"
        ;;
      *) die "Unsupported archive format: $stageTarget" ;;
    esac
    # Ignore __MACOSX if present
    rm -rf "$stageDir/__MACOSX" 2>/dev/null || true

    local entries; entries="$(find "$stageDir" -mindepth 1 -maxdepth 1 ! -name '.*' ! -name '__MACOSX' | wc -l)"
    local has_root=0
    [ -f "$stageDir/index.php" ] || [ -f "$stageDir/index.html" ] || [ -f "$stageDir/artisan" ] && has_root=1

    if [ "$has_root" -eq 0 ] && [ "$entries" -eq 1 ]; then
      local only; only="$(find "$stageDir" -mindepth 1 -maxdepth 1 ! -name '.*' ! -name '__MACOSX')"
      if [ -d "$only" ]; then
        local unwrap_tmp; unwrap_tmp="$(mktemp -d "$STAGE_DIR/.unwrap.XXXXXX")"
        ( shopt -s dotglob nullglob; mv "$only"/* "$unwrap_tmp"/ 2>/dev/null || true )
        rm -rf "$stageDir"
        stageDir="$unwrap_tmp"
      fi
    fi
  else
    die "Staged folder or archive not found: $stageTarget"
  fi

  # 1. Package and store site archive into /opt/wtc-web/sites so it is permanently deployable & visible
  store_dir_as_site "$site" "$stageDir" || die "Failed to save site package to $SITES_DIR"

  # 2. Swap directly in RAM immediately - zero delay
  "$SERVICE_BIN" --swap-dir "$site" "$stageDir"

  if [ "$is_temp" -eq 1 ]; then
    rm -rf "$stageDir"
    rm -f "$stageTarget"
  else
    rm -rf "$stageDir"
  fi

  log "Direct deployed '$site' into RAM disk instantly and saved package to sites repository"
}

cmd_store_site() {
  local site="${1:-}" stageTarget="${2:-}" stageDir="" is_temp=0
  valid_name "$site" || die "Invalid site name"
  ensure_ramdisk

  if [ -d "$stageTarget" ]; then
    stageDir="$stageTarget"
  elif [ -f "$stageTarget" ]; then
    stageDir="$(mktemp -d "$STAGE_DIR/.deploy.XXXXXX")"
    is_temp=1
    case "$stageTarget" in
      *.tar.gz|*.tgz|*.tar) tar -xf "$stageTarget" -C "$stageDir" --no-same-owner --no-same-permissions || die "Tar extraction failed" ;;
      *.zip) unzip -qo "$stageTarget" -d "$stageDir" || die "Zip extraction failed" ;;
      *) die "Unsupported archive format: $stageTarget" ;;
    esac
    local entries; entries="$(find "$stageDir" -mindepth 1 -maxdepth 1 | wc -l)"
    if [ "$entries" -eq 1 ]; then
      local only; only="$(find "$stageDir" -mindepth 1 -maxdepth 1)"
      if [ -d "$only" ]; then
        local unwrap_tmp; unwrap_tmp="$(mktemp -d "$STAGE_DIR/.unwrap.XXXXXX")"
        mv "$only"/* "$unwrap_tmp"/ 2>/dev/null || true
        rm -rf "$stageDir"
        stageDir="$unwrap_tmp"
      fi
    fi
  else
    die "Staged folder or archive not found: $stageTarget"
  fi

  store_dir_as_site "$site" "$stageDir" || die "Failed to store site package"
  rm -rf "$stageDir"
  [ "$is_temp" -eq 1 ] && rm -f "$stageTarget"
  log "Stored '$site' into /opt/wtc-web/sites repository"
}

cmd_server_tune() {
  ensure_ramdisk
  local phpv; phpv="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo '8.2')"
  
  mkdir -p "$PHP_TMP/nginx-body" "$DROP_DIR" "$STAGE_DIR"
  chown -R www-data:www-data "$PHP_TMP/nginx-body" 2>/dev/null || true
  chmod 0700 "$PHP_TMP/nginx-body" 2>/dev/null || true
  chmod 1777 "$PHP_TMP" "$STAGE_DIR" 2>/dev/null || true
  chmod 2775 "$DROP_DIR" 2>/dev/null || true

  # 1. Management UI Pool: Single worker thread/process
  local fpm_ui_pool="/etc/php/$phpv/fpm/pool.d/wtc-ui.conf"
  if [ -f "$fpm_ui_pool" ]; then
    sed -i 's/.*upload_max_filesize.*/php_admin_value[upload_max_filesize] = 1024M/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's/.*post_max_size.*/php_admin_value[post_max_size] = 1024M/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's/.*max_execution_time.*/php_admin_value[max_execution_time] = 600/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's/.*max_input_time.*/php_admin_value[max_input_time] = 600/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's/.*max_file_uploads.*/php_admin_value[max_file_uploads] = 10000/' "$fpm_ui_pool" 2>/dev/null || true
    if ! grep -q 'max_input_vars' "$fpm_ui_pool"; then
      echo "php_admin_value[max_input_vars] = 50000" >> "$fpm_ui_pool"
    fi
    sed -i 's/^pm = .*/pm = static/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's/.*pm\.max_children.*/pm.max_children = 1/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i '/^pm\.start_servers/d;/^pm\.min_spare_servers/d;/^pm\.max_spare_servers/d' "$fpm_ui_pool" 2>/dev/null || true
  fi

  # 2. Live Site Pool: Dedicated multi-worker processes
  local fpm_site_pool="/etc/php/$phpv/fpm/pool.d/wtc-site.conf"
  if [ -f "$fpm_site_pool" ]; then
    sed -i 's/.*upload_max_filesize.*/php_admin_value[upload_max_filesize] = 1024M/' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's/.*post_max_size.*/php_admin_value[post_max_size] = 1024M/' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's/.*max_execution_time.*/php_admin_value[max_execution_time] = 600/' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's/.*max_input_time.*/php_admin_value[max_input_time] = 600/' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's/.*max_file_uploads.*/php_admin_value[max_file_uploads] = 10000/' "$fpm_site_pool" 2>/dev/null || true
    if ! grep -q 'max_input_vars' "$fpm_site_pool"; then
      echo "php_admin_value[max_input_vars] = 50000" >> "$fpm_site_pool"
    fi
    sed -i 's/^pm = .*/pm = dynamic/' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's/.*pm\.max_children.*/pm.max_children = 8/' "$fpm_site_pool" 2>/dev/null || true
    if ! grep -q 'pm.start_servers' "$fpm_site_pool"; then
      cat >> "$fpm_site_pool" <<'EOF'
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
EOF
    fi
  fi

  local fpm_ini="/etc/php/$phpv/fpm/conf.d/90-wtc.ini"
  if [ -f "$fpm_ini" ]; then
    sed -i 's/.*upload_max_filesize.*/upload_max_filesize = 1024M/' "$fpm_ini" 2>/dev/null || true
    sed -i 's/.*post_max_size.*/post_max_size = 1024M/' "$fpm_ini" 2>/dev/null || true
    sed -i 's/.*max_execution_time.*/max_execution_time = 600/' "$fpm_ini" 2>/dev/null || true
    sed -i 's/.*max_input_time.*/max_input_time = 600/' "$fpm_ini" 2>/dev/null || true
    sed -i 's/.*max_file_uploads.*/max_file_uploads = 10000/' "$fpm_ini" 2>/dev/null || true
    if ! grep -q 'max_input_vars' "$fpm_ini"; then
      echo "max_input_vars = 50000" >> "$fpm_ini"
    fi
    if ! grep -q 'opcache.enable' "$fpm_ini"; then
      cat >> "$fpm_ini" <<'EOF'
opcache.enable = 1
opcache.memory_consumption = 64
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1
EOF
    fi
  fi

  systemctl reload nginx 2>/dev/null || true
  systemctl reload "php$phpv-fpm.service" 2>/dev/null || true
  log "Server tuned for high-speed RAM UI and uploads (pm=dynamic, opcache=on, 1024M uploads)"
}

cmd_reload_ui() {
  "$SERVICE_BIN" --reload-ui
  local unit
  unit="$(php_fpm_unit)"
  [ -n "$unit" ] && systemctl reload "$unit" 2>/dev/null || true
  log "Reloaded Web Management UI from SD ($SD_UI_DIR) into RAM disk ($RAM_UI_DIR)"
}

# ------------------------------------------------------------ database management
cmd_db_create() {
  local site="${1:-}"
  valid_name "$site" || die "Invalid site name"
  db_create "$site" || die "Could not create database for '$site'"
  log "Database initialized for site '$site'"
}

cmd_db_import() {
  local site="${1:-}" id
  valid_name "$site" || die "Invalid site name"
  db_available || die "MariaDB is not running"
  db_ensure "$site" || die "Could not ensure database"
  id="$(db_ident "$site")"
  mariadb "$id" || die "SQL import into '$id' failed"
  log "Imported SQL into database '$id'"
}

cmd_db_export() {
  local site="${1:-$(active_site)}" dest="${2:-}"
  valid_name "$site" || die "Invalid site name"
  db_available || die "MariaDB is not running"
  if [ -z "$dest" ]; then
    db_dump "$site" "/dev/stdout"
  else
    db_dump "$site" "$dest" || die "Failed to export database for '$site'"
    log "Exported database for '$site' to $dest"
  fi
}

cmd_db_info() {
  local site="${1:-}" id f tables=0 size=0
  valid_name "$site" || die "Invalid site name"
  id="$(db_ident "$site")"
  f="$(db_cred_file "$site")"
  if [ -f "$f" ] && db_available; then
    tables="$(db_sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$id';" 2>/dev/null || echo 0)"
    size="$(db_sql "SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema='$id';" 2>/dev/null || echo 0)"
  fi
  jq -nc --arg id "$id" --argjson tables "${tables:-0}" --argjson size "${size:-0}" \
    --argjson ready "$([ -f "$f" ] && echo true || echo false)" \
    '{name:$id,ready:$ready,tables:$tables,size:$size}'
}

cmd_db_test() {
  local site="${1:-$(active_site)}" id f db_user="" db_pass="" db_host="127.0.0.1" db_name="" ok=false err="" tables=0
  valid_name "$site" || die "Invalid site name"
  id="$(db_ident "$site")"
  f="$(db_cred_file "$site")"

  if ! db_available; then
    jq -nc --arg site "$site" --arg err "MariaDB service is not running" --argjson ok false '{ok:$ok,site:$site,error:$err}'
    return 1
  fi

  if [ -f "$f" ]; then
    # shellcheck disable=SC1090
    . "$f"
    db_user="${DB_USER:-$id}"
    db_pass="${DB_PASS:-}"
    db_host="${DB_HOST:-127.0.0.1}"
    db_name="${DB_NAME:-$id}"
  else
    db_user="$id"
    db_name="$id"
  fi

  # Test connection using credentials
  local test_out
  if test_out="$(mariadb -h "$db_host" -u "$db_user" ${db_pass:+-p"$db_pass"} -D "$db_name" -e "SELECT 1;" 2>&1)"; then
    ok=true
    tables="$(mariadb -h "$db_host" -u "$db_user" ${db_pass:+-p"$db_pass"} -D "$db_name" --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db_name';" 2>/dev/null || echo 0)"
  elif test_out="$(mariadb -D "$db_name" -e "SELECT 1;" 2>&1)"; then
    # Root socket fallback
    ok=true
    tables="$(db_sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db_name';" 2>/dev/null || echo 0)"
  else
    err="$(echo "$test_out" | tail -n 2 | tr '\n' ' ')"
  fi

  jq -nc --arg site "$site" --arg db "$db_name" --arg user "$db_user" --arg host "$db_host" \
    --argjson ok "$ok" --argjson tables "${tables:-0}" --arg err "$err" \
    '{ok:$ok,site:$site,database:$db,user:$user,host:$host,tables:$tables,error:$err}'
}

cmd_db_creds() {
  local site="${1:-$(active_site)}" id f db_user="" db_pass="" db_host="127.0.0.1" db_port="3306" db_name=""
  valid_name "$site" || die "Invalid site name"
  id="$(db_ident "$site")"
  f="$(db_cred_file "$site")"
  if [ -f "$f" ]; then
    # shellcheck disable=SC1090
    . "$f"
    db_user="${DB_USER:-$id}"
    db_pass="${DB_PASS:-}"
    db_host="${DB_HOST:-127.0.0.1}"
    db_port="${DB_PORT:-3306}"
    db_name="${DB_NAME:-$id}"
  else
    db_user="$id"
    db_name="$id"
  fi
  jq -nc --arg host "$db_host" --arg port "$db_port" --arg user "$db_user" --arg pass "$db_pass" --arg name "$db_name" \
    '{host:$host,port:$port,user:$user,pass:$pass,name:$name}'
}

cmd_db_tables() {
  local site="${1:-$(active_site)}" id f
  valid_name "$site" || die "Invalid site name"
  id="$(db_ident "$site")"
  f="$(db_cred_file "$site")"
  db_available || die "MariaDB is not running"
  if [ -f "$f" ]; then
    # shellcheck disable=SC1090
    . "$f"
  else
    DB_NAME="$id"
  fi
  local q="SELECT table_name, COALESCE(table_rows, 0), COALESCE(data_length + index_length, 0) FROM information_schema.tables WHERE table_schema = '${DB_NAME:-$id}' ORDER BY table_name ASC;"
  local raw; raw="$(db_sql "$q" 2>/dev/null || true)"
  if [ -z "$raw" ]; then
    echo "[]"
  else
    echo "$raw" | jq -R -s -c '
      split("\n") | map(select(length > 0) | split("\t")) | map({
        name: .[0],
        rows: (.[1] | tonumber? // 0),
        size: (.[2] | tonumber? // 0)
      })
    '
  fi
}

cmd_db_query() {
  local site="${1:-$(active_site)}" id f sql
  valid_name "$site" || die "Invalid site name"
  id="$(db_ident "$site")"
  f="$(db_cred_file "$site")"
  db_available || die "MariaDB is not running"
  if [ -f "$f" ]; then
    # shellcheck disable=SC1090
    . "$f"
  else
    DB_NAME="$id"
  fi
  if [ -n "${2:-}" ]; then
    sql="$2"
  else
    sql="$(cat)"
  fi
  [ -n "$sql" ] || die "SQL query required"
  mariadb --table -D "${DB_NAME:-$id}" -e "$sql" 2>&1
}

cmd_artisan() {
  local target="${1:-}" cmd="${2:-}" targetDir="" site=""
  [ -n "$target" ] || target="$(active_site)"
  [ -n "$cmd" ] || die "Artisan command required (e.g. migrate, db:seed)"

  if [ -d "$target" ] && [ -f "$target/artisan" ]; then
    targetDir="$target"
    site="$(basename "$target")"
  else
    site="$target"
    valid_name "$site" || die "Invalid site name: $site"
    local live; live="$(readlink -f "$WWW_LINK" 2>/dev/null || true)"
    if [ -d "$live" ] && [ "$(active_site)" = "$site" ] && [ -f "$live/artisan" ]; then
      targetDir="$live"
    elif [ -d "$RAM_MOUNT/sites/$site" ] && [ -f "$RAM_MOUNT/sites/$site/artisan" ]; then
      targetDir="$RAM_MOUNT/sites/$site"
    elif [ -d "$STAGE_DIR/$site" ] && [ -f "$STAGE_DIR/$site/artisan" ]; then
      targetDir="$STAGE_DIR/$site"
    fi
  fi

  [ -n "$targetDir" ] || die "Site '$target' is not active in RAM or has no artisan"
  [ -f "$targetDir/artisan" ] || die "No artisan found in $targetDir"

  [ -n "$site" ] && { db_ensure "$site" || true; }
  # Make sure .env has live DB credentials + APP_KEY before artisan touches the DB
  [ -n "$site" ] && valid_name "$site" && db_write_files "$site" "$targetDir"

  laravel_repair_shim "$site" "$targetDir" || exit 1

  log "Running 'php artisan $cmd' for site '$site' in $targetDir"
  su -s /bin/bash "$WEB_USER" -c "cd '$targetDir' && php artisan $cmd" 2>&1
}

# If a site was uploaded without vendor/, the UI replaced bootstrap/app.php and
# vendor/autoload.php with a display-only WTC shim. Real artisan cannot run on that.
# Restore a genuine Laravel bootstrap and install Composer dependencies.
laravel_repair_shim() {
  local site="$1" dir="$2" shim=0
  grep -q 'WTC Pi Lightweight Laravel Application Bootstrap Shim' "$dir/bootstrap/app.php" 2>/dev/null && shim=1
  grep -q 'WTC Pi Lightweight Laravel Autoloader Shim' "$dir/vendor/autoload.php" 2>/dev/null && shim=1
  # vendor/ missing entirely (never uploaded, or a previous repair failed mid-way)
  [ -f "$dir/composer.json" ] && [ ! -f "$dir/vendor/autoload.php" ] && shim=1
  [ "$shim" -eq 1 ] || return 0

  echo "WTC: '$site' is running on a placeholder shim (vendor/ was not uploaded) - repairing..."
  [ -f "$dir/composer.json" ] || { echo "ERROR: No composer.json in $dir - cannot install Laravel. Re-upload the project including vendor/."; return 1; }
  command -v composer >/dev/null 2>&1 || { echo "ERROR: Composer is not installed. Install it from Stack Components, or re-upload the project including vendor/."; return 1; }

  # 1. Restore real bootstrap/app.php
  if grep -q 'WTC Pi Lightweight' "$dir/bootstrap/app.php" 2>/dev/null; then
    if [ -f "$dir/bootstrap/app.php.wtc-real" ]; then
      mv -f "$dir/bootstrap/app.php.wtc-real" "$dir/bootstrap/app.php"
    else
      local console=""
      [ -f "$dir/routes/console.php" ] && console="        commands: __DIR__.'/../routes/console.php',"
      cat > "$dir/bootstrap/app.php" <<EOF
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
$console
        health: '/up',
    )
    ->withMiddleware(function (Middleware \$middleware) {
        //
    })
    ->withExceptions(function (Exceptions \$exceptions) {
        //
    })->create();
EOF
    fi
  fi

  # 2. Drop the fake autoloader and install real dependencies
  grep -q 'WTC Pi Lightweight' "$dir/vendor/autoload.php" 2>/dev/null && rm -rf "$dir/vendor"
  mkdir -p "$dir/bootstrap/cache" "$dir/storage/framework/"{cache/data,sessions,views} "$dir/storage/logs"
  rm -f "$dir/bootstrap/cache/"*.php 2>/dev/null || true
  chown -R "$WEB_USER:$WEB_USER" "$dir"

  local cenv="cd '$dir' && COMPOSER_HOME='$dir/storage/.composer' COMPOSER_MEMORY_LIMIT=-1 COMPOSER_ALLOW_SUPERUSER=0"
  local cflags="--no-interaction --prefer-dist --optimize-autoloader"
  local out rc=0

  echo "WTC: Running composer install (this can take a few minutes on a Pi)..."
  out="$(su -s /bin/bash "$WEB_USER" -c "$cenv composer install $cflags" 2>&1)" || rc=$?
  echo "$out" | tail -n 25

  if [ "$rc" -ne 0 ] && echo "$out" | grep -qiE 'not present in the lock file|lock file is not up to date|does not contain valid lock'; then
    # composer.json was edited without updating composer.lock - resolve only what's missing
    local missing
    missing="$(echo "$out" | grep -oE 'Required package "[^"]+"' | sed -E 's/.*"([^"]+)"/\1/' | sort -u | xargs)"
    rc=0
    if [ -n "$missing" ]; then
      echo "WTC: composer.lock is out of sync - updating only: $missing"
      out="$(su -s /bin/bash "$WEB_USER" -c "$cenv composer update $missing --with-dependencies $cflags" 2>&1)" || rc=$?
      echo "$out" | tail -n 25
    else
      rc=1
    fi
    if [ "$rc" -ne 0 ]; then
      echo "WTC: Targeted update failed - running full composer update..."
      rc=0
      out="$(su -s /bin/bash "$WEB_USER" -c "$cenv composer update $cflags" 2>&1)" || rc=$?
      echo "$out" | tail -n 25
    fi
  fi
  [ "$rc" -eq 0 ] || { echo "ERROR: composer install failed (exit $rc)"; return 1; }

  # 3. Persist the repaired site to SD so re-activation doesn't bring the shim back
  valid_name "$site" && store_dir_as_site "$site" "$dir" >/dev/null 2>&1 || true
  echo "WTC: Laravel framework restored for '$site'."
}

# ------------------------------------------------------------ settings
cmd_setting() {
  local k="${1:-}" v="${2:-}"
  case "$k" in
    RAM_SIZE)               [[ "$v" =~ ^[0-9]{2,5}[MG]$ ]] || die "RAM_SIZE like 512M or 1G" ;;
    DROP_MAX_MB|SAVE_INTERVAL_MIN) [[ "$v" =~ ^[0-9]{1,5}$ ]] || die "$k must be a number" ;;
    AUTO_ACTIVATE_NEW|AUTO_RELOAD_ACTIVE|KEEP_PROCESSED|SAVE_ON_SHUTDOWN|SAVE_ON_SWITCH)
                            [[ "$v" =~ ^(yes|no)$ ]] || die "$k must be yes or no" ;;
    *) die "Unknown setting '$k'" ;;
  esac
  set_conf "$k" "$v"
  if [ "$k" = RAM_SIZE ] && mountpoint -q "$RAM_MOUNT"; then
    mount -o "remount,size=$v" "$RAM_MOUNT" || die "Could not resize RAM disk (not enough memory?)"
  fi
  log "Setting $k=$v"
}

# ------------------------------------------------------------ transfer (Samba / SFTP)
cmd_transfer() {
  local what="${1:-}" val="${2:-}" pw
  case "$what" in
    samba)
      pkg_installed samba || die "Samba is not installed"
      case "$val" in
        on)  systemctl enable --now smbd; systemctl enable --now nmbd 2>/dev/null || true ;;
        off) systemctl disable --now smbd; systemctl disable --now nmbd 2>/dev/null || true ;;
        *) die "Use on|off" ;;
      esac ;;
    ssh)
      case "$val" in
        on)  systemctl enable --now ssh.service; systemctl enable --now ssh.socket 2>/dev/null || true ;;
        off) systemctl disable --now ssh.socket 2>/dev/null || true; systemctl disable --now ssh.service ;;
        *) die "Use on|off" ;;
      esac ;;
    samba-guest)
      [[ "$val" =~ ^(yes|no)$ ]] || die "Use yes|no"
      set_conf SAMBA_GUEST "$val"; SAMBA_GUEST="$val"
      write_samba_block ;;
    samba-password)
      IFS= read -r pw
      [ "${#pw}" -ge 8 ] || die "Password must be at least 8 characters"
      id "$SAMBA_USER" >/dev/null 2>&1 || die "Samba user '$SAMBA_USER' missing (re-run setup)"
      printf '%s\n%s\n' "$pw" "$pw" | smbpasswd -s -a "$SAMBA_USER" >/dev/null
      log "Samba password updated" ;;
    ftp)
      case "$val" in
        install)
          export DEBIAN_FRONTEND=noninteractive
          apt-get update && apt-get install -y vsftpd
          write_ftp_block
          systemctl enable --now vsftpd
          log "Installed and started vsftpd" ;;
        on)
          pkg_installed vsftpd || die "vsftpd is not installed (click Install first)"
          write_ftp_block
          systemctl enable --now vsftpd
          log "FTP server started" ;;
        off)
          systemctl disable --now vsftpd 2>/dev/null || true
          log "FTP server stopped" ;;
        *) die "Use on|off|install" ;;
      esac ;;
    ftp-guest)
      [[ "$val" =~ ^(yes|no)$ ]] || die "Use yes|no"
      set_conf FTP_GUEST "$val"; FTP_GUEST="$val"
      write_ftp_block
      log "FTP guest access set to $val" ;;
    ftp-password)
      IFS= read -r pw
      [ "${#pw}" -ge 8 ] || die "Password must be at least 8 characters"
      id "$SAMBA_USER" >/dev/null 2>&1 || die "FTP user '$SAMBA_USER' missing"
      echo "$SAMBA_USER:$pw" | chpasswd
      printf '%s\n%s\n' "$pw" "$pw" | smbpasswd -s -a "$SAMBA_USER" >/dev/null 2>&1 || true
      log "FTP password updated" ;;
    *) die "Unknown transfer option" ;;
  esac
}

# ------------------------------------------------------------ firewall (UFW)
cmd_firewall() {
  local action="${1:-status}" port="${2:-}" proto="${3:-tcp}"
  [[ "$proto" =~ ^(tcp|udp)$ ]] || proto="tcp"

  case "$action" in
    status)
      # Handled by json_firewall via --json firewall
      ;;
    install)
      export DEBIAN_FRONTEND=noninteractive
      apt-get update && apt-get install -y ufw
      # Ensure SSH and UI are allowed before enabling
      ufw allow 22/tcp comment "SSH Server" >/dev/null 2>&1 || true
      ufw allow 80/tcp comment "HTTP Web Server" >/dev/null 2>&1 || true
      ufw allow 8080/tcp comment "Site Direct Port" >/dev/null 2>&1 || true
      ufw allow 4485/tcp comment "WTC Management UI" >/dev/null 2>&1 || true
      log "Installed and pre-configured UFW firewall"
      echo "Installed UFW firewall."
      ;;
    enable)
      command -v ufw >/dev/null 2>&1 || [ -x /usr/sbin/ufw ] || die "UFW firewall is not installed. Click Install UFW first."
      # Safety check: ensure SSH (22) and UI ports are allowed before enabling
      ufw allow 22/tcp comment "SSH Server" >/dev/null 2>&1 || true
      ufw allow 80/tcp comment "HTTP Web Server" >/dev/null 2>&1 || true
      ufw allow 8080/tcp comment "Site Direct Port" >/dev/null 2>&1 || true
      ufw allow 4485/tcp comment "WTC Management UI" >/dev/null 2>&1 || true
      echo "y" | ufw enable
      systemctl enable ufw 2>/dev/null || true
      log "Firewall enabled"
      echo "Firewall is now active and protecting the system."
      ;;
    disable)
      command -v ufw >/dev/null 2>&1 || [ -x /usr/sbin/ufw ] || die "UFW firewall is not installed"
      ufw disable
      log "Firewall disabled"
      echo "Firewall disabled."
      ;;
    reset)
      command -v ufw >/dev/null 2>&1 || [ -x /usr/sbin/ufw ] || die "UFW firewall is not installed"
      echo "y" | ufw reset
      ufw default deny incoming
      ufw default allow outgoing
      ufw allow 22/tcp comment "SSH Server"
      ufw allow 80/tcp comment "HTTP Web Server"
      ufw allow 8080/tcp comment "Site Direct Port"
      ufw allow 4485/tcp comment "WTC Management UI"
      echo "y" | ufw enable
      log "Firewall reset to factory defaults"
      echo "Firewall rules reset to defaults."
      ;;
    allow|open)
      command -v ufw >/dev/null 2>&1 || [ -x /usr/sbin/ufw ] || die "UFW firewall is not installed"
      [[ "$port" =~ ^[0-9]{1,5}(:[0-9]{1,5})?$ ]] || die "Invalid port number '$port'"
      ufw allow "$port/$proto" comment "WTC UI Rule"
      log "Firewall allowed port $port/$proto"
      echo "Port $port/$proto allowed."
      ;;
    deny|block)
      command -v ufw >/dev/null 2>&1 || [ -x /usr/sbin/ufw ] || die "UFW firewall is not installed"
      [[ "$port" =~ ^[0-9]{1,5}(:[0-9]{1,5})?$ ]] || die "Invalid port number '$port'"
      # Protect against locking out management interface
      if [ "$port" = "4485" ] || [ "$port" = "22" ]; then
        die "Cannot block port $port: critical management port (SSH / WTC UI)"
      fi
      ufw deny "$port/$proto" comment "WTC UI Block Rule"
      log "Firewall blocked port $port/$proto"
      echo "Port $port/$proto blocked."
      ;;
    delete|remove)
      command -v ufw >/dev/null 2>&1 || [ -x /usr/sbin/ufw ] || die "UFW firewall is not installed"
      [[ "$port" =~ ^[0-9]{1,5}(:[0-9]{1,5})?$ ]] || die "Invalid port number '$port'"
      ufw delete allow "$port/$proto" 2>/dev/null || ufw delete deny "$port/$proto" 2>/dev/null || ufw delete "$port/$proto" 2>/dev/null || true
      log "Firewall removed rule for $port/$proto"
      echo "Rule for $port/$proto removed."
      ;;
    *) die "Unknown firewall action '$action' (use install|enable|disable|reset|allow|deny|delete)" ;;
  esac
}

# ------------------------------------------------------------ stack
cmd_stack() {
  local action="${1:-}" id="${2:-}"
  [[ "$action" =~ ^(install|remove)$ ]] || die "Use install|remove"
  [ -n "${STACK_PKGS[$id]:-}" ] || die "Unknown component '$id'"
  systemctl is-active --quiet wtc-stack-job.service && die "Another stack job is running"
  mkdir -p "$(dirname "$STACK_LOG")"
  echo "=== $action $id  ($(date '+%F %T')) ===" > "$STACK_LOG"
  systemd-run --quiet --unit=wtc-stack-job --collect \
    --property=StandardOutput=append:"$STACK_LOG" --property=StandardError=append:"$STACK_LOG" \
    /usr/local/bin/wtc-manage --stack-exec "$action" "$id"
  log "Stack job started: $action $id"
}

cmd_stack_exec() { # runs inside the transient unit
  local action="$1" id="$2" unit rc=0
  [ -n "${STACK_PKGS[$id]:-}" ] || exit 2
  export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
  local APT=(apt-get -o DPkg::Lock::Timeout=300 -y)
  # shellcheck disable=SC2086
  if [ "$action" = install ]; then
    { "${APT[@]}" update && "${APT[@]}" install ${STACK_PKGS[$id]}; } || rc=$?
  else
    "${APT[@]}" remove ${STACK_PKGS[$id]} || rc=$?
  fi
  unit="$(php_fpm_unit)"
  [ -n "$unit" ] && systemctl reload "$unit" 2>/dev/null || true
  refresh_pkg_cache 2>/dev/null || true
  build_stack_cache 2>/dev/null || true
  echo "=== finished: exit code $rc ==="
  exit "$rc"
}

# ------------------------------------------------------------ site build (npm run build)
cmd_build() {
  local site="${1:-$(active_site)}"
  valid_name "$site" || die "Invalid site name"
  systemctl is-active --quiet wtc-build-job.service && die "Another build job is currently running"
  mkdir -p "$(dirname "$BUILD_LOG")"
  echo "=== npm build for '$site' ($(date '+%F %T')) ===" > "$BUILD_LOG"
  systemd-run --quiet --unit=wtc-build-job --collect \
    --property=StandardOutput=append:"$BUILD_LOG" --property=StandardError=append:"$BUILD_LOG" \
    /usr/local/bin/wtc-manage --build-exec "$site"
  log "Background build job started for '$site'"
}

cmd_build_exec() {
  local site="$1" rc=0
  valid_name "$site" || exit 2
  echo "==> Preparing build environment for '$site'..."

  # 1. Ensure nodejs and npm are present
  if ! command -v npm >/dev/null 2>&1; then
    echo "==> npm not found. Installing nodejs & npm via apt..."
    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    local APT=(apt-get -o DPkg::Lock::Timeout=300 -y)
    "${APT[@]}" update && "${APT[@]}" install nodejs npm || {
      echo "ERROR: Failed to install nodejs / npm"
      exit 1
    }
  fi

  echo "==> Node: $(node -v 2>/dev/null || echo 'N/A') | npm: $(npm -v 2>/dev/null || echo 'N/A')"

  # 2. Determine target build directory
  local targetDir="" is_active=0
  if [ "$(active_site)" = "$site" ]; then
    targetDir="$(readlink -f "$WWW_LINK" 2>/dev/null || echo '')"
    is_active=1
  fi

  if [ -z "$targetDir" ] || [ ! -d "$targetDir" ]; then
    local archive="$SITES_DIR/$site.tar.gz"
    [ -f "$archive" ] || { echo "ERROR: Site archive $archive not found"; exit 1; }
    targetDir="$(mktemp -d "$STAGE_DIR/.build.XXXXXX")"
    tar -xf "$archive" -C "$targetDir" --no-same-owner --no-same-permissions
  fi

  cd "$targetDir"
  if [ ! -f "package.json" ]; then
    echo "ERROR: No package.json found in '$site'"
    [ "$is_active" -ne 1 ] && rm -rf "$targetDir"
    exit 1
  fi

  # 3. npm install & npm run build
  echo "==> Running npm install..."
  if [ -f "package-lock.json" ]; then
    npm ci --no-audit 2>&1 || npm install --no-audit 2>&1 || rc=$?
  else
    npm install --no-audit 2>&1 || rc=$?
  fi

  if [ "$rc" -ne 0 ]; then
    echo "ERROR: npm install failed (exit code $rc)"
    [ "$is_active" -ne 1 ] && rm -rf "$targetDir"
    exit "$rc"
  fi

  echo "==> Running npm run build..."
  npm run build 2>&1 || rc=$?
  if [ "$rc" -ne 0 ]; then
    echo "ERROR: npm run build failed (exit code $rc)"
    [ "$is_active" -ne 1 ] && rm -rf "$targetDir"
    exit "$rc"
  fi

  # 4. Promote dist / build assets
  echo "==> Build succeeded. Promoting assets..."
  local is_laravel=0
  if [ -f "$targetDir/artisan" ] || [ -f "$targetDir/public/index.php" ]; then
    is_laravel=1
  fi

  if [ "$is_laravel" -eq 1 ]; then
    # Laravel Vite puts assets in public/build
    echo "==> Laravel application detected. Verifying public/build assets..."
    if [ -d "$targetDir/public/build" ]; then
      echo "==> Found public/build manifest and assets."
    elif [ -d "$targetDir/dist" ] && [ ! -d "$targetDir/public/dist" ]; then
      echo "==> Copying dist/ into public/dist for Laravel asset loader..."
      cp -rn "$targetDir/dist" "$targetDir/public/" 2>/dev/null || true
    fi
  else
    for sub in dist build out; do
      if [ -d "$targetDir/$sub" ] && [ -f "$targetDir/$sub/index.html" ]; then
        echo "==> Promoting $sub/ into site root..."
        cp -rn "$targetDir/$sub"/* "$targetDir"/ 2>/dev/null || true
        break
      fi
    done

    # 5. Inject SPA bridge if needed (for pure client-side SPA)
    if [ -f "$targetDir/index.html" ] && [ ! -f "$targetDir/index.php" ]; then
      cat > "$targetDir/index.php" <<'EOF'
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
EOF
    fi

    local stack
    stack="$(detect_site_stack "$targetDir")"
    generate_stack_nginx_conf "$stack" "$targetDir" "$targetDir/nginx.conf"
  fi

  chown -R www-data:www-data "$targetDir"
  chmod -R u=rwX,go=rX "$targetDir"

  # 6. Save updated build into site package
  echo "==> Packaging updated build into /opt/wtc-web/sites/$site.tar.gz..."
  store_dir_as_site "$site" "$targetDir" || true

  if [ "$is_active" -eq 1 ]; then
    generate_stack_nginx_conf "$(detect_site_stack "$targetDir")" "$targetDir" "$RAM_NGINX_CONF"
    ln -sfn "$RAM_NGINX_CONF" "$NGINX_SNIPPET" 2>/dev/null || true
    systemctl reload nginx 2>/dev/null || true
  else
    rm -rf "$targetDir"
  fi

  echo "=== Build job completed successfully ($(date '+%F %T')) ==="
  exit 0
}

json_build() {
  local busy=no log=""
  if systemctl is-active --quiet wtc-build-job.service; then busy=yes; fi
  log="$(tail -n 60 "$BUILD_LOG" 2>/dev/null || true)"
  jq -nc --argjson busy "$(b2j "$busy")" --arg log "$log" '{busy:$busy,log:$log}'
}

scan_site_meta() {
  local s="$1" f="$2" stack="unknown" hasBuild="false" m=0 listing=""
  m="$(stat -c %Y "$f" 2>/dev/null || echo 0)"
  listing="$(tar -tf "$f" 2>/dev/null || true)"
  if echo "$listing" | grep -q 'package.json'; then hasBuild="true"; fi
  if echo "$listing" | grep -qE '(artisan|public/index\.php)'; then
    stack="laravel"
  elif [ "$hasBuild" = "true" ]; then
    stack="spa"
  elif echo "$listing" | grep -q '\.php$'; then
    stack="php"
  else
    stack="static"
  fi
  cat > "$SITES_DIR/$s.meta.json" 2>/dev/null <<EOF || true
{"stack":"$stack","hasBuild":$hasBuild,"mtime":$m}
EOF
}

json_sites() {
  local cur s f meta_f size m p a is_prim live hasPkg stack slug site_ram_dir items=()
  cur="$(active_site)"
  while read -r s; do
    [ -z "$s" ] && continue
    f="$SITES_DIR/$s.tar.gz"
    meta_f="$SITES_DIR/$s.meta.json"
    hasPkg=false
    stack="unknown"
    slug="$(site_slug "$s")"
    site_ram_dir="$RAM_SITES_DIR/$slug"

    if [ -f "$f" ]; then
      size="$(stat -c %s "$f" 2>/dev/null || echo 0)"
      m="$(stat -c %Y "$f" 2>/dev/null || echo 0)"
      # Fast path: check cached metadata file
      if [ -f "$meta_f" ]; then
        stack="$(jq -r '.stack // "unknown"' "$meta_f" 2>/dev/null || echo "unknown")"
        hasPkg="$(jq -r '.hasBuild // false' "$meta_f" 2>/dev/null || echo false)"
      else
        scan_site_meta "$s" "$f" 2>/dev/null || true
        if [ -f "$meta_f" ]; then
          stack="$(jq -r '.stack // "unknown"' "$meta_f" 2>/dev/null || echo "unknown")"
          hasPkg="$(jq -r '.hasBuild // false' "$meta_f" 2>/dev/null || echo false)"
        fi
      fi
    else
      if [ -d "$site_ram_dir" ]; then
        size="$(du -sb "$site_ram_dir" 2>/dev/null | awk '{print $1}')"
      else
        size=0
      fi
      m="$(date +%s)"
    fi

    # Check active state in RAM disk
    a=false
    is_prim=false
    if is_site_active "$s"; then
      a=true
    fi
    if [ "$s" = "$cur" ]; then
      is_prim=true
    fi

    # Live inspection if unpacked in RAM
    if [ -d "$site_ram_dir" ]; then
      stack="$(detect_site_stack "$site_ram_dir" 2>/dev/null || echo "$stack")"
      [ -f "$site_ram_dir/package.json" ] && hasPkg=true
    fi

    p="$([ -f "$PREV_DIR/$s.tar.gz" ] && echo true || echo false)"
    items+=("$(jq -nc --arg n "$s" --argjson a "$a" --argjson isPrim "$is_prim" \
      --arg slug "$slug" --arg url "/$slug/" \
      --argjson size "${size:-0}" --argjson m "${m:-0}" \
      --argjson p "$p" --argjson hasBuild "$hasPkg" \
      --arg stack "$stack" \
      '{name:$n,active:$a,isPrimary:$isPrim,slug:$slug,url:$url,size:$size,mtime:$m,hasPrevious:$p,hasBuild:$hasBuild,stack:$stack}')")
  done < <(list_sites)
  if [ "${#items[@]}" -eq 0 ]; then
    echo "[]"
  else
    printf '%s\n' "${items[@]}" | jq -s '.'
  fi
}

cmd_device() {
  local action="${1:-}"
  case "$action" in
    reboot)       log "Device reboot requested"; (sleep 1 && reboot) & ;;
    shutdown)     log "Device shutdown requested"; (sleep 1 && poweroff) & ;;
    restart-web)  systemctl restart nginx; systemctl restart "$(php_fpm_unit)"; log "Restarted Web Services" ;;
    restart-db)   systemctl restart mariadb; log "Restarted MariaDB" ;;
    restart-smb)  systemctl restart smbd; log "Restarted Samba" ;;
    *) die "Unknown device action '$action'" ;;
  esac
}

cmd_rescan_sites() {
  local s f
  log "Rescanning all site archives in $SITES_DIR..."
  while read -r s; do
    [ -z "$s" ] && continue
    f="$SITES_DIR/$s.tar.gz"
    if [ -f "$f" ]; then
      scan_site_meta "$s" "$f"
    fi
  done < <(list_sites)
  log "Site metadata scan completed."
}

json_status() {
  local used=0 size=0 line
  if mountpoint -q "$RAM_MOUNT"; then
    line="$(df -B1 --output=used,size "$RAM_MOUNT" | tail -n1)"
    used="$(echo "$line" | awk '{print $1}')"; size="$(echo "$line" | awk '{print $2}')"
  fi
  local temp="N/A"
  if [ -f /sys/class/thermal/thermal_zone0/temp ]; then
    temp="$(awk '{printf "%.1f", $1/1000}' /sys/class/thermal/thermal_zone0/temp)°C"
  elif command -v vcgencmd >/dev/null 2>&1; then
    temp="$(vcgencmd measure_temp | cut -d= -f2)"
  fi
  local uptime_str; uptime_str="$(uptime -p 2>/dev/null || uptime | awk -F'( |,|:)+' '{print $6,"hrs",$7,"min"}')"
  local sys_mem_total; sys_mem_total="$(free -b 2>/dev/null | awk '/Mem:/ {print $2}' || echo 0)"
  local sys_mem_used; sys_mem_used="$(free -b 2>/dev/null | awk '/Mem:/ {print $3}' || echo 0)"
  local disk_line; disk_line="$(df -B1 / | tail -n1)"
  local disk_used; disk_used="$(echo "$disk_line" | awk '{print $3}')"
  local disk_size; disk_size="$(echo "$disk_line" | awk '{print $2}')"

  # Static hardware model cache to avoid repeated /proc or /etc reads
  local model="Raspberry Pi"
  if [ -f /run/wtc-model.cache ]; then
    model="$(cat /run/wtc-model.cache 2>/dev/null)"
  elif [ -f /proc/device-tree/model ]; then
    model="$(tr -d '\0' < /proc/device-tree/model)"
    echo "$model" > /run/wtc-model.cache 2>/dev/null || true
  elif [ -f /etc/os-release ]; then
    model="$(grep PRETTY_NAME /etc/os-release | cut -d= -f2 | tr -d '\"')"
    echo "$model" > /run/wtc-model.cache 2>/dev/null || true
  fi

  local ips; ips="$(hostname -I 2>/dev/null | xargs || true)"

  # Fast worker check: read PID file instead of invoking external binary
  local workers_json
  local w_has_artisan=false w_q_run=false w_s_run=false w_svc="no" w_q_pid="" w_s_pid=""
  if [ -f "/mnt/ramdisk/webroot/artisan" ] || [ -f "/mnt/ramdisk/webroot/../artisan" ] || [ -f "/mnt/ramdisk/www/artisan" ]; then w_has_artisan=true; fi
  if systemctl is-active --quiet wtc-workers.service 2>/dev/null; then w_svc="yes"; fi
  w_q_pid="$(pgrep -f "artisan queue:work" 2>/dev/null | head -n1 || true)"
  w_s_pid="$(pgrep -f "artisan schedule:work" 2>/dev/null | head -n1 || true)"
  [ -n "$w_q_pid" ] && w_q_run=true
  [ -n "$w_s_pid" ] && w_s_run=true
  workers_json="$(printf '{"service":"%s","has_artisan":%s,"queue":{"running":%s,"pid":"%s"},"schedule":{"running":%s,"pid":"%s"}}' \
    "$w_svc" "$w_has_artisan" "$w_q_run" "$w_q_pid" "$w_s_run" "$w_s_pid")"

  # Fast service checks
  local ng_act="no" php_act="no" smb_act="no" ssh_act="no" db_act="no"
  pgrep -x nginx >/dev/null 2>&1 && ng_act="yes" || ng_act="$(svc_active nginx)"
  pgrep -f "php-fpm: master process" >/dev/null 2>&1 && php_act="yes" || php_act="$(svc_active "$(php_fpm_unit)")"
  pgrep -x smbd >/dev/null 2>&1 && smb_act="yes" || smb_act="$(svc_active smbd)"
  ssh_act="$(ssh_active)"
  pgrep -x mariadbd >/dev/null 2>&1 || pgrep -x mysqld >/dev/null 2>&1 && db_act="yes" || db_act="$(svc_active mariadb)"

  # Active sites list
  local active_sites_json="[]"
  local as_items=() as_name as_slug
  while read -r as_name; do
    [ -z "$as_name" ] && continue
    as_slug="$(site_slug "$as_name")"
    as_items+=("$(jq -nc --arg name "$as_name" --arg slug "$as_slug" --arg url "/$as_slug/" '{name:$name,slug:$slug,url:$url}')")
  done < <(list_active_sites)
  if [ "${#as_items[@]}" -gt 0 ]; then
    active_sites_json="$(printf '%s\n' "${as_items[@]}" | jq -s '.')"
  fi

  jq -nc --arg active "$(active_site)" --arg nginx "$ng_act" \
    --arg php "$php_act" --arg smb "$smb_act" --arg ssh "$ssh_act" \
    --arg db "$db_act" \
    --argjson used "$used" --argjson size "$size" \
    --argjson sysMemUsed "${sys_mem_used:-0}" --argjson sysMemTotal "${sys_mem_total:-0}" \
    --argjson diskUsed "${disk_used:-0}" --argjson diskSize "${disk_size:-0}" \
    --arg temp "$temp" --arg uptime "$uptime_str" --arg model "$model" --arg ips "$ips" \
    --argjson pending "$(find "$DROP_DIR" -maxdepth 1 -type f 2>/dev/null | wc -l)" \
    --argjson rejected "$(find "$REJECT_DIR" -maxdepth 1 -name '*.error' 2>/dev/null | wc -l)" \
    --argjson sites "$(list_sites | wc -l)" --arg host "$(hostname)" \
    --argjson workers "$workers_json" \
    --argjson activeSites "$active_sites_json" \
    '{active:$active,activeSites:$activeSites,nginx:$nginx,phpfpm:$php,samba:$smb,ssh:$ssh,mariadb:$db,ramUsed:$used,ramSize:$size,sysMemUsed:$sysMemUsed,sysMemTotal:$sysMemTotal,diskUsed:$diskUsed,diskSize:$diskSize,temp:$temp,uptime:$uptime,model:$model,ips:$ips,pending:$pending,rejected:$rejected,sites:$sites,host:$host,workers:$workers}'
}

json_sysinfo() {
  # Snapshot /proc/stat twice with a brief sleep or calculate differential
  local stat_file="/run/wtc-proc-stat.cache"
  local now_ts; now_ts="$(date +%s%N 2>/dev/null || date +%s)"
  local prev_ts="" prev_data=""
  if [ -f "$stat_file" ]; then
    prev_ts="$(head -n1 "$stat_file" 2>/dev/null || true)"
    prev_data="$(tail -n +2 "$stat_file" 2>/dev/null || true)"
  fi

  local cur_data; cur_data="$(grep '^cpu' /proc/stat 2>/dev/null || true)"
  # Write current snapshot for next polling cycle
  printf "%s\n%s\n" "$now_ts" "$cur_data" > "$stat_file" 2>/dev/null || true

  # If no previous data exists (first poll), take a 0.15s sample
  if [ -z "$prev_data" ] || [ -z "$cur_data" ]; then
    prev_data="$cur_data"
    sleep 0.15
    cur_data="$(grep '^cpu' /proc/stat 2>/dev/null || true)"
  fi

  # CPU Frequency in MHz
  local freq_mhz=0
  if [ -f /sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq ]; then
    freq_mhz="$(awk '{printf "%.0f", $1/1000}' /sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq 2>/dev/null || echo 0)"
  fi

  # Compute per-core and total CPU usage via awk
  awk -v f_prev="$prev_data" -v f_cur="$cur_data" -v freq="$freq_mhz" 'BEGIN {
    split(f_prev, p_lines, "\n")
    split(f_cur, c_lines, "\n")

    for (i in p_lines) {
      if (p_lines[i] ~ /^cpu/) {
        split(p_lines[i], f)
        cname = f[1]
        p_user[cname] = f[2]; p_nice[cname] = f[3]; p_sys[cname] = f[4]; p_idle[cname] = f[5]
        p_iow[cname] = f[6]; p_irq[cname] = f[7]; p_sirq[cname] = f[8]; p_steal[cname] = f[9]
      }
    }

    core_count = 0
    total_pct = 0.0

    # Build JSON output
    out = "{\"freq_mhz\":" freq ",\"total\":"
    
    # Process total cpu
    cname = "cpu"
    for (i in c_lines) {
      if (c_lines[i] ~ /^cpu /) {
        split(c_lines[i], f)
        p_tot = p_user[cname] + p_nice[cname] + p_sys[cname] + p_idle[cname] + p_iow[cname] + p_irq[cname] + p_sirq[cname] + p_steal[cname]
        c_tot = f[2] + f[3] + f[4] + f[5] + f[6] + f[7] + f[8] + f[9]
        p_id = p_idle[cname] + p_iow[cname]
        c_id = f[5] + f[6]
        d_tot = c_tot - p_tot
        d_id = c_id - p_id
        if (d_tot > 0) {
          total_pct = ((d_tot - d_id) / d_tot) * 100.0
          if (total_pct < 0) total_pct = 0; if (total_pct > 100) total_pct = 100
        }
      }
    }
    out = out sprintf("%.1f", total_pct) ",\"cores\":["

    first = 1
    for (idx = 0; idx < 64; idx++) {
      cname = "cpu" idx
      found = 0
      for (i in c_lines) {
        if (c_lines[i] ~ ("^" cname " ")) {
          split(c_lines[i], f)
          p_tot = p_user[cname] + p_nice[cname] + p_sys[cname] + p_idle[cname] + p_iow[cname] + p_irq[cname] + p_sirq[cname] + p_steal[cname]
          c_tot = f[2] + f[3] + f[4] + f[5] + f[6] + f[7] + f[8] + f[9]
          p_id = p_idle[cname] + p_iow[cname]
          c_id = f[5] + f[6]
          d_tot = c_tot - p_tot
          d_id = c_id - p_id
          pct = 0.0
          if (d_tot > 0) {
            pct = ((d_tot - d_id) / d_tot) * 100.0
            if (pct < 0) pct = 0; if (pct > 100) pct = 100
          }
          if (!first) out = out ","
          out = out "{\"id\":" idx ",\"usage\":" sprintf("%.1f", pct) "}"
          first = 0
          core_count++
          found = 1
          break
        }
      }
      if (!found) break
    }
    out = out "],\"count\":" core_count "}"
    print out
  }'
}

json_processes() {
  # Fetch top processes sorted by %CPU (PID, User, %CPU, %MEM, Status, Command)
  # Limit to top 25 processes to keep response snappy
  ps -eo pid,user,%cpu,%mem,stat,comm,args --sort=-%cpu 2>/dev/null | tail -n +2 | head -n 25 | awk '
  BEGIN {
    print "["
    first = 1
  }
  {
    pid = $1; user = $2; cpu = $3; mem = $4; stat = $5; comm = $6
    # Combine remaining args as full command line
    cmd = ""
    for (i = 7; i <= NF; i++) {
      cmd = (cmd == "" ? "" : cmd " ") $i
    }
    if (cmd == "") cmd = comm

    # Escape quotes and backslashes for JSON
    gsub(/\\/, "\\\\", cmd); gsub(/"/, "\\\"", cmd)
    gsub(/\\/, "\\\\", comm); gsub(/"/, "\\\"", comm)
    gsub(/\\/, "\\\\", user); gsub(/"/, "\\\"", user)

    if (!first) printf ",\n"
    printf "{\"pid\":%d,\"user\":\"%s\",\"cpu\":%.1f,\"mem\":%.1f,\"stat\":\"%s\",\"comm\":\"%s\",\"cmd\":\"%s\"}", pid, user, cpu, mem, stat, comm, cmd
    first = 0
  }
  END {
    print "\n]"
  }'
}

json_net_monitor() {
  local limit="${1:-30}"
  [[ "$limit" =~ ^[0-9]+$ ]] || limit=30
  if [ "$limit" -lt 5 ]; then limit=5; fi
  if [ "$limit" -gt 500 ]; then limit=500; fi

  # 1. Parse active listening ports (TCP) to verify Nginx, MariaDB, SSH, SMB, etc.
  local listeners_json
  listeners_json="$(ss -tulpn 2>/dev/null | awk '
    NR > 1 && $1 ~ /^tcp/ {
      state = $2; laddr = $5; pinfo = $7
      gsub(/.*:/, "", laddr)
      gsub(/.*users:\(\("/, "", pinfo); gsub(/".*/, "", pinfo)
      if (laddr != "" && !seen[laddr pinfo]++) {
        ports = ports (ports == "" ? "" : ",") sprintf("{\"port\":%s,\"proc\":\"%s\"}", (laddr ~ /^[0-9]+$/ ? laddr : 0), pinfo)
      }
    }
    END { print "[" ports "]" }
  ' || echo "[]")"

  # 2. Extract recent Nginx access log requests (IP, time, method, uri, status, user-agent)
  # Look in /var/log/nginx/access.log, or fallback to any active nginx log
  local log_file="/var/log/nginx/access.log"
  local reqs_json="[]"
  if [ ! -f "$log_file" ] || [ ! -s "$log_file" ]; then
    local alt_log; alt_log="$(ls -1t /var/log/nginx/*.log 2>/dev/null | head -n1 || echo "")"
    [ -n "$alt_log" ] && [ -f "$alt_log" ] && log_file="$alt_log"
  fi

  if [ -f "$log_file" ]; then
    reqs_json="$(tail -n "$limit" "$log_file" 2>/dev/null | awk '
      BEGIN { print "[" ; first = 1 }
      {
        line = $0
        ip = $1

        # Extract timestamp between brackets [ ... ]
        time = ""
        if (match(line, /\[([^\]]+)\]/, t_arr)) {
          time = t_arr[1]
        } else {
          time = $4
          gsub(/^\[/, "", time); gsub(/\]$/, "", time)
        }

        # Extract HTTP request line between quotes "GET /path HTTP/1.1"
        method = "GET"
        path = "/"
        if (match(line, /"([A-Z]+) ([^" ]+)[^"]*"/, r_arr)) {
          method = r_arr[1]
          path = r_arr[2]
        } else if ($6 ~ /^"/) {
          method = substr($6, 2)
          path = $7
        }

        # Extract status code (first 3-digit number after the request quotes)
        status = "200"
        if (match(line, /" [0-9]{3} /, s_arr)) {
          status = substr(s_arr[0], 3, 3)
        } else if ($9 ~ /^[0-9]{3}$/) {
          status = $9
        }

        # Extract User-Agent (last quoted string)
        ua = ""
        n_quotes = split(line, q_parts, "\"")
        if (n_quotes >= 6) {
          ua = q_parts[6]
        } else if (n_quotes >= 4) {
          ua = q_parts[4]
        } else {
          ua = $NF
        }

        # Safety cleanups and JSON escapes
        gsub(/\\/, "\\\\", path); gsub(/"/, "\\\"", path)
        gsub(/\\/, "\\\\", ua); gsub(/"/, "\\\"", ua)
        gsub(/\\/, "\\\\", ip); gsub(/"/, "\\\"", ip)
        gsub(/\\/, "\\\\", time); gsub(/"/, "\\\"", time)

        if (!first) printf ",\n"
        printf "{\"ip\":\"%s\",\"time\":\"%s\",\"method\":\"%s\",\"path\":\"%s\",\"status\":\"%s\",\"ua\":\"%s\"}", ip, time, method, path, status, ua
        first = 0
      }
      END { print "\n]" }
    ' || echo "[]")"
  fi

  # 3. Detect network interfaces, IP addresses, and default gateway
  local ifaces_json
  ifaces_json="$(ip -j addr show 2>/dev/null || ip addr show 2>/dev/null | awk '
    /^[0-9]+: / { iface = $2; gsub(/:/, "", iface) }
    /inet / {
      ip = $2; gsub(/\/.*/, "", ip)
      if (iface != "lo" && !seen[iface ip]++) {
        list = list (list == "" ? "" : ",") sprintf("{\"iface\":\"%s\",\"ip\":\"%s\"}", iface, ip)
      }
    }
    END { print "[" list "]" }
  ' || echo "[]")"

  local gw; gw="$(ip route show default 2>/dev/null | awk '/default/ {print $3}' | head -n1 || echo "")"
  local hostname_str; hostname_str="$(hostname 2>/dev/null || echo "pi")"

  jq -nc \
    --arg host "$hostname_str" \
    --arg gateway "$gw" \
    --argjson listeners "$listeners_json" \
    --argjson requests "$reqs_json" \
    --argjson ifaces "$ifaces_json" \
    '{host:$host,gateway:$gateway,listeners:$listeners,requests:$requests,ifaces:$ifaces}'
}

json_dropbox() {
  local pending rejected processed f
  pending="$(find "$DROP_DIR" -maxdepth 1 -type f -printf '%f\t%s\n' 2>/dev/null \
    | jq -R -s -c 'split("\n")|map(select(length>0)|split("\t")|{name:.[0],size:(.[1]|tonumber)})')"
  rejected="$(
    for f in "$REJECT_DIR"/*.error; do
      [ -e "$f" ] || continue
      jq -nc --arg n "$(basename "${f%.error}")" --arg r "$(head -c 300 "$f")" '{name:$n,reason:$r}'
    done | jq -s '.')"
  processed="$(find "$PROCESSED_DIR" -maxdepth 1 -type f -printf '%f\n' 2>/dev/null | jq -R -s -c 'split("\n")|map(select(length>0))')"
  jq -nc --argjson p "$pending" --argjson r "$rejected" --argjson k "$processed" --arg path "$DROP_DIR" \
    '{path:$path,pending:$p,rejected:$r,processed:$k}'
}

json_settings() {
  jq -nc --arg RAM_SIZE "$RAM_SIZE" --arg DROP_MAX_MB "$DROP_MAX_MB" --arg AUTO_ACTIVATE_NEW "$AUTO_ACTIVATE_NEW" \
    --arg AUTO_RELOAD_ACTIVE "$AUTO_RELOAD_ACTIVE" --arg KEEP_PROCESSED "$KEEP_PROCESSED" \
    --arg SAVE_ON_SHUTDOWN "$SAVE_ON_SHUTDOWN" --arg SAVE_ON_SWITCH "$SAVE_ON_SWITCH" \
    --arg SAVE_INTERVAL_MIN "$SAVE_INTERVAL_MIN" \
    '{RAM_SIZE:$RAM_SIZE,DROP_MAX_MB:$DROP_MAX_MB,AUTO_ACTIVATE_NEW:$AUTO_ACTIVATE_NEW,AUTO_RELOAD_ACTIVE:$AUTO_RELOAD_ACTIVE,KEEP_PROCESSED:$KEEP_PROCESSED,SAVE_ON_SHUTDOWN:$SAVE_ON_SHUTDOWN,SAVE_ON_SWITCH:$SAVE_ON_SWITCH,SAVE_INTERVAL_MIN:$SAVE_INTERVAL_MIN}'
}

json_transfer() {
  jq -nc --argjson si "$(b2j "$(pkg_installed samba && echo yes || echo no)")" \
    --argjson sa "$(b2j "$(svc_active smbd)")" --argjson se "$(b2j "$(svc_enabled smbd)")" \
    --argjson sg "$(b2j "$SAMBA_GUEST")" \
    --argjson fi "$(b2j "$(pkg_installed vsftpd && echo yes || echo no)")" \
    --argjson fa "$(b2j "$(svc_active vsftpd)")" --argjson fe "$(b2j "$(svc_enabled vsftpd)")" \
    --argjson fg "$(b2j "${FTP_GUEST:-yes}")" \
    --argjson ha "$(b2j "$(ssh_active)")" --argjson he "$(b2j "$(ssh_enabled)")" \
    --arg host "$(hostname)" --arg ips "$(hostname -I 2>/dev/null | xargs || true)" \
    --arg path "$DROP_DIR" --arg suser "$SAMBA_USER" \
    '{sambaInstalled:$si,sambaActive:$sa,sambaEnabled:$se,sambaGuest:$sg,ftpInstalled:$fi,ftpActive:$fa,ftpEnabled:$fe,ftpGuest:$fg,sshActive:$ha,sshEnabled:$he,host:$host,ips:$ips,dropPath:$path,sambaUser:$suser}'
}

build_stack_cache() {
  refresh_pkg_cache
  local id busy components=()
  busy="$(svc_active wtc-stack-job.service)"
  for id in "${STACK_IDS[@]}"; do
    local inst=false
    if stack_installed "$id"; then inst=true; fi
    components+=("$(jq -nc --arg id "$id" --arg label "${STACK_LABELS[$id]}" --arg pk "${STACK_PKGS[$id]}" \
      --argjson inst "$inst" '{id:$id,label:$label,packages:$pk,installed:$inst}')")
  done
  mkdir -p "$(dirname "$STACK_CACHE_FILE")"
  printf '%s\n' "${components[@]}" | jq -s --argjson busy "$(b2j "$busy")" --arg log "$(tail -n 60 "$STACK_LOG" 2>/dev/null || true)" \
    '{busy:$busy,log:$log,components:.}' > "$STACK_CACHE_FILE.tmp"
  mv -f "$STACK_CACHE_FILE.tmp" "$STACK_CACHE_FILE"
}

json_stack() {
  local busy
  busy="$(svc_active wtc-stack-job.service)"
  # If a stack job is running or cache missing, refresh cache
  if [ ! -f "$STACK_CACHE_FILE" ] || [ "$busy" = "yes" ]; then
    build_stack_cache
  fi
  cat "$STACK_CACHE_FILE"
}

cmd_workers() {
  local action="${1:-status}"
  case "$action" in
    status)  /usr/local/bin/wtc-workers status "${2:-}" ;;
    start)   /usr/local/bin/wtc-workers start ;;
    stop)    /usr/local/bin/wtc-workers stop ;;
    restart) /usr/local/bin/wtc-workers restart ;;
    logs)    /usr/local/bin/wtc-workers logs "${2:-50}" ;;
    *) die "Unknown workers action: $action" ;;
  esac
}

cmd_nginx_save() {
  local target="${1:-ram}" restart="${2:-restart}" content="${3:-}"
  local target_file=""
  case "$target" in
    ram|site)  target_file="$RAM_NGINX_CONF" ;;
    wtc|vhost) target_file="/etc/nginx/sites-available/wtc" ;;
    ui)        target_file="/etc/nginx/sites-available/wtc-ui" ;;
    *) die "Invalid target '$target' (use ram|wtc|ui)" ;;
  esac

  if [ -z "$content" ]; then
    content="$(cat)"
  fi
  [ -n "$content" ] || die "Configuration content cannot be empty"

  mkdir -p "$(dirname "$target_file")"
  local backup; backup="$(mktemp)"
  if [ -f "$target_file" ]; then cp -f "$target_file" "$backup"; else : > "$backup"; fi

  printf '%s\n' "$content" > "$target_file"

  local test_out
  if ! test_out="$(nginx -t 2>&1)"; then
    if [ -s "$backup" ]; then
      cp -f "$backup" "$target_file"
    else
      rm -f "$target_file"
    fi
    rm -f "$backup"
    die "Nginx syntax error; configuration was not applied. Details: $test_out"
  fi
  rm -f "$backup"

  if [ "$restart" = "restart" ]; then
    systemctl restart nginx 2>/dev/null || systemctl reload nginx 2>/dev/null || true
    log "Updated $target_file and restarted Nginx"
    echo "Configuration saved to $target_file and Nginx restarted successfully."
  else
    systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
    log "Updated $target_file and reloaded Nginx"
    echo "Configuration saved to $target_file and Nginx reloaded successfully."
  fi
}

cmd_nginx_test() {
  local target="${1:-ram}" content="${2:-}"
  local target_file=""
  case "$target" in
    ram|site)  target_file="$RAM_NGINX_CONF" ;;
    wtc|vhost) target_file="/etc/nginx/sites-available/wtc" ;;
    ui)        target_file="/etc/nginx/sites-available/wtc-ui" ;;
    *) die "Invalid target '$target' (use ram|wtc|ui)" ;;
  esac

  if [ -z "$content" ]; then
    content="$(cat)"
  fi
  [ -n "$content" ] || die "Configuration content cannot be empty"

  mkdir -p "$(dirname "$target_file")"
  local backup; backup="$(mktemp)"
  if [ -f "$target_file" ]; then cp -f "$target_file" "$backup"; else : > "$backup"; fi

  printf '%s\n' "$content" > "$target_file"
  local test_out rc=0
  test_out="$(nginx -t 2>&1)" || rc=$?

  if [ -s "$backup" ]; then
    cp -f "$backup" "$target_file"
  else
    rm -f "$target_file"
  fi
  rm -f "$backup"

  echo "$test_out"
  return "$rc"
}

cmd_nginx_ctl() {
  local action="${1:-restart}"
  case "$action" in
    reload)
      nginx -t 2>&1 || die "Cannot reload: Nginx configuration test failed"
      systemctl reload nginx
      log "Nginx reloaded"
      echo "Nginx reloaded successfully"
      ;;
    restart)
      nginx -t 2>&1 || die "Cannot restart: Nginx configuration test failed"
      systemctl restart nginx
      log "Nginx restarted"
      echo "Nginx restarted successfully"
      ;;
    test)
      nginx -t 2>&1
      ;;
    *) die "Unknown nginx action '$action' (use reload|restart|test)" ;;
  esac
}

cmd_nginx_reset() {
  ensure_ramdisk
  local live; live="$(readlink -f "$WWW_LINK" 2>/dev/null || true)"
  [ -d "$live" ] || die "No active site loaded in RAM disk"
  local stack; stack="$(detect_site_stack "$live")"
  generate_stack_nginx_conf "$stack" "$live" "$RAM_NGINX_CONF"
  nginx -t 2>&1 || die "Generated Nginx configuration failed syntax test"
  systemctl restart nginx 2>/dev/null || systemctl reload nginx 2>/dev/null || true
  log "Reset Nginx configuration to stack default ($stack)"
  echo "Reset Nginx configuration to '$stack' stack default and restarted Nginx."
}

json_nginx() {
  local wtc_conf="" site_conf="" ui_conf="" ram_conf="" cur live stack="unknown" webroot=""
  [ -f /etc/nginx/sites-available/wtc ] && wtc_conf="$(cat /etc/nginx/sites-available/wtc)"
  [ -f "$NGINX_SNIPPET" ] && site_conf="$(cat "$NGINX_SNIPPET")"
  [ -f "$RAM_NGINX_CONF" ] && ram_conf="$(cat "$RAM_NGINX_CONF")"
  [ -f /etc/nginx/sites-available/wtc-ui ] && ui_conf="$(cat /etc/nginx/sites-available/wtc-ui)"
  cur="$(active_site)"
  live="$(readlink -f "$WWW_LINK" 2>/dev/null || true)"
  [ -d "$live" ] && stack="$(detect_site_stack "$live")"
  webroot="$(readlink -f "$RAM_MOUNT/webroot" 2>/dev/null || true)"
  local test_out; test_out="$(nginx -t 2>&1 || true)"
  local ok=true
  if ! nginx -t >/dev/null 2>&1; then ok=false; fi

  jq -nc --arg wtc "$wtc_conf" --arg site "$site_conf" --arg ram "$ram_conf" \
    --arg ui "$ui_conf" --arg cur "$cur" --arg stack "$stack" \
    --arg webroot "$webroot" --arg live "$live" \
    --arg test "$test_out" --argjson ok "$ok" \
    '{wtc:$wtc,site:$site,ram:$ram,ui:$ui,cur:$cur,stack:$stack,webroot:$webroot,live:$live,test:$test,ok:$ok}'
}

json_firewall() {
  local installed=false active=false default_in="deny" default_out="allow" rules="[]"
  if command -v ufw >/dev/null 2>&1 || [ -x /usr/sbin/ufw ]; then
    installed=true
  fi

  if [ "$installed" = true ]; then
    local status_out; status_out="$(ufw status verbose 2>/dev/null || true)"
    if echo "$status_out" | grep -qi "Status: active"; then
      active=true
    fi

    # Parse rules numbered
    rules="$(ufw status numbered 2>/dev/null | awk '
      BEGIN { print "[" ; first = 1 }
      /^\[[ 0-9]+\]/ {
        # Format: [ 1] 22/tcp ALLOW IN Anywhere
        line = $0
        gsub(/^\[[ ]*/, "", line)
        idx = substr(line, 1, index(line, "]") - 1)
        rest = substr(line, index(line, "]") + 1)
        sub(/^[ ]+/, "", rest)

        split(rest, parts)
        port_proto = parts[1]
        action = parts[2]
        direction = parts[3]
        from_ip = parts[4]

        # Extract comment if present
        comment = ""
        if (index(line, "#") > 0) {
          comment = substr(line, index(line, "#") + 1)
          gsub(/^[ ]+/, "", comment)
        }

        port = port_proto
        proto = "any"
        if (index(port_proto, "/") > 0) {
          split(port_proto, pp, "/")
          port = pp[1]
          proto = pp[2]
        }

        gsub(/\\/, "\\\\", comment); gsub(/"/, "\\\"", comment)
        gsub(/\\/, "\\\\", port); gsub(/"/, "\\\"", port)

        if (!first) printf ",\n"
        printf "{\"index\":%s,\"port\":\"%s\",\"proto\":\"%s\",\"action\":\"%s\",\"direction\":\"%s\",\"from\":\"%s\",\"comment\":\"%s\"}", idx, port, proto, action, direction, from_ip, comment
        first = 0
      }
      END { print "\n]" }
    ' || echo "[]")"
  fi

  # Identify open listening ports from ss to cross-reference
  local listeners_json
  listeners_json="$(ss -tulpn 2>/dev/null | awk '
    NR > 1 && $1 ~ /^tcp/ {
      laddr = $5; pinfo = $7
      gsub(/.*:/, "", laddr)
      gsub(/.*users:\(\("/, "", pinfo); gsub(/".*/, "", pinfo)
      if (laddr != "" && !seen[laddr]++) {
        ports = ports (ports == "" ? "" : ",") sprintf("{\"port\":%s,\"proc\":\"%s\"}", (laddr ~ /^[0-9]+$/ ? laddr : 0), pinfo)
      }
    }
    END { print "[" ports "]" }
  ' || echo "[]")"

  jq -nc \
    --argjson installed "$installed" \
    --argjson active "$active" \
    --arg defaultIn "$default_in" \
    --arg defaultOut "$default_out" \
    --argjson rules "$rules" \
    --argjson listeners "$listeners_json" \
    '{installed:$installed,active:$active,default_in:$defaultIn,default_out:$defaultOut,rules:$rules,listeners:$listeners}'
}

cmd_json() {
  case "${1:-}" in
    sites) json_sites ;; status) json_status ;; dropbox) json_dropbox ;;
    settings) json_settings ;; transfer) json_transfer ;; stack) json_stack ;;
    build) json_build ;; workers) /usr/local/bin/wtc-workers status --json ;;
    nginx) json_nginx ;; sysinfo) json_sysinfo ;; processes) json_processes ;;
    net_monitor) json_net_monitor "${2:-30}" ;;
    firewall) json_firewall ;;
    *) die "Unknown json view" ;;
  esac
}

# ------------------------------------------------------------ whiptail fallback
pick_site() {
  local cur items=() s; cur="$(active_site)"
  while read -r s; do
    [ -z "$s" ] && continue
    items+=("$s" "$([ "$s" = "$cur" ] && echo ACTIVE || echo '')")
  done < <(list_sites)
  [ "${#items[@]}" -gt 0 ] || { whiptail --msgbox "No sites available." 8 40; return 1; }
  whiptail --title "$1" --menu "Select a site" 18 60 10 "${items[@]}" 3>&1 1>&2 2>&3
}

menu() {
  command -v whiptail >/dev/null || die "whiptail not installed"
  local c s
  while :; do
    c="$(whiptail --title "WTC Pi  [active: $(active_site)]  UI: http://$(hostname -I | awk '{print $1}'):4485" \
      --menu "Choose an action" 18 76 8 \
      1 "Switch active site" 2 "Status" 3 "Process dropbox now" 4 "Save state of live site" \
      5 "Rollback a site" 0 "Exit" 3>&1 1>&2 2>&3)" || break
    case "$c" in
      1) if s="$(pick_site 'Switch site')"; then
           if cmd_set "$s" >/dev/null 2>&1; then whiptail --msgbox "Now serving: $s" 8 40; else whiptail --msgbox "Swap failed - see $LOG_FILE" 8 50; fi
         fi ;;
      2) whiptail --scrolltext --msgbox "$(cmd_status)" 20 70 ;;
      3) "$SERVICE_BIN" --ingest >/dev/null 2>&1 || true; whiptail --msgbox "Dropbox processed." 8 40 ;;
      4) if "$SERVICE_BIN" --save-state >/dev/null 2>&1; then whiptail --msgbox "State saved." 8 40; else whiptail --msgbox "Save failed" 8 40; fi ;;
      5) if s="$(pick_site Rollback)"; then cmd_rollback "$s" >/dev/null 2>&1 && whiptail --msgbox "Rolled back $s" 8 40 || whiptail --msgbox "No previous version" 8 40; fi ;;
      0) break ;;
    esac
  done
}

case "${1:-}" in
  "")           menu ;;
  --list)       cmd_list ;;
  --current)    active_site ;;
  --status)     cmd_status ;;
  --logs)       cmd_logs "${2:-50}" ;;
  --set)        cmd_set "${2:-}" ;;
  --activate|--enable-site)   cmd_activate "${2:-}" "${3:-1}" ;;
  --deactivate|--disable-site) cmd_deactivate "${2:-}" ;;
  --rollback)   cmd_rollback "${2:-}" ;;
  --delete)     cmd_delete "${2:-}" ;;
  --create)     cmd_create "${2:-}" ;;
  --ingest)     "$SERVICE_BIN" --ingest ;;
  --save-state) "$SERVICE_BIN" --save-state ;;
  --json)       cmd_json "${2:-}" "${3:-}" ;;
  --setting)    cmd_setting "${2:-}" "${3:-}" ;;
  --transfer)   cmd_transfer "${2:-}" "${3:-}" ;;
  --stack)      cmd_stack "${2:-}" "${3:-}" ;;
  --stack-exec) cmd_stack_exec "${2:-}" "${3:-}" ;;
  --device)     cmd_device "${2:-}" ;;
  --db-create)      cmd_db_create "${2:-}" ;;
  --db-import)      cmd_db_import "${2:-}" ;;
  --db-export)      cmd_db_export "${2:-}" "${3:-}" ;;
  --db-info)        cmd_db_info "${2:-}" ;;
  --db-test)        cmd_db_test "${2:-}" ;;
  --db-creds)       cmd_db_creds "${2:-}" ;;
  --db-tables)      cmd_db_tables "${2:-}" ;;
  --db-query)       cmd_db_query "${2:-}" "${3:-}" ;;
  --artisan)        cmd_artisan "${2:-}" "${3:-}" ;;
  --direct-deploy)  cmd_direct_deploy "${2:-}" "${3:-}" ;;
  --store-site)     cmd_store_site "${2:-}" "${3:-}" ;;
  --build)          cmd_build "${2:-}" ;;
  --build-exec)     cmd_build_exec "${2:-}" ;;
  --server-tune)    cmd_server_tune ;;
  --reload-ui)      cmd_reload_ui ;;
  --workers)        cmd_workers "${2:-status}" "${3:-}" ;;
  --nginx-save)     cmd_nginx_save "${2:-ram}" "${3:-restart}" "${4:-}" ;;
  --nginx-test)     cmd_nginx_test "${2:-ram}" "${3:-}" ;;
  --nginx-ctl)      cmd_nginx_ctl "${2:-restart}" ;;
  --rescan-sites)   cmd_rescan_sites ;;
  --rescan-stack)   build_stack_cache ;;
  --firewall)       cmd_firewall "${2:-status}" "${3:-}" "${4:-tcp}" ;;
  *) echo "Usage: wtc-manage [--list|--current|--status|--logs N|--set S|--rollback S|--delete S|--create S|--ingest|--save-state|--reload-ui|--rescan-sites|--rescan-stack|--firewall ACT [PORT] [PROTO]|--json V|--setting K V|--transfer ...|--stack A ID|--device A|--db-create S|--db-import S|--db-export S [F]|--db-info S|--db-test S|--db-creds S|--db-tables S|--db-query S [Q]|--artisan S CMD|--direct-deploy S DIR|--store-site S DIR|--build S|--server-tune|--workers ACTION|--nginx-save T [R]|--nginx-test T|--nginx-ctl A|--nginx-reset]"; exit 2 ;;
esac

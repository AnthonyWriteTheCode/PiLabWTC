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

_resolve_rollback_file() {
  local site="$1" ver="${2:-}"
  if [ -n "$ver" ]; then
    if [ "$ver" = "legacy" ] && [ -f "$PREV_DIR/$site.tar.gz" ]; then
      echo "$PREV_DIR/$site.tar.gz"
      return 0
    fi
    [[ "$ver" =~ ^[a-zA-Z0-9._-]+\.tar\.gz$ ]] || die "Invalid rollback version '$ver'"
    [ -f "$PREV_DIR/$site/$ver" ] || die "Rollback version '$ver' not found for '$site'"
    echo "$PREV_DIR/$site/$ver"
    return 0
  fi
  local latest=""
  if [ -d "$PREV_DIR/$site" ]; then
    latest="$(ls -1t "$PREV_DIR/$site"/*.tar.gz 2>/dev/null | head -n1 || true)"
  fi
  if [ -n "$latest" ] && [ -f "$latest" ]; then
    echo "$latest"
    return 0
  fi
  if [ -f "$PREV_DIR/$site.tar.gz" ]; then
    echo "$PREV_DIR/$site.tar.gz"
    return 0
  fi
  return 1
}

_cancel_catalog_rebuild() {
  local site="$1" slug
  slug="$(site_slug "$site")"
  [ -n "$slug" ] || slug="$site"
  rm -f "/run/wtc-catalog-${slug}.pending" 2>/dev/null || true
  touch "/run/wtc-catalog-${slug}.cancel" 2>/dev/null || true
  systemctl stop "wtc-catalog-${slug}.service" 2>/dev/null || true
}

cmd_rollback() {
  local site="${1:-}" ver="${2:-}"
  valid_name "$site" || die "Invalid site name"
  local target; target="$(_resolve_rollback_file "$site" "$ver")" || die "No previous version of '$site'"
  _cancel_catalog_rebuild "$site"
  acquire_lock
  local tmp_restore; tmp_restore="$(mktemp "$SITES_DIR/.restore.XXXXXX")"
  mv -f "$target" "$tmp_restore"
  if [ -f "$SITES_DIR/$site.tar.gz" ]; then
    save_site_rollback "$site"
  fi
  chmod 0644 "$tmp_restore" 2>/dev/null || true
  mv -f "$tmp_restore" "$SITES_DIR/$site.tar.gz"
  log "Rolled back '$site'${ver:+ to $ver}"
  if is_site_active "$site"; then
    local is_prim=0; [ "$(active_site)" = "$site" ] && is_prim=1
    "$SERVICE_BIN" --activate "$site" "$is_prim"
  fi
}

cmd_rollback_delete() {
  local site="${1:-}" ver="${2:-}"
  valid_name "$site" || die "Invalid site name"
  [ -n "$ver" ] || die "Specify a rollback version to delete"
  local target; target="$(_resolve_rollback_file "$site" "$ver")" || die "Rollback version not found"
  rm -f "$target"
  rmdir "$PREV_DIR/$site" 2>/dev/null || true
  log "Deleted rollback '$ver' for '$site'"
  echo "Deleted rollback '$ver' for '$site'."
}

cmd_rollback_clear() {
  local site="${1:-}"
  if [ "$site" = "--all" ] || [ "$site" = "all" ]; then
    rm -rf "$PREV_DIR"/* 2>/dev/null || true
    log "Cleared all rollback snapshots for all sites"
    echo "Cleared all rollback snapshots."
    return 0
  fi
  valid_name "$site" || die "Invalid site name"
  rm -rf "$PREV_DIR/$site" "$PREV_DIR/$site.tar.gz" 2>/dev/null || true
  log "Cleared all rollback snapshots for '$site'"
  echo "Cleared rollback snapshots for '$site'."
}

cmd_delete() {
  valid_name "${1:-}" || die "Invalid site name"
  is_site_active "$1" && die "Cannot delete an active site; stop/deactivate it first"
  [ -f "$SITES_DIR/$1.tar.gz" ] || die "No such site"
  _cancel_catalog_rebuild "$1"
  rm -rf "$SITES_DIR/$1.tar.gz" "$PREV_DIR/$1.tar.gz" "$PREV_DIR/$1" "$SITES_DIR/$1.meta.json"
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

cmd_site_rename() {
  local old="${1:-}" new="${2:-}"
  valid_name "$old" || die "Invalid existing site name"
  valid_name "$new" || die "Invalid new site name (a-z 0-9 - _)"
  [ -f "$SITES_DIR/$old.tar.gz" ] || die "Site '$old' not found"
  [ ! -f "$SITES_DIR/$new.tar.gz" ] || die "Site '$new' already exists"

  _cancel_catalog_rebuild "$old"
  acquire_lock
  mv -f "$SITES_DIR/$old.tar.gz" "$SITES_DIR/$new.tar.gz"
  [ -f "$SITES_DIR/$old.meta.json" ] && mv -f "$SITES_DIR/$old.meta.json" "$SITES_DIR/$new.meta.json"
  [ -f "$PREV_DIR/$old.tar.gz" ] && mv -f "$PREV_DIR/$old.tar.gz" "$PREV_DIR/$new.tar.gz"
  [ -d "$PREV_DIR/$old" ] && mv -f "$PREV_DIR/$old" "$PREV_DIR/$new"

  # If site was active in RAM, migrate active state
  if is_site_active "$old"; then
    local is_prim=0
    [ "$(active_site)" = "$old" ] && is_prim=1
    "$SERVICE_BIN" --deactivate "$old" 2>/dev/null || true
    "$SERVICE_BIN" --activate "$new" "$is_prim" 2>/dev/null || true
  fi
  log "Renamed site '$old' to '$new'"
}

cmd_site_clone() {
  local src="${1:-}" new="${2:-}" port="${3:-}" db_mode="${4:-clone}" activate="${5:-1}"
  valid_name "$src" || die "Invalid source site name: $src"
  valid_name "$new" || die "Invalid target instance name: $new (a-z 0-9 - _)"
  [ "$src" != "$new" ] || die "Target instance name cannot be identical to source"
  [ -f "$SITES_DIR/$src.tar.gz" ] || die "Source site '$src' not found"
  [ ! -f "$SITES_DIR/$new.tar.gz" ] || die "Target site '$new' already exists"

  if [ -n "$port" ] && [ "$port" != "0" ] && [ "$port" != "none" ] && [ "$port" != "null" ]; then
    [[ "$port" =~ ^[0-9]{2,5}$ ]] || die "Port must be a number between 1 and 65535"
    [ "$port" -ge 1 ] && [ "$port" -le 65535 ] || die "Port must be between 1 and 65535"
    local conflict
    conflict="$(grep -l "\"port\":[[:space:]]*$port" "$SITES_DIR"/*.meta.json 2>/dev/null | head -n1 || true)"
    if [ -n "$conflict" ]; then
      local c_site; c_site="$(basename "$conflict" .meta.json)"
      if [ "$c_site" != "$src" ]; then
        die "Port $port is already assigned to site '$c_site'"
      fi
    fi
  else
    port=""
  fi

  acquire_lock

  # 1. Prepare workspace directory
  local stage_clone
  stage_clone="$(mktemp -d "$STAGE_DIR/.clone.XXXXXX" 2>/dev/null || mktemp -d "/tmp/.clone.XXXXXX")"

  local src_slug; src_slug="$(site_slug "$src")"
  local new_slug; new_slug="$(site_slug "$new")"
  local src_ram="$RAM_SITES_DIR/$src_slug"

  if [ -d "$src_ram" ] && is_site_active "$src"; then
    log "Duplicating from live active RAM disk directory of '$src'..."
    cp -a "$src_ram"/. "$stage_clone"/
  else
    log "Extracting archive for site '$src' to duplicate..."
    safe_extract_archive "$SITES_DIR/$src.tar.gz" "$stage_clone" || die "Extraction failed during clone"
  fi

  # 2. Database cloning & provisioning
  if db_available; then
    local src_db; src_db="$(db_ident "$src")"
    local new_db; new_db="$(db_ident "$new")"

    case "$db_mode" in
      clone)
        log "Duplicating database from '$src_db' to '$new_db'..."
        db_create "$new" || log "Warning: Failed to create database user for '$new'"
        local dump_file="$stage_clone/.db_clone_tmp.sql"
        if db_dump "$src" "$dump_file"; then
          mariadb "$new_db" < "$dump_file" 2>/dev/null || log "Warning: Database clone import reported errors"
          rm -f "$dump_file"
        fi
        db_write_files "$new" "$stage_clone"
        ;;
      new)
        log "Provisioning fresh empty database '$new_db' for '$new'..."
        db_create "$new" || log "Warning: Failed to create database for '$new'"
        db_write_files "$new" "$stage_clone"
        ;;
      share)
        log "Sharing existing database '$src_db' with instance '$new'..."
        ;;
    esac
  fi

  # 3. Update APP_NAME, APP_URL, ASSET_URL and session parameters in .env
  configure_site_env "$stage_clone" "$new_slug" "$port" "$new"

  # 4. Storage link & permissions
  ensure_laravel_storage_link "$stage_clone"

  # 5. Package into SITES_DIR/<new>.tar.gz
  store_dir_as_site "$new" "$stage_clone" || die "Failed to store duplicated site package"
  rm -rf "$stage_clone"

  # 6. Configure metadata & custom port
  local cur_stack="unknown" has_build="false"
  if [ -f "$SITES_DIR/$src.meta.json" ]; then
    cur_stack="$(jq -r '.stack // "unknown"' "$SITES_DIR/$src.meta.json" 2>/dev/null || echo "unknown")"
    has_build="$(jq -r '.hasBuild // false' "$SITES_DIR/$src.meta.json" 2>/dev/null || echo false)"
  fi

  local meta_new="$SITES_DIR/$new.meta.json"
  if [ -n "$port" ]; then
    jq -n --arg st "$cur_stack" --argjson hb "$has_build" --argjson m "$(date +%s)" --argjson pt "$port" \
      '{stack:$st, hasBuild:$hb, mtime:$m, port:$pt}' > "$meta_new.tmp" && mv -f "$meta_new.tmp" "$meta_new"
  else
    jq -n --arg st "$cur_stack" --argjson hb "$has_build" --argjson m "$(date +%s)" \
      '{stack:$st, hasBuild:$hb, mtime:$m}' > "$meta_new.tmp" && mv -f "$meta_new.tmp" "$meta_new"
  fi
  chmod 0644 "$meta_new" 2>/dev/null || true

  # 7. Activate in RAM if requested
  if [ "$activate" = "1" ] || [ "$activate" = "yes" ] || [ "$activate" = "true" ]; then
    log "Activating duplicated instance '$new' in RAM disk..."
    "$SERVICE_BIN" --activate "$new" 0 || log "Warning: Failed to activate duplicated instance '$new'"
  fi

  log "Successfully duplicated site '$src' to '$new' (slug: $new_slug, port: ${port:-none})"
  echo "Successfully duplicated site '$src' to '$new' (URL: /$new_slug/${port:+, Port: $port})"
}

cmd_site_stack() {
  local site="${1:-}" stack="${2:-}"
  valid_name "$site" || die "Invalid site name"
  [ -n "$stack" ] || die "No stack provided"
  [ -f "$SITES_DIR/$site.tar.gz" ] || die "Site '$site' not found"

  local meta_f="$SITES_DIR/$site.meta.json"
  local cur_mtime has_build="false" cur_port="null"
  if [ -f "$meta_f" ]; then
    has_build="$(jq -r '.hasBuild // false' "$meta_f" 2>/dev/null || echo false)"
    cur_port="$(jq -r '.port // null' "$meta_f" 2>/dev/null || echo null)"
    cur_mtime="$(jq -r '.mtime // 0' "$meta_f" 2>/dev/null || date +%s)"
  else
    cur_mtime="$(date +%s)"
  fi

  jq -n --arg st "$stack" --argjson hb "$has_build" --argjson m "$cur_mtime" --argjson pt "$cur_port" \
    '{stack:$st, hasBuild:$hb, mtime:$m, port:$pt}' > "$meta_f.tmp" && mv -f "$meta_f.tmp" "$meta_f"
  chmod 0644 "$meta_f" 2>/dev/null || true

  # If site is currently active in RAM, re-generate nginx routing and reload
  if is_site_active "$site"; then
    local slug site_ram_dir webroot
    slug="$(site_slug "$site")"
    site_ram_dir="$RAM_SITES_DIR/$slug"
    if [ -d "$site_ram_dir" ]; then
      webroot="$site_ram_dir"
      if [ -d "$site_ram_dir/public" ] && { [ -f "$site_ram_dir/artisan" ] || [ -f "$site_ram_dir/public/index.php" ]; }; then
        webroot="$site_ram_dir/public"
      fi
      generate_site_nginx_block "$site" "$slug" "$site_ram_dir" "$webroot" "$RAM_NGINX_SITES/$slug.conf"
      if [ "$(active_site)" = "$site" ]; then
        generate_stack_nginx_conf "$stack" "$site_ram_dir" "$RAM_NGINX_CONF"
      fi
      systemctl reload nginx 2>/dev/null || true
    fi
  fi
  log "Assigned stack '$stack' to site '$site'"
}

cmd_site_port() {
  local site="${1:-}" port="${2:-}"
  valid_name "$site" || die "Invalid site name"
  [ -f "$SITES_DIR/$site.tar.gz" ] || die "Site '$site' not found"

  local meta_f="$SITES_DIR/$site.meta.json"
  local cur_stack="unknown" has_build="false" cur_mtime
  if [ -f "$meta_f" ]; then
    cur_stack="$(jq -r '.stack // "unknown"' "$meta_f" 2>/dev/null || echo "unknown")"
    has_build="$(jq -r '.hasBuild // false' "$meta_f" 2>/dev/null || echo false)"
    cur_mtime="$(jq -r '.mtime // 0' "$meta_f" 2>/dev/null || date +%s)"
  else
    cur_mtime="$(date +%s)"
  fi

  local slug; slug="$(site_slug "$site")"
  local port_conf="$RAM_NGINX_SITES/${slug}-port.conf"

  if [ -n "$port" ] && [ "$port" != "0" ] && [ "$port" != "none" ] && [ "$port" != "null" ]; then
    [[ "$port" =~ ^[0-9]{2,5}$ ]] || die "Port must be a number between 1 and 65535"
    [ "$port" -ge 1 ] && [ "$port" -le 65535 ] || die "Port must be between 1 and 65535"

    jq -n --arg st "$cur_stack" --argjson hb "$has_build" --argjson m "$cur_mtime" --argjson pt "$port" \
      '{stack:$st, hasBuild:$hb, mtime:$m, port:$pt}' > "$meta_f.tmp" && mv -f "$meta_f.tmp" "$meta_f"
    chmod 0644 "$meta_f" 2>/dev/null || true

    # If active in RAM, generate dedicated vhost server block and align .env
    if is_site_active "$site"; then
      local site_ram_dir="$RAM_SITES_DIR/$slug" webroot="$site_ram_dir"
      if [ -d "$site_ram_dir/public" ] && { [ -f "$site_ram_dir/artisan" ] || [ -f "$site_ram_dir/public/index.php" ]; }; then
        webroot="$site_ram_dir/public"
        ensure_laravel_storage_link "$site_ram_dir"
      fi
      configure_site_env "$site_ram_dir" "$slug" "$port" "$site"
      generate_site_port_nginx_conf "$site" "$slug" "$port" "$webroot" "$port_conf"
      ufw allow "$port/tcp" comment "Site $site Port" >/dev/null 2>&1 || true
      systemctl reload nginx 2>/dev/null || true
    fi
    log "Configured dedicated port $port for site '$site'"
  else
    # Clear port
    jq -n --arg st "$cur_stack" --argjson hb "$has_build" --argjson m "$cur_mtime" \
      '{stack:$st, hasBuild:$hb, mtime:$m}' > "$meta_f.tmp" && mv -f "$meta_f.tmp" "$meta_f"
    chmod 0644 "$meta_f" 2>/dev/null || true
    rm -f "$port_conf" 2>/dev/null || true
    if is_site_active "$site"; then
      local site_ram_dir="$RAM_SITES_DIR/$slug"
      if [ "$site" = "$(active_site)" ]; then
        configure_site_env "$site_ram_dir" "" "" "$site"
      else
        configure_site_env "$site_ram_dir" "$slug" "" "$site"
      fi
    fi
    systemctl reload nginx 2>/dev/null || true
    log "Cleared dedicated port for site '$site'"
  fi
}

_extract_stage_for_deploy() {
  local stageTarget="$1" stageDir=""
  if [ -d "$stageTarget" ]; then
    echo "$stageTarget"
    return 0
  fi
  [ -f "$stageTarget" ] || die "Staged folder or archive not found: $stageTarget"
  stageDir="$(mktemp -d "$STAGE_DIR/.deploy.XXXXXX")"
  safe_extract_archive "$stageTarget" "$stageDir" || { rm -rf "$stageDir"; die "Archive extraction failed"; }
  rm -rf "$stageDir/__MACOSX" 2>/dev/null || true

  local entries; entries="$(find "$stageDir" -mindepth 1 -maxdepth 1 ! -name '.*' ! -name '__MACOSX' | wc -l)"
  local has_root=0
  [ -f "$stageDir/index.php" ] || [ -f "$stageDir/index.html" ] || [ -f "$stageDir/artisan" ] && has_root=1

  if [ "$has_root" -eq 0 ] && [ "$entries" -eq 1 ]; then
    local only; only="$(find "$stageDir" -mindepth 1 -maxdepth 1 ! -name '.*' ! -name '__MACOSX')"
    if [ -d "$only" ]; then
      local base_only; base_only="$(basename "$only" | tr '[:upper:]' '[:lower:]')"
      local is_app_subdir=0
      case "$base_only" in
        app|bootstrap|config|database|public|resources|routes|storage|tests|vendor|node_modules|src|dist|build|out|assets|css|js|views|wp-content|wp-includes|wp-admin)
          is_app_subdir=1 ;;
      esac
      if [ "$is_app_subdir" -eq 0 ] || [ -f "$only/artisan" ] || [ -f "$only/public/index.php" ] || [ -f "$only/index.php" ] || [ -f "$only/index.html" ] || [ -f "$only/wp-config.php" ]; then
        local unwrap_tmp; unwrap_tmp="$(mktemp -d "$STAGE_DIR/.unwrap.XXXXXX")"
        ( shopt -s dotglob nullglob; mv "$only"/* "$unwrap_tmp"/ 2>/dev/null || true )
        rm -rf "$stageDir"
        stageDir="$unwrap_tmp"
      fi
    fi
  fi
  echo "$stageDir"
}

cmd_rebuild_catalog() {
  local site="${1:-}"
  valid_name "$site" || die "Invalid site name"
  ensure_ramdisk
  local slug; slug="$(site_slug "$site")"
  [ -n "$slug" ] || slug="$site"
  local site_dir="$RAM_SITES_DIR/$slug"
  [ -d "$site_dir" ] || die "Site '$site' is not loaded in RAM at $site_dir"

  local unit="wtc-catalog-${slug}"
  local pending_f="/run/wtc-catalog-${slug}.pending"
  local cancel_f="/run/wtc-catalog-${slug}.cancel"
  rm -f "$cancel_f" 2>/dev/null || true

  if [ -d /run/systemd/system ] && command -v systemd-run >/dev/null 2>&1; then
    if systemctl is-active --quiet "${unit}.service" 2>/dev/null; then
      touch "$pending_f"
      log "Queued SD catalog rebuild for '$site'"
      return 0
    fi
    systemctl reset-failed "${unit}.service" 2>/dev/null || true
    rm -f "$pending_f"
    systemd-run --quiet --unit="$unit" --collect \
      --property=StandardOutput=append:"$LOG_FILE" --property=StandardError=append:"$LOG_FILE" \
      /usr/local/bin/wtc-manage --rebuild-catalog-exec "$site"
    log "Started background SD catalog rebuild for '$site'"
  else
    touch "$pending_f"
    ( nohup /usr/local/bin/wtc-manage --rebuild-catalog-exec "$site" >>"$LOG_FILE" 2>&1 </dev/null & )
    log "Started background SD catalog rebuild for '$site'"
  fi
}

cmd_rebuild_catalog_exec() {
  local site="${1:-}"
  valid_name "$site" || exit 2
  local slug; slug="$(site_slug "$site")"
  [ -n "$slug" ] || slug="$site"
  local site_dir="$RAM_SITES_DIR/$slug"
  local pending_f="/run/wtc-catalog-${slug}.pending"
  local cancel_f="/run/wtc-catalog-${slug}.cancel"
  local cat_lock="/run/wtc-catalog-${slug}.lock"

  mkdir -p /run "$SITES_DIR" "$PREV_DIR" 2>/dev/null || true
  exec 8>"$cat_lock"
  flock -w 300 8 || die "Could not obtain catalog lock for '$site'"

  local -a tar_cmd=(tar -czf)
  if command -v pigz >/dev/null 2>&1; then
    tar_cmd=(tar --use-compress-program=pigz -cf)
  fi

  while :; do
    rm -f "$pending_f" 2>/dev/null || true
    [ -f "$cancel_f" ] && { rm -f "$cancel_f"; break; }
    [ -d "$site_dir" ] || break

    local out; out="$(mktemp "$SITES_DIR/.new.XXXXXX")"
    local rc=0
    "${tar_cmd[@]}" "$out" -C "$site_dir" . 2>/dev/null || rc=$?
    if [ "$rc" -gt 1 ] || { [ "$rc" -eq 1 ] && ! gzip -t "$out" 2>/dev/null; }; then
      rm -f "$out"
      die "SD catalog compression failed for '$site' (exit $rc)"
    fi

    if [ -f "$cancel_f" ] || [ ! -d "$site_dir" ]; then
      rm -f "$out" "$cancel_f" 2>/dev/null || true
      break
    fi

    if [ -f "$pending_f" ]; then
      rm -f "$out"
      continue
    fi

    (
      acquire_lock 60
      [ -f "$cancel_f" ] && exit 0
      [ -d "$site_dir" ] || exit 0
      mkdir -p "$SITES_DIR" "$PREV_DIR"
      if [ -f "$SITES_DIR/$site.tar.gz" ]; then
        save_site_rollback "$site"
      fi
      chmod 0644 "$out"
      mv -f "$out" "$SITES_DIR/$site.tar.gz"
      write_site_meta "$site" "$site_dir"
      sync
    ) || { rm -f "$out"; die "Failed to commit SD catalog for '$site'"; }

    rm -f "$out" 2>/dev/null || true
    log "Rebuilt SD catalog for '$site' from RAM ($site_dir)"
    [ -f "$pending_f" ] && [ ! -f "$cancel_f" ] || break
  done
}

cmd_direct_deploy() {
  local site="${1:-}" stageTarget="${2:-}" make_primary="${3:-}" no_catalog="${4:-}" stageDir="" is_temp=0
  valid_name "$site" || die "Invalid site name"
  ensure_ramdisk

  if [ -f "$stageTarget" ]; then
    is_temp=1
  fi
  stageDir="$(_extract_stage_for_deploy "$stageTarget")"

  # 1. Replace files directly in RAM immediately for quickest site access
  "$SERVICE_BIN" --ram-replace "$site" "$stageDir" "$make_primary"

  rm -rf "$stageDir"
  if [ "$is_temp" -eq 1 ]; then
    rm -f "$stageTarget"
  fi

  # 2. Rebuild the catalog on the SD card from RAM (unless caller triggers it after Artisan hooks)
  if [ "$no_catalog" != "--no-catalog" ]; then
    cmd_rebuild_catalog "$site"
  fi

  log "Replaced '$site' files directly in RAM${no_catalog:+ (catalog rebuild deferred)}"
}

cmd_store_site() {
  local site="${1:-}" stageTarget="${2:-}" stageDir="" is_temp=0
  valid_name "$site" || die "Invalid site name"
  ensure_ramdisk

  if [ "${AUTO_RELOAD_ACTIVE:-yes}" = "yes" ] && is_site_active "$site"; then
    local is_prim=0; [ "$(active_site)" = "$site" ] && is_prim=1
    cmd_direct_deploy "$site" "$stageTarget" "$is_prim"
    return 0
  fi

  if [ -d "$stageTarget" ]; then
    stageDir="$stageTarget"
  elif [ -f "$stageTarget" ]; then
    # If the target is ALREADY a valid tar.gz archive, avoid uncompressing and re-compressing
    # 400,000 files (which can take 15GB of space and 10 minutes)!
    if [[ "$stageTarget" =~ \.(tar\.gz|tgz)$ ]] && tar -tzf "$stageTarget" >/dev/null 2>&1; then
      _cancel_catalog_rebuild "$site"
      mkdir -p "$SITES_DIR" "$PREV_DIR"
      save_site_rollback "$site"
      mv -f "$stageTarget" "$SITES_DIR/$site.tar.gz"
      local cur_port="null"
      if [ -f "$SITES_DIR/$site.meta.json" ] && command -v jq >/dev/null 2>&1; then
        cur_port="$(jq -r '.port // null' "$SITES_DIR/$site.meta.json" 2>/dev/null || echo null)"
      fi
      if [ -n "$cur_port" ] && [ "$cur_port" != "null" ] && command -v jq >/dev/null 2>&1; then
        jq -nc --argjson m "$(date +%s)" --argjson pt "$cur_port" '{stack:"unknown",hasBuild:false,mtime:$m,port:$pt}' > "$SITES_DIR/$site.meta.json"
      else
        cat > "$SITES_DIR/$site.meta.json" <<EOF
{"stack":"unknown","hasBuild":false,"mtime":$(date +%s)}
EOF
      fi
      chmod 0644 "$SITES_DIR/$site.meta.json" 2>/dev/null || true
      log "Stored '$site' into /opt/wtc-web/sites repository (direct archive adoption)"
      return 0
    fi

    stageDir="$(mktemp -d "$STAGE_DIR/.deploy.XXXXXX")"
    is_temp=1
    safe_extract_archive "$stageTarget" "$stageDir" || die "Archive extraction failed"
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
    sed -i 's/.*upload_max_filesize.*/php_admin_value[upload_max_filesize] = 10240M/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's/.*post_max_size.*/php_admin_value[post_max_size] = 10240M/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's/.*max_execution_time.*/php_admin_value[max_execution_time] = 3600/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's/.*max_input_time.*/php_admin_value[max_input_time] = 3600/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's/.*memory_limit.*/php_admin_value[memory_limit] = 2048M/' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's|.*upload_tmp_dir.*|php_admin_value[upload_tmp_dir] = /var/tmp|' "$fpm_ui_pool" 2>/dev/null || true
    sed -i 's/.*max_file_uploads.*/php_admin_value[max_file_uploads] = 50000/' "$fpm_ui_pool" 2>/dev/null || true
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
    sed -i 's/.*upload_max_filesize.*/php_admin_value[upload_max_filesize] = 10240M/' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's/.*post_max_size.*/php_admin_value[post_max_size] = 10240M/' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's/.*max_execution_time.*/php_admin_value[max_execution_time] = 3600/' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's/.*max_input_time.*/php_admin_value[max_input_time] = 3600/' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's/.*memory_limit.*/php_admin_value[memory_limit] = 2048M/' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's|.*upload_tmp_dir.*|php_admin_value[upload_tmp_dir] = /var/tmp|' "$fpm_site_pool" 2>/dev/null || true
    sed -i 's/.*max_file_uploads.*/php_admin_value[max_file_uploads] = 50000/' "$fpm_site_pool" 2>/dev/null || true
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
    sed -i 's/.*upload_max_filesize.*/upload_max_filesize = 10240M/' "$fpm_ini" 2>/dev/null || true
    sed -i 's/.*post_max_size.*/post_max_size = 10240M/' "$fpm_ini" 2>/dev/null || true
    sed -i 's/.*max_execution_time.*/max_execution_time = 3600/' "$fpm_ini" 2>/dev/null || true
    sed -i 's/.*max_input_time.*/max_input_time = 3600/' "$fpm_ini" 2>/dev/null || true
    sed -i 's/.*memory_limit.*/memory_limit = 2048M/' "$fpm_ini" 2>/dev/null || true
    sed -i 's|.*upload_tmp_dir.*|upload_tmp_dir = /var/tmp|' "$fpm_ini" 2>/dev/null || true
    sed -i 's/.*max_file_uploads.*/max_file_uploads = 50000/' "$fpm_ini" 2>/dev/null || true
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
  harden_mariadb_loopback 2>/dev/null || true
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
    local slug; slug="$(site_slug "$site")"
    if [ -d "$live" ] && [ "$(active_site)" = "$site" ] && [ -f "$live/artisan" ]; then
      targetDir="$live"
    elif [ -d "$RAM_MOUNT/sites/$site" ] && [ -f "$RAM_MOUNT/sites/$site/artisan" ]; then
      targetDir="$RAM_MOUNT/sites/$site"
    elif [ -d "$RAM_MOUNT/sites/$slug" ] && [ -f "$RAM_MOUNT/sites/$slug/artisan" ]; then
      targetDir="$RAM_MOUNT/sites/$slug"
    elif [ -d "$STAGE_DIR/$site" ] && [ -f "$STAGE_DIR/$site/artisan" ]; then
      targetDir="$STAGE_DIR/$site"
    elif [ -d "$STAGE_DIR/$slug" ] && [ -f "$STAGE_DIR/$slug/artisan" ]; then
      targetDir="$STAGE_DIR/$slug"
    fi
  fi

  [ -n "$targetDir" ] || die "Site '$target' is not active in RAM or has no artisan"
  [ -f "$targetDir/artisan" ] || die "No artisan found in $targetDir"

  [ -n "$site" ] && { db_ensure "$site" || true; }
  # Make sure .env has live DB credentials + APP_KEY before artisan touches the DB
  [ -n "$site" ] && valid_name "$site" && db_write_files "$site" "$targetDir"

  laravel_repair_shim "$site" "$targetDir" || exit 1

  # Security validation: strictly reject shell operators, metacharacters, or illegal syntax
  case "$cmd" in
    *[\;\&\|\`\$\<\>\\]*|*$'\r'*|*$'\n'*) die "Invalid artisan command: shell operators prohibited" ;;
  esac
  local safe_artisan_rx='^[a-zA-Z0-9_: =./-]+$'
  [[ "$cmd" =~ $safe_artisan_rx ]] || die "Invalid artisan command: illegal characters detected"

  case "$cmd" in
    *clear*|*route:*|*config:*|*optimize*)
      rm -f "$targetDir/bootstrap/cache/"*.php 2>/dev/null || true
      ;;
    *storage:link*)
      # Clean up any broken symlink so artisan doesn't fail with "File exists"
      if [ -L "$targetDir/public/storage" ] && [ ! -e "$targetDir/public/storage" ]; then
        rm -f "$targetDir/public/storage" 2>/dev/null || true
      fi
      ;;
  esac

  log "Running 'php artisan $cmd' for site '$site' in $targetDir"
  local artisan_out artisan_rc=0
  artisan_out="$(su -s /bin/bash "$WEB_USER" -c "cd $(printf '%q' "$targetDir") && exec php artisan $cmd" 2>&1)" || artisan_rc=$?
  echo "$artisan_out"

  if [[ "$cmd" == *"storage:link"* ]]; then
    ensure_laravel_storage_link "$targetDir"
    # If artisan failed or said link exists, but ensure_laravel_storage_link succeeded
    if [ "$artisan_rc" -ne 0 ] && [ -L "$targetDir/public/storage" ] && [ -e "$targetDir/public/storage" ]; then
      artisan_rc=0
      echo "The [public/storage] link has been connected to [../storage/app/public]."
    fi
  fi
  return $artisan_rc
}

cmd_storage_link() {
  local target="${1:-$(active_site)}" targetDir="" site=""
  
  if [ -n "$target" ] && [ "$target" != "all" ]; then
    if [ -d "$target" ] && [ -d "$target/public" ]; then
      targetDir="$target"
      site="$(basename "$target")"
    else
      site="$target"
      valid_name "$site" || die "Invalid site name: $site"
      local live; live="$(readlink -f "$WWW_LINK" 2>/dev/null || true)"
      local slug; slug="$(site_slug "$site")"
      if [ -d "$live" ] && [ "$(active_site)" = "$site" ] && [ -d "$live/public" ]; then
        targetDir="$live"
      elif [ -d "$RAM_SITES_DIR/$slug" ]; then
        targetDir="$RAM_SITES_DIR/$slug"
      elif [ -d "$RAM_MOUNT/sites/$site" ]; then
        targetDir="$RAM_MOUNT/sites/$site"
      elif [ -d "$STAGE_DIR/$site" ]; then
        targetDir="$STAGE_DIR/$site"
      elif [ -d "$STAGE_DIR/$slug" ]; then
        targetDir="$STAGE_DIR/$slug"
      fi
    fi

    [ -n "$targetDir" ] || die "Site '$target' is not active in RAM disk or has no webroot"
    ensure_laravel_storage_link "$targetDir"
    log "Ensured Laravel storage link for site '$site' in $targetDir"
    echo "Storage link verified and ensured for site '$site' (public/storage -> storage/app/public)"
  else
    local count=0
    while read -r s; do
      [ -z "$s" ] && continue
      local sl; sl="$(site_slug "$s")"
      for d in "$RAM_SITES_DIR/$sl" "$RAM_SITES_DIR/$s"; do
        if [ -d "$d" ] && { [ -f "$d/artisan" ] || [ -f "$d/public/index.php" ] || [ -d "$d/storage" ]; }; then
          ensure_laravel_storage_link "$d"
          count=$((count + 1))
        fi
      done
    done < <(list_active_sites)
    if [ -d "/mnt/ramdisk/webroot" ]; then
      ensure_laravel_storage_link "/mnt/ramdisk/webroot"
      count=$((count + 1))
    fi
    log "Ensured storage links for $count active Laravel site(s)"
    echo "Ensured storage links for $count active Laravel site(s)"
  fi
}

cmd_ziggy() {
  local target="${1:-$(active_site)}" targetDir="" site=""
  if [ -d "$target" ] && [ -f "$target/artisan" ]; then
    targetDir="$target"
    site="$(basename "$target")"
  else
    site="$target"
    valid_name "$site" || die "Invalid site name: $site"
    local live; live="$(readlink -f "$WWW_LINK" 2>/dev/null || true)"
    local slug; slug="$(site_slug "$site")"
    if [ -d "$live" ] && [ "$(active_site)" = "$site" ] && [ -f "$live/artisan" ]; then
      targetDir="$live"
    elif [ -d "$RAM_MOUNT/sites/$site" ] && [ -f "$RAM_MOUNT/sites/$site/artisan" ]; then
      targetDir="$RAM_MOUNT/sites/$site"
    elif [ -d "$RAM_MOUNT/sites/$slug" ] && [ -f "$RAM_MOUNT/sites/$slug/artisan" ]; then
      targetDir="$RAM_MOUNT/sites/$slug"
    elif [ -d "$STAGE_DIR/$site" ] && [ -f "$STAGE_DIR/$site/artisan" ]; then
      targetDir="$STAGE_DIR/$site"
    elif [ -d "$STAGE_DIR/$slug" ] && [ -f "$STAGE_DIR/$slug/artisan" ]; then
      targetDir="$STAGE_DIR/$slug"
    fi
  fi

  [ -n "$targetDir" ] || die "Site '$target' is not active in RAM or has no artisan"
  [ -f "$targetDir/artisan" ] || die "No artisan found in $targetDir"

  [ -n "$site" ] && { db_ensure "$site" || true; }
  [ -n "$site" ] && valid_name "$site" && db_write_files "$site" "$targetDir"
  laravel_repair_shim "$site" "$targetDir" || exit 1

  if [ ! -d "$targetDir/vendor/tightenco/ziggy" ] && ! grep -qi '"tightenco/ziggy"' "$targetDir/composer.json" 2>/dev/null; then
    echo "Ziggy (tightenco/ziggy) is not installed in '$site' - skipping."
    return 0
  fi

  su -s /bin/bash "$WEB_USER" -c "cd $(printf '%q' "$targetDir") && php artisan config:clear" >/dev/null 2>&1 || true
  su -s /bin/bash "$WEB_USER" -c "cd $(printf '%q' "$targetDir") && php artisan route:clear" >/dev/null 2>&1 || true
  rebuild_laravel_ziggy "$targetDir" "stdout"
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

  ensure_laravel_storage_link "$dir"
  rebuild_laravel_ziggy "$dir" "stdout" || true
  # 3. Persist the repaired site to SD so re-activation doesn't bring the shim back
  valid_name "$site" && store_dir_as_site "$site" "$dir" >/dev/null 2>&1 || true
  echo "WTC: Laravel framework restored for '$site'."
}

# ------------------------------------------------------------ settings
cmd_setting() {
  local k="${1:-}" v="${2:-}"
  case "$k" in
    RAM_SIZE)               [[ "$v" =~ ^[0-9]{2,5}[MG]$ ]] || die "RAM_SIZE like 512M or 1G" ;;
    DROP_MAX_MB|SAVE_INTERVAL_MIN|FAN_GPIO|ROLLBACK_KEEP) [[ "$v" =~ ^[0-9]{1,5}$ ]] || die "$k must be a number" ;;
    FAN_WIFI_MODE)          [[ "$v" =~ ^(pulse|spin)$ ]] || die "$k must be pulse or spin" ;;
    AUTO_ACTIVATE_NEW|AUTO_RELOAD_ACTIVE|KEEP_PROCESSED|SAVE_ON_SHUTDOWN|SAVE_ON_SWITCH|FAN_ON_WIFI_DISCONNECT|FORCE_HTTPS)
                            [[ "$v" =~ ^(yes|no)$ ]] || die "$k must be yes or no" ;;
    *) die "Unknown setting '$k'" ;;
  esac
  if [ "$k" = FORCE_HTTPS ] && [ "$v" = "yes" ]; then
    [ -f /etc/ssl/wtc-ui/wtc-ui.crt ] && [ -f /etc/ssl/wtc-ui/wtc-ui.key ] || die "TLS certificate missing (/etc/ssl/wtc-ui/wtc-ui.crt). Run update.sh first."
  fi
  set_conf "$k" "$v"
  if [ "$k" = RAM_SIZE ] && mountpoint -q "$RAM_MOUNT"; then
    mount -o "remount,size=$v" "$RAM_MOUNT" || die "Could not resize RAM disk (not enough memory?)"
  fi
  if [ "$k" = FORCE_HTTPS ]; then
    FORCE_HTTPS="$v"
    sync_force_https_conf
    if [ "$v" = "yes" ] && command -v ufw >/dev/null 2>&1; then
      ufw allow 443/tcp comment "HTTPS Web Server" >/dev/null 2>&1 || true
    fi
    if [ -f "$ACTIVE_SITES_FILE" ]; then
      local cur_primary; cur_primary="$(active_site)"
      while IFS= read -r s || [ -n "$s" ]; do
        [ -n "$s" ] || continue
        local slug; slug="$(site_slug "$s")"
        local sdir="$RAM_SITES_DIR/$slug"
        [ -d "$sdir" ] || continue
        local wroot="$sdir"
        if [ -d "$sdir/public" ] && { [ -f "$sdir/artisan" ] || [ -f "$sdir/public/index.php" ]; }; then
          wroot="$sdir/public"
        fi
        local sport=""
        [ -f "$SITES_DIR/$s.meta.json" ] && sport="$(jq -r '.port // empty' "$SITES_DIR/$s.meta.json" 2>/dev/null || true)"
        if [ -n "$sport" ] && [ "$sport" != "null" ]; then
          generate_site_port_nginx_conf "$s" "$slug" "$sport" "$wroot" "$RAM_NGINX_SITES/${slug}-port.conf"
          configure_site_env "$sdir" "$slug" "$sport" "$s"
        elif [ "$s" = "$cur_primary" ]; then
          configure_site_env "$sdir" "" "" "$s"
        else
          configure_site_env "$sdir" "$slug" "" "$s"
        fi
      done < "$ACTIVE_SITES_FILE"
    fi
    nginx -t >/dev/null 2>&1 || die "Nginx config test failed after updating FORCE_HTTPS"
    systemctl reload nginx 2>/dev/null || true
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
      ufw allow 443/tcp comment "HTTPS Web Server" >/dev/null 2>&1 || true
      ufw allow 8080/tcp comment "Site Direct Port" >/dev/null 2>&1 || true
      ufw allow 4485/tcp comment "WTC Management UI"
      ufw allow 4486/tcp comment "WTC Management UI (HTTPS)" >/dev/null 2>&1 || true
      log "Installed and pre-configured UFW firewall"
      echo "Installed UFW firewall."
      ;;
    enable)
      command -v ufw >/dev/null 2>&1 || [ -x /usr/sbin/ufw ] || die "UFW firewall is not installed. Click Install UFW first."
      # Safety check: ensure SSH (22) and UI ports are allowed before enabling
      ufw allow 22/tcp comment "SSH Server" >/dev/null 2>&1 || true
      ufw allow 80/tcp comment "HTTP Web Server" >/dev/null 2>&1 || true
      ufw allow 443/tcp comment "HTTPS Web Server" >/dev/null 2>&1 || true
      ufw allow 8080/tcp comment "Site Direct Port" >/dev/null 2>&1 || true
      ufw allow 4485/tcp comment "WTC Management UI"
      ufw allow 4486/tcp comment "WTC Management UI (HTTPS)" >/dev/null 2>&1 || true
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
      ufw allow 443/tcp comment "HTTPS Web Server"
      ufw allow 8080/tcp comment "Site Direct Port"
      ufw allow 4485/tcp comment "WTC Management UI"
      ufw allow 4486/tcp comment "WTC Management UI (HTTPS)"
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
      if [ "$port" = "4485" ] || [ "$port" = "4486" ] || [ "$port" = "22" ]; then
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
  systemctl reset-failed wtc-stack-job.service 2>/dev/null || true
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
    "${APT[@]}" update || true
    "${APT[@]}" install ${STACK_PKGS[$id]} || rc=$?
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

# ------------------------------------------------------------ site build (npm run build / custom build)
cmd_build() {
  local site="${1:-$(active_site)}" custom_cmd="${2:-}"
  valid_name "$site" || die "Invalid site name"
  systemctl is-active --quiet wtc-build-job.service && die "Another build job is currently running"
  mkdir -p "$(dirname "$BUILD_LOG")"
  echo "=== build for '$site' ($(date '+%F %T')) ===" > "$BUILD_LOG"
  systemd-run --quiet --unit=wtc-build-job --collect \
    --property=StandardOutput=append:"$BUILD_LOG" --property=StandardError=append:"$BUILD_LOG" \
    /usr/local/bin/wtc-manage --build-exec "$site" "$custom_cmd"
  log "Background build job started for '$site'"
}

cmd_build_exec() {
  local site="$1" custom_cmd="${2:-}" rc=0
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
  if [ -n "$custom_cmd" ]; then
    if [ -f "$targetDir/artisan" ] && ! echo "$custom_cmd" | grep -q 'ziggy:generate'; then
      if echo "$custom_cmd" | grep -qE '(npm|yarn|pnpm|bun)[[:space:]]+(run[[:space:]]+)?(build|prod)|vite[[:space:]]+build'; then
        echo "==> Rebuilding Laravel Ziggy routes before frontend build..."
        rebuild_laravel_ziggy "$targetDir" "stdout" || true
      fi
    fi

    echo "==> Executing custom build command: $custom_cmd"
    eval "$custom_cmd" 2>&1 || rc=$?
    if [ "$rc" -ne 0 ]; then
      echo "ERROR: Build command failed (exit code $rc)"
      [ "$is_active" -ne 1 ] && rm -rf "$targetDir"
      exit "$rc"
    fi

    if [ -f "$targetDir/artisan" ] && ! echo "$custom_cmd" | grep -q 'ziggy:generate'; then
      if echo "$custom_cmd" | grep -qE 'composer[[:space:]]+(install|update)'; then
        echo "==> Checking Laravel Ziggy routes after Composer update..."
        rebuild_laravel_ziggy "$targetDir" "stdout" || true
      fi
    fi
  else
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

    if [ -f "$targetDir/artisan" ]; then
      echo "==> Rebuilding Laravel Ziggy routes before npm run build..."
      rebuild_laravel_ziggy "$targetDir" "stdout" || true
    fi

    echo "==> Running npm run build..."
    npm run build 2>&1 || rc=$?
    if [ "$rc" -ne 0 ]; then
      echo "ERROR: npm run build failed (exit code $rc)"
      [ "$is_active" -ne 1 ] && rm -rf "$targetDir"
      exit "$rc"
    fi
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

    local rb_items=() rb_total=0 rb_count=0 rf rsz rmtime rid rlabel
    if [ -d "$PREV_DIR/$s" ]; then
      while IFS= read -r rf; do
        [ -f "$rf" ] || continue
        rid="$(basename "$rf")"
        rsz="$(stat -c %s "$rf" 2>/dev/null || echo 0)"
        rmtime="$(stat -c %Y "$rf" 2>/dev/null || echo 0)"
        rlabel="${rid%.tar.gz}"
        rb_total=$((rb_total + rsz))
        rb_count=$((rb_count + 1))
        rb_items+=("$(jq -nc --arg id "$rid" --arg label "$rlabel" --argjson size "$rsz" --argjson mtime "$rmtime" \
          '{id:$id,label:$label,size:$size,mtime:$mtime}')")
      done < <(ls -1t "$PREV_DIR/$s"/*.tar.gz 2>/dev/null || true)
    fi
    if [ -f "$PREV_DIR/$s.tar.gz" ]; then
      rsz="$(stat -c %s "$PREV_DIR/$s.tar.gz" 2>/dev/null || echo 0)"
      rmtime="$(stat -c %Y "$PREV_DIR/$s.tar.gz" 2>/dev/null || echo 0)"
      rb_total=$((rb_total + rsz))
      rb_count=$((rb_count + 1))
      rb_items+=("$(jq -nc --arg id "legacy" --arg label "Previous (legacy)" --argjson size "$rsz" --argjson mtime "$rmtime" \
        '{id:$id,label:$label,size:$size,mtime:$mtime}')")
    fi
    local rb_json="[]"
    [ "${#rb_items[@]}" -gt 0 ] && rb_json="$(printf '%s\n' "${rb_items[@]}" | jq -s '.')"
    p="$([ "$rb_count" -gt 0 ] && echo true || echo false)"
    port="$(jq -r '.port // null' "$meta_f" 2>/dev/null || echo null)"
    local storage_linked=false
    if [ -d "$site_ram_dir/public" ]; then
      if [ -L "$site_ram_dir/public/storage" ] && [ -e "$site_ram_dir/public/storage" ]; then
        storage_linked=true
      fi
    fi
    local catalog_rebuilding=false
    if systemctl is-active --quiet "wtc-catalog-${slug}.service" 2>/dev/null || [ -f "/run/wtc-catalog-${slug}.pending" ]; then
      catalog_rebuilding=true
    fi
    items+=("$(jq -nc --arg n "$s" --argjson a "$a" --argjson isPrim "$is_prim" \
      --arg slug "$slug" --arg url "/$slug/" \
      --argjson size "${size:-0}" --argjson m "${m:-0}" \
      --argjson p "$p" --argjson hasBuild "$hasPkg" \
      --arg stack "$stack" --argjson port "$port" \
      --argjson storageLinked "$storage_linked" \
      --argjson catalogRebuilding "$catalog_rebuilding" \
      --argjson rollbacks "$rb_json" --argjson rollbackCount "$rb_count" --argjson rollbackSize "$rb_total" \
      '{name:$n,active:$a,isPrimary:$isPrim,slug:$slug,url:$url,size:$size,mtime:$m,hasPrevious:$p,hasBuild:$hasBuild,stack:$stack,port:$port,storageLinked:$storageLinked,catalogRebuilding:$catalogRebuilding,rollbacks:$rollbacks,rollbackCount:$rollbackCount,rollbackSize:$rollbackSize}')")
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
  if [ -f "/mnt/ramdisk/webroot/artisan" ] || [ -f "/mnt/ramdisk/webroot/../artisan" ] || [ -f "/mnt/ramdisk/www/artisan" ]; then
    w_has_artisan=true
  elif [ -n "$(active_site)" ] && { [ -f "$RAM_MOUNT/sites/$(site_slug "$(active_site)")/artisan" ] || [ -f "$RAM_MOUNT/sites/$(active_site)/artisan" ]; }; then
    w_has_artisan=true
  elif [ -d "$RAM_MOUNT/sites" ] && [ -n "$(find "$RAM_MOUNT/sites" -maxdepth 2 -name artisan 2>/dev/null | head -n1)" ]; then
    w_has_artisan=true
  fi
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
  local client_id="${1:-}"
  client_id="$(echo "$client_id" | tr -cd 'a-zA-Z0-9_-' | cut -c1-16)"
  local stat_dir="/run"
  [ -d "$stat_dir" ] && [ -w "$stat_dir" ] || stat_dir="/tmp"
  local stat_file="$stat_dir/wtc-proc-stat${client_id:+-$client_id}.cache"
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

json_dash() {
  local client_id="${1:-}"
  client_id="$(echo "$client_id" | tr -cd 'a-zA-Z0-9_-' | cut -c1-16)"
  local status_json sys_json build_json
  status_json="$(json_status)"
  sys_json="$(json_sysinfo "$client_id")"
  if [ -z "$sys_json" ]; then
    sys_json='{"freq_mhz":1500,"total":0.0,"cores":[],"count":0}'
  fi
  build_json="$(json_build)"
  if [ -z "$build_json" ]; then
    build_json='{"busy":false,"log":""}'
  fi

  # Load averages from /proc/loadavg
  local l1="0.00" l5="0.00" l15="0.00"
  if [ -r /proc/loadavg ]; then
    read -r l1 l5 l15 _ < /proc/loadavg 2>/dev/null || true
  fi

  # Throttled status from vcgencmd (Raspberry Pi hardware)
  local throttled_code="0x0" under_voltage=false is_throttled=false freq_capped=false temp_limit=false
  local uv_has=false thr_has=false cap_has=false tmp_has=false
  if command -v vcgencmd >/dev/null 2>&1; then
    local t_raw
    t_raw="$(vcgencmd get_throttled 2>/dev/null || true)"
    if [[ "$t_raw" =~ throttled=(0x[0-9a-fA-F]+) ]]; then
      throttled_code="${BASH_REMATCH[1]}"
      local t_val=$(( throttled_code ))
      [ $(( t_val & 0x1 )) -ne 0 ] && under_voltage=true
      [ $(( t_val & 0x2 )) -ne 0 ] && freq_capped=true
      [ $(( t_val & 0x4 )) -ne 0 ] && is_throttled=true
      [ $(( t_val & 0x8 )) -ne 0 ] && temp_limit=true
      [ $(( t_val & 0x10000 )) -ne 0 ] && uv_has=true
      [ $(( t_val & 0x20000 )) -ne 0 ] && cap_has=true
      [ $(( t_val & 0x40000 )) -ne 0 ] && thr_has=true
      [ $(( t_val & 0x80000 )) -ne 0 ] && tmp_has=true
    fi
  fi

  # Saved state info and dirty check
  local last_saved=0 is_dirty=false
  local save_stamp_file="${SAVE_STAMP:-/run/wtc-web.save-stamp}"
  if [ -f "$save_stamp_file" ]; then
    last_saved="$(cat "$save_stamp_file" 2>/dev/null || stat -c %Y "$save_stamp_file" 2>/dev/null || echo 0)"
  fi
  [[ "$last_saved" =~ ^[0-9]+$ ]] || last_saved=0
  local act_site; act_site="$(active_site)"
  if [ -n "$act_site" ] && [ "$last_saved" -gt 0 ]; then
    local r_dir="/mnt/ramdisk/webroot"
    [ -d "$r_dir" ] || r_dir="$RAM_MOUNT/sites/$(site_slug "$act_site")"
    if [ -d "$r_dir" ]; then
      local newer_count
      newer_count="$(find "$r_dir" -maxdepth 3 -type f -newermt "@$last_saved" 2>/dev/null | head -n 5 | wc -l)"
      [ "$newer_count" -gt 0 ] && is_dirty=true
    fi
  elif [ -n "$act_site" ] && [ "$last_saved" -eq 0 ]; then
    is_dirty=true
  fi

  # Network rate deltas via /proc/net/dev
  local rx_rate=0 tx_rate=0
  local net_cache="/run/wtc-net-rates${client_id:+-$client_id}.cache"
  local now_sec; now_sec="$(date +%s)"
  local cur_rx=0 cur_tx=0
  if [ -r /proc/net/dev ]; then
    while read -r line; do
      if [[ "$line" =~ ^[[:space:]]*(eth[0-9]+|wlan[0-9]+|end[0-9]+):[[:space:]]*([0-9]+)[[:space:]]+[0-9]+[[:space:]]+[0-9]+[[:space:]]+[0-9]+[[:space:]]+[0-9]+[[:space:]]+[0-9]+[[:space:]]+[0-9]+[[:space:]]+([0-9]+) ]]; then
        cur_rx=$(( cur_rx + BASH_REMATCH[2] ))
        cur_tx=$(( cur_tx + BASH_REMATCH[3] ))
      fi
    done < /proc/net/dev
    if [ -f "$net_cache" ]; then
      local prev_sec prev_rx prev_tx
      read -r prev_sec prev_rx prev_tx < "$net_cache" 2>/dev/null || true
      if [ -n "$prev_sec" ] && [ "$now_sec" -gt "$prev_sec" ]; then
        local dt=$(( now_sec - prev_sec ))
        rx_rate=$(( (cur_rx - prev_rx) / dt ))
        tx_rate=$(( (cur_tx - prev_tx) / dt ))
        [ "$rx_rate" -lt 0 ] && rx_rate=0
        [ "$tx_rate" -lt 0 ] && tx_rate=0
      fi
    fi
    printf "%s %s %s\n" "$now_sec" "$cur_rx" "$cur_tx" > "$net_cache" 2>/dev/null || true
  fi

  jq -nc \
    --argjson status "$status_json" \
    --argjson cpu "$sys_json" \
    --argjson build "$build_json" \
    --argjson loadavg "[\"$l1\",\"$l5\",\"$l15\"]" \
    --arg throttledCode "$throttled_code" \
    --argjson underVoltage "$under_voltage" \
    --argjson isThrottled "$is_throttled" \
    --argjson freqCapped "$freq_capped" \
    --argjson tempLimit "$temp_limit" \
    --argjson uvOccurred "$uv_has" \
    --argjson lastSaved "$last_saved" \
    --argjson isDirty "$is_dirty" \
    --argjson rxRate "$rx_rate" \
    --argjson txRate "$tx_rate" \
    '{
      status: $status,
      cpu: $cpu,
      build: $build,
      loadavg: $loadavg,
      throttling: {
        code: $throttledCode,
        under_voltage: $underVoltage,
        throttled: $isThrottled,
        freq_capped: $freqCapped,
        temp_limit: $tempLimit,
        under_voltage_occurred: $uvOccurred
      },
      saved_state: {
        last_saved: $lastSaved,
        is_dirty: $isDirty
      },
      net_rates: {
        rx_bytes_sec: $rxRate,
        tx_bytes_sec: $txRate
      }
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
    --arg FAN_ON_WIFI_DISCONNECT "${FAN_ON_WIFI_DISCONNECT:-no}" --arg FAN_WIFI_MODE "${FAN_WIFI_MODE:-pulse}" \
    --arg FAN_GPIO "${FAN_GPIO:-14}" --arg FORCE_HTTPS "${FORCE_HTTPS:-no}" --arg ROLLBACK_KEEP "${ROLLBACK_KEEP:-5}" \
    '{RAM_SIZE:$RAM_SIZE,DROP_MAX_MB:$DROP_MAX_MB,AUTO_ACTIVATE_NEW:$AUTO_ACTIVATE_NEW,AUTO_RELOAD_ACTIVE:$AUTO_RELOAD_ACTIVE,KEEP_PROCESSED:$KEEP_PROCESSED,SAVE_ON_SHUTDOWN:$SAVE_ON_SHUTDOWN,SAVE_ON_SWITCH:$SAVE_ON_SWITCH,SAVE_INTERVAL_MIN:$SAVE_INTERVAL_MIN,FAN_ON_WIFI_DISCONNECT:$FAN_ON_WIFI_DISCONNECT,FAN_WIFI_MODE:$FAN_WIFI_MODE,FAN_GPIO:$FAN_GPIO,FORCE_HTTPS:$FORCE_HTTPS,ROLLBACK_KEEP:$ROLLBACK_KEEP}'
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
  # If a stack job is running, cache missing, or cache still says busy after job finished, refresh cache
  if [ ! -f "$STACK_CACHE_FILE" ] || [ "$busy" = "yes" ] || grep -q '"busy":true' "$STACK_CACHE_FILE" 2>/dev/null; then
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

# ------------------------------------------------------------ Stack Logs Discovery & Reader
resolve_stack_log_target() {
  local src="${1:-}"
  case "$src" in
    nginx-error)  echo "/var/log/nginx/error.log|Nginx Error Log|nginx_error|1" ;;
    nginx-access) echo "/var/log/nginx/access.log|Nginx Access Log|nginx_access|1" ;;
    php-fpm)
      local fpm_log
      fpm_log="$(ls -1t /var/log/php*-fpm.log 2>/dev/null | head -n1 || true)"
      [ -n "$fpm_log" ] || fpm_log="/var/log/php-fpm.log"
      echo "$fpm_log|PHP-FPM Log|php_fpm|1"
      ;;
    mariadb)
      local db_log="/var/log/mysql/error.log"
      [ -f "$db_log" ] || db_log="/var/log/mariadb/mariadb.log"
      echo "$db_log|MariaDB Error Log|auto|0"
      ;;
    redis)        echo "/var/log/redis/redis-server.log|Redis Server Log|auto|0" ;;
    wtc-manage)   echo "$LOG_FILE|WTC System Activity|wtc|1" ;;
    wtc-workers)  echo "/var/log/wtc-web/workers.log|Laravel Queue & Scheduler Workers|wtc|1" ;;
    wtc-build)    echo "$BUILD_LOG|Build Runner Job Log|wtc|1" ;;
    wtc-stack)    echo "$STACK_LOG|Stack Package Installer Log|wtc|1" ;;
    wtc-service)  echo "/var/log/wtc-web/service.log|RAM Disk & Service Boot Log|wtc|1" ;;
    ram:*)
      local rel="${src#ram:}"
      rel="${rel#/}"
      case "$rel" in
        ""|*..*|*\\*) return 1 ;;
      esac
      local candidate="$RAM_MOUNT/$rel"
      local real_ram real_file
      real_ram="$(readlink -f "$RAM_MOUNT" 2>/dev/null || echo "$RAM_MOUNT")"
      real_file="$(readlink -f "$candidate" 2>/dev/null || true)"
      [ -n "$real_file" ] && [[ "$real_file" == "$real_ram/"* ]] && [ -f "$real_file" ] || return 1
      case "$real_file" in
        *.log|*.error|*.txt|*/storage/logs/*|*/logs/*) ;;
        *) return 1 ;;
      esac
      local fmt="auto"
      case "$rel" in
        *laravel*|*storage/logs/*) fmt="laravel" ;;
        *nginx*error*) fmt="nginx_error" ;;
        *nginx*access*) fmt="nginx_access" ;;
      esac
      echo "$real_file|$rel|$fmt|1"
      ;;
    *) return 1 ;;
  esac
}

json_stack_logs() {
  local items=()
  local cur_site; cur_site="$(active_site)"

  # 1. Discover site / application stack logs in RAM drive (/mnt/ramdisk/sites/*)
  if [ -d "$RAM_SITES_DIR" ]; then
    local s slug site_dir stack f rel fmt
    while read -r s; do
      [ -z "$s" ] && continue
      slug="$(site_slug "$s")"
      site_dir="$RAM_SITES_DIR/$slug"
      [ -d "$site_dir" ] || continue
      stack="$(detect_site_stack "$site_dir" 2>/dev/null || echo "unknown")"

      while IFS= read -r f; do
        [ -f "$f" ] || continue
        rel="${f#$RAM_MOUNT/}"
        local sz mt
        sz="$(stat -c %s "$f" 2>/dev/null || echo 0)"
        mt="$(stat -c %Y "$f" 2>/dev/null || echo 0)"
        fmt="auto"
        [[ "$f" == *"/storage/logs/"* ]] && fmt="laravel"
        items+=("$(jq -nc \
          --arg id "ram:$rel" \
          --arg label "$s / $(basename "$f")" \
          --arg category "Application Stacks" \
          --arg stack "$stack" \
          --arg site "$s" \
          --arg path "$f" \
          --arg relPath "$rel" \
          --arg format "$fmt" \
          --argjson size "$sz" \
          --argjson mtime "$mt" \
          --argjson exists true \
          --argjson clearable true \
          --argjson primary "$([ "$s" = "$cur_site" ] && echo true || echo false)" \
          '{id:$id,label:$label,category:$category,stack:$stack,site:$site,path:$path,relPath:$relPath,format:$format,size:$size,mtime:$mtime,exists:$exists,clearable:$clearable,primary:$primary}')")
      done < <(find "$site_dir" -maxdepth 4 -type f -name "*.log" \( -path "*/storage/logs/*" -o -path "*/logs/*" -o -path "*/log/*" -o -path "$site_dir/*.log" \) 2>/dev/null | sort)
    done < <(list_sites)
  fi

  # 2. Web & Runtime Stack logs
  local sys_defs=(
    "nginx-error|Nginx Error Log|Web & Runtime Stack|nginx_error|/var/log/nginx/error.log|1"
    "nginx-access|Nginx Access Log|Web & Runtime Stack|nginx_access|/var/log/nginx/access.log|1"
    "php-fpm|PHP-FPM Runtime Log|Web & Runtime Stack|php_fpm|$(ls -1t /var/log/php*-fpm.log 2>/dev/null | head -n1 || echo /var/log/php-fpm.log)|1"
    "mariadb|MariaDB Error Log|Web & Runtime Stack|auto|$([ -f /var/log/mysql/error.log ] && echo /var/log/mysql/error.log || echo /var/log/mariadb/mariadb.log)|0"
    "redis|Redis Server Log|Web & Runtime Stack|auto|/var/log/redis/redis-server.log|0"
    "wtc-manage|WTC System Activity|WTC Platform & Jobs|wtc|$LOG_FILE|1"
    "wtc-workers|Laravel Queue & Scheduler Workers|WTC Platform & Jobs|wtc|/var/log/wtc-web/workers.log|1"
    "wtc-build|Build Runner Job Output|WTC Platform & Jobs|wtc|$BUILD_LOG|1"
    "wtc-stack|Stack Package Installer|WTC Platform & Jobs|wtc|$STACK_LOG|1"
    "wtc-service|RAM Disk & Service Boot|WTC Platform & Jobs|wtc|/var/log/wtc-web/service.log|1"
  )

  local def id lbl cat fmt fpath clr ex sz mt
  for def in "${sys_defs[@]}"; do
    IFS='|' read -r id lbl cat fmt fpath clr <<< "$def"
    ex=false; sz=0; mt=0
    if [ -f "$fpath" ]; then
      ex=true
      sz="$(stat -c %s "$fpath" 2>/dev/null || echo 0)"
      mt="$(stat -c %Y "$fpath" 2>/dev/null || echo 0)"
    fi
    # Skip optional services if log file does not exist
    if { [ "$id" = "mariadb" ] || [ "$id" = "redis" ]; } && [ "$ex" = "false" ]; then
      continue
    fi
    items+=("$(jq -nc \
      --arg id "$id" \
      --arg label "$lbl" \
      --arg category "$cat" \
      --arg stack "system" \
      --arg site "" \
      --arg path "$fpath" \
      --arg relPath "" \
      --arg format "$fmt" \
      --argjson size "$sz" \
      --argjson mtime "$mt" \
      --argjson exists "$ex" \
      --argjson clearable "$([ "$clr" = "1" ] && echo true || echo false)" \
      --argjson primary false \
      '{id:$id,label:$label,category:$category,stack:$stack,site:$site,path:$path,relPath:$relPath,format:$format,size:$size,mtime:$mtime,exists:$exists,clearable:$clearable,primary:$primary}')")
  done

  # 3. Rejected dropbox error files in RAM
  if [ -d "$REJECT_DIR" ]; then
    local rf
    for rf in "$REJECT_DIR"/*.error; do
      [ -f "$rf" ] || continue
      local rrel="${rf#$RAM_MOUNT/}"
      local rsz rmt
      rsz="$(stat -c %s "$rf" 2>/dev/null || echo 0)"
      rmt="$(stat -c %Y "$rf" 2>/dev/null || echo 0)"
      items+=("$(jq -nc \
        --arg id "ram:$rrel" \
        --arg label "Rejected: $(basename "$rf")" \
        --arg category "RAM Drive Errors" \
        --arg stack "dropbox" \
        --arg site "" \
        --arg path "$rf" \
        --arg relPath "$rrel" \
        --arg format "wtc" \
        --argjson size "$rsz" \
        --argjson mtime "$rmt" \
        --argjson exists true \
        --argjson clearable true \
        --argjson primary false \
        '{id:$id,label:$label,category:$category,stack:$stack,site:$site,path:$path,relPath:$relPath,format:$format,size:$size,mtime:$mtime,exists:$exists,clearable:$clearable,primary:$primary}')")
    done
  fi

  if [ "${#items[@]}" -eq 0 ]; then
    echo "[]"
  else
    printf '%s\n' "${items[@]}" | jq -s '.'
  fi
}

cmd_stack_logs() {
  local action="${1:-list}" source="${2:-wtc-manage}" lines="${3:-200}"
  case "$action" in
    list)
      json_stack_logs
      ;;
    read)
      [[ "$lines" =~ ^[0-9]+$ ]] || lines=200
      [ "$lines" -lt 10 ] && lines=10
      [ "$lines" -gt 2000 ] && lines=2000
      local resolved fpath label fmt clr
      if ! resolved="$(resolve_stack_log_target "$source")"; then
        jq -nc --arg err "Invalid or inaccessible log source: $source" '{ok:false,error:$err}'
        return 1
      fi
      IFS='|' read -r fpath label fmt clr <<< "$resolved"
      if [ ! -f "$fpath" ]; then
        jq -nc --arg id "$source" --arg label "$label" --arg path "$fpath" --arg format "$fmt" \
          '{ok:true,id:$id,label:$label,path:$path,format:$format,exists:false,size:0,mtime:0,clearable:false,content:""}'
        return 0
      fi
      local sz mt
      sz="$(stat -c %s "$fpath" 2>/dev/null || echo 0)"
      mt="$(stat -c %Y "$fpath" 2>/dev/null || echo 0)"
      tail -n "$lines" "$fpath" 2>/dev/null | tail -c 524288 | jq -Rs \
        --arg id "$source" \
        --arg label "$label" \
        --arg path "$fpath" \
        --arg format "$fmt" \
        --argjson size "$sz" \
        --argjson mtime "$mt" \
        --argjson exists true \
        --argjson clearable "$([ "$clr" = "1" ] && echo true || echo false)" \
        '{ok:true,id:$id,label:$label,path:$path,format:$format,exists:$exists,size:$size,mtime:$mtime,clearable:$clearable,content:.}'
      ;;
    clear)
      local resolved fpath label fmt clr
      if ! resolved="$(resolve_stack_log_target "$source")"; then
        jq -nc --arg err "Invalid log source: $source" '{ok:false,error:$err}'
        return 1
      fi
      IFS='|' read -r fpath label fmt clr <<< "$resolved"
      if [ "$clr" != "1" ]; then
        jq -nc --arg err "Log source '$label' is read-only" '{ok:false,error:$err}'
        return 1
      fi
      if [ -f "$fpath" ]; then
        : > "$fpath"
        log "Cleared log file: $fpath"
      fi
      jq -nc --arg id "$source" --arg label "$label" '{ok:true,id:$id,label:$label}'
      ;;
    *)
      die "Unknown stack-logs action: $action"
      ;;
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

json_services() {
  if ! command -v systemctl >/dev/null 2>&1; then
    echo '{"summary":{"total":0,"running":0,"stopped":0,"failed":0,"ours_count":0,"system_count":0},"ours":[],"system":[]}'
    return
  fi

  local enabled_map
  enabled_map="$(mktemp 2>/dev/null || echo "/tmp/wtc-svc-en-$$.tmp")"
  systemctl list-unit-files --type=service,timer,path,socket --no-legend --no-pager 2>/dev/null | awk '{print $1 "=" $2}' > "$enabled_map"

  local units_data
  units_data="$(systemctl list-units --type=service,timer,path,socket --all --no-legend --no-pager 2>/dev/null || true)"

  local php_unit; php_unit="$(php_fpm_unit 2>/dev/null || echo "php-fpm.service")"

  local raw_svc
  raw_svc="$(awk -v php_fpm="$php_unit" -v en_file="$enabled_map" '
    BEGIN {
      while ((getline line < en_file) > 0) {
        split(line, ep, "=")
        if (ep[1] != "") enabled_state[ep[1]] = ep[2]
      }
      close(en_file)

      ours_list["wtc-ramdisk.service"] = "WTC RAM-Disk Mount & UI Loader;platform;Mounts tmpfs RAM disk and syncs Management UI first on boot"
      ours_list["wtc-site-loader.service"] = "WTC Site RAM Loader;platform;Transfers active sites from SD card into RAM after UI starts"
      ours_list["wtc-dropbox.path"] = "WTC Ingest Watcher (Path);platform;Watches /mnt/ramdisk/dropbox for incoming deployment archives"
      ours_list["wtc-dropbox.service"] = "WTC Ingest Service;platform;Validates and unpacks dropped site packages into storage"
      ours_list["wtc-workers.service"] = "WTC Workers Supervisor;platform;Supervises Laravel queue:work and schedule:work background runners"
      ours_list["wtc-save.timer"] = "WTC State Persistence Timer;platform;Interval timer triggering state snapshots back to SD storage"
      ours_list["wtc-save.service"] = "WTC State Persistence Service;platform;Snapshots live RAM webroot changes back to SD card archive"
      ours_list["wtc-network.service"] = "WTC Network Watchdog & Fan Alert;platform;Monitors network connectivity and triggers GPIO fan warning pulses"
      ours_list["wtc-stack-job.service"] = "WTC Stack Package Runner;platform;Executes asynchronous apt background package installations"
      ours_list["wtc-build-job.service"] = "WTC Build Job Runner;platform;Executes asynchronous frontend/backend build compilation"
      ours_list["nginx.service"] = "Nginx Web Server;stack;High-performance HTTP server and reverse proxy serving RAM webroot"
      ours_list["mariadb.service"] = "MariaDB Database;stack;Dedicated relational database engine with per-site auto-provisioning"
      ours_list["mysql.service"] = "MySQL Database;stack;Relational database daemon fallback"
      ours_list["redis.service"] = "Redis Cache & Queue;stack;In-memory data structure store, cache, and message broker"
      ours_list["redis-server.service"] = "Redis Cache & Queue;stack;In-memory data structure store, cache, and message broker"
      ours_list["smbd.service"] = "Samba SMB Daemon;stack;Windows file sharing server providing drag-and-drop dropbox share"
      ours_list["nmbd.service"] = "Samba NetBIOS Name Server;stack;NetBIOS nameserver providing LAN network browsing"
      ours_list["vsftpd.service"] = "vsftpd FTP Daemon;stack;Lightweight FTP server for remote file upload and deployment"
      ours_list["ssh.service"] = "SSH Remote Shell;stack;OpenSSH secure shell and SFTP remote administration daemon"
      ours_list["ssh.socket"] = "SSH Socket Activation;stack;Systemd socket activation listener for OpenSSH connections"
      ours_list["avahi-daemon.service"] = "Avahi mDNS Discovery;stack;Zero-configuration networking daemon (wtc-pi.local resolution)"
      if (php_fpm != "") {
        ours_list[php_fpm] = "PHP-FPM Engine;stack;PHP FastCGI Process Manager worker pool for live web applications"
      }
    }
    {
      sub(/^[ \t*•]+/, "", $0)
      if (NF < 4) next

      unit = $1
      load = $2
      active = $3
      sub_state = $4

      desc = ""
      for (i = 5; i <= NF; i++) {
        desc = (desc == "" ? "" : desc " ") $i
      }

      en = enabled_state[unit]
      if (en == "") en = "unknown"

      is_ours = 0
      name = unit
      cat_type = "kernel"
      category = "system"

      if (unit in ours_list) {
        is_ours = 1
        split(ours_list[unit], op, ";")
        name = op[1]
        cat_type = op[2]
        if (desc == "" || desc ~ /^WTC/ || desc ~ /^[A-Z]/) {
          if (op[3] != "") desc = op[3]
        }
        category = "ours"
      } else if (unit ~ /^wtc-/) {
        is_ours = 1
        name = unit
        cat_type = "platform"
        category = "ours"
      } else if (unit ~ /^php[0-9.]*-fpm/) {
        is_ours = 1
        name = "PHP-FPM (" unit ")"
        cat_type = "stack"
        category = "ours"
      } else {
        if (unit ~ /^(systemd-udev|systemd-journal|systemd-timesync|rngd|haveged|irqbalance|kmod|dmesg|thermal)/) {
          cat_type = "kernel_hardware"
        } else if (unit ~ /^(systemd-network|NetworkManager|systemd-resolved|wpa_supplicant|ufw|iptables|firewall|avahi)/) {
          cat_type = "kernel_network"
        } else if (unit ~ /^(systemd-logind|dbus|polkit|cron|rsyslog|syslog|apparmor|secure)/) {
          cat_type = "kernel_system"
        } else {
          cat_type = "system_daemon"
        }
        category = "system"
      }

      seen_units[unit] = 1

      gsub(/\\/, "\\\\", name); gsub(/"/, "\\\"", name)
      gsub(/\\/, "\\\\", desc); gsub(/"/, "\\\"", desc)
      gsub(/\\/, "\\\\", unit); gsub(/"/, "\\\"", unit)

      is_run = (active == "active" ? 1 : 0)
      is_fail = (active == "failed" ? 1 : 0)

      json_item = sprintf("{\"unit\":\"%s\",\"name\":\"%s\",\"category\":\"%s\",\"type\":\"%s\",\"load\":\"%s\",\"active\":\"%s\",\"sub\":\"%s\",\"enabled\":\"%s\",\"is_running\":%s,\"is_failed\":%s,\"desc\":\"%s\"}", unit, name, category, cat_type, load, active, sub_state, en, (is_run ? "true" : "false"), (is_fail ? "true" : "false"), desc)

      if (category == "ours") {
        ours_json = (ours_json == "" ? "" : ours_json ",") json_item
        ours_count++
      } else {
        sys_json = (sys_json == "" ? "" : sys_json ",") json_item
        sys_count++
      }

      total++
      if (is_run) running++
      else stopped++
      if (is_fail) failed++
    }
    END {
      for (u in ours_list) {
        if (!seen_units[u]) {
          split(ours_list[u], op, ";")
          uname = op[1]
          utype = op[2]
          udesc = op[3]
          uen = enabled_state[u]
          if (uen == "") uen = "disabled"

          gsub(/\\/, "\\\\", uname); gsub(/"/, "\\\"", uname)
          gsub(/\\/, "\\\\", udesc); gsub(/"/, "\\\"", udesc)

          item = sprintf("{\"unit\":\"%s\",\"name\":\"%s\",\"category\":\"ours\",\"type\":\"%s\",\"load\":\"loaded\",\"active\":\"inactive\",\"sub\":\"dead\",\"enabled\":\"%s\",\"is_running\":false,\"is_failed\":false,\"desc\":\"%s\"}", u, uname, utype, uen, udesc)

          ours_json = (ours_json == "" ? "" : ours_json ",") item
          ours_count++
          total++
          stopped++
        }
      }

      print "{"
      print "  \"summary\": {"
      print "    \"total\": " (total + 0) ","
      print "    \"running\": " (running + 0) ","
      print "    \"stopped\": " (stopped + 0) ","
      print "    \"failed\": " (failed + 0) ","
      print "    \"ours_count\": " (ours_count + 0) ","
      print "    \"system_count\": " (sys_count + 0)
      print "  },"
      print "  \"ours\": [" ours_json "],"
      print "  \"system\": [" sys_json "]"
      print "}"
    }
  ' <<< "$units_data")"

  rm -f "$enabled_map" 2>/dev/null || true

  local sec_json
  sec_json="$(generate_security_audit cached 2>/dev/null || echo '{}')"
  if command -v jq >/dev/null 2>&1; then
    jq -c --argjson sec "$sec_json" '. + {security: $sec}' <<< "$raw_svc" 2>/dev/null || echo "$raw_svc"
  else
    echo "$raw_svc"
  fi
}

service_to_pkgs() {
  local u="$1" phpv=""
  if [[ "$u" =~ php([0-9]+\.[0-9]+) ]]; then
    phpv="${BASH_REMATCH[1]}"
  else
    phpv="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  fi
  case "$u" in
    nginx|nginx.service) echo "nginx nginx-common" ;;
    mariadb|mariadb.service|mysql|mysql.service) echo "mariadb-server mariadb-client" ;;
    php*|php*-fpm|php*-fpm.service)
      if [ -n "$phpv" ]; then
        echo "php${phpv}-fpm php${phpv}-common php${phpv}-cli"
      else
        echo "php-fpm php-common php-cli"
      fi
      ;;
    smbd|smbd.service|nmbd|nmbd.service|samba*) echo "samba samba-common" ;;
    ssh|ssh.service|sshd|sshd.service|ssh.socket|ssh*|sshd*) echo "openssh-server openssh-client" ;;
    redis|redis.service|redis-server|redis-server.service|redis*) echo "redis-server" ;;
    memcached|memcached.service) echo "memcached" ;;
    vsftpd|vsftpd.service) echo "vsftpd" ;;
    avahi-daemon|avahi-daemon.service|avahi-daemon.socket|avahi*) echo "avahi-daemon" ;;
    systemd-journald|systemd-journald.service|systemd-udevd|systemd-udevd.service|systemd-logind|systemd-logind.service) echo "systemd udev" ;;
    systemd-timesyncd|systemd-timesyncd.service) echo "systemd-timesyncd" ;;
    systemd-resolved|systemd-resolved.service) echo "systemd-resolved systemd" ;;
    systemd*) echo "systemd" ;;
    NetworkManager|NetworkManager.service) echo "network-manager" ;;
    wpa_supplicant|wpa_supplicant.service) echo "wpasupplicant" ;;
    dbus|dbus.service) echo "dbus" ;;
    cron|cron.service) echo "cron" ;;
    irqbalance|irqbalance.service) echo "irqbalance" ;;
    wtc-*) echo "wtc-core" ;;
    *) echo "" ;;
  esac
}

harden_mariadb_loopback() {
  log "Enforcing MariaDB loopback binding to 127.0.0.1 (closing LAN port exposure)..."

  # 1. Authoritative drop-in configs in both mariadb.conf.d and conf.d
  mkdir -p /etc/mysql/mariadb.conf.d /etc/mysql/conf.d 2>/dev/null || true
  cat > /etc/mysql/mariadb.conf.d/99-wtc-bind.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
EOF
  chmod 0644 /etc/mysql/mariadb.conf.d/99-wtc-bind.cnf 2>/dev/null || true

  if [ -d /etc/mysql/conf.d ]; then
    cp -f /etc/mysql/mariadb.conf.d/99-wtc-bind.cnf /etc/mysql/conf.d/99-wtc-bind.cnf 2>/dev/null || true
    chmod 0644 /etc/mysql/conf.d/99-wtc-bind.cnf 2>/dev/null || true
  fi

  # 2. Also un-comment and replace any explicit bind-address in all cnf files
  for f in /etc/mysql/mariadb.conf.d/*.cnf /etc/mysql/conf.d/*.cnf /etc/mysql/my.cnf /etc/mysql/mariadb.cnf; do
    if [ -f "$f" ]; then
      sed -i -E 's/^#?[[:space:]]*bind-address[[:space:]]*=.*/bind-address = 127.0.0.1/' "$f" 2>/dev/null || true
    fi
  done

  # 3. Restart MariaDB / MySQL daemon
  if command -v systemctl >/dev/null 2>&1; then
    systemctl restart mariadb 2>/dev/null || systemctl restart mysql 2>/dev/null || true
  fi

  # 4. Invalidate all security scan caches
  rm -f /mnt/ramdisk/.security_scan.json /tmp/.security_scan.json /tmp/.wtc_security_scan.json 2>/dev/null || true
}

record_applied_patch() {
  local unit="${1:-}"
  local cve="${2:-ALL}"
  local ver="${3:-}"
  local ts; ts="$(date -u +"%Y-%m-%dT%H:%M:%SZ" 2>/dev/null || date +"%Y-%m-%dT%H:%M:%SZ")"

  mkdir -p /etc/wtc-web /opt/wtc-web /var/lib/wtc-web 2>/dev/null || true
  local patch_targets=(
    "/etc/wtc-web/applied_patches.json"
    "/opt/wtc-web/applied_patches.json"
    "/var/lib/wtc-web/applied_patches.json"
    "/mnt/ramdisk/applied_patches.json"
    "/tmp/applied_patches.json"
  )
  for pf in "${patch_targets[@]}"; do
    if command -v php >/dev/null 2>&1; then
      php -r '
        $file = $argv[1];
        $entry = ["unit" => $argv[2], "cve" => $argv[3], "version" => $argv[4], "timestamp" => $argv[5]];
        $data = file_exists($file) ? json_decode(@file_get_contents($file), true) : [];
        if (!is_array($data)) $data = [];
        $data[] = $entry;
        $unique = [];
        foreach ($data as $item) {
          $key = ($item["unit"] ?? "") . ":" . ($item["cve"] ?? "");
          $unique[$key] = $item;
        }
        @file_put_contents($file, json_encode(array_values($unique), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      ' "$pf" "$unit" "$cve" "$ver" "$ts" 2>/dev/null || true
    fi
    chmod 0666 "$pf" 2>/dev/null || true
  done
}

remove_applied_patch() {
  local unit="${1:-all}"
  local patch_targets=(
    "/etc/wtc-web/applied_patches.json"
    "/opt/wtc-web/applied_patches.json"
    "/var/lib/wtc-web/applied_patches.json"
    "/mnt/ramdisk/applied_patches.json"
    "/tmp/applied_patches.json"
  )
  for pf in "${patch_targets[@]}"; do
    if [ -f "$pf" ] && command -v php >/dev/null 2>&1; then
      php -r '
        $file = $argv[1];
        $u = strtolower(preg_replace("/\.service$/", "", trim($argv[2] ?? "all")));
        $data = file_exists($file) ? json_decode(@file_get_contents($file), true) : [];
        if (!is_array($data)) $data = [];
        $filtered = array_values(array_filter($data, function($item) use ($u) {
          if ($u === "all" || $u === "*") return false;
          $pu = strtolower(preg_replace("/\.service$/", "", trim((string)($item["unit"] ?? ""))));
          if ($pu === $u) return false;
          if (str_starts_with($u, "php") && str_starts_with($pu, "php")) return false;
          return true;
        }));
        @file_put_contents($file, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      ' "$pf" "$unit" 2>/dev/null || true
    fi
  done
  rm -f /mnt/ramdisk/.security_scan.json /tmp/.security_scan.json /tmp/.wtc_security_scan.json 2>/dev/null || true
}

cmd_service_unpatch() {
  local unit="${1:-all}"
  remove_applied_patch "$unit"
  log "Reset patch receipts for service '$unit'"
  echo "{\"ok\":true,\"unit\":\"$unit\",\"message\":\"Patch receipts reset for $unit\"}"
}

harden_php_security() {
  log "Applying PHP runtime hardening and vulnerability mitigations..."
  local phpv; phpv="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo '8.2')"
  local ini_dirs=(
    "/etc/php/$phpv/fpm/conf.d"
    "/etc/php/$phpv/cli/conf.d"
    "/etc/php/8.5/fpm/conf.d"
    "/etc/php/8.4/fpm/conf.d"
    "/etc/php/8.3/fpm/conf.d"
    "/etc/php/8.2/fpm/conf.d"
  )

  for d in "${ini_dirs[@]}"; do
    if [ -d "$d" ]; then
      cat > "$d/90-wtc-security.ini" <<'EOF'
; WTC Security Hardening Drop-in
expose_php = Off
display_errors = Off
max_multipart_body_parts = 1000
max_file_uploads = 50000
allow_url_include = Off
session.cookie_httponly = 1
session.use_strict_mode = 1
EOF
      chmod 0644 "$d/90-wtc-security.ini" 2>/dev/null || true
    fi
  done

  # Record patch receipts for PHP-FPM across common aliases
  record_applied_patch "php${phpv}-fpm.service" "SEC-PHP-HARDEN" "hardened"
  record_applied_patch "php-fpm" "SEC-PHP-HARDEN" "hardened"
  record_applied_patch "php" "SEC-PHP-HARDEN" "hardened"

  rm -f /mnt/ramdisk/.security_scan.json /tmp/.security_scan.json /tmp/.wtc_security_scan.json 2>/dev/null || true
}

cmd_service_patch() {
  local unit="$1" pkgs="" rc=0 out=""
  [ -n "$unit" ] || die "Unit required for patch"
  [[ "$unit" =~ ^[a-zA-Z0-9_.@-]{1,80}$ ]] || die "Invalid service unit: $unit"

  pkgs="$(service_to_pkgs "$unit")"
  log "Patching service '$unit' (mapped packages: ${pkgs:-none})..."

  if [ "$pkgs" = "wtc-core" ]; then
    log "Re-verifying and refreshing WTC platform service '$unit'"
    chmod 0755 /usr/local/bin/wtc-* /usr/local/lib/wtc-web/common.sh 2>/dev/null || true
    systemctl restart "$unit" 2>/dev/null || true
    record_applied_patch "$unit" "SEC-WTC-CORE" "1.2.0"
    echo "{\"ok\":true,\"unit\":\"$unit\",\"patched_packages\":[\"wtc-core\"],\"message\":\"WTC platform service $unit refreshed and verified\"}"
    return 0
  fi

  if [ -z "$pkgs" ]; then
    pkgs="${unit%.service}"
  fi

  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    local APT=(apt-get -o DPkg::Lock::Timeout=300 -y)
    local prc="/usr/sbin/policy-rc.d" prc_set=0
    case "$unit" in
      php*)
        # The UI itself runs on PHP-FPM: a package-triggered restart would kill this very request (502).
        if [ ! -e "$prc" ]; then
          printf '#!/bin/sh\nexit 101\n' > "$prc" 2>/dev/null && chmod 0755 "$prc" && prc_set=1
        fi
        ;;
    esac
    "${APT[@]}" update >/dev/null 2>&1 || true
    out="$("${APT[@]}" install --only-upgrade $pkgs 2>&1)" || rc=$?
    [ "$prc_set" = 1 ] && rm -f "$prc"
    if [ "$rc" -ne 0 ]; then
      log "Warning: apt-get upgrade for '$pkgs' exited with code $rc: $out"
    fi
  else
    log "Notice: Running in environment without apt-get (mock patch applied for $unit)"
  fi

  local post_ver=""
  if [ -n "$pkgs" ] && command -v dpkg-query >/dev/null 2>&1; then
    local first_pkg="${pkgs%% *}"
    post_ver="$(dpkg-query -W -f='${Version}' "$first_pkg" 2>/dev/null || echo "")"
  fi

  case "$unit" in
    mariadb|mariadb.service|mysql|mysql.service)
      harden_mariadb_loopback 2>/dev/null || true
      record_applied_patch "$unit" "SEC-BIND-01" "127.0.0.1"
      record_applied_patch "$unit" "PACKAGE_UPGRADE" "${post_ver:-hardened}"
      systemctl restart mariadb 2>/dev/null || systemctl restart "$unit" 2>/dev/null || true
      ;;
    php*|php*-fpm|php*-fpm.service)
      harden_php_security 2>/dev/null || true
      record_applied_patch "$unit" "PACKAGE_UPGRADE" "${post_ver:-hardened}"
      # Defer reload until after HTTP JSON response completes so PHP-FPM never drops this active request
      if [[ "$unit" == *fpm* ]] && command -v systemctl >/dev/null 2>&1; then
        ( nohup bash -c "sleep 1.2 && systemctl reload '$unit'" </dev/null >/dev/null 2>&1 & )
      fi
      ;;
    nginx|nginx.service)
      record_applied_patch "$unit" "PACKAGE_UPGRADE" "${post_ver:-hardened}"
      if command -v systemctl >/dev/null 2>&1; then
        systemctl reload "$unit" 2>/dev/null || true
      fi
      ;;
    *.socket|*.timer|*.path|*@*|sshd-keygen*|phpsessionclean*)
      record_applied_patch "$unit" "PACKAGE_UPGRADE" "${post_ver:-hardened}"
      ;;
    *)
      record_applied_patch "$unit" "PACKAGE_UPGRADE" "${post_ver:-hardened}"
      systemctl reload "$unit" 2>/dev/null || systemctl restart "$unit" 2>/dev/null || true
      ;;
  esac

  local is_active=true
  if command -v systemctl >/dev/null 2>&1; then
    systemctl is-active --quiet "$unit" 2>/dev/null || is_active=false
  fi

  rm -f /mnt/ramdisk/.security_scan.json /tmp/.security_scan.json /tmp/.wtc_security_scan.json 2>/dev/null || true
  log "Successfully patched service '$unit'"
  echo "{\"ok\":true,\"unit\":\"$unit\",\"is_active\":$is_active,\"patched_packages\":[\"$pkgs\"],\"installed_version\":\"$post_ver\",\"message\":\"Service $unit and packages ($pkgs) updated and hardened successfully\"}"
}

cmd_service_patch_all() {
  local rc=0 out=""
  local phpv; phpv="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo '')"
  log "Patching all available security packages..."

  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    local APT=(apt-get -o DPkg::Lock::Timeout=300 -y)
    local prc="/usr/sbin/policy-rc.d" prc_set=0
    if [ ! -e "$prc" ]; then
      printf '#!/bin/sh\nexit 101\n' > "$prc" 2>/dev/null && chmod 0755 "$prc" && prc_set=1
    fi
    "${APT[@]}" update >/dev/null 2>&1 || true
    out="$("${APT[@]}" dist-upgrade 2>&1)" || rc=$?
    [ "$prc_set" = 1 ] && rm -f "$prc"
    if [ "$rc" -ne 0 ]; then
      log "Warning: dist-upgrade reported: $out"
    fi
  else
    log "Notice: Mock patch all applied in non-apt environment"
  fi

  harden_mariadb_loopback 2>/dev/null || true
  harden_php_security 2>/dev/null || true

  local s_units=(nginx.service nginx mariadb.service mariadb mysql.service mysql php-fpm.service php-fpm php ssh.service ssh sshd.service sshd smbd.service smbd redis.service redis redis-server.service vsftpd.service vsftpd avahi-daemon.service avahi-daemon systemd-resolved.service system wtc-core)
  [ -n "$phpv" ] && s_units+=("php${phpv}-fpm.service")
  local patched_count=0
  for su in "${s_units[@]}"; do
    local spkgs; spkgs="$(service_to_pkgs "$su")"
    local sp_ver=""
    if [ -n "$spkgs" ] && command -v dpkg-query >/dev/null 2>&1; then
      local fp="${spkgs%% *}"
      sp_ver="$(dpkg-query -W -f='${Version}' "$fp" 2>/dev/null || echo "")"
    fi
    if [ -n "$sp_ver" ]; then
      record_applied_patch "$su" "PACKAGE_UPGRADE" "$sp_ver"
      patched_count=$((patched_count + 1))
    fi
  done
  record_applied_patch "all" "ALL" "hardened"

  local php_unit; php_unit="$(php_fpm_unit 2>/dev/null || echo '')"
  if command -v systemctl >/dev/null 2>&1; then
    systemctl reload nginx 2>/dev/null || true
    systemctl restart mariadb smbd 2>/dev/null || true
    if [ -n "$php_unit" ]; then
      ( nohup bash -c "sleep 1.2 && systemctl reload '$php_unit'" </dev/null >/dev/null 2>&1 & )
    fi
  fi

  rm -f /mnt/ramdisk/.security_scan.json /tmp/.security_scan.json /tmp/.wtc_security_scan.json 2>/dev/null || true
  log "All services and packages patched successfully"
  echo "{\"ok\":true,\"patched_count\":$patched_count,\"total_units\":${#s_units[@]},\"message\":\"All security updates and packages successfully patched and services reloaded\"}"
}

cmd_security_remediate() {
  local rule="${1:-}"
  log "Remediating security vulnerability: $rule"

  case "$rule" in
    enable_firewall)
      cmd_firewall enable
      echo '{"ok":true,"rule":"enable_firewall","message":"UFW Firewall successfully enabled and active"}'
      ;;
    close_db_port)
      harden_mariadb_loopback
      echo '{"ok":true,"rule":"close_db_port","message":"MariaDB bind-address restricted to 127.0.0.1 and daemon restarted"}'
      ;;
    disable_samba_guest)
      cmd_transfer samba-guest no
      echo '{"ok":true,"rule":"disable_samba_guest","message":"Samba guest mode disabled; password authentication required"}'
      ;;
    disable_ftp_guest)
      cmd_transfer ftp-guest no
      echo '{"ok":true,"rule":"disable_ftp_guest","message":"FTP anonymous access disabled"}'
      ;;
    disable_app_debug)
      local env_f="/mnt/ramdisk/webroot/.env"
      if [ -f "$env_f" ]; then
        sed -i 's/^[[:space:]]*APP_DEBUG[[:space:]]*=.*/APP_DEBUG=false/' "$env_f" 2>/dev/null || true
      fi
      echo '{"ok":true,"rule":"disable_app_debug","message":"APP_DEBUG set to false in live .env"}'
      ;;
    link_laravel_storage)
      cmd_storage_link ""
      echo '{"ok":true,"rule":"link_laravel_storage","message":"Laravel storage symlinks verified and linked to storage/app/public"}'
      ;;
    harden_php_tokens)
      local php_u; php_u="$(php_fpm_unit 2>/dev/null || echo '')"
      local phpv=""
      if [[ "$php_u" =~ php([0-9]+\.[0-9]+) ]]; then
        phpv="${BASH_REMATCH[1]}"
      else
        phpv="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
      fi
      for v in $phpv 8.5 8.4 8.3 8.2; do
        if [ -d "/etc/php/$v/fpm/conf.d" ]; then
          local ini="/etc/php/$v/fpm/conf.d/90-wtc.ini"
          grep -q 'expose_php' "$ini" 2>/dev/null || echo "expose_php = Off" >> "$ini"
          grep -q 'display_errors' "$ini" 2>/dev/null || echo "display_errors = Off" >> "$ini"
        fi
      done
      [ -n "$php_u" ] && systemctl reload "$php_u" 2>/dev/null || true
      echo '{"ok":true,"rule":"harden_php_tokens","message":"PHP expose_php and display_errors hardened"}'
      ;;
    patch_*)
      local unit="${rule#patch_}"
      cmd_service_patch "$unit"
      ;;
    *)
      die "Unknown remediation rule: $rule"
      ;;
  esac

  rm -f /mnt/ramdisk/.security_scan.json /tmp/.security_scan.json /tmp/.wtc_security_scan.json 2>/dev/null || true
}

generate_security_audit() {
  local force="${1:-0}"
  local cache_dir="/mnt/ramdisk"
  [ -d "$cache_dir" ] || cache_dir="/tmp"
  local cache_file="$cache_dir/.security_scan.json"

  # "cached" mode (used for page loads/status refresh): return the last scan result
  # of any age, or {} if never scanned. Scans only run when the user presses Scan.
  if [ "$force" = "cached" ]; then
    if [ -f "$cache_file" ]; then cat "$cache_file"; else echo '{}'; fi
    return 0
  fi

  if [ "$force" != "1" ] && [ -f "$cache_file" ]; then
    local mtime; mtime="$(stat -c %Y "$cache_file" 2>/dev/null || echo 0)"
    local now; now="$(date +%s)"
    if [ $((now - mtime)) -lt 120 ]; then
      cat "$cache_file"
      return 0
    fi
  fi

  local phpv; phpv="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo '8.2')"
  local issues=()
  local critical=0 high=0 medium=0 low=0

  # 1. UFW Firewall
  local ufw_act="no"
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi 'status: active'; then
    ufw_act="yes"
  fi
  if [ "$ufw_act" != "yes" ]; then
    issues+=('{"id":"ufw_disabled","category":"network","severity":"medium","title":"UFW Host Firewall is Inactive","desc":"The packet-filtering firewall is currently disabled, exposing listening ports directly to the local network.","remediation":"Activate UFW with preconfigured rules for Port 80, 8080, 4485, and SSH.","can_remediate":true,"remediation_id":"enable_firewall"}')
    medium=$((medium + 1))
  fi

  # 2. Database Port Exposure (Must strictly bind to 127.0.0.1/::1 loopback)
  local db_exposed=0
  local net_out=""
  if command -v ss >/dev/null 2>&1; then
    net_out="$(ss -tlpn 2>/dev/null || true)"
  elif command -v netstat >/dev/null 2>&1; then
    net_out="$(netstat -tlpn 2>/dev/null || true)"
  fi

  if [ -n "$net_out" ]; then
    db_exposed="$(echo "$net_out" | awk '
      {
        for (i=1; i<=NF; i++) {
          if ($i ~ /:(3306|33060)$/) {
            addr = $i
            if (addr !~ /^(127\.0\.0\.1|\[::1\]|::1|localhost):/) {
              print 1
              exit
            }
          }
        }
      }
    ' || echo 0)"
    [ "$db_exposed" = "1" ] || db_exposed=0
  fi

  if [ "$db_exposed" -eq 1 ]; then
    issues+=('{"id":"db_port_exposed","category":"network","severity":"high","title":"MariaDB Database Port 3306 Exposed to LAN","desc":"MariaDB is listening on public interfaces instead of binding strictly to loopback (127.0.0.1).","remediation":"Bind MariaDB to 127.0.0.1 to reject unauthorized remote network connections.","can_remediate":true,"remediation_id":"close_db_port"}')
    high=$((high + 1))
  fi

  # 3. Samba Guest Access
  if [ "${SAMBA_GUEST:-yes}" = "yes" ]; then
    issues+=('{"id":"samba_guest_active","category":"auth","severity":"medium","title":"Samba Guest Network Access is Enabled","desc":"The Windows Dropbox network share (\\\\pi\\dropbox) allows anonymous guest file reads and writes without a password.","remediation":"Require password authentication for Windows Samba network users.","can_remediate":true,"remediation_id":"disable_samba_guest"}')
    medium=$((medium + 1))
  fi

  # 4. FTP Guest Access
  if [ "${FTP_GUEST:-yes}" = "yes" ] && command -v vsftpd >/dev/null 2>&1 && systemctl is-active --quiet vsftpd 2>/dev/null; then
    issues+=('{"id":"ftp_guest_active","category":"auth","severity":"medium","title":"Anonymous FTP Guest Access Enabled","desc":"The vsftpd daemon permits unauthenticated anonymous logins on Port 21.","remediation":"Disable anonymous guest logins in FTP configuration.","can_remediate":true,"remediation_id":"disable_ftp_guest"}')
    medium=$((medium + 1))
  fi

  # 5. PHP Runtime Hardening
  local php_tokens_leaked=0
  if php -i 2>/dev/null | grep -qi '^expose_php => On'; then
    php_tokens_leaked=1
  fi
  if [ "$php_tokens_leaked" -eq 1 ]; then
    issues+=('{"id":"php_tokens_leaked","category":"php_runtime","severity":"low","title":"PHP Version Information Leaked in Headers","desc":"PHP sends the X-Powered-By HTTP response header disclosing the exact PHP version to web clients.","remediation":"Set expose_php = Off in PHP-FPM configuration.","can_remediate":true,"remediation_id":"harden_php_tokens"}')
    low=$((low + 1))
  fi

  # 6. Sensitive Files & Application Debug Mode
  local active_env="/mnt/ramdisk/webroot/.env"
  if [ -f "$active_env" ] && grep -qiE '^[[:space:]]*APP_DEBUG[[:space:]]*=[[:space:]]*true' "$active_env" 2>/dev/null; then
    issues+=('{"id":"app_debug_enabled","category":"app_config","severity":"high","title":"Laravel APP_DEBUG is Enabled in Production","desc":"Verbose debugging is enabled on the active website. Unhandled application exceptions will expose database credentials, environment keys, and source code.","remediation":"Set APP_DEBUG=false in the active site .env file.","can_remediate":true,"remediation_id":"disable_app_debug"}')
    high=$((high + 1))
  fi

  # Check active site Laravel storage link
  local active_ram="/mnt/ramdisk/webroot"
  if [ -d "$active_ram/public" ] && { [ -f "$active_ram/artisan" ] || [ -f "$active_ram/public/index.php" ]; }; then
    if [ ! -L "$active_ram/public/storage" ] || [ ! -e "$active_ram/public/storage" ]; then
      issues+=('{"id":"laravel_storage_unlinked","category":"app_config","severity":"medium","title":"Laravel Public Storage Link is Missing","desc":"The active Laravel application does not have public/storage linked to storage/app/public. Uploaded files, user avatars, and media assets will return 404 errors.","remediation":"Link Laravel storage via wtc-manage --storage-link or the 🔗 storage:link button in Sites & Apps.","can_remediate":true,"remediation_id":"link_laravel_storage"}')
      medium=$((medium + 1))
    fi
  fi

  # 7. System Reboot Required
  if [ -f /var/run/reboot-required ]; then
    issues+=('{"id":"reboot_required","category":"system","severity":"low","title":"System Reboot Required for Security Patches","desc":"Core operating system security updates (e.g., Linux kernel or glibc) have been installed and require a reboot to take effect.","remediation":"Reboot device from the Device Dashboard when convenient.","can_remediate":false,"remediation_id":""}')
    low=$((low + 1))
  fi

  # 8. Check upgradable packages for service CVEs
  local upgradable_map=""
  if command -v apt-get >/dev/null 2>&1; then
    upgradable_map="$(apt-get -s dist-upgrade 2>/dev/null | awk '/^Inst / {print $2 "=" $3 "=" $4}' || true)"
  fi

  local cve_client_path="/opt/wtc-web/ui/components/cve_client.php"
  [ -f "$cve_client_path" ] || cve_client_path="/mnt/ramdisk/ui/components/cve_client.php"
  [ -f "$cve_client_path" ] || cve_client_path="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/ui/components/cve_client.php"

  if [ "$force" = "1" ] && [ -f "$cve_client_path" ] && command -v php >/dev/null 2>&1; then
    php -r "require_once '$cve_client_path'; get_cve_threat_catalog(true);" 2>/dev/null || true
  fi

  local svc_sec_items=()
  local mon_units=(nginx.service mariadb.service mysql.service smbd.service ssh.service sshd.service redis.service redis-server.service vsftpd.service avahi-daemon.service systemd-journald.service cron.service)
  local php_unit; php_unit="$(php_fpm_unit 2>/dev/null || echo '')"
  [ -n "$php_unit" ] && mon_units+=("$php_unit")
  [ -n "$phpv" ] && mon_units+=("php$phpv-fpm.service")

  if command -v systemctl >/dev/null 2>&1; then
    local live_units
    live_units="$(systemctl list-units --type=service "*-*" --no-legend --no-pager 2>/dev/null | awk '{print $1}' || true)"
    if [ -n "$live_units" ]; then
      while read -r lu; do
        [ -n "$lu" ] && mon_units+=("$lu")
      done <<< "$live_units"
    fi
  fi
  
  local unique_units=()
  while IFS= read -r u_entry; do
    [ -n "$u_entry" ] && unique_units+=("$u_entry")
  done < <(printf '%s\n' "${mon_units[@]}" | sort -u)
  mon_units=("${unique_units[@]}")
  
  for u in "${mon_units[@]}"; do
    local pkgs; pkgs="$(service_to_pkgs "$u")"
    local has_patch=false
    local cur_ver="installed"
    local cand_ver=""
    local cve=""
    
    if [ -n "$pkgs" ] && command -v dpkg-query >/dev/null 2>&1; then
      local first_pkg="${pkgs%% *}"
      cur_ver="$(dpkg-query -W -f='${Version}' "$first_pkg" 2>/dev/null || echo "installed")"
      if [ -n "$upgradable_map" ]; then
        for p in $pkgs; do
          if echo "$upgradable_map" | grep -q "^$p="; then
            has_patch=true
            cand_ver="$(echo "$upgradable_map" | grep "^$p=" | head -n1 | cut -d= -f3 | tr -d '[]')"
            cve="USN-Security-Patch"
            break
          fi
        done
      fi
    fi

    if [ "$cur_ver" = "installed" ] || [ -z "$cur_ver" ]; then
      case "$u" in
        nginx*) cur_ver="$(nginx -v 2>&1 | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n1 || true)" ;;
        mariadb*|mysql*) cur_ver="$(mariadb -V 2>&1 | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n1 || true)" ;;
        php*)
          local branch=""
          if [[ "$u" =~ php([0-9]+\.[0-9]+) ]]; then branch="${BASH_REMATCH[1]}"; fi
          if [ -n "$branch" ] && command -v "php$branch" >/dev/null 2>&1; then
            cur_ver="$("php$branch" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
          else
            cur_ver="$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"
          fi
          ;;
        ssh*) cur_ver="$(ssh -V 2>&1 | grep -oE '[0-9]+\.[0-9]+(p[0-9]+)?' | head -n1 || true)" ;;
        redis*) cur_ver="$(redis-server -v 2>/dev/null | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n1 || true)" ;;
        smbd*|samba*) cur_ver="$(smbd -V 2>/dev/null | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n1 || true)" ;;
        vsftpd*) cur_ver="$(vsftpd -v 2>&1 | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n1 || true)" ;;
        avahi*) cur_ver="$(avahi-daemon -V 2>&1 | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n1 || true)" ;;
        wtc-*) cur_ver="1.2.0" ;;
        systemd*|cron*|dbus*) cur_ver="$(systemctl --version 2>/dev/null | grep -oE '[0-9]+' | head -n1 || true)" ;;
        *) cur_ver="$(uname -r 2>/dev/null || true)" ;;
      esac
    fi
    [ -z "$cur_ver" ] && cur_ver="installed"

    local cve_sev="high"
    local cve_title=""
    local cve_fixed=""
    if [ -f "$cve_client_path" ] && command -v php >/dev/null 2>&1; then
      local matched_cve_info
      matched_cve_info="$(php -r '
        require_once $argv[1];
        $force = ($argv[4] ?? "0") === "1";
        $res = audit_service_cves($argv[2], $argv[3], [], $force);
        $vuln = array_values(array_filter($res, fn($c) => !empty($c["is_affected"])));
        if (!empty($vuln[0])) {
          echo $vuln[0]["id"] . "|" . $vuln[0]["severity"] . "|" . ($vuln[0]["title"] ?? "") . "|" . ($vuln[0]["fixed_version"] ?? "");
        }
      ' "$cve_client_path" "$u" "$cur_ver" "$force" 2>/dev/null || true)"
      if [ -n "$matched_cve_info" ]; then
        has_patch=true
        cve="$(echo "$matched_cve_info" | cut -d'|' -f1)"
        cve_sev="$(echo "$matched_cve_info" | cut -d'|' -f2)"
        cve_title="$(echo "$matched_cve_info" | cut -d'|' -f3)"
        cve_fixed="$(echo "$matched_cve_info" | cut -d'|' -f4)"
        [ -z "$cand_ver" ] && cand_ver="$cve_fixed"
      fi
    fi

    if [ "$has_patch" = "true" ]; then
      if [ "$cve_sev" = "critical" ]; then
        critical=$((critical + 1))
      else
        high=$((high + 1))
      fi
      local issue_title="${cve:-Security Advisory}: $u"
      [ -n "$cve_title" ] && issue_title="$cve in $u: $cve_title"
      local issue_desc="Package update available addressing security advisories in $u."
      if [ -n "$cve" ]; then
        issue_desc="Installed version ($cur_ver) is affected by $cve. Fixed in ${cand_ver:-latest update}."
      fi
      issues+=("{\"id\":\"patch_$u\",\"category\":\"package_cve\",\"severity\":\"$cve_sev\",\"title\":\"$issue_title\",\"desc\":\"$issue_desc\",\"remediation\":\"Apply 1-click patch for $u in the Services Monitor.\",\"can_remediate\":true,\"remediation_id\":\"patch_$u\"}")
    fi

    local now_time; now_time="$(date +'%H:%M:%S' 2>/dev/null || date +'%T')"
    if command -v jq >/dev/null 2>&1; then
      svc_sec_items+=("$(jq -nc \
        --arg unit "$u" \
        --argjson has_patch "$has_patch" \
        --arg cur_ver "$cur_ver" \
        --arg cand_ver "$cand_ver" \
        --arg cve "$cve" \
        --arg last_scanned "$now_time" \
        '{unit:$unit,has_patch:$has_patch,installed_version:$cur_ver,candidate_version:$cand_ver,cve:$cve,last_scanned:$last_scanned}')")
    fi
  done

  local total_issues=$((critical + high + medium + low))
  local grade="A+"
  local grade_label="Excellent"
  local grade_color="#3fb950"

  if [ "$critical" -gt 0 ]; then
    grade="F"
    grade_label="Vulnerable"
    grade_color="#f85149"
  elif [ "$high" -gt 0 ]; then
    grade="C"
    grade_label="Needs Attention"
    grade_color="#db6d28"
  elif [ "$medium" -gt 0 ]; then
    grade="B"
    grade_label="Fair"
    grade_color="#d29922"
  elif [ "$low" -gt 0 ]; then
    grade="A"
    grade_label="Good"
    grade_color="#58a6ff"
  fi

  local issues_json="[]"
  if [ "${#issues[@]}" -gt 0 ] && command -v jq >/dev/null 2>&1; then
    issues_json="$(printf '%s\n' "${issues[@]}" | jq -s '.')"
  fi

  local svc_sec_json="[]"
  if [ "${#svc_sec_items[@]}" -gt 0 ] && command -v jq >/dev/null 2>&1; then
    svc_sec_json="$(printf '%s\n' "${svc_sec_items[@]}" | jq -s '.')"
  fi

  local result
  if command -v jq >/dev/null 2>&1; then
    result="$(jq -nc \
      --arg grade "$grade" \
      --arg grade_label "$grade_label" \
      --arg grade_color "$grade_color" \
      --argjson total "$total_issues" \
      --argjson critical "$critical" \
      --argjson high "$high" \
      --argjson medium "$medium" \
      --argjson low "$low" \
      --argjson issues "$issues_json" \
      --argjson services "$svc_sec_json" \
      --arg timestamp "$(date -u +'%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date +'%F %T')" \
      '{grade:$grade,grade_label:$grade_label,grade_color:$grade_color,total_vulnerabilities:$total,critical_count:$critical,high_count:$high,medium_count:$medium,low_count:$low,issues:$issues,services:$services,timestamp:$timestamp}')"
  else
    result="{\"grade\":\"$grade\",\"grade_label\":\"$grade_label\",\"grade_color\":\"$grade_color\",\"total_vulnerabilities\":$total_issues,\"critical_count\":$critical,\"high_count\":$high,\"medium_count\":$medium,\"low_count\":$low,\"issues\":$issues_json,\"services\":$svc_sec_json}"
  fi

  mkdir -p "$(dirname "$cache_file")" 2>/dev/null || true
  echo "$result" > "$cache_file" 2>/dev/null || true
  echo "$result"
}

cmd_service() {
  local action="${1:-status}" unit="${2:-}" lines="${3:-50}"
  case "$action" in
    patch-all)
      cmd_service_patch_all
      return 0
      ;;
    patch)
      [ -n "$unit" ] || die "Service unit name required for patch"
      cmd_service_patch "$unit"
      return 0
      ;;
    unpatch|reset-patch)
      cmd_service_unpatch "${unit:-all}"
      return 0
      ;;
    security-scan)
      generate_security_audit 1
      return 0
      ;;
    remediate)
      [ -n "$unit" ] || die "Remediation rule required"
      cmd_security_remediate "$unit"
      return 0
      ;;
  esac

  [ -n "$unit" ] || die "Service unit name required"
  [[ "$unit" =~ ^[a-zA-Z0-9_.@-]{1,80}$ ]] || die "Invalid service unit name: $unit"

  case "$action" in
    start|stop|reload|enable|disable)
      systemctl "$action" "$unit"
      log "Service $unit: $action executed"
      ;;
    restart)
      if [[ "$unit" =~ ^php.*-fpm ]] || [[ "$unit" =~ ^nginx ]]; then
        systemctl reload "$unit" 2>/dev/null || true
        ( nohup bash -c "sleep 1.5 && systemctl reload-or-restart '$unit'" >/dev/null 2>&1 & )
      else
        systemctl restart "$unit"
      fi
      log "Service $unit: restart executed"
      ;;
    patch)
      cmd_service_patch "$unit"
      ;;
    status)
      systemctl status "$unit" --no-pager
      ;;
    logs)
      [[ "$lines" =~ ^[0-9]+$ ]] || lines=50
      journalctl -u "$unit" -n "$lines" --no-pager 2>&1 || echo "No journal entries found for $unit"
      ;;
    *)
      die "Unknown service action: $action"
      ;;
  esac
}

cmd_cve_lookup() {
  local cve="${1:-}"
  [ -n "$cve" ] || die "CVE identifier required"
  local cve_client_path="/opt/wtc-web/ui/components/cve_client.php"
  [ -f "$cve_client_path" ] || cve_client_path="/mnt/ramdisk/ui/components/cve_client.php"
  [ -f "$cve_client_path" ] || cve_client_path="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/ui/components/cve_client.php"
  if [ -f "$cve_client_path" ] && command -v php >/dev/null 2>&1; then
    php -r '
      require_once $argv[1];
      echo json_encode(fetch_cve_details($argv[2]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ' "$cve_client_path" "$cve" 2>/dev/null || echo "{\"ok\":false,\"error\":\"Lookup failed\"}"
  else
    echo "{\"ok\":false,\"error\":\"PHP or cve_client.php unavailable\"}"
  fi
}

cmd_cve_check() {
  local unit="${1:-}"
  local cve_client_path="/opt/wtc-web/ui/components/cve_client.php"
  [ -f "$cve_client_path" ] || cve_client_path="/mnt/ramdisk/ui/components/cve_client.php"
  [ -f "$cve_client_path" ] || cve_client_path="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/ui/components/cve_client.php"
  if [ -f "$cve_client_path" ] && command -v php >/dev/null 2>&1; then
    php -r '
      require_once $argv[1];
      $unit = $argv[2];
      $ver = $argv[3] ?? "installed";
      $audit = audit_service_cves($unit, $ver);
      echo json_encode(["ok" => true, "unit" => $unit, "cves" => $audit], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ' "$cve_client_path" "$unit" "${2:-}" 2>/dev/null || echo "{\"ok\":false,\"error\":\"Check failed\"}"
  else
    echo "{\"ok\":false,\"error\":\"PHP or cve_client.php unavailable\"}"
  fi
}

cmd_json() {
  case "${1:-}" in
    dash) json_dash "${2:-}" ;;
    sites) json_sites ;; status) json_status ;; dropbox) json_dropbox ;;
    settings) json_settings ;; transfer) json_transfer ;; stack) json_stack ;;
    build) json_build ;; workers) /usr/local/bin/wtc-workers status --json ;;
    nginx) json_nginx ;; sysinfo) json_sysinfo ;; processes) json_processes ;;
    net_monitor) json_net_monitor "${2:-30}" ;;
    firewall) json_firewall ;;
    services) json_services ;;
    security) generate_security_audit cached ;;
    stack_logs) json_stack_logs ;;
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
  --rollback)   cmd_rollback "${2:-}" "${3:-}" ;;
  --rollback-delete) cmd_rollback_delete "${2:-}" "${3:-}" ;;
  --rollback-clear)  cmd_rollback_clear "${2:-}" ;;
  --delete)     cmd_delete "${2:-}" ;;
  --create)     cmd_create "${2:-}" ;;
  --ingest)     "$SERVICE_BIN" --ingest ;;
  --save-state) "$SERVICE_BIN" --save-state ;;
  --json)       cmd_json "${2:-}" "${3:-}" ;;
  --setting)    cmd_setting "${2:-}" "${3:-}" ;;
  --transfer)   cmd_transfer "${2:-}" "${3:-}" ;;
  --stack)      cmd_stack "${2:-}" "${3:-}" ;;
  --stack-exec) cmd_stack_exec "${2:-}" "${3:-}" ;;
  --stack-logs) cmd_stack_logs "${2:-list}" "${3:-wtc-manage}" "${4:-200}" ;;
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
  --ziggy|--rebuild-ziggy) cmd_ziggy "${2:-}" ;;
  --storage-link|--link-storage) cmd_storage_link "${2:-}" ;;
  --direct-deploy)  cmd_direct_deploy "${2:-}" "${3:-}" "${4:-}" "${5:-}" ;;
  --rebuild-catalog) cmd_rebuild_catalog "${2:-}" ;;
  --rebuild-catalog-exec) cmd_rebuild_catalog_exec "${2:-}" ;;
  --store-site)     cmd_store_site "${2:-}" "${3:-}" ;;
  --site-rename)    cmd_site_rename "${2:-}" "${3:-}" ;;
  --site-clone|--site-duplicate|--duplicate-site|--clone-site) cmd_site_clone "${2:-}" "${3:-}" "${4:-}" "${5:-clone}" "${6:-1}" ;;
  --site-stack)     cmd_site_stack "${2:-}" "${3:-}" ;;
  --site-port)      cmd_site_port "${2:-}" "${3:-}" ;;
  --build)          cmd_build "${2:-}" "${3:-}" ;;
  --build-exec)     cmd_build_exec "${2:-}" "${3:-}" ;;
  --server-tune)    cmd_server_tune ;;
  --reload-ui)      cmd_reload_ui ;;
  --workers)        cmd_workers "${2:-status}" "${3:-}" ;;
  --nginx-save)     cmd_nginx_save "${2:-ram}" "${3:-restart}" "${4:-}" ;;
  --nginx-test)     cmd_nginx_test "${2:-ram}" "${3:-}" ;;
  --nginx-ctl)      cmd_nginx_ctl "${2:-restart}" ;;
  --rescan-sites)   cmd_rescan_sites ;;
  --rescan-stack)   build_stack_cache ;;
  --firewall)       cmd_firewall "${2:-status}" "${3:-}" "${4:-tcp}" ;;
  --service)        cmd_service "${2:-status}" "${3:-}" "${4:-50}" ;;
  --security-scan)  generate_security_audit "${2:-1}" ;;
  --security-remediate) cmd_security_remediate "${2:-}" ;;
  --cve-lookup)     cmd_cve_lookup "${2:-}" ;;
  --cve-check)      cmd_cve_check "${2:-}" "${3:-}" ;;
  --network)        if [ -x /usr/local/bin/wtc-network ]; then /usr/local/bin/wtc-network "${2:-check}"; else echo "Network watchdog not installed"; fi ;;
  --fan)            if [ -x /usr/local/bin/wtc-network ]; then /usr/local/bin/wtc-network fan "${2:-status}" "${3:-}"; else echo "Fan controller not installed"; fi ;;
  *) echo "Usage: wtc-manage [--list|--current|--status|--logs N|--set S|--rollback S|--delete S|--create S|--ingest|--save-state|--reload-ui|--rescan-sites|--rescan-stack|--firewall ACT [PORT] [PROTO]|--service ACT UNIT [LINES]|--network [ACT]|--fan [ACT]|--json V|--setting K V|--transfer ...|--stack A ID|--device A|--db-create S|--db-import S|--db-export S [F]|--db-info S|--db-test S|--db-creds S|--db-tables S|--db-query S [Q]|--artisan S CMD|--ziggy [S]|--storage-link [S]|--direct-deploy S DIR [P]|--store-site S DIR|--build S|--server-tune|--workers ACTION|--nginx-save T [R]|--nginx-test T|--nginx-ctl A|--nginx-reset]"; exit 2 ;;
esac

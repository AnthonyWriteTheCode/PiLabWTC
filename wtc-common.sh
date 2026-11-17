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
: "${FAN_ON_WIFI_DISCONNECT:=no}"
: "${FAN_WIFI_MODE:=pulse}"
: "${FAN_GPIO:=14}"
: "${FORCE_HTTPS:=no}"
: "${ROLLBACK_KEEP:=5}"

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
: "${STAGE_DIR:=/var/tmp/wtc-staging}"
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

# Safely extract .tar.gz, .tgz, .tar, or .zip archive into destDir with ZipSlip & symlink defenses
safe_extract_archive() {
  local archive="$1" destDir="$2"
  [ -f "$archive" ] || die "Archive not found: $archive"
  mkdir -p "$destDir"

  local -a tar_cmd=(tar)
  if command -v pigz >/dev/null 2>&1; then
    tar_cmd=(tar --use-compress-program=pigz)
  fi

  # 1. Pre-check: Reject path traversal (ZipSlip / TarSlip) via streaming pipeline (avoids buffering 400k lines in memory)
  case "$archive" in
    *.tar.gz|*.tgz)
      if "${tar_cmd[@]}" -tf "$archive" 2>/dev/null | grep -Eq '(^|/)\.\.(/|$)|^/'; then
        die "Archive rejected: contains absolute or parent directory ('..') path traversal"
      fi
      ;;
    *.tar)
      if tar -tf "$archive" 2>/dev/null | grep -Eq '(^|/)\.\.(/|$)|^/'; then
        die "Archive rejected: contains absolute or parent directory ('..') path traversal"
      fi
      ;;
    *.zip)
      command -v unzip >/dev/null 2>&1 || die "unzip utility not installed"
      if unzip -Z1 "$archive" 2>/dev/null | grep -Eq '(^|/)\.\.(/|$)|^/'; then
        die "Archive rejected: contains absolute or parent directory ('..') path traversal"
      fi
      ;;
    *)
      die "Unsupported archive format: $archive"
      ;;
  esac

  # 2. Extract
  case "$archive" in
    *.tar.gz|*.tgz)
      "${tar_cmd[@]}" -xf "$archive" -C "$destDir" --no-same-owner --no-same-permissions || die "Tar extraction failed"
      ;;
    *.tar)
      tar -xf "$archive" -C "$destDir" --no-same-owner --no-same-permissions || die "Tar extraction failed"
      ;;
    *.zip)
      unzip -qo "$archive" -d "$destDir" || die "Zip extraction failed"
      ;;
  esac

  # 3. Post-check: Audit symlinks. Remove any symlinks that resolve outside destDir or point to sensitive paths
  local real_dest; real_dest="$(readlink -f "$destDir" 2>/dev/null || echo "$destDir")"
  local link target real_target
  while IFS= read -r link; do
    [ -L "$link" ] || continue
    target="$(readlink "$link" 2>/dev/null || true)"
    real_target="$(readlink -f "$link" 2>/dev/null || true)"
    # If symlink points to absolute system path or outside destDir, purge it
    if [[ "$target" == /* ]] || [ -z "$real_target" ] || [[ "$real_target" != "$real_dest"* ]]; then
      log "Security Warning: Removed unsafe symlink in archive: $link -> $target"
      rm -f "$link"
    fi
  done < <(find "$destDir" -type l 2>/dev/null || true)
  return 0
}

# Site IDs: lowercase letters, digits, '-' and '_'.
valid_name() { [[ "${1:-}" =~ ^[a-z0-9][a-z0-9_-]{0,62}$ ]]; }

list_sites() {
  local f cur s seen=""
  cur="$(active_site)"
  for f in "$SITES_DIR"/*.tar.gz; do
    [ -e "$f" ] || continue
    s="$(basename "$f" .tar.gz)"
    echo "$s"
    seen="$seen|$s|"
  done
  while IFS= read -r s; do
    [ -z "$s" ] && continue
    case "$seen" in
      *"|$s|"*) ;;
      *) echo "$s"; seen="$seen|$s|" ;;
    esac
  done < <(list_active_sites)
  if [ -n "$cur" ]; then
    case "$seen" in
      *"|$cur|"*) ;;
      *) echo "$cur" ;;
    esac
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
  mkdir -p "$RELEASES_DIR" "$RAM_SITES_DIR" "$RAM_NGINX_SITES" "$DROP_DIR" "$REJECT_DIR" "$PHP_TMP" "$PHP_TMP/nginx-body" "$STAGE_DIR" "/var/tmp/nginx-body" "/var/tmp/wtc-staging"
  if getent group "$DROP_GROUP" >/dev/null; then
    chgrp "$DROP_GROUP" "$DROP_DIR" "$REJECT_DIR"
  fi
  chmod 2775 "$DROP_DIR" "$REJECT_DIR"
  chmod 1777 "$PHP_TMP" "$STAGE_DIR" "/var/tmp/wtc-staging"
  chown -R www-data:www-data "$PHP_TMP/nginx-body" "/var/tmp/nginx-body" "$STAGE_DIR" 2>/dev/null || true
  chmod 0700 "$PHP_TMP/nginx-body" "/var/tmp/nginx-body" 2>/dev/null || true
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

  # Restore persistent security patch receipts from SD storage into RAM disk
  for pf_src in /etc/wtc-web/applied_patches.json /opt/wtc-web/applied_patches.json /var/lib/wtc-web/applied_patches.json; do
    if [ -f "$pf_src" ]; then
      cp -f "$pf_src" "$RAM_MOUNT/applied_patches.json" 2>/dev/null || true
      cp -f "$pf_src" "/tmp/applied_patches.json" 2>/dev/null || true
      chmod 0666 "$RAM_MOUNT/applied_patches.json" "/tmp/applied_patches.json" 2>/dev/null || true
      break
    fi
  done
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

# Preserve working site configs (.env, database credentials, .wtc-database.sql, SQLite, storage/app)
# from being overwritten when re-uploading or updating an application package.
preserve_existing_site_configs() {
  local site="$1" staged="$2" slug live="" old_archive="" ext_tmp=""
  [ -n "$site" ] && [ -d "$staged" ] || return 0

  slug="$(site_slug "$site")"
  if [ -d "$RAM_SITES_DIR/$slug" ]; then
    live="$RAM_SITES_DIR/$slug"
  fi
  old_archive="$SITES_DIR/$site.tar.gz"

  # If not live in RAM, but an existing archive package exists, extract protected files to inspect
  if [ -z "$live" ] && [ -f "$old_archive" ]; then
    ext_tmp="$(mktemp -d "${STAGE_DIR:-/tmp}/.prev_extract.XXXXXX" 2>/dev/null || mktemp -d "/tmp/.prev_extract.XXXXXX")"
    tar -xf "$old_archive" -C "$ext_tmp" --no-same-owner --no-same-permissions 2>/dev/null || true
    live="$ext_tmp"
  fi

  [ -n "$live" ] && [ -d "$live" ] || return 0

  # Collect protected patterns (defaults + gitignore + wtc-protected.json)
  local patterns=('.env' '.env.*' '.wtc-database.sql' '.wtc-db.sql' '.wtc-db.php' 'nginx.conf' 'wtc.json' 'wp-config.php')

  # Read custom patterns from .wtc-protected.json if written by UI or user
  local pjson
  for pjson in "$staged/.wtc-protected.json" "$live/.wtc-protected.json"; do
    if [ -f "$pjson" ] && command -v jq >/dev/null; then
      while IFS= read -r p; do
        [ -n "$p" ] && patterns+=("$p")
      done < <(jq -r '.[]? // empty' "$pjson" 2>/dev/null || true)
    fi
  done

  # Read gitignore/wtcignore rules for configs, databases, and keys
  local gi
  for gi in "$staged/.gitignore" "$live/.gitignore" "$staged/.wtcignore" "$live/.wtcignore"; do
    if [ -f "$gi" ]; then
      while IFS= read -r line || [ -n "$line" ]; do
        line="$(echo "$line" | sed -e 's/[[:space:]]*#.*//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        [ -n "$line" ] || continue
        line="${line#/}"
        if [[ "$line" =~ (\.env|\.sql|\.sqlite|\.db|storage|config|secret|key|upload) ]]; then
          patterns+=("$line")
        fi
      done < "$gi"
    fi
  done

  # 1. Protect & merge .env
  if [ -f "$live/.env" ]; then
    if [ ! -f "$staged/.env" ]; then
      cp -p "$live/.env" "$staged/.env" 2>/dev/null || true
    else
      # Live .env is the authority on the server.
      # Keep live .env completely, and only append genuinely new keys from staged .env
      local merged_tmp; merged_tmp="$(mktemp "${STAGE_DIR:-/tmp}/.env_merged.XXXXXX" 2>/dev/null || mktemp "/tmp/.env_merged.XXXXXX")"
      cp -p "$live/.env" "$merged_tmp"
      while IFS= read -r line || [ -n "$line" ]; do
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ "$line" =~ ^[[:space:]]*$ ]] && continue
        local k="${line%%=*}"
        k="$(echo "$k" | tr -d '[:space:]')"
        [ -n "$k" ] || continue
        if ! grep -qE "^[[:space:]]*${k}=" "$live/.env" 2>/dev/null; then
          echo "$line" >> "$merged_tmp"
        fi
      done < "$staged/.env"
      mv -f "$merged_tmp" "$staged/.env"
    fi
  fi

  # 2. Preserve .wtc-db.php (managed database helper)
  if [ -f "$live/.wtc-db.php" ] && [ ! -f "$staged/.wtc-db.php" ]; then
    cp -p "$live/.wtc-db.php" "$staged/.wtc-db.php" 2>/dev/null || true
  fi

  # 3. Preserve database snapshot dump (.wtc-database.sql or .wtc-db.sql)
  if [ ! -f "$staged/.wtc-database.sql" ] && [ ! -f "$staged/.wtc-db.sql" ]; then
    if [ -f "$live/.wtc-database.sql" ]; then
      cp -p "$live/.wtc-database.sql" "$staged/.wtc-database.sql" 2>/dev/null || true
    elif [ -f "$live/.wtc-db.sql" ]; then
      cp -p "$live/.wtc-db.sql" "$staged/.wtc-db.sql" 2>/dev/null || true
    fi
  fi

  # 4. Preserve SQLite databases (database/database.sqlite or *.sqlite, *.db)
  local sqldb
  for sqldb in database/database.sqlite database/*.sqlite storage/*.sqlite *.sqlite *.db; do
    if [ -f "$live/$sqldb" ]; then
      if [ ! -f "$staged/$sqldb" ] || [ ! -s "$staged/$sqldb" ]; then
        mkdir -p "$(dirname "$staged/$sqldb")" 2>/dev/null || true
        cp -p "$live/$sqldb" "$staged/$sqldb" 2>/dev/null || true
      fi
    fi
  done

  # 5. Preserve user uploads & persisted media (storage/app)
  if [ -d "$live/storage/app" ]; then
    mkdir -p "$staged/storage/app" 2>/dev/null || true
    cp -rn "$live/storage/app"/* "$staged/storage/app"/ 2>/dev/null || true
  fi

  # 6. Check all custom and gitignore patterns
  local pat match_f rel_f
  for pat in "${patterns[@]}"; do
    [ -n "$pat" ] || continue
    if [[ "$pat" == *"*"* ]] || [ -d "$live/$pat" ]; then
      while IFS= read -r match_f; do
        [ -f "$match_f" ] || continue
        rel_f="${match_f#$live/}"
        if [ -f "$live/$rel_f" ]; then
          mkdir -p "$(dirname "$staged/$rel_f")" 2>/dev/null || true
          if [ ! -f "$staged/$rel_f" ] || [ ! -s "$staged/$rel_f" ]; then
            cp -p "$live/$rel_f" "$staged/$rel_f" 2>/dev/null || true
          fi
        fi
      done < <(find "$live" -type f -path "$live/$pat" 2>/dev/null || true)
    else
      if [ -f "$live/$pat" ]; then
        mkdir -p "$(dirname "$staged/$pat")" 2>/dev/null || true
        if [ ! -f "$staged/$pat" ] || [ ! -s "$staged/$pat" ]; then
          cp -p "$live/$pat" "$staged/$pat" 2>/dev/null || true
        fi
      fi
    fi
  done

  [ -n "$ext_tmp" ] && [ -d "$ext_tmp" ] && rm -rf "$ext_tmp" || true
}

# Protect existing live files in RAM ($live_dir) before overlaying uploaded files from $upload_dir.
# Merges .env (keeping live server credentials authoritative) and removes protected files from
# $upload_dir that already exist in $live_dir so cp -a never overwrites live configs/data.
protect_ram_overlay() {
  local site="$1" upload_dir="$2" live_dir="$3"
  [ -n "$site" ] && [ -d "$upload_dir" ] && [ -d "$live_dir" ] || return 0

  local patterns=('.env' '.env.*' '.wtc-database.sql' '.wtc-db.sql' '.wtc-db.php' 'nginx.conf' 'wtc.json' 'wp-config.php' 'database/*.sqlite' '*.sqlite' '*.db')

  local pjson
  for pjson in "$upload_dir/.wtc-protected.json" "$live_dir/.wtc-protected.json"; do
    if [ -f "$pjson" ] && command -v jq >/dev/null 2>&1; then
      while IFS= read -r p; do
        [ -n "$p" ] && patterns+=("$p")
      done < <(jq -r '.[]? // empty' "$pjson" 2>/dev/null || true)
    fi
  done

  local gi line
  for gi in "$upload_dir/.gitignore" "$live_dir/.gitignore" "$upload_dir/.wtcignore" "$live_dir/.wtcignore"; do
    if [ -f "$gi" ]; then
      while IFS= read -r line || [ -n "$line" ]; do
        line="$(echo "$line" | sed -e 's/[[:space:]]*#.*//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        [ -n "$line" ] || continue
        line="${line#/}"
        if [[ "$line" =~ (\.env|\.sql|\.sqlite|\.db|storage|config|secret|key|upload) ]]; then
          patterns+=("$line")
        fi
      done < "$gi"
    fi
  done

  # 1. Merge .env safely into live RAM .env, then remove from upload_dir so cp doesn't clobber it
  if [ -f "$live_dir/.env" ] && [ -f "$upload_dir/.env" ]; then
    local merged_tmp; merged_tmp="$(mktemp "${STAGE_DIR:-/tmp}/.env_merged.XXXXXX" 2>/dev/null || mktemp "/tmp/.env_merged.XXXXXX")"
    cp -p "$live_dir/.env" "$merged_tmp"
    while IFS= read -r line || [ -n "$line" ]; do
      [[ "$line" =~ ^[[:space:]]*# ]] && continue
      [[ "$line" =~ ^[[:space:]]*$ ]] && continue
      local k="${line%%=*}"
      k="$(echo "$k" | tr -d '[:space:]')"
      [ -n "$k" ] || continue
      if ! grep -qE "^[[:space:]]*${k}=" "$live_dir/.env" 2>/dev/null; then
        echo "$line" >> "$merged_tmp"
      fi
    done < "$upload_dir/.env"
    chown "${WEB_USER:-www-data}:${WEB_USER:-www-data}" "$merged_tmp" 2>/dev/null || true
    chmod 0644 "$merged_tmp" 2>/dev/null || true
    mv -f "$merged_tmp" "$live_dir/.env"
    rm -f "$upload_dir/.env"
  fi

  # 2. Never overwrite existing storage/app files in RAM
  if [ -d "$live_dir/storage/app" ] && [ -d "$upload_dir/storage/app" ]; then
    local uf rel_f
    while IFS= read -r uf; do
      [ -f "$uf" ] || continue
      rel_f="${uf#$upload_dir/}"
      if [ -f "$live_dir/$rel_f" ] && [ -s "$live_dir/$rel_f" ]; then
        rm -f "$uf"
      fi
    done < <(find "$upload_dir/storage/app" -type f 2>/dev/null || true)
  fi

  # 3. Never overwrite protected files that already exist in RAM
  local pat match_f rel_f clean_pat
  for pat in "${patterns[@]}"; do
    [ -n "$pat" ] || continue
    clean_pat="${pat#/}"
    clean_pat="${clean_pat%/**}"
    clean_pat="${clean_pat%/}"
    if [[ "$pat" == *"*"* ]] || [ -d "$upload_dir/$clean_pat" ]; then
      while IFS= read -r match_f; do
        [ -f "$match_f" ] || continue
        rel_f="${match_f#$upload_dir/}"
        if [ -f "$live_dir/$rel_f" ] && [ -s "$live_dir/$rel_f" ]; then
          rm -f "$match_f"
        fi
      done < <(find "$upload_dir" -type f \( -path "$upload_dir/$pat" -o -path "$upload_dir/$clean_pat/*" \) 2>/dev/null || true)
    else
      if [ -f "$upload_dir/$clean_pat" ] && [ -f "$live_dir/$clean_pat" ] && [ -s "$live_dir/$clean_pat" ]; then
        rm -f "$upload_dir/$clean_pat"
      fi
    fi
  done
}

# Ensure Laravel sites have a complete storage hierarchy and public/storage properly linked to storage/app/public.
# Enforces relative symlink (../storage/app/public) so that directory swaps, tar archives, and cloning never break.
ensure_laravel_storage_link() {
  local dir="${1:-}"
  [ -n "$dir" ] && [ -d "$dir" ] || return 0

  # Check if directory is a Laravel application
  local is_laravel=0
  if [ -f "$dir/artisan" ] || [ -f "$dir/public/index.php" ]; then
    is_laravel=1
  elif [ -d "$dir/public" ] && [ -d "$dir/storage" ]; then
    is_laravel=1
  elif [ -f "$dir/composer.json" ] && grep -qiE '"laravel/framework"' "$dir/composer.json" 2>/dev/null; then
    is_laravel=1
  fi

  [ "$is_laravel" -eq 1 ] || return 0

  local pub_dir="$dir/public"
  local storage_pub="$dir/storage/app/public"
  local pub_storage="$pub_dir/storage"

  # 1. Guarantee complete Laravel storage directory structure
  mkdir -p "$storage_pub" \
           "$dir/storage/framework/cache/data" \
           "$dir/storage/framework/sessions" \
           "$dir/storage/framework/views" \
           "$dir/storage/logs" \
           "$dir/bootstrap/cache" 2>/dev/null || true

  [ -d "$pub_dir" ] || return 0

  # 2. If public/storage is a physical directory (not a symlink), preserve files by moving into storage/app/public
  if [ -d "$pub_storage" ] && [ ! -L "$pub_storage" ]; then
    log "Notice: Migrating existing files from public/storage directory to storage/app/public in '$dir'..."
    cp -rn "$pub_storage"/* "$storage_pub"/ 2>/dev/null || true
    rm -rf "$pub_storage" 2>/dev/null || true
  fi

  # 3. If public/storage is a symlink, check if broken, obsolete, or pointing to a temporary staging path
  if [ -L "$pub_storage" ]; then
    local cur_target; cur_target="$(readlink "$pub_storage" 2>/dev/null || true)"
    if [ ! -e "$pub_storage" ] || [[ "$cur_target" == *".new."* ]] || [[ "$cur_target" == *"/tmp/"* ]] || [[ "$cur_target" == *"$STAGE_DIR"* ]]; then
      rm -f "$pub_storage" 2>/dev/null || true
    fi
  fi

  # 4. If artisan is runnable with real framework (not a placeholder shim), invoke artisan storage:link --force
  if [ -f "$dir/artisan" ] && [ ! -L "$pub_storage" ]; then
    if [ -f "$dir/vendor/autoload.php" ] && ! grep -q 'WTC Pi Lightweight' "$dir/vendor/autoload.php" 2>/dev/null; then
      local sname; sname="$(basename "$dir")"
      [ "$sname" = "webroot" ] && sname="$(active_site 2>/dev/null || echo "")"
      if [ -n "$sname" ] && valid_name "$sname" 2>/dev/null; then
        db_write_files "$sname" "$dir" 2>/dev/null || true
      fi
      su -s /bin/bash "${WEB_USER:-www-data}" -c "cd '$dir' && php artisan storage:link --force" >> /var/log/wtc-web/service.log 2>&1 || true
    fi
  fi

  # 5. Deterministic verification: enforce relative symlink public/storage -> ../storage/app/public
  # Absolute symlinks are replaced with relative symlinks so archiving and moving never break
  local link_target; link_target="$(readlink "$pub_storage" 2>/dev/null || true)"
  if [ ! -L "$pub_storage" ] || [ ! -e "$pub_storage" ] || [[ "$link_target" == /* ]]; then
    rm -rf "$pub_storage" 2>/dev/null || true
    ( cd "$pub_dir" && ln -sfn ../storage/app/public storage ) 2>/dev/null || ln -sfn "$storage_pub" "$pub_storage" 2>/dev/null || true
  fi

  # 6. Ensure appropriate permissions and ownership for web server and php-fpm
  local u="${WEB_USER:-www-data}"
  chown -h "$u:$u" "$pub_storage" 2>/dev/null || true
  chown -R "$u:$u" "$dir/storage" "$dir/bootstrap/cache" 2>/dev/null || true
  chmod -R 775 "$dir/storage" "$dir/bootstrap/cache" 2>/dev/null || true
  [ -d "$pub_storage" ] && chmod 775 "$pub_storage" 2>/dev/null || true
}

# Rebuild Ziggy JS/TS route definitions if the Laravel application uses tightenco/ziggy.
# Ensures generated route files (e.g. resources/js/ziggy.js) match the current APP_URL and routes.
rebuild_laravel_ziggy() {
  local dir="${1:-}" out_target="${2:-/var/log/wtc-web/service.log}"
  [ -n "$dir" ] && [ -d "$dir" ] || return 0
  [ -f "$dir/artisan" ] || return 0
  [ -f "$dir/vendor/autoload.php" ] || return 0
  ! grep -q 'WTC Pi Lightweight' "$dir/vendor/autoload.php" 2>/dev/null || return 0
  command -v php >/dev/null 2>&1 || return 0

  local u="${WEB_USER:-www-data}"
  local has_ziggy=0
  if [ -d "$dir/vendor/tightenco/ziggy" ]; then
    has_ziggy=1
  elif [ -f "$dir/composer.json" ] && grep -qi '"tightenco/ziggy"' "$dir/composer.json" 2>/dev/null; then
    su -s /bin/bash "$u" -c "cd $(printf '%q' "$dir") && php artisan list --raw" 2>/dev/null | grep -qx 'ziggy:generate' && has_ziggy=1
  fi
  [ "$has_ziggy" -eq 1 ] || return 0

  [ -d "$dir/storage" ] && chown -R "$u:$u" "$dir/storage" 2>/dev/null || true
  [ -d "$dir/bootstrap/cache" ] && chown -R "$u:$u" "$dir/bootstrap/cache" 2>/dev/null || true
  [ -d "$dir/resources/js" ] && chown -R "$u:$u" "$dir/resources/js" 2>/dev/null || true

  # Clear stale cached config/routes so Ziggy reads current .env APP_URL and routes
  rm -f "$dir/bootstrap/cache/"*.php 2>/dev/null || true

  local z_out="" z_rc=0 z_ran=0
  if [ -f "$dir/tsconfig.json" ] || [ -f "$dir/resources/js/ziggy.d.ts" ]; then
    # Try --types first (Ziggy v2+); fall back to plain ziggy:generate on older versions
    if z_out="$(su -s /bin/bash "$u" -c "cd $(printf '%q' "$dir") && php artisan ziggy:generate --types" 2>&1)"; then
      z_ran=1
      log "Rebuilt Ziggy routes + TypeScript types in '$dir'"
    fi
  fi

  if [ "$z_ran" -eq 0 ]; then
    z_out="$(su -s /bin/bash "$u" -c "cd $(printf '%q' "$dir") && php artisan ziggy:generate" 2>&1)" || z_rc=$?
    [ "$z_rc" -eq 0 ] && log "Rebuilt Ziggy routes in '$dir'"
  fi

  if [ -n "$z_out" ]; then
    if [ "$out_target" = "stdout" ]; then
      printf '%s\n' "$z_out"
    else
      printf '%s\n' "$z_out" >> "$out_target"
    fi
  fi
  return "$z_rc"
}

# Configure Laravel / PHP application .env for subpath or dedicated port hosting
# Ensures APP_URL, ASSET_URL, SESSION_COOKIE, and SESSION_PATH align with instance URL and port
configure_site_env() {
  local site_dir="$1" slug="$2" port="${3:-}" site_name="${4:-}"
  [ -n "$site_dir" ] && [ -d "$site_dir" ] || return 0
  [ -f "$site_dir/.env" ] || return 0

  local host_ip
  host_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  [ -n "$host_ip" ] || host_ip="127.0.0.1"

  # Extract host / protocol from existing APP_URL if valid, else fallback to host IP
  local cur_app_url proto host_base
  cur_app_url="$(grep -E '^APP_URL=' "$site_dir/.env" 2>/dev/null | cut -d= -f2- | tr -d '"'\'' ' || true)"
  if [[ "$cur_app_url" =~ ^https?://([^/:]+) ]]; then
    proto="${cur_app_url%%://*}"
    host_base="${BASH_REMATCH[1]}"
  else
    proto="http"
    host_base="$host_ip"
  fi
  if [ "${FORCE_HTTPS:-no}" = "yes" ]; then
    proto="https"
  else
    proto="http"
  fi

  local target_app_url target_asset_url target_session_path
  if [ -n "$port" ] && [ "$port" != "0" ] && [ "$port" != "none" ] && [ "$port" != "null" ]; then
    target_app_url="${proto}://${host_base}:${port}"
    target_asset_url=""
    target_session_path="/"
  elif [ -n "$slug" ] && [ "$slug" != "root" ] && [ "$slug" != "primary" ]; then
    target_app_url="${proto}://${host_base}/${slug}"
    target_asset_url="/${slug}"
    target_session_path="/${slug}"
  else
    target_app_url="${proto}://${host_base}"
    target_asset_url=""
    target_session_path="/"
  fi

  local env_changed=0
  # 1. Update APP_URL
  local cur_raw_app_url=""
  cur_raw_app_url="$(grep -E '^APP_URL=' "$site_dir/.env" 2>/dev/null | head -n1 || true)"
  if [ "$cur_raw_app_url" != "APP_URL=\"$target_app_url\"" ]; then
    env_changed=1
    if grep -q '^APP_URL=' "$site_dir/.env"; then
      sed -i "s|^APP_URL=.*|APP_URL=\"$target_app_url\"|" "$site_dir/.env" 2>/dev/null || true
    else
      echo "APP_URL=\"$target_app_url\"" >> "$site_dir/.env"
    fi
  fi

  # 2. Update ASSET_URL
  local cur_raw_asset_url=""
  cur_raw_asset_url="$(grep -E '^ASSET_URL=' "$site_dir/.env" 2>/dev/null | head -n1 || true)"
  if [ -n "$cur_raw_asset_url" ]; then
    if [ "$cur_raw_asset_url" != "ASSET_URL=\"$target_asset_url\"" ]; then
      env_changed=1
      sed -i "s|^ASSET_URL=.*|ASSET_URL=\"$target_asset_url\"|" "$site_dir/.env" 2>/dev/null || true
    fi
  elif [ -n "$target_asset_url" ]; then
    env_changed=1
    echo "ASSET_URL=\"$target_asset_url\"" >> "$site_dir/.env"
  fi

  # 3. Update SESSION_COOKIE to avoid session collision between concurrent instances
  local sess_cookie="w_${slug:-root}_session"
  local cur_raw_sc=""
  cur_raw_sc="$(grep -E '^SESSION_COOKIE=' "$site_dir/.env" 2>/dev/null | head -n1 || true)"
  if [ "$cur_raw_sc" != "SESSION_COOKIE=\"$sess_cookie\"" ]; then
    env_changed=1
    if grep -q '^SESSION_COOKIE=' "$site_dir/.env"; then
      sed -i "s|^SESSION_COOKIE=.*|SESSION_COOKIE=\"$sess_cookie\"|" "$site_dir/.env" 2>/dev/null || true
    else
      echo "SESSION_COOKIE=\"$sess_cookie\"" >> "$site_dir/.env"
    fi
  fi

  # 4. Update SESSION_PATH
  local cur_raw_sp=""
  cur_raw_sp="$(grep -E '^SESSION_PATH=' "$site_dir/.env" 2>/dev/null | head -n1 || true)"
  if [ "$cur_raw_sp" != "SESSION_PATH=\"$target_session_path\"" ]; then
    env_changed=1
    if grep -q '^SESSION_PATH=' "$site_dir/.env"; then
      sed -i "s|^SESSION_PATH=.*|SESSION_PATH=\"$target_session_path\"|" "$site_dir/.env" 2>/dev/null || true
    else
      echo "SESSION_PATH=\"$target_session_path\"" >> "$site_dir/.env"
    fi
  fi

  # 5. Update APP_NAME if provided
  if [ -n "$site_name" ]; then
    local cur_raw_an=""
    cur_raw_an="$(grep -E '^APP_NAME=' "$site_dir/.env" 2>/dev/null | head -n1 || true)"
    if [ -n "$cur_raw_an" ] && [ "$cur_raw_an" != "APP_NAME=\"$site_name\"" ]; then
      env_changed=1
      sed -i "s|^APP_NAME=.*|APP_NAME=\"$site_name\"|" "$site_dir/.env" 2>/dev/null || true
    fi
  fi

  # 6. Clear Laravel configuration caches and rebuild Ziggy routes when .env changed
  if [ "$env_changed" -eq 1 ] && [ -f "$site_dir/artisan" ] && [ -f "$site_dir/vendor/autoload.php" ]; then
    local u="${WEB_USER:-www-data}"
    su -s /bin/bash "$u" -c "cd '$site_dir' && php artisan config:clear" >> /var/log/wtc-web/service.log 2>&1 || true
    su -s /bin/bash "$u" -c "cd '$site_dir' && php artisan route:clear" >> /var/log/wtc-web/service.log 2>&1 || true
    rebuild_laravel_ziggy "$site_dir" || true
  fi
}

# Save current SITES_DIR/<name>.tar.gz as a timestamped rollback snapshot in PREV_DIR/<name>/.
# Prunes oldest snapshots if ROLLBACK_KEEP > 0.
save_site_rollback() {
  local name="$1"
  local src="$SITES_DIR/$name.tar.gz"
  [ -f "$src" ] || return 0
  local rdir="$PREV_DIR/$name"
  mkdir -p "$rdir"
  local ts; ts="$(date '+%Y%m%d-%H%M%S')"
  local dest="$rdir/${ts}.tar.gz"
  local n=1
  while [ -e "$dest" ]; do
    dest="$rdir/${ts}-${n}.tar.gz"
    n=$((n + 1))
  done
  cp -f "$src" "$dest" 2>/dev/null || return 1
  chmod 0644 "$dest" 2>/dev/null || true

  local keep="${ROLLBACK_KEEP:-5}"
  if [[ "$keep" =~ ^[0-9]+$ ]] && [ "$keep" -gt 0 ]; then
    local count=0 f
    while IFS= read -r f; do
      [ -n "$f" ] || continue
      count=$((count + 1))
      if [ "$count" -gt "$keep" ]; then
        rm -f "$f" 2>/dev/null || true
      fi
    done < <(ls -1t "$rdir"/*.tar.gz 2>/dev/null || true)
  fi
}

# Write/update SITES_DIR/<name>.meta.json atomically from <dir> while preserving assigned port.
write_site_meta() {
  local name="$1" dir="$2"
  local meta_f="$SITES_DIR/$name.meta.json"
  local stack="unknown" hasBuild="false" cur_port="null"
  if [ -d "$dir" ]; then
    stack="$(detect_site_stack "$dir")"
    [ -f "$dir/package.json" ] && hasBuild="true"
  fi
  if [ -f "$meta_f" ] && command -v jq >/dev/null 2>&1; then
    cur_port="$(jq -r '.port // null' "$meta_f" 2>/dev/null || echo null)"
  fi
  if command -v jq >/dev/null 2>&1; then
    if [ -n "$cur_port" ] && [ "$cur_port" != "null" ]; then
      jq -nc --arg st "$stack" --argjson hb "$hasBuild" --argjson m "$(date +%s)" --argjson pt "$cur_port" \
        '{stack:$st, hasBuild:$hb, mtime:$m, port:$pt}' > "$meta_f.tmp" && mv -f "$meta_f.tmp" "$meta_f"
    else
      jq -nc --arg st "$stack" --argjson hb "$hasBuild" --argjson m "$(date +%s)" \
        '{stack:$st, hasBuild:$hb, mtime:$m}' > "$meta_f.tmp" && mv -f "$meta_f.tmp" "$meta_f"
    fi
  else
    cat > "$meta_f.tmp" <<EOF
{"stack":"$stack","hasBuild":$hasBuild,"mtime":$(date +%s)}
EOF
    mv -f "$meta_f.tmp" "$meta_f"
  fi
  chmod 0644 "$meta_f" 2>/dev/null || true
}

# Pack <dir> into SITES_DIR/<name>.tar.gz atomically, saving a timestamped rollback snapshot.
# Also writes cached metadata in SITES_DIR/<name>.meta.json for instant UI listing.
# Sets EXISTED=1 if the site already existed.
store_dir_as_site() {
  local name="$1" dir="$2" out
  mkdir -p "$SITES_DIR" "$PREV_DIR"
  ensure_laravel_storage_link "$dir"
  preserve_existing_site_configs "$name" "$dir"
  out="$(mktemp "$SITES_DIR/.new.XXXXXX")"
  local -a tar_cmd=(tar -czf)
  if command -v pigz >/dev/null 2>&1; then
    tar_cmd=(tar --use-compress-program=pigz -cf)
  fi
  if ! "${tar_cmd[@]}" "$out" -C "$dir" .; then rm -f "$out"; return 1; fi
  EXISTED=0
  if [ -f "$SITES_DIR/$name.tar.gz" ]; then
    EXISTED=1
    save_site_rollback "$name"
  fi
  chmod 0644 "$out"
  mv -f "$out" "$SITES_DIR/$name.tar.gz"

  write_site_meta "$name" "$dir"
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
  local v u
  v="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  if [ -n "$v" ] && systemctl list-unit-files --no-legend "php${v}-fpm.service" 2>/dev/null | grep -q .; then
    echo "php${v}-fpm.service"
    return 0
  fi
  systemctl list-unit-files --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | sort -rV | head -n1
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

    # Sync into Laravel / standard .env if present (upsert even if commented out or missing)
    if [ -f "$rel/.env" ]; then
      local ek ev
      for ek in "DB_CONNECTION=mysql" "DB_HOST=$DB_HOST" "DB_PORT=$DB_PORT" "DB_DATABASE=$DB_NAME" "DB_USERNAME=$DB_USER" "DB_PASSWORD=$DB_PASS"; do
        ev="${ek#*=}"; ek="${ek%%=*}"
        if grep -qE "^#?[[:space:]]*${ek}=" "$rel/.env" 2>/dev/null; then
          sed -i "s|^#*[[:space:]]*${ek}=.*|${ek}=${ev}|" "$rel/.env" 2>/dev/null || true
        else
          echo "${ek}=${ev}" >> "$rel/.env"
        fi
      done
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
  local slug_var="${slug//-/_}"
  mkdir -p "$(dirname "$out")"

  cat > "$out" <<EOF
# WTC Managed Multi-Site: $site (slug: $slug)
location = /$slug {
    return 301 /$slug/;
}

# Auto-rewrite for $slug:
# If a request (form submission, XHR, relative asset, or redirect follow) originated from
# this instance (identified by Referer: .../$slug/...) but lost the /$slug prefix
# (e.g. form action="/login", a href="/login", redirect to / or /dashboard),
# rewrite it back into /$slug/ so the request stays within this system.
set \$wtc_sub_${slug_var} "";
if (\$http_referer ~* ^https?://[^/]+/$slug(?:/|$)) {
    set \$wtc_sub_${slug_var} "R";
}
if (\$uri ~* ^/$slug(?:/|$)) {
    set \$wtc_sub_${slug_var} "";
}
if (\$uri ~* ^/(?:manage|api/wtc)) {
    set \$wtc_sub_${slug_var} "";
}
if (\$wtc_sub_${slug_var} = "R") {
    rewrite ^/(.*)\$ /$slug/\$1 last;
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
    sub_filter_once off;
    sub_filter_types text/html;
    sub_filter '<base href="/"' '<base href="/$slug/"';
    sub_filter '<base href=""' '<base href="/$slug/"';
    sub_filter '<head>' '<head><base href="/$slug/">';
    sub_filter '<HEAD>' '<HEAD><base href="/$slug/">';

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
        fastcgi_param REQUEST_URI \$document_uri\$is_args\$args;
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
    fastcgi_param REQUEST_URI \$document_uri\$is_args\$args;
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

# Sync /etc/nginx/wtc-force-https.conf with FORCE_HTTPS (yes|no) and ensure
# /etc/nginx/sites-available/wtc has port 443 SSL + include directive when enabled.
sync_force_https_conf() {
  local conf="/etc/nginx/wtc-force-https.conf"
  local crt="/etc/ssl/wtc-ui/wtc-ui.crt"
  local key="/etc/ssl/wtc-ui/wtc-ui.key"
  local vhost="/etc/nginx/sites-available/wtc"
  mkdir -p /etc/nginx

  if [ "${FORCE_HTTPS:-no}" = "yes" ] && [ -f "$crt" ] && [ -f "$key" ]; then
    cat > "$conf" <<'EOF'
if ($scheme = http) {
    return 302 https://$host$request_uri;
}
EOF
    if [ -f "$vhost" ] && ! grep -q "listen 443 ssl" "$vhost"; then
      sed -i '/listen \[::\]:8080;/a \    listen 443 ssl default_server;\n    listen [::]:443 ssl default_server;\n    ssl_certificate /etc/ssl/wtc-ui/wtc-ui.crt;\n    ssl_certificate_key /etc/ssl/wtc-ui/wtc-ui.key;\n    ssl_protocols TLSv1.2 TLSv1.3;' "$vhost" 2>/dev/null || true
    fi
    if [ -f "$vhost" ] && ! grep -q "wtc-force-https.conf" "$vhost"; then
      sed -i '/server_name _;/a \    include /etc/nginx/wtc-force-https.conf;' "$vhost" 2>/dev/null || true
    fi
  else
    cat > "$conf" <<'EOF'
# Force HTTPS disabled
EOF
  fi
  chmod 0644 "$conf" 2>/dev/null || true
}

# Generate dedicated port vhost config for a site (HTTP or HTTPS when FORCE_HTTPS=yes).
generate_site_port_nginx_conf() {
  local site="$1" slug="$2" port="$3" webroot="$4" out="$5"
  local crt="/etc/ssl/wtc-ui/wtc-ui.crt"
  local key="/etc/ssl/wtc-ui/wtc-ui.key"
  mkdir -p "$(dirname "$out")"

  if [ "${FORCE_HTTPS:-no}" = "yes" ] && [ -f "$crt" ] && [ -f "$key" ]; then
    cat > "$out" <<EOF
server {
    listen $port ssl;
    listen [::]:$port ssl;
    ssl_certificate $crt;
    ssl_certificate_key $key;
    ssl_protocols TLSv1.2 TLSv1.3;
    error_page 497 =302 https://\$host:\$server_port\$request_uri;
    server_name _;
    root $webroot;
    index index.php index.html index.htm;

    client_max_body_size 10240m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ^~ /storage/ {
        root $webroot;
        access_log off;
        expires 30d;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/wtc-site.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\. { deny all; }
}
EOF
  else
    cat > "$out" <<EOF
server {
    listen $port;
    listen [::]:$port;
    server_name _;
    root $webroot;
    index index.php index.html index.htm;

    client_max_body_size 10240m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ^~ /storage/ {
        root $webroot;
        access_log off;
        expires 30d;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/wtc-site.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\. { deny all; }
}
EOF
  fi
  chmod 0644 "$out" 2>/dev/null || true
}



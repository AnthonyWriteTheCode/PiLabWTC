#!/usr/bin/env bash
# 03-site-service.sh -> /usr/local/bin/wtc-site-service
# Manages the RAM disk, swaps the live site, ingests dropbox archives, saves state.
#
#   --boot            mount RAM disk, load the active site (nginx not reloaded; it starts after us)
#   --swap <site>     make <site> live and reload nginx
#   --reload          re-load the active site from its archive
#   --ingest          process archives in the dropbox (new site / update existing)
#   --save-state [s]  write the live RAM site back to its SD archive
#   --save-tick       periodic save (honours SAVE_INTERVAL_MIN)
#   --shutdown        save on shutdown (honours SAVE_ON_SHUTDOWN)
set -Eeuo pipefail
# shellcheck disable=SC1091
. "${WTC_LIB:-/usr/local/lib/wtc-web/common.sh}"

NO_RELOAD="${NO_RELOAD:-0}"

# ensure_ramdisk is provided by common.sh

restart_nginx() {
  [ "$NO_RELOAD" = "1" ] && return 0
  if systemctl is-active --quiet nginx; then
    systemctl restart nginx 2>/dev/null || systemctl reload nginx 2>/dev/null || true
  else
    systemctl start nginx 2>/dev/null || true
  fi
}

reload_nginx() {
  restart_nginx
}

placeholder_site() {
  local dir="$RELEASES_DIR/placeholder"
  mkdir -p "$dir"
  cat > "$dir/index.php" <<'EOF'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WTC Pi - Management Portal</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #0f1419; color: #e6e6e6; display: grid; place-items: center; min-height: 100vh; padding: 20px; }
    .card { background: #1a2129; border: 1px solid #2a3541; border-radius: 12px; padding: 32px; max-width: 520px; width: 100%; text-align: center; }
    h1 { font-size: 1.5rem; margin-bottom: 12px; color: #7cc4ff; }
    p { color: #9bb0c4; margin-bottom: 24px; font-size: 0.95rem; }
    .btn { display: block; background: #2b7de9; color: #fff; text-decoration: none; padding: 12px 20px; border-radius: 6px; font-weight: 600; margin-bottom: 12px; }
    .btn:hover { filter: brightness(1.15); }
    .btn-secondary { background: #2a3541; color: #ccc; }
  </style>
</head>
<body>
  <div class="card">
    <h1>WTC Pi Management Portal</h1>
    <p>No site is currently loaded in the RAM disk. Use the Management Utility to activate an application or drop an archive into the dropbox.</p>
    <a href="/manage/" class="btn">Open Management Utility</a>
    <a href="http://<?= $_SERVER['SERVER_NAME'] ?>:4485/" class="btn btn-secondary">Direct Port 4485</a>
  </div>
</body>
</html>
EOF
  chown -R "$WEB_USER:$WEB_USER" "$dir"
  ln -sfn "$dir" "$RAM_MOUNT/.www.new"
  mv -T "$RAM_MOUNT/.www.new" "$WWW_LINK"
  ln -sfn "$dir" "$RAM_MOUNT/.webroot.new"
  mv -T "$RAM_MOUNT/.webroot.new" "$RAM_MOUNT/webroot"
}
 
# ---------------------------------------------------------------- swap
# ---------------------------------------------------------------- multi-site activation & swap
activate_site() {
  local site="$1" make_primary="${2:-1}" archive rel slug site_dir webroot snippet_new ng_err
  valid_name "$site" || die "Invalid site name: $site"
  archive="$SITES_DIR/$site.tar.gz"
  [ -f "$archive" ] || die "Site archive not found: $archive"
  ensure_ramdisk

  slug="$(site_slug "$site")"
  [ -n "$slug" ] || slug="site"
  site_dir="$RAM_SITES_DIR/$slug"

  log "Activating site '$site' (slug: $slug) in RAM disk at $site_dir..."
  mkdir -p "$site_dir"
  # Unpack archive into dedicated site folder in RAM
  if ! tar -xf "$archive" -C "$site_dir" --no-same-owner --no-same-permissions; then
    rm -rf "$site_dir"; die "Failed to unpack $archive into $site_dir"
  fi

  # Check if React build / dist folder is present and promote
  for sub in dist build out; do
    if [ -d "$site_dir/$sub" ] && [ -f "$site_dir/$sub/index.html" ]; then
      cp -rn "$site_dir/$sub"/* "$site_dir"/ 2>/dev/null || true
      break
    fi
  done

  # If React/Node project with package.json build script and no built index.*, trigger background build
  if [ -f "$site_dir/package.json" ] && grep -q '"build"' "$site_dir/package.json" 2>/dev/null; then
    if [ ! -f "$site_dir/index.html" ] && [ ! -f "$site_dir/index.php" ]; then
      log "Site '$site' contains package.json build script - triggering background build job"
      /usr/local/bin/wtc-manage --build "$site" >/dev/null 2>&1 &
    fi
  fi

  # Auto-create database & user if enabled, and inject connection helpers
  db_ensure "$site" || log "Database ensure for '$site' failed or MariaDB not running"
  db_write_files "$site" "$site_dir"

  # Restore database if snapshot exists in the archive
  if db_available; then
    local db_dump_file=""
    if [ -f "$site_dir/.wtc-database.sql" ]; then
      db_dump_file="$site_dir/.wtc-database.sql"
    elif [ -f "$site_dir/.wtc-db.sql" ]; then
      db_dump_file="$site_dir/.wtc-db.sql"
    fi
    if [ -n "$db_dump_file" ]; then
      local db_id; db_id="$(db_ident "$site")"
      log "Restoring database snapshot from $(basename "$db_dump_file") into '$db_id'..."
      mariadb "$db_id" < "$db_dump_file" 2>/dev/null || log "Warning: Database restore into '$db_id' reported errors"
    fi
  fi

  # Check if Laravel application
  if [ -f "$site_dir/artisan" ] || [ -f "$site_dir/public/index.php" ]; then
    log "Laravel application detected for '$site' - preparing webroot & permissions"
    rm -f "$site_dir/public/hot" 2>/dev/null || true
    if [ ! -f "$site_dir/index.php" ]; then
      cat > "$site_dir/index.php" <<'EOF'
<?php
// WTC Pi Laravel Gateway
if (file_exists(__DIR__ . '/public/index.php')) {
    require_once __DIR__ . '/public/index.php';
}
EOF
    fi
    mkdir -p "$site_dir/storage/framework/cache/data" \
             "$site_dir/storage/framework/sessions" \
             "$site_dir/storage/framework/views" \
             "$site_dir/storage/logs" \
             "$site_dir/storage/app/public" \
             "$site_dir/bootstrap/cache"
    if [ ! -f "$site_dir/.env" ] && [ -f "$site_dir/.env.example" ]; then
      cp -n "$site_dir/.env.example" "$site_dir/.env"
      db_write_files "$site" "$site_dir"
    fi
    if [ -f "$site_dir/artisan" ]; then
      rm -f "$site_dir/public/storage" 2>/dev/null || true
      su -s /bin/bash "$WEB_USER" -c "php '$site_dir/artisan' storage:link --force" >> /var/log/wtc-web/service.log 2>&1 || true
      su -s /bin/bash "$WEB_USER" -c "php '$site_dir/artisan' optimize:clear" >> /var/log/wtc-web/service.log 2>&1 || true
    fi
  fi

  if [ ! -f "$site_dir/index.php" ]; then
    cat > "$site_dir/index.php" <<'EOF'
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
  chown -R "$WEB_USER:$WEB_USER" "$site_dir"
  chmod -R u=rwX,go=rX "$site_dir"
  [ -d "$site_dir/storage" ] && chmod -R 775 "$site_dir/storage" "$site_dir/bootstrap/cache" 2>/dev/null || true

  webroot="$site_dir"
  if [ -d "$site_dir/public" ] && { [ -f "$site_dir/artisan" ] || [ -f "$site_dir/public/index.php" ]; }; then
    webroot="$site_dir/public"
  fi

  # Generate dedicated Port 80 location blocks for /<slug>/
  generate_site_nginx_block "$site" "$slug" "$site_dir" "$webroot" "$RAM_NGINX_SITES/$slug.conf"

  # If this site should be the primary site (or no primary site exists)
  local cur_primary
  cur_primary="$(active_site)"
  if [ "$make_primary" = "1" ] || [ -z "$cur_primary" ] || [ ! -d "$RAM_SITES_DIR/$(site_slug "$cur_primary")" ]; then
    ln -sfn "$webroot" "$RAM_MOUNT/.webroot.new"
    mv -T "$RAM_MOUNT/.webroot.new" "$RAM_MOUNT/webroot"
    rm -f "$WWW_LINK" "$RAM_MOUNT/.www.new" 2>/dev/null || true
    local stack; stack="$(detect_site_stack "$site_dir")"
    generate_stack_nginx_conf "$stack" "$site_dir" "$RAM_NGINX_CONF"
    ln -sfn "$RAM_NGINX_CONF" "$NGINX_SNIPPET" 2>/dev/null || true
    echo "$site" > "$STATE_FILE"
    log "Set primary site to '$site' (slug: $slug)"
  fi

  # Test Nginx if not during boot (when nginx starts afterwards)
  if [ "$NO_RELOAD" != "1" ]; then
    if ng_err="$(nginx -t 2>&1)"; then
      restart_nginx
    else
      log "nginx -t failed for '$site' - reverting. Details: $ng_err"
      rm -f "$RAM_NGINX_SITES/$slug.conf"
      rm -rf "$site_dir"
      die "Activation of '$site' aborted: invalid nginx configuration"
    fi
  fi

  add_active_site "$site"
  date +%s > "$SAVE_STAMP"
  log "Site '$site' is now live at /$slug/ and active in RAM"
}

deactivate_site() {
  local site="$1" slug site_dir
  valid_name "$site" || die "Invalid site name: $site"
  slug="$(site_slug "$site")"
  site_dir="$RAM_SITES_DIR/$slug"

  log "Deactivating site '$site' (slug: $slug) from RAM..."
  if [ "$SAVE_ON_SWITCH" = yes ] && [ "$NO_RELOAD" != 1 ] && [ -d "$site_dir" ]; then
    save_state "$site" || log "Save-state before deactivation failed for '$site'"
  fi

  rm -f "$RAM_NGINX_SITES/$slug.conf"
  rm -rf "$site_dir"
  remove_active_site "$site"

  # If deactivated site was the primary site, pick another active site or fallback to placeholder
  local cur_primary
  cur_primary="$(active_site)"
  if [ "$cur_primary" = "$site" ]; then
    local next_site; next_site="$(list_active_sites | head -n1 || true)"
    if [ -n "$next_site" ] && [ -d "$RAM_SITES_DIR/$(site_slug "$next_site")" ]; then
      local nslug nwebroot nsite_dir
      nslug="$(site_slug "$next_site")"
      nsite_dir="$RAM_SITES_DIR/$nslug"
      nwebroot="$nsite_dir"
      [ -d "$nsite_dir/public" ] && nwebroot="$nsite_dir/public"
      ln -sfn "$nwebroot" "$RAM_MOUNT/.webroot.new"
      mv -T "$RAM_MOUNT/.webroot.new" "$RAM_MOUNT/webroot"
      ln -sfn "$nsite_dir" "$RAM_MOUNT/.www.new"
      mv -T "$RAM_MOUNT/.www.new" "$WWW_LINK"
      local nstack; nstack="$(detect_site_stack "$nsite_dir")"
      generate_stack_nginx_conf "$nstack" "$nsite_dir" "$RAM_NGINX_CONF"
      echo "$next_site" > "$STATE_FILE"
      log "Primary active site rotated to '$next_site'"
    else
      log "No remaining active sites - falling back to placeholder"
      placeholder_site
      rm -f "$STATE_FILE"
    fi
  fi

  restart_nginx
  log "Deactivated site '$site'"
}

swap_site() {
  local site="$1"
  activate_site "$site" 1
}

# Swap a staged directory in RAM directly into www without tar/untar
swap_dir() {
  local site="$1" dir="$2" rel snippet_new old_rel old_snip
  valid_name "$site" || die "Invalid site name: $site"
  [ -d "$dir" ] || die "Directory not found: $dir"
  ensure_ramdisk

  rel="$RELEASES_DIR/$(date +%s)-$site"
  mkdir -p "$rel"
  cp -a "$dir"/. "$rel"/

  # Check if React build / dist folder is present and promote
  for sub in dist build out; do
    if [ -d "$rel/$sub" ] && [ -f "$rel/$sub/index.html" ]; then
      cp -rn "$rel/$sub"/* "$rel"/ 2>/dev/null || true
      break
    fi
  done

  # Auto-create database & user if enabled, and inject connection helpers
  db_ensure "$site" || log "Database ensure for '$site' failed or MariaDB not running"
  db_write_files "$site" "$rel"

  # Restore database if snapshot exists in the staged directory
  if db_available; then
    local db_dump_file=""
    if [ -f "$rel/.wtc-database.sql" ]; then
      db_dump_file="$rel/.wtc-database.sql"
    elif [ -f "$rel/.wtc-db.sql" ]; then
      db_dump_file="$rel/.wtc-db.sql"
    fi
    if [ -n "$db_dump_file" ]; then
      local db_id; db_id="$(db_ident "$site")"
      log "Restoring database snapshot from $(basename "$db_dump_file") into '$db_id'..."
      mariadb "$db_id" < "$db_dump_file" 2>/dev/null || log "Warning: Database restore into '$db_id' reported errors"
    fi
  fi

  # Check if Laravel application
  if [ -f "$rel/artisan" ] || [ -f "$rel/public/index.php" ]; then
    log "Laravel application detected for '$site' - preparing webroot & permissions"
    if [ ! -f "$rel/index.php" ]; then
      cat > "$rel/index.php" <<'EOF'
<?php
// WTC Pi Laravel Gateway
if (file_exists(__DIR__ . '/public/index.php')) {
    require_once __DIR__ . '/public/index.php';
}
EOF
    fi
    mkdir -p "$rel/storage/framework/cache/data" \
             "$rel/storage/framework/sessions" \
             "$rel/storage/framework/views" \
             "$rel/storage/logs" \
             "$rel/bootstrap/cache"
    if [ ! -f "$rel/.env" ] && [ -f "$rel/.env.example" ]; then
      cp -n "$rel/.env.example" "$rel/.env"
      db_write_files "$site" "$rel"
    fi
    if [ -f "$rel/artisan" ]; then
      su -s /bin/bash "$WEB_USER" -c "php '$rel/artisan' storage:link" >> /var/log/wtc-web/service.log 2>&1 || true
      su -s /bin/bash "$WEB_USER" -c "php '$rel/artisan' optimize:clear" >> /var/log/wtc-web/service.log 2>&1 || true
    fi
  fi

  # Determine stack type and configure Nginx snippet in RAM drive
  local stack
  stack="$(detect_site_stack "$rel")"
  snippet_new="$RAM_MOUNT/.snippet.new"
  if [ -f "$rel/nginx.conf" ]; then
    log "Site '$site' provides custom nginx.conf"
    mv "$rel/nginx.conf" "$snippet_new"
  else
    log "Configuring Nginx in RAM disk for stack: '$stack' ($site)"
    generate_stack_nginx_conf "$stack" "$rel" "$snippet_new"
  fi

  if [ ! -f "$rel/index.php" ]; then
    cat > "$rel/index.php" <<'EOF'
<?php
// WTC Gateway for Active RAM Disk Site
if (file_exists(__DIR__ . '/public/index.php')) {
    require_once __DIR__ . '/public/index.php';
} elseif (file_exists(__DIR__ . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
} elseif (file_exists(__DIR__ . '/public/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/public/index.html');
} else {
    http_response_code(404);
    echo "WTC: No index file found in active RAM site.";
}
EOF
  fi
  chown -R "$WEB_USER:$WEB_USER" "$rel"
  chmod -R u=rwX,go=rX "$rel"
  [ -d "$rel/storage" ] && chmod -R 775 "$rel/storage" "$rel/bootstrap/cache" 2>/dev/null || true

  old_rel="$(readlink -f "$WWW_LINK" 2>/dev/null || true)"
  old_snip="$RAM_MOUNT/.snippet.old"
  if [ -f "$RAM_NGINX_CONF" ]; then cp -f "$RAM_NGINX_CONF" "$old_snip"; else : > "$old_snip"; fi

  # Establish correct project webroot (Laravel public/ directory vs standard site root)
  local webroot="$rel"
  if [ -d "$rel/public" ] && { [ -f "$rel/artisan" ] || [ -f "$rel/public/index.php" ]; }; then
    webroot="$rel/public"
    log "Setting project webroot to Laravel public/ directory: $webroot"
  fi
  ln -sfn "$webroot" "$RAM_MOUNT/.webroot.new"
  mv -T "$RAM_MOUNT/.webroot.new" "$RAM_MOUNT/webroot"

  ln -sfn "$rel" "$RAM_MOUNT/.www.new"
  mv -T "$RAM_MOUNT/.www.new" "$WWW_LINK"
  install -m 0644 "$snippet_new" "$RAM_NGINX_CONF"
  ln -sfn "$RAM_NGINX_CONF" "$NGINX_SNIPPET" 2>/dev/null || true

  local ng_err
  if ng_err="$(nginx -t 2>&1)"; then
    restart_nginx
  else
    log "nginx -t failed for '$site' - reverting. Details: $ng_err"
    install -m 0644 "$old_snip" "$RAM_NGINX_CONF"
    ln -sfn "$RAM_NGINX_CONF" "$NGINX_SNIPPET" 2>/dev/null || true
    if [ -n "$old_rel" ] && [ -d "$old_rel" ]; then
      ln -sfn "$old_rel" "$RAM_MOUNT/.www.new"
      mv -T "$RAM_MOUNT/.www.new" "$WWW_LINK"
      local old_webroot="$old_rel"
      [ -d "$old_rel/public" ] && old_webroot="$old_rel/public"
      ln -sfn "$old_webroot" "$RAM_MOUNT/.webroot.new"
      mv -T "$RAM_MOUNT/.webroot.new" "$RAM_MOUNT/webroot"
    fi
    rm -rf "$rel"
    die "Swap to '$site' aborted: invalid nginx configuration"
  fi

  find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 ! -path "$rel" -exec rm -rf {} +
  rm -f "$snippet_new" "$old_snip"
  echo "$site" > "$STATE_FILE"
  date +%s > "$SAVE_STAMP"
  log "Active site is now '$site' (direct RAM swap, stack: $stack)"
  if [ -x /usr/local/bin/wtc-workers ]; then
    /usr/local/bin/wtc-workers restart >> /var/log/wtc-web/service.log 2>&1 || true
  fi
}

boot() {
  ensure_ramdisk
  reload_ui_ram

  # On boot: clean up any stale nginx-sites snippets so only primary is loaded
  rm -f "$RAM_NGINX_SITES"/*.conf 2>/dev/null || true

  local primary
  primary="$(active_site)"
  if [ -z "$primary" ] || [ ! -f "$SITES_DIR/$primary.tar.gz" ]; then
    primary="$(list_sites | head -n1 || true)"
  fi

  if [ -n "$primary" ] && [ -f "$SITES_DIR/$primary.tar.gz" ]; then
    log "Booting UI and primary site: '$primary'"
    mkdir -p "$(dirname "$ACTIVE_SITES_FILE")"
    echo "$primary" > "$ACTIVE_SITES_FILE"
    echo "$primary" > "$STATE_FILE"
    NO_RELOAD=1 activate_site "$primary" 1 || log "Boot activation for primary site '$primary' failed"
  else
    log "No sites available on boot - serving placeholder"
    rm -f "$ACTIVE_SITES_FILE" 2>/dev/null || true
    generate_stack_nginx_conf "php" "" "$RAM_NGINX_CONF"
    ln -sfn "$RAM_NGINX_CONF" "$NGINX_SNIPPET" 2>/dev/null || true
    placeholder_site
  fi
}

# ---------------------------------------------------------------- store / save
# (store_dir_as_site is provided by common.sh)

# Snapshot the live RAM site back to SD. If the site ships a wtc.json with
# {"persist": ["storage","data"]} only those paths are saved; otherwise the whole site.
save_state() {
  local site="${1:-$(active_site)}" live tmp archive persist p slug
  valid_name "$site" || die "No valid site to save: $site"
  archive="$SITES_DIR/$site.tar.gz"
  [ -f "$archive" ] || die "Archive missing: $archive"

  slug="$(site_slug "$site")"
  if [ -d "$RAM_SITES_DIR/$slug" ]; then
    live="$RAM_SITES_DIR/$slug"
  else
    live="$(readlink -f "$WWW_LINK" 2>/dev/null || true)"
  fi
  [ -d "$live" ] || die "Site '$site' is not active in RAM disk"

  tmp="$(mktemp -d "$RAM_MOUNT/.save.XXXXXX")"
  tar -xf "$archive" -C "$tmp" --no-same-owner --no-same-permissions
  persist=""
  if [ -f "$live/wtc.json" ] && command -v jq >/dev/null; then
    persist="$(jq -r '.persist[]? // empty' "$live/wtc.json" 2>/dev/null || true)"
  fi
  if [ -n "$persist" ]; then
    while IFS= read -r p; do
      if ! [[ "$p" =~ ^[A-Za-z0-9._/-]+$ ]] || [[ "$p" == *..* ]] || [[ "$p" == /* ]]; then
        log "Ignoring unsafe persist path '$p'"; continue
      fi
      [ -e "$live/$p" ] || continue
      mkdir -p "$(dirname "$tmp/$p")"
      rm -rf "${tmp:?}/$p"
      cp -a --no-preserve=ownership "$live/$p" "$tmp/$p"
    done <<< "$persist"
  else
    rsync -rlpt --delete --exclude=/nginx.conf "$live"/ "$tmp"/
  fi

  # Snapshot MariaDB database from RAM into the package
  if db_available; then
    log "Dumping database for '$site' to .wtc-database.sql..."
    if db_dump "$site" "$tmp/.wtc-database.sql"; then
      chmod 0640 "$tmp/.wtc-database.sql" 2>/dev/null || true
      # Also update live site copy in RAM so it reflects current state
      cp -f "$tmp/.wtc-database.sql" "$live/.wtc-database.sql" 2>/dev/null || true
    else
      log "Warning: Database dump for '$site' failed or database is empty"
    fi
  fi

  if store_dir_as_site "$site" "$tmp"; then
    log "Saved state of '$site' to SD"
  else
    rm -rf "$tmp"; die "Failed to save state of '$site'"
  fi
  rm -rf "$tmp"
  date +%s > "$SAVE_STAMP"
}

save_tick() {
  [ "$SAVE_INTERVAL_MIN" -gt 0 ] 2>/dev/null || return 0
  [ -n "$(active_site)" ] || return 0
  local last now; last="$(cat "$SAVE_STAMP" 2>/dev/null || echo 0)"; now="$(date +%s)"
  if [ $((now - last)) -ge $((SAVE_INTERVAL_MIN * 60)) ]; then save_state; fi
}

# ---------------------------------------------------------------- dropbox ingest
reject() { # <file> <reason>
  local f="$1" reason="$2" dest
  dest="$REJECT_DIR/$(date +%Y%m%d-%H%M%S)-$(basename "$f")"
  mkdir -p "$REJECT_DIR"
  mv -f "$f" "$dest" 2>/dev/null || rm -f "$f"
  echo "$reason" > "$dest.error"
  rm -f "$f.sha256"
  log "REJECTED $(basename "$f"): $reason"
}

wait_stable() { # wait until an upload stops growing
  local f="$1" last=-1 cur n=0 waited=0
  while :; do
    cur="$(stat -c %s "$f" 2>/dev/null)" || return 1
    if [ "$cur" = "$last" ]; then n=$((n + 1)); [ "$n" -ge 2 ] && return 0; else n=0; last="$cur"; fi
    sleep 2; waited=$((waited + 2))
    [ "$waited" -gt 900 ] && return 1
  done
}

site_name_for() {
  local n="$1"
  n="${n%.tar.gz}"; n="${n%.tgz}"; n="${n%.tar}"; n="${n%.zip}"
  n="$(echo "$n" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9_-]+/-/g; s/^-+//; s/-+$//')"
  echo "${n:0:63}"
}

ingest_one() {
  local f="$1" base name tmp ext listing extracted entries only
  base="$(basename "$f")"
  name="$(site_name_for "$base")"
  valid_name "$name" || { reject "$f" "Cannot derive a valid site name from '$base'"; return; }
  case "$base" in
    *.tar.gz|*.tgz|*.tar) ext=tar ;;
    *.zip) ext=zip ;;
    *) reject "$f" "Unsupported file type"; return ;;
  esac

  if [ -f "$f.sha256" ]; then
    wait_stable "$f.sha256" || true
    local want got
    want="$(awk '{print $1; exit}' "$f.sha256")"
    got="$(sha256sum "$f" | awk '{print $1}')"
    [ "$want" = "$got" ] || { reject "$f" "SHA256 mismatch"; return; }
    rm -f "$f.sha256"
  fi

  if [ "$ext" = tar ]; then
    listing="$(tar -tf "$f" 2>/dev/null)" || { reject "$f" "Not a valid tar archive"; return; }
  else
    listing="$(unzip -Z1 "$f" 2>/dev/null)" || { reject "$f" "Not a valid zip archive"; return; }
  fi
  if echo "$listing" | grep -Eq '(^|/)\.\.(/|$)|^/'; then
    reject "$f" "Archive contains absolute or '..' paths"; return
  fi

  tmp="$(mktemp -d "$RAM_MOUNT/.ingest.XXXXXX")"
  extracted="$tmp/x"; mkdir -p "$extracted"
  if [ "$ext" = tar ]; then
    tar -xf "$f" -C "$extracted" --no-same-owner --no-same-permissions || { rm -rf "$tmp"; reject "$f" "Extraction failed"; return; }
  else
    unzip -q "$f" -d "$extracted" || { rm -rf "$tmp"; reject "$f" "Extraction failed"; return; }
  fi

  if [ -n "$(find "$extracted" -type l -print -quit)" ]; then
    rm -rf "$tmp"; reject "$f" "Archive contains symlinks"; return
  fi
  if [ "$(du -sm "$extracted" | awk '{print $1}')" -gt "$DROP_MAX_MB" ]; then
    rm -rf "$tmp"; reject "$f" "Extracted size exceeds ${DROP_MAX_MB}MB"; return
  fi

  entries="$(find "$extracted" -mindepth 1 -maxdepth 1 | wc -l)"
  if [ "$entries" -eq 1 ]; then
    only="$(find "$extracted" -mindepth 1 -maxdepth 1)"
    [ -d "$only" ] && extracted="$only"
  fi
  if [ -f "$extracted/public/index.php" ]; then
    if [ ! -f "$extracted/index.php" ]; then
      log "Laravel / public webroot detected for '$name' - creating entrypoint forwarder"
      cat > "$extracted/index.php" <<'EOF'
<?php
// WTC auto-forwarder to public/ webroot
require_once __DIR__ . '/public/index.php';
EOF
    fi
    if [ -f "$extracted/.env.example" ] && [ ! -f "$extracted/.env" ]; then
      cp "$extracted/.env.example" "$extracted/.env"
      local db_id; db_id="$(db_ident "$name")"
      sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$db_id|" "$extracted/.env" 2>/dev/null || true
      sed -i "s|^DB_USERNAME=.*|DB_USERNAME=$db_id|" "$extracted/.env" 2>/dev/null || true
    fi
    # If only public/ was provided and bootstrap/app.php or vendor/autoload.php is missing,
    # generate compatibility shims so public/index.php does not fatal error.
    if [ ! -f "$extracted/bootstrap/app.php" ] || [ ! -f "$extracted/vendor/autoload.php" ]; then
      mkdir -p "$extracted/bootstrap" "$extracted/vendor"
      cat > "$extracted/vendor/autoload.php" <<'EOF'
<?php
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
EOF
      cat > "$extracted/bootstrap/app.php" <<'EOF'
<?php
return new class {
    public function handleRequest($request) {
        $site = basename(dirname(__DIR__));
        $phpv = PHP_VERSION;
        $dbName = 'w_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($site));
        $dbStatus = 'Not Connected';
        $dbTables = [];
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
        <!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= htmlspecialchars($site) ?> - Laravel Webroot</title>
        <style>body{font-family:sans-serif;background:#0f1419;color:#e6e6e6;padding:40px 20px}.card{background:#1a2129;border:1px solid #2a3541;border-radius:10px;padding:24px;max-width:800px;margin:0 auto}h1{color:#ff2d20}code{background:#0a0d11;color:#a5d6ff;padding:2px 6px;border-radius:4px}.pill{background:#1d6b3a;color:#fff;padding:3px 10px;border-radius:10px;font-size:0.8rem}.tag{display:inline-block;background:#2a3541;color:#7ee787;padding:3px 8px;border-radius:4px;margin:2px;font-family:monospace}</style></head>
        <body><div class="card"><h1>🅰️ Laravel Public Webroot</h1><p><span class="pill">Active in RAM</span> PHP <?= $phpv ?> &bull; DB: <b><?= htmlspecialchars($dbName) ?></b> (<?= htmlspecialchars($dbStatus) ?>)</p><p style="margin-top:12px">Served from tmpfs RAM disk. Missing backend application files (<code>app/</code>, <code>bootstrap/</code>) were substituted with WTC compatibility shim.</p>
        <h3 style="margin-top:18px">🗄️ Database Tables (<?= count($dbTables) ?>)</h3>
        <?php if (!empty($dbTables)): foreach($dbTables as $t): ?><span class="tag"><?= htmlspecialchars($t) ?></span><?php endforeach; else: ?><p style="color:#8b949e">No tables imported yet. Drop a <code>.sql</code> file into the UI to populate this database.</p><?php endif; ?>
        <p style="margin-top:20px"><a href="/?p=transfer" style="color:#7cc4ff">&larr; Back to WTC Device Portal</a></p></div></body></html>
        <?php
    }
};
EOF
    fi

    # Ensure storage skeleton exists
    if [ ! -d "$extracted/storage" ]; then
      mkdir -p "$extracted/storage/framework/views" "$extracted/storage/framework/sessions" \
               "$extracted/storage/framework/cache" "$extracted/storage/logs" "$extracted/bootstrap/cache"
    fi
  fi

  # Check if React build / dist folder is present and promote
  for sub in dist build out; do
    if [ -d "$extracted/$sub" ] && [ -f "$extracted/$sub/index.html" ]; then
      log "React/SPA build folder '$sub' detected in '$name' - promoting to root"
      cp -rn "$extracted/$sub"/* "$extracted"/ 2>/dev/null || true
      break
    fi
  done

  # If SPA with index.html but no index.php, provide SPA router bridge
  if [ -f "$extracted/index.html" ] && [ ! -f "$extracted/index.php" ]; then
    cat > "$extracted/index.php" <<'EOF'
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
    cat > "$extracted/nginx.conf" <<'EOF'
location / {
    try_files $uri $uri/ /index.html /index.php?$query_string;
}
EOF
  fi

  if [ -z "$(find "$extracted" -maxdepth 1 -name 'index.*' -print -quit)" ]; then
    rm -rf "$tmp"; reject "$f" "No index.* file found at the site root"; return
  fi

  if ! store_dir_as_site "$name" "$extracted"; then
    rm -rf "$tmp"; reject "$f" "Failed to repack"; return
  fi
  rm -rf "$tmp"

  if [ "$KEEP_PROCESSED" = yes ]; then
    mkdir -p "$PROCESSED_DIR"
    mv -f "$f" "$PROCESSED_DIR/" 2>/dev/null || rm -f "$f"
    # keep only the 5 newest processed files (RAM is limited)
    find "$PROCESSED_DIR" -maxdepth 1 -type f -printf '%T@ %p\n' | sort -rn | tail -n +6 | cut -d' ' -f2- | xargs -r -d '\n' rm -f
  else
    rm -f "$f"
  fi

  if [ "$EXISTED" -eq 1 ]; then
    log "UPDATED site '$name' from $base"
    if [ "$AUTO_RELOAD_ACTIVE" = yes ] && [ "$(active_site)" = "$name" ]; then
      "$SERVICE_BIN" --swap "$name" || log "Hot reload of '$name' failed"
    fi
  else
    log "NEW site '$name' from $base"
    if [ "$AUTO_ACTIVATE_NEW" = yes ]; then
      "$SERVICE_BIN" --swap "$name" || log "Auto-activate of '$name' failed"
    fi
  fi
}

wait_dir_stable() { # wait until a dropped directory stops receiving file changes
  local d="$1" last=-1 cur n=0 waited=0
  while :; do
    cur="$(find "$d" -type f -printf '%s+' 2>/dev/null; echo 0)"
    if [ "$cur" = "$last" ]; then
      n=$((n + 1)); [ "$n" -ge 2 ] && return 0
    else
      n=0; last="$cur"
    fi
    sleep 2; waited=$((waited + 2))
    [ "$waited" -gt 600 ] && return 1
  done
}

ingest_dir() {
  local dir="$1" base name
  base="$(basename "$dir")"
  name="$(site_name_for "$base")"
  valid_name "$name" || { reject "$dir" "Cannot derive a valid site name from folder '$base'"; return; }

  wait_dir_stable "$dir" || { reject "$dir" "Folder transfer did not complete"; return; }

  if [ "$(du -sm "$dir" | awk '{print $1}')" -gt "$DROP_MAX_MB" ]; then
    reject "$dir" "Folder size exceeds ${DROP_MAX_MB}MB"; return
  fi

  local extracted="$dir" only entries
  entries="$(find "$extracted" -mindepth 1 -maxdepth 1 | wc -l)"
  if [ "$entries" -eq 1 ]; then
    only="$(find "$extracted" -mindepth 1 -maxdepth 1)"
    [ -d "$only" ] && extracted="$only"
  fi

  if [ -f "$extracted/public/index.php" ]; then
    if [ ! -f "$extracted/index.php" ]; then
      log "Laravel / public webroot detected for '$name' in folder '$base'"
      cat > "$extracted/index.php" <<'EOF'
<?php
// WTC auto-forwarder to public/ webroot
require_once __DIR__ . '/public/index.php';
EOF
    fi
    if [ -f "$extracted/.env.example" ] && [ ! -f "$extracted/.env" ]; then
      cp "$extracted/.env.example" "$extracted/.env"
      local db_id; db_id="$(db_ident "$name")"
      sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$db_id|" "$extracted/.env" 2>/dev/null || true
      sed -i "s|^DB_USERNAME=.*|DB_USERNAME=$db_id|" "$extracted/.env" 2>/dev/null || true
    fi
    if [ ! -f "$extracted/bootstrap/app.php" ] || [ ! -f "$extracted/vendor/autoload.php" ]; then
      mkdir -p "$extracted/bootstrap" "$extracted/vendor"
      cat > "$extracted/vendor/autoload.php" <<'EOF'
<?php
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
EOF
      cat > "$extracted/bootstrap/app.php" <<'EOF'
<?php
return new class {
    public function handleRequest($request) {
        $site = basename(dirname(__DIR__));
        $phpv = PHP_VERSION;
        $dbName = 'w_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($site));
        $dbStatus = 'Not Connected';
        $dbTables = [];
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
        <!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= htmlspecialchars($site) ?> - Laravel Webroot</title>
        <style>body{font-family:sans-serif;background:#0f1419;color:#e6e6e6;padding:40px 20px}.card{background:#1a2129;border:1px solid #2a3541;border-radius:10px;padding:24px;max-width:800px;margin:0 auto}h1{color:#ff2d20}code{background:#0a0d11;color:#a5d6ff;padding:2px 6px;border-radius:4px}.pill{background:#1d6b3a;color:#fff;padding:3px 10px;border-radius:10px;font-size:0.8rem}.tag{display:inline-block;background:#2a3541;color:#7ee787;padding:3px 8px;border-radius:4px;margin:2px;font-family:monospace}</style></head>
        <body><div class="card"><h1>🅰️ Laravel Public Webroot</h1><p><span class="pill">Active in RAM</span> PHP <?= $phpv ?> &bull; DB: <b><?= htmlspecialchars($dbName) ?></b> (<?= htmlspecialchars($dbStatus) ?>)</p><p style="margin-top:12px">Served from tmpfs RAM disk. Missing backend application files (<code>app/</code>, <code>bootstrap/</code>) were substituted with WTC compatibility shim.</p>
        <h3 style="margin-top:18px">🗄️ Database Tables (<?= count($dbTables) ?>)</h3>
        <?php if (!empty($dbTables)): foreach($dbTables as $t): ?><span class="tag"><?= htmlspecialchars($t) ?></span><?php endforeach; else: ?><p style="color:#8b949e">No tables imported yet. Drop a <code>.sql</code> file into the UI to populate this database.</p><?php endif; ?>
        <p style="margin-top:20px"><a href="/?p=transfer" style="color:#7cc4ff">&larr; Back to WTC Device Portal</a></p></div></body></html>
        <?php
    }
};
EOF
    fi

    # Ensure storage skeleton exists
    if [ ! -d "$extracted/storage" ]; then
      mkdir -p "$extracted/storage/framework/views" "$extracted/storage/framework/sessions" \
               "$extracted/storage/framework/cache" "$extracted/storage/logs" "$extracted/bootstrap/cache"
    fi
  fi

  # Check if React build / dist folder is present and promote
  for sub in dist build out; do
    if [ -d "$extracted/$sub" ] && [ -f "$extracted/$sub/index.html" ]; then
      log "React/SPA build folder '$sub' detected in '$name' - promoting to root"
      cp -rn "$extracted/$sub"/* "$extracted"/ 2>/dev/null || true
      break
    fi
  done

  # If SPA with index.html but no index.php, provide SPA router bridge
  if [ -f "$extracted/index.html" ] && [ ! -f "$extracted/index.php" ]; then
    cat > "$extracted/index.php" <<'EOF'
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
    cat > "$extracted/nginx.conf" <<'EOF'
location / {
    try_files $uri $uri/ /index.html /index.php?$query_string;
}
EOF
  fi

  if [ -z "$(find "$extracted" -maxdepth 1 -name 'index.*' -print -quit)" ]; then
    log "Notice: No index.* in folder '$base', generating default index.html"
    cat > "$extracted/index.html" <<EOF
<!doctype html><html><head><meta charset="utf-8"><title>$name</title></head>
<body><h1>$name</h1><p>Site '$name' running live from RAM.</p></body></html>
EOF
  fi

  if ! store_dir_as_site "$name" "$extracted"; then
    reject "$dir" "Failed to package folder into site"; return
  fi

  rm -rf "$dir"
  log "Packaged dropped folder '$base' into site '$name'"

  if [ "$EXISTED" -eq 1 ]; then
    log "UPDATED site '$name' from folder $base"
    if [ "$AUTO_RELOAD_ACTIVE" = yes ] && [ "$(active_site)" = "$name" ]; then
      "$SERVICE_BIN" --swap "$name" || log "Hot reload of '$name' failed"
    fi
  else
    log "NEW site '$name' from folder $base - auto-spinning up live"
    "$SERVICE_BIN" --swap "$name" || log "Auto-spin-up of '$name' failed"
  fi
}

ingest() {
  [ -d "$DROP_DIR" ] || return 0
  local f base

  # 1. Process dropped directories (e.g. from Windows Explorer SMB or FTP)
  while IFS= read -r -d '' f; do
    base="$(basename "$f")"
    case "$base" in
      .*|*.part|*.tmp) continue ;;
      *) ingest_dir "$f" ;;
    esac
  done < <(find "$DROP_DIR" -mindepth 1 -maxdepth 1 -type d -print0 | sort -z)

  # 2. Process dropped archive files
  while IFS= read -r -d '' f; do
    base="$(basename "$f")"
    case "$base" in
      .*|*.part|*.partial|*.tmp|*.crdownload|*.filepart) continue ;;  # still uploading
      *.sha256) [ -f "${f%.sha256}" ] || reject "$f" "Checksum file without archive" ;;
      *) wait_stable "$f" || { reject "$f" "Upload did not complete"; continue; }
         ingest_one "$f" ;;
    esac
  done < <(find "$DROP_DIR" -maxdepth 1 -type f -print0 | sort -z)
}

# ---------------------------------------------------------------- main
main() {
  need_root
  acquire_lock
  case "${1:-}" in
    --boot)       boot ;;
    --reload-ui)  reload_ui_ram ;;
    --activate|--enable-site) [ -n "${2:-}" ] || die "Usage: $0 --activate <site> [primary:0|1]"; activate_site "$2" "${3:-1}" ;;
    --deactivate|--disable-site) [ -n "${2:-}" ] || die "Usage: $0 --deactivate <site>"; deactivate_site "$2" ;;
    --swap)       [ -n "${2:-}" ] || die "Usage: $0 --swap <site>"; swap_site "$2" ;;
    --swap-dir)   [ -n "${2:-}" ] && [ -n "${3:-}" ] || die "Usage: $0 --swap-dir <site> <dir>"; swap_dir "$2" "$3" ;;
    --reload)     swap_site "$(active_site)" ;;
    --ingest)     ingest ;;
    --save-state) save_state "${2:-}" ;;
    --save-tick)  save_tick ;;
    --shutdown)   [ "$SAVE_ON_SHUTDOWN" = yes ] && save_state || true; [ -x /usr/local/bin/wtc-workers ] && /usr/local/bin/wtc-workers stop || true ;;
    *) echo "Usage: $0 --boot|--reload-ui|--activate S [P]|--deactivate S|--swap S|--swap-dir S D|--reload|--ingest|--save-state [S]|--save-tick|--shutdown" >&2; exit 2 ;;
  esac
}
main "$@"

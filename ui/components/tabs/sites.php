<?php
declare(strict_types=1);
/**
 * Tab Container: Sites & Apps
 * List installed sites, activate/rollback/download/delete actions, instant dropzone, upload packages, create skeletons.
 */

$sites = wj('sites');
$buildJob = wj('build');
$st = wj('status');
?>

<!-- Drag & Drop Instant Deployer -->
<?php require __DIR__ . '/../dropzone.php'; ?>

<?php if (!empty($buildJob['busy'])): ?>
    <div class="card" style="border-color:#388bfd;background:#0d1b2a;margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <h4 style="color:#79c0ff;margin:0">🔨 Background Build in Progress (npm run build)...</h4>
            <span class="pill ok">Building in RAM</span>
        </div>
        <pre
            style="background:#050a10;color:#7ee787;padding:12px;border-radius:6px;max-height:160px;overflow-y:auto;font-size:0.78rem;font-family:monospace;white-space:pre-wrap"><?= h($buildJob['log'] ?? 'Executing npm install && npm run build...') ?></pre>
        <small style="color:#8b949e">Auto-refreshing until build finishes...</small>
        <script>setTimeout(() => { if (window.wtcSpa) { window.wtcSpa.navigateTo('/?p=sites', false); } else { window.location.reload(); } }, 3000);</script>
    </div>
<?php endif; ?>

<div class="card">
    <div
        style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #1c2128;padding-bottom:8px">
        <h3 style="margin:0;border:0;padding:0">Available Applications &amp; Archives</h3>
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rescan_sites">
            <input type="hidden" name="back" value="sites">
            <button class="btn" title="Inspect site archives to update stack types & metadata">🔍 Rescan
                Packages</button>
        </form>
    </div>
    <table>
        <tr>
            <th>Site ID</th>
            <th>Archive Size</th>
            <th>Last Updated</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($sites as $s): ?>
            <?php
            $slug = !empty($s['slug']) ? $s['slug'] : strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '', $s['name'])));
            $url = '/' . $slug . '/';
            $isActive = !empty($s['active']);
            $isPrimary = !empty($s['isPrimary']);
            ?>
            <tr>
                <td>
                    <b><?= h($s['name']) ?></b>
                    <?php if (!empty($s['stack'])): ?>
                        <?php
                        $stk = match ($s['stack']) {
                            'laravel' => ['⚡ Laravel', '#1f3322', '#7ee787'],
                            'spa' => ['⚛️ SPA', '#13233a', '#79c0ff'],
                            'php' => ['🐘 PHP', '#2a1b3d', '#d2a8ff'],
                            'static' => ['📄 Static', '#2d2518', '#e3b341'],
                            default => ['📦 App', '#21262d', '#8b949e']
                        };
                        ?>
                        <span class="pill"
                            style="background:<?= $stk[1] ?>;color:<?= $stk[2] ?>;font-size:0.72rem;margin-left:4px"><?= $stk[0] ?></span>
                    <?php endif; ?>
                    <?php if ($isActive): ?>
                        <span class="pill ok">active in RAM</span>
                        <?php if ($isPrimary): ?>
                            <span class="pill" style="background:#1f6feb;color:#fff;font-size:0.72rem"
                                title="Primary site serving Port 80 and Port 8080">⭐ Primary</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($isActive && $isPrimary && !empty($st['workers']['queue']['running'])): ?>
                        <span class="pill" style="background:#13233a;color:#79c0ff;border:1px solid #1f6feb">⚙️ workers
                            active</span>
                    <?php endif; ?>
                    <div style="font-size:0.75rem;color:#8b949e;margin-top:3px">
                        URL: <code><?= h(site_url($isPrimary ? '/' : $url)) ?></code>
                    </div>
                </td>
                <td><?= fmt_bytes($s['size']) ?></td>
                <td><?= date('Y-m-d H:i', $s['mtime']) ?></td>
                <td class="act" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <?php if ($isActive): ?>
                        <?php
                        $targetUrl = site_url($isPrimary ? '/' : $url);
                        $targetLabel = $isPrimary ? 'Port 80 (/)' : $url;
                        ?>
                        <a class="btn-pri" href="<?= h($targetUrl) ?>" target="_blank"
                            style="background:#238636;border:0;padding:5px 10px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-block;font-size:0.82rem">🚀
                            <?= h($targetLabel) ?></a>
                        <?php if ($isPrimary): ?>
                            <a class="btn-pri" href="<?= site_port_url(8080, '/') ?>"
                                target="_blank"
                                style="background:#1f6feb;border:0;padding:5px 10px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-block;font-size:0.82rem"
                                title="Open at Root / on Port 8080">:8080</a>
                        <?php else: ?>
                            <?= form('set_primary', ['site' => $s['name'], 'back' => 'sites'], '⭐ Make Primary', '', '', 'btn', 'Set as primary site for Port 80 and Port 8080') ?>
                        <?php endif; ?>
                        <?= form('deactivate', ['site' => $s['name'], 'back' => 'sites'], '⏹️ Stop', 'danger', 'Stop and unload ' . $s['name'] . ' from RAM?') ?>
                    <?php else: ?>
                        <?= form('activate', ['site' => $s['name'], 'back' => 'sites'], '⚡ Start Live', 'btn-pri') ?>
                    <?php endif; ?>
                    <?php if (!empty($s['hasBuild'])): ?>
                        <?= form('build', ['site' => $s['name'], 'back' => 'sites'], '🔨 Build', '') ?>
                    <?php endif; ?>
                    <?php if ($s['hasPrevious']): ?>
                        <?= form('rollback', ['site' => $s['name'], 'back' => 'sites'], 'Rollback', '', 'Restore previous version?') ?>
                    <?php endif; ?>
                    <a class="btn" href="/?download=<?= h($s['name']) ?>">Download</a>
                    <?php if (!$isActive): ?>
                        <?= form('delete', ['site' => $s['name'], 'back' => 'sites'], 'Delete', 'danger', 'Delete ' . $s['name'] . '?') ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$sites): ?>
            <tr>
                <td colspan="4">No site packages found in /opt/wtc-web/sites.</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<?php if (!empty($st['workers']['has_artisan'])): ?>
        <?php $activeSite = (string) ($st['active'] ?? ''); ?>
    <div class="card" style="border-color:#388bfd;background:#0d1825;margin-bottom:16px">
        <div class="card-head" style="margin-bottom:8px">
            <h4 style="color:#7cc4ff;margin:0">🗄️ Laravel Database &amp; Artisan Management</h4>
            <span class="pill ok">Artisan Ready in RAM</span>
        </div>
        <div
            style="background:#11171e;border:1px solid #2a3541;border-radius:6px;padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <div>
                <div style="font-size:0.86rem;color:#c9d1d9">
                    Active Site: <b><?= h($activeSite ?: 'active site') ?></b> &bull; Database:
                    <code>w_<?= preg_replace('/[^a-z0-9_]/', '_', strtolower($activeSite ?: 'site')) ?></code>
                </div>
                <div id="siteDbStatusMsg" style="font-size:0.8rem;color:#8b949e;margin-top:2px">
                    Click "Verify Connection" to test live MariaDB communication and table status.
                </div>
            </div>
            <button type="button" class="btn" id="btnTestSiteDb" onclick="testActiveDbConnection('<?= h($activeSite) ?>')">
                🔌 Verify DB Connection
            </button>
        </div>
        <p style="font-size:0.86rem;color:#c9d1d9;margin-bottom:12px">
            Initialize database tables or run seeders for the currently active Laravel application
            (<b><?= h($activeSite ?: 'active site') ?></b>):
        </p>
        <form method="post" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap">
                <?= csrf_field() ?>
            <input type="hidden" name="action" value="artisan">
            <input type="hidden" name="site" value="<?= h($activeSite) ?>">
            <input type="hidden" name="back" value="sites">
            <label style="font-size:0.85rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" name="run_migrations" id="siteChkMigrate" value="1" checked>
                <span>⚡ <b>Run Migrations</b> (<code>php artisan migrate --force</code>)</span>
            </label>
            <label style="font-size:0.85rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" name="run_fresh" id="siteChkFresh" value="1"
                    onchange="if(this.checked && !confirm('⚠️ Warning: Run Fresh will DROP all database tables before recreating them. Are you sure you want to run fresh?')){ this.checked = false; } else if(this.checked){ document.getElementById('siteChkMigrate').checked = false; }">
                <span style="color:#ffab70">🔄 <b>Run Fresh</b> (<code>php artisan migrate:fresh --force</code>)</span>
            </label>
            <label style="font-size:0.85rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" name="run_seeds" value="1">
                <span>🌱 <b>Run Seeders</b> (<code>php artisan db:seed --force</code>)</span>
            </label>
            <button class="btn-pri" style="padding:6px 14px;font-size:0.85rem">Execute Artisan Commands</button>
        </form>
    </div>
    <script>
        function testActiveDbConnection(site) {
            const btn = document.getElementById('btnTestSiteDb');
            const msg = document.getElementById('siteDbStatusMsg');
            if (btn) btn.textContent = '⏳ Testing connection...';
            fetch('/?action=db_test&site=' + encodeURIComponent(site) + '&is_ajax=1')
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        if (btn) btn.innerHTML = '✅ Connected (' + data.tables + ' tables)';
                        if (btn) btn.style.borderColor = '#2ea043';
                        if (msg) msg.innerHTML = '<span style="color:#7ee787">Connected successfully to <b>' + data.database + '</b> as <code>' + data.user + '</code> (' + data.tables + ' tables found).</span>';
                    } else {
                        if (btn) btn.innerHTML = '❌ Connection Failed';
                        if (btn) btn.style.borderColor = '#da3633';
                        if (msg) msg.innerHTML = '<span style="color:#ff7b72">Connection failed: ' + (data.error || 'Unable to connect') + '</span>';
                    }
                })
                .catch(err => {
                    if (btn) btn.innerHTML = '❌ Error';
                    if (msg) msg.innerHTML = '<span style="color:#ff7b72">Error: ' + err.message + '</span>';
                });
        }
    </script>
<?php endif; ?>

<div class="grid2">
    <div class="card">
        <h3>Standard Package Upload</h3>
        <p>Upload a <code>.tar.gz</code> or <code>.zip</code> package manually without dragging.</p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="back" value="sites">
            <input type="file" name="archive" required>
            <button class="btn-pri">Upload Archive</button>
        </form>
    </div>

    <div class="card">
        <h3>Create Empty Skeleton</h3>
        <p>Generate a starter template site structure.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="back" value="sites">
            <input name="site" placeholder="site-id (e.g. app-portal)" pattern="[a-z0-9][a-z0-9_-]*" required>
            <button class="btn-pri">Create Skeleton</button>
        </form>
    </div>
</div>
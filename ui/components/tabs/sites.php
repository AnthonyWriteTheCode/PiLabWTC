<?php
declare(strict_types=1);
/**
 * Tab Container: Sites & Apps
 * List installed sites, activate/rollback/download/delete actions, instant dropzone, upload packages, create skeletons.
 */

$sites = wj('sites');
$buildJob = wj('build');
$st = wj('status');
$set = wj('settings');
$forceHttps = ($set['FORCE_HTTPS'] ?? 'no') === 'yes';
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
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <small style="color:#8b949e">💡 <b>Drop &amp; Auto-Update:</b> Drag and drop folders or archives directly onto any row below to update that application instantly.</small>
            <?php
            $totalRollbacks = 0;
            $totalRollbackBytes = 0;
            foreach ($sites as $_s) {
                $totalRollbacks += (int)($_s['rollbackCount'] ?? (!empty($_s['hasPrevious']) ? 1 : 0));
                $totalRollbackBytes += (int)($_s['rollbackSize'] ?? 0);
            }
            ?>
            <?php if ($totalRollbacks > 0): ?>
                <?= form('rollback_clear', ['site' => '__ALL__', 'back' => 'sites'], '🧹 Clear All Rollbacks (' . $totalRollbacks . ' · ' . fmt_bytes($totalRollbackBytes) . ')', 'btn-ctrl', 'Delete ALL saved rollback snapshots across every site? This frees ' . fmt_bytes($totalRollbackBytes) . ' and cannot be undone.') ?>
            <?php endif; ?>
            <?= form('setting', ['key' => 'FORCE_HTTPS', 'value' => $forceHttps ? 'no' : 'yes', 'back' => 'sites'], $forceHttps ? '🔒 HTTPS Forced (Click to Disable)' : '🔓 Force HTTPS: Off', $forceHttps ? 'btn' : 'btn-ctrl', $forceHttps ? 'Disable Force HTTPS and allow plain HTTP on live sites?' : 'Force HTTPS on all live sites (redirects Port 80/8080 to Port 443)?') ?>
            <button type="button" class="btn" style="border-color:#388bfd;color:#79c0ff"
                onclick="if (window.WtcLogViewer) { window.WtcLogViewer.openModal({}); }"
                title="View formatted Stack &amp; Runtime Logs">📜 View Logs</button>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rescan_sites">
                <input type="hidden" name="back" value="sites">
                <button class="btn" title="Inspect site archives to update stack types & metadata">🔍 Rescan
                    Packages</button>
            </form>
        </div>
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
            <tr class="site-row" data-site="<?= h($s['name']) ?>" data-active="<?= $isActive ? '1' : '0' ?>" data-primary="<?= $isPrimary ? '1' : '0' ?>" data-stack="<?= h($s['stack'] ?? '') ?>" data-size="<?= (int) ($s['size'] ?? 0) ?>">
                <td>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                        <b><?= h($s['name']) ?></b>
                        <span class="row-drop-badge" style="display:none">📥 Drop to update</span>
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
                                style="background:<?= $stk[1] ?>;color:<?= $stk[2] ?>;font-size:0.72rem"><?= $stk[0] ?></span>
                        <?php endif; ?>
                        <?php if ($isActive): ?>
                            <span class="pill ok">active in RAM</span>
                            <?php if ($isPrimary): ?>
                                <span class="pill" style="background:#1f6feb;color:#fff;font-size:0.72rem"
                                    title="Primary site serving Port 80 and Port 8080">⭐ Primary</span>
                            <?php endif; ?>
                            <?php if (($s['stack'] ?? '') === 'laravel' || !empty($s['storageLinked'])): ?>
                                <?php if (!empty($s['storageLinked'])): ?>
                                    <span class="pill ok" style="font-size:0.72rem" title="public/storage is linked to storage/app/public">🔗 storage linked</span>
                                <?php else: ?>
                                    <span class="pill err" style="background:#441c1c;color:#ff7b72;font-size:0.72rem" title="public/storage is missing or not linked to storage/app/public">⚠️ storage unlinked</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($isActive && $isPrimary && !empty($st['workers']['queue']['running'])): ?>
                            <span class="pill" style="background:#13233a;color:#79c0ff;border:1px solid #1f6feb">⚙️ workers
                                active</span>
                        <?php endif; ?>
                        <?php if (!empty($s['catalogRebuilding'])): ?>
                            <span class="pill" style="background:#1c2533;color:#79c0ff;border:1px solid #388bfd;font-size:0.72rem" title="Rebuilding SD card catalog archive in background">💾 syncing catalog...</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:0.75rem;color:#8b949e;margin-top:3px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                        <span>URL: <code><?= h(site_url($isPrimary ? '/' : $url)) ?></code></span>
                        <?php if (!empty($s['port'])): ?>
                            <a href="<?= h(site_port_url((int)$s['port'], '/')) ?>" target="_blank" class="pill ok" style="text-decoration:none;font-size:0.68rem" title="Dedicated Port listener">🌐 :<?= (int)$s['port'] ?></a>
                        <?php endif; ?>
                    </div>
                </td>
                <td><?= fmt_bytes($s['size']) ?></td>
                <td><?= date('Y-m-d H:i', $s['mtime']) ?></td>
                <td class="act" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <?php if ($isActive): ?>
                        <?php
                        $targetUrl = site_url($isPrimary ? '/' : $url);
                        $targetLabel = $isPrimary ? ($forceHttps ? 'HTTPS (/)' : 'Port 80 (/)') : $url;
                        ?>
                        <a class="btn-pri" href="<?= h($targetUrl) ?>" target="_blank"
                            style="background:#238636;border:0;padding:5px 10px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-block;font-size:0.82rem"><?= $forceHttps ? '🔒' : '🚀' ?>
                            <?= h($targetLabel) ?></a>
                        <?php if ($isPrimary): ?>
                            <?php if (!$forceHttps): ?>
                            <a class="btn-pri" href="<?= site_port_url(8080, '/') ?>"
                                target="_blank"
                                style="background:#1f6feb;border:0;padding:5px 10px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-block;font-size:0.82rem"
                                title="Open at Root / on Port 8080">:8080</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= form('set_primary', ['site' => $s['name'], 'back' => 'sites'], '⭐ Make Primary', '', '', 'btn', 'Set as primary site for Port 80 and Port 8080') ?>
                        <?php endif; ?>
                        <?php if (!empty($s['port'])): ?>
                            <a class="btn-pri" href="<?= site_port_url((int)$s['port'], '/') ?>"
                                target="_blank"
                                style="background:#1f6feb;border:0;padding:5px 10px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-block;font-size:0.82rem"
                                title="Open on Dedicated Port <?= (int)$s['port'] ?>">:<?= (int)$s['port'] ?></a>
                        <?php endif; ?>
                        <?php if (($s['stack'] ?? '') === 'laravel' && empty($s['storageLinked'])): ?>
                            <?= form('storage_link', ['site' => $s['name'], 'back' => 'sites'], '🔗 Link Storage', 'btn', 'Ensure public/storage is linked to storage/app/public for ' . $s['name'] . '?') ?>
                        <?php endif; ?>
                        <?= form('deactivate', ['site' => $s['name'], 'back' => 'sites'], '⏹️ Stop', 'danger', 'Stop and unload ' . $s['name'] . ' from RAM?') ?>
                    <?php else: ?>
                        <?= form('activate', ['site' => $s['name'], 'back' => 'sites'], '⚡ Start Live', 'btn-pri') ?>
                    <?php endif; ?>
                    <?php if (!empty($s['hasBuild'])): ?>
                        <?= form('build', ['site' => $s['name'], 'back' => 'sites'], '🔨 Build', '') ?>
                    <?php endif; ?>
                    <?php
                    $rbList = is_array($s['rollbacks'] ?? null) ? $s['rollbacks'] : [];
                    $rbCount = (int)($s['rollbackCount'] ?? count($rbList));
                    if ($rbCount === 0 && !empty($s['hasPrevious'])) {
                        $rbCount = 1;
                    }
                    ?>
                    <?php if ($rbCount > 0): ?>
                        <button type="button" class="btn" style="border-color:#d29922;color:#e3b341"
                            onclick='openRollbackModal(<?= h(json_encode($s["name"])) ?>, <?= h(json_encode($rbList)) ?>)'
                            title="Choose a rollback version or clear saved rollbacks">⏪ Rollback (<?= $rbCount ?>)</button>
                    <?php endif; ?>

                    <!-- Duplicate system instance button -->
                    <button type="button" class="btn" style="border-color:#388bfd;color:#79c0ff" onclick="openDuplicateModal('<?= h($s['name']) ?>', '<?= h($s['stack'] ?? '') ?>', <?= !empty($s['port']) ? (int)$s['port'] : 'null' ?>, <?= $isActive ? 'true' : 'false' ?>)" title="Duplicate <?= h($s['name']) ?> to run multiple versions or on a separate port">📋 Duplicate</button>

                    <!-- View Stack Logs button -->
                    <button type="button" class="btn" style="border-color:#30363d;color:#79c0ff" onclick="if (window.WtcLogViewer) { window.WtcLogViewer.openModal({ site: <?= h(json_encode($s['name'])) ?>, title: <?= h(json_encode('📜 Stack Logs — ' . $s['name'])) ?> }); }" title="View formatted logs for <?= h($s['name']) ?>">📜 View Logs</button>

                    <!-- Re-upload project button -->
                    <button type="button" class="btn btn-reupload" onclick="openReuploadModal('<?= h($s['name']) ?>', <?= $isActive ? 'true' : 'false' ?>, '<?= h($s['stack'] ?? '') ?>', <?= $isPrimary ? 'true' : 'false' ?>)" title="Re-upload archive or folder to update <?= h($s['name']) ?>">🔄 Re-upload</button>

                    <!-- Protected Download button -->
                    <?php if ($s['size'] > 0 || $isActive): ?>
                        <a class="btn no-spa" download="<?= h($s['name']) ?>.tar.gz" href="/?download=<?= h($s['name']) ?>&csrf=<?= h(csrf()) ?>" onclick="return confirm('Download application package archive for \'<?= h($s['name']) ?>\' (<?= fmt_bytes($s['size']) ?>)?')" title="Download verified archive package for <?= h($s['name']) ?>">⬇️ Download</a>
                    <?php else: ?>
                        <button type="button" class="btn" disabled title="No archive package exists for this site" style="opacity:0.45;cursor:not-allowed">⬇️ Download</button>
                    <?php endif; ?>

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

<!-- Re-upload & Update Modal -->
<div id="reuploadModal" class="modal-backdrop" style="display:none">
    <div class="modal-dialog" style="max-width:540px">
        <div class="modal-header">
            <h3><span>🔄</span> <span id="reuploadModalTitle">Re-upload &amp; Update Application</span></h3>
            <button type="button" class="modal-close" onclick="closeReuploadModal()" title="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.86rem;color:#8b949e;margin-bottom:14px">
                Upload an updated package (<code>.zip</code>, <code>.tar.gz</code>) or folder to update <b id="reuploadSiteNameLabel" style="color:#79c0ff">app</b>.
                Files are replaced <b>directly in RAM</b> for instant access, then the SD card catalog is rebuilt in the background.
                Database credentials, <code>.env</code> configurations, database snapshots, and user storage are <b>automatically protected and preserved</b>.
            </p>

            <!-- Drop target inside modal -->
            <div id="reuploadDropTarget" style="border:2px dashed #1f6feb;background:#0d1520;border-radius:8px;padding:22px 16px;text-align:center;cursor:pointer;transition:border-color .15s ease, background .15s ease;margin-bottom:14px" onclick="document.getElementById('reuploadArchiveInput').click()">
                <div style="font-size:2rem;margin-bottom:6px">📦⚡</div>
                <b style="color:#79c0ff;font-size:0.95rem">Drag &amp; drop package or folder here</b>
                <div style="font-size:0.8rem;color:#8b949e;margin-top:4px">or click to browse from your computer</div>
            </div>

            <div style="display:flex;gap:10px;justify-content:center;margin-bottom:10px">
                <button type="button" class="btn" onclick="document.getElementById('reuploadArchiveInput').click()">📦 Browse Archive (.zip / .tar.gz)</button>
                <button type="button" class="btn" onclick="browseReuploadFolder()">📁 Browse Folder</button>
            </div>
            <div id="reuploadHttpsHint" style="display:none;text-align:center;margin-bottom:14px;font-size:0.76rem;color:#8b949e">
                💡 <a id="reuploadHttpsLink" href="#" class="no-spa" style="color:#79c0ff;text-decoration:underline">Use HTTPS (port 4486)</a> for fast folder browsing
                <a href="/?download_ui_cert=1" download="pilabwtc-ui.crt" class="btn no-spa" style="margin-left:6px;padding:2px 8px;font-size:0.72rem">🔐 Download HTTPS Cert (.crt)</a>
            </div>

            <input type="file" id="reuploadArchiveInput" accept=".zip,.tar,.gz,.tgz,.tar.gz" style="display:none">
            <input type="file" id="reuploadFolderInput" webkitdirectory directory style="display:none">

            <!-- Options -->
            <div style="background:#11171e;border:1px solid #21262d;border-radius:6px;padding:10px 14px;margin-bottom:12px;display:flex;flex-direction:column;gap:8px">
                <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem;color:#c9d1d9;cursor:pointer">
                    <input type="checkbox" id="reuploadSpinUp" checked>
                    <span>⚡ <b>Update and reload live in RAM immediately</b></span>
                </label>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:0.85rem;color:#c9d1d9">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" id="reuploadRecentOnly" checked onchange="saveReuploadRecentPrefs()">
                        <span>⏱️ <b>Only upload files saved in the past</b></span>
                    </label>
                    <input type="number" id="reuploadRecentMins" value="3" min="1" max="10080" oninput="saveReuploadRecentPrefs()" style="width:64px;background:#0d1117;color:#79c0ff;border:1px solid #30363d;border-radius:4px;padding:2px 6px;font-size:0.82rem;text-align:center;font-weight:600">
                    <span style="color:#8b949e;font-size:0.82rem">minutes <span style="font-size:0.75rem">(folder uploads)</span></span>
                </div>
            </div>

            <!-- Protected Files Selection Section -->
            <div style="background:#0e1d15;border:1px solid #238636;border-radius:6px;padding:12px;margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:8px">
                    <div>
                        <b style="color:#7ee787;font-size:0.88rem">🛡️ Protected Files (Do Not Overwrite)</b>
                        <div style="font-size:0.75rem;color:#8b949e">Preserves server database credentials, SQLite databases, and user uploads</div>
                    </div>
                    <select id="reuploadPresetSelect" onchange="applyReuploadPreset(this.value)" style="background:#161b22;color:#c9d1d9;border:1px solid #30363d;border-radius:4px;padding:2px 8px;font-size:0.76rem">
                        <option value="auto">⚡ Auto (Stack Preset)</option>
                        <option value="laravel">🅰️ Laravel</option>
                        <option value="react">⚛️ React / SPA / Vite</option>
                        <option value="wordpress">📝 WordPress</option>
                        <option value="php">🐘 Classic PHP</option>
                        <option value="static">📄 Static HTML</option>
                        <option value="custom">⚙️ Custom</option>
                    </select>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:8px;margin-top:8px">
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#c9d1d9;cursor:pointer">
                        <input type="checkbox" id="reuploadChkEnv" checked>
                        <span>🔒 <b>.env &amp; DB credentials</b></span>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#c9d1d9;cursor:pointer">
                        <input type="checkbox" id="reuploadChkSqlite" checked>
                        <span>🗄️ <b>SQLite databases (*.sqlite)</b></span>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#c9d1d9;cursor:pointer">
                        <input type="checkbox" id="reuploadChkDbDump" checked>
                        <span>📦 <b>.wtc-database.sql dump</b></span>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#c9d1d9;cursor:pointer">
                        <input type="checkbox" id="reuploadChkStorage" checked>
                        <span>📁 <b>storage/app &amp; uploads</b></span>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#c9d1d9;cursor:pointer">
                        <input type="checkbox" id="reuploadChkNginx" checked>
                        <span>⚙️ <b>nginx.conf &amp; wtc.json</b></span>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#c9d1d9;cursor:pointer">
                        <input type="checkbox" id="reuploadChkGitignore" checked>
                        <span>📄 <b>Rules from .gitignore</b></span>
                    </label>
                </div>

                <!-- Custom patterns input -->
                <div style="display:flex;gap:6px;margin-top:8px">
                    <input type="text" id="reuploadCustomPattern" placeholder="Add custom pattern, e.g. config/local.php" style="flex:1;background:#0d1117;color:#c9d1d9;border:1px solid #30363d;border-radius:4px;padding:3px 8px;font-size:0.78rem" onkeydown="if(event.key==='Enter'){event.preventDefault();addReuploadCustomPattern();}">
                    <button type="button" class="btn" style="padding:2px 8px;font-size:0.75rem" onclick="addReuploadCustomPattern()">+ Add</button>
                </div>
                <div id="reuploadCustomChips" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px"></div>
            </div>

            <!-- Progress bar -->
            <div id="reuploadProgress" style="display:none;margin-top:10px">
                <div class="bar" style="background:#21262d;height:6px;border-radius:3px;overflow:hidden"><i id="reuploadProgressBar" style="display:block;height:100%;background:#238636;width:0%;transition:width .2s ease"></i></div>
                <div id="reuploadStatusText" style="font-size:0.8rem;color:#79c0ff;margin-top:6px;text-align:center">Uploading package...</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" onclick="closeReuploadModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Duplicate System Instance Modal -->
<div id="duplicateModal" class="modal-backdrop" style="display:none">
    <div class="modal-dialog" style="max-width:540px">
        <div class="modal-header">
            <h3><span>📋</span> <span id="dupModalTitle">Duplicate System / Application Instance</span></h3>
            <button type="button" class="modal-close" onclick="closeDuplicateModal()" title="Close">&times;</button>
        </div>
        <form method="post" action="/" data-action="site_clone" id="duplicateForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="site_clone">
            <input type="hidden" name="site" id="dupSourceInput" value="">
            <input type="hidden" name="back" value="sites">

            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
                <div style="background:#0c1524;border:1px solid #1f3a5f;border-radius:6px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <div style="font-size:0.75rem;color:#8b949e;text-transform:uppercase;letter-spacing:0.5px">Source System</div>
                        <div style="font-size:0.95rem;font-weight:700;color:#79c0ff" id="dupSourceLabel">app</div>
                    </div>
                    <span class="pill" id="dupSourceStackBadge" style="background:#1f3322;color:#7ee787">⚡ Laravel</span>
                </div>

                <div>
                    <label style="display:block;font-size:0.84rem;font-weight:600;color:#c9d1d9;margin-bottom:6px">
                        New System Name / Instance ID <span style="color:#f85149">*</span>
                    </label>
                    <input type="text" name="new_name" id="dupNewNameInput" required pattern="[a-z0-9_-]+"
                        placeholder="e.g. app-v2, app-staging, app-admin"
                        oninput="updateDuplicatePreview()"
                        style="width:100%;box-sizing:border-box;background:#0d1117;border:1px solid #388bfd;color:#e6edf3;padding:8px 12px;border-radius:6px;font-size:0.88rem;font-family:monospace">
                    <div style="font-size:0.76rem;color:#8b949e;margin-top:4px">
                        New URL: <code id="dupUrlPreview" style="color:#7ee787">/app-v2/</code>
                    </div>
                </div>

                <div>
                    <label style="display:block;font-size:0.84rem;font-weight:600;color:#c9d1d9;margin-bottom:6px">
                        🌐 Dedicated Listening Port (Optional)
                    </label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="number" name="port" id="dupPortInput" min="1" max="65535"
                            placeholder="e.g. 8081, 8082, 3000"
                            style="flex:1;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:8px 12px;border-radius:6px;font-size:0.88rem">
                        <button type="button" class="btn" onclick="suggestNextPort()" style="font-size:0.8rem;white-space:nowrap" title="Auto-select next available port">
                            💡 Suggest Next
                        </button>
                    </div>
                    <div style="font-size:0.76rem;color:#8b949e;margin-top:4px">
                        Leave blank to serve under URL subpath on Port 80, or assign a dedicated port to isolate this version/part of the system.
                    </div>
                </div>

                <div id="dupDbOptionsBox" style="background:#0d1117;border:1px solid #21262d;border-radius:6px;padding:12px">
                    <div style="font-size:0.84rem;font-weight:600;color:#c9d1d9;margin-bottom:8px;display:flex;align-items:center;gap:6px">
                        🗄️ Database Duplication
                    </div>
                    <div style="display:flex;flex-direction:column;gap:8px">
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                            <input type="radio" name="db_mode" value="clone" checked style="margin-top:2px">
                            <div>
                                <b>Clone Database &amp; Data</b> <span class="pill ok" style="font-size:0.65rem">Recommended</span>
                                <div style="font-size:0.74rem;color:#8b949e">Creates a new database and clones all tables and data from the source system.</div>
                            </div>
                        </label>
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                            <input type="radio" name="db_mode" value="new" style="margin-top:2px">
                            <div>
                                <b>Fresh Empty Database</b>
                                <div style="font-size:0.74rem;color:#8b949e">Creates a new blank database with fresh credentials and app key.</div>
                            </div>
                        </label>
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                            <input type="radio" name="db_mode" value="share" style="margin-top:2px">
                            <div>
                                <b>Share Same Database</b>
                                <div style="font-size:0.74rem;color:#8b949e">Both systems share the same database tables (use with caution).</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div>
                    <label style="font-size:0.84rem;color:#c9d1d9;display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="activate" value="1" checked>
                        <span>⚡ <b>Spin up live in RAM immediately</b> (both versions run side-by-side)</span>
                    </label>
                </div>
            </div>

            <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid #21262d">
                <button type="button" class="btn" onclick="closeDuplicateModal()">Cancel</button>
                <button type="submit" class="btn-pri" style="background:#238636;border-color:#2ea043;font-weight:600">
                    🚀 Duplicate &amp; Launch
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const usedSitePorts = <?= json_encode(array_values(array_filter(array_map(fn($s) => !empty($s['port']) ? (int)$s['port'] : null, $sites)))) ?>;

    window.openDuplicateModal = function(sourceSite, stack, port, isActive) {
        document.getElementById('dupSourceInput').value = sourceSite;
        document.getElementById('dupSourceLabel').textContent = sourceSite;
        
        const badge = document.getElementById('dupSourceStackBadge');
        if (badge) {
            badge.textContent = stack ? (stack === 'laravel' ? '⚡ Laravel' : (stack === 'spa' ? '⚛️ SPA' : (stack === 'php' ? '🐘 PHP' : stack.toUpperCase()))) : 'APP';
        }
        
        // Auto-suggest new name: source + '-v2'
        let suggested = sourceSite + '-v2';
        document.getElementById('dupNewNameInput').value = suggested;
        window.updateDuplicatePreview();
        
        // Suggest port if source had a port or auto-select next
        document.getElementById('dupPortInput').value = '';
        if (port) {
            let p = parseInt(port, 10) + 1;
            while (usedSitePorts.includes(p)) p++;
            document.getElementById('dupPortInput').value = p;
        }
        
        const modal = document.getElementById('duplicateModal');
        if (modal) {
            modal.style.display = 'flex';
            document.getElementById('dupNewNameInput').focus();
        }
    };

    window.closeDuplicateModal = function() {
        const modal = document.getElementById('duplicateModal');
        if (modal) modal.style.display = 'none';
    };

    window.updateDuplicatePreview = function() {
        const name = (document.getElementById('dupNewNameInput').value || '').trim();
        const slug = name.toLowerCase().replace(/[^a-z0-9_-]/g, '');
        const preview = document.getElementById('dupUrlPreview');
        if (preview) {
            preview.textContent = '/' + (slug || 'new-instance') + '/';
        }
    };

    window.suggestNextPort = function() {
        let nextPort = 8081;
        while (usedSitePorts.includes(nextPort)) {
            nextPort++;
        }
        document.getElementById('dupPortInput').value = nextPort;
    };
    let currentReuploadSite = '';
    let currentReuploadActive = false;
    let currentReuploadIsPrimary = false;
    let currentReuploadStack = '';
    let reuploadCustomPatterns = [];

    window.openReuploadModal = function(siteName, isActive, stack, isPrimary) {
        currentReuploadSite = siteName;
        currentReuploadActive = !!isActive;
        currentReuploadIsPrimary = !!isPrimary;
        currentReuploadStack = stack || '';
        const modal = document.getElementById('reuploadModal');
        const titleLabel = document.getElementById('reuploadModalTitle');
        const siteLabel = document.getElementById('reuploadSiteNameLabel');
        const spinCheck = document.getElementById('reuploadSpinUp');
        const progressBox = document.getElementById('reuploadProgress');

        if (titleLabel) titleLabel.textContent = `Re-upload & Update: ${siteName}`;
        if (siteLabel) siteLabel.textContent = siteName;
        if (spinCheck) spinCheck.checked = currentReuploadActive;
        if (progressBox) progressBox.style.display = 'none';
        loadReuploadRecentPrefs();

    if (!window.isSecureContext) {
        const rHint = document.getElementById('reuploadHttpsHint');
        const rLink = document.getElementById('reuploadHttpsLink');
        if (rHint && rLink) {
            const targetHost = window.location.hostname || 'pilabwtc.local';
            rLink.href = 'https://' + targetHost + ':4486' + window.location.pathname + window.location.search;
            rHint.style.display = 'block';
        }
    }

        applyReuploadPreset(currentReuploadStack || 'auto');
        if (modal) modal.style.display = 'flex';
    };

    window.loadReuploadRecentPrefs = function() {
        const chk = document.getElementById('reuploadRecentOnly');
        const mins = document.getElementById('reuploadRecentMins');
        try {
            const savedEnabled = localStorage.getItem('wtc_reupload_recent_only');
            const savedMins = localStorage.getItem('wtc_reupload_recent_mins');
            if (chk) chk.checked = savedEnabled === null ? true : (savedEnabled === '1');
            if (mins) {
                const parsed = parseInt(savedMins || '3', 10);
                mins.value = (!isNaN(parsed) && parsed > 0) ? String(parsed) : '3';
            }
        } catch (_) {}
    };

    window.saveReuploadRecentPrefs = function() {
        const chk = document.getElementById('reuploadRecentOnly');
        const mins = document.getElementById('reuploadRecentMins');
        try {
            if (chk) localStorage.setItem('wtc_reupload_recent_only', chk.checked ? '1' : '0');
            if (mins) {
                const parsed = parseInt(mins.value, 10);
                if (!isNaN(parsed) && parsed > 0) {
                    localStorage.setItem('wtc_reupload_recent_mins', String(parsed));
                }
            }
        } catch (_) {}
    };

    function getReuploadRecentMinutes() {
        const chk = document.getElementById('reuploadRecentOnly');
        const mins = document.getElementById('reuploadRecentMins');
        try {
            const savedEnabled = localStorage.getItem('wtc_reupload_recent_only');
            const savedMins = localStorage.getItem('wtc_reupload_recent_mins');
            const enabled = chk ? chk.checked : (savedEnabled === null ? true : savedEnabled === '1');
            if (!enabled) return 0;
            const rawVal = mins ? mins.value : (savedMins || '3');
            const parsed = parseInt(rawVal, 10);
            return (!isNaN(parsed) && parsed > 0) ? parsed : 3;
        } catch (_) {
            return (chk && !chk.checked) ? 0 : 3;
        }
    }

    loadReuploadRecentPrefs();

    if (!window.isSecureContext) {
        const rHint = document.getElementById('reuploadHttpsHint');
        const rLink = document.getElementById('reuploadHttpsLink');
        if (rHint && rLink) {
            const targetHost = window.location.hostname || 'pilabwtc.local';
            rLink.href = 'https://' + targetHost + ':4486' + window.location.pathname + window.location.search;
            rHint.style.display = 'block';
        }
    }

    window.applyReuploadPreset = function(preset) {
        if (!preset || preset === 'auto') {
            preset = currentReuploadStack || 'php';
        }
        const sel = document.getElementById('reuploadPresetSelect');
        if (sel) sel.value = preset;

        const chkEnv = document.getElementById('reuploadChkEnv');
        const chkSqlite = document.getElementById('reuploadChkSqlite');
        const chkDbDump = document.getElementById('reuploadChkDbDump');
        const chkStorage = document.getElementById('reuploadChkStorage');
        const chkNginx = document.getElementById('reuploadChkNginx');

        if (preset === 'laravel') {
            if (chkEnv) chkEnv.checked = true;
            if (chkSqlite) chkSqlite.checked = true;
            if (chkDbDump) chkDbDump.checked = true;
            if (chkStorage) chkStorage.checked = true;
            if (chkNginx) chkNginx.checked = true;
        } else if (preset === 'react' || preset === 'spa') {
            if (chkEnv) chkEnv.checked = true;
            if (chkSqlite) chkSqlite.checked = false;
            if (chkDbDump) chkDbDump.checked = false;
            if (chkStorage) chkStorage.checked = false;
            if (chkNginx) chkNginx.checked = true;
        } else if (preset === 'wordpress') {
            if (chkEnv) chkEnv.checked = false;
            if (chkSqlite) chkSqlite.checked = false;
            if (chkDbDump) chkDbDump.checked = true;
            if (chkStorage) chkStorage.checked = true;
            if (chkNginx) chkNginx.checked = true;
        } else if (preset === 'php') {
            if (chkEnv) chkEnv.checked = true;
            if (chkSqlite) chkSqlite.checked = true;
            if (chkDbDump) chkDbDump.checked = true;
            if (chkStorage) chkStorage.checked = true;
            if (chkNginx) chkNginx.checked = true;
        } else if (preset === 'static') {
            if (chkEnv) chkEnv.checked = false;
            if (chkSqlite) chkSqlite.checked = false;
            if (chkDbDump) chkDbDump.checked = true;
            if (chkStorage) chkStorage.checked = false;
            if (chkNginx) chkNginx.checked = true;
        }
    };

    window.addReuploadCustomPattern = function() {
        const inp = document.getElementById('reuploadCustomPattern');
        if (!inp) return;
        const val = inp.value.trim().replace(/\\/g, '/').replace(/^\/+/, '');
        if (val && !reuploadCustomPatterns.includes(val)) {
            reuploadCustomPatterns.push(val);
            renderReuploadChips();
            inp.value = '';
        }
    };

    window.removeReuploadCustomPattern = function(idx) {
        if (idx >= 0 && idx < reuploadCustomPatterns.length) {
            reuploadCustomPatterns.splice(idx, 1);
            renderReuploadChips();
        }
    };

    function renderReuploadChips() {
        const c = document.getElementById('reuploadCustomChips');
        if (!c) return;
        if (reuploadCustomPatterns.length === 0) {
            c.innerHTML = '';
            return;
        }
        c.innerHTML = reuploadCustomPatterns.map((p, idx) => `
            <span style="background:#1f2937;border:1px solid #374151;color:#a5d6ff;border-radius:4px;padding:2px 8px;font-size:0.75rem;display:inline-flex;align-items:center;gap:6px">
                <code>${p}</code>
                <button type="button" onclick="removeReuploadCustomPattern(${idx})" style="background:none;border:none;color:#ff7b72;cursor:pointer;padding:0;font-size:0.85rem;line-height:1" title="Remove">&times;</button>
            </span>
        `).join('');
    }

    function getReuploadProtectedPatterns() {
        const patterns = [];
        const chkEnv = document.getElementById('reuploadChkEnv');
        const chkSqlite = document.getElementById('reuploadChkSqlite');
        const chkDbDump = document.getElementById('reuploadChkDbDump');
        const chkStorage = document.getElementById('reuploadChkStorage');
        const chkNginx = document.getElementById('reuploadChkNginx');
        const chkGitignore = document.getElementById('reuploadChkGitignore');

        if (chkEnv && chkEnv.checked) {
            patterns.push('.env', '.env.*', '.env.local', '.env.production');
        }
        if (chkSqlite && chkSqlite.checked) {
            patterns.push('database/*.sqlite', 'database/database.sqlite', '*.sqlite', '*.db', 'storage/*.sqlite');
        }
        if (chkDbDump && chkDbDump.checked) {
            patterns.push('.wtc-database.sql', '.wtc-db.sql', '.wtc-db.php');
        }
        if (chkStorage && chkStorage.checked) {
            patterns.push('storage/app/**', 'uploads/**', 'wp-content/uploads/**');
        }
        if (chkNginx && chkNginx.checked) {
            patterns.push('nginx.conf', 'wtc.json', '.htaccess', 'wp-config.php');
        }
        if (chkGitignore && chkGitignore.checked) {
            patterns.push('.env*', '*.sqlite', 'storage/*', 'uploads/*');
        }
        reuploadCustomPatterns.forEach(cp => {
            if (!patterns.includes(cp)) patterns.push(cp);
        });
        return Array.from(new Set(patterns));
    }

    window.closeReuploadModal = function() {
        const modal = document.getElementById('reuploadModal');
        if (modal) modal.style.display = 'none';
        const fileIn = document.getElementById('reuploadArchiveInput');
        if (fileIn) fileIn.value = '';
        const dirIn = document.getElementById('reuploadFolderInput');
        if (dirIn) dirIn.value = '';
    };

    const modalBackdrop = document.getElementById('reuploadModal');
    if (modalBackdrop) {
        modalBackdrop.addEventListener('click', (e) => {
            if (e.target === modalBackdrop) closeReuploadModal();
        });
    }

    // Modal Archive Browse input
    const archiveInput = document.getElementById('reuploadArchiveInput');
    if (archiveInput) {
        archiveInput.addEventListener('change', () => {
            if (archiveInput.files && archiveInput.files.length > 0) {
                const spinCheck = document.getElementById('reuploadSpinUp');
                executeReuploadArchive(archiveInput.files[0], currentReuploadSite, spinCheck ? spinCheck.checked : true, currentReuploadIsPrimary);
            }
        });
    }

    // Modal Folder Browse input
    const folderInput = document.getElementById('reuploadFolderInput');
    if (folderInput) {
        folderInput.addEventListener('change', async () => {
            if (folderInput.files && folderInput.files.length > 0) {
                const spinCheck = document.getElementById('reuploadSpinUp');
                const list = [];
                for (let i = 0; i < folderInput.files.length; i++) {
                    const f = folderInput.files[i];
                    list.push({ file: f, path: f.webkitRelativePath || f.name });
                }
                executeReuploadFolder(list, currentReuploadSite, spinCheck ? spinCheck.checked : true, currentReuploadIsPrimary);
            }
        });
    }

    // Modal Drop Target
    const modalDrop = document.getElementById('reuploadDropTarget');
    if (modalDrop) {
        ['dragenter', 'dragover'].forEach(name => {
            modalDrop.addEventListener(name, (e) => {
                e.preventDefault(); e.stopPropagation();
                modalDrop.style.borderColor = '#2ea043';
                modalDrop.style.background = '#0e2015';
            });
        });
        ['dragleave', 'drop'].forEach(name => {
            modalDrop.addEventListener(name, (e) => {
                e.preventDefault(); e.stopPropagation();
                modalDrop.style.borderColor = '#1f6feb';
                modalDrop.style.background = '#0d1520';
            });
        });
        modalDrop.addEventListener('drop', async (e) => {
            const items = e.dataTransfer.items;
            if (!items || items.length === 0) return;
            const spinCheck = document.getElementById('reuploadSpinUp');
            const shouldSpin = spinCheck ? spinCheck.checked : true;

            const entries = [];
            for (let i = 0; i < items.length; i++) {
                const entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
                if (entry) entries.push(entry);
            }

            if (entries.length > 0 && entries[0].isDirectory) {
                const filesWithPaths = [];
                if (window.wtcDropzone && window.wtcDropzone.readDirectoryRecursive) {
                    await window.wtcDropzone.readDirectoryRecursive(entries[0], '', filesWithPaths);
                }
                executeReuploadFolder(filesWithPaths, currentReuploadSite, shouldSpin, currentReuploadIsPrimary);
            } else if (e.dataTransfer.files.length > 0) {
                executeReuploadArchive(e.dataTransfer.files[0], currentReuploadSite, shouldSpin, currentReuploadIsPrimary);
            }
        });
    }

    function executeReuploadArchive(file, siteName, shouldSpin, isPrimary = false) {
        const protPatterns = getReuploadProtectedPatterns();
        closeReuploadModal();
        if (window.wtcDropzone && window.wtcDropzone.uploadSingleArchive) {
            window.wtcDropzone.uploadSingleArchive(file, siteName, shouldSpin, protPatterns, isPrimary);
            return;
        }
        const fd = new FormData();
        fd.append('csrf', '<?= csrf() ?>');
        fd.append('action', 'drop_upload');
        fd.append('is_ajax', '1');
        fd.append('spin_up', shouldSpin ? '1' : '0');
        if (isPrimary) {
            fd.append('primary', '1');
        }
        fd.append('site', siteName);
        fd.append('archive', file);
        fd.append('protected_patterns', JSON.stringify(protPatterns));

        if (window.wtcSpa) {
            window.wtcSpa.showLoadingModal('activate', `Updating site '${siteName}'...`);
        }
        fetch('/', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (window.wtcSpa) {
                    window.wtcSpa.hideLoadingModal();
                    if (data.ok) {
                        window.wtcSpa.showFlash('ok', `Site '${siteName}' updated directly in RAM!` + (data.catalog_rebuilding ? ' (rebuilding SD catalog in background)' : (data.spun_up ? ' (active in RAM)' : '')));
                        window.wtcSpa.navigateTo('/?p=sites', false);
                    } else {
                        window.wtcSpa.showFlash('err', 'Update failed: ' + (data.error || 'Server error'));
                    }
                } else {
                    window.location.reload();
                }
            })
            .catch(err => {
                if (window.wtcSpa) {
                    window.wtcSpa.hideLoadingModal();
                    window.wtcSpa.showFlash('err', 'Upload error: ' + err.message);
                } else {
                    alert('Upload error: ' + err.message);
                }
            });
    }

    window.browseReuploadFolder = function() {
        const spinCheck = document.getElementById('reuploadSpinUp');
        const shouldSpin = spinCheck ? spinCheck.checked : true;
        if (window.wtcDropzone && window.wtcDropzone.pickDirectoryFast) {
            window.wtcDropzone.pickDirectoryFast('reuploadFolderInput', (list) => {
                executeReuploadFolder(list, currentReuploadSite, shouldSpin, currentReuploadIsPrimary);
            });
        } else {
            const inp = document.getElementById('reuploadFolderInput');
            if (inp) inp.click();
        }
    };

    async function executeReuploadFolder(filesWithPaths, siteName, shouldSpin, isPrimary = false) {
        const protPatterns = getReuploadProtectedPatterns();
        const recentMinutes = getReuploadRecentMinutes();
        closeReuploadModal();
        if (window.wtcDropzone && window.wtcDropzone.uploadFolderForSite) {
            window.wtcDropzone.uploadFolderForSite(filesWithPaths, siteName, shouldSpin, protPatterns, isPrimary, recentMinutes);
            return;
        }
    }

    // Bind drag & drop onto table rows for instant auto-updating!
    function initTableRowsDropzone() {
        const rows = document.querySelectorAll('tr.site-row');
        rows.forEach(tr => {
            const siteName = tr.getAttribute('data-site');
            const isActive = tr.getAttribute('data-active') === '1';
            const isPrimary = tr.getAttribute('data-primary') === '1';
            const badge = tr.querySelector('.row-drop-badge');

            ['dragenter', 'dragover'].forEach(name => {
                tr.addEventListener(name, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    tr.classList.add('dragover');
                    if (badge) badge.style.display = 'inline-block';
                });
            });

            ['dragleave', 'drop'].forEach(name => {
                tr.addEventListener(name, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    tr.classList.remove('dragover');
                    if (badge) badge.style.display = 'none';
                });
            });

            tr.addEventListener('drop', async (e) => {
                const items = e.dataTransfer.items;
                if (!items || items.length === 0) return;

                const entries = [];
                for (let i = 0; i < items.length; i++) {
                    const entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
                    if (entry) entries.push(entry);
                }

                if (entries.length > 0 && entries[0].isDirectory) {
                    const filesWithPaths = [];
                    if (window.wtcDropzone && window.wtcDropzone.readDirectoryRecursive) {
                        await window.wtcDropzone.readDirectoryRecursive(entries[0], '', filesWithPaths);
                    }
                    executeReuploadFolder(filesWithPaths, siteName, isActive, isPrimary);
                } else if (e.dataTransfer.files.length > 0) {
                    executeReuploadArchive(e.dataTransfer.files[0], siteName, isActive, isPrimary);
                }
            });
        });
    }

    initTableRowsDropzone();
})();
</script>

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
        <div style="margin-bottom:12px">
            <div style="font-size:0.8rem;font-weight:600;color:#8b949e;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">
                ⚡ Quick 1-Click Commands:
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px;border-color:#388bfd;color:#79c0ff" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'route:clear')">
                    🧹 route:clear
                </button>
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px;border-color:#388bfd;color:#79c0ff" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'optimize:clear')">
                    ✨ optimize:clear
                </button>
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'config:clear')">
                    ⚙️ config:clear
                </button>
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'cache:clear')">
                    💾 cache:clear
                </button>
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'view:clear')">
                    👁️ view:clear
                </button>
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px;border-color:#8957e5;color:#d2a8ff" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'ziggy:generate')">
                    🔀 ziggy:generate
                </button>
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'storage:link --force')">
                    🔗 storage:link
                </button>
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px;border-color:#2ea043;color:#7ee787" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'migrate --force')">
                    ⚡ migrate
                </button>
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px;border-color:#2ea043;color:#7ee787" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'db:seed --force')">
                    🌱 db:seed
                </button>
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'migrate:status')">
                    📊 migrate:status
                </button>
                <button type="button" class="btn" style="font-size:0.78rem;padding:3px 8px" onclick="runSingleArtisan('<?= h($activeSite) ?>', 'about')">
                    ℹ️ about
                </button>
            </div>
        </div>

        <form method="post" id="formArtisanBatch" style="background:#11171e;border:1px solid #2a3541;border-radius:6px;padding:12px 14px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="artisan">
            <input type="hidden" name="site" value="<?= h($activeSite) ?>">
            <input type="hidden" name="back" value="sites">

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px;margin-bottom:12px">
                <div>
                    <div style="font-size:0.8rem;font-weight:600;color:#7cc4ff;margin-bottom:6px">🧹 Routing &amp; Caches</div>
                    <div style="display:flex;flex-direction:column;gap:6px">
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="run_route_clear" value="1" checked>
                            <span>Clear routes (<code>route:clear</code>)</span>
                        </label>
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="run_optimize_clear" value="1" checked>
                            <span>Clear all caches (<code>optimize:clear</code>)</span>
                        </label>
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="run_optimize" value="1">
                            <span>Re-cache routes/config (<code>optimize</code>)</span>
                        </label>
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="run_ziggy_generate" value="1">
                            <span>Rebuild Ziggy routes (<code>ziggy:generate</code>)</span>
                        </label>
                    </div>
                </div>

                <div>
                    <div style="font-size:0.8rem;font-weight:600;color:#7cc4ff;margin-bottom:6px">🗄️ Database &amp; Seeds</div>
                    <div style="display:flex;flex-direction:column;gap:6px">
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="run_migrations" id="siteChkMigrate" value="1" checked>
                            <span>Run migrations (<code>migrate --force</code>)</span>
                        </label>
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="run_seeds" value="1">
                            <span>Run seeders (<code>db:seed --force</code>)</span>
                        </label>
                        <label style="font-size:0.82rem;color:#ffab70;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="run_fresh" id="siteChkFresh" value="1"
                                onchange="if(this.checked && !confirm('⚠️ Warning: Run Fresh will DROP all database tables before recreating them. Are you sure you want to run fresh?')){ this.checked = false; } else if(this.checked){ document.getElementById('siteChkMigrate').checked = false; }">
                            <span>Fresh reset (<code>migrate:fresh --force</code>)</span>
                        </label>
                    </div>
                </div>

                <div>
                    <div style="font-size:0.8rem;font-weight:600;color:#7cc4ff;margin-bottom:6px">📁 Storage &amp; Framework</div>
                    <div style="display:flex;flex-direction:column;gap:6px">
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="run_storage_link" value="1" checked>
                            <span>Link storage (<code>storage:link --force</code>)</span>
                        </label>
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="run_view_clear" value="1">
                            <span>Clear compiled views (<code>view:clear</code>)</span>
                        </label>
                        <label style="font-size:0.82rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="run_config_clear" value="1">
                            <span>Clear config cache (<code>config:clear</code>)</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Custom Command & Submit Bar -->
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;border-top:1px solid #21262d;padding-top:10px">
                <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:240px">
                    <span style="font-size:0.84rem;color:#8b949e;white-space:nowrap">php artisan</span>
                    <input type="text" name="cmd" id="artisanCustomCmd" list="artisanDefaultCommands" placeholder="or custom command (e.g. route:clear, migrate:status)" style="flex:1;background:#0d1117;color:#c9d1d9;border:1px solid #30363d;border-radius:4px;padding:4px 8px;font-size:0.84rem">
                    <datalist id="artisanDefaultCommands">
                        <option value="route:clear">Clear route cache</option>
                        <option value="route:list">List all registered routes</option>
                        <option value="optimize:clear">Clear all cached bootstrap files</option>
                        <option value="optimize">Cache the framework bootstrap files</option>
                        <option value="config:clear">Clear configuration cache</option>
                        <option value="config:cache">Create configuration cache</option>
                        <option value="cache:clear">Flush application cache</option>
                        <option value="view:clear">Clear compiled view files</option>
                        <option value="ziggy:generate">Rebuild Ziggy JS route definitions</option>
                        <option value="ziggy:generate --types">Rebuild Ziggy JS + TypeScript types</option>
                        <option value="storage:link --force">Create storage symbolic link</option>
                        <option value="migrate --force">Run database migrations</option>
                        <option value="migrate:status">Show migration status</option>
                        <option value="migrate:fresh --force">Drop tables and run migrations</option>
                        <option value="db:seed --force">Run database seeders</option>
                        <option value="key:generate --force">Generate application key</option>
                        <option value="queue:restart">Restart queue workers</option>
                        <option value="about">Display basic information about app</option>
                    </datalist>
                </div>
                <button type="submit" class="btn-pri" style="padding:6px 14px;font-size:0.85rem">Execute Selected Commands</button>
            </div>
        </form>

        <div id="artisanOutputConsole" style="display:none;margin-top:10px;background:#05080c;border:1px solid #30363d;border-radius:6px;padding:10px 12px;font-family:monospace;font-size:0.82rem;color:#c9d1d9;max-height:220px;overflow-y:auto;white-space:pre-wrap"></div>
    </div>
    <script>
        function runSingleArtisan(site, cmd) {
            const consoleBox = document.getElementById('artisanOutputConsole');
            if (consoleBox) {
                consoleBox.style.display = 'block';
                consoleBox.innerHTML = '<span style="color:#79c0ff">⏳ Running: php artisan ' + cmd + '...</span>';
            }
            const fd = new FormData();
            fd.append('csrf', '<?= csrf_token() ?>');
            fd.append('action', 'artisan');
            fd.append('is_ajax', '1');
            fd.append('site', site);
            fd.append('cmd', cmd);

            fetch('/', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (consoleBox) {
                        if (data.ok) {
                            consoleBox.innerHTML = '<span style="color:#7ee787">✅ Success:</span>\n' + (data.output || 'Command completed successfully.');
                        } else {
                            consoleBox.innerHTML = '<span style="color:#ff7b72">❌ Error:</span>\n' + (data.error || data.output || 'Execution failed.');
                        }
                    }
                })
                .catch(err => {
                    if (consoleBox) {
                        consoleBox.innerHTML = '<span style="color:#ff7b72">❌ Network error:</span> ' + err.message;
                    }
                });
        }

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

<!-- Rollback Version Selector & Manager Modal -->
<div id="rollbackModal" class="modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.72);z-index:9999;align-items:center;justify-content:center;padding:16px">
    <div class="card" style="width:100%;max-width:620px;max-height:85vh;overflow:auto;border-color:#d29922;margin:0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #30363d;padding-bottom:8px">
            <h3 style="margin:0;color:#e3b341">⏪ Rollbacks — <span id="rbModalSite"></span></h3>
            <button type="button" class="btn" onclick="closeRollbackModal()">✕ Close</button>
        </div>
        <p style="font-size:0.84rem;color:#8b949e;margin-top:0">
            Select a snapshot to restore (the current version is saved automatically before restoring), or delete snapshots to free SD storage.
        </p>
        <div id="rbModalList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px"></div>
        <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid #30363d;padding-top:10px">
            <span id="rbModalTotal" style="font-size:0.8rem;color:#8b949e"></span>
            <form method="post" onsubmit="return confirm('Clear ALL saved rollback snapshots for this site?')" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rollback_clear">
                <input type="hidden" name="back" value="sites">
                <input type="hidden" name="site" id="rbClearSite" value="">
                <button type="submit" class="danger">🧹 Clear All for Site</button>
            </form>
        </div>
    </div>
</div>
<script>
function fmtRbBytes(b) {
    if (!b || b <= 0) return '0 B';
    const u = ['B','KB','MB','GB'];
    let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i === 0 ? Math.round(b) : b.toFixed(1)) + ' ' + u[i];
}
function openRollbackModal(site, list) {
    document.getElementById('rbModalSite').textContent = site;
    document.getElementById('rbClearSite').value = site;
    const container = document.getElementById('rbModalList');
    container.innerHTML = '';
    let total = 0;
    const csrfVal = <?= json_encode(csrf()) ?>;
    if (!Array.isArray(list) || list.length === 0) {
        container.innerHTML = '<div style="color:#8b949e;padding:12px;text-align:center">No rollback snapshots found.</div>';
    } else {
        list.forEach((item, idx) => {
            total += Number(item.size || 0);
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 12px;background:#0d1117;border:1px solid #30363d;border-radius:6px;flex-wrap:wrap';
            const dt = item.mtime ? new Date(item.mtime * 1000).toLocaleString() : item.label;
            const badge = idx === 0 ? '<span class="pill ok" style="font-size:0.68rem;margin-left:6px">Latest</span>' : '';
            row.innerHTML = `
                <div>
                    <div style="font-weight:600;font-size:0.86rem"><code>${item.label}</code>${badge}</div>
                    <div style="font-size:0.76rem;color:#8b949e;margin-top:2px">${dt} · ${fmtRbBytes(item.size)}</div>
                </div>
                <div style="display:flex;gap:6px;align-items:center">
                    <form method="post" style="margin:0" onsubmit="return confirm('Restore ${site} to ${item.label}?')">
                        <input type="hidden" name="csrf" value="${csrfVal}">
                        <input type="hidden" name="action" value="rollback">
                        <input type="hidden" name="back" value="sites">
                        <input type="hidden" name="site" value="${site}">
                        <input type="hidden" name="version" value="${item.id}">
                        <button type="submit" class="btn-pri" style="padding:4px 10px;font-size:0.78rem">⏪ Restore</button>
                    </form>
                    <form method="post" style="margin:0" onsubmit="return confirm('Delete rollback snapshot ${item.label}?')">
                        <input type="hidden" name="csrf" value="${csrfVal}">
                        <input type="hidden" name="action" value="rollback_delete">
                        <input type="hidden" name="back" value="sites">
                        <input type="hidden" name="site" value="${site}">
                        <input type="hidden" name="version" value="${item.id}">
                        <button type="submit" class="danger" style="padding:4px 8px;font-size:0.78rem">🗑️</button>
                    </form>
                </div>`;
            container.appendChild(row);
        });
    }
    document.getElementById('rbModalTotal').textContent = 'Total stored: ' + fmtRbBytes(total);
    document.getElementById('rollbackModal').style.display = 'flex';
}
function closeRollbackModal() {
    document.getElementById('rollbackModal').style.display = 'none';
}
</script>
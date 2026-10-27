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
        <div style="display:flex;align-items:center;gap:10px">
            <small style="color:#8b949e">💡 <b>Drop &amp; Auto-Update:</b> Drag and drop folders or archives directly onto any row below to update that application instantly.</small>
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
                        <?php endif; ?>
                        <?php if ($isActive && $isPrimary && !empty($st['workers']['queue']['running'])): ?>
                            <span class="pill" style="background:#13233a;color:#79c0ff;border:1px solid #1f6feb">⚙️ workers
                                active</span>
                        <?php endif; ?>
                    </div>
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
                Database credentials, <code>.env</code> configurations, database snapshots, and user storage are <b>automatically protected and preserved</b>.
            </p>

            <!-- Drop target inside modal -->
            <div id="reuploadDropTarget" style="border:2px dashed #1f6feb;background:#0d1520;border-radius:8px;padding:22px 16px;text-align:center;cursor:pointer;transition:border-color .15s ease, background .15s ease;margin-bottom:14px" onclick="document.getElementById('reuploadArchiveInput').click()">
                <div style="font-size:2rem;margin-bottom:6px">📦⚡</div>
                <b style="color:#79c0ff;font-size:0.95rem">Drag &amp; drop package or folder here</b>
                <div style="font-size:0.8rem;color:#8b949e;margin-top:4px">or click to browse from your computer</div>
            </div>

            <div style="display:flex;gap:10px;justify-content:center;margin-bottom:14px">
                <button type="button" class="btn" onclick="document.getElementById('reuploadArchiveInput').click()">📦 Browse Archive (.zip / .tar.gz)</button>
                <button type="button" class="btn" onclick="document.getElementById('reuploadFolderInput').click()">📁 Browse Folder</button>
            </div>

            <input type="file" id="reuploadArchiveInput" accept=".zip,.tar,.gz,.tgz,.tar.gz" style="display:none">
            <input type="file" id="reuploadFolderInput" webkitdirectory directory style="display:none">

            <!-- Options -->
            <div style="background:#11171e;border:1px solid #21262d;border-radius:6px;padding:10px 14px;margin-bottom:12px;display:flex;flex-direction:column;gap:8px">
                <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem;color:#c9d1d9;cursor:pointer">
                    <input type="checkbox" id="reuploadSpinUp" checked>
                    <span>⚡ <b>Update and reload live in RAM immediately</b></span>
                </label>
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

<script>
(function() {
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

        applyReuploadPreset(currentReuploadStack || 'auto');
        if (modal) modal.style.display = 'flex';
    };

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
                        window.wtcSpa.showFlash('ok', `Site '${siteName}' updated successfully!` + (data.spun_up ? ' (active in RAM)' : ''));
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

    async function executeReuploadFolder(filesWithPaths, siteName, shouldSpin, isPrimary = false) {
        const protPatterns = getReuploadProtectedPatterns();
        closeReuploadModal();
        if (window.wtcDropzone && window.wtcDropzone.uploadFolderForSite) {
            window.wtcDropzone.uploadFolderForSite(filesWithPaths, siteName, shouldSpin, protPatterns, isPrimary);
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
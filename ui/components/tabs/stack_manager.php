<?php
declare(strict_types=1);
/**
 * Tab Container: Stack Manager
 * Multi-instance application stack selection, build commands runner,
 * dedicated port configuration, and site renaming / cloning.
 */

require_once __DIR__ . '/../stacks.php';

$sites = wj('sites');
$buildJob = wj('build');
$deviceStack = wj('stack');
$stackProfiles = get_stack_profiles();

// Compile list of installed system component IDs
$installedComponents = [];
foreach ($deviceStack['components'] ?? [] as $comp) {
    if (!empty($comp['installed'])) {
        $installedComponents[$comp['id']] = true;
    }
}

// Determine selected site
$selectedSiteName = trim((string)($_GET['site'] ?? ''));
$selectedSite = null;

if (!empty($sites)) {
    if ($selectedSiteName !== '') {
        foreach ($sites as $s) {
            if ($s['name'] === $selectedSiteName) {
                $selectedSite = $s;
                break;
            }
        }
    }
    // Fallback to primary active site or first available
    if (!$selectedSite) {
        foreach ($sites as $s) {
            if (!empty($s['isPrimary'])) {
                $selectedSite = $s;
                break;
            }
        }
        if (!$selectedSite) {
            $selectedSite = $sites[0];
        }
        $selectedSiteName = $selectedSite['name'];
    }
}
?>

<div class="card" style="border-left: 4px solid #58a6ff; background: #0c1524; margin-bottom: 16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
            <h3 style="margin:0;border:0;padding:0;color:#58a6ff;font-size:1.05rem">🥞 Stack &amp; Instance Manager</h3>
            <p style="margin:4px 0 0 0;color:#8b949e;font-size:0.85rem">
                Select an application to assign its runtime stack, execute custom build commands, configure dedicated listening ports, or duplicate instances to host parallel environments in RAM.
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
            <a href="/?p=sites" class="btn" title="Go to Sites &amp; Dropzone">📁 Sites &amp; Apps</a>
            <a href="/?p=stack" class="btn" title="Inspect system package bundles">📦 Stack Packages</a>
        </div>
    </div>
</div>

<?php if (empty($sites)): ?>
    <div class="card" style="text-align:center;padding:40px 20px">
        <h3 style="justify-content:center;border:0">No Installed Applications Found</h3>
        <p style="color:#8b949e;margin-bottom:16px">You can upload a site archive (.zip, .tar.gz) or create a starter skeleton to begin managing application stacks.</p>
        <div style="display:inline-flex;gap:10px">
            <a href="/?p=sites" class="btn-pri">🚀 Open Dropzone &amp; Upload Site</a>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="site" value="starter-app">
                <input type="hidden" name="back" value="stack_manager">
                <button class="btn">✨ Create Starter Skeleton</button>
            </form>
        </div>
    </div>
<?php else: ?>

    <?php
    $slug = !empty($selectedSite['slug']) ? $selectedSite['slug'] : strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '', $selectedSite['name'])));
    $isActive = !empty($selectedSite['active']);
    $isPrimary = !empty($selectedSite['isPrimary']);
    $currentStack = !empty($selectedSite['stack']) ? $selectedSite['stack'] : 'unknown';
    $customPort = !empty($selectedSite['port']) ? (int)$selectedSite['port'] : null;
    $siteUrl = site_url('/' . $slug . '/');
    $portUrl = $customPort ? site_port_url($customPort, '/') : null;
    ?>

    <!-- Site / Instance Selector Bar -->
    <div class="card" style="background:#111822;border-color:#2a3644;margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <label for="stackSiteSelect" style="font-weight:600;font-size:0.9rem;color:#f0f6fc">
                    🎯 Selected Application / Instance:
                </label>
                <select id="stackSiteSelect" style="background:#0d1117;border:1px solid #388bfd;color:#58a6ff;padding:6px 12px;border-radius:4px;font-weight:600;font-size:0.9rem;cursor:pointer"
                    onchange="if (window.wtcSpa) { window.wtcSpa.navigateTo('/?p=stack_manager&site=' + encodeURIComponent(this.value)); } else { location.href='/?p=stack_manager&site=' + encodeURIComponent(this.value); }">
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= h($s['name']) ?>" <?= $s['name'] === $selectedSiteName ? 'selected' : '' ?>>
                            <?= h($s['name']) ?> <?= !empty($s['isPrimary']) ? '(⭐ Primary)' : (!empty($s['active']) ? '(Active)' : '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status Pills & Quick Launch Links -->
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <?php if ($isActive): ?>
                    <span class="pill ok">Active in RAM</span>
                    <?php if ($isPrimary): ?>
                        <span class="pill" style="background:#1f6feb;color:#fff" title="Primary site serving Port 80 root">⭐ Primary</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="pill off">Inactive (SD Storage)</span>
                <?php endif; ?>

                <?php
                $badgeStyle = match ($currentStack) {
                    'laravel' => ['⚡ Laravel', '#1f3322', '#7ee787'],
                    'spa', 'react' => ['⚛️ SPA / React', '#13233a', '#79c0ff'],
                    'php', 'lamp' => ['🐘 PHP', '#2a1b3d', '#d2a8ff'],
                    'static' => ['📄 Static Web', '#2d2518', '#e3b341'],
                    'wordpress' => ['📝 WordPress', '#162b3a', '#66c0f4'],
                    'nodejs' => ['🟢 Node.js', '#142918', '#56d364'],
                    'python' => ['🐍 Python', '#252118', '#ffdf5d'],
                    default => ['📦 ' . ucfirst($currentStack), '#21262d', '#8b949e']
                };
                ?>
                <span class="pill" style="background:<?= $badgeStyle[1] ?>;color:<?= $badgeStyle[2] ?>"><?= $badgeStyle[0] ?></span>

                <?php if ($customPort): ?>
                    <a href="<?= h($portUrl) ?>" target="_blank" class="pill ok" style="text-decoration:none" title="Open site on dedicated port :<?= $customPort ?>">
                        🚀 Port <?= $customPort ?>
                    </a>
                <?php endif; ?>

                <?php if ($isActive): ?>
                    <a href="<?= h($siteUrl) ?>" target="_blank" class="btn" style="padding:4px 10px;font-size:0.8rem">
                        🌐 Open /<?= h($slug) ?>/
                    </a>
                <?php else: ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="site" value="<?= h($selectedSiteName) ?>">
                        <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($selectedSiteName) ?>">
                        <button class="btn-pri" style="padding:4px 10px;font-size:0.8rem">⚡ Activate in RAM</button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn" style="padding:4px 10px;font-size:0.8rem;border-color:#388bfd;color:#79c0ff"
                    onclick="if (window.WtcLogViewer) { window.WtcLogViewer.openModal({ site: <?= h(json_encode($selectedSiteName)) ?>, title: <?= h(json_encode('📜 Stack Logs — ' . $selectedSiteName)) ?> }); }"
                    title="View formatted stack &amp; runtime logs for <?= h($selectedSiteName) ?>">
                    📜 View Logs
                </button>
            </div>
        </div>
    </div>

    <!-- Active Build Banner (if running) -->
    <?php if (!empty($buildJob['busy'])): ?>
        <div class="card" style="border-color:#388bfd;background:#0d1b2a;margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <h4 style="color:#79c0ff;margin:0">🔨 Background Build in Progress (<?= h($buildJob['site'] ?? $selectedSiteName) ?>)...</h4>
                <span class="pill ok">Building in RAM</span>
            </div>
            <pre style="background:#050a10;color:#7ee787;padding:12px;border-radius:4px;max-height:160px;overflow-y:auto;font-size:0.78rem;font-family:monospace;white-space:pre-wrap"><?= h($buildJob['log'] ?? 'Executing build...') ?></pre>
            <small style="color:#8b949e">Auto-refreshing until build completes...</small>
            <script>
                setTimeout(() => {
                    if (window.wtcSpa) {
                        window.wtcSpa.navigateTo('/?p=stack_manager&site=<?= urlencode($selectedSiteName) ?>', false);
                    } else {
                        location.reload();
                    }
                }, 3000);
            </script>
        </div>
    <?php endif; ?>

    <!-- Two-column row: Stack Selection & Build Runner -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(460px, 1fr));gap:16px;margin-bottom:16px">

        <!-- Card: Application Stack Selection -->
        <div class="card" style="margin-bottom:0">
            <div class="card-head">
                <h3>🥞 Assign Application Stack</h3>
                <span style="font-size:0.82rem;color:#8b949e">
                    Current: <b style="color:<?= $badgeStyle[2] ?>"><?= $badgeStyle[0] ?></b>
                </span>
            </div>
            <p style="font-size:0.84rem;color:#8b949e;margin-bottom:14px">
                Choose the stack runtime profile for <b><?= h($selectedSiteName) ?></b>. Setting the stack optimizes Nginx routing rules, FastCGI parameters, public asset routing, and environment templates.
            </p>

            <div style="display:flex;flex-direction:column;gap:10px;max-height:480px;overflow-y:auto;padding-right:4px">
                <?php foreach ($stackProfiles as $pId => $p): ?>
                    <?php
                    $isCur = ($currentStack === $pId) || ($pId === 'spa' && $currentStack === 'react') || ($pId === 'lamp' && $currentStack === 'php');
                    // Check missing components
                    $missing = [];
                    foreach ($p['required_components'] as $rc) {
                        if (empty($installedComponents[$rc])) {
                            $missing[] = $rc;
                        }
                    }
                    $isReady = empty($missing);
                    ?>
                    <div style="border:1px solid <?= $isCur ? '#388bfd' : '#21262d' ?>;background:<?= $isCur ? '#0e1826' : '#0d1117' ?>;border-radius:6px;padding:12px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;transition:all 0.15s ease">
                        <div style="flex:1">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                <span style="font-size:1.1rem"><?= $p['icon'] ?></span>
                                <b style="color:#f0f6fc;font-size:0.9rem"><?= h($p['name']) ?></b>
                                <?php if ($isCur): ?>
                                    <span class="pill ok" style="font-size:0.68rem">Active Stack</span>
                                <?php endif; ?>
                            </div>
                            <p style="font-size:0.8rem;color:#8b949e;margin:0 0 8px 0"><?= h($p['description']) ?></p>
                            <div style="display:flex;gap:12px;font-size:0.75rem;color:#c9d1d9;flex-wrap:wrap">
                                <span>📁 Webroot: <code><?= h($p['webroot'] ?: 'Root (/)') ?></code></span>
                                <?php if (!empty($p['config_file'])): ?>
                                    <span>⚙️ Config: <code><?= h($p['config_file']) ?></code></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!$isReady): ?>
                                <div style="margin-top:6px;font-size:0.74rem;color:#f85149">
                                    ⚠️ Missing components on Pi: <code><?= h(implode(', ', $missing)) ?></code>
                                </div>
                            <?php else: ?>
                                <div style="margin-top:6px;font-size:0.74rem;color:#3fb950">
                                    ✓ All required system components installed
                                </div>
                            <?php endif; ?>
                        </div>

                        <div style="white-space:nowrap">
                            <?php if ($isCur): ?>
                                <button type="button" class="btn" disabled style="opacity:0.6;cursor:default">Selected</button>
                            <?php else: ?>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="site_stack">
                                    <input type="hidden" name="site" value="<?= h($selectedSiteName) ?>">
                                    <input type="hidden" name="stack" value="<?= h($pId) ?>">
                                    <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($selectedSiteName) ?>">
                                    <button type="submit" class="btn-pri" style="font-size:0.8rem;padding:5px 12px">
                                        Select Stack
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Card: Build Runner & Dedicated Port -->
        <div style="display:flex;flex-direction:column;gap:16px">

            <!-- Build Commands Runner -->
            <div class="card" style="margin-bottom:0">
                <div class="card-head">
                    <h3>🔨 Build Commands Runner</h3>
                    <?php if (!empty($buildJob['log'])): ?>
                        <small style="color:#8b949e">Last Build Output Available</small>
                    <?php endif; ?>
                </div>
                <p style="font-size:0.84rem;color:#8b949e;margin-bottom:12px">
                    Execute build scripts or asset compilation pipelines for <b><?= h($selectedSiteName) ?></b> in the RAM environment.
                </p>

                <!-- Preset Quick Buttons -->
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="build">
                        <input type="hidden" name="site" value="<?= h($selectedSiteName) ?>">
                        <input type="hidden" name="cmd" value="npm run build">
                        <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($selectedSiteName) ?>">
                        <button class="btn" title="Run npm run build (auto-rebuilds Laravel Ziggy routes first if present)">⚡ npm run build</button>
                    </form>

                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="build">
                        <input type="hidden" name="site" value="<?= h($selectedSiteName) ?>">
                        <input type="hidden" name="cmd" value="php artisan ziggy:generate">
                        <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($selectedSiteName) ?>">
                        <button class="btn" title="Rebuild Laravel Ziggy JS/TS route definitions">🔀 ziggy:generate</button>
                    </form>

                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="build">
                        <input type="hidden" name="site" value="<?= h($selectedSiteName) ?>">
                        <input type="hidden" name="cmd" value="composer install --no-dev --optimize-autoloader">
                        <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($selectedSiteName) ?>">
                        <button class="btn" title="Run Composer dependency install">📦 composer install</button>
                    </form>

                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="build">
                        <input type="hidden" name="site" value="<?= h($selectedSiteName) ?>">
                        <input type="hidden" name="cmd" value="php artisan optimize">
                        <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($selectedSiteName) ?>">
                        <button class="btn" title="Clear and cache Laravel routes and configs">🅰️ artisan optimize</button>
                    </form>
                </div>

                <!-- Custom Build Command Form -->
                <form method="post" style="display:flex;gap:8px;margin-bottom:14px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="build">
                    <input type="hidden" name="site" value="<?= h($selectedSiteName) ?>">
                    <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($selectedSiteName) ?>">
                    <input type="text" name="cmd" placeholder="Custom command (e.g. npm ci && npm run build:prod)"
                        style="flex:1;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:6px 10px;border-radius:4px;font-size:0.84rem;font-family:monospace">
                    <button type="submit" class="btn-pri" style="font-size:0.84rem">Run Command</button>
                </form>

                <!-- Build Terminal / Output Window -->
                <?php if (!empty($buildJob['log'])): ?>
                    <div style="margin-top:6px">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                            <small style="color:#8b949e;font-size:0.75rem">Terminal Output:</small>
                        </div>
                        <pre style="background:#050a10;color:#7ee787;padding:12px;border-radius:4px;max-height:160px;overflow-y:auto;font-size:0.76rem;font-family:monospace;white-space:pre-wrap;border:1px solid #1c2128;margin:0"><?= h($buildJob['log']) ?></pre>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Dedicated Port Configuration Card -->
            <div class="card" style="margin-bottom:0">
                <div class="card-head">
                    <h3>🌐 Dedicated Port Routing</h3>
                    <?php if ($customPort): ?>
                        <span class="pill ok">:<?= $customPort ?> Active</span>
                    <?php else: ?>
                        <span class="pill off">Default /<?= h($slug) ?>/</span>
                    <?php endif; ?>
                </div>
                <p style="font-size:0.84rem;color:#8b949e;margin-bottom:12px">
                    Assign a dedicated TCP port to <b><?= h($selectedSiteName) ?></b>. WTC Pi automatically provisions a dedicated Nginx server listener and opens the port in the UFW firewall.
                </p>

                <form method="post" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="site_port">
                    <input type="hidden" name="site" value="<?= h($selectedSiteName) ?>">
                    <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($selectedSiteName) ?>">

                    <label style="font-size:0.84rem;color:#c9d1d9;display:flex;align-items:center;gap:6px">
                        Port:
                        <input type="number" name="port" min="1" max="65535" value="<?= h((string)($customPort ?? '')) ?>"
                            placeholder="e.g. 8081, 8082, 3000"
                            style="width:160px;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:5px 8px;border-radius:4px;font-size:0.84rem">
                    </label>

                    <button type="submit" class="btn-pri" style="font-size:0.84rem">Save Port</button>

                    <?php if ($customPort): ?>
                        <button type="submit" name="port" value="" class="btn" style="color:#f85149;border-color:#da3633"
                            onclick="return confirm('Remove dedicated port listener for <?= h($selectedSiteName) ?>?')">
                            Clear Port
                        </button>
                        <a href="<?= h($portUrl) ?>" target="_blank" class="btn" style="background:#1d6b3a;color:#fff;border-color:#2ea043;font-size:0.84rem">
                            🚀 Launch Port <?= $customPort ?>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

        </div>
    </div>

    <!-- Card: Multiple Instances & Site Renaming -->
    <div class="card" style="margin-bottom:16px">
        <div class="card-head">
            <h3>📋 Instance Management &amp; Renaming</h3>
            <span style="font-size:0.8rem;color:#8b949e">Run multiple versions or environments side-by-side</span>
        </div>
        <p style="font-size:0.84rem;color:#8b949e;margin-bottom:14px">
            Change the name of <b><?= h($selectedSiteName) ?></b>, or clone it into a completely separate instance (e.g. <code><?= h($selectedSiteName) ?>-v2</code> or <code><?= h($selectedSiteName) ?>-dev</code>). Each cloned instance gets its own independent database, dedicated port, and RAM directory.
        </p>

        <div class="grid2" style="margin-top:0">
            <!-- Rename Box -->
            <div style="background:#090d13;border:1px solid #21262d;border-radius:6px;padding:14px">
                <h4 style="margin:0 0 8px 0;font-size:0.88rem;color:#f0f6fc">✏️ Rename Site Package</h4>
                <p style="font-size:0.8rem;color:#8b949e;margin-bottom:10px">
                    Modifies the site identifier and URL slug for this package.
                </p>
                <form method="post" style="display:flex;gap:8px" onsubmit="return confirm('Rename site package <?= h($selectedSiteName) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="site_rename">
                    <input type="hidden" name="site" value="<?= h($selectedSiteName) ?>">
                    <input type="hidden" name="back" value="stack_manager">
                    <input type="text" name="new_name" placeholder="new-site-name" value="<?= h($selectedSiteName) ?>"
                        style="flex:1;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:6px 10px;border-radius:4px;font-size:0.84rem">
                    <button type="submit" class="btn" style="font-size:0.84rem">Rename</button>
                </form>
            </div>

            <!-- Clone / Duplicate Box -->
            <div style="background:#090d13;border:1px solid #21262d;border-radius:6px;padding:14px">
                <h4 style="margin:0 0 8px 0;font-size:0.88rem;color:#58a6ff">📋 Duplicate to New Instance</h4>
                <p style="font-size:0.8rem;color:#8b949e;margin-bottom:10px">
                    Duplicates this site archive, database, and metadata to run multiple versions or on a separate port.
                </p>
                <form method="post" style="display:flex;flex-direction:column;gap:10px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="site_clone">
                    <input type="hidden" name="site" value="<?= h($selectedSiteName) ?>">
                    <input type="hidden" name="back" value="stack_manager">
                    
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="text" name="new_name" placeholder="<?= h($selectedSiteName) ?>-v2" required pattern="[a-z0-9_-]+"
                            style="flex:1;background:#0d1117;border:1px solid #388bfd;color:#e6edf3;padding:6px 10px;border-radius:4px;font-size:0.84rem" title="Target instance name">
                        <input type="number" name="port" min="1" max="65535" placeholder="Port (e.g. 8082)"
                            style="width:130px;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:6px 10px;border-radius:4px;font-size:0.84rem" title="Optional dedicated TCP port">
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                        <select name="db_mode" style="background:#0d1117;border:1px solid #30363d;color:#c9d1d9;padding:4px 8px;border-radius:4px;font-size:0.78rem">
                            <option value="clone" selected>🗄️ Clone DB &amp; Data</option>
                            <option value="new">🆕 Fresh Empty DB</option>
                            <option value="share">🔄 Share Same DB</option>
                        </select>
                        <label style="font-size:0.78rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="activate" value="1" checked>
                            <span>⚡ Spin up in RAM</span>
                        </label>
                        <button type="submit" class="btn-pri" style="font-size:0.84rem;padding:5px 12px">🚀 Duplicate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- All Installed Instances & Applications Table -->
    <div class="card">
        <div class="card-head">
            <h3>📑 All Installed Site Instances</h3>
            <span style="font-size:0.8rem;color:#8b949e"><?= count($sites) ?> installed instances</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Site Instance</th>
                    <th>Stack</th>
                    <th>State</th>
                    <th>Port / URL</th>
                    <th>Archive Size</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $s): ?>
                    <?php
                    $isCurRow = ($s['name'] === $selectedSiteName);
                    $sSlug = !empty($s['slug']) ? $s['slug'] : strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '', $s['name'])));
                    $sActive = !empty($s['active']);
                    $sPrimary = !empty($s['isPrimary']);
                    $sPort = !empty($s['port']) ? (int)$s['port'] : null;
                    $sStack = !empty($s['stack']) ? $s['stack'] : 'unknown';
                    ?>
                    <tr style="<?= $isCurRow ? 'background:#131c27;' : '' ?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:6px">
                                <b><?= h($s['name']) ?></b>
                                <?php if ($isCurRow): ?>
                                    <span class="pill" style="background:#1f6feb;color:#fff;font-size:0.68rem">Selected</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <code><?= h($sStack) ?></code>
                        </td>
                        <td>
                            <?php if ($sActive): ?>
                                <span class="pill ok">Active</span>
                                <?php if ($sPrimary): ?>
                                    <span class="pill" style="background:#1f6feb;color:#fff;font-size:0.68rem">⭐ Primary</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="pill off">Storage</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div>
                                <a href="<?= h(site_url('/' . $sSlug . '/')) ?>" target="_blank" style="color:#58a6ff;text-decoration:none;font-size:0.8rem">
                                    /<?= h($sSlug) ?>/
                                </a>
                                <?php if ($sPort): ?>
                                    <a href="<?= h(site_port_url($sPort, '/')) ?>" target="_blank" class="pill ok" style="text-decoration:none;font-size:0.68rem;margin-left:4px">
                                        :<?= $sPort ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= fmt_bytes($s['size']) ?></td>
                        <td class="act">
                            <div style="display:inline-flex;gap:6px;align-items:center">
                                <?php if (!$isCurRow): ?>
                                    <a href="/?p=stack_manager&site=<?= urlencode($s['name']) ?>" class="btn" style="padding:3px 8px;font-size:0.75rem">
                                        Manage
                                    </a>
                                <?php endif; ?>

                                <?php if ($sActive): ?>
                                    <?php if (!$sPrimary): ?>
                                        <form method="post" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="set_primary">
                                            <input type="hidden" name="site" value="<?= h($s['name']) ?>">
                                            <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($s['name']) ?>">
                                            <button class="btn" style="padding:3px 8px;font-size:0.75rem" title="Set as Primary on Port 80">⭐ Primary</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="site" value="<?= h($s['name']) ?>">
                                        <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($s['name']) ?>">
                                        <button class="btn" style="padding:3px 8px;font-size:0.75rem;color:#f85149" title="Stop and deactivate from RAM">Stop</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="site" value="<?= h($s['name']) ?>">
                                        <input type="hidden" name="back" value="stack_manager&site=<?= urlencode($s['name']) ?>">
                                        <button class="btn-pri" style="padding:3px 8px;font-size:0.75rem">Activate</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

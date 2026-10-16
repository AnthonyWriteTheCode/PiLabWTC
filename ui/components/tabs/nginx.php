<?php
declare(strict_types=1);
/**
 * Tab Container: Nginx Config & Routing
 * Interactive configuration editor, syntax validation, active webroot inspector, and service restart.
 */

$nginx = wj('nginx');
$ok = !empty($nginx['ok']);
$testOutput = $nginx['test'] ?? 'No test output available';
$wtcConf = $nginx['wtc'] ?? '';
$siteConf = $nginx['site'] ?? '';
$ramConf = $nginx['ram'] ?? ($siteConf !== '' ? $siteConf : '');
$uiConf = $nginx['ui'] ?? '';
$curSite = $nginx['cur'] ?? '';
$curStack = $nginx['stack'] ?? 'unknown';
$webroot = $nginx['webroot'] ?? '';
$live = $nginx['live'] ?? '';

$stackBadges = [
    'laravel' => ['⚡ Laravel Stack', '#1f3322', '#7ee787', '#238636'],
    'spa'     => ['⚛️ Single Page App (SPA)', '#13233a', '#79c0ff', '#1f6feb'],
    'php'     => ['🐘 Classic PHP Stack', '#2a1b3d', '#d2a8ff', '#8957e5'],
    'static'  => ['📄 Static HTML Stack', '#2d2518', '#e3b341', '#9e6a03'],
    'unknown' => ['❓ Generic Stack', '#21262d', '#8b949e', '#30363d']
];
$badge = $stackBadges[$curStack] ?? $stackBadges['unknown'];
$targetParam = (string)($_GET['target'] ?? 'ram');
?>

<!-- Header Summary Card -->
<div class="card" style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h3 style="margin-bottom:4px;color:#7cc4ff;display:flex;align-items:center;gap:8px">
                🌐 Nginx Reverse Proxy &amp; Routing Editor
                <?php if ($ok): ?>
                    <span class="pill ok" style="font-size:0.75rem">Syntax OK</span>
                <?php else: ?>
                    <span class="pill off" style="font-size:0.75rem">Syntax Error</span>
                <?php endif; ?>
                <?php if ($curSite !== ''): ?>
                    <span class="pill" style="background:<?= $badge[1] ?>;color:<?= $badge[2] ?>;border:1px solid <?= $badge[3] ?>;font-size:0.75rem">
                        <?= $badge[0] ?>
                    </span>
                <?php endif; ?>
            </h3>
            <div style="font-size:0.85rem;color:#8b949e">
                Configure root paths, FastCGI PHP-FPM integration, and live RAM drive vhost routing.
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <?php if ($curSite !== ''): ?>
                <a href="<?= site_url('/') ?>" target="_blank" class="btn-ctrl" style="text-decoration:none;font-size:0.82rem;padding:6px 12px;background:#162536;color:#79c0ff;border-color:#1f6feb">🌐 Live Site (Port 80)</a>
                <a href="<?= site_port_url(8080, '/') ?>" target="_blank" class="btn-ctrl" style="text-decoration:none;font-size:0.82rem;padding:6px 12px;background:#241d3b;color:#d2a8ff;border-color:#8957e5">🚀 Open Port 8080</a>
            <?php endif; ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="nginx_ctl">
                <input type="hidden" name="do" value="reload">
                <button type="submit" class="btn-ctrl">🔄 Reload</button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Restart Nginx service? Active requests will quickly reconnect.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="nginx_ctl">
                <input type="hidden" name="do" value="restart">
                <button type="submit" class="btn-pri" style="background:#238636;border-color:#2ea043">⚡ Restart Nginx</button>
            </form>
        </div>
    </div>
</div>

<!-- Active Project Webroot Inspector -->
<div class="card" style="margin-bottom:16px;background:#11171e;border-color:#30363d">
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px;font-size:0.84rem">
        <div>
            <span style="color:#8b949e;display:block;margin-bottom:2px">Active Site:</span>
            <b style="color:#fff"><?= $curSite !== '' ? h($curSite) : '<i style="color:#8b949e">None loaded</i>' ?></b>
            <?php if ($curSite !== ''): ?>
                <span style="color:#7ee787;margin-left:4px">(<?= h($curStack) ?>)</span>
            <?php endif; ?>
        </div>
        <div>
            <span style="color:#8b949e;display:block;margin-bottom:2px">Project Files Base:</span>
            <code style="background:#050a10;color:#79c0ff;padding:2px 6px;border-radius:4px"><?= h($live !== '' ? $live : '/mnt/ramdisk/www') ?></code>
        </div>
        <div>
            <span style="color:#8b949e;display:block;margin-bottom:2px">Nginx Active Webroot:</span>
            <code style="background:#050a10;color:#7ee787;padding:2px 6px;border-radius:4px"><?= h($webroot !== '' ? $webroot : '/mnt/ramdisk/webroot') ?></code>
        </div>
        <div>
            <span style="color:#8b949e;display:block;margin-bottom:2px">Live Configuration Snippet:</span>
            <code style="background:#050a10;color:#d2a8ff;padding:2px 6px;border-radius:4px">/mnt/ramdisk/nginx.conf</code>
        </div>
    </div>
</div>

<!-- Live Test Result Banner -->
<div id="nginxTestBanner" class="card" style="margin-bottom:16px;border-color:<?= $ok ? '#238636' : '#da3633' ?>;background:<?= $ok ? '#0d1d15' : '#220e10' ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
        <b id="nginxTestStatusLabel" style="color:<?= $ok ? '#7ee787' : '#f85149' ?>;font-size:0.88rem">
            <?= $ok ? '✓ Configuration Test (nginx -t): PASSED' : '✕ Configuration Test (nginx -t): SYNTAX ERROR' ?>
        </b>
        <button type="button" class="btn-ctrl" onclick="testCurrentConfig()" style="font-size:0.75rem;padding:3px 10px">🧪 Test Syntax Now</button>
    </div>
    <pre id="nginxTestOutput" style="background:#050a10;color:<?= $ok ? '#7ee787' : '#f85149' ?>;padding:10px 14px;border-radius:6px;font-family:monospace;font-size:0.8rem;margin:0;white-space:pre-wrap"><?= h(trim($testOutput)) ?></pre>
</div>

<!-- Interactive Configuration Editor -->
<div class="card" style="margin-bottom:16px;border-color:#388bfd">
    <form id="nginxConfigForm" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="nginx_save">
        <input type="hidden" id="targetFileInput" name="target" value="<?= h($targetParam) ?>">
        <input type="hidden" id="serviceActionInput" name="service_action" value="restart">

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px">
            <!-- Target File Selection Tabs -->
            <div style="display:flex;gap:6px;flex-wrap:wrap" role="tablist">
                <button type="button" class="btn-ctrl tab-btn <?= $targetParam === 'ram' ? 'active-target' : '' ?>" onclick="switchTargetFile('ram')">
                    ⚡ Active RAM Config (<code>nginx.conf</code>)
                </button>
                <button type="button" class="btn-ctrl tab-btn <?= $targetParam === 'wtc' ? 'active-target' : '' ?>" onclick="switchTargetFile('wtc')">
                    🌐 Port 80 Primary VHost (<code>wtc</code>)
                </button>
                <button type="button" class="btn-ctrl tab-btn <?= $targetParam === 'ui' ? 'active-target' : '' ?>" onclick="switchTargetFile('ui')">
                    🛠️ UI VHost Port 4485 (<code>wtc-ui</code>)
                </button>
            </div>

            <!-- Quick Stack Presets -->
            <div id="stackPresetsGroup" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <span style="font-size:0.78rem;color:#8b949e">Templates:</span>
                <button type="button" class="btn-ctrl" onclick="applyPreset('laravel')" style="font-size:0.76rem;padding:3px 8px;border-color:#238636;color:#7ee787" title="Set document root to /mnt/ramdisk/webroot (public/) with clean Laravel routing">
                    🅰️ Laravel Webroot
                </button>
                <button type="button" class="btn-ctrl" onclick="applyPreset('spa')" style="font-size:0.76rem;padding:3px 8px;border-color:#1f6feb;color:#79c0ff" title="SPA fallback routing for React / Vite">
                    ⚛️ React/SPA
                </button>
                <button type="button" class="btn-ctrl" onclick="applyPreset('php')" style="font-size:0.76rem;padding:3px 8px;border-color:#8957e5;color:#d2a8ff" title="Classic PHP routing">
                    🐘 Classic PHP
                </button>
                <button type="button" class="btn-ctrl" onclick="resetToStackDefault()" style="font-size:0.76rem;padding:3px 8px;color:#e3b341" title="Auto-detect stack and reset config">
                    🔄 Reset Default
                </button>
            </div>
        </div>

        <div style="position:relative">
            <div id="fileIndicator" style="display:flex;justify-content:space-between;padding:6px 12px;background:#0d1117;border:1px solid #30363d;border-bottom:none;border-radius:6px 6px 0 0;font-size:0.8rem;color:#8b949e">
                <span id="filePathLabel">Editing: <code>/mnt/ramdisk/nginx.conf</code> (Included by <code>/etc/nginx/wtc-site.conf</code>)</span>
                <span style="color:#7ee787">Tab = 4 spaces</span>
            </div>
            <textarea
                id="nginxConfigTextarea"
                name="config_content"
                rows="20"
                spellcheck="false"
                style="width:100%;background:#050a10;color:#c9d1d9;font-family:ui-monospace,SFMono-Regular,SF Mono,Menlo,Consolas,Liberation Mono,monospace;font-size:0.84rem;line-height:1.5;padding:14px;border:1px solid #30363d;border-radius:0 0 6px 6px;resize:vertical;outline:none"
            ><?= h($ramConf) ?></textarea>
        </div>

        <!-- Editor Toolbar & Submit Actions -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;flex-wrap:wrap;gap:10px">
            <div style="font-size:0.82rem;color:#8b949e">
                💡 <span id="saveHint">Syntax is automatically verified with <code>nginx -t</code> before applying. If a syntax error is found, changes are safely reverted.</span>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn-ctrl" onclick="testCurrentConfig()" style="font-weight:600">
                    🧪 Test Syntax
                </button>
                <button type="button" class="btn-ctrl" onclick="submitSave('reload')" style="background:#162536;border-color:#1f6feb;color:#79c0ff;font-weight:600">
                    🔄 Save &amp; Reload
                </button>
                <button type="button" class="btn-pri" onclick="submitSave('restart')" style="background:#238636;border-color:#2ea043;font-weight:600">
                    💾 Save &amp; Restart Nginx
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Standalone Stack Preset Templates Store -->
<script>
const fileContents = {
    ram: <?= json_encode($ramConf) ?>,
    wtc: <?= json_encode($wtcConf) ?>,
    ui:  <?= json_encode($uiConf) ?>
};

const stackPresets = {
    laravel: `# WTC Managed Configuration: Laravel Stack
# Project Webroot: /mnt/ramdisk/webroot (points to /mnt/ramdisk/www/public)
# FastCGI PHP handling is managed globally by Port 8080 and /site/
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ^~ /build/ {
    root /mnt/ramdisk/webroot;
    access_log off;
    expires max;
}

location ^~ /assets/ {
    root /mnt/ramdisk/webroot;
    access_log off;
    expires max;
}

location ^~ /storage/ {
    root /mnt/ramdisk/webroot;
    access_log off;
}

location ~* \\.(?:css|js|mjs|map|jpe?g|gif|png|webp|ico|svg|woff2?|ttf|eot|json|wasm|webmanifest|avif)$ {
    root /mnt/ramdisk/webroot;
    try_files $uri =404;
}`,
    spa: `# WTC Managed Configuration: Single Page Application (SPA / React / Vite / Vue)
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

location ~ \\.(?:css|js|map|jpe?g|gif|png|webp|ico|svg|woff2?|ttf|eot|json)$ {
    root /mnt/ramdisk/webroot;
    try_files $uri =404;
    access_log off;
}`,
    php: `# WTC Managed Configuration: Classic PHP Stack
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \\.(?:css|js|map|jpe?g|gif|png|webp|ico|svg|woff2?|ttf|eot|json)$ {
    root /mnt/ramdisk/webroot;
    try_files $uri =404;
    access_log off;
}`
};

let currentTarget = '<?= h($targetParam) ?>';

function switchTargetFile(target) {
    const textarea = document.getElementById('nginxConfigTextarea');
    fileContents[currentTarget] = textarea.value;

    currentTarget = target;
    document.getElementById('targetFileInput').value = target;

    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active-target'));
    const btns = document.querySelectorAll('.tab-btn');
    if (target === 'ram' && btns[0]) btns[0].classList.add('active-target');
    if (target === 'wtc' && btns[1]) btns[1].classList.add('active-target');
    if (target === 'ui' && btns[2]) btns[2].classList.add('active-target');

    const label = document.getElementById('filePathLabel');
    const presets = document.getElementById('stackPresetsGroup');
    if (target === 'ram') {
        label.innerHTML = 'Editing: <code>/mnt/ramdisk/nginx.conf</code> (Included by <code>/etc/nginx/wtc-site.conf</code>)';
        presets.style.display = 'flex';
    } else if (target === 'wtc') {
        label.innerHTML = 'Editing: <code>/etc/nginx/sites-available/wtc</code> (Port 80 Management UI + RAM Gateway)';
        presets.style.display = 'none';
    } else {
        label.innerHTML = 'Editing: <code>/etc/nginx/sites-available/wtc-ui</code> (Port 4485 Dedicated Dashboard)';
        presets.style.display = 'none';
    }

    textarea.value = fileContents[target] || '';
}

function applyPreset(name) {
    if (!stackPresets[name]) return;
    if (confirm(`Apply ${name.toUpperCase()} Nginx preset to editor? This will replace the editor content.`)) {
        document.getElementById('nginxConfigTextarea').value = stackPresets[name];
    }
}

function resetToStackDefault() {
    if (confirm('Reset active site configuration to detected stack default and restart Nginx?')) {
        const form = document.createElement('form');
        form.method = 'post';
        form.innerHTML = `<?= csrf_field() ?><input type="hidden" name="action" value="nginx_reset">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function submitSave(serviceAction) {
    document.getElementById('serviceActionInput').value = serviceAction;
    const form = document.getElementById('nginxConfigForm');
    form.submit();
}

async function testCurrentConfig() {
    const banner = document.getElementById('nginxTestBanner');
    const statusLabel = document.getElementById('nginxTestStatusLabel');
    const outputEl = document.getElementById('nginxTestOutput');

    statusLabel.style.color = '#79c0ff';
    statusLabel.textContent = '⏳ Testing configuration syntax (nginx -t)...';

    const formData = new FormData();
    formData.append('csrf', '<?= csrf() ?>');
    formData.append('action', 'nginx_test');
    formData.append('target', currentTarget);
    formData.append('config_content', document.getElementById('nginxConfigTextarea').value);

    try {
        const res = await fetch('/', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.ok) {
            banner.style.borderColor = '#238636';
            banner.style.background = '#0d1d15';
            statusLabel.style.color = '#7ee787';
            statusLabel.textContent = '✓ Configuration Test (nginx -t): PASSED';
            outputEl.style.color = '#7ee787';
            outputEl.textContent = data.output || 'Syntax is okay.';
        } else {
            banner.style.borderColor = '#da3633';
            banner.style.background = '#220e10';
            statusLabel.style.color = '#f85149';
            statusLabel.textContent = '✕ Configuration Test (nginx -t): SYNTAX ERROR';
            outputEl.style.color = '#f85149';
            outputEl.textContent = data.output || 'Syntax error detected.';
        }
    } catch (e) {
        statusLabel.style.color = '#f85149';
        statusLabel.textContent = '✕ Syntax Test Request Failed';
        outputEl.textContent = e.message;
    }
}

// Enable Tab key indentation inside textarea
document.getElementById('nginxConfigTextarea').addEventListener('keydown', function(e) {
    if (e.key === 'Tab') {
        e.preventDefault();
        const start = this.selectionStart;
        const end = this.selectionEnd;
        this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
        this.selectionStart = this.selectionEnd = start + 4;
    }
});
</script>

<style>
.active-target {
    background: #1f6feb !important;
    border-color: #388bfd !important;
    color: #fff !important;
    font-weight: 600;
}
</style>

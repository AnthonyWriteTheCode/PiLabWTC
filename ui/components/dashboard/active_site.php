<?php
declare(strict_types=1);
/**
 * Dashboard Component: Active Site & Quick Swapper
 * Live application switcher, quick launch URLs (port 80 / 8080), and in-place build progress banner.
 *
 * Variables passed in:
 * @var array $st Device status array
 * @var array $sites List of available site packages
 * @var array $buildJob Current background build job status
 * @var string $active Name of active site or 'None'
 */

$activeSitesList = $st['activeSites'] ?? [];
if (empty($activeSitesList) && $active !== 'None' && $active !== '') {
    $activeSitesList = [['name' => $active, 'slug' => strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '', $active))), 'url' => '/' . strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '', $active))) . '/']];
}
$activeCount = count($activeSitesList);
?>

<!-- In-Place Background Build Status Banner -->
<div id="dashBuildBanner" class="card" style="<?= !empty($buildJob['busy']) ? '' : 'display:none;' ?>border-color:#388bfd;background:#0d1b2a;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h4 style="color:#79c0ff;margin:0">🔨 Background Build in Progress (npm run build)...</h4>
        <span class="pill ok" id="dashBuildPill">Building in RAM</span>
    </div>
    <pre id="dashBuildLog" style="background:#050a10;color:#7ee787;padding:12px;border-radius:6px;max-height:160px;overflow-y:auto;font-size:0.78rem;font-family:monospace;white-space:pre-wrap"><?= h($buildJob['log'] ?? 'Executing build...') ?></pre>
    <small style="color:#8b949e">Building volatile assets directly in tmpfs. Live updating...</small>
</div>

<!-- Active Applications Card -->
<div class="card card-active" id="dashActiveSitesCard" style="margin-bottom:0">
    <div class="card-head">
        <div>
            <h3 style="margin:0;display:flex;align-items:center;gap:8px">
                🚀 Live Sites in RAM
                <span class="pill ok" id="dashActiveCountBadge" style="font-size:0.75rem"><?= $activeCount ?> Active</span>
            </h3>
            <p style="margin:4px 0 0 0;font-size:0.82rem;color:#8b949e">
                Running in volatile RAM tmpfs with zero SD-card write cycles.
            </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn-pri" href="<?= site_url('/') ?>" target="_blank" style="padding:4px 10px;font-size:0.8rem">🌐 Port 80</a>
            <a class="btn-pri" href="<?= site_port_url(8080, '/') ?>" target="_blank" style="background:#238636;border-color:#2ea043;padding:4px 10px;font-size:0.8rem">🚀 Port 8080</a>
        </div>
    </div>

    <!-- Live Sites Container -->
    <div id="dashActiveSitesList" style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px">
        <?php if ($activeCount > 0): ?>
            <?php foreach ($activeSitesList as $as): ?>
                <?php
                $asName = (string) ($as['name'] ?? '');
                $asSlug = (string) ($as['slug'] ?? '');
                $asUrl = (string) ($as['url'] ?? ('/' . $asSlug . '/'));
                $isPrim = ($asName === $active);
                $targetLiveUrl = site_url($isPrim ? '/' : $asUrl);
                ?>
                <div class="active-site-row" style="background:#0d1825;border:1px solid <?= $isPrim ? '#388bfd' : '#238636' ?>;border-radius:6px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
                    <div>
                        <div style="display:flex;align-items:center;gap:6px">
                            <span style="font-weight:600;color:#c9d1d9"><?= h($asName) ?></span>
                            <?php if ($isPrim): ?>
                                <span class="pill" style="background:#1f6feb;color:#fff;font-size:0.68rem;padding:1px 6px">Primary</span>
                            <?php else: ?>
                                <span class="pill ok" style="font-size:0.68rem;padding:1px 6px">Multi-Site</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:0.75rem;color:#79c0ff;margin-top:2px">
                            <code><?= h($targetLiveUrl) ?></code>
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center">
                        <a class="btn-pri" href="<?= h($targetLiveUrl) ?>" target="_blank" style="background:#238636;border:0;padding:4px 10px;font-size:0.75rem;border-radius:4px;text-decoration:none">Open</a>
                        <?= form('deactivate', ['site' => $asName, 'back' => 'dashboard'], '⏹️ Stop', 'danger', 'Unload ' . $asName . ' from RAM?') ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="padding:14px;background:#0d1117;border:1px dashed #30363d;border-radius:6px;text-align:center;color:#8b949e;font-size:0.85rem">
                No site active in RAM disk. Select a site below to launch.
            </div>
        <?php endif; ?>
    </div>

    <!-- 1-Click Site Switcher / Launcher -->
    <div class="site-quick-swapper" style="border-top:1px solid #1c2128;padding-top:10px">
        <span style="font-size:0.8rem;color:#8b949e;margin-right:6px"><b>1-Click Site Switcher:</b></span>
        <div style="display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px">
            <?php foreach ($sites as $s): ?>
                <?php
                $sName = $s['name'];
                $isActive = !empty($s['active']);
                $isPrim = !empty($s['isPrimary']) || ($sName === $active);
                ?>
                <?php if ($isActive): ?>
                    <span class="pill ok" style="<?= $isPrim ? 'border:1px solid #388bfd;' : '' ?>"><?= h($sName) ?> <?= $isPrim ? '(Primary)' : '(Live)' ?></span>
                <?php else: ?>
                    <?= form('activate', ['site' => $sName, 'back' => 'dashboard'], '⚡ ' . $sName, 'btn-swap') ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
(() => {
    let prevBuildBusy = <?= !empty($buildJob['busy']) ? 'true' : 'false' ?>;

    if (window.dashLive) {
        window.dashLive.subscribe('active_sites', (data) => {
            if (!data) return;
            const build = data.build || {};
            const banner = document.getElementById('dashBuildBanner');
            const logEl = document.getElementById('dashBuildLog');
            const pillEl = document.getElementById('dashBuildPill');

            // Handle background build in-place updating without full-page reloads
            if (banner && logEl) {
                if (build.busy) {
                    banner.style.display = 'block';
                    logEl.textContent = build.log || 'Building...';
                    logEl.scrollTop = logEl.scrollHeight;
                    prevBuildBusy = true;
                } else if (prevBuildBusy) {
                    // Transitioned from busy to done!
                    if (pillEl) {
                        pillEl.textContent = 'Build Complete';
                        pillEl.className = 'pill ok';
                    }
                    setTimeout(() => { banner.style.display = 'none'; }, 4000);
                    prevBuildBusy = false;
                }
            }
        });
    }
})();
</script>
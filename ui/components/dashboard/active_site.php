<?php
declare(strict_types=1);
/**
 * Dashboard Component: Active Site & Quick Swapper
 * Live application switcher, quick launch URLs (port 80 / 8080), and background build progress banner.
 *
 * Variables passed in:
 * @var array $st Device status array
 * @var array $sites List of available site packages
 * @var array $buildJob Current background build job status
 * @var string $active Name of active site or 'None'
 */
?>

<?php if (!empty($buildJob['busy'])): ?>
    <div class="card" style="border-color:#388bfd;background:#0d1b2a;margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <h4 style="color:#79c0ff;margin:0">🔨 Background Build in Progress (npm run build)...</h4>
            <span class="pill ok">Building in RAM</span>
        </div>
        <pre
            style="background:#050a10;color:#7ee787;padding:12px;border-radius:6px;max-height:160px;overflow-y:auto;font-size:0.78rem;font-family:monospace;white-space:pre-wrap"><?= h($buildJob['log'] ?? 'Executing build...') ?></pre>
        <small style="color:#8b949e">Auto-refreshing dashboard...</small>
        <script>setTimeout(() => { if (window.wtcSpa) { window.wtcSpa.navigateTo('/?p=dashboard', false); } else { window.location.reload(); } }, 3000);</script>
    </div>
<?php endif; ?>

<!-- Active Applications Card -->
<?php
$activeSitesList = $st['activeSites'] ?? [];
if (empty($activeSitesList) && $active !== 'None' && $active !== '') {
    $activeSitesList = [['name' => $active, 'slug' => strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '', $active))), 'url' => '/' . strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '', $active))) . '/']];
}
$activeCount = count($activeSitesList);
?>
<div class="card card-active">
    <div class="card-head">
        <div>
            <h3>🚀 Live Sites in RAM: <span class="hl"><?= $activeCount ?> Active</span></h3>
            <p>Each application runs in RAM tmpfs with zero SD-card wear. Access each site directly at its dedicated URL
                (<code>/&lt;site_slug&gt;/</code>).</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn-pri" href="<?= site_url('/') ?>" target="_blank">🌐 Live Site (Port 80)</a>
            <a class="btn-pri" href="<?= site_port_url(8080, '/') ?>"
                target="_blank" style="background:#238636;border-color:#2ea043">🚀 Port 8080 (Root)</a>
        </div>
    </div>

    <?php if ($activeCount > 0): ?>
        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:14px">
                <?php foreach ($activeSitesList as $as): ?>
                    <?php
                    $asName = (string) ($as['name'] ?? '');
                    $asSlug = (string) ($as['slug'] ?? '');
                    $asUrl = (string) ($as['url'] ?? ('/' . $asSlug . '/'));
                    $isPrim = ($asName === $active);
                    $targetLiveUrl = site_url($isPrim ? '/' : $asUrl);
                    ?>
                <div
                    style="background:#0d1825;border:1px solid <?= $isPrim ? '#388bfd' : '#238636' ?>;border-radius:8px;padding:10px 14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <div>
                        <div style="display:flex;align-items:center;gap:6px">
                            <span style="font-weight:600;color:#c9d1d9"><?= h($asName) ?></span>
                                <?php if ($isPrim): ?>
                                <span class="pill" style="background:#1f6feb;color:#fff;font-size:0.68rem">Primary</span>
                                <?php endif; ?>
                        </div>
                        <div style="font-size:0.75rem;color:#79c0ff;margin-top:2px">
                            <code><?= h($targetLiveUrl) ?></code>
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center">
                        <a class="btn-pri" href="<?= h($targetLiveUrl) ?>" target="_blank"
                            style="background:#238636;border:0;padding:4px 8px;font-size:0.75rem;border-radius:4px;text-decoration:none">Open</a>
                            <?= form('deactivate', ['site' => $asName, 'back' => 'dashboard'], '⏹️ Stop', 'danger', 'Unload ' . $asName . ' from RAM?') ?>
                    </div>
                </div>
                <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="site-quick-swapper" style="border-top:1px solid #1c2128;padding-top:10px">
        <b>1-Click Site Switcher / Launcher: </b>
        <?php foreach ($sites as $s): ?>
            <?php
            $sName = $s['name'];
            $isActive = !empty($s['active']);
            $isPrim = !empty($s['isPrimary']) || ($sName === $active);
            ?>
            <?php if ($isActive): ?>
                <span class="pill ok" style="<?= $isPrim ? 'border:1px solid #388bfd;' : '' ?>"><?= h($sName) ?>
                    <?= $isPrim ? '(Primary)' : '(Live)' ?></span>
            <?php else: ?>
                <?= form('activate', ['site' => $sName, 'back' => 'dashboard'], '⚡ Start ' . $sName, 'btn-swap') ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
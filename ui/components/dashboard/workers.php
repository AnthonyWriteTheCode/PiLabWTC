<?php
declare(strict_types=1);
/**
 * Dashboard Component: Background Workers
 * Queue workers and scheduled tasks status and execution controls.
 *
 * Variables passed in:
 * @var array $st Device status array
 */

$wk = $st['workers'] ?? [];
$hasArtisan = !empty($wk['has_artisan']);
$qRun = !empty($wk['queue']['running']);
$sRun = !empty($wk['schedule']['running']);
$qPid = $wk['queue']['pid'] ?? '';
$sPid = $wk['schedule']['pid'] ?? '';
?>
<!-- Background Workers Card -->
<div class="card" style="border-color:#388bfd">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px">⚙️ Background Workers <span style="font-size:0.8rem;color:#8b949e">(Laravel Queue &amp; Scheduler)</span></h3>
        <div style="display:flex;gap:8px">
            <?= form('workers', ['do' => 'restart', 'back' => 'dashboard'], '🔄 Restart Workers', 'btn-pri') ?>
            <?php if ($qRun || $sRun): ?>
                <?= form('workers', ['do' => 'stop', 'back' => 'dashboard'], '🛑 Stop', 'btn-ctrl danger') ?>
            <?php else: ?>
                <?= form('workers', ['do' => 'start', 'back' => 'dashboard'], '▶️ Start', 'btn-ctrl') ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="grid2" style="margin-top:0">
        <div style="background:#11171e;padding:12px;border-radius:6px;border:1px solid #2a3541">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <span><b>Queue Worker</b> (<code>queue:work</code>)</span>
                <?= $qRun ? '<span class="pill ok">running ' . ($qPid ? '(PID ' . h($qPid) . ')' : '') . '</span>' : '<span class="pill off">stopped</span>' ?>
            </div>
            <small style="color:#8b949e;display:block;margin-top:4px">Processes queued background jobs (emails, sync, database).</small>
        </div>
        <div style="background:#11171e;padding:12px;border-radius:6px;border:1px solid #2a3541">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <span><b>Task Scheduler</b> (<code>schedule:work</code>)</span>
                <?= $sRun ? '<span class="pill ok">running ' . ($sPid ? '(PID ' . h($sPid) . ')' : '') . '</span>' : '<span class="pill off">stopped</span>' ?>
            </div>
            <small style="color:#8b949e;display:block;margin-top:4px">Executes scheduled tasks (cron daemon equivalent) minutely.</small>
        </div>
    </div>
    <?php if (!$hasArtisan): ?>
        <p style="color:#8b949e;font-size:0.82rem;margin-top:8px"><i>Notice: Current active site does not contain Laravel <code>artisan</code>. Workers will start automatically when a Laravel application is activated.</i></p>
    <?php endif; ?>
</div>

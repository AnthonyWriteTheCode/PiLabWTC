<?php
declare(strict_types=1);
/**
 * Dashboard Component: Unified System Health Matrix & Background Workers
 * Combines core Linux daemons (Nginx, PHP-FPM, MariaDB, Samba, SSH) and background workers.
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
<div class="card" id="dashHealthMatrixCard" style="margin-top:14px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
        <div>
            <h3 style="margin:0;display:flex;align-items:center;gap:8px">
                ⚙️ Platform Daemons &amp; Health Matrix
                <span class="pill ok" id="healthMatrixStatusBadge" style="font-size:0.75rem">All Active</span>
            </h3>
            <p style="margin:4px 0 0 0;font-size:0.82rem;color:#8b949e">
                Real-time daemon operational status and background queue execution.
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="/?p=services" data-page="services" data-title="WTC Pi - ⚙️ Service Manager" class="btn" style="font-size:0.8rem;padding:4px 10px;text-decoration:none">
                🛡️ Services &amp; Patch Manager &rarr;
            </a>
        </div>
    </div>

    <!-- Core Daemons Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:10px;margin-bottom:12px">
        <!-- Nginx -->
        <div class="health-chip" id="chipNginx" style="background:#11171f;border:1px solid #21262d;border-radius:6px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:0.82rem;color:#c9d1d9;font-weight:600">🌐 Nginx Web</span>
            <span class="pill <?= ($st['nginx'] ?? '') === 'yes' ? 'ok' : 'off' ?>" id="chipNginxStatus"><?= ($st['nginx'] ?? '') === 'yes' ? 'active' : 'stopped' ?></span>
        </div>

        <!-- PHP-FPM -->
        <div class="health-chip" id="chipPhp" style="background:#11171f;border:1px solid #21262d;border-radius:6px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:0.82rem;color:#c9d1d9;font-weight:600">🐘 PHP-FPM</span>
            <span class="pill <?= ($st['phpfpm'] ?? '') === 'yes' ? 'ok' : 'off' ?>" id="chipPhpStatus"><?= ($st['phpfpm'] ?? '') === 'yes' ? 'active' : 'stopped' ?></span>
        </div>

        <!-- MariaDB -->
        <div class="health-chip" id="chipDb" style="background:#11171f;border:1px solid #21262d;border-radius:6px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:0.82rem;color:#c9d1d9;font-weight:600">🗄️ MariaDB</span>
            <span class="pill <?= ($st['mariadb'] ?? '') === 'yes' ? 'ok' : 'off' ?>" id="chipDbStatus"><?= ($st['mariadb'] ?? '') === 'yes' ? 'active' : 'stopped' ?></span>
        </div>

        <!-- Samba -->
        <div class="health-chip" id="chipSmb" style="background:#11171f;border:1px solid #21262d;border-radius:6px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:0.82rem;color:#c9d1d9;font-weight:600">📁 Samba SMB</span>
            <span class="pill <?= ($st['samba'] ?? '') === 'yes' ? 'ok' : 'off' ?>" id="chipSmbStatus"><?= ($st['samba'] ?? '') === 'yes' ? 'active' : 'stopped' ?></span>
        </div>

        <!-- SSH -->
        <div class="health-chip" id="chipSsh" style="background:#11171f;border:1px solid #21262d;border-radius:6px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:0.82rem;color:#c9d1d9;font-weight:600">🔒 SSH / SFTP</span>
            <span class="pill <?= ($st['ssh'] ?? '') === 'yes' ? 'ok' : 'off' ?>" id="chipSshStatus"><?= ($st['ssh'] ?? '') === 'yes' ? 'active' : 'stopped' ?></span>
        </div>
    </div>

    <!-- Background Workers Strip -->
    <div style="background:#0e131a;border:1px solid #21262d;border-radius:6px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span style="font-size:0.82rem;color:#8b949e;font-weight:600">👷 Background Workers:</span>
            <?php if ($hasArtisan): ?>
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="font-size:0.8rem;color:#c9d1d9">Queue:</span>
                    <span class="pill <?= $qRun ? 'ok' : 'off' ?>" id="chipWorkerQueue"><?= $qRun ? 'running' . ($qPid ? ' (PID ' . h($qPid) . ')' : '') : 'stopped' ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="font-size:0.8rem;color:#c9d1d9">Scheduler:</span>
                    <span class="pill <?= $sRun ? 'ok' : 'off' ?>" id="chipWorkerSched"><?= $sRun ? 'running' . ($sPid ? ' (PID ' . h($sPid) . ')' : '') : 'stopped' ?></span>
                </div>
            <?php else: ?>
                <span class="badge" id="chipWorkerInactive" style="background:#1a202c;color:#8b949e">
                    Inactive (Active site is not a Laravel app)
                </span>
            <?php endif; ?>
        </div>

        <?php if ($hasArtisan): ?>
            <div style="display:flex;gap:6px" id="dashWorkerControls">
                <?= form('workers', ['do' => 'restart', 'back' => 'dashboard'], '🔄 Restart', 'btn', 'Restart Laravel queue & scheduler workers?') ?>
                <?php if ($qRun || $sRun): ?>
                    <?= form('workers', ['do' => 'stop', 'back' => 'dashboard'], '🛑 Stop', 'btn-ctrl danger') ?>
                <?php else: ?>
                    <?= form('workers', ['do' => 'start', 'back' => 'dashboard'], '▶️ Start', 'btn-ctrl') ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
    if (window.dashLive) {
        window.dashLive.subscribe('services', (data) => {
            const st = data.status || {};
            const wk = st.workers || {};

            function updateChip(id, statusVal) {
                const el = document.getElementById(id);
                if (!el) return;
                const isOk = (statusVal === 'yes' || statusVal === true);
                el.className = 'pill ' + (isOk ? 'ok' : 'off');
                el.textContent = isOk ? 'active' : 'stopped';
            }

            updateChip('chipNginxStatus', st.nginx);
            updateChip('chipPhpStatus', st.phpfpm);
            updateChip('chipDbStatus', st.mariadb);
            updateChip('chipSmbStatus', st.samba);
            updateChip('chipSshStatus', st.ssh);

            // Matrix overall badge
            const matrixBadge = document.getElementById('healthMatrixStatusBadge');
            if (matrixBadge) {
                const allUp = (st.nginx === 'yes' && st.phpfpm === 'yes' && st.mariadb === 'yes');
                matrixBadge.className = 'pill ' + (allUp ? 'ok' : 'danger');
                matrixBadge.textContent = allUp ? 'All Active' : 'Degraded';
            }

            // Workers
            const qEl = document.getElementById('chipWorkerQueue');
            const sEl = document.getElementById('chipWorkerSched');
            if (qEl && wk.queue) {
                const isRun = Boolean(wk.queue.running);
                qEl.className = 'pill ' + (isRun ? 'ok' : 'off');
                qEl.textContent = isRun ? 'running' + (wk.queue.pid ? ` (PID ${wk.queue.pid})` : '') : 'stopped';
            }
            if (sEl && wk.schedule) {
                const isRun = Boolean(wk.schedule.running);
                sEl.className = 'pill ' + (isRun ? 'ok' : 'off');
                sEl.textContent = isRun ? 'running' + (wk.schedule.pid ? ` (PID ${wk.schedule.pid})` : '') : 'stopped';
            }
        });
    }
})();
</script>

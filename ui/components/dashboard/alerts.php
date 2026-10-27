<?php
declare(strict_types=1);
/**
 * Dashboard Component: Active System Alerts Strip
 * Conditional banner strip for critical hardware, daemon, and RAM alerts.
 * Renders server-side on initial load and stays synced via dashLive.
 *
 * Variables passed in:
 * @var array $st Device status array
 */
$rebootReq = is_file('/var/run/reboot-required');
$rejectedCount = (int) ($st['rejected'] ?? 0);
$ramUsed = (float) ($st['ramUsed'] ?? 0);
$ramSize = (float) ($st['ramSize'] ?? 1);
$ramPct = $ramSize > 0 ? round(100 * $ramUsed / $ramSize) : 0;
$diskUsed = (float) ($st['diskUsed'] ?? 0);
$diskSize = (float) ($st['diskSize'] ?? 1);
$diskPct = $diskSize > 0 ? round(100 * $diskUsed / $diskSize) : 0;

$hasInitialAlert = $rebootReq || $rejectedCount > 0 || $ramPct >= 85 || $diskPct >= 90;
?>
<div id="dashAlertsContainer" style="<?= $hasInitialAlert ? '' : 'display:none;' ?>margin-bottom:14px">
    <div id="dashAlertsList" style="display:flex;flex-direction:column;gap:8px">
        <?php if ($rebootReq): ?>
            <div class="dash-alert-item warn" id="alertRebootRequired">
                <div style="display:flex;align-items:center;gap:10px">
                    <span style="font-size:1.1rem">⚠️</span>
                    <div>
                        <b>Reboot Required:</b> Linux OS kernel or security package updates are pending.
                    </div>
                </div>
                <?= form('device', ['do' => 'reboot'], '🔁 Reboot Pi Now', 'btn-ctrl danger', 'Reboot the Raspberry Pi now to apply kernel updates?') ?>
            </div>
        <?php endif; ?>

        <?php if ($rejectedCount > 0): ?>
            <div class="dash-alert-item danger" id="alertRejectedArchives">
                <div style="display:flex;align-items:center;gap:10px">
                    <span style="font-size:1.1rem">🚫</span>
                    <div>
                        <b>Rejected Uploads:</b> <?= $rejectedCount ?> archive(s) in dropbox failed validation.
                    </div>
                </div>
                <a href="/?p=transfer" data-page="transfer" class="btn" style="padding:3px 10px;font-size:0.75rem;text-decoration:none">View Rejected Logs &rarr;</a>
            </div>
        <?php endif; ?>

        <?php if ($ramPct >= 85): ?>
            <div class="dash-alert-item <?= $ramPct >= 95 ? 'danger' : 'warn' ?>" id="alertRamHigh">
                <div style="display:flex;align-items:center;gap:10px">
                    <span style="font-size:1.1rem">⚡</span>
                    <div>
                        <b>RAM Disk High Utilization:</b> tmpfs is at <?= $ramPct ?>% capacity. Consider saving state and removing unused assets.
                    </div>
                </div>
                <a href="/?p=explorer" data-page="explorer" class="btn" style="padding:3px 10px;font-size:0.75rem;text-decoration:none">Inspect RAM Drive &rarr;</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
    if (window.dashLive) {
        window.dashLive.subscribe('alerts', (data) => {
            const container = document.getElementById('dashAlertsContainer');
            const list = document.getElementById('dashAlertsList');
            if (!container || !list) return;

            const st = data.status || {};
            const thr = data.throttling || {};
            const saved = data.saved_state || {};
            const alerts = [];

            // 1. Throttling / Undervoltage check (critical Raspberry Pi hardware warning)
            if (thr.under_voltage) {
                alerts.push({
                    type: 'danger',
                    icon: '⚡',
                    msg: '<b>Under-Voltage Detected!</b> Power supply voltage dropped below 4.63V. Use an official Raspberry Pi 5V/3A power supply to avoid SD/RAM corruption.'
                });
            } else if (thr.throttled) {
                alerts.push({
                    type: 'warn',
                    icon: '🔥',
                    msg: '<b>CPU Throttling Active!</b> SoC temperature limit reached; CPU clock speed is being reduced.'
                });
            }

            // 2. Critical Service down
            const downSvcs = [];
            if (st.nginx === 'no') downSvcs.push('Nginx');
            if (st.phpfpm === 'no') downSvcs.push('PHP-FPM');
            if (st.mariadb === 'no') downSvcs.push('MariaDB');
            if (downSvcs.length > 0) {
                alerts.push({
                    type: 'danger',
                    icon: '🛑',
                    msg: `<b>Critical Service Down:</b> ${DashUtil.escapeHtml(downSvcs.join(', '))} is stopped!`,
                    actionHtml: `<a href="/?p=services" data-page="services" class="btn" style="padding:3px 10px;font-size:0.75rem;text-decoration:none">Manage Services &rarr;</a>`
                });
            }

            // 3. RAM Disk capacity
            const ramUsed = Number(st.ramUsed) || 0;
            const ramSize = Number(st.ramSize) || 1;
            const ramPct = Math.round(100 * ramUsed / ramSize);
            if (ramPct >= 85) {
                alerts.push({
                    type: ramPct >= 95 ? 'danger' : 'warn',
                    icon: '⚡',
                    msg: `<b>RAM Disk Utilization:</b> ${ramPct}% used (${DashUtil.fmtBytes(ramUsed)} of ${DashUtil.fmtBytes(ramSize)}).`,
                    actionHtml: `<a href="/?p=explorer" data-page="explorer" class="btn" style="padding:3px 10px;font-size:0.75rem;text-decoration:none">Explore RAM &rarr;</a>`
                });
            }

            // 4. Rejected archives
            const rej = Number(st.rejected) || 0;
            if (rej > 0) {
                alerts.push({
                    type: 'danger',
                    icon: '🚫',
                    msg: `<b>Rejected Uploads:</b> ${rej} package(s) failed integrity check.`,
                    actionHtml: `<a href="/?p=transfer" data-page="transfer" class="btn" style="padding:3px 10px;font-size:0.75rem;text-decoration:none">View Logs &rarr;</a>`
                });
            }

            // Render alerts if any
            if (alerts.length === 0) {
                // Keep server-rendered reboot alert if present
                const rebootAlert = document.getElementById('alertRebootRequired');
                if (rebootAlert) {
                    container.style.display = 'block';
                } else {
                    container.style.display = 'none';
                }
            } else {
                container.style.display = 'block';
                const rebootHtml = document.getElementById('alertRebootRequired')?.outerHTML || '';
                list.innerHTML = rebootHtml + alerts.map(a => `
                    <div class="dash-alert-item ${a.type}">
                        <div style="display:flex;align-items:center;gap:10px">
                            <span style="font-size:1.1rem">${a.icon}</span>
                            <div>${a.msg}</div>
                        </div>
                        ${a.actionHtml ? `<div>${a.actionHtml}</div>` : ''}
                    </div>
                `).join('');
            }
        });
    }
})();
</script>

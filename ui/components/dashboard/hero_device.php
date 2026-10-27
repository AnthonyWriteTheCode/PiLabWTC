<?php
declare(strict_types=1);
/**
 * Dashboard Component: Hero Device Telemetry Ribbon
 * Live device telemetry (Host, Model, Temp, Uptime, IP) and quick action controls.
 *
 * Variables passed in:
 * @var array $st Device status array from wj('status')
 */
$hostName = $st['host'] ?? 'pi';
$ipList = $st['ips'] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
?>
<div class="hero-device" id="dashDeviceRibbon">
    <div class="device-info">
        <h2>
            <span id="telemetry-model"><?= h($st['model'] ?? 'Raspberry Pi') ?></span>
            <span class="badge" id="telemetry-host-badge"><?= h($hostName) ?></span>
            <span id="telemetry-demo-pill" class="pill warn" style="display:none;font-size:0.7rem;padding:2px 8px">Demo Simulation</span>
        </h2>
        <div class="telemetry">
            <span>🏷️ Host: <a id="telemetry-host-link" href="http://<?= htmlspecialchars($hostName) ?>.local/" target="_blank" style="color:#58a6ff;text-decoration:none"><b><?= h($hostName) ?>.local</b></a></span>
            <span>🌡️ Temp: <b id="telemetry-temp"><?= h($st['temp'] ?? 'N/A') ?></b></span>
            <span>⏱️ Uptime: <b id="telemetry-uptime"><?= h($st['uptime'] ?? 'N/A') ?></b></span>
            <span>🌐 IP: <b id="telemetry-ip"><?= h($ipList) ?></b></span>
            <span>PHP: <b><?= PHP_VERSION ?></b></span>
        </div>
    </div>
    <div class="quick-device-controls">
        <div style="display:flex;align-items:center;gap:6px;margin-right:6px">
            <span id="dashLivePulse" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#3fb950;box-shadow:0 0 6px #3fb950" title="Unified 3s live polling active"></span>
            <button type="button" class="btn-ctrl" style="padding:4px 8px;font-size:0.75rem" onclick="if(window.dashLive)window.dashLive.poll(true)">🔄 Poll</button>
        </div>

        <?= form('device', ['do' => 'restart-web'], '🔄 Restart Web', 'btn-ctrl', 'Restart Nginx and PHP-FPM daemons?') ?>
        <?= form('device', ['do' => 'restart-db'], '🗄️ Restart DB', 'btn-ctrl', 'Restart MariaDB database daemon?') ?>

        <!-- Save RAM State with Dirty Indicator -->
        <form method="post" style="display:inline;position:relative">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_state">
            <input type="hidden" name="back" value="dashboard">
            <button type="submit" class="btn-ctrl" id="dashSaveStateBtn" title="Snapshot RAM changes directly back into persistent SD archive">
                💾 Save RAM State <span id="dashDirtyDot" style="display:none;color:#e3b341;font-weight:bold">•</span>
            </button>
        </form>

        <!-- Dangerous Power Actions Dropdown -->
        <details class="device-actions-dropdown" style="position:relative;display:inline-block">
            <summary class="btn-ctrl" style="list-style:none;cursor:pointer;user-select:none;display:inline-flex;align-items:center;gap:4px">
                ⚙️ Power ▾
            </summary>
            <div class="device-actions-menu" style="position:absolute;right:0;top:calc(100% + 4px);background:#161b22;border:1px solid #30363d;border-radius:6px;padding:6px;min-width:160px;box-shadow:0 8px 24px rgba(0,0,0,0.5);z-index:20">
                <div style="font-size:0.72rem;color:#8b949e;padding:4px 8px;text-transform:uppercase;font-weight:600">Hardware Actions</div>
                <?= form('device', ['do' => 'reboot'], '🔁 Reboot Pi', 'btn-ctrl danger', 'Are you sure you want to REBOOT the Raspberry Pi?') ?>
                <div style="margin-top:4px"></div>
                <?= form('device', ['do' => 'shutdown'], '🛑 Shutdown Pi', 'btn-ctrl danger', 'Are you sure you want to SHUT DOWN the Raspberry Pi?') ?>
            </div>
        </details>
    </div>
</div>

<script>
(() => {
    if (window.dashLive) {
        window.dashLive.subscribe('telemetry', (data) => {
            if (!data) return;
            const st = data.status || {};
            const tempEl = document.getElementById('telemetry-temp');
            const uptimeEl = document.getElementById('telemetry-uptime');
            const ipEl = document.getElementById('telemetry-ip');
            const hostBadge = document.getElementById('telemetry-host-badge');
            const hostLink = document.getElementById('telemetry-host-link');
            const demoPill = document.getElementById('telemetry-demo-pill');
            const dirtyDot = document.getElementById('dashDirtyDot');
            const saveBtn = document.getElementById('dashSaveStateBtn');

            if (tempEl && st.temp) tempEl.textContent = st.temp;
            if (uptimeEl && st.uptime) uptimeEl.textContent = st.uptime;
            if (ipEl && st.ips) ipEl.textContent = st.ips;
            if (hostBadge && st.host) hostBadge.textContent = st.host;
            if (hostLink && st.host) {
                hostLink.href = 'http://' + encodeURIComponent(st.host) + '.local/';
                hostLink.querySelector('b').textContent = st.host + '.local';
            }
            if (demoPill) {
                demoPill.style.display = data.demo ? 'inline-block' : 'none';
            }

            // Save state dirty indicator
            const isDirty = data.saved_state?.is_dirty;
            if (dirtyDot) dirtyDot.style.display = isDirty ? 'inline' : 'none';
            if (saveBtn && isDirty) {
                saveBtn.style.borderColor = '#d29922';
                saveBtn.title = 'RAM state has unsaved changes!';
            }
        });
    }
})();
</script>

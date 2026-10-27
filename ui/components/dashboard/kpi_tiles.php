<?php
declare(strict_types=1);
/**
 * Dashboard Component: KPI Metric Tiles with Real-Time Sparklines
 * Replaces static memory_storage.php with 6 dynamic, live-updating hardware tiles.
 *
 * Variables passed in:
 * @var array $st Device status array
 */

$ram_pct = ($st['ramSize'] ?? 0) > 0 ? round(100 * ($st['ramUsed'] ?? 0) / $st['ramSize']) : 0;
$sys_pct = ($st['sysMemTotal'] ?? 0) > 0 ? round(100 * ($st['sysMemUsed'] ?? 0) / $st['sysMemTotal']) : 0;
$disk_pct = ($st['diskSize'] ?? 0) > 0 ? round(100 * ($st['diskUsed'] ?? 0) / $st['diskSize']) : 0;
?>
<div class="dash-kpis">
    <!-- Tile 1: CPU Load -->
    <div class="kpi-card" id="kpiCpuCard">
        <div class="kpi-head">
            <span class="kpi-title">⚡ CPU Load</span>
            <span class="kpi-badge ok" id="kpiCpuBadge">--%</span>
        </div>
        <div class="kpi-body">
            <div class="kpi-value-row">
                <span class="kpi-val" id="kpiCpuVal">0.0%</span>
                <span class="kpi-sub" id="kpiCpuFreq">-- GHz</span>
            </div>
            <div class="kpi-spark-wrap">
                <canvas id="kpiCpuSpark" width="140" height="32" class="kpi-spark"></canvas>
            </div>
        </div>
    </div>

    <!-- Tile 2: SoC Temperature -->
    <div class="kpi-card" id="kpiTempCard">
        <div class="kpi-head">
            <span class="kpi-title">🌡️ SoC Temperature</span>
            <span class="kpi-badge ok" id="kpiTempBadge">Normal</span>
        </div>
        <div class="kpi-body">
            <div class="kpi-value-row">
                <span class="kpi-val" id="kpiTempVal"><?= h($st['temp'] ?? 'N/A') ?></span>
                <span class="kpi-sub" id="kpiTempStatus">&lt; 70°C target</span>
            </div>
            <div class="kpi-spark-wrap">
                <canvas id="kpiTempSpark" width="140" height="32" class="kpi-spark"></canvas>
            </div>
        </div>
    </div>

    <!-- Tile 3: RAM Disk tmpfs -->
    <div class="kpi-card" id="kpiRamCard">
        <div class="kpi-head">
            <span class="kpi-title">⚡ RAM Disk (tmpfs)</span>
            <span class="kpi-badge ok" id="kpiRamBadge"><?= $ram_pct ?>%</span>
        </div>
        <div class="kpi-body">
            <div class="kpi-value-row">
                <span class="kpi-val" id="kpiRamVal"><?= fmt_bytes($st['ramUsed'] ?? 0) ?></span>
                <span class="kpi-sub" id="kpiRamTotal">/ <?= fmt_bytes($st['ramSize'] ?? 0) ?></span>
            </div>
            <div class="bar" style="margin-top:8px"><i id="kpiRamBar" style="width:<?= $ram_pct ?>%"></i></div>
            <div class="kpi-foot">High-speed volatile RAM. Zero SD wear.</div>
        </div>
    </div>

    <!-- Tile 4: System Memory (Physical SoC) -->
    <div class="kpi-card" id="kpiSysCard">
        <div class="kpi-head">
            <span class="kpi-title">🧠 System Memory</span>
            <span class="kpi-badge ok" id="kpiSysBadge"><?= $sys_pct ?>%</span>
        </div>
        <div class="kpi-body">
            <div class="kpi-value-row">
                <span class="kpi-val" id="kpiSysVal"><?= fmt_bytes($st['sysMemUsed'] ?? 0) ?></span>
                <span class="kpi-sub" id="kpiSysTotal">/ <?= fmt_bytes($st['sysMemTotal'] ?? 0) ?></span>
            </div>
            <div class="bar" style="margin-top:8px"><i id="kpiSysBar" style="width:<?= $sys_pct ?>%;background:#8957e5"></i></div>
            <div class="kpi-foot">Physical LPDDR4 memory allocation.</div>
        </div>
    </div>

    <!-- Tile 5: SD Card Persistent Storage -->
    <div class="kpi-card" id="kpiDiskCard">
        <div class="kpi-head">
            <span class="kpi-title">💾 Persistent Storage (/)</span>
            <span class="kpi-badge ok" id="kpiDiskBadge"><?= $disk_pct ?>%</span>
        </div>
        <div class="kpi-body">
            <div class="kpi-value-row">
                <span class="kpi-val" id="kpiDiskVal"><?= fmt_bytes($st['diskUsed'] ?? 0) ?></span>
                <span class="kpi-sub" id="kpiDiskTotal">/ <?= fmt_bytes($st['diskSize'] ?? 0) ?></span>
            </div>
            <div class="bar" style="margin-top:8px"><i id="kpiDiskBar" style="width:<?= $disk_pct ?>%;background:#d29922"></i></div>
            <div class="kpi-foot">MicroSD root filesystem archives.</div>
        </div>
    </div>

    <!-- Tile 6: Load Average & Hardware Health -->
    <div class="kpi-card" id="kpiHealthCard">
        <div class="kpi-head">
            <span class="kpi-title">🛡️ Load &amp; Health</span>
            <span class="kpi-badge ok" id="kpiHealthBadge">Optimal</span>
        </div>
        <div class="kpi-body">
            <div class="kpi-value-row">
                <span class="kpi-val" id="kpiLoadVal" style="font-size:0.95rem;font-family:monospace">0.10, 0.15, 0.12</span>
            </div>
            <div style="font-size:0.75rem;color:#8b949e;margin-top:6px;display:flex;justify-content:space-between">
                <span>1m, 5m, 15m load</span>
                <span id="kpiThrottleStatus" style="color:#3fb950;font-weight:600">No Throttling</span>
            </div>
            <div class="kpi-foot" id="kpiNetRateFoot">Net I/O: -- KB/s rx • -- KB/s tx</div>
        </div>
    </div>
</div>

<script>
(() => {
    function updateKpiSparklines() {
        if (!window.dashLive || !window.DashUtil) return;
        const hist = window.dashLive.getHistory();
        const cpuCanvas = document.getElementById('kpiCpuSpark');
        const tempCanvas = document.getElementById('kpiTempSpark');

        if (cpuCanvas && hist.cpu) {
            DashUtil.renderSparkline(cpuCanvas, hist.cpu, '#1f6feb', 'rgba(31,111,235,0.15)');
        }
        if (tempCanvas && hist.temp) {
            DashUtil.renderSparkline(tempCanvas, hist.temp, '#bc8cff', 'rgba(188,140,255,0.15)');
        }
    }

    if (window.dashLive) {
        window.dashLive.subscribe('kpis', (data) => {
            if (!data) return;
            const st = data.status || {};
            const cpu = data.cpu || {};
            const thr = data.throttling || {};
            const load = data.loadavg || ['0.00', '0.00', '0.00'];
            const netRates = data.net_rates || {};

            // 1. CPU Load Tile
            const cpuVal = Number(cpu.total ?? 0);
            const cpuValEl = document.getElementById('kpiCpuVal');
            const cpuBadge = document.getElementById('kpiCpuBadge');
            const cpuFreqEl = document.getElementById('kpiCpuFreq');
            if (cpuValEl) cpuValEl.textContent = cpuVal.toFixed(1) + '%';
            if (cpuBadge) {
                cpuBadge.textContent = cpuVal.toFixed(0) + '%';
                cpuBadge.className = 'kpi-badge ' + (cpuVal > 80 ? 'danger' : (cpuVal > 50 ? 'warn' : 'ok'));
            }
            if (cpuFreqEl && cpu.freq_mhz > 0) {
                const ghz = (cpu.freq_mhz >= 1000) ? (cpu.freq_mhz / 1000).toFixed(2) + ' GHz' : cpu.freq_mhz + ' MHz';
                cpuFreqEl.textContent = ghz;
            }

            // 2. Temp Tile
            const tempStr = st.temp || 'N/A';
            const tempVal = parseFloat(tempStr) || 0;
            const tempValEl = document.getElementById('kpiTempVal');
            const tempBadge = document.getElementById('kpiTempBadge');
            if (tempValEl) tempValEl.textContent = tempStr;
            if (tempBadge) {
                if (tempVal >= 80) {
                    tempBadge.textContent = 'Critical';
                    tempBadge.className = 'kpi-badge danger';
                } else if (tempVal >= 70) {
                    tempBadge.textContent = 'Warm';
                    tempBadge.className = 'kpi-badge warn';
                } else {
                    tempBadge.textContent = 'Normal';
                    tempBadge.className = 'kpi-badge ok';
                }
            }

            // 3. RAM Disk Tile
            const ramUsed = Number(st.ramUsed) || 0;
            const ramSize = Number(st.ramSize) || 1;
            const ramPct = Math.round(100 * ramUsed / ramSize);
            const ramValEl = document.getElementById('kpiRamVal');
            const ramTotalEl = document.getElementById('kpiRamTotal');
            const ramBadge = document.getElementById('kpiRamBadge');
            const ramBar = document.getElementById('kpiRamBar');
            if (ramValEl) ramValEl.textContent = DashUtil.fmtBytes(ramUsed);
            if (ramTotalEl) ramTotalEl.textContent = '/ ' + DashUtil.fmtBytes(ramSize);
            if (ramBadge) {
                ramBadge.textContent = ramPct + '%';
                ramBadge.className = 'kpi-badge ' + (ramPct > 90 ? 'danger' : (ramPct > 75 ? 'warn' : 'ok'));
            }
            if (ramBar) {
                ramBar.style.width = Math.min(100, ramPct) + '%';
                ramBar.style.background = ramPct > 90 ? '#f85149' : (ramPct > 75 ? '#d29922' : '#1f6feb');
            }

            // 4. System Memory Tile
            const sysUsed = Number(st.sysMemUsed) || 0;
            const sysTotal = Number(st.sysMemTotal) || 1;
            const sysPct = Math.round(100 * sysUsed / sysTotal);
            const sysValEl = document.getElementById('kpiSysVal');
            const sysTotalEl = document.getElementById('kpiSysTotal');
            const sysBadge = document.getElementById('kpiSysBadge');
            const sysBar = document.getElementById('kpiSysBar');
            if (sysValEl) sysValEl.textContent = DashUtil.fmtBytes(sysUsed);
            if (sysTotalEl) sysTotalEl.textContent = '/ ' + DashUtil.fmtBytes(sysTotal);
            if (sysBadge) {
                sysBadge.textContent = sysPct + '%';
                sysBadge.className = 'kpi-badge ' + (sysPct > 90 ? 'danger' : (sysPct > 75 ? 'warn' : 'ok'));
            }
            if (sysBar) sysBar.style.width = Math.min(100, sysPct) + '%';

            // 5. Persistent SD Storage Tile
            const diskUsed = Number(st.diskUsed) || 0;
            const diskSize = Number(st.diskSize) || 1;
            const diskPct = Math.round(100 * diskUsed / diskSize);
            const diskValEl = document.getElementById('kpiDiskVal');
            const diskTotalEl = document.getElementById('kpiDiskTotal');
            const diskBadge = document.getElementById('kpiDiskBadge');
            const diskBar = document.getElementById('kpiDiskBar');
            if (diskValEl) diskValEl.textContent = DashUtil.fmtBytes(diskUsed);
            if (diskTotalEl) diskTotalEl.textContent = '/ ' + DashUtil.fmtBytes(diskSize);
            if (diskBadge) {
                diskBadge.textContent = diskPct + '%';
                diskBadge.className = 'kpi-badge ' + (diskPct > 90 ? 'danger' : (diskPct > 80 ? 'warn' : 'ok'));
            }
            if (diskBar) diskBar.style.width = Math.min(100, diskPct) + '%';

            // 6. Load & Health Tile
            const loadValEl = document.getElementById('kpiLoadVal');
            const throttleEl = document.getElementById('kpiThrottleStatus');
            const healthBadge = document.getElementById('kpiHealthBadge');
            const netFoot = document.getElementById('kpiNetRateFoot');
            if (loadValEl && Array.isArray(load)) {
                loadValEl.textContent = load.slice(0, 3).join(', ');
            }
            if (throttleEl && healthBadge) {
                if (thr.under_voltage) {
                    throttleEl.textContent = '⚡ Low Voltage!';
                    throttleEl.style.color = '#f85149';
                    healthBadge.textContent = 'Alert';
                    healthBadge.className = 'kpi-badge danger';
                } else if (thr.throttled) {
                    throttleEl.textContent = '🔥 Throttled';
                    throttleEl.style.color = '#d29922';
                    healthBadge.textContent = 'Throttled';
                    healthBadge.className = 'kpi-badge warn';
                } else {
                    throttleEl.textContent = 'No Throttling';
                    throttleEl.style.color = '#3fb950';
                    healthBadge.textContent = 'Optimal';
                    healthBadge.className = 'kpi-badge ok';
                }
            }
            if (netFoot && typeof netRates.rx_bytes_sec !== 'undefined') {
                const rxK = (netRates.rx_bytes_sec / 1024).toFixed(1);
                const txK = (netRates.tx_bytes_sec / 1024).toFixed(1);
                netFoot.textContent = `Net I/O: ${rxK} KB/s rx • ${txK} KB/s tx`;
            }

            // Draw sparklines
            updateKpiSparklines();
        });

        // Initial draw on render
        setTimeout(updateKpiSparklines, 50);
    }
})();
</script>

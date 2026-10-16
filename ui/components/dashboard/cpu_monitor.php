<?php
declare(strict_types=1);
/**
 * Dashboard Component: CPU Core Usage & Live Charts
 * Displays overall CPU load, frequency, per-core meters, and an HTML5 Canvas multi-core real-time sparkline graph.
 */
?>
<div class="card cpu-monitor-card" style="border-color:#1f6feb;margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
        <div>
            <h3 style="margin:0;display:flex;align-items:center;gap:8px">
                ⚡ CPU Multi-Core Performance
                <span id="cpuTotalBadge" class="pill ok" style="font-weight:600">0% Load</span>
            </h3>
            <p style="margin:4px 0 0 0;font-size:0.84rem;color:#8b949e">
                Real-time per-core hardware telemetry from <code>/proc/stat</code>
                <span id="cpuFreqSpan" style="margin-left:8px;color:#58a6ff;font-weight:600"></span>
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span id="cpuLiveDot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#3fb950;box-shadow:0 0 6px #3fb950"></span>
            <small style="color:#8b949e">Live (2s)</small>
            <button type="button" class="btn" style="padding:4px 10px;font-size:0.75rem" onclick="window.fetchCpuTelemetry(true)">🔄 Poll</button>
        </div>
    </div>

    <!-- Multi-Core Usage Meters Grid -->
    <div id="cpuCoresGrid" class="cpu-cores-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px;margin-bottom:14px">
        <!-- Rendered dynamically via JS, placeholder cores for instant layout -->
        <div class="cpu-core-box">
            <div class="cpu-core-head"><span>Core 0</span><b class="val">--%</b></div>
            <div class="bar"><i style="width:0%"></i></div>
        </div>
        <div class="cpu-core-box">
            <div class="cpu-core-head"><span>Core 1</span><b class="val">--%</b></div>
            <div class="bar"><i style="width:0%"></i></div>
        </div>
        <div class="cpu-core-box">
            <div class="cpu-core-head"><span>Core 2</span><b class="val">--%</b></div>
            <div class="bar"><i style="width:0%"></i></div>
        </div>
        <div class="cpu-core-box">
            <div class="cpu-core-head"><span>Core 3</span><b class="val">--%</b></div>
            <div class="bar"><i style="width:0%"></i></div>
        </div>
    </div>

    <!-- Multi-Core Canvas History Chart -->
    <div style="background:#080c12;border:1px solid #21262d;border-radius:6px;padding:10px;position:relative">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <span style="font-size:0.78rem;font-weight:600;color:#8b949e;text-transform:uppercase;letter-spacing:0.04em">Multi-Core History Chart</span>
            <div id="cpuChartLegend" style="display:flex;gap:12px;font-size:0.75rem;color:#8b949e">
                <!-- Legend items injected dynamically -->
            </div>
        </div>
        <canvas id="cpuHistoryCanvas" width="800" height="140" style="width:100%;height:140px;display:block"></canvas>
    </div>
</div>

<script>
(() => {
    const COLORS = ['#58a6ff', '#3fb950', '#d29922', '#f85149', '#bc8cff', '#39c5cf', '#ff7b72', '#7ee787'];
    const MAX_POINTS = 30;
    let coreHistories = {};
    let pollInterval = null;

    function renderCores(data) {
        const grid = document.getElementById('cpuCoresGrid');
        const badge = document.getElementById('cpuTotalBadge');
        const freqSpan = document.getElementById('cpuFreqSpan');
        if (!grid) return;

        if (badge && typeof data.total !== 'undefined') {
            const tot = Number(data.total);
            badge.textContent = tot.toFixed(1) + '% Load';
            badge.className = 'pill ' + (tot > 80 ? 'off' : (tot > 50 ? 'warn' : 'ok'));
        }

        if (freqSpan && data.freq_mhz > 0) {
            const ghz = (data.freq_mhz >= 1000) ? (data.freq_mhz / 1000).toFixed(2) + ' GHz' : data.freq_mhz + ' MHz';
            freqSpan.textContent = '• ' + ghz;
        }

        const cores = data.cores || [];
        if (cores.length === 0) return;

        // Sync history queues
        cores.forEach(c => {
            if (!coreHistories[c.id]) {
                coreHistories[c.id] = new Array(MAX_POINTS).fill(0);
            }
            coreHistories[c.id].push(Number(c.usage));
            if (coreHistories[c.id].length > MAX_POINTS) {
                coreHistories[c.id].shift();
            }
        });

        // Update / render core boxes
        grid.innerHTML = cores.map((c, i) => {
            const color = COLORS[i % COLORS.length];
            const usage = Number(c.usage);
            return `
                <div class="cpu-core-box" style="border-left:3px solid ${color};background:#11171f;padding:10px 12px;border-radius:5px;border-top:1px solid #21262d;border-right:1px solid #21262d;border-bottom:1px solid #21262d">
                    <div class="cpu-core-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                        <span style="font-weight:600;font-size:0.85rem;color:#c9d1d9">Core ${c.id}</span>
                        <b class="val" style="color:${color};font-size:0.85rem">${usage.toFixed(1)}%</b>
                    </div>
                    <div class="bar" style="height:6px;background:#21262d;border-radius:3px;overflow:hidden">
                        <i style="display:block;height:100%;width:${Math.min(100, Math.max(0, usage))}%;background:${color};transition:width 0.3s ease"></i>
                    </div>
                </div>
            `;
        }).join('');

        // Update Legend
        const legend = document.getElementById('cpuChartLegend');
        if (legend && (!legend.children || legend.children.length === 0)) {
            legend.innerHTML = cores.map((c, i) => {
                const color = COLORS[i % COLORS.length];
                return `<span style="display:inline-flex;align-items:center;gap:4px"><i style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${color}"></i> Core ${c.id}</span>`;
            }).join('');
        }

        drawChart(cores.length);
    }

    function drawChart(coreCount) {
        const canvas = document.getElementById('cpuHistoryCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        // Match resolution to CSS display width
        const rect = canvas.getBoundingClientRect();
        if (canvas.width !== rect.width || canvas.height !== rect.height) {
            canvas.width = rect.width || 800;
            canvas.height = rect.height || 140;
        }

        const W = canvas.width;
        const H = canvas.height;
        ctx.clearRect(0, 0, W, H);

        // Draw horizontal grid lines (25%, 50%, 75%, 100%)
        ctx.strokeStyle = '#1b222c';
        ctx.lineWidth = 1;
        ctx.fillStyle = '#6e7681';
        ctx.font = '10px monospace';
        [0.25, 0.5, 0.75, 1.0].forEach(p => {
            const y = H - (H * p);
            ctx.beginPath();
            ctx.moveTo(0, y);
            ctx.lineTo(W, y);
            ctx.stroke();
            ctx.fillText(Math.round(p * 100) + '%', 4, y - 2);
        });

        // Draw each core line
        Object.keys(coreHistories).forEach((coreId, idx) => {
            const hist = coreHistories[coreId];
            if (!hist || hist.length < 2) return;
            const color = COLORS[idx % COLORS.length];
            const stepX = W / (MAX_POINTS - 1);

            ctx.beginPath();
            ctx.strokeStyle = color;
            ctx.lineWidth = 1.8;
            ctx.lineJoin = 'round';
            ctx.lineCap = 'round';

            hist.forEach((val, i) => {
                const x = i * stepX;
                const clamped = Math.min(100, Math.max(0, val));
                const y = H - (clamped / 100 * H);
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });
            ctx.stroke();
        });
    }

    window.fetchCpuTelemetry = async function(manual = false) {
        const dot = document.getElementById('cpuLiveDot');
        if (dot && manual) dot.style.opacity = '0.3';

        try {
            const res = await fetch('/?action=sysinfo&_=' + Date.now());
            if (!res.ok) return;
            const data = await res.json();
            renderCores(data);
        } catch (err) {
            console.error('CPU fetch error:', err);
        } finally {
            if (dot && manual) dot.style.opacity = '1';
        }
    };

    // First fetch immediately
    window.fetchCpuTelemetry();

    // Setup periodic polling
    if (window._cpuTimer) clearInterval(window._cpuTimer);
    window._cpuTimer = setInterval(() => {
        // Only poll if tab is active and element is still on screen
        if (document.getElementById('cpuHistoryCanvas')) {
            window.fetchCpuTelemetry();
        } else {
            clearInterval(window._cpuTimer);
        }
    }, 2000);
})();
</script>

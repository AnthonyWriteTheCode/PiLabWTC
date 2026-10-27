<?php
declare(strict_types=1);
/**
 * Dashboard Component: Processes Monitor
 * Interactive table displaying top running processes on the Raspberry Pi (PID, User, CPU%, MEM%, Status, Command).
 * Lazy-loaded: Polling only executes when accordion is opened.
 */
?>
<details class="dash-accordion card" id="dashProcAccordion" style="margin-bottom:14px">
    <summary style="cursor:pointer;user-select:none;display:flex;justify-content:space-between;align-items:center;padding:4px 0">
        <div style="display:flex;align-items:center;gap:8px">
            <span style="font-size:1.1rem">📋</span>
            <span style="font-weight:600;color:#f0f6fc;font-size:0.95rem">Process Monitor &amp; Resource Usage</span>
            <span id="procCountBadge" class="pill" style="background:#21262d;color:#8b949e;font-size:0.75rem">Collapsed</span>
        </div>
        <span style="font-size:0.8rem;color:#8b949e">▸ Click to expand</span>
    </summary>

    <div style="margin-top:12px;border-top:1px solid #21262d;padding-top:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
            <div>
                <p style="margin:0;font-size:0.82rem;color:#8b949e">Top resource-consuming processes sorted by CPU and memory utilization</p>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="text" id="procFilterInput" placeholder="Filter processes..." style="background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:4px 10px;border-radius:4px;font-size:0.82rem;width:160px" oninput="window.filterProcesses()">
                <button type="button" class="btn" style="padding:4px 10px;font-size:0.75rem" onclick="window.fetchProcesses(true)">🔄 Refresh</button>
            </div>
        </div>

        <div style="overflow-x:auto;max-height:320px;overflow-y:auto;border:1px solid #21262d;border-radius:6px">
            <table class="simple-table" style="width:100%;font-size:0.82rem;border-collapse:collapse;margin-top:0">
                <thead style="position:sticky;top:0;background:#161b22;z-index:2;box-shadow:0 1px 0 #30363d">
                    <tr style="text-align:left;color:#8b949e">
                        <th style="top:0;padding:8px 10px;width:70px">PID</th>
                        <th style="top:0;padding:8px 10px;width:90px">User</th>
                        <th style="top:0;padding:8px 10px;width:80px">CPU%</th>
                        <th style="top:0;padding:8px 10px;width:80px">MEM%</th>
                        <th style="top:0;padding:8px 10px;width:60px">Stat</th>
                        <th style="top:0;padding:8px 10px">Command / Process</th>
                    </tr>
                </thead>
                <tbody id="procTableBody">
                    <tr><td colspan="6" style="text-align:center;color:#8b949e;padding:16px">Open accordion to load active processes...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</details>

<script>
(() => {
    let allProcesses = [];
    let procTimer = null;
    const accordion = document.getElementById('dashProcAccordion');

    window.filterProcesses = function() {
        const query = (document.getElementById('procFilterInput')?.value || '').toLowerCase().trim();
        const tbody = document.getElementById('procTableBody');
        const countBadge = document.getElementById('procCountBadge');
        if (!tbody) return;

        const filtered = query === '' ? allProcesses : allProcesses.filter(p => {
            return (p.comm && p.comm.toLowerCase().includes(query)) ||
                   (p.cmd && p.cmd.toLowerCase().includes(query)) ||
                   (p.user && p.user.toLowerCase().includes(query)) ||
                   String(p.pid).includes(query);
        });

        if (countBadge) {
            countBadge.textContent = `${filtered.length} Active`;
        }

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#8b949e;padding:16px">No matching processes found</td></tr>';
            return;
        }

        const escape = window.DashUtil ? window.DashUtil.escapeHtml : (s => s);

        tbody.innerHTML = filtered.map(p => {
            const cpuVal = Number(p.cpu) || 0;
            const memVal = Number(p.mem) || 0;
            const cpuColor = cpuVal > 30 ? '#f85149' : (cpuVal > 10 ? '#d29922' : '#3fb950');
            const memColor = memVal > 20 ? '#f85149' : (memVal > 5 ? '#d29922' : '#58a6ff');

            const isKeyService = /(nginx|php|mariadb|mysql|smbd|ssh|node|python)/i.test(p.comm);
            const commTag = isKeyService ? `<span style="color:#58a6ff;font-weight:600">${escape(p.comm)}</span>` : `<span>${escape(p.comm)}</span>`;

            return `
                <tr style="border-bottom:1px solid #1f242c">
                    <td style="padding:6px 10px;font-family:monospace;color:#8b949e">${p.pid}</td>
                    <td style="padding:6px 10px"><span class="badge" style="background:#1f242c;color:#c9d1d9;padding:2px 6px;font-size:0.75rem">${escape(p.user)}</span></td>
                    <td style="padding:6px 10px;font-family:monospace;font-weight:600;color:${cpuColor}">${cpuVal.toFixed(1)}%</td>
                    <td style="padding:6px 10px;font-family:monospace;color:${memColor}">${memVal.toFixed(1)}%</td>
                    <td style="padding:6px 10px;color:#8b949e;font-size:0.75rem">${escape(p.stat || '-')}</td>
                    <td style="padding:6px 10px" title="${escape(p.cmd || p.comm)}">
                        ${commTag}
                        <span style="color:#8b949e;font-size:0.75rem;margin-left:6px;max-width:320px;display:inline-block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;vertical-align:bottom">${escape(p.cmd || '')}</span>
                    </td>
                </tr>
            `;
        }).join('');
    };

    window.fetchProcesses = async function(manual = false) {
        if (!accordion || (!accordion.open && !manual)) return;
        try {
            const res = await fetch('/?action=processes&_=' + Date.now());
            if (!res.ok) return;
            const data = await res.json();
            if (Array.isArray(data)) {
                allProcesses = data;
                window.filterProcesses();
            }
        } catch (err) {
            console.error('Processes fetch error:', err);
        }
    };

    if (accordion) {
        accordion.addEventListener('toggle', () => {
            if (accordion.open) {
                window.fetchProcesses(true);
                if (procTimer) clearInterval(procTimer);
                procTimer = setInterval(window.fetchProcesses, 5000);
            } else {
                if (procTimer) {
                    clearInterval(procTimer);
                    procTimer = null;
                }
            }
        });
    }
})();
</script>

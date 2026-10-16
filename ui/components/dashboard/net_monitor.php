<?php
declare(strict_types=1);
/**
 * Dashboard Component: Network Monitor & Inbound Request Inspector
 * Shows real-time incoming HTTP requests, client IP diagnostics, active listening ports,
 * and WiFi/LAN network reachability for phones & other devices on the LAN.
 *
 * Variables passed in:
 * @var array $st Device status array
 */

$ips = explode(' ', $st['ips'] ?? '');
$primaryIp = $ips[0] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
?>
<div class="card net-monitor-card" style="border-color:#2ea043;margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
        <div>
            <h3 style="margin:0;display:flex;align-items:center;gap:8px">
                📡 Network &amp; Inbound Request Monitor
                <span class="pill ok" id="netReqCountBadge">Listening</span>
            </h3>
            <p style="margin:4px 0 0 0;font-size:0.84rem;color:#8b949e">
                Inspect incoming connections, active LAN interfaces, and diagnose device connectivity.
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span id="netLiveDot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#2ea043;box-shadow:0 0 6px #2ea043"></span>
            <small style="color:#8b949e">Live (3s)</small>
            <button type="button" class="btn" style="padding:4px 10px;font-size:0.75rem" onclick="window.fetchNetMonitor(true)">🔄 Poll Requests</button>
        </div>
    </div>

    <!-- Phone & Multi-Device Reachability Diagnostic Banner -->
    <div style="background:#131c26;border:1px solid #1f6feb;border-radius:6px;padding:12px;margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
            <div style="flex:1;min-width:260px">
                <h4 style="color:#58a6ff;margin:0 0 4px 0;display:flex;align-items:center;gap:6px">
                    📱 Phone / Mobile Device Access Helper
                </h4>
                <p style="font-size:0.82rem;color:#c9d1d9;margin:0 0 8px 0">
                    If your phone cannot load the UI while your laptop can, ensure your phone is connected to the <b>same WiFi network / SSID</b> and check these access URLs:
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;font-size:0.82rem">
                    <span style="background:#0d1117;padding:4px 8px;border-radius:4px;border:1px solid #30363d">
                        🌐 Direct IP: <a href="http://<?= h($primaryIp) ?>/" target="_blank" style="color:#38ef7d;text-decoration:none;font-weight:600">http://<?= h($primaryIp) ?>/</a>
                    </span>
                    <span style="background:#0d1117;padding:4px 8px;border-radius:4px;border:1px solid #30363d">
                        🛠️ Alt Port (4485): <a href="http://<?= h($primaryIp) ?>:4485/" target="_blank" style="color:#58a6ff;text-decoration:none;font-weight:600">http://<?= h($primaryIp) ?>:4485/</a>
                    </span>
                    <span style="background:#0d1117;padding:4px 8px;border-radius:4px;border:1px solid #30363d">
                        🏷️ mDNS Hostname: <a href="http://<?= h($st['host'] ?? 'pi') ?>.local/" target="_blank" style="color:#d2a8ff;text-decoration:none;font-weight:600">http://<?= h($st['host'] ?? 'pi') ?>.local/</a>
                    </span>
                </div>
            </div>
            <div style="background:#090d13;border:1px solid #30363d;padding:8px 12px;border-radius:5px;font-size:0.78rem;color:#8b949e">
                <div>Your Current IP: <b style="color:#e6edf3" id="netCurrentClientIp"><?= h($clientIp) ?></b></div>
                <div style="margin-top:4px">Gateway: <b style="color:#e6edf3" id="netGatewayIp">Detecting...</b></div>
            </div>
        </div>
    </div>

    <!-- Interface and Listening Ports Bar -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;font-size:0.8rem">
        <div style="flex:1;min-width:220px;background:#10151d;border:1px solid #21262d;border-radius:5px;padding:8px 12px">
            <span style="color:#8b949e;text-transform:uppercase;font-size:0.72rem;font-weight:600;display:block;margin-bottom:4px">🔌 Pi Network Interfaces</span>
            <div id="netIfacesList" style="display:flex;gap:8px;flex-wrap:wrap">
                <span class="badge" style="background:#1f242c;color:#c9d1d9">Loading interfaces...</span>
            </div>
        </div>
        <div style="flex:1;min-width:220px;background:#10151d;border:1px solid #21262d;border-radius:5px;padding:8px 12px">
            <span style="color:#8b949e;text-transform:uppercase;font-size:0.72rem;font-weight:600;display:block;margin-bottom:4px">🔓 Active Listening Ports</span>
            <div id="netListenersList" style="display:flex;gap:6px;flex-wrap:wrap">
                <span class="badge" style="background:#1f242c;color:#c9d1d9">Loading listeners...</span>
            </div>
        </div>
    </div>

    <!-- Live Inbound Request Log -->
    <div style="border:1px solid #21262d;border-radius:6px;overflow:hidden">
        <div style="background:#161b22;padding:8px 12px;border-bottom:1px solid #21262d;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span style="font-size:0.8rem;font-weight:600;color:#c9d1d9">📥 Live Inbound Web Requests (Nginx Access Log)</span>
                <!-- Target Filter Toggle: All vs Site -->
                <div style="display:inline-flex;background:#0d1117;border:1px solid #30363d;border-radius:4px;padding:2px;gap:2px">
                    <button type="button" id="netFilterAllBtn" class="btn" style="padding:2px 8px;font-size:0.74rem;background:#1f6feb;color:#fff;border:none" onclick="window.setNetScope('all')">🌐 All Requests</button>
                    <button type="button" id="netFilterSiteBtn" class="btn" style="padding:2px 8px;font-size:0.74rem;background:transparent;color:#8b949e;border:none" onclick="window.setNetScope('site')">🚀 Site Only (/site/)</button>
                    <button type="button" id="netFilterUiBtn" class="btn" style="padding:2px 8px;font-size:0.74rem;background:transparent;color:#8b949e;border:none" onclick="window.setNetScope('ui')">⚙️ UI Only</button>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <!-- Limit Selector -->
                <label style="font-size:0.75rem;color:#8b949e;display:flex;align-items:center;gap:4px">
                    Show last:
                    <select id="netLimitSelect" style="background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:3px 6px;border-radius:4px;font-size:0.75rem" onchange="window.changeNetLimit()">
                        <option value="15">15</option>
                        <option value="30" selected>30</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                    </select>
                </label>
                <input type="text" id="netFilterInput" placeholder="Filter by IP, URL, status..." style="background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:3px 8px;border-radius:4px;font-size:0.75rem;width:170px" oninput="window.filterNetRequests()">
            </div>
        </div>
        <div style="overflow-x:auto;max-height:280px;overflow-y:auto">
            <table class="simple-table" style="width:100%;font-size:0.8rem;border-collapse:collapse;margin-top:0">
                <thead style="position:sticky;top:0;background:#0d1117;z-index:2;box-shadow:0 1px 0 #21262d">
                    <tr style="text-align:left;color:#8b949e">
                        <th style="top:0;padding:6px 10px;width:120px">Client IP</th>
                        <th style="top:0;padding:6px 10px;width:70px">Method</th>
                        <th style="top:0;padding:6px 10px;width:60px">Status</th>
                        <th style="top:0;padding:6px 10px">Path / URL</th>
                        <th style="top:0;padding:6px 10px;width:140px">Time</th>
                        <th style="top:0;padding:6px 10px;width:180px">User Agent</th>
                    </tr>
                </thead>
                <tbody id="netRequestsTableBody">
                    <tr><td colspan="6" style="text-align:center;color:#8b949e;padding:14px">Listening for inbound traffic...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(() => {
    let allNetRequests = [];
    let currentScope = 'all'; // 'all', 'site', 'ui'
    let currentLimit = 30;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    window.setNetScope = function(scope) {
        currentScope = scope;
        const btnAll = document.getElementById('netFilterAllBtn');
        const btnSite = document.getElementById('netFilterSiteBtn');
        const btnUi = document.getElementById('netFilterUiBtn');
        if (btnAll) {
            btnAll.style.background = scope === 'all' ? '#1f6feb' : 'transparent';
            btnAll.style.color = scope === 'all' ? '#fff' : '#8b949e';
        }
        if (btnSite) {
            btnSite.style.background = scope === 'site' ? '#238636' : 'transparent';
            btnSite.style.color = scope === 'site' ? '#fff' : '#8b949e';
        }
        if (btnUi) {
            btnUi.style.background = scope === 'ui' ? '#8957e5' : 'transparent';
            btnUi.style.color = scope === 'ui' ? '#fff' : '#8b949e';
        }
        window.filterNetRequests();
    };

    window.changeNetLimit = function() {
        const select = document.getElementById('netLimitSelect');
        if (select) {
            currentLimit = Number(select.value) || 30;
            window.fetchNetMonitor(true);
        }
    };

    window.filterNetRequests = function() {
        const query = (document.getElementById('netFilterInput')?.value || '').toLowerCase().trim();
        const tbody = document.getElementById('netRequestsTableBody');
        const badge = document.getElementById('netReqCountBadge');
        if (!tbody) return;

        const filtered = allNetRequests.filter(r => {
            // Scope filter: All, Site only (/site/, /api/, or root app), or UI only
            const isSite = (r.path && (r.path.startsWith('/site') || r.path.startsWith('/api') || r.path.startsWith('/assets') || r.path.startsWith('/build') || r.path.startsWith('/storage') || r.path.startsWith('/static')));
            if (currentScope === 'site') {
                if (!isSite) return false;
            } else if (currentScope === 'ui') {
                if (isSite) return false;
            }

            if (query === '') return true;

            return (r.ip && r.ip.toLowerCase().includes(query)) ||
                   (r.path && r.path.toLowerCase().includes(query)) ||
                   (r.method && r.method.toLowerCase().includes(query)) ||
                   (r.status && String(r.status).includes(query)) ||
                   (r.ua && r.ua.toLowerCase().includes(query));
        });

        if (badge) {
            const scopeLabel = currentScope === 'site' ? 'Site' : (currentScope === 'ui' ? 'UI' : 'Total');
            badge.textContent = `${filtered.length} ${scopeLabel} Requests`;
        }

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#8b949e;padding:14px">No inbound requests found matching filter</td></tr>';
            return;
        }

        tbody.innerHTML = filtered.map(r => {
            const sc = Number(r.status);
            const statusColor = sc >= 500 ? '#f85149' : (sc >= 400 ? '#d29922' : (sc >= 300 ? '#58a6ff' : '#3fb950'));
            const isLocal = r.ip.startsWith('192.168.') || r.ip.startsWith('10.') || r.ip === '127.0.0.1' || r.ip === '::1';

            // Tag site requests with badge
            const isSite = (r.path && (r.path.startsWith('/site') || r.path.startsWith('/api') || r.path.startsWith('/assets') || r.path.startsWith('/build') || r.path.startsWith('/storage')));
            const siteTag = isSite ? '<span class="badge" style="background:#142a1e;color:#3fb950;border:1px solid #238636;margin-right:6px;font-size:0.7rem">SITE</span>' : '';

            // Device icon check based on user-agent
            const isMobile = /iPhone|iPad|Android|Mobile/i.test(r.ua || '');
            const deviceIcon = isMobile ? '📱' : '💻';

            return `
                <tr style="border-bottom:1px solid #1a222d">
                    <td style="padding:5px 10px;font-family:monospace">
                        <span style="color:${isLocal ? '#7ee787' : '#e6edf3'};font-weight:600">${escapeHtml(r.ip)}</span>
                    </td>
                    <td style="padding:5px 10px"><b style="color:#58a6ff">${escapeHtml(r.method)}</b></td>
                    <td style="padding:5px 10px;font-family:monospace;font-weight:600;color:${statusColor}">${escapeHtml(r.status)}</td>
                    <td style="padding:5px 10px;font-family:monospace;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escapeHtml(r.path)}">
                        ${siteTag}${escapeHtml(r.path)}
                    </td>
                    <td style="padding:5px 10px;color:#8b949e;font-size:0.75rem">${escapeHtml(r.time)}</td>
                    <td style="padding:5px 10px;font-size:0.75rem;color:#8b949e;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escapeHtml(r.ua)}">
                        ${deviceIcon} ${escapeHtml(r.ua || 'Unknown')}
                    </td>
                </tr>
            `;
        }).join('');
    };

    window.fetchNetMonitor = async function(manual = false) {
        const dot = document.getElementById('netLiveDot');
        if (dot && manual) dot.style.opacity = '0.3';

        try {
            const res = await fetch(`/?action=net_monitor&limit=${currentLimit}&_=` + Date.now());
            if (!res.ok) return;
            const data = await res.json();

            // Client IP & Gateway
            if (data.client_ip) {
                const cip = document.getElementById('netCurrentClientIp');
                if (cip) cip.textContent = data.client_ip;
            }
            if (data.gateway) {
                const gw = document.getElementById('netGatewayIp');
                if (gw) gw.textContent = data.gateway || 'LAN Default';
            }

            // Interfaces list
            if (Array.isArray(data.ifaces) && data.ifaces.length > 0) {
                const ifacesList = document.getElementById('netIfacesList');
                if (ifacesList) {
                    ifacesList.innerHTML = data.ifaces.map(it => {
                        return `<span class="badge" style="background:#162436;color:#58a6ff;border:1px solid #1f6feb;padding:2px 6px"><b>${escapeHtml(it.iface)}:</b> ${escapeHtml(it.ip)}</span>`;
                    }).join('');
                }
            }

            // Listeners
            if (Array.isArray(data.listeners) && data.listeners.length > 0) {
                const listEl = document.getElementById('netListenersList');
                if (listEl) {
                    listEl.innerHTML = data.listeners.map(l => {
                        return `<span class="badge" style="background:#13241b;color:#3fb950;border:1px solid #238636;padding:2px 6px">Port ${escapeHtml(l.port)} (${escapeHtml(l.proc || 'svc')})</span>`;
                    }).join('');
                }
            }

            // Requests
            if (Array.isArray(data.requests)) {
                // Reverse so newest requests appear at top
                allNetRequests = [...data.requests].reverse();
                window.filterNetRequests();
            }

        } catch (err) {
            console.error('Net monitor fetch error:', err);
        } finally {
            if (dot && manual) dot.style.opacity = '1';
        }
    };

    // Initial fetch
    window.fetchNetMonitor();

    // Polling every 3 seconds
    if (window._netTimer) clearInterval(window._netTimer);
    window._netTimer = setInterval(() => {
        if (document.getElementById('netRequestsTableBody')) {
            window.fetchNetMonitor();
        } else {
            clearInterval(window._netTimer);
        }
    }, 3000);
})();
</script>

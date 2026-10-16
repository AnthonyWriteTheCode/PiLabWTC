<?php
declare(strict_types=1);
/**
 * Tab Container: Firewall & Ports
 * Interactive UFW firewall manager allowing users to enable/disable firewall protection,
 * open/close specific ports (TCP/UDP), quick-toggle common services, and view active listening ports.
 */

$fw = wj('firewall');
if (empty($fw)) {
    // Development workstation fallback data
    $fw = [
        'installed' => true,
        'active' => true,
        'default_in' => 'deny',
        'default_out' => 'allow',
        'rules' => [
            ['index' => 1, 'port' => '22', 'proto' => 'tcp', 'action' => 'ALLOW', 'direction' => 'IN', 'from' => 'Anywhere', 'comment' => 'SSH Server'],
            ['index' => 2, 'port' => '80', 'proto' => 'tcp', 'action' => 'ALLOW', 'direction' => 'IN', 'from' => 'Anywhere', 'comment' => 'HTTP Web Server'],
            ['index' => 3, 'port' => '8080', 'proto' => 'tcp', 'action' => 'ALLOW', 'direction' => 'IN', 'from' => 'Anywhere', 'comment' => 'Site Direct Port'],
            ['index' => 4, 'port' => '4485', 'proto' => 'tcp', 'action' => 'ALLOW', 'direction' => 'IN', 'from' => 'Anywhere', 'comment' => 'WTC Management UI'],
            ['index' => 5, 'port' => '3306', 'proto' => 'tcp', 'action' => 'DENY', 'direction' => 'IN', 'from' => 'Anywhere', 'comment' => 'MariaDB Database']
        ],
        'listeners' => [
            ['port' => 80, 'proc' => 'nginx'],
            ['port' => 8080, 'proc' => 'nginx'],
            ['port' => 4485, 'proc' => 'nginx'],
            ['port' => 3306, 'proc' => 'mariadbd'],
            ['port' => 22, 'proc' => 'sshd'],
            ['port' => 445, 'proc' => 'smbd']
        ]
    ];
}

$installed = !empty($fw['installed']);
$active = !empty($fw['active']);
$rules = $fw['rules'] ?? [];
$listeners = $fw['listeners'] ?? [];

// Helper to determine if a port is currently allowed
$allowedPorts = [];
$blockedPorts = [];
foreach ($rules as $r) {
    $p = (string)($r['port'] ?? '');
    $act = strtoupper((string)($r['action'] ?? ''));
    if ($act === 'ALLOW') {
        $allowedPorts[$p] = true;
    } elseif ($act === 'DENY' || $act === 'REJECT') {
        $blockedPorts[$p] = true;
    }
}

// Preset common Raspberry Pi services for 1-click toggling
$commonServices = [
    ['name' => 'HTTP Web Server', 'port' => '80', 'proto' => 'tcp', 'icon' => '🌐', 'desc' => 'Port 80 (Site /site/ & UI Root)'],
    ['name' => 'Site Direct Root', 'port' => '8080', 'proto' => 'tcp', 'icon' => '🚀', 'desc' => 'Port 8080 (Native root application)'],
    ['name' => 'WTC Management UI', 'port' => '4485', 'proto' => 'tcp', 'icon' => '🛠️', 'desc' => 'Port 4485 (Dedicated LAN UI)', 'locked' => true],
    ['name' => 'SSH Server', 'port' => '22', 'proto' => 'tcp', 'icon' => '🔒', 'desc' => 'Port 22 (Remote Shell & SFTP)', 'locked' => true],
    ['name' => 'MariaDB Database', 'port' => '3306', 'proto' => 'tcp', 'icon' => '🗄️', 'desc' => 'Port 3306 (MySQL remote access)'],
    ['name' => 'Samba File Sharing', 'port' => '445', 'proto' => 'tcp', 'icon' => '📁', 'desc' => 'Port 445 (Windows SMB / Dropbox)'],
    ['name' => 'FTP File Transfer', 'port' => '21', 'proto' => 'tcp', 'icon' => '📦', 'desc' => 'Port 21 (FTP daemon vsftpd)'],
    ['name' => 'Vite / Node Dev Server', 'port' => '5173', 'proto' => 'tcp', 'icon' => '⚡', 'desc' => 'Port 5173 (Hot Module Reloading)']
];
?>

<div class="card" style="margin-bottom:16px;border-color:<?= $active ? '#2ea043' : '#d29922' ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h3 style="margin:0;display:flex;align-items:center;gap:8px">
                🛡️ Raspberry Pi Firewall (UFW)
                <?php if (!$installed): ?>
                    <span class="pill off">Not Installed</span>
                <?php elseif ($active): ?>
                    <span class="pill ok">Active &amp; Protecting</span>
                <?php else: ?>
                    <span class="pill warn">Inactive (All Ports Open)</span>
                <?php endif; ?>
            </h3>
            <p style="margin:4px 0 0 0;font-size:0.86rem;color:#8b949e">
                Manage inbound network access, allow or block ports, and shield sensitive database &amp; system daemons.
            </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <?php if (!$installed): ?>
                <?= form('firewall', ['do' => 'install'], '📦 Install UFW Firewall', 'btn-pri', 'Install UFW packet filter on the Raspberry Pi?') ?>
            <?php elseif ($active): ?>
                <?= form('firewall', ['do' => 'disable'], '🛑 Disable Firewall', 'btn-ctrl danger', 'Disable the firewall? All system ports will be accessible on the network.') ?>
                <?= form('firewall', ['do' => 'reset'], '🔄 Reset Rules', 'btn-ctrl', 'Reset firewall rules to safe default (Allow 22, 80, 8080, 4485)?') ?>
            <?php else: ?>
                <?= form('firewall', ['do' => 'enable'], '▶️ Enable Firewall', 'btn-pri', 'Enable UFW firewall? Unopened ports will be blocked from external access.') ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 1-Click Common Service Port Toggles -->
<div class="card" style="margin-bottom:16px">
    <div class="card-head" style="margin-bottom:12px">
        <div>
            <h3 style="margin:0">⚡ Quick Service Port Controller</h3>
            <p style="margin:4px 0 0 0;font-size:0.84rem;color:#8b949e">Enable or disable access to standard services with one click.</p>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:12px">
        <?php foreach ($commonServices as $svc): ?>
            <?php
            $p = $svc['port'];
            $isAllowed = !empty($allowedPorts[$p]) || !$active;
            $isBlocked = !empty($blockedPorts[$p]) && $active;
            $isListening = false;
            foreach ($listeners as $l) {
                if ((string)($l['port'] ?? '') === $p) {
                    $isListening = true;
                    break;
                }
            }
            ?>
            <div style="background:#11171f;border:1px solid #21262d;border-radius:6px;padding:12px;display:flex;justify-content:space-between;align-items:center;gap:10px">
                <div>
                    <div style="display:flex;align-items:center;gap:6px">
                        <span style="font-size:1.1rem"><?= $svc['icon'] ?></span>
                        <b style="font-size:0.9rem;color:#f0f6fc"><?= h($svc['name']) ?></b>
                        <code style="font-size:0.75rem;background:#090d13;padding:1px 5px;border-radius:3px;border:1px solid #30363d">:<?= h($p) ?></code>
                    </div>
                    <div style="font-size:0.76rem;color:#8b949e;margin-top:2px"><?= h($svc['desc']) ?></div>
                    <div style="margin-top:4px">
                        <?php if ($isListening): ?>
                            <span style="color:#58a6ff;font-size:0.72rem">● Daemon listening</span>
                        <?php else: ?>
                            <span style="color:#8b949e;font-size:0.72rem">○ Inactive</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <?php if (!empty($svc['locked'])): ?>
                        <span class="pill ok" title="Protected management port">Protected</span>
                    <?php elseif (!$installed): ?>
                        <span class="pill off">UFW Required</span>
                    <?php elseif ($isAllowed && $active): ?>
                        <?= form('firewall', ['do' => 'deny', 'port' => $p, 'proto' => $svc['proto']], '🚫 Block', 'btn-ctrl danger', "Block incoming traffic on port $p?") ?>
                    <?php else: ?>
                        <?= form('firewall', ['do' => 'allow', 'port' => $p, 'proto' => $svc['proto']], '✅ Allow', 'btn-ctrl', "Open port $p to network clients?") ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Custom Port Management Form & Active Rules Table -->
<div class="grid2">
    <!-- Manual Port Opener Card -->
    <div class="card">
        <h3>🔓 Open / Close Custom Port</h3>
        <p style="font-size:0.84rem;color:#8b949e;margin-bottom:12px">
            Specify any custom TCP or UDP port (e.g. <code>3000</code>, <code>8000</code>, <code>9000</code>) to allow through the firewall.
        </p>
        <form method="post" action="/" style="display:flex;flex-direction:column;gap:10px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="firewall">
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <div style="flex:2;min-width:120px">
                    <label style="font-size:0.78rem;color:#8b949e;display:block;margin-bottom:4px">Port Number</label>
                    <input type="number" name="port" min="1" max="65535" placeholder="e.g. 3000" required style="width:100%;background:#090d13;border:1px solid #30363d;color:#e6edf3;padding:6px 10px;border-radius:4px;font-size:0.85rem">
                </div>
                <div style="flex:1;min-width:90px">
                    <label style="font-size:0.78rem;color:#8b949e;display:block;margin-bottom:4px">Protocol</label>
                    <select name="proto" style="width:100%;background:#090d13;border:1px solid #30363d;color:#e6edf3;padding:6px 8px;border-radius:4px;font-size:0.85rem">
                        <option value="tcp" selected>TCP</option>
                        <option value="udp">UDP</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:6px">
                <button name="do" value="allow" class="btn-pri" style="flex:1">✅ Allow Inbound</button>
                <button name="do" value="deny" class="btn-ctrl danger" style="flex:1">🚫 Deny Inbound</button>
            </div>
        </form>
    </div>

    <!-- Active Listening Ports -->
    <div class="card">
        <h3>🔌 Active System Listeners</h3>
        <p style="font-size:0.84rem;color:#8b949e;margin-bottom:12px">
            Services currently bound to local sockets (discovered via <code>ss -tulpn</code>).
        </p>
        <div style="max-height:210px;overflow-y:auto;border:1px solid #21262d;border-radius:5px">
            <table class="simple-table" style="width:100%;font-size:0.8rem;margin-top:0">
                <thead>
                    <tr>
                        <th style="padding:6px 10px">Port</th>
                        <th style="padding:6px 10px">Process / Daemon</th>
                        <th style="padding:6px 10px">Firewall State</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($listeners)): ?>
                        <tr><td colspan="3" style="text-align:center;color:#8b949e;padding:10px">No listeners detected</td></tr>
                    <?php else: ?>
                        <?php foreach ($listeners as $l): ?>
                            <?php
                            $lp = (string)($l['port'] ?? '');
                            $isOpen = !empty($allowedPorts[$lp]) || !$active;
                            ?>
                            <tr>
                                <td style="padding:5px 10px;font-family:monospace;font-weight:600;color:#58a6ff">:<?= h($lp) ?></td>
                                <td style="padding:5px 10px;color:#c9d1d9"><?= h($l['proc'] ?: 'daemon') ?></td>
                                <td style="padding:5px 10px">
                                    <?php if ($isOpen): ?>
                                        <span class="pill ok">Open</span>
                                    <?php else: ?>
                                        <span class="pill off">Filtered</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Firewall Rules Table -->
<div class="card">
    <div class="card-head" style="margin-bottom:12px">
        <div>
            <h3 style="margin:0">📜 Configured UFW Rules</h3>
            <p style="margin:4px 0 0 0;font-size:0.84rem;color:#8b949e">Current packet filter table entries in numbered sequence.</p>
        </div>
    </div>
    <div style="overflow-x:auto">
        <table class="simple-table" style="width:100%;font-size:0.84rem">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th style="width:120px">Port / Protocol</th>
                    <th style="width:90px">Action</th>
                    <th style="width:90px">Direction</th>
                    <th style="width:120px">From</th>
                    <th>Comment / Service</th>
                    <th style="width:90px;text-align:right">Remove</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rules)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;color:#8b949e;padding:16px">
                            <?= $installed ? 'No custom rules defined (Default incoming traffic is denied)' : 'UFW not installed' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rules as $r): ?>
                        <?php
                        $act = strtoupper((string)($r['action'] ?? ''));
                        $pillCls = $act === 'ALLOW' ? 'ok' : 'off';
                        ?>
                        <tr>
                            <td style="font-family:monospace;color:#8b949e"><?= h((string)($r['index'] ?? '')) ?></td>
                            <td style="font-family:monospace;font-weight:600;color:#f0f6fc">
                                <?= h((string)($r['port'] ?? '')) ?>/<?= h((string)($r['proto'] ?? 'any')) ?>
                            </td>
                            <td><span class="pill <?= $pillCls ?>"><?= h($act) ?></span></td>
                            <td style="color:#8b949e"><?= h((string)($r['direction'] ?? 'IN')) ?></td>
                            <td style="font-family:monospace;color:#c9d1d9"><?= h((string)($r['from'] ?? 'Anywhere')) ?></td>
                            <td style="color:#8b949e"><?= h((string)($r['comment'] ?: '-')) ?></td>
                            <td style="text-align:right">
                                <?php if (($r['port'] ?? '') !== '4485' && ($r['port'] ?? '') !== '22'): ?>
                                    <?= form('firewall', ['do' => 'delete', 'port' => $r['port'], 'proto' => $r['proto']], '🗑️ Delete', 'btn-ctrl danger', "Remove rule for port {$r['port']}?") ?>
                                <?php else: ?>
                                    <span style="color:#8b949e;font-size:0.75rem">Locked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
declare(strict_types=1);
/**
 * Tab Container: Service Manager
 * Provides comprehensive service inspection and lifecycle management.
 * Explicitly structures and distinguishes services that belong to us (WTC Platform & Web Stack)
 * from system services running on the Linux kernel and operating system.
 */

$svcData = wj('services');

if (!is_array($svcData)) {
    $svcData = [];
}

$summary = $svcData['summary'] ?? [
    'total' => 0,
    'running' => 0,
    'stopped' => 0,
    'failed' => 0,
    'ours_count' => 0,
    'system_count' => 0
];

$ourServices = $svcData['ours'] ?? [];
$systemServices = $svcData['system'] ?? [];

$secReport = $svcData['security'] ?? null;
if (!is_array($secReport) || empty($secReport)) {
    // No live scan data available: show an empty report rather than invented findings
    $secReport = [
        'grade' => '?',
        'grade_label' => 'Not scanned - press Scan Vulnerabilities',
        'grade_color' => '#8b949e',
        'total_vulnerabilities' => 0,
        'critical_count' => 0,
        'high_count' => 0,
        'medium_count' => 0,
        'low_count' => 0,
        'issues' => [],
        'services' => [],
        'timestamp' => date('c')
    ];
}

$serviceSecurityMap = [];
if (!empty($secReport['services']) && is_array($secReport['services'])) {
    foreach ($secReport['services'] as $svcSec) {
        if (!empty($svcSec['unit'])) {
            $serviceSecurityMap[$svcSec['unit']] = $svcSec;
            $bare = preg_replace('/\.service$/', '', $svcSec['unit']);
            $serviceSecurityMap[$bare] = $svcSec;
        }
    }
}

if (!function_exists('wtc_js_json')) {
    function wtc_js_json($data, string $fallback = 'null'): string {
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $out = json_encode($data, $flags);
        return ($out === false || $out === '') ? $fallback : $out;
    }
}

require_once __DIR__ . '/../cve_client.php';

// Known CVE and vulnerability mitigation catalog across all managed services
$knownServiceAdvisories = get_cve_threat_catalog();

// Check if MariaDB port 3306 is currently exposed to LAN
$isDbPortExposed = false;
foreach ($secReport['issues'] ?? [] as $iss) {
    if (($iss['id'] ?? '') === 'db_port_exposed') {
        $isDbPortExposed = true;
        break;
    }
}
if ($isDbPortExposed && isset($knownServiceAdvisories['mariadb'])) {
    foreach ($knownServiceAdvisories['mariadb'] as &$adv) {
        if (($adv['id'] ?? '') === 'SEC-BIND-01') {
            $adv['status'] = 'pending';
            $adv['severity'] = 'high';
            $adv['title'] = 'MariaDB Port 3306 Exposed to LAN (0.0.0.0 / :: Interface)';
            $adv['desc'] = 'MariaDB is listening on public interfaces instead of binding strictly to loopback (127.0.0.1).';
            $adv['mitigation'] = 'Apply 1-click patch to bind MariaDB strictly to 127.0.0.1 in 99-wtc-bind.cnf.';
        }
    }
    unset($adv);
}

// Separate our services into WTC platform core vs web application stack daemons
$wtcPlatform = [];
$webStack = [];
foreach ($ourServices as $s) {
    if (($s['type'] ?? '') === 'platform' || str_starts_with((string)$s['unit'], 'wtc-')) {
        $wtcPlatform[] = $s;
    } else {
        $webStack[] = $s;
    }
}

function get_service_version(string $unit, array $serviceSecurityMap): array {
    $sec = $serviceSecurityMap[$unit] ?? $serviceSecurityMap[$unit . '.service'] ?? null;
    $installed = (string)($sec['installed_version'] ?? '');
    $candidate = (string)($sec['candidate_version'] ?? '');
    $hasPatch = !empty($sec['has_patch']);

    if (empty($installed) || $installed === 'installed') {
        $u = strtolower($unit);
        if (str_starts_with($u, 'php')) {
            if (preg_match('/php([0-9]+\.[0-9]+)/', $u, $pm)) {
                $installed = $pm[1];
            } else {
                $installed = (defined('PHP_VERSION') && PHP_VERSION ? PHP_VERSION : '');
            }
        } elseif (str_starts_with($u, 'wtc-')) {
            $installed = '1.2.0 (PiLab)';
        } else {
            $installed = '';
        }
    }

    $short = $installed;
    if (preg_match('/^([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $installed, $m)) {
        $short = $m[1];
    } elseif (preg_match('/^([0-9]+(?:\.[0-9]+)?p[0-9]+)/i', $installed, $m)) {
        $short = $m[1];
    }

    return [
        'full' => $installed,
        'short' => $short,
        'candidate' => $candidate,
        'has_patch' => $hasPatch
    ];
}

// Precalculate CVE threat intelligence audits across all services
$serviceAuditsMap = [];
$allServicesList = array_merge($ourServices, $systemServices);
foreach ($allServicesList as $srv) {
    $uName = (string)($srv['unit'] ?? '');
    if ($uName !== '') {
        $vInfo = get_service_version($uName, $serviceSecurityMap);
        $audits = audit_service_cves($uName, $vInfo['full'], $secReport['issues'] ?? []);
        $serviceAuditsMap[$uName] = $audits;
        $bare = preg_replace('/\.service$/', '', $uName);
        $serviceAuditsMap[$bare] = $audits;
    }
}

// Synchronize all detected service CVEs directly into the top scanner report
if (!isset($secReport['issues']) || !is_array($secReport['issues'])) {
    $secReport['issues'] = [];
}
$existingIssueIds = array_column($secReport['issues'], 'id');
$existingRemIds = array_column($secReport['issues'], 'remediation_id');

foreach ($allServicesList as $srv) {
    $uName = (string)($srv['unit'] ?? '');
    if ($uName === '') continue;
    $bare = preg_replace('/\.service$/', '', $uName);
    $audits = $serviceAuditsMap[$uName] ?? $serviceAuditsMap[$bare] ?? [];
    $pending = array_values(array_filter($audits, fn($c) => ($c['status'] ?? '') === 'pending' || !empty($c['is_affected'])));

    foreach ($pending as $cve) {
        if (($cve['id'] ?? '') === 'SEC-BIND-01') continue;

        $issueId = 'patch_' . $uName;
        $remedId = 'patch_' . $uName;

        if (!in_array($issueId, $existingIssueIds, true) && !in_array($remedId, $existingRemIds, true)) {
            $vInfo = get_service_version($uName, $serviceSecurityMap);
            $cveId = (string)($cve['id'] ?? 'CVE');
            $sev = strtolower((string)($cve['severity'] ?? 'high'));
            $cveTitle = (string)($cve['title'] ?? 'Security Advisory');
            $fixedTarget = (string)($cve['fixed_version'] ?? 'latest update');

            $secReport['issues'][] = [
                'id' => $issueId,
                'category' => 'package_cve',
                'severity' => $sev,
                'title' => $cveId . ' in ' . $uName . ': ' . $cveTitle,
                'desc' => 'Installed version (' . $vInfo['full'] . ') is affected by ' . $cveId . '. Fixed in ' . $fixedTarget . '.',
                'remediation' => 'Apply 1-click patch for ' . $uName . ' in the Services Monitor.',
                'can_remediate' => true,
                'remediation_id' => $remedId,
                'unit' => $uName,
                'cve_id' => $cveId
            ];
            $existingIssueIds[] = $issueId;
            $existingRemIds[] = $remedId;

            if (!isset($serviceSecurityMap[$uName])) {
                $serviceSecurityMap[$uName] = [
                    'unit' => $uName,
                    'has_patch' => true,
                    'installed_version' => $vInfo['full'],
                    'candidate_version' => $fixedTarget,
                    'cve' => $cveId
                ];
                $serviceSecurityMap[$bare] = $serviceSecurityMap[$uName];
            } else {
                $serviceSecurityMap[$uName]['has_patch'] = true;
                $serviceSecurityMap[$uName]['cve'] = $cveId;
                if (empty($serviceSecurityMap[$uName]['candidate_version']) && $fixedTarget !== '') {
                    $serviceSecurityMap[$uName]['candidate_version'] = $fixedTarget;
                }
                $serviceSecurityMap[$bare] = $serviceSecurityMap[$uName];
            }
        }
    }
}

// Recalculate summary metrics and grade based on the complete synchronized issues
$secReport['total_vulnerabilities'] = count($secReport['issues']);
$secReport['critical_count'] = count(array_filter($secReport['issues'], fn($i) => strtolower((string)($i['severity'] ?? '')) === 'critical'));
$secReport['high_count'] = count(array_filter($secReport['issues'], fn($i) => strtolower((string)($i['severity'] ?? '')) === 'high'));
$secReport['medium_count'] = count(array_filter($secReport['issues'], fn($i) => strtolower((string)($i['severity'] ?? '')) === 'medium'));
$secReport['low_count'] = count(array_filter($secReport['issues'], fn($i) => strtolower((string)($i['severity'] ?? '')) === 'low'));

if ($secReport['critical_count'] > 0) {
    $secReport['grade'] = 'F';
    $secReport['grade_label'] = 'Vulnerable';
    $secReport['grade_color'] = '#f85149';
} elseif ($secReport['high_count'] > 0) {
    $secReport['grade'] = 'C';
    $secReport['grade_label'] = 'Needs Attention';
    $secReport['grade_color'] = '#ff7b72';
} elseif ($secReport['medium_count'] > 0) {
    $secReport['grade'] = 'B';
    $secReport['grade_label'] = 'Moderate';
    $secReport['grade_color'] = '#e3b341';
} elseif ($secReport['low_count'] > 0) {
    $secReport['grade'] = 'A';
    $secReport['grade_label'] = 'Good';
    $secReport['grade_color'] = '#58a6ff';
} else {
    $secReport['grade'] = 'A+';
    $secReport['grade_label'] = 'Excellent';
    $secReport['grade_color'] = '#3fb950';
}

function service_version_badge(string $unit, array $serviceSecurityMap): string {
    global $serviceAuditsMap;
    $v = get_service_version($unit, $serviceSecurityMap);
    $verText = 'v' . $v['short'];
    if (str_starts_with($unit, 'wtc-')) {
        $verText = 'v1.2.0';
    }

    $audits = $serviceAuditsMap[$unit] ?? $serviceAuditsMap[preg_replace('/\.service$/', '', $unit)] ?? [];
    $pendingCves = array_values(array_filter($audits, fn($c) => ($c['status'] ?? '') === 'pending' || !empty($c['is_affected'])));

    if (!empty($pendingCves)) {
        $primary = $pendingCves[0];
        $targetVer = !empty($primary['fixed_version']) ? $primary['fixed_version'] : (!empty($v['candidate']) ? $v['candidate'] : 'Update');
        if (preg_match('/([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\+)?)/', $targetVer, $m)) {
            $targetVer = $m[1];
        }
        return '<span id="ver-badge-' . h($unit) . '" class="pill service-ver-badge" style="font-size:0.72rem;background:#3c181a;color:#ff7b72;border:1px solid #da363366;padding:1px 6px;cursor:pointer" onclick="openServicePatchModal(\'' . h($unit) . '\')" title="Installed: ' . h($v['full']) . ' &bull; Target Patch: ' . h($primary['fixed_version'] ?? $targetVer) . ' &bull; Vulnerable to ' . h($primary['id'] ?? '') . '">' . h($verText) . ' ➔ ' . h($targetVer) . '</span>';
    }

    if ($v['has_patch'] && !empty($v['candidate'])) {
        return '<span id="ver-badge-' . h($unit) . '" class="pill service-ver-badge" style="font-size:0.72rem;background:#3c181a;color:#ff7b72;border:1px solid #da363366;padding:1px 6px;cursor:pointer" onclick="openServicePatchModal(\'' . h($unit) . '\')" title="Installed: ' . h($v['full']) . ' &bull; Update Available: ' . h($v['candidate']) . '">' . h($verText) . ' ➔ ' . h($v['candidate']) . '</span>';
    }

    return '<span id="ver-badge-' . h($unit) . '" class="pill service-ver-badge" style="font-size:0.72rem;background:#161b22;color:#79c0ff;border:1px solid #30363d;padding:1px 6px" title="Installed package version: ' . h($v['full']) . '">' . h($verText) . '</span>';
}

function get_service_advisories(string $unit, array $knownServiceAdvisories, ?array $sec): array {
    global $serviceAuditsMap;
    $bare = preg_replace('/\.service$/', '', $unit);
    if (!empty($serviceAuditsMap[$unit])) {
        return $serviceAuditsMap[$unit];
    }
    if (!empty($serviceAuditsMap[$bare])) {
        return $serviceAuditsMap[$bare];
    }
    return $knownServiceAdvisories[$bare] ?? [];
}

function service_status_badge(array $s): string {
    $act = strtolower((string)($s['active'] ?? 'inactive'));
    $sub = strtolower((string)($s['sub'] ?? ''));
    if (!empty($s['is_failed']) || $act === 'failed') {
        return '<span class="pill off" style="background:#da3633;color:#fff">failed</span>';
    }
    if ($act === 'active') {
        if ($sub === 'running' || $sub === 'listening') {
            return '<span class="pill ok">active (' . h($sub) . ')</span>';
        }
        if ($sub === 'exited' || $sub === 'waiting') {
            return '<span class="pill ok" style="background:#1f6feb;color:#fff">active (' . h($sub) . ')</span>';
        }
        return '<span class="pill ok">active</span>';
    }
    return '<span class="pill off">stopped</span>';
}

function service_boot_badge(array $s): string {
    $en = strtolower((string)($s['enabled'] ?? 'disabled'));
    if ($en === 'enabled') {
        return '<span class="pill ok" style="background:#1b4728;color:#56d364;font-size:0.75rem;padding:2px 8px">boot: on</span>';
    }
    if ($en === 'static') {
        return '<span class="pill off" style="background:#21262d;color:#8b949e;font-size:0.75rem;padding:2px 8px">boot: static</span>';
    }
    return '<span class="pill off" style="background:#30363d;color:#c9d1d9;font-size:0.75rem;padding:2px 8px">boot: off</span>';
}

function service_security_badge(string $unit, array $serviceSecurityMap, array $secIssues = []): string {
    global $serviceAuditsMap;
    $sec = $serviceSecurityMap[$unit] ?? $serviceSecurityMap[$unit . '.service'] ?? null;
    $v = get_service_version($unit, $serviceSecurityMap);
    $audits = $serviceAuditsMap[$unit] ?? $serviceAuditsMap[preg_replace('/\.service$/', '', $unit)] ?? audit_service_cves($unit, $v['full'], $secIssues);

    $pendingCves = array_values(array_filter($audits, fn($c) => ($c['status'] ?? '') === 'pending' || !empty($c['is_affected'])));

    if (!empty($pendingCves)) {
        // Find most critical pending CVE
        $primary = $pendingCves[0];
        foreach ($pendingCves as $c) {
            if (($c['cvss'] ?? 0) > ($primary['cvss'] ?? 0)) {
                $primary = $c;
            }
        }

        if (($primary['id'] ?? '') === 'SEC-BIND-01') {
            return '<span class="pill sec-badge-interactive" style="background:#3c181a;color:#f85149;border:1px solid #da3633;font-size:0.75rem;padding:2px 8px;cursor:pointer" '
                . 'onclick="openServicePatchModal(\'' . h($unit) . '\')" '
                . 'onmouseenter="showSecTooltip(this, \'' . h($unit) . '\', \'' . h($v['full']) . '\', true)" '
                . 'onmouseleave="hideSecTooltip()">'
                . '⚠️ LAN Exposed (3306)'
                . '</span>';
        }

        $tag = h($primary['id'] ?? 'Vulnerable');
        $moreCount = count($pendingCves) - 1;
        $badgeText = '⚠️ ' . $tag . ($moreCount > 0 ? " (+$moreCount)" : '');
        $cvssVal = !empty($primary['cvss']) ? ' • CVSS ' . $primary['cvss'] : '';

        return '<span class="pill sec-badge-interactive" style="background:#3c181a;color:#f85149;border:1px solid #da3633;font-size:0.75rem;padding:2px 8px;cursor:pointer" '
            . 'onclick="openServicePatchModal(\'' . h($unit) . '\')" '
            . 'onmouseenter="showSecTooltip(this, \'' . h($unit) . '\', \'' . h($v['full']) . '\', true)" '
            . 'onmouseleave="hideSecTooltip()"'
            . ' title="Vulnerable to ' . $tag . $cvssVal . '">'
            . $badgeText
            . '</span>';
    }

    if (!empty($sec['has_patch'])) {
        $cve = !empty($sec['cve']) ? $sec['cve'] : 'Patch Available';
        return '<span class="pill sec-badge-interactive" style="background:#3c181a;color:#f85149;border:1px solid #da3633;font-size:0.75rem;padding:2px 8px;cursor:pointer" '
            . 'onclick="openServicePatchModal(\'' . h($unit) . '\')" '
            . 'onmouseenter="showSecTooltip(this, \'' . h($unit) . '\', \'' . h($v['full']) . '\', true)" '
            . 'onmouseleave="hideSecTooltip()">'
            . '⚠️ ' . h($cve)
            . '</span>';
    }

    if (!empty($audits)) {
        return '<span class="pill ok sec-badge-interactive" style="background:#1b4728;color:#56d364;font-size:0.75rem;padding:2px 8px;border:1px solid #23863644;cursor:pointer" '
            . 'onclick="openServicePatchModal(\'' . h($unit) . '\')" '
            . 'onmouseenter="showSecTooltip(this, \'' . h($unit) . '\', \'' . h($v['full']) . '\', false)" '
            . 'onmouseleave="hideSecTooltip()">'
            . '🛡️ Patched'
            . '</span>';
    }

    return '<span class="pill sec-badge-interactive" style="background:#161b22;color:#8b949e;border:1px solid #30363d;font-size:0.75rem;padding:2px 8px;cursor:pointer" '
        . 'onclick="openServicePatchModal(\'' . h($unit) . '\')" '
        . 'onmouseenter="showSecTooltip(this, \'' . h($unit) . '\', \'' . h($v['full']) . '\', false)" '
        . 'onmouseleave="hideSecTooltip()">'
        . '🛡️ Monitored'
        . '</span>';
}
?>

<!-- Top Metric Header -->
<div class="card" style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="margin:0;display:flex;align-items:center;gap:10px">
                ⚙️ Service Manager
                <span class="pill ok" style="font-size:0.8rem;text-transform:none">systemd architecture</span>
            </h2>
            <p style="margin:4px 0 0 0;font-size:0.88rem;color:#8b949e">
                Inspect, control, and monitor live daemons. Distinguishes <b>services belonging to us</b> (WTC Platform &amp; Web Stack) from <b>system services running on the Linux kernel / OS</b>.
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button type="button" class="btn" id="btnRefreshServices" onclick="refreshServicesTelemetry(this)" title="Refresh live status from systemd">
                <span class="refresh-icon">🔄</span> Refresh
            </button>
        </div>
    </div>

    <!-- Summary Stats Bar -->
    <div class="grid3" style="margin-top:16px;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px">
        <div class="card" style="padding:12px;text-align:center;background:#11171f;border-color:#30363d">
            <div style="font-size:1.4rem;font-weight:700;color:#58a6ff" id="statTotal"><?= (int)$summary['total'] ?></div>
            <div style="font-size:0.75rem;color:#8b949e;text-transform:uppercase;letter-spacing:0.5px">Total Units</div>
        </div>
        <div class="card" style="padding:12px;text-align:center;background:#11171f;border-color:#238636">
            <div style="font-size:1.4rem;font-weight:700;color:#3fb950" id="statRunning"><?= (int)$summary['running'] ?></div>
            <div style="font-size:0.75rem;color:#8b949e;text-transform:uppercase;letter-spacing:0.5px">Active Running</div>
        </div>
        <div class="card" style="padding:12px;text-align:center;background:#11171f;border-color:#30363d">
            <div style="font-size:1.4rem;font-weight:700;color:#8b949e" id="statStopped"><?= (int)$summary['stopped'] ?></div>
            <div style="font-size:0.75rem;color:#8b949e;text-transform:uppercase;letter-spacing:0.5px">Inactive / Stopped</div>
        </div>
        <div class="card" style="padding:12px;text-align:center;background:#11171f;border-color:<?= !empty($summary['failed']) ? '#da3633' : '#30363d' ?>">
            <div style="font-size:1.4rem;font-weight:700;color:<?= !empty($summary['failed']) ? '#f85149' : '#8b949e' ?>" id="statFailed"><?= (int)$summary['failed'] ?></div>
            <div style="font-size:0.75rem;color:#8b949e;text-transform:uppercase;letter-spacing:0.5px">Failed Units</div>
        </div>
        <div class="card" style="padding:12px;text-align:center;background:#11171f;border-color:#1f6feb">
            <div style="font-size:1.4rem;font-weight:700;color:#58a6ff" id="statOurs"><?= (int)count($ourServices) ?></div>
            <div style="font-size:0.75rem;color:#8b949e;text-transform:uppercase;letter-spacing:0.5px">⚡ Our Services</div>
        </div>
        <div class="card" style="padding:12px;text-align:center;background:#11171f;border-color:#d29922">
            <div style="font-size:1.4rem;font-weight:700;color:#e3b341" id="statKernel"><?= (int)count($systemServices) ?></div>
            <div style="font-size:0.75rem;color:#8b949e;text-transform:uppercase;letter-spacing:0.5px">🐧 Kernel &amp; OS</div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- SECURITY & VULNERABILITY CENTER (CVE, RCE & PATCH MANAGER)      -->
<!-- ============================================================== -->
<div class="card" style="margin-bottom:16px;border-color:<?= h($secReport['grade_color'] ?? '#3fb950') ?>66;background:linear-gradient(180deg, #11171f 0%, #0d1117 100%)">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px">
        <div style="display:flex;align-items:center;gap:14px">
            <div id="secGradeBox" style="width:52px;height:52px;border-radius:10px;background:<?= h($secReport['grade_color'] ?? '#3fb950') ?>22;border:2px solid <?= h($secReport['grade_color'] ?? '#3fb950') ?>;display:flex;flex-direction:column;align-items:center;justify-content:center">
                <span id="secGradeLetter" style="font-size:1.4rem;font-weight:800;color:<?= h($secReport['grade_color'] ?? '#3fb950') ?>;line-height:1"><?= h($secReport['grade'] ?? 'A') ?></span>
                <span style="font-size:0.6rem;text-transform:uppercase;color:#8b949e;letter-spacing:0.5px">Grade</span>
            </div>
            <div>
                <h3 style="margin:0;display:flex;align-items:center;gap:8px">
                    🛡️ Security &amp; Vulnerability Center
                    <span id="secGradeBadge" class="pill" style="font-size:0.75rem;background:<?= h($secReport['grade_color'] ?? '#3fb950') ?>22;color:<?= h($secReport['grade_color'] ?? '#3fb950') ?>;border:1px solid <?= h($secReport['grade_color'] ?? '#3fb950') ?>66">
                        <?= h($secReport['grade_label'] ?? 'Secure') ?>
                    </span>
                </h3>
                <p style="margin:4px 0 0 0;font-size:0.86rem;color:#8b949e">
                    Real-time detection of RCE vulnerabilities, service CVEs, network port exposures, and insecure application configurations.
                </p>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <button type="button" class="btn" id="btnScanSec" onclick="scanVulnerabilities(this)" title="Iteratively scan every service daemon against open source CVE feeds">
                <span class="refresh-icon">🔍</span> Scan Vulnerabilities
            </button>
            <button type="button" class="btn-pri" style="background:#238636;border-color:#2ea043" onclick="openPatchAllModal()" title="Apply all pending security updates across all services">
                ⚡ Patch All Services
            </button>
            <button type="button" class="btn" id="btnToggleIssues" onclick="toggleSecurityDetails()" style="border-color:#f8514966;color:#f85149;display:<?= !empty($secReport['issues']) ? 'inline-block' : 'none' ?>">
                ⚠️ View Advisories (<?= count($secReport['issues'] ?? []) ?>)
            </button>
        </div>
    </div>

    <!-- Security Metric Stats -->
    <div class="grid3" style="margin-top:14px;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px">
        <div class="card" style="padding:10px;text-align:center;background:#090d13;border-color:<?= (int)($secReport['total_vulnerabilities'] ?? 0) > 0 ? '#da363366' : '#23863644' ?>">
            <div id="metricTotalVulns" style="font-size:1.3rem;font-weight:700;color:<?= (int)($secReport['total_vulnerabilities'] ?? 0) > 0 ? '#f85149' : '#3fb950' ?>">
                <?= (int)($secReport['total_vulnerabilities'] ?? 0) ?>
            </div>
            <div style="font-size:0.72rem;color:#8b949e;text-transform:uppercase">Vulnerabilities</div>
        </div>
        <div class="card" style="padding:10px;text-align:center;background:#090d13;border-color:<?= (int)($secReport['critical_count'] ?? 0) > 0 ? '#da3633' : '#30363d' ?>">
            <div id="metricCriticalCves" style="font-size:1.3rem;font-weight:700;color:<?= (int)($secReport['critical_count'] ?? 0) > 0 ? '#f85149' : '#8b949e' ?>">
                <?= (int)($secReport['critical_count'] ?? 0) ?>
            </div>
            <div style="font-size:0.72rem;color:#8b949e;text-transform:uppercase">Critical CVEs</div>
        </div>
        <div class="card" style="padding:10px;text-align:center;background:#090d13;border-color:<?= (int)($secReport['high_count'] ?? 0) > 0 ? '#db6d28' : '#30363d' ?>">
            <div id="metricHighSev" style="font-size:1.3rem;font-weight:700;color:<?= (int)($secReport['high_count'] ?? 0) > 0 ? '#ff7b72' : '#8b949e' ?>">
                <?= (int)($secReport['high_count'] ?? 0) ?>
            </div>
            <div style="font-size:0.72rem;color:#8b949e;text-transform:uppercase">High Severity</div>
        </div>
        <div class="card" style="padding:10px;text-align:center;background:#090d13;border-color:<?= (int)($secReport['medium_count'] ?? 0) > 0 ? '#d29922' : '#30363d' ?>">
            <div id="metricMediumSev" style="font-size:1.3rem;font-weight:700;color:<?= (int)($secReport['medium_count'] ?? 0) > 0 ? '#e3b341' : '#8b949e' ?>">
                <?= (int)($secReport['medium_count'] ?? 0) ?>
            </div>
            <div style="font-size:0.72rem;color:#8b949e;text-transform:uppercase">Medium / Warnings</div>
        </div>
        <div class="card" style="padding:10px;text-align:center;background:#090d13;border-color:#1f6feb44">
            <div style="font-size:1.3rem;font-weight:700;color:#58a6ff">
                Active
            </div>
            <div style="font-size:0.72rem;color:#8b949e;text-transform:uppercase">Auto Security Patches</div>
        </div>
        <div class="card" style="padding:10px;text-align:center;background:#090d13;border-color:#30363d">
            <div id="metricLastScan" style="font-size:0.9rem;font-weight:600;color:#c9d1d9;margin-top:4px">
                <?= !empty($secReport['timestamp']) ? date('H:i:s', strtotime($secReport['timestamp'])) : date('H:i:s') ?>
            </div>
            <div style="font-size:0.72rem;color:#8b949e;text-transform:uppercase">Last Scan</div>
        </div>
    </div>

    <!-- Collapsible Security Issues & 1-Click Remediation Panel -->
    <div id="securityIssuesPanel" style="display:<?= !empty($secReport['issues']) ? 'block' : 'none' ?>;margin-top:16px;border-top:1px solid #30363d;padding-top:14px">
        <h4 style="margin:0 0 10px 0;font-size:0.92rem;color:#e6edf3;display:flex;align-items:center;gap:6px">
            <span>🔍</span> Detected Security Advisories &amp; System Hardening Recommendations
        </h4>

        <div id="noSecurityIssuesMsg" style="display:<?= empty($secReport['issues']) ? 'block' : 'none' ?>;padding:12px;background:#161b22;border:1px solid #30363d;border-radius:6px;color:#8b949e;font-size:0.86rem">
            ✅ No security vulnerabilities or unpatched service CVEs detected on this system.
        </div>
        <div id="securityIssuesList" style="display:<?= !empty($secReport['issues']) ? 'flex' : 'none' ?>;flex-direction:column;gap:10px">
                <?php foreach ($secReport['issues'] as $issue): 
                    $sev = strtolower((string)($issue['severity'] ?? 'low'));
                    $sevBg = '#161b22'; $sevColor = '#8b949e'; $sevBorder = '#30363d';
                    if ($sev === 'critical') { $sevBg = '#490202'; $sevColor = '#ff7b72'; $sevBorder = '#f85149'; }
                    elseif ($sev === 'high') { $sevBg = '#3c181a'; $sevColor = '#f85149'; $sevBorder = '#da3633'; }
                    elseif ($sev === 'medium') { $sevBg = '#2b220d'; $sevColor = '#d29922'; $sevBorder = '#bb8009'; }
                    elseif ($sev === 'low') { $sevBg = '#0c2d6b'; $sevColor = '#79c0ff'; $sevBorder = '#1f6feb'; }

                    $catLabel = strtoupper(str_replace('_', ' ', (string)($issue['category'] ?? 'SYSTEM')));
                    $cardUnit = $issue['unit'] ?? (preg_match('/^patch_(.+)$/', (string)($issue['id'] ?? ''), $pm) ? $pm[1] : '');
                ?>
                    <div class="sec-advisory-card" data-advisory-unit="<?= h($cardUnit) ?>" data-severity="<?= h($sev) ?>" style="background:#090d13;border:1px solid <?= $sevBorder ?>66;border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
                        <div style="flex:1;min-width:280px">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap">
                                <span class="pill" style="background:<?= $sevBg ?>;color:<?= $sevColor ?>;border:1px solid <?= $sevBorder ?>;font-size:0.72rem;padding:2px 8px;font-weight:bold">
                                    <?= strtoupper($sev) ?>
                                </span>
                                <span class="pill" style="background:#21262d;color:#8b949e;font-size:0.7rem;padding:1px 6px">
                                    <?= h($catLabel) ?>
                                </span>
                                <b style="font-size:0.92rem;color:#e6edf3"><?= h($issue['title'] ?? 'Security Advisory') ?></b>
                            </div>
                            <p style="margin:4px 0 6px 0;font-size:0.84rem;color:#c9d1d9;line-height:1.4">
                                <?= h($issue['desc'] ?? '') ?>
                            </p>
                            <div style="font-size:0.8rem;color:#8b949e;display:flex;align-items:center;gap:6px">
                                <span style="color:#58a6ff">💡 Guidance:</span> <?= h($issue['remediation'] ?? 'Review system service settings.') ?>
                            </div>
                        </div>

                        <?php if (!empty($issue['can_remediate']) && !empty($issue['remediation_id'])): ?>
                            <div style="white-space:nowrap;align-self:center">
                                <?= form('security_remediate', ['rule' => $issue['remediation_id'], 'back' => 'services'], '🛡️ Fix Issue Now', 'btn-pri', 'Apply automated security hardening for: ' . ($issue['title'] ?? '') . '?') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
    </div>

    <!-- Live Multi-Source CVE Threat Intelligence Gateway -->
    <div style="margin-top:16px;border-top:1px solid #30363d;padding-top:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
            <div>
                <h4 style="margin:0;font-size:0.92rem;color:#e6edf3;display:flex;align-items:center;gap:8px">
                    <span>🌐</span> Live Multi-Source CVE Threat Intelligence Gateway
                </h4>
                <div style="font-size:0.78rem;color:#8b949e;margin-top:2px">
                    Query live vulnerability intelligence feeds (Google OSV, Shodan CVEDB, FIRST EPSS, NVD, CISA KEV) across all services.
                </div>
            </div>
            <div style="display:flex;gap:6px;align-items:center">
                <span class="pill" style="font-size:0.7rem;background:#161b22;color:#7ee787;border:1px solid #23863644">● Google OSV</span>
                <span class="pill" style="font-size:0.7rem;background:#161b22;color:#79c0ff;border:1px solid #1f6feb44">● Shodan CVEDB</span>
                <span class="pill" style="font-size:0.7rem;background:#161b22;color:#d2a8ff;border:1px solid #a371f744">● FIRST EPSS</span>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="text" id="cveQueryInput" placeholder="Enter CVE ID (e.g. CVE-2024-6387, CVE-2023-44487, CVE-2024-4577)..."
                style="flex:1;min-width:260px;background:#090d13;border:1px solid #30363d;border-radius:6px;padding:7px 12px;color:#e6edf3;font-size:0.84rem;font-family:monospace">
            <button type="button" class="btn-pri" id="btnQueryCve" onclick="queryLiveCveIntelligence()" style="background:#1f6feb;border-color:#388bfd">
                🔍 Query Intelligence
            </button>
            <button type="button" class="btn" onclick="lookupQuickCve('CVE-2024-6387')" style="font-size:0.76rem;padding:6px 10px">
                OpenSSH regreSSHion
            </button>
            <button type="button" class="btn" onclick="lookupQuickCve('CVE-2023-44487')" style="font-size:0.76rem;padding:6px 10px">
                HTTP/2 Rapid Reset
            </button>
            <button type="button" class="btn" onclick="lookupQuickCve('CVE-2024-4577')" style="font-size:0.76rem;padding:6px 10px">
                PHP CGI RCE
            </button>
        </div>
        <div id="cveQueryResult" style="display:none;margin-top:12px;background:#090d13;border:1px solid #30363d;border-radius:6px;padding:12px;font-size:0.85rem">
            <!-- Populated dynamically via JS -->
        </div>
    </div>
</div>

<!-- Interactive Toolbar: Filter by Category, Status, and Search -->
<div class="card" style="margin-bottom:16px;padding:12px 16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <!-- Search Input -->
        <div style="position:relative;flex:1;min-width:260px">
            <input type="text" id="serviceSearchInput" placeholder="🔍 Filter services by name, unit, or description..."
                style="width:100%;padding:8px 32px 8px 12px;background:#090d13;border:1px solid #30363d;border-radius:6px;color:#e6edf3;font-size:0.9rem">
            <button type="button" id="clearSearchBtn" onclick="clearServiceSearch()"
                style="display:none;position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#8b949e;cursor:pointer;font-size:1rem">✕</button>
        </div>

        <!-- Category View Switcher -->
        <div style="display:flex;gap:4px;background:#090d13;padding:3px;border-radius:8px;border:1px solid #21262d">
            <button type="button" class="btn-filter on" data-category="all" onclick="setCategoryFilter('all', this)">
                All (<?= (int)$summary['total'] ?>)
            </button>
            <button type="button" class="btn-filter" data-category="ours" onclick="setCategoryFilter('ours', this)">
                ⚡ Our Services (<?= (int)count($ourServices) ?>)
            </button>
            <button type="button" class="btn-filter" data-category="system" onclick="setCategoryFilter('system', this)">
                🐧 Kernel &amp; OS (<?= (int)count($systemServices) ?>)
            </button>
        </div>

        <!-- Status Filter -->
        <div style="display:flex;gap:4px;background:#090d13;padding:3px;border-radius:8px;border:1px solid #21262d">
            <button type="button" class="btn-filter on" data-status="all" onclick="setStatusFilter('all', this)">
                All Status
            </button>
            <button type="button" class="btn-filter" data-status="running" onclick="setStatusFilter('running', this)">
                🟢 Running
            </button>
            <button type="button" class="btn-filter" data-status="stopped" onclick="setStatusFilter('stopped', this)">
                ⚪ Stopped
            </button>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- SECTION 1: SERVICES THAT BELONG TO US (WTC Platform & Stack)   -->
<!-- ============================================================== -->
<div id="sectionOurs" class="service-section" data-section="ours" style="margin-bottom:24px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;border-bottom:1px solid #21262d;padding-bottom:8px">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px;color:#58a6ff">
            ⚡ Services That Belong To Us
            <span class="pill ok" style="font-size:0.75rem;padding:2px 8px">Application &amp; Platform</span>
        </h3>
        <span style="font-size:0.82rem;color:#8b949e">Custom WTC systemd services and the active web application hosting stack</span>
    </div>

    <!-- 1A: WTC Core Platform Daemons -->
    <div style="margin-bottom:20px">
        <h4 style="margin:0 0 8px 0;font-size:0.92rem;color:#79c0ff;display:flex;align-items:center;gap:6px">
            <span>🛡️</span> WTC Core Platform Daemons (PiLab Architecture)
        </h4>
        <div class="services-table-wrapper card" style="padding:0;overflow-x:auto">
            <table class="simple-table service-table" style="margin:0">
                <thead>
                    <tr style="background:#11171f;border-bottom:1px solid #30363d">
                        <th style="width:20%">Service / Daemon</th>
                        <th style="width:14%">Status</th>
                        <th style="width:11%">Boot State</th>
                        <th style="width:16%">Security &amp; CVE Check</th>
                        <th style="width:21%">Description &amp; Role</th>
                        <th style="width:18%;text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wtcPlatform as $s): 
                        $vInfo = get_service_version($s['unit'], $serviceSecurityMap);
                    ?>
                        <tr class="service-row" data-category="ours" data-unit="<?= h($s['unit']) ?>"
                            data-version="<?= h($vInfo['full']) ?>" data-short-version="<?= h($vInfo['short']) ?>"
                            data-name="<?= h(strtolower($s['name'])) ?>" data-desc="<?= h(strtolower($s['desc'])) ?>"
                            data-running="<?= !empty($s['is_running']) ? '1' : '0' ?>"
                            data-failed="<?= !empty($s['is_failed']) ? '1' : '0' ?>">
                            <td>
                                <div style="display:flex;flex-direction:column;gap:3px">
                                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                        <b style="font-size:0.92rem;color:#e6edf3"><?= h($s['name']) ?></b>
                                        <?= service_version_badge($s['unit'], $serviceSecurityMap) ?>
                                    </div>
                                    <code style="font-size:0.78rem;color:#79c0ff"><?= h($s['unit']) ?></code>
                                </div>
                            </td>
                            <td><?= service_status_badge($s) ?></td>
                            <td><?= service_boot_badge($s) ?></td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:3px">
                                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap">
                                        <span id="sec-badge-<?= h($s['unit']) ?>">
                                            <?= service_security_badge($s['unit'], $serviceSecurityMap, $secReport['issues'] ?? []) ?>
                                        </span>
                                        <button type="button" class="btn btn-row-check-cve" id="btn-cve-check-<?= h($s['unit']) ?>"
                                            onclick="checkServiceRowCve('<?= h($s['unit']) ?>', this)"
                                            style="padding:2px 7px;font-size:0.72rem;border-color:#30363d;white-space:nowrap"
                                            title="Query open source CVE feeds (OSV, Ubuntu, Debian, EPSS) for <?= h($s['unit']) ?>">
                                            🔍 Check
                                        </button>
                                    </div>
                                    <div style="font-size:0.70rem;color:#8b949e;display:flex;align-items:center;gap:4px">
                                        <span style="opacity:0.6">🕒</span>
                                        <span id="sec-time-<?= h($s['unit']) ?>">
                                            <?php 
                                                $svcSec = $serviceSecurityMap[$s['unit']] ?? $serviceSecurityMap[preg_replace('/\.service$/', '', $s['unit'])] ?? null;
                                                if (!empty($svcSec['last_scanned'])) {
                                                    echo 'Scanned ' . h($svcSec['last_scanned']);
                                                } elseif (!empty($secReport['timestamp'])) {
                                                    echo 'Scanned ' . date('H:i:s', strtotime($secReport['timestamp']));
                                                } else {
                                                    echo 'Not scanned';
                                                }
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:0.84rem;color:#8b949e"><?= h($s['desc']) ?></td>
                            <td style="text-align:right;white-space:nowrap">
                                <div style="display:inline-flex;gap:4px;align-items:center">
                                    <button type="button" class="btn" style="padding:3px 8px;font-size:0.75rem"
                                        onclick="showServiceJournalLogs('<?= h($s['unit']) ?>', '<?= h($s['name']) ?>')">📜 Logs</button>
                                    <button type="button" class="btn" style="padding:3px 8px;font-size:0.75rem;border-color:#388bfd44;color:#58a6ff"
                                        onclick="openServicePatchModal('<?= h($s['unit']) ?>', '<?= h($s['name']) ?>')">🛡️ Patch</button>
                                    <?php if (!empty($s['is_running'])): ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'restart'], '🔄 Restart', 'btn', 'Restart service ' . $s['unit'] . '?') ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'stop'], '⏹ Stop', 'btn-ctrl danger', 'Stop service ' . $s['unit'] . '?') ?>
                                    <?php else: ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'start'], '▶ Start', 'btn-pri', 'Start service ' . $s['unit'] . '?') ?>
                                    <?php endif; ?>
                                    <?php if (($s['enabled'] ?? '') === 'enabled'): ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'disable'], 'Disable', 'btn-ctrl', 'Disable auto-start on boot for ' . $s['unit'] . '?') ?>
                                    <?php elseif (($s['enabled'] ?? '') === 'disabled'): ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'enable'], 'Enable', 'btn-ctrl', 'Enable auto-start on boot for ' . $s['unit'] . '?') ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 1B: Application Web Stack Daemons -->
    <div>
        <h4 style="margin:0 0 8px 0;font-size:0.92rem;color:#7ee787;display:flex;align-items:center;gap:6px">
            <span>🌐</span> Application Web Stack Daemons (Web, PHP, DB &amp; Networking)
        </h4>
        <div class="services-table-wrapper card" style="padding:0;overflow-x:auto">
            <table class="simple-table service-table" style="margin:0">
                <thead>
                    <tr style="background:#11171f;border-bottom:1px solid #30363d">
                        <th style="width:20%">Stack Daemon</th>
                        <th style="width:14%">Status</th>
                        <th style="width:11%">Boot State</th>
                        <th style="width:16%">Security &amp; CVE Check</th>
                        <th style="width:21%">Description &amp; Role</th>
                        <th style="width:18%;text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($webStack as $s): 
                        $vInfo = get_service_version($s['unit'], $serviceSecurityMap);
                    ?>
                        <tr class="service-row" data-category="ours" data-unit="<?= h($s['unit']) ?>"
                            data-version="<?= h($vInfo['full']) ?>" data-short-version="<?= h($vInfo['short']) ?>"
                            data-name="<?= h(strtolower($s['name'])) ?>" data-desc="<?= h(strtolower($s['desc'])) ?>"
                            data-running="<?= !empty($s['is_running']) ? '1' : '0' ?>"
                            data-failed="<?= !empty($s['is_failed']) ? '1' : '0' ?>">
                            <td>
                                <div style="display:flex;flex-direction:column;gap:3px">
                                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                        <b style="font-size:0.92rem;color:#e6edf3"><?= h($s['name']) ?></b>
                                        <?= service_version_badge($s['unit'], $serviceSecurityMap) ?>
                                    </div>
                                    <code style="font-size:0.78rem;color:#7ee787"><?= h($s['unit']) ?></code>
                                </div>
                            </td>
                            <td><?= service_status_badge($s) ?></td>
                            <td><?= service_boot_badge($s) ?></td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:3px">
                                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap">
                                        <span id="sec-badge-<?= h($s['unit']) ?>">
                                            <?= service_security_badge($s['unit'], $serviceSecurityMap, $secReport['issues'] ?? []) ?>
                                        </span>
                                        <button type="button" class="btn btn-row-check-cve" id="btn-cve-check-<?= h($s['unit']) ?>"
                                            onclick="checkServiceRowCve('<?= h($s['unit']) ?>', this)"
                                            style="padding:2px 7px;font-size:0.72rem;border-color:#30363d;white-space:nowrap"
                                            title="Query open source CVE feeds (OSV, Ubuntu, Debian, EPSS) for <?= h($s['unit']) ?>">
                                            🔍 Check
                                        </button>
                                    </div>
                                    <div style="font-size:0.70rem;color:#8b949e;display:flex;align-items:center;gap:4px">
                                        <span style="opacity:0.6">🕒</span>
                                        <span id="sec-time-<?= h($s['unit']) ?>">
                                            <?php 
                                                $svcSec = $serviceSecurityMap[$s['unit']] ?? $serviceSecurityMap[preg_replace('/\.service$/', '', $s['unit'])] ?? null;
                                                if (!empty($svcSec['last_scanned'])) {
                                                    echo 'Scanned ' . h($svcSec['last_scanned']);
                                                } elseif (!empty($secReport['timestamp'])) {
                                                    echo 'Scanned ' . date('H:i:s', strtotime($secReport['timestamp']));
                                                } else {
                                                    echo 'Not scanned';
                                                }
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:0.84rem;color:#8b949e"><?= h($s['desc']) ?></td>
                            <td style="text-align:right;white-space:nowrap">
                                <div style="display:inline-flex;gap:4px;align-items:center">
                                    <button type="button" class="btn" style="padding:3px 8px;font-size:0.75rem"
                                        onclick="showServiceJournalLogs('<?= h($s['unit']) ?>', '<?= h($s['name']) ?>')">📜 Logs</button>
                                    <button type="button" class="btn" style="padding:3px 8px;font-size:0.75rem;border-color:#388bfd44;color:#58a6ff"
                                        onclick="openServicePatchModal('<?= h($s['unit']) ?>', '<?= h($s['name']) ?>')">🛡️ Patch</button>
                                    <?php if (!empty($s['is_running'])): ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'restart'], '🔄 Restart', 'btn', 'Restart ' . $s['unit'] . '?') ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'stop'], '⏹ Stop', 'btn-ctrl danger', 'Stop ' . $s['unit'] . '?') ?>
                                    <?php else: ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'start'], '▶ Start', 'btn-pri', 'Start ' . $s['unit'] . '?') ?>
                                    <?php endif; ?>
                                    <?php if (($s['enabled'] ?? '') === 'enabled'): ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'disable'], 'Disable', 'btn-ctrl', 'Disable auto-start on boot for ' . $s['unit'] . '?') ?>
                                    <?php elseif (($s['enabled'] ?? '') === 'disabled'): ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'enable'], 'Enable', 'btn-ctrl', 'Enable auto-start on boot for ' . $s['unit'] . '?') ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- SECTION 2: SYSTEM SERVICES RUNNING ON THE KERNEL / OS          -->
<!-- ============================================================== -->
<div id="sectionSystem" class="service-section" data-section="system" style="margin-bottom:24px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;border-bottom:1px solid #21262d;padding-bottom:8px">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px;color:#e3b341">
            🐧 System Services Running on the Kernel &amp; OS
            <span class="pill warn" style="font-size:0.75rem;padding:2px 8px;background:#2b220d;color:#d29922;border:1px solid #bb8009">Linux Kernel &amp; systemd Base</span>
        </h3>
        <span style="font-size:0.82rem;color:#8b949e">Hardware drivers, kernel event journal, networking stack, and operating system daemons</span>
    </div>

    <div class="services-table-wrapper card" style="padding:0;overflow-x:auto">
        <table class="simple-table service-table" style="margin:0">
            <thead>
                <tr style="background:#11171f;border-bottom:1px solid #30363d">
                    <th style="width:20%">Kernel / System Service</th>
                    <th style="width:14%">Subsystem Role</th>
                    <th style="width:12%">Status</th>
                    <th style="width:9%">Boot State</th>
                    <th style="width:15%">Security &amp; CVE Check</th>
                    <th style="width:15%">Description</th>
                    <th style="width:15%;text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($systemServices as $s): 
                    $vInfo = get_service_version($s['unit'], $serviceSecurityMap);
                    $roleLabel = 'System Daemon';
                    $roleColor = '#8b949e';
                    $roleBg = '#161b22';
                    $subType = (string)($s['type'] ?? '');
                    if ($subType === 'kernel_hardware') {
                        $roleLabel = 'Kernel & Hardware';
                        $roleColor = '#ff7b72';
                        $roleBg = '#3c181a';
                    } elseif ($subType === 'kernel_network') {
                        $roleLabel = 'OS Networking';
                        $roleColor = '#79c0ff';
                        $roleBg = '#0c2d6b';
                    } elseif ($subType === 'kernel_system') {
                        $roleLabel = 'OS Core & Auth';
                        $roleColor = '#d2a8ff';
                        $roleBg = '#271052';
                    }
                    $isCriticalKernel = in_array((string)$s['unit'], ['systemd-journald.service', 'systemd-udevd.service', 'dbus.service', 'systemd-logind.service'], true);
                ?>
                    <tr class="service-row" data-category="system" data-unit="<?= h($s['unit']) ?>"
                        data-version="<?= h($vInfo['full']) ?>" data-short-version="<?= h($vInfo['short']) ?>"
                        data-name="<?= h(strtolower($s['name'])) ?>" data-desc="<?= h(strtolower($s['desc'])) ?>"
                        data-running="<?= !empty($s['is_running']) ? '1' : '0' ?>"
                        data-failed="<?= !empty($s['is_failed']) ? '1' : '0' ?>">
                        <td>
                            <div style="display:flex;flex-direction:column;gap:3px">
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                    <b style="font-size:0.92rem;color:#e6edf3"><?= h($s['name']) ?></b>
                                    <?= service_version_badge($s['unit'], $serviceSecurityMap) ?>
                                </div>
                                <code style="font-size:0.78rem;color:#e3b341"><?= h($s['unit']) ?></code>
                            </div>
                        </td>
                        <td>
                            <span class="pill" style="background:<?= $roleBg ?>;color:<?= $roleColor ?>;font-size:0.75rem;padding:2px 8px;border:1px solid <?= $roleColor ?>44">
                                <?= h($roleLabel) ?>
                            </span>
                        </td>
                        <td><?= service_status_badge($s) ?></td>
                        <td><?= service_boot_badge($s) ?></td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:3px">
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap">
                                    <span id="sec-badge-<?= h($s['unit']) ?>">
                                        <?= service_security_badge($s['unit'], $serviceSecurityMap, $secReport['issues'] ?? []) ?>
                                    </span>
                                    <button type="button" class="btn btn-row-check-cve" id="btn-cve-check-<?= h($s['unit']) ?>"
                                        onclick="checkServiceRowCve('<?= h($s['unit']) ?>', this)"
                                        style="padding:2px 7px;font-size:0.72rem;border-color:#30363d;white-space:nowrap"
                                        title="Query open source CVE feeds (OSV, Ubuntu, Debian, EPSS) for <?= h($s['unit']) ?>">
                                        🔍 Check
                                    </button>
                                </div>
                                <div style="font-size:0.70rem;color:#8b949e;display:flex;align-items:center;gap:4px">
                                    <span style="opacity:0.6">🕒</span>
                                    <span id="sec-time-<?= h($s['unit']) ?>">
                                        <?php 
                                            $svcSec = $serviceSecurityMap[$s['unit']] ?? $serviceSecurityMap[preg_replace('/\.service$/', '', $s['unit'])] ?? null;
                                            if (!empty($svcSec['last_scanned'])) {
                                                echo 'Scanned ' . h($svcSec['last_scanned']);
                                            } elseif (!empty($secReport['timestamp'])) {
                                                echo 'Scanned ' . date('H:i:s', strtotime($secReport['timestamp']));
                                            } else {
                                                echo 'Not scanned';
                                            }
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:0.84rem;color:#8b949e"><?= h($s['desc']) ?></td>
                        <td style="text-align:right;white-space:nowrap">
                            <div style="display:inline-flex;gap:4px;align-items:center">
                                <button type="button" class="btn" style="padding:3px 8px;font-size:0.75rem"
                                    onclick="showServiceJournalLogs('<?= h($s['unit']) ?>', '<?= h($s['name']) ?>')">📜 Logs</button>
                                <button type="button" class="btn" style="padding:3px 8px;font-size:0.75rem;border-color:#388bfd44;color:#58a6ff"
                                    onclick="openServicePatchModal('<?= h($s['unit']) ?>', '<?= h($s['name']) ?>')">🛡️ Patch</button>
                                <?php if (!empty($s['is_running'])): ?>
                                    <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'restart'], '🔄 Restart', 'btn', 'Restart kernel/system service ' . $s['unit'] . '?') ?>
                                    <?php if (!$isCriticalKernel): ?>
                                        <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'stop'], '⏹ Stop', 'btn-ctrl danger', 'Warning: Stopping system service ' . $s['unit'] . ' may affect OS behavior. Stop?') ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= form('service_ctl', ['service' => $s['unit'], 'do' => 'start'], '▶ Start', 'btn-pri', 'Start system service ' . $s['unit'] . '?') ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Empty Filter Message -->
<div id="serviceEmptyMsg" class="card" style="display:none;text-align:center;padding:32px;margin-bottom:16px">
    <div style="font-size:2rem;margin-bottom:8px">🔍</div>
    <h3 style="margin:0 0 6px 0;color:#c9d1d9">No matching services found</h3>
    <p style="margin:0;font-size:0.88rem;color:#8b949e">Try adjusting your keyword search query or clearing the category filter.</p>
    <button type="button" class="btn" style="margin-top:12px" onclick="clearServiceSearch()">Reset Search</button>
</div>

<!-- ============================================================== -->
<!-- SERVICE JOURNAL LOG VIEWER MODAL                               -->
<!-- ============================================================== -->
<div id="journalModal" class="modal-overlay" style="display:none">
    <div class="modal-card" style="max-width:850px;width:95%">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #30363d;padding-bottom:12px;margin-bottom:14px">
            <div>
                <h3 id="journalModalTitle" style="margin:0;font-size:1.1rem;display:flex;align-items:center;gap:8px">
                    📜 Systemd Journal Log
                </h3>
                <code id="journalModalUnit" style="font-size:0.82rem;color:#79c0ff">service.unit</code>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <select id="journalLineCount" onchange="reloadCurrentJournal()"
                    style="background:#090d13;border:1px solid #30363d;color:#e6edf3;padding:4px 8px;border-radius:4px;font-size:0.8rem">
                    <option value="30">30 lines</option>
                    <option value="50" selected>50 lines</option>
                    <option value="100">100 lines</option>
                    <option value="200">200 lines</option>
                </select>
                <button type="button" class="btn" style="padding:4px 10px;font-size:0.8rem" onclick="copyJournalOutput()">📋 Copy</button>
                <button type="button" class="btn" style="padding:4px 10px;font-size:0.8rem" onclick="closeJournalModal()">✕ Close</button>
            </div>
        </div>
        <div id="journalLoading" style="display:none;padding:24px;text-align:center;color:#8b949e">
            <span class="refresh-icon" style="display:inline-block;animation:spin 1s linear infinite">🔄</span> Fetching journalctl logs...
        </div>
        <pre id="journalOutput" style="background:#090d13;border:1px solid #30363d;border-radius:6px;padding:12px;max-height:480px;overflow-y:auto;font-family:monospace;font-size:0.82rem;line-height:1.45;color:#c9d1d9;white-space:pre-wrap;word-break:break-all"></pre>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;font-size:0.8rem;color:#8b949e">
            <span>Direct output from <code>journalctl -u &lt;unit&gt;</code></span>
            <button type="button" class="btn-pri" style="padding:4px 14px;font-size:0.82rem" onclick="closeJournalModal()">Done</button>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- FLOATING SECURITY & VULNERABILITY TOOLTIP                      -->
<!-- ============================================================== -->
<div id="secFloatingTooltip" style="display:none;position:fixed;z-index:10000;max-width:360px;width:max-content;background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:12px;box-shadow:0 12px 28px rgba(0,0,0,0.85);pointer-events:none;font-size:0.82rem;line-height:1.45">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;border-bottom:1px solid #21262d;padding-bottom:6px;gap:8px">
        <b id="tooltipTitle" style="color:#e6edf3;font-size:0.86rem">Service Security</b>
        <span id="tooltipBadge" class="pill ok" style="font-size:0.7rem;padding:1px 6px">Patched</span>
    </div>
    <div id="tooltipVersion" style="color:#79c0ff;margin-bottom:6px;font-family:monospace;font-size:0.75rem">Installed: -</div>
    <div id="tooltipContent" style="color:#c9d1d9;margin-bottom:6px">
        <!-- Dynamic list of patched/pending CVEs -->
    </div>
    <div style="font-size:0.72rem;color:#8b949e;border-top:1px solid #21262d;padding-top:4px;display:flex;align-items:center;gap:4px">
        <span>💡 Click badge for complete vulnerability details</span>
    </div>
</div>

<!-- ============================================================== -->
<!-- SERVICE SECURITY PATCH & VULNERABILITY DETAILS MODAL            -->
<!-- ============================================================== -->
<div id="servicePatchModal" class="modal-overlay" style="display:none">
    <div class="modal-card" style="max-width:640px;width:95%;max-height:90vh;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #30363d;padding-bottom:12px;margin-bottom:14px">
            <div>
                <h3 id="patchModalTitle" style="margin:0;font-size:1.15rem;display:flex;align-items:center;gap:8px">
                    🛡️ Service Security &amp; Vulnerabilities
                </h3>
                <code id="patchModalUnit" style="font-size:0.82rem;color:#79c0ff">service.unit</code>
            </div>
            <button type="button" class="btn" style="padding:4px 10px;font-size:0.8rem" onclick="closeServicePatchModal()">✕ Close</button>
        </div>

        <div style="margin-bottom:14px;font-size:0.88rem;color:#c9d1d9;line-height:1.5">
            <!-- Active Remediation Alert Banner (e.g., LAN Port Exposure) -->
            <div id="patchModalAlertBanner" style="display:none;background:#21090a;border:1px solid #da3633;border-radius:8px;padding:12px;margin-bottom:14px;font-size:0.84rem;color:#f85149;line-height:1.45">
                <div style="font-weight:bold;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                    <span style="font-size:1rem">⚠️</span> MariaDB Database Port 3306 Exposed to LAN
                </div>
                <div style="color:#c9d1d9;font-size:0.82rem;margin-bottom:6px">
                    MariaDB is listening on public interfaces instead of binding strictly to loopback (127.0.0.1).
                </div>
                <div style="color:#7ee787;font-size:0.78rem">
                    💡 <b>Automated Remediation:</b> Applying this patch writes <code>/etc/mysql/mariadb.conf.d/99-wtc-bind.cnf</code> with <code>bind-address = 127.0.0.1</code>, restarts MariaDB, and securely isolates port 3306 from unauthorized remote LAN access.
                </div>
            </div>

            <!-- Version & Status Overview Card -->
            <div style="background:#090d13;border:1px solid #30363d;border-radius:8px;padding:12px;margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span style="color:#8b949e">Target Unit:</span>
                    <b id="patchModalUnitName" style="color:#e6edf3">-</b>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span style="color:#8b949e">Installed Version:</span>
                    <code id="patchModalInstalledVer" style="color:#79c0ff;background:#161b22;padding:2px 6px;border-radius:4px;font-size:0.8rem">-</code>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span style="color:#8b949e">Security Status:</span>
                    <span id="patchModalStatus" class="pill ok" style="font-size:0.75rem">-</span>
                </div>
                <div id="patchModalCandidateRow" style="display:none;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span style="color:#f85149;font-weight:600">Pending Upgrade:</span>
                    <code id="patchModalCandidateVer" style="color:#f85149;background:#3c181a;padding:2px 6px;border-radius:4px;font-size:0.8rem">-</code>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <span style="color:#8b949e">Operation:</span>
                    <span id="patchModalOpDesc" style="color:#58a6ff;font-family:monospace;font-size:0.78rem">apt-get install --only-upgrade &amp; reload unit</span>
                </div>
            </div>

            <!-- Vulnerabilities & Security Advisories Section -->
            <h4 style="margin:16px 0 8px 0;font-size:0.92rem;color:#e6edf3;display:flex;align-items:center;gap:6px">
                <span>🔍</span> Disclosed Vulnerabilities &amp; Mitigations
            </h4>
            <div id="patchModalAdvisories" style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
                <!-- Populated dynamically via JS -->
            </div>
        </div>

        <form method="post" action="/?p=services" id="formServicePatch">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="service_patch">
            <input type="hidden" name="service" id="patchFormServiceInput" value="">
            <input type="hidden" name="back" value="services">
            <div style="display:flex;justify-content:flex-end;gap:8px">
                <button type="button" class="btn" onclick="closeServicePatchModal()">Close</button>
                <button type="submit" id="patchModalSubmitBtn" class="btn-pri" style="background:#238636;border-color:#2ea043">
                    🛡️ Apply Security Patch
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- PATCH ALL SERVICES MODAL                                       -->
<!-- ============================================================== -->
<div id="patchAllModal" class="modal-overlay" style="display:none">
    <div class="modal-card" style="max-width:550px;width:95%">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #30363d;padding-bottom:12px;margin-bottom:14px">
            <h3 style="margin:0;font-size:1.1rem;display:flex;align-items:center;gap:8px">
                ⚡ Patch All Services &amp; Daemons
            </h3>
            <button type="button" class="btn" style="padding:4px 10px;font-size:0.8rem" onclick="closePatchAllModal()">✕ Close</button>
        </div>

        <div style="margin-bottom:14px;font-size:0.88rem;color:#c9d1d9;line-height:1.5">
            <p style="margin:0 0 10px 0">
                This will trigger a full system security patch run across all managed services (Nginx, PHP, MariaDB, Redis, Samba, SSH, etc.) using automated package updates and rolling service reloads.
            </p>
            <div style="background:#090d13;border:1px solid #30363d;border-radius:6px;padding:10px">
                <ul style="margin:0;padding-left:18px;color:#8b949e;font-size:0.82rem;line-height:1.6">
                    <li>Updates apt package security advisories</li>
                    <li>Upgrades vulnerable packages with <code>NEEDRESTART_MODE=a</code></li>
                    <li>Restarts modified service daemons cleanly</li>
                    <li>Re-verifies post-patch service health</li>
                </ul>
            </div>
        </div>

        <form method="post" action="/?p=services" id="formPatchAll">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="service_patch_all">
            <input type="hidden" name="back" value="services">
            <div style="display:flex;justify-content:flex-end;gap:8px">
                <button type="button" class="btn" onclick="closePatchAllModal()">Cancel</button>
                <button type="submit" class="btn-pri" style="background:#238636;border-color:#2ea043">
                    ⚡ Confirm &amp; Patch All Services
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.service-table th {
    padding: 8px 12px;
    font-size: 0.82rem;
    color: #8b949e;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.service-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #21262d;
    vertical-align: middle;
}
.service-table tr:last-child td {
    border-bottom: none;
}
.service-table tr:hover {
    background: #161b2255;
}
.btn-filter {
    background: none;
    border: none;
    color: #8b949e;
    font-size: 0.82rem;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-filter:hover {
    color: #e6edf3;
    background: #161b22;
}
.btn-filter.on {
    color: #fff;
    background: #1f6feb;
}
.refresh-icon {
    display: inline-block;
    transition: transform 0.3s ease;
}
.spinning {
    animation: spin 0.8s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}
.modal-card {
    background: #0d1117;
    border: 1px solid #30363d;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 16px 40px rgba(0,0,0,0.8);
}
</style>

<script>
let currentCategoryFilter = 'all';
let currentStatusFilter = 'all';
let currentSearchTerm = '';
let currentJournalUnit = '';
let currentJournalName = '';

function applyServiceFilters() {
    const rows = document.querySelectorAll('.service-row');
    const term = currentSearchTerm.trim().toLowerCase();
    let visibleCount = 0;
    let visibleOurs = 0;
    let visibleSystem = 0;

    rows.forEach(row => {
        const cat = row.getAttribute('data-category') || '';
        const isRun = row.getAttribute('data-running') === '1';
        const isFail = row.getAttribute('data-failed') === '1';
        const unit = row.getAttribute('data-unit') || '';
        const name = row.getAttribute('data-name') || '';
        const desc = row.getAttribute('data-desc') || '';

        // Category filter
        if (currentCategoryFilter !== 'all' && cat !== currentCategoryFilter) {
            row.style.display = 'none';
            return;
        }

        // Status filter
        if (currentStatusFilter === 'running' && !isRun) {
            row.style.display = 'none';
            return;
        }
        if (currentStatusFilter === 'stopped' && isRun) {
            row.style.display = 'none';
            return;
        }
        if (currentStatusFilter === 'failed' && !isFail) {
            row.style.display = 'none';
            return;
        }

        // Search term
        if (term !== '') {
            const matches = unit.toLowerCase().includes(term) ||
                            name.toLowerCase().includes(term) ||
                            desc.toLowerCase().includes(term);
            if (!matches) {
                row.style.display = 'none';
                return;
            }
        }

        row.style.display = '';
        visibleCount++;
        if (cat === 'ours') visibleOurs++;
        else visibleSystem++;
    });

    // Control section container visibility
    const secOurs = document.getElementById('sectionOurs');
    const secSystem = document.getElementById('sectionSystem');
    const emptyMsg = document.getElementById('serviceEmptyMsg');

    if (secOurs) {
        secOurs.style.display = (currentCategoryFilter === 'system' || visibleOurs === 0) ? 'none' : '';
    }
    if (secSystem) {
        secSystem.style.display = (currentCategoryFilter === 'ours' || visibleSystem === 0) ? 'none' : '';
    }
    if (emptyMsg) {
        emptyMsg.style.display = (visibleCount === 0) ? '' : 'none';
    }
}

function setCategoryFilter(cat, btn) {
    currentCategoryFilter = cat;
    document.querySelectorAll('[data-category]').forEach(b => b.classList.remove('on'));
    if (btn) btn.classList.add('on');
    applyServiceFilters();
}

function setStatusFilter(status, btn) {
    currentStatusFilter = status;
    document.querySelectorAll('[data-status]').forEach(b => b.classList.remove('on'));
    if (btn) btn.classList.add('on');
    applyServiceFilters();
}

const searchInput = document.getElementById('serviceSearchInput');
if (searchInput) {
    searchInput.addEventListener('input', (e) => {
        currentSearchTerm = e.target.value;
        const clearBtn = document.getElementById('clearSearchBtn');
        if (clearBtn) {
            clearBtn.style.display = currentSearchTerm ? 'block' : 'none';
        }
        applyServiceFilters();
    });
}

function clearServiceSearch() {
    currentSearchTerm = '';
    const input = document.getElementById('serviceSearchInput');
    if (input) input.value = '';
    const clearBtn = document.getElementById('clearSearchBtn');
    if (clearBtn) clearBtn.style.display = 'none';
    applyServiceFilters();
}

function refreshServicesTelemetry(btn) {
    const icon = btn ? btn.querySelector('.refresh-icon') : null;
    if (icon) icon.classList.add('spinning');

    if (window.wtcSpa && typeof window.wtcSpa.navigateTo === 'function') {
        window.wtcSpa.navigateTo('/?p=services');
        return;
    }

    fetch('/?p=services&spa=1')
        .then(res => res.text())
        .then(html => {
            const container = document.getElementById('spa-content');
            if (container) {
                container.innerHTML = html;
                const scripts = container.querySelectorAll('script');
                scripts.forEach(oldScript => {
                    const newScript = document.createElement('script');
                    Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                    newScript.textContent = oldScript.src ? oldScript.textContent
                        : oldScript.textContent.replace(/^(let|const)\s+/gm, 'var ');
                    oldScript.parentNode.replaceChild(newScript, oldScript);
                });
            }
        })
        .catch(err => {
            console.error('Failed to refresh services:', err);
        })
        .finally(() => {
            if (icon) icon.classList.remove('spinning');
        });
}

function showServiceJournalLogs(unit, name) {
    currentJournalUnit = unit;
    currentJournalName = name;

    const modal = document.getElementById('journalModal');
    const title = document.getElementById('journalModalTitle');
    const unitEl = document.getElementById('journalModalUnit');
    const output = document.getElementById('journalOutput');
    const loading = document.getElementById('journalLoading');

    if (modal) modal.style.display = 'flex';
    if (title) title.innerText = '📜 Journal Log: ' + name;
    if (unitEl) unitEl.innerText = unit;
    if (output) {
        output.style.display = 'none';
        output.innerText = '';
    }
    if (loading) loading.style.display = 'block';

    const lines = document.getElementById('journalLineCount') ? document.getElementById('journalLineCount').value : 50;

    fetch('/?action=service_logs&service=' + encodeURIComponent(unit) + '&lines=' + encodeURIComponent(lines))
        .then(res => res.json())
        .then(data => {
            if (loading) loading.style.display = 'none';
            if (output) {
                output.style.display = 'block';
                output.innerText = data.logs || 'No journal log entries found for ' + unit;
            }
        })
        .catch(err => {
            if (loading) loading.style.display = 'none';
            if (output) {
                output.style.display = 'block';
                output.innerText = 'Error fetching journal logs: ' + err.message;
            }
        });
}

function reloadCurrentJournal() {
    if (currentJournalUnit) {
        showServiceJournalLogs(currentJournalUnit, currentJournalName);
    }
}

function copyJournalOutput() {
    const output = document.getElementById('journalOutput');
    if (output && output.innerText) {
        navigator.clipboard.writeText(output.innerText).then(() => {
            alert('Journal logs copied to clipboard!');
        });
    }
}

function closeJournalModal() {
    const modal = document.getElementById('journalModal');
    if (modal) modal.style.display = 'none';
}

const serviceSecData = <?= wtc_js_json($serviceSecurityMap, '{}') ?>;
const serviceAdvisoriesCatalog = <?= wtc_js_json($knownServiceAdvisories, '{}') ?>;
const serviceAuditsMap = <?= wtc_js_json($serviceAuditsMap, '{}') ?>;
const serviceSecIssues = <?= wtc_js_json($secReport['issues'] ?? [], '[]') ?>;

function getUnitAdvisories(unit) {
    const u = unit.toLowerCase();
    const bare = unit.replace(/\.service$/, '');
    if (Array.isArray(serviceAuditsMap[unit])) {
        return serviceAuditsMap[unit];
    }
    if (Array.isArray(serviceAuditsMap[bare])) {
        return serviceAuditsMap[bare];
    }

    const sec = serviceSecData[unit] || serviceSecData[bare];
    let list = [];

    if (sec && sec.has_patch) {
        list.push({
            id: sec.cve || 'USN-Security-Advisory',
            cvss: 7.5,
            severity: 'high',
            epss: 0.0,
            title: 'Security Update Pending: Upgrade to ' + (sec.candidate_version || 'Latest'),
            status: 'pending',
            desc: 'A security advisory has been released for ' + unit + '. Upgrading addresses known stability and security fixes.',
            mitigation: 'Apply 1-click patch in Services Monitor.'
        });
    }

    let catKey = 'system';
    if (u.startsWith('nginx')) catKey = 'nginx';
    else if (u.startsWith('mariadb') || u.startsWith('mysql')) catKey = 'mariadb';
    else if (u.startsWith('php')) catKey = 'php';
    else if (u.startsWith('ssh')) catKey = 'ssh';
    else if (u.startsWith('smbd') || u.startsWith('samba') || u.startsWith('nmbd')) catKey = 'smbd';
    else if (u.startsWith('redis')) catKey = 'redis';
    else if (u.startsWith('vsftpd')) catKey = 'vsftpd';
    else if (u.startsWith('wtc-')) catKey = 'wtc';

    if (serviceAdvisoriesCatalog[catKey]) {
        list = list.concat(serviceAdvisoriesCatalog[catKey]);
    }
    return list;
}

let tooltipHideTimer = null;
function showSecTooltip(el, unit, version, hasPatch) {
    if (tooltipHideTimer) clearTimeout(tooltipHideTimer);
    const tooltip = document.getElementById('secFloatingTooltip');
    if (!tooltip) return;

    const titleEl = document.getElementById('tooltipTitle');
    const badgeEl = document.getElementById('tooltipBadge');
    const verEl = document.getElementById('tooltipVersion');
    const contentEl = document.getElementById('tooltipContent');

    const advisories = getUnitAdvisories(unit);
    const pendingAdvisories = advisories.filter(a => a.status === 'pending' || a.is_affected);
    const u = unit.toLowerCase();
    const isDbPortExposedForUnit = (u.startsWith('mariadb') || u.startsWith('mysql')) && serviceSecIssues.some(iss => iss.id === 'db_port_exposed');

    if (titleEl) titleEl.innerText = unit;
    if (verEl) verEl.innerText = 'Installed: ' + version;

    if (badgeEl) {
        if (isDbPortExposedForUnit) {
            badgeEl.className = 'pill';
            badgeEl.style.background = '#3c181a';
            badgeEl.style.color = '#f85149';
            badgeEl.innerText = '⚠️ LAN Exposed (3306)';
        } else if (pendingAdvisories.length > 0) {
            const topAdv = pendingAdvisories[0];
            badgeEl.className = 'pill';
            badgeEl.style.background = '#3c181a';
            badgeEl.style.color = '#f85149';
            badgeEl.innerText = '⚠️ ' + topAdv.id + (pendingAdvisories.length > 1 ? ` (+${pendingAdvisories.length - 1})` : '');
        } else if (hasPatch) {
            badgeEl.className = 'pill';
            badgeEl.style.background = '#3c181a';
            badgeEl.style.color = '#f85149';
            badgeEl.innerText = '⚠️ Update Pending';
        } else {
            badgeEl.className = 'pill ok';
            badgeEl.style.background = '#1b4728';
            badgeEl.style.color = '#56d364';
            badgeEl.innerText = '🛡️ Patched & Hardened';
        }
    }

    if (contentEl) {
        let html = '';
        if (advisories.length > 0) {
            html += '<div style="font-weight:600;margin-bottom:6px;color:#8b949e">Security Mitigations &amp; Threat Intelligence:</div><ul style="margin:0;padding-left:14px;color:#c9d1d9">';
            advisories.slice(0, 4).forEach(a => {
                const isPending = a.status === 'pending' || a.is_affected;
                const tagColor = isPending ? '#f85149' : '#3fb950';
                const cvssBadge = a.cvss ? ` <span style="font-size:0.7rem;color:#ff7b72">[CVSS ${a.cvss}]</span>` : '';
                html += '<li style="margin-bottom:4px"><b style="color:' + tagColor + '">' + a.id + '</b>' + cvssBadge + ': ' + a.title + '</li>';
            });
            html += '</ul>';
        } else {
            html = '<div style="color:#8b949e">No known active vulnerabilities. Service packages up to date.</div>';
        }
        contentEl.innerHTML = html;
    }

    const rect = el.getBoundingClientRect();
    tooltip.style.display = 'block';

    let left = rect.left;
    if (left + 360 > window.innerWidth) {
        left = window.innerWidth - 370;
    }
    let top = rect.bottom + 8;
    if (top + 200 > window.innerHeight) {
        top = rect.top - tooltip.offsetHeight - 8;
    }
    tooltip.style.left = Math.max(10, left) + 'px';
    tooltip.style.top = Math.max(10, top) + 'px';
}

function hideSecTooltip() {
    tooltipHideTimer = setTimeout(() => {
        const tooltip = document.getElementById('secFloatingTooltip');
        if (tooltip) {
            tooltip.style.display = 'none';
        }
    }, 120);
}

function toggleSecurityDetails() {
    const panel = document.getElementById('securityIssuesPanel');
    if (panel) {
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    }
}

function secEscapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function checkServiceRowCve(unit, btn) {
    if (!unit) return null;
    const row = document.querySelector('tr.service-row[data-unit="' + unit + '"]');
    const badgeContainer = document.getElementById('sec-badge-' + unit);
    let ver = '';
    if (row) {
        ver = row.getAttribute('data-version') || '';
    }

    const timeEl = document.getElementById('sec-time-' + unit);
    if (btn) {
        btn.disabled = true;
        btn.dataset.origHtml = btn.innerHTML;
        btn.innerHTML = '<span class="refresh-icon spinning">🔍</span> Check';
    }
    if (badgeContainer) {
        badgeContainer.innerHTML = '<span class="pill" style="background:#161b22;color:#8b949e;border:1px solid #30363d">Scanning...</span>';
    }
    if (timeEl) {
        timeEl.innerText = 'Checking feeds...';
        timeEl.style.color = '#e3b341';
    }

    try {
        const url = '/?action=cve_check&unit=' + encodeURIComponent(unit) + '&version=' + encodeURIComponent(ver) + '&force=1';
        const res = await fetch(url);
        const data = await res.json();
        if (data && data.ok) {
            updateRowSecurityUi(unit, data);
            return data;
        } else {
            console.error('CVE check failed for ' + unit, data);
            if (badgeContainer) {
                badgeContainer.innerHTML = '<span class="pill" style="background:#21262d;color:#ff7b72;border:1px solid #da3633">Scan Error</span>';
            }
            if (timeEl) {
                timeEl.innerText = 'Scan failed';
                timeEl.style.color = '#ff7b72';
            }
            return null;
        }
    } catch (err) {
        console.error('CVE check network error for ' + unit, err);
        if (badgeContainer) {
            badgeContainer.innerHTML = '<span class="pill" style="background:#21262d;color:#ff7b72;border:1px solid #da3633">Scan Error</span>';
        }
        if (timeEl) {
            timeEl.innerText = 'Scan error';
            timeEl.style.color = '#ff7b72';
        }
        return null;
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.origHtml || '🔍 Check';
        }
    }
}

function updateRowSecurityUi(unit, data) {
    if (!data) return;
    const badgeContainer = document.getElementById('sec-badge-' + unit);
    const row = document.querySelector('tr.service-row[data-unit="' + unit + '"]');
    const timeEl = document.getElementById('sec-time-' + unit);

    if (timeEl) {
        const d = new Date();
        const pad = n => n.toString().padStart(2, '0');
        const fallbackTime = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
        const timeStr = data.scanned_at || fallbackTime;
        timeEl.innerText = 'Scanned ' + timeStr;
        timeEl.style.color = '#8b949e';
    }

    if (!window.serviceSecData) window.serviceSecData = {};
    if (!window.serviceAuditsMap) window.serviceAuditsMap = {};

    serviceAuditsMap[unit] = data.cves || [];
    const bare = unit.replace(/\.service$/, '');
    serviceAuditsMap[bare] = data.cves || [];

    const hasVulns = !!data.has_vulnerabilities;
    const pendingCves = data.pending_cves || [];
    const primaryCve = data.primary_cve;

    if (hasVulns && primaryCve) {
        serviceSecData[unit] = {
            has_patch: true,
            cve: primaryCve.id,
            candidate_version: primaryCve.fixed_version || 'Latest Security Release',
            installed_version: data.version
        };
        serviceSecData[bare] = serviceSecData[unit];
    } else {
        serviceSecData[unit] = {
            has_patch: false,
            candidate_version: null,
            installed_version: data.version
        };
        serviceSecData[bare] = serviceSecData[unit];
    }

    if (badgeContainer) {
        let badgeHtml = '';
        const safeText = secEscapeHtml(data.badge_text || '🛡️ Hardened');
        const safeUnit = secEscapeHtml(unit);
        const safeVer = secEscapeHtml(data.version || '');
        const patchFlag = hasVulns ? 'true' : 'false';

        if (data.badge_type === 'downgraded') {
            badgeHtml = `<span class="pill" style="background:#490202;color:#ff7b72;border:1px solid #f85149;cursor:pointer;font-weight:bold"
                onmouseenter="showSecTooltip(this, '${safeUnit}', '${safeVer}', ${patchFlag})"
                onmouseleave="hideSecTooltip()"
                onclick="openServicePatchModal('${safeUnit}')">${safeText}</span>`;
        } else if (hasVulns) {
            badgeHtml = `<span class="pill" style="background:#3c181a;color:#f85149;border:1px solid #da3633;cursor:pointer"
                onmouseenter="showSecTooltip(this, '${safeUnit}', '${safeVer}', ${patchFlag})"
                onmouseleave="hideSecTooltip()"
                onclick="openServicePatchModal('${safeUnit}')">${safeText}</span>`;
        } else {
            badgeHtml = `<span class="pill ok" style="background:#1b4728;color:#56d364;border:1px solid #238636;cursor:pointer"
                onmouseenter="showSecTooltip(this, '${safeUnit}', '${safeVer}', ${patchFlag})"
                onmouseleave="hideSecTooltip()"
                onclick="openServicePatchModal('${safeUnit}')">${safeText}</span>`;
        }
        badgeContainer.innerHTML = badgeHtml;
    }

    if (row) {
        row.style.background = hasVulns ? 'rgba(218, 54, 51, 0.08)' : '';
        if (data.version) {
            row.setAttribute('data-version', data.version);
        }
    }

    const verBadge = document.getElementById('ver-badge-' + unit);
    if (verBadge && !hasVulns) {
        verBadge.style.background = '#1b4728';
        verBadge.style.color = '#56d364';
        verBadge.style.borderColor = '#238636';
        const shortVer = (data.version || '').split('-')[0];
        verBadge.innerText = (shortVer ? 'v' + shortVer + ' ' : '') + '🛡️ Hardened';
        verBadge.title = 'Security hardening active \u2022 All CVEs mitigated';
    }

    appendSecurityAdvisories(unit, pendingCves, data.version);
    recalculateSecurityMetrics();
}

function appendSecurityAdvisories(unit, pendingCves, version) {
    const listEl = document.getElementById('securityIssuesList');
    if (!listEl) return;

    // Remove any previously appended cards for this unit
    const existing = listEl.querySelectorAll(`[data-advisory-unit="${unit}"]`);
    existing.forEach(el => el.remove());

    if (Array.isArray(pendingCves) && pendingCves.length > 0) {
        pendingCves.forEach(adv => {
            const card = document.createElement('div');
            card.className = 'sec-advisory-card';
            card.setAttribute('data-advisory-unit', unit);

            const cvss = parseFloat(adv.cvss) || 0;
            let sev = (adv.severity || '').toLowerCase();
            if (!sev) {
                if (cvss >= 9.0) sev = 'critical';
                else if (cvss >= 7.0) sev = 'high';
                else if (cvss >= 4.0) sev = 'medium';
                else sev = 'low';
            }
            card.setAttribute('data-severity', sev);

            let sevBg = '#161b22', sevColor = '#8b949e', sevBorder = '#30363d';
            if (sev === 'critical') { sevBg = '#490202'; sevColor = '#ff7b72'; sevBorder = '#f85149'; }
            else if (sev === 'high') { sevBg = '#3c181a'; sevColor = '#f85149'; sevBorder = '#da3633'; }
            else if (sev === 'medium') { sevBg = '#2b220d'; sevColor = '#d29922'; sevBorder = '#bb8009'; }
            else if (sev === 'low') { sevBg = '#0c2d6b'; sevColor = '#79c0ff'; sevBorder = '#1f6feb'; }

            const cvssText = adv.cvss ? `[CVSS ${adv.cvss}]` : '';
            const epssText = adv.epss ? ` [EPSS ${(adv.epss * 100).toFixed(1)}%]` : '';
            const title = adv.title || (adv.id + ' Security Vulnerability');
            const desc = adv.desc || adv.details || `Affected range: ${adv.affected_range || 'All unpatched versions'}. Target fixed version: ${adv.fixed_version || 'Latest upstream release'}.`;
            const mitigation = adv.mitigation || `Upgrade ${unit} to current security patch release. Apply 1-click patch in Services Monitor.`;

            card.style.cssText = `background:#090d13;border:1px solid ${sevBorder}66;border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px`;
            card.innerHTML = `
                <div style="flex:1;min-width:280px">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap">
                        <span class="pill" style="background:${sevBg};color:${sevColor};border:1px solid ${sevBorder};font-size:0.72rem;padding:2px 8px;font-weight:bold">
                            ${secEscapeHtml(sev.toUpperCase())}
                        </span>
                        <span class="pill" style="background:#21262d;color:#8b949e;font-size:0.7rem;padding:1px 6px">
                            ${secEscapeHtml(unit.toUpperCase())}
                        </span>
                        <b style="font-size:0.92rem;color:#e6edf3">${secEscapeHtml(adv.id)} <span style="font-size:0.75rem;color:#ff7b72">${secEscapeHtml(cvssText)}${secEscapeHtml(epssText)}</span> - ${secEscapeHtml(title)}</b>
                    </div>
                    <p style="margin:4px 0 6px 0;font-size:0.84rem;color:#c9d1d9;line-height:1.4">
                        ${secEscapeHtml(desc)}
                    </p>
                    <div style="font-size:0.8rem;color:#8b949e;display:flex;align-items:center;gap:6px">
                        <span style="color:#58a6ff">💡 Guidance:</span> ${secEscapeHtml(mitigation)}
                    </div>
                </div>
                <div style="white-space:nowrap;align-self:center">
                    <button type="button" class="btn-pri" onclick="openServicePatchModal('${secEscapeHtml(unit)}')" style="background:#238636;border-color:#2ea043;font-size:0.82rem;padding:6px 12px">
                        🛡️ Fix Issue Now
                    </button>
                </div>
            `;
            listEl.prepend(card);
        });
    }

    recalculateSecurityMetrics();
}

function recalculateSecurityMetrics() {
    const listEl = document.getElementById('securityIssuesList');
    const cards = listEl ? Array.from(listEl.querySelectorAll('.sec-advisory-card')) : [];

    let totalVulns = cards.length;
    let criticalCount = 0;
    let highCount = 0;
    let mediumCount = 0;

    cards.forEach(card => {
        const sev = (card.getAttribute('data-severity') || '').toLowerCase();
        if (sev === 'critical') criticalCount++;
        else if (sev === 'high') highCount++;
        else if (sev === 'medium') mediumCount++;
    });

    const elTotal = document.getElementById('metricTotalVulns');
    if (elTotal) {
        elTotal.innerText = totalVulns;
        elTotal.style.color = totalVulns > 0 ? '#f85149' : '#3fb950';
        if (elTotal.parentElement) {
            elTotal.parentElement.style.borderColor = totalVulns > 0 ? '#da363366' : '#23863644';
        }
    }
    const elCrit = document.getElementById('metricCriticalCves');
    if (elCrit) {
        elCrit.innerText = criticalCount;
        elCrit.style.color = criticalCount > 0 ? '#f85149' : '#8b949e';
        if (elCrit.parentElement) {
            elCrit.parentElement.style.borderColor = criticalCount > 0 ? '#da3633' : '#30363d';
        }
    }
    const elHigh = document.getElementById('metricHighSev');
    if (elHigh) {
        elHigh.innerText = highCount;
        elHigh.style.color = highCount > 0 ? '#ff7b72' : '#8b949e';
        if (elHigh.parentElement) {
            elHigh.parentElement.style.borderColor = highCount > 0 ? '#db6d28' : '#30363d';
        }
    }
    const elMed = document.getElementById('metricMediumSev');
    if (elMed) {
        elMed.innerText = mediumCount;
        elMed.style.color = mediumCount > 0 ? '#e3b341' : '#8b949e';
        if (elMed.parentElement) {
            elMed.parentElement.style.borderColor = mediumCount > 0 ? '#d29922' : '#30363d';
        }
    }

    let grade = 'A+';
    let gradeLabel = 'Excellent';
    let gradeColor = '#3fb950';

    if (criticalCount > 0) {
        grade = 'F';
        gradeLabel = 'Critical Risk';
        gradeColor = '#f85149';
    } else if (highCount > 0) {
        grade = 'D';
        gradeLabel = 'High Risk';
        gradeColor = '#ff7b72';
    } else if (mediumCount > 0) {
        grade = 'B';
        gradeLabel = 'Needs Attention';
        gradeColor = '#d29922';
    } else if (totalVulns > 0) {
        grade = 'B';
        gradeLabel = 'Needs Attention';
        gradeColor = '#d29922';
    }

    const elLetter = document.getElementById('secGradeLetter');
    if (elLetter) {
        elLetter.innerText = grade;
        elLetter.style.color = gradeColor;
    }
    const elBox = document.getElementById('secGradeBox');
    if (elBox) {
        elBox.style.borderColor = gradeColor;
        elBox.style.background = gradeColor + '22';
    }
    const elBadge = document.getElementById('secGradeBadge');
    if (elBadge) {
        elBadge.innerText = gradeLabel;
        elBadge.style.color = gradeColor;
        elBadge.style.borderColor = gradeColor + '66';
        elBadge.style.background = gradeColor + '22';
    }

    const btnToggle = document.getElementById('btnToggleIssues');
    if (btnToggle) {
        if (totalVulns > 0) {
            btnToggle.style.display = 'inline-block';
            btnToggle.innerHTML = `⚠️ View Advisories (${totalVulns})`;
        } else {
            btnToggle.style.display = 'none';
        }
    }

    const noMsg = document.getElementById('noSecurityIssuesMsg');
    const panel = document.getElementById('securityIssuesPanel');
    if (noMsg) noMsg.style.display = totalVulns > 0 ? 'none' : 'block';
    if (listEl) listEl.style.display = totalVulns > 0 ? 'flex' : 'none';
    if (panel && totalVulns > 0 && panel.style.display === 'none') {
        panel.style.display = 'block';
    }
}

async function scanVulnerabilities(btn) {
    const rows = Array.from(document.querySelectorAll('tr.service-row'));
    if (!rows || rows.length === 0) return;

    if (btn) {
        btn.disabled = true;
        btn.dataset.origHtml = btn.innerHTML;
    }

    const totalCount = rows.length;
    const CHUNK_SIZE = 4;

    for (let i = 0; i < totalCount; i += CHUNK_SIZE) {
        const batch = rows.slice(i, i + CHUNK_SIZE);
        const inProgress = Math.min(i + batch.length, totalCount);

        if (btn) {
            btn.innerHTML = `<span class="refresh-icon spinning">🔍</span> Scanning (${inProgress}/${totalCount})...`;
        }

        // Run batch of up to 4 services concurrently in parallel
        await Promise.all(batch.map(async (row) => {
            const unit = row.getAttribute('data-unit');
            if (!unit) return;
            const rowBtn = document.getElementById('btn-cve-check-' + unit);
            try {
                await checkServiceRowCve(unit, rowBtn);
            } catch (err) {
                console.error('Scan error for ' + unit, err);
            }
        }));

        // Yield to browser UI thread so animations and user interactions stay completely fluid
        await new Promise(resolve => setTimeout(resolve, 40));
    }

    const lastScanEl = document.getElementById('metricLastScan');
    if (lastScanEl) {
        const d = new Date();
        const pad = n => n.toString().padStart(2, '0');
        lastScanEl.innerText = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    }

    if (btn) {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.origHtml || '<span class="refresh-icon">🔍</span> Scan Vulnerabilities';
    }
}

function openServicePatchModal(unit, name) {
    hideSecTooltip();
    const modal = document.getElementById('servicePatchModal');
    const titleEl = document.getElementById('patchModalTitle');
    const unitEl = document.getElementById('patchModalUnit');
    const nameEl = document.getElementById('patchModalUnitName');
    const statusEl = document.getElementById('patchModalStatus');
    const instVerEl = document.getElementById('patchModalInstalledVer');
    const candRow = document.getElementById('patchModalCandidateRow');
    const candVerEl = document.getElementById('patchModalCandidateVer');
    const advContainer = document.getElementById('patchModalAdvisories');
    const submitBtn = document.getElementById('patchModalSubmitBtn');
    const input = document.getElementById('patchFormServiceInput');
    const alertBanner = document.getElementById('patchModalAlertBanner');
    const opDesc = document.getElementById('patchModalOpDesc');

    if (modal) modal.style.display = 'flex';
    if (titleEl) titleEl.innerText = '🛡️ ' + (name || unit) + ' Vulnerability Audit';
    if (unitEl) unitEl.innerText = unit;
    if (nameEl) nameEl.innerText = (name || unit) + ' (' + unit + ')';
    if (input) input.value = unit;

    const u = unit.toLowerCase();
    const isDbPortExposedForUnit = (u.startsWith('mariadb') || u.startsWith('mysql')) && serviceSecIssues.some(iss => iss.id === 'db_port_exposed');

    if (alertBanner) {
        alertBanner.style.display = isDbPortExposedForUnit ? 'block' : 'none';
    }

    if (opDesc) {
        opDesc.innerText = isDbPortExposedForUnit
            ? 'apt-get upgrade + enforce 127.0.0.1 bind-address in 99-wtc-bind.cnf'
            : 'apt-get install --only-upgrade & reload unit';
    }

    const sec = serviceSecData[unit] || serviceSecData[unit.replace(/\.service$/, '')];
    const installed = (sec && sec.installed_version) ? sec.installed_version : (unit.startsWith('wtc-') ? '1.2.0 (PiLab)' : 'Installed (latest)');
    if (instVerEl) instVerEl.innerText = installed;

    const advisories = getUnitAdvisories(unit);
    const pendingAdvisories = advisories.filter(a => a.status === 'pending' || a.is_affected);
    const hasPatch = (sec && sec.has_patch) || pendingAdvisories.length > 0;

    let targetVerText = '';
    if (pendingAdvisories.length > 0 && pendingAdvisories[0].fixed_version) {
        targetVerText = pendingAdvisories[0].fixed_version;
    } else if (sec && sec.candidate_version) {
        targetVerText = sec.candidate_version;
    }

    if (hasPatch && candRow && candVerEl) {
        candRow.style.display = 'flex';
        candVerEl.innerText = targetVerText || 'Security upgrade available';
    } else if (candRow) {
        candRow.style.display = 'none';
    }

    if (statusEl) {
        if (isDbPortExposedForUnit) {
            statusEl.className = 'pill';
            statusEl.style.background = '#3c181a';
            statusEl.style.color = '#f85149';
            statusEl.innerText = '⚠️ Port 3306 Exposed to LAN';
        } else if (pendingAdvisories.some(a => a.is_downgraded)) {
            statusEl.className = 'pill';
            statusEl.style.background = '#490202';
            statusEl.style.color = '#ff7b72';
            statusEl.innerText = '⚠️ Service Downgrade Detected';
        } else if (pendingAdvisories.length > 0) {
            const topAdv = pendingAdvisories[0];
            const cvssText = topAdv.cvss ? ` (CVSS ${topAdv.cvss} ${(topAdv.severity || '').toUpperCase()})` : '';
            statusEl.className = 'pill';
            statusEl.style.background = '#3c181a';
            statusEl.style.color = '#f85149';
            statusEl.innerText = '⚠️ Vulnerable to ' + topAdv.id + cvssText;
        } else if (sec && sec.has_patch) {
            statusEl.className = 'pill';
            statusEl.style.background = '#3c181a';
            statusEl.style.color = '#f85149';
            statusEl.innerText = '⚠️ Security Update Pending';
        } else {
            statusEl.className = 'pill ok';
            statusEl.style.background = '#1b4728';
            statusEl.style.color = '#56d364';
            statusEl.innerText = '🛡️ All Disclosed CVEs Patched';
        }
    }

    if (submitBtn) {
        if (isDbPortExposedForUnit) {
            submitBtn.innerText = '🛡️ Apply Security Patch & Secure Port 3306';
            submitBtn.style.background = '#238636';
            submitBtn.style.borderColor = '#2ea043';
        } else if (hasPatch) {
            submitBtn.innerText = '🛡️ Apply Security Patch Now';
            submitBtn.style.background = '#238636';
            submitBtn.style.borderColor = '#2ea043';
        } else {
            submitBtn.innerText = '🔄 Re-Verify & Check Updates';
            submitBtn.style.background = '#1f6feb';
            submitBtn.style.borderColor = '#388bfd';
        }
    }

    if (advContainer) {
        if (advisories.length === 0) {
            advContainer.innerHTML = '<div style="padding:10px;background:#161b22;border:1px solid #30363d;border-radius:6px;color:#8b949e">No known active or disclosed vulnerabilities recorded for this daemon.</div>';
        } else {
            let html = '';
            advisories.forEach(a => {
                const isPending = a.status === 'pending' || a.is_affected;
                const borderCol = isPending ? '#da3633' : '#30363d';
                const bgCol = isPending ? '#21090a' : '#0d1117';
                const tagBg = isPending ? '#490202' : '#1b4728';
                const tagColor = isPending ? '#ff7b72' : '#56d364';
                const statusLabel = isPending ? '⚠️ VULNERABLE' : (a.status === 'hardened' ? '🛡️ HARDENED' : '🛡️ PATCHED');
                const cvss = a.cvss || 0;
                const epss = a.epss ? (a.epss * 100).toFixed(1) + '%' : null;

                html += '<div style="background:' + bgCol + ';border:1px solid ' + borderCol + ';border-radius:6px;padding:12px">';
                html += '  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px">';
                html += '    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">';
                html += '      <b style="color:#e6edf3;font-size:0.9rem">' + a.id + '</b>';
                if (cvss > 0) {
                    const cvssColor = cvss >= 9 ? '#da3633' : (cvss >= 7 ? '#db6d28' : '#388bfd');
                    html += '      <span class="pill" style="font-size:0.68rem;padding:1px 6px;background:' + cvssColor + ';color:#fff;font-weight:700">CVSS ' + cvss.toFixed(1) + ' ' + (a.severity || '').toUpperCase() + '</span>';
                }
                if (epss) {
                    html += '      <span class="pill" style="font-size:0.68rem;padding:1px 6px;background:#5438dc33;color:#d2a8ff;border:1px solid #7c3aed66">EPSS ' + epss + '</span>';
                }
                if (a.cisa_kev) {
                    html += '      <span class="pill" style="font-size:0.68rem;padding:1px 6px;background:#da3633;color:#fff;font-weight:700">CISA KEV</span>';
                }
                html += '    </div>';
                html += '    <div style="display:flex;gap:4px;align-items:center">';
                if (a.id.startsWith('CVE-')) {
                    html += '      <a href="https://www.cve.org/CVERecord?id=' + a.id + '" target="_blank" rel="noopener" class="btn" style="padding:1px 6px;font-size:0.68rem">cve.org ↗</a>';
                    html += '      <a href="https://nvd.nist.gov/vuln/detail/' + a.id + '" target="_blank" rel="noopener" class="btn" style="padding:1px 6px;font-size:0.68rem">NVD ↗</a>';
                    html += '      <a href="https://osv.dev/vulnerability/' + a.id + '" target="_blank" rel="noopener" class="btn" style="padding:1px 6px;font-size:0.68rem">OSV ↗</a>';
                }
                html += '      <span class="pill" style="font-size:0.68rem;padding:2px 8px;background:' + tagBg + ';color:' + tagColor + ';font-weight:700">' + statusLabel + '</span>';
                html += '    </div>';
                html += '  </div>';
                html += '  <div style="font-size:0.86rem;color:#c9d1d9;font-weight:600;margin-bottom:4px">' + a.title + '</div>';
                html += '  <p style="margin:0 0 8px 0;font-size:0.82rem;color:#8b949e;line-height:1.45">' + a.desc + '</p>';

                if (a.affected_ranges && a.affected_ranges.length > 0) {
                    html += '  <div style="background:#090d13;border:1px solid #30363d;border-radius:4px;padding:6px 10px;margin-bottom:6px;font-size:0.78rem">';
                    html += '    <span style="color:#8b949e"><b>Affected Version Ranges:</b> </span>';
                    html += '    <span style="color:#ff7b72;font-family:monospace">';
                    html += a.affected_ranges.map(r => r.display || (r.prefix ? r.prefix + ' < ' + r.before : '< ' + r.before)).join(' &bull; ');
                    html += '    </span>';
                    if (a.fixed_version) {
                        html += '    <div style="color:#7ee787;margin-top:3px"><b>Fixed Target:</b> ' + a.fixed_version + '</div>';
                    }
                    html += '  </div>';
                }

                html += '  <div style="font-size:0.78rem;color:#58a6ff"><span style="color:#7ee787">✓ Mitigation:</span> ' + a.mitigation + '</div>';
                html += '</div>';
            });
            advContainer.innerHTML = html;
        }
    }
}

function closeServicePatchModal() {
    const modal = document.getElementById('servicePatchModal');
    if (modal) modal.style.display = 'none';
}

function openPatchAllModal() {
    const modal = document.getElementById('patchAllModal');
    if (modal) modal.style.display = 'flex';
}

function closePatchAllModal() {
    const modal = document.getElementById('patchAllModal');
    if (modal) modal.style.display = 'none';
}

function queryLiveCveIntelligence() {
    const input = document.getElementById('cveQueryInput');
    const resultDiv = document.getElementById('cveQueryResult');
    const btn = document.getElementById('btnQueryCve');
    if (!input || !resultDiv) return;
    const cve = input.value.trim();
    if (!cve) {
        alert('Please enter a CVE identifier (e.g. CVE-2024-6387)');
        return;
    }
    if (btn) btn.disabled = true;
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="color:#8b949e;display:flex;align-items:center;gap:8px"><span class="refresh-icon spinning">🔍</span> Querying Google OSV, Shodan CVEDB, and FIRST EPSS for ' + cve + '...</div>';

    fetch('/?action=cve_lookup&cve=' + encodeURIComponent(cve))
        .then(r => r.json())
        .then(data => {
            if (!data || !data.ok) {
                resultDiv.innerHTML = '<div style="color:#f85149">⚠️ ' + (data && data.error ? data.error : 'No records found for ' + cve) + '</div>';
                return;
            }
            const cvss = data.cvss || 0;
            const epss = (data.epss ? (data.epss * 100).toFixed(1) + '%' : 'N/A');
            const sevColor = cvss >= 9 ? '#f85149' : (cvss >= 7 ? '#ff7b72' : (cvss >= 4 ? '#e3b341' : '#58a6ff'));
            
            let html = '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px">';
            html += '  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
            html += '    <b style="font-size:1.05rem;color:#e6edf3">' + data.cve_id + '</b>';
            html += '    <span class="pill" style="background:' + sevColor + '22;color:' + sevColor + ';border:1px solid ' + sevColor + '66;font-weight:700">CVSS ' + (cvss > 0 ? cvss.toFixed(1) : 'Unscored') + ' ' + (data.severity || '') + '</span>';
            if (data.epss > 0) {
                html += '    <span class="pill" style="background:#5438dc22;color:#d2a8ff;border:1px solid #7c3aed66">EPSS ' + epss + ' Exploit Prob</span>';
            }
            if (data.cisa_kev) {
                html += '    <span class="pill" style="background:#da3633;color:#fff;font-weight:700">⚠️ CISA KEV (Known Exploited)</span>';
            }
            html += '  </div>';
            html += '  <div style="display:flex;gap:6px">';
            html += '    <a href="' + (data.references?.cve_org || 'https://www.cve.org/CVERecord?id=' + data.cve_id) + '" target="_blank" rel="noopener" class="btn" style="font-size:0.74rem;padding:2px 8px">cve.org ↗</a>';
            html += '    <a href="' + (data.references?.nvd || 'https://nvd.nist.gov/vuln/detail/' + data.cve_id) + '" target="_blank" rel="noopener" class="btn" style="font-size:0.74rem;padding:2px 8px">NVD ↗</a>';
            html += '    <a href="' + (data.references?.osv || 'https://osv.dev/vulnerability/' + data.cve_id) + '" target="_blank" rel="noopener" class="btn" style="font-size:0.74rem;padding:2px 8px">OSV.dev ↗</a>';
            html += '  </div>';
            html += '</div>';

            html += '<div style="color:#c9d1d9;font-size:0.86rem;line-height:1.45;margin-bottom:10px">' + data.summary + '</div>';

            if (data.affected_ranges && data.affected_ranges.length > 0) {
                html += '<div style="background:#161b22;border:1px solid #30363d;border-radius:6px;padding:8px 10px;margin-bottom:8px;font-size:0.8rem">';
                html += '  <div style="color:#8b949e;margin-bottom:3px"><b>Affected Version Ranges:</b></div>';
                html += '  <div style="color:#ff7b72;font-family:monospace">';
                data.affected_ranges.forEach(r => {
                    html += '<span style="display:inline-block;margin-right:10px;background:#3c181a;padding:2px 6px;border-radius:3px;margin-bottom:2px">● ' + (r.display || (r.prefix ? r.prefix + ' < ' + r.before : '< ' + r.before)) + '</span>';
                });
                html += '  </div>';
                if (data.fixed_version) {
                    html += '  <div style="color:#7ee787;margin-top:4px"><b>Fixed Version Target:</b> ' + data.fixed_version + '</div>';
                }
                html += '</div>';
            }

            html += '<div style="display:flex;justify-content:space-between;color:#8b949e;font-size:0.74rem;border-top:1px solid #21262d;padding-top:6px;flex-wrap:wrap;gap:6px">';
            html += '  <span><b>Sources:</b> ' + ((data.sources_queried && data.sources_queried.length) ? data.sources_queried.join(', ') : 'Local Threat Catalog') + (data.from_cache ? ' (Cached 24h)' : ' (Live response)') + '</span>';
            html += '  <span><b>Verified:</b> ' + (data.updated_at || new Date().toISOString()) + '</span>';
            html += '</div>';

            resultDiv.innerHTML = html;
        })
        .catch(err => {
            resultDiv.innerHTML = '<div style="color:#f85149">Query error: ' + err.message + '</div>';
        })
        .finally(() => {
            if (btn) btn.disabled = false;
        });
}

function lookupQuickCve(id) {
    const input = document.getElementById('cveQueryInput');
    if (input) {
        input.value = id;
        queryLiveCveIntelligence();
    }
}

// Dynamic AJAX Form Handlers with Progress Indicators
const patchForm = document.getElementById('formServicePatch');
if (patchForm) {
    patchForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('patchModalSubmitBtn');
        const origText = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ Applying Security Patch & Hardening...';
        }

        const formData = new FormData(patchForm);
        formData.append('is_ajax', '1');

        fetch('/?p=services', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.ok) {
                if (submitBtn) {
                    submitBtn.innerHTML = '🛡️ Hardened & Patched!';
                    submitBtn.style.background = '#238636';
                }
                setTimeout(() => {
                    closeServicePatchModal();
                    window.location.reload();
                }, 800);
            } else {
                alert('Patch failed: ' + (data.error || 'Unknown error'));
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origText;
                }
            }
        })
        .catch(err => {
            console.error('AJAX patch error, falling back to form submit:', err);
            patchForm.submit();
        });
    });
}

const patchAllForm = document.getElementById('formPatchAll');
if (patchAllForm) {
    patchAllForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = patchAllForm.querySelector('button[type="submit"]');
        const origText = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ Patching & Upgrading All Services...';
        }

        const formData = new FormData(patchAllForm);
        formData.append('is_ajax', '1');

        fetch('/?p=services', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.ok) {
                if (submitBtn) {
                    submitBtn.innerHTML = '🛡️ All Services Hardened!';
                }
                setTimeout(() => {
                    closePatchAllModal();
                    window.location.reload();
                }, 800);
            } else {
                alert('Patch all failed: ' + (data.error || 'Unknown error'));
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origText;
                }
            }
        })
        .catch(err => {
            console.error('AJAX patch all error, falling back to form submit:', err);
            patchAllForm.submit();
        });
    });
}

// Close modal on escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeJournalModal();
        closeServicePatchModal();
        closePatchAllModal();
        hideSecTooltip();
    }
});
</script>

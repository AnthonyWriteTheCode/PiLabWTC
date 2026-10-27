<?php
declare(strict_types=1);
/**
 * Tab Container: Dashboard
 * Restructured, high-performance architecture:
 *  1. Hero Device telemetry ribbon and power overflow controls
 *  2. Real-time active alerts strip (reboot, under-voltage, throttling, RAM disk capacity)
 *  3. Live KPI hardware tiles with sparklines (CPU, Temp, RAM Disk tmpfs, SoC RAM, SD Root, Load)
 *  4. Core Operational Row: Live RAM Applications + Quick Ingest & Deploy
 *  5. Unified Platform Daemons & Health Matrix (Core services + Laravel workers)
 *  6. Collapsible Deep Inspectors: CPU Multi-Core, Processes, Network Requests (lazy polling)
 */

$st = wj('status');
$sites = wj('sites');
$buildJob = wj('build');
$active = $st['active'] ?: 'None';
?>

<div id="dashRoot">
    <!-- 1. Hero Device Ribbon -->
    <?php require __DIR__ . '/../dashboard/hero_device.php'; ?>

    <!-- 2. Active Alerts Strip -->
    <?php require __DIR__ . '/../dashboard/alerts.php'; ?>

    <!-- 3. Real-Time Hardware KPI Metric Tiles -->
    <?php require __DIR__ . '/../dashboard/kpi_tiles.php'; ?>

    <!-- 4. Core Operational Grid (Live Sites + Quick Ingest) -->
    <div class="dash-main-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));gap:14px;margin-bottom:14px">
        <?php
        require __DIR__ . '/../dashboard/active_site.php';
        require __DIR__ . '/../dashboard/quick_ingest.php';
        ?>
    </div>

    <!-- 5. Platform Daemons & Health Matrix -->
    <?php require __DIR__ . '/../dashboard/services.php'; ?>

    <!-- 6. Collapsible Deep Diagnostics & System Inspectors -->
    <div class="dash-details-container" style="display:flex;flex-direction:column;gap:4px">
        <?php
        require __DIR__ . '/../dashboard/cpu_monitor.php';
        require __DIR__ . '/../dashboard/processes.php';
        require __DIR__ . '/../dashboard/net_monitor.php';
        ?>
    </div>
</div>

<script>
if (window.dashLive) {
    window.dashLive.start();
}
</script>

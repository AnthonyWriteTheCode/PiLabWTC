<?php
declare(strict_types=1);
/**
 * Tab Container: Dashboard
 * Component-based architecture orchestrating modular subcomponents:
 *  - Hero Device telemetry and power controls
 *  - CPU Multi-Core Performance and live sparkline charts
 *  - RAM disk, physical system memory, and SD card storage metrics
 *  - Top running system and Pi application processes monitor
 *  - Active application switcher and web links
 *  - Background workers (Laravel queue & schedule runner)
 *  - Core system services table and quick file ingest
 */

$st = wj('status');
$sites = wj('sites');
$buildJob = wj('build');
$active = $st['active'] ?: 'None';

// Load Component Sub-views
require __DIR__ . '/../dashboard/hero_device.php';
require __DIR__ . '/../dashboard/net_monitor.php';
require __DIR__ . '/../dashboard/cpu_monitor.php';
require __DIR__ . '/../dashboard/memory_storage.php';
require __DIR__ . '/../dashboard/processes.php';
require __DIR__ . '/../dashboard/active_site.php';
require __DIR__ . '/../dashboard/workers.php';
?>

<!-- System Services & Ingest Grid -->
<div class="grid2">
    <?php
    require __DIR__ . '/../dashboard/services.php';
    require __DIR__ . '/../dashboard/quick_ingest.php';
    ?>
</div>

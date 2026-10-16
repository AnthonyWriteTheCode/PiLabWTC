<?php
declare(strict_types=1);
/**
 * Dashboard Component: Memory & Storage
 * High-speed RAM disk (/mnt/ramdisk), Physical SoC Memory, and SD card root storage.
 *
 * Variables passed in:
 * @var array $st Device status array
 */

$ram_pct = ($st['ramSize'] ?? 0) > 0 ? round(100 * ($st['ramUsed'] ?? 0) / $st['ramSize']) : 0;
$sys_pct = ($st['sysMemTotal'] ?? 0) > 0 ? round(100 * ($st['sysMemUsed'] ?? 0) / $st['sysMemTotal']) : 0;
$disk_pct = ($st['diskSize'] ?? 0) > 0 ? round(100 * ($st['diskUsed'] ?? 0) / $st['diskSize']) : 0;
?>
<div class="grid3">
    <div class="card">
        <h3>⚡ RAM Disk (/mnt/ramdisk)</h3>
        <div class="bar"><i style="width:<?= $ram_pct ?>%"></i></div>
        <p class="stat"><?= fmt_bytes($st['ramUsed'] ?? 0) ?> / <?= fmt_bytes($st['ramSize'] ?? 0) ?> (<?= $ram_pct ?>%)</p>
        <small>High-speed volatile tmpfs. Zero SD wear.</small>
    </div>

    <div class="card">
        <h3>🧠 System Memory</h3>
        <div class="bar"><i style="width:<?= $sys_pct ?>%"></i></div>
        <p class="stat"><?= fmt_bytes($st['sysMemUsed'] ?? 0) ?> / <?= fmt_bytes($st['sysMemTotal'] ?? 0) ?> (<?= $sys_pct ?>%)</p>
        <small>Physical SoC RAM usage.</small>
    </div>

    <div class="card">
        <h3>💾 SD Card Storage (/)</h3>
        <div class="bar"><i style="width:<?= $disk_pct ?>%"></i></div>
        <p class="stat"><?= fmt_bytes($st['diskUsed'] ?? 0) ?> / <?= fmt_bytes($st['diskSize'] ?? 0) ?> (<?= $disk_pct ?>%)</p>
        <small>Persistent root filesystem.</small>
    </div>
</div>

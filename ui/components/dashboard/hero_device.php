<?php
declare(strict_types=1);
/**
 * Dashboard Component: Hero Device
 * Device telemetry (Host, Model, Temp, Uptime, IP) and quick device action buttons.
 *
 * Variables passed in:
 * @var array $st Device status array from wj('status')
 */
?>
<div class="hero-device">
    <div class="device-info">
        <h2><?= h($st['model'] ?? 'Raspberry Pi') ?> <span class="badge"><?= h($st['host'] ?? 'pi') ?></span></h2>
        <div class="telemetry">
            <span>🏷️ Host: <a href="http://<?= htmlspecialchars($st['host'] ?? 'pi') ?>.local/" target="_blank" style="color:#58a6ff;text-decoration:none"><b><?= h($st['host'] ?? 'pi') ?>.local</b></a></span>
            <span>🌡️ Temp: <b id="telemetry-temp"><?= h($st['temp'] ?? 'N/A') ?></b></span>
            <span>⏱️ Uptime: <b><?= h($st['uptime'] ?? 'N/A') ?></b></span>
            <span>🌐 IP: <b><?= h($st['ips'] ?? 'N/A') ?></b></span>
            <span>PHP: <b><?= PHP_VERSION ?></b></span>
        </div>
    </div>
    <div class="quick-device-controls">
        <?= form('device', ['do' => 'restart-web'], '🔄 Restart Web Services', 'btn-ctrl', 'Restart Nginx and PHP-FPM?') ?>
        <?= form('device', ['do' => 'restart-db'], '🗄️ Restart MariaDB', 'btn-ctrl', 'Restart MariaDB?') ?>
        <?= form('save_state', ['back' => 'dashboard'], '💾 Save RAM State', 'btn-ctrl') ?>
        <?= form('device', ['do' => 'reboot'], '🔁 Reboot Pi', 'btn-ctrl danger', 'Are you sure you want to REBOOT the Raspberry Pi?') ?>
        <?= form('device', ['do' => 'shutdown'], '🛑 Shutdown Pi', 'btn-ctrl danger', 'Are you sure you want to SHUT DOWN the Raspberry Pi?') ?>
    </div>
</div>

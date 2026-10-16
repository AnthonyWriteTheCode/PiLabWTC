<?php
declare(strict_types=1);
/**
 * Dashboard Component: Services Table
 * System daemons status monitor (Nginx, PHP-FPM, MariaDB, Samba, SSH).
 *
 * Variables passed in:
 * @var array $st Device status array
 */
?>
<div class="card">
    <h3>⚙️ System Services</h3>
    <table class="simple-table">
        <tr><td>Nginx Web Server</td><td><?= onoff(($st['nginx'] ?? '') === 'yes') ?></td></tr>
        <tr><td>PHP-FPM Engine</td><td><?= onoff(($st['phpfpm'] ?? '') === 'yes') ?></td></tr>
        <tr><td>MariaDB Database</td><td><?= onoff(($st['mariadb'] ?? '') === 'yes') ?></td></tr>
        <tr><td>Samba File Share</td><td><?= onoff(($st['samba'] ?? '') === 'yes') ?></td></tr>
        <tr><td>SSH / SFTP Server</td><td><?= onoff(($st['ssh'] ?? '') === 'yes') ?></td></tr>
    </table>
</div>

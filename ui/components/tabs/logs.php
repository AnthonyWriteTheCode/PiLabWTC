<?php
declare(strict_types=1);
/**
 * Tab Container: Logs
 * Displays live system activity log from wtc-manage.
 */
?>

<div class="card">
    <h3>Live System Activity Log</h3>
    <pre><?= h(wtc(['--logs', '100'])[1]) ?></pre>
</div>

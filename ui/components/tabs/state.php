<?php
declare(strict_types=1);
/**
 * Tab Container: Save State
 * Snapshot active RAM site to persistent SD storage, manage persistence triggers and cron timer.
 */

$st = wj('status');
$set = wj('settings');
?>

<div class="card">
    <h3>Save Live RAM State to SD Storage</h3>
    <p>Currently Active Site: <b><?= h($st['active'] ?: 'None') ?></b></p>
    <p>Applications in RAM run without touching the SD card. When you click <b>Snapshot &amp; Save State Now</b>, WTC snapshots your entire runtime state—including uploaded assets, disk changes, and a full <b>MariaDB database dump</b> (from RAM to <code>.wtc-database.sql</code>)—directly back into the persistent SD archive (<code><?= h($st['active'] ?: 'site') ?>.tar.gz</code>).</p>
    <?= form('save_state', ['back' => 'state'], '💾 Snapshot Files & Database to SD Now', 'btn-pri') ?>
</div>

<div class="card">
    <h3>Automatic Persistence Settings</h3>
    <?php
    $toggles = [
        'SAVE_ON_SHUTDOWN' => 'Auto-save live state on shutdown / reboot',
        'SAVE_ON_SWITCH'   => 'Auto-save state before swapping applications'
    ];
    foreach ($toggles as $k => $label):
        $on = ($set[$k] ?? 'no') === 'yes';
    ?>
        <p>
            <?= h($label) ?>:
            <?= $on ? '<span class="pill ok">Enabled</span>' : '<span class="pill off">Disabled</span>' ?>
            <?= form('setting', ['key' => $k, 'value' => $on ? 'no' : 'yes', 'back' => 'state'], $on ? 'Disable' : 'Enable') ?>
        </p>
    <?php endforeach; ?>

    <form method="post">
        <p>
            Scheduled periodic save interval:
            <input type="number" name="value" min="0" max="9999" value="<?= h($set['SAVE_INTERVAL_MIN'] ?? '0') ?>" style="width:5em"> minutes (0 = disabled)
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="setting">
            <input type="hidden" name="key" value="SAVE_INTERVAL_MIN">
            <input type="hidden" name="back" value="state">
            <button>Save Setting</button>
        </p>
    </form>
</div>

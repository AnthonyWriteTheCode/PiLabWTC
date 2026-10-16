<?php
declare(strict_types=1);
/**
 * Tab Container: Settings
 * Configure RAM disk sizing, upload limits, auto-activation, and hot-reloading.
 */

$set = wj('settings');
?>

<div class="card">
    <h3>Server & Memory Limits</h3>
    <?php
    $inputs = [
        'RAM_SIZE'    => 'RAM Disk Size (e.g. 512M, 1G)',
        'DROP_MAX_MB' => 'Max extracted archive size (MB)'
    ];
    foreach ($inputs as $k => $l):
    ?>
        <form method="post">
            <p>
                <label><?= h($l) ?>: <input name="value" value="<?= h($set[$k] ?? '') ?>" style="width:7em"></label>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="setting">
                <input type="hidden" name="key" value="<?= $k ?>">
                <input type="hidden" name="back" value="settings">
                <button>Apply</button>
            </p>
        </form>
    <?php endforeach; ?>

    <?php
    $toggles = [
        'AUTO_ACTIVATE_NEW'  => 'Automatically activate newly uploaded sites',
        'AUTO_RELOAD_ACTIVE' => 'Hot-reload when an updated active site is uploaded'
    ];
    foreach ($toggles as $k => $l):
        $on = ($set[$k] ?? 'no') === 'yes';
    ?>
        <p>
            <?= h($l) ?>:
            <?= $on ? '<span class="pill ok">Yes</span>' : '<span class="pill off">No</span>' ?>
            <?= form('setting', ['key' => $k, 'value' => $on ? 'no' : 'yes', 'back' => 'settings'], $on ? 'Disable' : 'Enable') ?>
        </p>
    <?php endforeach; ?>
</div>

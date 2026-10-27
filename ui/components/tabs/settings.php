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

    <h3 style="margin-top:20px">Automation & Hot-Reload</h3>
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

<div class="card" style="margin-top:16px;border-color:#1f6feb">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <h3 style="margin:0;color:#58a6ff">🌀 Network &amp; Wi-Fi Disconnect Fan Alert</h3>
        <form method="post" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="fan_test">
            <input type="hidden" name="duration" value="5">
            <button type="submit" class="btn" style="background:#238636;color:#fff;padding:6px 14px;font-size:0.85rem">⚡ Test Fan (5s)</button>
        </form>
    </div>
    <p style="color:#8b949e;font-size:0.85rem;margin:8px 0 16px 0">
        Provides physical/audible feedback when the Pi loses network connection or is hunting to associate with Wi-Fi.
    </p>

    <?php $fanAlertOn = ($set['FAN_ON_WIFI_DISCONNECT'] ?? 'no') === 'yes'; ?>
    <p>
        <b>Fan Alert when Disconnected / Connecting:</b>
        <?= $fanAlertOn ? '<span class="pill ok">Enabled</span>' : '<span class="pill off">Disabled</span>' ?>
        <?= form('setting', ['key' => 'FAN_ON_WIFI_DISCONNECT', 'value' => $fanAlertOn ? 'no' : 'yes', 'back' => 'settings'], $fanAlertOn ? 'Disable Alert' : 'Enable Alert') ?>
    </p>

    <form method="post" style="margin-top:10px">
        <p>
            <label><b>Alert Pattern:</b>
                <select name="value" style="background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:4px 8px;border-radius:4px">
                    <option value="pulse" <?= ($set['FAN_WIFI_MODE'] ?? 'pulse') === 'pulse' ? 'selected' : '' ?>>Pulse (Heartbeat while connecting)</option>
                    <option value="spin" <?= ($set['FAN_WIFI_MODE'] ?? '') === 'spin' ? 'selected' : '' ?>>Spin (Continuous full speed)</option>
                </select>
            </label>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="setting">
            <input type="hidden" name="key" value="FAN_WIFI_MODE">
            <input type="hidden" name="back" value="settings">
            <button>Save Pattern</button>
        </p>
    </form>

    <form method="post" style="margin-top:10px">
        <p>
            <label><b>Fan Control GPIO Pin:</b>
                <input name="value" value="<?= h($set['FAN_GPIO'] ?? '14') ?>" style="width:5em;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:4px 8px;border-radius:4px" placeholder="14">
            </label>
            <span style="font-size:0.8rem;color:#8b949e">(Default 14. Supports single pin or comma-separated list like 14,18. Also auto-drives official active coolers and HWMon PWM)</span>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="setting">
            <input type="hidden" name="key" value="FAN_GPIO">
            <input type="hidden" name="back" value="settings">
            <button>Save Pin</button>
        </p>
    </form>
</div>

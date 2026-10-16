<?php
declare(strict_types=1);
/**
 * Tab Container: Stack Components
 * Manage pre-configured PHP packages, web extensions, view apt job logs,
 * and view application framework profiles (Laravel, WordPress, Node.js, etc.).
 */

require_once __DIR__ . '/../stacks.php';

$stack = wj('stack');
$profiles = get_stack_profiles();
?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h3 style="margin:0;border:0;padding:0">PHP Stack &amp; Server Extensions</h3>
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rescan_stack">
            <input type="hidden" name="back" value="stack">
            <button class="btn" title="Refresh installed package status from dpkg">🔄 Refresh Package Status</button>
        </form>
    </div>
    <p>Manage pre-configured stack bundles and services. All packages install via apt in the background.</p>

    <?php if (!empty($stack['busy'])): ?>
        <div class="flash">A package installation job is currently running. Auto-refreshing...</div>
    <?php endif; ?>

    <table>
        <tr>
            <th>Component</th>
            <th>Package Contents</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($stack['components'] ?? [] as $c): ?>
            <tr>
                <td><b><?= h($c['label']) ?></b></td>
                <td><code><?= h($c['packages']) ?></code></td>
                <td><?= $c['installed'] ? '<span class="pill ok">Installed</span>' : '<span class="pill off">Not installed</span>' ?></td>
                <td class="act">
                    <?php if (empty($stack['busy'])): ?>
                        <?php if ($c['installed']): ?>
                            <?= form('stack', ['do' => 'remove', 'id' => $c['id']], 'Remove', 'danger', 'Remove ' . $c['label'] . '?') ?>
                        <?php else: ?>
                            <?= form('stack', ['do' => 'install', 'id' => $c['id']], 'Install', 'btn-pri') ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h3>🎯 Application Framework &amp; Stack Profiles</h3>
    <p>Framework profiles detected by the drag &amp; drop uploader. Supported profiles include automatic dependency validation, <code>.env</code> configuration wizards, and webroot routing.</p>
    <table>
        <tr>
            <th>Framework / Stack</th>
            <th>Required Components</th>
            <th>Configuration</th>
            <th>Support Status</th>
        </tr>
        <?php foreach ($profiles as $p): ?>
            <tr>
                <td><b><?= $p['icon'] ?> <?= h($p['name']) ?></b><br><small style="color:#8b949e"><?= h($p['description']) ?></small></td>
                <td><code><?= h(implode(', ', $p['required_components']) ?: 'None (Nginx)') ?></code></td>
                <td><?= $p['config_file'] ? '<code>' . h($p['config_file']) . '</code>' : '<em>Auto</em>' ?></td>
                <td><?= $p['status'] === 'supported' ? '<span class="pill ok">Active</span>' : '<span class="pill off">Placeholder</span>' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if (!empty($stack['log'])): ?>
    <div class="card">
        <h3>Latest Package Job Output</h3>
        <pre><?= h($stack['log']) ?></pre>
    </div>
<?php endif; ?>

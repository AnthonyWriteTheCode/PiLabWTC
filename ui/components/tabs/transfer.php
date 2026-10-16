<?php
declare(strict_types=1);
/**
 * Tab Container: File Transfer
 * Browser drag & drop site deployer, Windows SMB share, vsftpd FTP server, and SSH/SFTP access.
 */

$t = wj('transfer');
$d = wj('dropbox');
$host = h($t['host'] ?? 'pi');
$ip = h(explode(' ', $t['ips'] ?? '')[0] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1'));
?>

<!-- Browser Drag & Drop Site Deployer -->
<?php require __DIR__ . '/../dropzone.php'; ?>

<div class="grid2">
    <!-- Windows File Share (Samba) -->
    <div class="card">
        <h3>🗂️ Windows File Share (Samba) <?= onoff(!empty($t['sambaActive'])) ?></h3>
        <?php if (empty($t['sambaInstalled'])): ?>
            <p>Samba is not installed on this system.</p>
        <?php else: ?>
            <p>Direct Network Path: <code>\\<?= $ip ?>\dropbox</code></p>
            <p>
                <?= form('transfer', ['what' => 'samba', 'value' => !empty($t['sambaActive']) ? 'off' : 'on'], !empty($t['sambaActive']) ? 'Stop Samba' : 'Start Samba') ?>
            </p>
            <p>
                Guest LAN Write Access:
                <?= !empty($t['sambaGuest']) ? '<span class="pill ok">Allowed</span>' : '<span class="pill off">Password Required</span>' ?>
                <?= form('transfer', ['what' => 'samba-guest', 'value' => !empty($t['sambaGuest']) ? 'no' : 'yes'], !empty($t['sambaGuest']) ? 'Require Password' : 'Allow Guest') ?>
            </p>
            <form method="post">
                <p>
                    Set password for user <code><?= h($t['sambaUser'] ?? 'wtcdrop') ?></code>:
                    <input type="password" name="password" minlength="8" required>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="samba_password">
                    <button>Set Password</button>
                </p>
            </form>
            <div class="tip-box">
                <b>💡 Windows Drag &amp; Drop:</b><br>
                Open Windows Explorer, paste <code>\\<?= $ip ?>\dropbox</code> into the address bar, and drag &amp; drop any site folder or archive directly into the Pi!
            </div>
        <?php endif; ?>
    </div>

    <!-- FTP Server (vsftpd) -->
    <div class="card">
        <h3>📡 FTP Server (vsftpd) <?= onoff(!empty($t['ftpActive'])) ?></h3>
        <?php if (empty($t['ftpInstalled'])): ?>
            <p>vsftpd FTP server is not installed.</p>
            <?= form('transfer', ['what' => 'ftp', 'value' => 'install'], '📦 Install & Setup FTP Server', 'btn-pri', 'Install vsftpd and configure it for RAM dropbox?') ?>
        <?php else: ?>
            <p>Direct FTP URL: <code>ftp://<?= $ip ?>/</code></p>
            <p>
                <?= form('transfer', ['what' => 'ftp', 'value' => !empty($t['ftpActive']) ? 'off' : 'on'], !empty($t['ftpActive']) ? 'Stop FTP Server' : 'Start FTP Server') ?>
            </p>
            <p>
                Anonymous / Guest Write Access:
                <?= !empty($t['ftpGuest']) ? '<span class="pill ok">Allowed</span>' : '<span class="pill off">Password Required</span>' ?>
                <?= form('transfer', ['what' => 'ftp-guest', 'value' => !empty($t['ftpGuest']) ? 'no' : 'yes'], !empty($t['ftpGuest']) ? 'Require Password' : 'Allow Anonymous') ?>
            </p>
            <form method="post">
                <p>
                    Set FTP password for user <code><?= h($t['sambaUser'] ?? 'wtcdrop') ?></code>:
                    <input type="password" name="password" minlength="8" required>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="ftp_password">
                    <button>Set Password</button>
                </p>
            </form>
            <div class="tip-box">
                <b>💡 Windows Explorer FTP:</b><br>
                Press <kbd>Ctrl+L</kbd> in Windows Explorer, type <code>ftp://<?= $ip ?>/</code>, and press Enter. You can drag and drop folders and files directly into the Pi!
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid2">
    <!-- SSH / SFTP -->
    <div class="card">
        <h3>🔒 SSH &amp; SFTP <?= onoff(!empty($t['sshActive'])) ?></h3>
        <p>Upload via SCP: <code>scp -r ./my-site/ ubuntu@<?= $ip ?>:/mnt/ramdisk/dropbox/</code></p>
        <p>Upload via FileZilla / WinSCP: Connect via SFTP to <code><?= $ip ?></code> and open <code>/mnt/ramdisk/dropbox</code>.</p>
        <?= form('transfer', ['what' => 'ssh', 'value' => !empty($t['sshActive']) ? 'off' : 'on'], !empty($t['sshActive']) ? 'Disable SSH' : 'Enable SSH', '', 'Disabling SSH may lock you out of remote terminal access!') ?>
    </div>

    <!-- Dropbox Queue -->
    <div class="card">
        <h3>📥 Dropbox Inbox: <code><?= h($d['path'] ?? '/mnt/ramdisk/dropbox') ?></code></h3>
        <p>
            Pending Ingest Queue: <?= count($d['pending'] ?? []) ?>
            <?= form('ingest', ['back' => 'transfer'], '🔄 Process Inbox Now') ?>
        </p>
        <?php foreach ($d['pending'] ?? [] as $f): ?>
            <div>📁 <?= h($f['name']) ?> (<?= fmt_bytes($f['size']) ?>)</div>
        <?php endforeach; ?>

        <?php if (!empty($d['rejected'])): ?>
            <h4 style="color:#ff7b72;margin-top:16px">Rejected Uploads</h4>
            <?php foreach ($d['rejected'] as $r): ?>
                <div class="flash err"><b><?= h($r['name']) ?></b>: <?= h($r['reason']) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

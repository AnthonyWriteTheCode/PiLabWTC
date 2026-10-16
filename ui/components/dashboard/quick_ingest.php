<?php
declare(strict_types=1);
/**
 * Dashboard Component: Quick File Ingest
 * Upload application packages and remote transfer instructions (SMB & SFTP).
 *
 * Variables passed in:
 * @var array $st Device status array
 */
?>
<div class="card">
    <h3>📥 Quick File Ingest</h3>
    <p>Drop any <code>&lt;site&gt;.tar.gz</code> to create or update applications:</p>
    <p>📁 <b>Windows SMB Share:</b> <code>\\<?= h(explode(' ', $st['ips'] ?? '')[0] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1')) ?>\dropbox</code></p>
    <p>🔒 <b>SFTP / SCP:</b> <code>/mnt/ramdisk/dropbox</code></p>
    <form method="post" enctype="multipart/form-data" class="upload-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="back" value="dashboard">
        <input type="file" name="archive" required>
        <button class="btn-pri">Upload & Deploy</button>
    </form>
</div>

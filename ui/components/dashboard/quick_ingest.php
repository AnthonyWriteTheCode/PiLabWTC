<?php
declare(strict_types=1);
/**
 * Dashboard Component: Quick File Ingest
 * Upload application packages with drag-and-drop, remote SMB/SFTP endpoints, and pending/rejected status.
 *
 * Variables passed in:
 * @var array $st Device status array
 */

$ips = explode(' ', $st['ips'] ?? '');
$primaryIp = $ips[0] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
$pendingCount = (int) ($st['pending'] ?? 0);
$rejectedCount = (int) ($st['rejected'] ?? 0);
?>
<div class="card" id="dashQuickIngestCard" style="margin-bottom:0">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px">
            📥 Quick Ingest &amp; Deploy
            <span class="badge" id="ingestPendingBadge" style="<?= $pendingCount > 0 ? '' : 'display:none;' ?>background:#1f6feb;color:#fff">
                <?= $pendingCount ?> Pending Ingest
            </span>
            <span class="badge" id="ingestRejectedBadge" style="<?= $rejectedCount > 0 ? '' : 'display:none;' ?>background:#f85149;color:#fff">
                <?= $rejectedCount ?> Rejected
            </span>
        </h3>
        <a href="/?p=transfer" data-page="transfer" class="btn" style="padding:2px 8px;font-size:0.75rem;text-decoration:none">
            Full Transfer &rarr;
        </a>
    </div>

    <p style="margin:0 0 10px 0;font-size:0.82rem;color:#8b949e">
        Drop any <code>&lt;site&gt;.tar.gz</code> archive. Ingest service validates integrity and auto-unpacks into RAM.
    </p>

    <!-- Visual Drop Target -->
    <form method="post" enctype="multipart/form-data" id="dashUploadForm" style="margin-bottom:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="back" value="dashboard">
        <div id="dashDropZone" style="border:2px dashed #30363d;border-radius:6px;padding:12px;text-align:center;background:#0d1117;transition:border-color 0.2s, background 0.2s;cursor:pointer">
            <span style="font-size:1.4rem;display:block;margin-bottom:4px">📦</span>
            <span style="font-size:0.82rem;color:#c9d1d9;font-weight:600" id="dashDropText">Click to select or drag .tar.gz archive here</span>
            <input type="file" name="archive" id="dashFileInput" accept=".tar.gz,.tgz,.zip" required style="display:none">
        </div>
        <div id="dashUploadSubmitRow" style="display:none;margin-top:8px;justify-content:flex-end">
            <button type="submit" class="btn-pri" style="padding:5px 14px;font-size:0.82rem">⚡ Upload &amp; Deploy to RAM</button>
        </div>
    </form>

    <!-- Network Transfer Endpoints -->
    <div style="font-size:0.78rem;background:#10151d;border:1px solid #21262d;border-radius:5px;padding:8px 10px;display:flex;flex-direction:column;gap:4px">
        <div style="display:flex;align-items:center;justify-content:space-between">
            <span>📁 <b>Windows SMB Share:</b></span>
            <code style="color:#79c0ff;user-select:all">\\<?= h($primaryIp) ?>\dropbox</code>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between">
            <span>🔒 <b>SFTP / SCP Inbox:</b></span>
            <code style="color:#7ee787;user-select:all">/mnt/ramdisk/dropbox</code>
        </div>
    </div>
</div>

<script>
(() => {
    const dropZone = document.getElementById('dashDropZone');
    const fileInput = document.getElementById('dashFileInput');
    const dropText = document.getElementById('dashDropText');
    const submitRow = document.getElementById('dashUploadSubmitRow');

    if (dropZone && fileInput) {
        dropZone.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', () => {
            if (fileInput.files && fileInput.files[0]) {
                const fname = fileInput.files[0].name;
                dropText.textContent = `Selected: ${fname} (${(fileInput.files[0].size / (1024*1024)).toFixed(1)} MB)`;
                dropZone.style.borderColor = '#238636';
                dropZone.style.background = '#0e1f14';
                if (submitRow) submitRow.style.display = 'flex';
            }
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropZone.style.borderColor = '#58a6ff';
                dropZone.style.background = '#111e2e';
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropZone.style.borderColor = '#30363d';
                dropZone.style.background = '#0d1117';
            }, false);
        });

        dropZone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files && files[0]) {
                fileInput.files = files;
                const fname = files[0].name;
                dropText.textContent = `Selected: ${fname} (${(files[0].size / (1024*1024)).toFixed(1)} MB)`;
                dropZone.style.borderColor = '#238636';
                dropZone.style.background = '#0e1f14';
                if (submitRow) submitRow.style.display = 'flex';
            }
        }, false);
    }

    if (window.dashLive) {
        window.dashLive.subscribe('ingest_badges', (data) => {
            const st = data.status || {};
            const pBadge = document.getElementById('ingestPendingBadge');
            const rBadge = document.getElementById('ingestRejectedBadge');
            const pending = Number(st.pending) || 0;
            const rejected = Number(st.rejected) || 0;

            if (pBadge) {
                pBadge.style.display = pending > 0 ? 'inline-block' : 'none';
                pBadge.textContent = `${pending} Pending`;
            }
            if (rBadge) {
                rBadge.style.display = rejected > 0 ? 'inline-block' : 'none';
                rBadge.textContent = `${rejected} Rejected`;
            }
        });
    }
})();
</script>

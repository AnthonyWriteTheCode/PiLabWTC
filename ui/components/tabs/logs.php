<?php
declare(strict_types=1);
/**
 * Tab Container: Stack Logs Viewer
 * Full-page structured, formatted logs viewer for Application Stacks,
 * Nginx, PHP-FPM, Workers, Build Jobs, and WTC System Activity.
 * Zero external libraries.
 */

$initSource = trim((string)($_GET['source'] ?? ''));
$initSite = trim((string)($_GET['site'] ?? ''));
$initPath = trim((string)($_GET['path'] ?? ''));
?>

<div class="card" style="margin-bottom:14px;border-left:4px solid #388bfd;background:#0c1524">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
            <h3 style="margin:0;border:0;padding:0;color:#7cc4ff">📜 Stack &amp; Runtime Logs Viewer</h3>
            <p style="margin:4px 0 0 0;font-size:0.84rem;color:#8b949e">
                Inspect and filter formatted logs across Laravel/PHP/SPA application stacks in RAM, Nginx, PHP-FPM, background workers, and build jobs.
            </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="/?p=explorer" class="btn" style="font-size:0.8rem">⚡ RAM Drive</a>
            <a href="/?p=stack_manager" class="btn" style="font-size:0.8rem">📦 Stack Manager</a>
            <a href="/?p=sites" class="btn" style="font-size:0.8rem">📁 Sites &amp; Apps</a>
        </div>
    </div>
</div>

<div class="card">
    <div id="wtcStackLogsPageHost">
        <noscript>
            <pre><?= h(wtc(['--logs', '100'])[1]) ?></pre>
        </noscript>
    </div>
</div>

<script>
(() => {
    const host = document.getElementById('wtcStackLogsPageHost');
    if (!host || !window.WtcLogViewer) return;

    if (window._wtcPageLogViewer && typeof window._wtcPageLogViewer.destroy === 'function') {
        window._wtcPageLogViewer.destroy();
    }

    window._wtcPageLogViewer = window.WtcLogViewer.mount(host, {
        source: <?= json_encode($initSource) ?>,
        site: <?= json_encode($initSite) ?>,
        path: <?= json_encode($initPath) ?>,
        lines: 200,
        maxHeight: '64vh'
    });
})();
</script>

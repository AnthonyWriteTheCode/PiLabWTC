<?php
declare(strict_types=1);
/**
 * Header Component
 * Renders HTML document head, styles, brand, and navigation tabs.
 */
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?php if ($refresh): ?>
        <meta http-equiv="refresh" content="<?= (int) $refresh ?>">
    <?php endif; ?>
    <title>WTC Pi - <?= h($pages[$page] ?? 'Device Manager') ?></title>
    <style>
        <?= css() ?>
    </style>
    <script defer src="/js/spa.js?v=<?= filemtime(__DIR__ . '/../js/spa.js') ?>"></script>
</head>

<body>
    <div id="spaProgressBar" class="spa-progress-bar"></div>
    <header>
        <div class="brand">
            <span class="brand-logo">⚡</span>
            <span class="brand-title">WTC Pi</span>
            <span class="brand-tag">LAB</span>
            <button type="button" id="mobileNavToggle" class="mobile-nav-toggle" aria-label="Toggle Navigation Menu">
                <span class="burger-bar"></span>
                <span class="burger-bar"></span>
                <span class="burger-bar"></span>
            </button>
        </div>
        <nav>
            <?php foreach ($pages as $k => $v): ?>
                <a href="/?p=<?= urlencode($k) ?>" data-page="<?= h($k) ?>" data-title="WTC Pi - <?= h($v) ?>" <?= $k === $page ? ' class="on"' : '' ?>><?= h($v) ?></a>
            <?php endforeach; ?>
        </nav>
        <span class="sp"></span>
        <div class="header-actions">
            <form method="post" style="display:inline"
                onsubmit="return confirm('Reload Management UI from SD card into RAM drive? This will update the running UI without rebooting.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reload_ui">
                <input type="hidden" name="back" value="<?= h($page) ?>">
                <button type="submit" class="btn-live btn-reload-ui"
                    title="Replace RAM drive UI with latest version from SD card without rebooting">🔄 Reload UI from
                    SD</button>
            </form>
            <a class="btn-live" href="<?= site_url('/') ?>" target="_blank" title="Open primary active site on Port 80 (Standard Web)">🌐 Live Site</a>
            <a class="btn-live root-link"
                href="<?= site_port_url(8080, '/') ?>" target="_blank"
                title="Open active site at root / on Port 8080 (Enterprise Port)">🚀 Port 8080</a>
        </div>
    </header>
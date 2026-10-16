<?php
declare(strict_types=1);
/**
 * Main Layout Component
 * Holds the main container shell, flash alerts, and hosts the active tab container.
 */
?>
<main class="main-container">
    <div id="spa-flash">
        <?php if (!empty($_SESSION['flash'])): ?>
            <?php [$flashType, $flashMsg] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
            <div class="flash <?= h($flashType) ?>"><?= h($flashMsg) ?></div>
        <?php endif; ?>
    </div>

    <div id="spa-content" class="tab-container tab-<?= h($page) ?>">
        <?php
        $tabFile = __DIR__ . '/tabs/' . $page . '.php';
        if (is_file($tabFile)) {
            require $tabFile;
        } else {
            echo '<div class="card"><p>Tab container "' . h($page) . '" not found.</p></div>';
        }
        ?>
    </div>
</main>

<?php
declare(strict_types=1);

/**
 * Tab Container: Database Manager & Table Row Editor
 * Allows browsing MariaDB tables, searching records, editing rows,
 * performing 1-click user account activations, inserting records, and running SQL queries.
 */

require_once __DIR__ . '/../db_helper.php';

$activeSite = (string)(wj('status')['active'] ?? '');
$dbSites = get_db_sites();
$curSite = (string)($_GET['site'] ?? $activeSite);
if ($curSite === '' && !empty($dbSites)) {
    $curSite = $dbSites[0];
}

$dbErr = null;
$pdo = null;
$creds = null;
if ($curSite !== '') {
    [$rc, $cOut] = wtc(['--db-creds', $curSite]);
    $creds = json_decode($cOut, true) ?: [];
    $pdo = get_db_connection($curSite, $dbErr);
}

$tables = [];
$curTable = (string)($_GET['table'] ?? '');
if ($pdo) {
    try {
        $dbName = (string)($creds['name'] ?? ('w_' . $curSite));
        $tables = get_db_tables($pdo, $dbName);
        if ($curTable === '' && !empty($tables)) {
            // Default to 'users' table if present, otherwise first table
            $foundUsers = false;
            foreach ($tables as $t) {
                if (strtolower($t['name']) === 'users') {
                    $curTable = $t['name'];
                    $foundUsers = true;
                    break;
                }
            }
            if (!$foundUsers) {
                $curTable = $tables[0]['name'];
            }
        }
    } catch (\Throwable $e) {
        $dbErr = $e->getMessage();
    }
}

$schema = [];
$pks = [];
$tableData = null;
$hasActivationFields = false;

if ($pdo && $curTable !== '') {
    try {
        $schema = get_table_schema($pdo, $curTable);
        $pks = get_primary_keys($schema);

        // Check if table contains account activation fields
        $fieldsLower = array_map('strtolower', array_column($schema, 'field'));
        foreach (['email_verified_at', 'status', 'is_active', 'active', 'verified'] as $fCheck) {
            if (in_array($fCheck, $fieldsLower, true)) {
                $hasActivationFields = true;
                break;
            }
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(10, min(100, (int)($_GET['limit'] ?? 25)));
        $search = (string)($_GET['q'] ?? '');
        $sort = (string)($_GET['sort'] ?? '');
        $dir = (string)($_GET['dir'] ?? 'ASC');

        $tableData = get_table_rows($pdo, $curTable, $schema, $page, $limit, $search, $sort, $dir);
    } catch (\Throwable $e) {
        $tableErr = $e->getMessage();
    }
}

$queryResult = $_SESSION['db_query_result'] ?? null;
unset($_SESSION['db_query_result']);
?>

<style>
/* Fix table header alignment and sticky behavior inside database manager */
.db-manager-container .db-table-scroll {
    overflow-x: auto;
    max-width: 100%;
    border: 1px solid #30363d;
    border-radius: 8px;
    background: #0d1117;
}
.db-manager-container table.db-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin-top: 0;
    border: none;
    font-size: 0.84rem;
    text-align: left;
    white-space: nowrap;
}
.db-manager-container table.db-table th {
    position: static !important;
    top: auto !important;
    background: #161b22;
    color: #c9d1d9;
    padding: 9px 12px;
    border-bottom: 2px solid #30363d;
    font-size: 0.78rem;
    letter-spacing: 0.03em;
    z-index: 1;
}
.db-manager-container table.db-table th.actions-col {
    position: sticky !important;
    left: 0 !important;
    z-index: 3 !important;
    background: #161b22 !important;
    color: #8b949e;
    box-shadow: 1px 0 0 #30363d;
}
.db-manager-container table.db-table td.actions-col {
    position: sticky !important;
    left: 0 !important;
    z-index: 2 !important;
    box-shadow: 1px 0 0 #21262d;
}
.db-manager-container table.sql-result-table th {
    position: static !important;
    top: auto !important;
}
</style>

<div class="db-manager-container">

    <!-- Top Navigation & Database Header Card -->
    <div class="card" style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
            <div>
                <h3 style="margin-bottom:4px;color:#7cc4ff;display:flex;align-items:center;gap:8px">
                    🗄️ Database Manager &amp; Table Editor
                    <?php if ($pdo): ?>
                        <span class="pill ok" style="font-size:0.75rem">Connected</span>
                    <?php else: ?>
                        <span class="pill off" style="font-size:0.75rem">Disconnected</span>
                    <?php endif; ?>
                    <?php if (!empty($creds['name'])): ?>
                        <span class="pill" style="background:#1f2833;color:#79c0ff;border:1px solid #1f6feb;font-size:0.75rem">
                            <?= h($creds['name']) ?> (MariaDB)
                        </span>
                    <?php endif; ?>
                </h3>
                <div style="font-size:0.85rem;color:#8b949e">
                    Browse and edit database tables, activate user accounts, and execute safe queries directly in volatile RAM storage.
                </div>
            </div>

            <!-- Site Switcher & Quick Controls -->
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <form method="get" action="/" style="display:inline-flex;gap:6px;align-items:center">
                    <input type="hidden" name="p" value="database">
                    <label style="font-size:0.85rem;color:#8b949e">Site DB:</label>
                    <select name="site" onchange="this.form.submit()" style="background:#2a3541;color:#fff;border:1px solid #3d4d5e;padding:6px 12px;border-radius:6px;font-size:0.85rem">
                        <?php foreach ($dbSites as $sName): ?>
                            <option value="<?= h($sName) ?>" <?= $sName === $curSite ? 'selected' : '' ?>>
                                <?= h($sName) ?><?= $sName === $activeSite ? ' (Active)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if ($pdo && $curTable !== ''): ?>
                    <button type="button" class="btn-pri" style="background:#238636;border-color:#2ea043;font-size:0.85rem;padding:6px 12px" onclick="openInsertModal()">
                        ➕ Insert Row
                    </button>
                <?php endif; ?>

                <?php if ($curSite === $activeSite && $activeSite !== ''): ?>
                    <?= form('save_state', ['back' => 'database&site=' . urlencode($curSite)], '💾 Snapshot DB to SD', 'btn-pri', 'title="Snapshots RAM database dump and runtime files directly back to persistent SD archive"') ?>
                <?php endif; ?>

                <a href="#sqlConsole" class="btn-ctrl" style="font-size:0.85rem;padding:6px 12px;text-decoration:none">
                    💻 SQL Console
                </a>
            </div>
        </div>

        <?php if ($pdo && !empty($creds)): ?>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid #2a3541;font-size:0.82rem;color:#8b949e">
                <div><span>Host:</span> <b style="color:#fff"><?= h($creds['host'] ?? '127.0.0.1') ?>:<?= (int)($creds['port'] ?? 3306) ?></b></div>
                <div><span>Database:</span> <b style="color:#7ee787"><?= h($creds['name']) ?></b></div>
                <div><span>User:</span> <b style="color:#fff"><?= h($creds['user'] ?? $creds['name']) ?></b></div>
                <div><span>Total Tables:</span> <b style="color:#79c0ff"><?= count($tables) ?></b></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$pdo): ?>
        <!-- Connection Error Banner -->
        <div class="card" style="border-color:#da3633;background:#220e10;margin-bottom:16px">
            <h4 style="color:#f85149;margin-bottom:8px">⚠️ Database Connection Failed</h4>
            <p style="font-size:0.9rem;color:#ff7b72;margin-bottom:12px"><?= h($dbErr ?: 'Could not establish connection to MariaDB.') ?></p>
            <div style="display:flex;gap:8px">
                <?= form('db_create', ['site' => $curSite, 'back' => 'database'], '⚡ Initialize Database for ' . $curSite, 'btn-pri') ?>
                <a href="/?p=database&site=<?= urlencode($curSite) ?>" class="btn">🔄 Retry Connection</a>
            </div>
        </div>
    <?php else: ?>

        <!-- Main Workspace: Table Navigator & Data Table Grid -->
        <div style="display:grid;grid-template-columns:260px 1fr;gap:16px;align-items:start">

            <!-- Left Navigation: Table List -->
            <div class="card" style="padding:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <h4 style="font-size:0.95rem;color:#7cc4ff">Tables (<?= count($tables) ?>)</h4>
                    <span style="font-size:0.75rem;color:#8b949e">MariaDB</span>
                </div>

                <div style="margin-bottom:10px">
                    <input type="text" id="tableFilterInput" placeholder="Filter tables..." onkeyup="filterTableList()"
                           style="width:100%;background:#0d1117;color:#fff;border:1px solid #30363d;padding:6px 10px;border-radius:6px;font-size:0.82rem">
                </div>

                <div id="tableListWrapper" style="display:flex;flex-direction:column;gap:3px;max-height:620px;overflow-y:auto;padding-right:4px">
                    <?php if (empty($tables)): ?>
                        <div style="font-size:0.82rem;color:#8b949e;padding:12px 0;text-align:center">
                            No tables found.<br>Run migrations or import a SQL seed.
                        </div>
                    <?php else: ?>
                        <?php foreach ($tables as $t): ?>
                            <?php
                            $isCur = $t['name'] === $curTable;
                            $isUsers = strtolower($t['name']) === 'users';
                            ?>
                            <a href="/?p=database&site=<?= urlencode($curSite) ?>&table=<?= urlencode($t['name']) ?>"
                               class="table-nav-item"
                               data-name="<?= h(strtolower($t['name'])) ?>"
                               style="display:flex;justify-content:space-between;align-items:center;padding:7px 10px;border-radius:6px;text-decoration:none;font-size:0.84rem;<?= $isCur ? 'background:#1f6feb;color:#fff;font-weight:600' : 'background:#161f28;color:#ccc' ?>">
                                <span style="display:flex;align-items:center;gap:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                    <?= $isUsers ? '👤' : '📄' ?> <?= h($t['name']) ?>
                                </span>
                                <span class="pill" style="font-size:0.72rem;padding:2px 6px;<?= $isCur ? 'background:#0d419d;color:#fff' : 'background:#21262d;color:#8b949e' ?>">
                                    <?= (int)$t['rows'] ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Workspace: Table Records & Data Grid -->
            <div>
                <?php if ($curTable === ''): ?>
                    <div class="card" style="text-align:center;padding:48px">
                        <h4 style="color:#7cc4ff;margin-bottom:8px">No Table Selected</h4>
                        <p style="color:#8b949e;font-size:0.9rem">Select a table from the left navigation to view and edit its records.</p>
                    </div>
                <?php else: ?>
                    <div class="card" style="margin-bottom:16px">

                        <!-- Table Action Toolbar -->
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px">
                            <div>
                                <h3 style="color:#fff;display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                    <span><?= strtolower($curTable) === 'users' ? '👤' : '📄' ?> <b><?= h($curTable) ?></b></span>
                                    <span style="font-size:0.75rem;color:#8b949e;font-weight:normal">
                                        (<?= (int)($tableData['total'] ?? 0) ?> records)
                                    </span>
                                    <?php if ($hasActivationFields): ?>
                                        <span class="pill" style="background:#13233a;color:#79c0ff;border:1px solid #1f6feb;font-size:0.72rem">
                                            ⚡ Account Activation Enabled
                                        </span>
                                    <?php endif; ?>
                                </h3>
                                <div style="font-size:0.82rem;color:#8b949e">
                                    Primary Key: <code style="color:#7ee787"><?= !empty($pks) ? implode(', ', $pks) : 'None' ?></code> &bull;
                                    Columns: <?= count($schema) ?> &bull;
                                    Sorting by: <code style="color:#79c0ff"><?= h($tableData['sort'] ?? '') ?> <?= h($tableData['dir'] ?? 'ASC') ?></code>
                                </div>
                            </div>

                            <!-- Search & Page Controls -->
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                <form method="get" action="/" style="display:flex;gap:6px;align-items:center">
                                    <input type="hidden" name="p" value="database">
                                    <input type="hidden" name="site" value="<?= h($curSite) ?>">
                                    <input type="hidden" name="table" value="<?= h($curTable) ?>">
                                    <input type="text" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Search records..."
                                           style="background:#0d1117;color:#fff;border:1px solid #30363d;padding:6px 12px;border-radius:6px;font-size:0.85rem;width:180px">
                                    <button type="submit" class="btn-ctrl" style="padding:6px 12px;font-size:0.85rem">🔍 Filter</button>
                                    <?php if (!empty($_GET['q'])): ?>
                                        <a href="/?p=database&site=<?= urlencode($curSite) ?>&table=<?= urlencode($curTable) ?>" class="btn-ctrl" style="padding:6px 10px;text-decoration:none;font-size:0.85rem">✕ Clear</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <!-- Data Grid Table -->
                        <?php if (empty($tableData['rows'])): ?>
                            <div style="padding:36px;text-align:center;background:#0d1117;border-radius:8px;border:1px dashed #30363d">
                                <p style="color:#8b949e;font-size:0.9rem;margin-bottom:12px">
                                    <?= !empty($_GET['q']) ? 'No records match search filter "' . h($_GET['q']) . '".' : 'Table is empty.' ?>
                                </p>
                                <button type="button" class="btn-pri" style="background:#238636;border-color:#2ea043" onclick="openInsertModal()">➕ Insert First Record</button>
                            </div>
                        <?php else: ?>
                            <div class="db-table-scroll">
                                <table class="db-table">
                                    <thead>
                                        <tr style="background:#161b22;border-bottom:2px solid #30363d">
                                            <th class="actions-col" style="padding:8px 12px">Actions</th>
                                            <?php foreach ($tableData['columns'] as $col): ?>
                                                <?php
                                                $isPk = in_array($col, $pks, true);
                                                $nextDir = ($tableData['sort'] === $col && $tableData['dir'] === 'ASC') ? 'DESC' : 'ASC';
                                                $sortUrl = "/?p=database&site=" . urlencode($curSite) . "&table=" . urlencode($curTable) . "&sort=" . urlencode($col) . "&dir=" . $nextDir . (!empty($_GET['q']) ? "&q=" . urlencode($_GET['q']) : '');
                                                ?>
                                                <th style="padding:8px 12px;color:#c9d1d9">
                                                    <a href="<?= $sortUrl ?>" style="color:<?= $isPk ? '#7ee787' : '#c9d1d9' ?>;text-decoration:none;display:inline-flex;align-items:center;gap:4px">
                                                        <?= $isPk ? '🔑 ' : '' ?><?= h($col) ?>
                                                        <?php if ($tableData['sort'] === $col): ?>
                                                            <span style="font-size:0.75rem"><?= $tableData['dir'] === 'ASC' ? '▲' : '▼' ?></span>
                                                        <?php endif; ?>
                                                    </a>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tableData['rows'] as $rIdx => $row): ?>
                                            <?php
                                            $rowWhere = [];
                                            if (!empty($pks)) {
                                                foreach ($pks as $pkCol) {
                                                    $rowWhere[$pkCol] = $row[$pkCol] ?? null;
                                                }
                                            } else {
                                                $rowWhere = $row;
                                            }
                                            $rowWhereJson = json_encode($rowWhere);
                                            $rowDataJson = json_encode($row);

                                            // Determine if row is already active / verified
                                            $isVerified = false;
                                            if (isset($row['email_verified_at']) && $row['email_verified_at'] !== null) $isVerified = true;
                                            if (isset($row['is_active']) && (int)$row['is_active'] === 1) $isVerified = true;
                                            if (isset($row['status']) && strtolower((string)$row['status']) === 'active') $isVerified = true;
                                            ?>
                                            <tr style="border-bottom:1px solid #21262d;background:<?= $rIdx % 2 === 0 ? '#0d1117' : '#161b22' ?>">
                                                <!-- Action Buttons Cell -->
                                                <td class="actions-col" style="padding:6px 12px;background:inherit">
                                                    <div style="display:flex;gap:4px;align-items:center">
                                                        <?php if ($hasActivationFields): ?>
                                                            <?php if (!$isVerified): ?>
                                                                <form method="post" style="display:inline">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="db_quick_activate">
                                                                    <input type="hidden" name="site" value="<?= h($curSite) ?>">
                                                                    <input type="hidden" name="table" value="<?= h($curTable) ?>">
                                                                    <input type="hidden" name="where_json" value="<?= h($rowWhereJson) ?>">
                                                                    <input type="hidden" name="back_url" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                                                                    <button type="submit" class="btn-pri" style="background:#238636;border:0;padding:3px 8px;font-size:0.75rem;border-radius:4px" title="Set email_verified_at = NOW(), status = active">
                                                                        ⚡ Activate
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span class="pill ok" style="font-size:0.72rem;padding:2px 6px">Active</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>

                                                        <button type="button" class="btn-ctrl" style="padding:3px 8px;font-size:0.75rem"
                                                                onclick='openEditModal(<?= htmlspecialchars($rowDataJson, ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars($rowWhereJson, ENT_QUOTES, "UTF-8") ?>)'>
                                                            ✏️ Edit
                                                        </button>

                                                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this record from <?= h($curTable) ?>? This action cannot be undone.')">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="db_row_delete">
                                                            <input type="hidden" name="site" value="<?= h($curSite) ?>">
                                                            <input type="hidden" name="table" value="<?= h($curTable) ?>">
                                                            <input type="hidden" name="where_json" value="<?= h($rowWhereJson) ?>">
                                                            <input type="hidden" name="back_url" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                                                            <button type="submit" class="btn-ctrl danger" style="padding:3px 7px;font-size:0.75rem" title="Delete record">
                                                                🗑️
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>

                                                <!-- Columns Data Cells -->
                                                <?php foreach ($tableData['columns'] as $col): ?>
                                                    <?php
                                                    $val = $row[$col] ?? null;
                                                    $isPk = in_array($col, $pks, true);
                                                    ?>
                                                    <td style="padding:6px 12px;font-family:monospace;font-size:0.82rem;color:<?= $isPk ? '#7ee787' : '#c9d1d9' ?>">
                                                        <?php if ($val === null): ?>
                                                            <span style="color:#6e7681;font-style:italic">&lt;null&gt;</span>
                                                        <?php elseif ($col === 'email_verified_at'): ?>
                                                            <span style="color:#7ee787">✓ <?= h((string)$val) ?></span>
                                                        <?php elseif ($col === 'status' || $col === 'is_active'): ?>
                                                            <?php if ($val === 'active' || $val === '1' || $val === 1): ?>
                                                                <span class="pill ok" style="font-size:0.72rem"><?= h((string)$val) ?></span>
                                                            <?php else: ?>
                                                                <span class="pill off" style="font-size:0.72rem"><?= h((string)$val) ?></span>
                                                            <?php endif; ?>
                                                        <?php elseif (strlen((string)$val) > 40): ?>
                                                            <span title="<?= h((string)$val) ?>"><?= h(substr((string)$val, 0, 37)) ?>…</span>
                                                        <?php else: ?>
                                                            <?= h((string)$val) ?>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination Footer -->
                            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-top:14px;padding-top:12px;border-top:1px solid #30363d;font-size:0.85rem">
                                <div style="color:#8b949e">
                                    Showing page <b><?= $tableData['page'] ?></b> of <b><?= $tableData['pages'] ?></b> &bull; <?= $tableData['total'] ?> total records
                                </div>
                                <div style="display:flex;gap:6px">
                                    <?php if ($tableData['page'] > 1): ?>
                                        <a href="/?p=database&site=<?= urlencode($curSite) ?>&table=<?= urlencode($curTable) ?>&page=1<?= !empty($_GET['q']) ? '&q=' . urlencode($_GET['q']) : '' ?>" class="btn-ctrl" style="padding:4px 10px;text-decoration:none">« First</a>
                                        <a href="/?p=database&site=<?= urlencode($curSite) ?>&table=<?= urlencode($curTable) ?>&page=<?= $tableData['page'] - 1 ?><?= !empty($_GET['q']) ? '&q=' . urlencode($_GET['q']) : '' ?>" class="btn-ctrl" style="padding:4px 10px;text-decoration:none">‹ Prev</a>
                                    <?php endif; ?>

                                    <span class="pill" style="background:#21262d;color:#fff;padding:5px 12px;font-size:0.82rem">Page <?= $tableData['page'] ?></span>

                                    <?php if ($tableData['page'] < $tableData['pages']): ?>
                                        <a href="/?p=database&site=<?= urlencode($curSite) ?>&table=<?= urlencode($curTable) ?>&page=<?= $tableData['page'] + 1 ?><?= !empty($_GET['q']) ? '&q=' . urlencode($_GET['q']) : '' ?>" class="btn-ctrl" style="padding:4px 10px;text-decoration:none">Next ›</a>
                                        <a href="/?p=database&site=<?= urlencode($curSite) ?>&table=<?= urlencode($curTable) ?>&page=<?= $tableData['pages'] ?><?= !empty($_GET['q']) ? '&q=' . urlencode($_GET['q']) : '' ?>" class="btn-ctrl" style="padding:4px 10px;text-decoration:none">Last »</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>

                <!-- SQL Query Console Card -->
                <div id="sqlConsole" class="card" style="margin-top:16px">
                    <h4 style="color:#7cc4ff;margin-bottom:8px;display:flex;align-items:center;gap:6px">
                        💻 Interactive SQL Query Console
                    </h4>
                    <p style="font-size:0.85rem;color:#8b949e;margin-bottom:12px">
                        Execute safe direct SQL queries against <code><?= h($creds['name'] ?? ('w_' . $curSite)) ?></code>.
                    </p>

                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
                        <span style="font-size:0.82rem;color:#8b949e;align-self:center">Presets:</span>
                        <button type="button" class="btn-ctrl" style="font-size:0.75rem;padding:3px 8px" onclick="setSqlQuery('SELECT * FROM users ORDER BY id DESC LIMIT 20;')">Users Preview</button>
                        <button type="button" class="btn-ctrl" style="font-size:0.75rem;padding:3px 8px" onclick="setSqlQuery('UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL;')">Activate All Users</button>
                        <button type="button" class="btn-ctrl" style="font-size:0.75rem;padding:3px 8px" onclick="setSqlQuery('SHOW TABLES;')">Show Tables</button>
                    </div>

                    <form method="post" action="/">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="db_query">
                        <input type="hidden" name="site" value="<?= h($curSite) ?>">
                        <input type="hidden" name="table" value="<?= h($curTable) ?>">
                        <input type="hidden" name="back_url" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                        <textarea id="sqlQueryInput" name="sql_query" rows="3" placeholder="SELECT * FROM users LIMIT 10;"
                                  style="width:100%;font-family:monospace;font-size:0.88rem;background:#0d1117;color:#7ee787;border:1px solid #30363d;padding:10px;border-radius:6px;margin-bottom:10px"><?= !empty($_POST['sql_query']) ? h($_POST['sql_query']) : '' ?></textarea>
                        <button type="submit" class="btn-pri" style="background:#238636;border-color:#2ea043;font-size:0.85rem">⚡ Run SQL Statement</button>
                    </form>

                    <?php if ($queryResult && !empty($queryResult['rows'])): ?>
                        <div style="margin-top:14px;overflow-x:auto;border:1px solid #30363d;border-radius:6px">
                            <table class="sql-result-table" style="width:100%;border-collapse:collapse;font-size:0.82rem;font-family:monospace">
                                <thead>
                                    <tr style="background:#161b22">
                                        <?php foreach (array_keys($queryResult['rows'][0]) as $k): ?>
                                            <th style="padding:6px 10px;border-bottom:1px solid #30363d;color:#79c0ff;text-align:left"><?= h($k) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($queryResult['rows'] as $qRow): ?>
                                        <tr style="border-bottom:1px solid #21262d">
                                            <?php foreach ($qRow as $cell): ?>
                                                <td style="padding:5px 10px;color:#c9d1d9"><?= $cell === null ? '<i style="color:#6e7681">&lt;null&gt;</i>' : h((string)$cell) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </div>

    <?php endif; ?>

</div>

<!-- Edit Row Modal -->
<div id="editRowModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center;padding:16px">
    <div class="card" style="max-width:650px;width:100%;max-height:90vh;overflow-y:auto;background:#161b22;border-color:#30363d;box-shadow:0 8px 32px rgba(0,0,0,0.5)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;border-bottom:1px solid #30363d;padding-bottom:10px">
            <h3 style="color:#7cc4ff;margin:0">✏️ Edit Record in <span id="modalEditTableName"><?= h($curTable) ?></span></h3>
            <button type="button" onclick="closeEditModal()" style="background:none;border:none;color:#8b949e;font-size:1.4rem;cursor:pointer">&times;</button>
        </div>

        <form method="post" action="/" id="editRowForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="db_row_update">
            <input type="hidden" name="site" value="<?= h($curSite) ?>">
            <input type="hidden" name="table" value="<?= h($curTable) ?>">
            <input type="hidden" name="where_json" id="editWhereJson" value="{}">
            <input type="hidden" name="back_url" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <div id="editFormFields" style="display:flex;flex-direction:column;gap:12px;margin-bottom:20px">
                <!-- Dynamically generated fields -->
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #30363d;padding-top:14px">
                <button type="button" class="btn-ctrl" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-pri" style="background:#238636;border-color:#2ea043">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Insert Row Modal -->
<div id="insertRowModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center;padding:16px">
    <div class="card" style="max-width:650px;width:100%;max-height:90vh;overflow-y:auto;background:#161b22;border-color:#30363d;box-shadow:0 8px 32px rgba(0,0,0,0.5)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;border-bottom:1px solid #30363d;padding-bottom:10px">
            <h3 style="color:#7ee787;margin:0">➕ Insert New Record into <?= h($curTable) ?></h3>
            <button type="button" onclick="closeInsertModal()" style="background:none;border:none;color:#8b949e;font-size:1.4rem;cursor:pointer">&times;</button>
        </div>

        <form method="post" action="/">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="db_row_insert">
            <input type="hidden" name="site" value="<?= h($curSite) ?>">
            <input type="hidden" name="table" value="<?= h($curTable) ?>">
            <input type="hidden" name="back_url" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:20px">
                <?php foreach ($schema as $col): ?>
                    <?php
                    $isAuto = str_contains(strtolower((string)$col['extra']), 'auto_increment');
                    $isPk = in_array($col['field'], $pks, true);
                    ?>
                    <div>
                        <label style="display:flex;justify-content:space-between;font-size:0.82rem;color:#8b949e;margin-bottom:4px">
                            <span><b><?= h($col['field']) ?></b> <span style="font-size:0.75rem;color:#6e7681">(<?= h($col['type']) ?>)</span></span>
                            <?php if ($isAuto): ?>
                                <span style="color:#7ee787;font-size:0.75rem">Auto-Increment</span>
                            <?php endif; ?>
                        </label>
                        <?php if ($isAuto): ?>
                            <input type="text" disabled placeholder="Generated automatically by MariaDB"
                                   style="width:100%;background:#0d1117;color:#6e7681;border:1px solid #30363d;padding:7px 10px;border-radius:6px;font-size:0.84rem">
                        <?php elseif (str_contains(strtolower($col['type']), 'text')): ?>
                            <textarea name="values[<?= h($col['field']) ?>]" rows="2"
                                      style="width:100%;background:#0d1117;color:#fff;border:1px solid #30363d;padding:7px 10px;border-radius:6px;font-size:0.84rem"></textarea>
                        <?php elseif ($col['field'] === 'email_verified_at'): ?>
                            <div style="display:flex;gap:6px">
                                <input type="text" name="values[<?= h($col['field']) ?>]" value="<?= date('Y-m-d H:i:s') ?>"
                                       style="width:100%;background:#0d1117;color:#fff;border:1px solid #30363d;padding:7px 10px;border-radius:6px;font-size:0.84rem">
                                <button type="button" class="btn-ctrl" style="font-size:0.75rem;white-space:nowrap" onclick="this.previousElementSibling.value='__NULL__'">Set NULL</button>
                            </div>
                        <?php else: ?>
                            <input type="text" name="values[<?= h($col['field']) ?>]" value="<?= h((string)$col['default']) ?>"
                                   style="width:100%;background:#0d1117;color:#fff;border:1px solid #30363d;padding:7px 10px;border-radius:6px;font-size:0.84rem">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #30363d;padding-top:14px">
                <button type="button" class="btn-ctrl" onclick="closeInsertModal()">Cancel</button>
                <button type="submit" class="btn-pri" style="background:#238636;border-color:#2ea043">Insert Record</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter tables list on typing
function filterTableList() {
    const q = document.getElementById('tableFilterInput').value.toLowerCase();
    const items = document.querySelectorAll('.table-nav-item');
    items.forEach(el => {
        const name = el.getAttribute('data-name');
        el.style.display = name.includes(q) ? 'flex' : 'none';
    });
}

// Schema metadata for modal field rendering
const tableSchema = <?= json_encode($schema) ?>;
const primaryKeys = <?= json_encode($pks) ?>;

// Open Edit Modal with prefilled values
function openEditModal(rowData, whereData) {
    document.getElementById('editWhereJson').value = JSON.stringify(whereData);
    const container = document.getElementById('editFormFields');
    container.innerHTML = '';

    tableSchema.forEach(col => {
        const fieldName = col.field;
        const val = rowData[fieldName];
        const isPk = primaryKeys.includes(fieldName);
        const isNull = (val === null);

        const wrap = document.createElement('div');
        wrap.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                <label style="font-size:0.82rem;color:#8b949e">
                    <b style="color:${isPk ? '#7ee787' : '#fff'}">${fieldName}</b>
                    <span style="font-size:0.75rem;color:#6e7681">(${col.type})</span>
                    ${isPk ? '<span style="color:#7ee787;font-size:0.75rem"> 🔑 PK</span>' : ''}
                </label>
                <label style="font-size:0.75rem;color:#8b949e;cursor:pointer">
                    <input type="checkbox" id="null_chk_${fieldName}" onchange="toggleNullField('${fieldName}')" ${isNull ? 'checked' : ''}> Set NULL
                </label>
            </div>
            ${col.type.toLowerCase().includes('text') ? `
                <textarea id="field_${fieldName}" name="values[${fieldName}]" rows="2" style="width:100%;background:#0d1117;color:#fff;border:1px solid #30363d;padding:7px 10px;border-radius:6px;font-size:0.84rem">${val !== null ? escapeHtml(val) : ''}</textarea>
            ` : `
                <input type="text" id="field_${fieldName}" name="values[${fieldName}]" value="${val !== null ? escapeHtml(val) : ''}" style="width:100%;background:#0d1117;color:#fff;border:1px solid #30363d;padding:7px 10px;border-radius:6px;font-size:0.84rem">
            `}
        `;
        container.appendChild(wrap);
        if (isNull) {
            toggleNullField(fieldName);
        }
    });

    document.getElementById('editRowModal').style.display = 'flex';
}

function toggleNullField(fieldName) {
    const chk = document.getElementById('null_chk_' + fieldName);
    const inp = document.getElementById('field_' + fieldName);
    if (chk.checked) {
        inp.dataset.prevVal = inp.value;
        inp.value = '__NULL__';
        inp.disabled = true;
        inp.style.opacity = '0.5';
    } else {
        inp.value = inp.dataset.prevVal || '';
        inp.disabled = false;
        inp.style.opacity = '1';
    }
}

function closeEditModal() {
    document.getElementById('editRowModal').style.display = 'none';
}

function openInsertModal() {
    document.getElementById('insertRowModal').style.display = 'flex';
}

function closeInsertModal() {
    document.getElementById('insertRowModal').style.display = 'none';
}

function setSqlQuery(sql) {
    document.getElementById('sqlQueryInput').value = sql;
}

function escapeHtml(str) {
    if (typeof str !== 'string') return String(str);
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}
</script>

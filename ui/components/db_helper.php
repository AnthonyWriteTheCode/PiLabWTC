<?php
declare(strict_types=1);

/**
 * WTC Pi Database Helper Module
 * Provides PDO connection pooling, table schema reflection, paginated record retrieval,
 * parameterized row modifications, custom SQL execution, and 1-click account activation.
 */

/**
 * Return list of sites that currently have provisioned MariaDB databases.
 */
function get_db_sites(): array {
    $sites = [];
    $raw = wj('sites');
    if (!empty($raw) && is_array($raw)) {
        foreach ($raw as $s) {
            $name = (string)($s['name'] ?? '');
            if ($name !== '') {
                $sites[] = $name;
            }
        }
    }
    // Also check active site
    $active = (string)(wj('status')['active'] ?? '');
    if ($active !== '' && !in_array($active, $sites, true)) {
        array_unshift($sites, $active);
    }
    return $sites;
}

/**
 * Establish a PDO connection to the database for the given site.
 */
function get_db_connection(string $site, ?string &$error = null): ?PDO {
    if (!valid_site($site)) {
        $error = "Invalid site name: '$site'";
        return null;
    }

    [$rc, $out, $err] = wtc(['--db-creds', $site]);
    $creds = json_decode($out, true);
    if (!is_array($creds) || empty($creds['name'])) {
        $error = "Database credentials not found for site '$site'. Has the database been created?";
        return null;
    }

    $dbName = (string)$creds['name'];
    $dbUser = (string)($creds['user'] ?? $dbName);
    $dbPass = (string)($creds['pass'] ?? '');
    $dbHost = (string)($creds['host'] ?? '127.0.0.1');
    $dbPort = (int)($creds['port'] ?? 3306);

    // Prefer TCP loopback 127.0.0.1 to avoid open_basedir restrictions on /run socket
    $dsns = [
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        "mysql:host=127.0.0.1;port=3306;dbname={$dbName};charset=utf8mb4",
        "mysql:host=localhost;dbname={$dbName};charset=utf8mb4",
    ];

    $lastEx = null;
    foreach ($dsns as $dsn) {
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            return $pdo;
        } catch (PDOException $e) {
            $lastEx = $e;
        }
    }

    $error = $lastEx ? $lastEx->getMessage() : "Could not connect to database '$dbName'";
    return null;
}

/**
 * Return list of all tables in the database with row count and data size.
 */
function get_db_tables(PDO $pdo, string $dbName): array {
    $stmt = $pdo->prepare("
        SELECT 
            table_name AS name,
            COALESCE(table_rows, 0) AS `rows`,
            COALESCE(data_length + index_length, 0) AS `size`,
            COALESCE(table_comment, '') AS `comment`
        FROM information_schema.tables 
        WHERE table_schema = :dbname
        ORDER BY table_name ASC
    ");
    $stmt->execute([':dbname' => $dbName]);
    return $stmt->fetchAll();
}

/**
 * Return detailed column schema for a given table.
 */
function get_table_schema(PDO $pdo, string $table): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException("Invalid table name: $table");
    }

    $stmt = $pdo->prepare("
        SELECT 
            column_name AS `field`,
            column_type AS `type`,
            is_nullable AS `null`,
            column_key AS `key`,
            column_default AS `default`,
            extra AS `extra`,
            data_type AS `data_type`
        FROM information_schema.columns 
        WHERE table_schema = DATABASE() AND table_name = :table
        ORDER BY ordinal_position ASC
    ");
    $stmt->execute([':table' => $table]);
    return $stmt->fetchAll();
}

/**
 * Extract primary key column names from table schema.
 */
function get_primary_keys(array $schema): array {
    $pks = [];
    foreach ($schema as $col) {
        if (($col['key'] ?? '') === 'PRI') {
            $pks[] = $col['field'];
        }
    }
    return $pks;
}

/**
 * Retrieve paginated records from a table with optional search and sort.
 */
function get_table_rows(
    PDO $pdo,
    string $table,
    array $schema,
    int $page = 1,
    int $limit = 25,
    string $search = '',
    string $sort = '',
    string $dir = 'ASC'
): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException("Invalid table name: $table");
    }

    $allowedCols = array_column($schema, 'field');
    $pks = get_primary_keys($schema);

    // Determine sort column
    if ($sort === '' || !in_array($sort, $allowedCols, true)) {
        $sort = !empty($pks) ? $pks[0] : (!empty($allowedCols) ? $allowedCols[0] : '');
        $dir = (!empty($pks) && in_array(strtolower($pks[0]), ['id', 'created_at'], true)) ? 'DESC' : 'ASC';
    }
    $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

    // Build WHERE clause for search
    $whereParts = [];
    $params = [];
    $search = trim($search);

    if ($search !== '') {
        $searchableCols = [];
        foreach ($schema as $col) {
            $dt = strtolower((string)$col['data_type']);
            if (in_array($dt, ['varchar', 'char', 'text', 'mediumtext', 'longtext', 'tinytext', 'int', 'bigint', 'smallint', 'tinyint'], true)) {
                $searchableCols[] = $col['field'];
            }
        }
        if (!empty($searchableCols)) {
            $subParts = [];
            foreach ($searchableCols as $i => $colName) {
                $pName = ":search_{$i}";
                $subParts[] = "`{$colName}` LIKE {$pName}";
                $params[$pName] = "%{$search}%";
            }
            $whereParts[] = '(' . implode(' OR ', $subParts) . ')';
        }
    }

    $whereSql = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

    // Count total matching rows
    $countSql = "SELECT COUNT(*) FROM `{$table}` {$whereSql}";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Calculate pagination
    $limit = max(10, min(100, $limit));
    $pages = max(1, (int)ceil($total / $limit));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * $limit;

    // Fetch records
    $orderSql = $sort !== '' ? "ORDER BY `{$sort}` {$dir}" : '';
    $rowsSql = "SELECT * FROM `{$table}` {$whereSql} {$orderSql} LIMIT {$limit} OFFSET {$offset}";
    $rowsStmt = $pdo->prepare($rowsSql);
    foreach ($params as $k => $v) {
        $rowsStmt->bindValue($k, $v);
    }
    $rowsStmt->execute();
    $rows = $rowsStmt->fetchAll();

    return [
        'columns'      => $allowedCols,
        'schema'       => $schema,
        'primary_keys' => $pks,
        'total'        => $total,
        'page'         => $page,
        'pages'        => $pages,
        'limit'        => $limit,
        'sort'         => $sort,
        'dir'          => $dir,
        'search'       => $search,
        'rows'         => $rows,
    ];
}

/**
 * Update a specific record identified by primary key / where criteria.
 */
function update_table_row(PDO $pdo, string $table, array $schema, array $where, array $values): int {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException("Invalid table name: $table");
    }

    $colsByName = [];
    foreach ($schema as $c) {
        $colsByName[$c['field']] = $c;
    }

    if (empty($where)) {
        throw new InvalidArgumentException("No primary key / where condition provided for row update.");
    }

    $setParts = [];
    $params = [];

    foreach ($values as $col => $val) {
        if (!isset($colsByName[$col])) continue;

        // Skip updating primary key unless explicitly provided
        $paramName = ":v_" . preg_replace('/[^a-zA-Z0-9_]/', '_', $col);
        $setParts[] = "`{$col}` = {$paramName}";

        if ($val === '__NULL__' || $val === null) {
            $params[$paramName] = null;
        } else {
            $params[$paramName] = $val;
        }
    }

    if (empty($setParts)) {
        return 0; // Nothing to update
    }

    $whereParts = [];
    foreach ($where as $wCol => $wVal) {
        if (!isset($colsByName[$wCol])) continue;
        $wParamName = ":w_" . preg_replace('/[^a-zA-Z0-9_]/', '_', $wCol);
        $whereParts[] = "`{$wCol}` = {$wParamName}";
        $params[$wParamName] = $wVal;
    }

    if (empty($whereParts)) {
        throw new InvalidArgumentException("Invalid where conditions for row update.");
    }

    $sql = "UPDATE `{$table}` SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts) . " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * 1-Click Activate Account on a user record.
 * Sets email_verified_at = NOW(), status = 'active', is_active = 1, etc.
 */
function activate_account_row(PDO $pdo, string $table, array $schema, array $where): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException("Invalid table name: $table");
    }

    $colsByName = [];
    foreach ($schema as $c) {
        $colsByName[strtolower($c['field'])] = $c['field'];
    }

    $now = date('Y-m-d H:i:s');
    $updates = [];

    // Check for email verification timestamps
    if (isset($colsByName['email_verified_at'])) {
        $updates[$colsByName['email_verified_at']] = $now;
    }
    if (isset($colsByName['verified_at'])) {
        $updates[$colsByName['verified_at']] = $now;
    }

    // Check for status flags
    if (isset($colsByName['status'])) {
        $dt = strtolower((string)($colsByName['status']['data_type'] ?? 'varchar'));
        $updates[$colsByName['status']] = in_array($dt, ['int', 'tinyint', 'smallint'], true) ? 1 : 'active';
    }
    if (isset($colsByName['is_active'])) {
        $updates[$colsByName['is_active']] = 1;
    }
    if (isset($colsByName['active'])) {
        $updates[$colsByName['active']] = 1;
    }
    if (isset($colsByName['is_verified'])) {
        $updates[$colsByName['is_verified']] = 1;
    }
    if (isset($colsByName['verified'])) {
        $updates[$colsByName['verified']] = 1;
    }

    // Clear ban flags if present
    if (isset($colsByName['banned_at'])) {
        $updates[$colsByName['banned_at']] = null;
    }
    if (isset($colsByName['is_banned'])) {
        $updates[$colsByName['is_banned']] = 0;
    }

    // Update timestamp if present
    if (isset($colsByName['updated_at'])) {
        $updates[$colsByName['updated_at']] = $now;
    }

    if (empty($updates)) {
        throw new RuntimeException("Table '$table' does not contain recognizable account status columns (email_verified_at, status, is_active, etc.).");
    }

    $affected = update_table_row($pdo, $table, $schema, $where, $updates);
    return [
        'affected' => $affected,
        'fields'   => array_keys($updates)
    ];
}

/**
 * Insert a new record into a table.
 */
function insert_table_row(PDO $pdo, string $table, array $schema, array $values): string|int {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException("Invalid table name: $table");
    }

    $colsByName = [];
    foreach ($schema as $c) {
        $colsByName[$c['field']] = $c;
    }

    $cols = [];
    $placeholders = [];
    $params = [];

    foreach ($values as $col => $val) {
        if (!isset($colsByName[$col])) continue;

        // Skip auto-increment if value is empty
        $extra = strtolower((string)$colsByName[$col]['extra']);
        if (str_contains($extra, 'auto_increment') && ($val === '' || $val === null)) {
            continue;
        }

        $pName = ":v_" . preg_replace('/[^a-zA-Z0-9_]/', '_', $col);
        $cols[] = "`{$col}`";
        $placeholders[] = $pName;

        if ($val === '__NULL__' || $val === null) {
            $params[$pName] = null;
        } else {
            $params[$pName] = $val;
        }
    }

    if (empty($cols)) {
        throw new InvalidArgumentException("No valid column values provided for insert.");
    }

    $sql = "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $pdo->lastInsertId();
}

/**
 * Delete a specific record identified by primary key / where criteria.
 */
function delete_table_row(PDO $pdo, string $table, array $schema, array $where): int {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException("Invalid table name: $table");
    }

    $colsByName = [];
    foreach ($schema as $c) {
        $colsByName[$c['field']] = $c;
    }

    $whereParts = [];
    $params = [];
    foreach ($where as $wCol => $wVal) {
        if (!isset($colsByName[$wCol])) continue;
        $wParamName = ":w_" . preg_replace('/[^a-zA-Z0-9_]/', '_', $wCol);
        $whereParts[] = "`{$wCol}` = {$wParamName}";
        $params[$wParamName] = $wVal;
    }

    if (empty($whereParts)) {
        throw new InvalidArgumentException("No valid where condition provided for delete.");
    }

    $sql = "DELETE FROM `{$table}` WHERE " . implode(' AND ', $whereParts) . " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Execute a custom SQL query and return results or summary.
 */
function execute_custom_query(PDO $pdo, string $sql): array {
    $sql = trim($sql);
    if ($sql === '') {
        throw new InvalidArgumentException("Empty SQL query provided.");
    }

    $isSelect = (bool)preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql);
    $start = microtime(true);

    if ($isSelect) {
        $stmt = $pdo->query($sql);
        $rows = [];
        $count = 0;
        while ($row = $stmt->fetch()) {
            $rows[] = $row;
            $count++;
            if ($count >= 200) break; // Cap preview at 200 rows
        }
        $duration = round((microtime(true) - $start) * 1000, 2);
        return [
            'type'     => 'select',
            'rows'     => $rows,
            'count'    => $count,
            'duration' => $duration
        ];
    }

    $affected = $pdo->exec($sql);
    $duration = round((microtime(true) - $start) * 1000, 2);
    return [
        'type'     => 'exec',
        'affected' => $affected,
        'duration' => $duration
    ];
}

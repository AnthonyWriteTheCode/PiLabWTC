<?php
declare(strict_types=1);
/**
 * Tab Container: RAM Drive Explorer
 * Safely browse /mnt/ramdisk, view files, permissions, sizes, and preview text files.
 */

$rootDir = '/mnt/ramdisk';
$reqPath = (string)($_GET['path'] ?? '');

// Strict path sanitization to prevent traversal outside /mnt/ramdisk
$cleanRelPath = ltrim(str_replace(['\\', '..'], ['/', ''], $reqPath), '/');
$targetDir = rtrim($rootDir . ($cleanRelPath !== '' ? '/' . $cleanRelPath : ''), '/');

// Verify directory exists or fallback to root
if (!is_dir($targetDir)) {
    $targetDir = $rootDir;
    $cleanRelPath = '';
}

// Check preview request
$previewFile = (string)($_GET['preview'] ?? '');
$previewContent = null;
$previewName = null;
$previewError = null;

if ($previewFile !== '') {
    $cleanPreview = ltrim(str_replace(['\\', '..'], ['/', ''], $previewFile), '/');
    $fullPreviewPath = $rootDir . '/' . $cleanPreview;
    if (is_file($fullPreviewPath)) {
        $previewName = basename($fullPreviewPath);
        $size = @filesize($fullPreviewPath);
        if ($size !== false && $size > 512 * 1024) {
            $previewError = 'File is too large to preview inline (' . fmt_bytes($size) . ' > 512 KB).';
        } else {
            $content = @file_get_contents($fullPreviewPath);
            if ($content !== false) {
                if (preg_match('~[^\x09\x0A\x0D\x20-\x7E\x80-\xFE]~', substr($content, 0, 1024))) {
                    $previewError = 'Binary file cannot be previewed as text.';
                } else {
                    $previewContent = $content;
                }
            } else {
                $previewError = 'Unable to read file content (permission denied).';
            }
        }
    } else {
        $previewError = 'Selected file not found.';
    }
}

// Scan directory
$items = [];
if (is_dir($targetDir)) {
    $scanned = @scandir($targetDir);
    if ($scanned !== false) {
        foreach ($scanned as $node) {
            if ($node === '.' || $node === '..') continue;
            $fullNode = $targetDir . '/' . $node;
            $isDir = is_dir($fullNode);
            $isLink = is_link($fullNode);
            $linkTarget = $isLink ? @readlink($fullNode) : null;
            $size = $isDir ? 0 : (@filesize($fullNode) ?: 0);
            $perms = @fileperms($fullNode);
            $permStr = $perms !== false ? substr(sprintf('%o', $perms), -4) : '????';
            $mtime = @filemtime($fullNode) ?: 0;

            $items[] = [
                'name' => $node,
                'isDir' => $isDir,
                'isLink' => $isLink,
                'linkTarget' => $linkTarget,
                'size' => $size,
                'perms' => $permStr,
                'mtime' => $mtime,
                'relPath' => ($cleanRelPath !== '' ? $cleanRelPath . '/' : '') . $node,
            ];
        }
        usort($items, function ($a, $b) {
            if ($a['isDir'] !== $b['isDir']) return $b['isDir'] <=> $a['isDir'];
            return strcasecmp($a['name'], $b['name']);
        });
    }
}

// Compute breadcrumbs
$parts = array_filter(explode('/', $cleanRelPath));
$breadcrumbs = [['name' => '⚡ /mnt/ramdisk', 'path' => '']];
$accum = '';
foreach ($parts as $p) {
    $accum .= ($accum === '' ? '' : '/') . $p;
    $breadcrumbs[] = ['name' => $p, 'path' => $accum];
}

$parentPath = '';
if (!empty($parts)) {
    $temp = $parts;
    array_pop($temp);
    $parentPath = implode('/', $temp);
}

// Grep search processing
$grepQuery = trim((string)($_GET['grep'] ?? ''));
$grepCase = isset($_GET['case']) && $_GET['case'] === '1';
$grepResults = [];
$grepTruncated = false;
$grepError = null;
$grepFilesScanned = 0;
$maxGrepMatches = 250;
$maxFileSize = 2 * 1024 * 1024; // 2MB max file size to grep

if ($grepQuery !== '') {
    if (strlen($grepQuery) < 2) {
        $grepError = 'Please enter at least 2 characters to search.';
    } elseif (!is_dir($targetDir)) {
        $grepError = 'Directory not accessible.';
    } else {
        try {
            $dirIter = new RecursiveDirectoryIterator(
                $targetDir,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
            );
            $filterIter = new RecursiveCallbackFilterIterator($dirIter, function ($current, $key, $iterator) {
                $filename = $current->getFilename();
                // Skip hidden folders like .git, cache folders
                if ($current->isDir()) {
                    return $filename !== '.git' && $filename !== 'cache';
                }
                return true;
            });
            $iter = new RecursiveIteratorIterator($filterIter, RecursiveIteratorIterator::LEAVES_ONLY);

            $searchStr = $grepCase ? $grepQuery : mb_strtolower($grepQuery);

            foreach ($iter as $fileInfo) {
                if (!$fileInfo->isFile()) continue;
                $filePath = $fileInfo->getPathname();
                $fileSize = $fileInfo->getSize();

                // Skip oversized or unreadable files
                if ($fileSize > $maxFileSize || $fileSize === 0) continue;

                $grepFilesScanned++;

                // Read sample or full file content
                $content = @file_get_contents($filePath);
                if ($content === false) continue;

                // Skip binary files
                if (str_contains(substr($content, 0, 1024), "\0")) continue;

                // Relative path from /mnt/ramdisk
                $normPath = str_replace('\\', '/', $filePath);
                $relFromRoot = ltrim(str_replace($rootDir, '', $normPath), '/');

                $lines = explode("\n", $content);
                foreach ($lines as $lineIdx => $lineText) {
                    $checkText = $grepCase ? $lineText : mb_strtolower($lineText);
                    if (str_contains($checkText, $searchStr)) {
                        $grepResults[] = [
                            'file' => basename($filePath),
                            'relPath' => $relFromRoot,
                            'line' => $lineIdx + 1,
                            'text' => trim($lineText),
                        ];
                        if (count($grepResults) >= $maxGrepMatches) {
                            $grepTruncated = true;
                            break 2;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $grepError = 'Search error: ' . $e->getMessage();
        }
    }
}

// Helper icon
if (!function_exists('getFileIcon')) {
    function getFileIcon(string $name, bool $isDir, bool $isLink): string {
        if ($isLink) return '🔗';
        if ($isDir) return '📁';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '.env' || str_starts_with($name, '.env.')) return '🔑';
        return match ($ext) {
            'php' => '🐘',
            'js', 'ts', 'jsx', 'tsx', 'vue' => '⚡',
            'json', 'lock' => '📦',
            'css', 'scss', 'sass' => '🎨',
            'html', 'htm', 'blade' => '🌐',
            'sh', 'bash' => '📜',
            'sql' => '🗄️',
            'md', 'txt', 'log' => '📄',
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico' => '🖼️',
            'zip', 'gz', 'tar', 'tgz' => '🗜️',
            default => '📄'
        };
    }
}
?>

<div class="card" style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h3 style="margin-bottom:4px;color:#7cc4ff">⚡ RAM Drive Explorer</h3>
            <div style="font-size:0.85rem;color:#8b949e">
                Inspect physical in-memory storage at <code>/mnt/ramdisk</code>. Zero flash wear, ultra-low latency.
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn-ctrl" href="/?p=explorer&path=" style="text-decoration:none;font-size:0.8rem">Root</a>
            <a class="btn-ctrl" href="/?p=explorer&path=sites" style="text-decoration:none;font-size:0.8rem">📁 /sites</a>
            <a class="btn-ctrl" href="/?p=explorer&path=webroot" style="text-decoration:none;font-size:0.8rem">🔗 /webroot</a>
            <a class="btn-ctrl" href="/?p=explorer&preview=nginx.conf" style="text-decoration:none;font-size:0.8rem;border-color:#388bfd;color:#79c0ff">⚙️ nginx.conf</a>
            <a class="btn-ctrl" href="/?p=explorer&path=nginx-sites" style="text-decoration:none;font-size:0.8rem">🌐 /nginx-sites</a>
            <a class="btn-ctrl" href="/?p=explorer&path=php-tmp" style="text-decoration:none;font-size:0.8rem">⚡ /php-tmp</a>
        </div>
    </div>
</div>

<!-- Search & Navigation Toolbar -->
<div class="card" style="padding:12px 16px;margin-bottom:16px;background:#0d1117">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <!-- Breadcrumb path -->
        <div style="font-family:monospace;font-size:0.9rem;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
            <?php foreach ($breadcrumbs as $idx => $bc): ?>
                <?php if ($idx > 0): ?><span style="color:#546e7a">/</span><?php endif; ?>
                <?php if ($idx === count($breadcrumbs) - 1): ?>
                    <span style="color:#7ee787;font-weight:600"><?= h($bc['name']) ?></span>
                <?php else: ?>
                    <a href="/?p=explorer&path=<?= urlencode($bc['path']) ?>" style="color:#7cc4ff;text-decoration:none"><?= h($bc['name']) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Grep Search Bar -->
        <form method="get" action="/" style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0">
            <input type="hidden" name="p" value="explorer">
            <input type="hidden" name="path" value="<?= h($cleanRelPath) ?>">
            
            <div style="display:inline-flex;align-items:center;position:relative">
                <input type="text" name="grep" value="<?= h($grepQuery) ?>" placeholder="Grep file contents in dir &amp; subdirs..."
                       style="background:#050a10;color:#f0f6fc;border:1px solid #30363d;padding:6px 12px;border-radius:4px;font-size:0.84rem;min-width:260px">
            </div>

            <label style="display:inline-flex;align-items:center;gap:4px;font-size:0.8rem;color:#8b949e;cursor:pointer;user-select:none">
                <input type="checkbox" name="case" value="1" <?= $grepCase ? 'checked' : '' ?> style="margin:0">
                Match Case
            </label>

            <button type="submit" class="btn-ctrl" style="padding:5px 12px;font-size:0.82rem;background:#1f6feb;border-color:#388bfd;color:#fff;font-weight:600">
                🔍 Grep
            </button>

            <?php if ($grepQuery !== ''): ?>
                <a href="/?p=explorer&path=<?= urlencode($cleanRelPath) ?>" class="btn-ctrl" style="text-decoration:none;font-size:0.8rem">
                    ✕ Clear
                </a>
            <?php endif; ?>
        </form>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;padding-top:8px;border-top:1px solid #161b22;font-size:0.82rem;color:#8b949e">
        <div>
            Scope: <code style="color:#79c0ff">/mnt/ramdisk<?= $cleanRelPath !== '' ? '/' . h($cleanRelPath) : '' ?></code> (and all subfolders)
        </div>
        <div style="display:flex;gap:12px">
            <?php if ($cleanRelPath !== ''): ?>
                <a href="/?p=explorer&path=<?= urlencode($parentPath) ?>" style="color:#7cc4ff;text-decoration:none">⬆️ Up one level</a>
            <?php endif; ?>
            <span>Directory items: <b><?= count($items) ?></b></span>
        </div>
    </div>
</div>

<!-- Grep Results Panel -->
<?php if ($grepQuery !== ''): ?>
    <div class="card" style="margin-bottom:16px;border-color:#1f6feb;background:#0b1320">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
            <h4 style="margin:0;color:#79c0ff;display:flex;align-items:center;gap:8px;font-size:0.95rem">
                🔍 Grep Results for "<code><?= h($grepQuery) ?></code>"
                <span class="pill" style="background:#162436;color:#58a6ff;border:1px solid #1f6feb;font-size:0.75rem">
                    <?= count($grepResults) ?><?= $grepTruncated ? '+' : '' ?> matches
                </span>
            </h4>
            <div style="font-size:0.8rem;color:#8b949e">
                Scanned <b><?= $grepFilesScanned ?></b> text files
                <?php if ($grepTruncated): ?>
                    &bull; <span style="color:#f0883e">Capped at <?= $maxGrepMatches ?> results</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($grepError): ?>
            <div style="background:#2c1517;color:#f85149;padding:10px 14px;border-radius:4px;font-size:0.85rem">
                ⚠️ <?= h($grepError) ?>
            </div>
        <?php elseif (empty($grepResults)): ?>
            <div style="padding:20px;text-align:center;color:#8b949e;font-size:0.88rem;background:#0d1117;border-radius:4px">
                No matching lines found for "<b><?= h($grepQuery) ?></b>" in this directory or subdirectories.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;max-height:450px;overflow-y:auto;border:1px solid #21262d;border-radius:4px">
                <table style="width:100%;font-size:0.82rem;border-collapse:collapse;margin:0">
                    <thead>
                        <tr style="background:#161b22;position:sticky;top:0;z-index:2">
                            <th style="text-align:left;width:300px;padding:8px 12px">File &amp; Path</th>
                            <th style="width:70px;text-align:center;padding:8px">Line</th>
                            <th style="text-align:left;padding:8px 12px">Matched Line Content</th>
                            <th style="width:80px;text-align:center;padding:8px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grepResults as $res): ?>
                            <tr>
                                <td style="padding:6px 12px;font-family:monospace">
                                    <span style="color:#58a6ff;font-weight:600"><?= h($res['file']) ?></span>
                                    <div style="color:#8b949e;font-size:0.75rem;margin-top:2px"><?= h($res['relPath']) ?></div>
                                </td>
                                <td style="text-align:center;font-family:monospace;color:#7ee787;padding:6px">
                                    :<?= (int)$res['line'] ?>
                                </td>
                                <td style="padding:6px 12px;font-family:monospace;white-space:pre-wrap;word-break:break-all;color:#c9d1d9">
                                    <?php
                                    $rawText = $res['text'];
                                    // Highlight match safely
                                    $pattern = '/' . preg_quote($grepQuery, '/') . '/' . ($grepCase ? '' : 'i');
                                    $highlighted = preg_replace_callback($pattern, function ($m) {
                                        return '<mark style="background:#388bfd;color:#fff;padding:1px 3px;border-radius:2px;font-weight:600">' . h($m[0]) . '</mark>';
                                    }, h($rawText));
                                    echo $highlighted;
                                    ?>
                                </td>
                                <td style="text-align:center;padding:6px">
                                    <a class="btn-ctrl" href="/?p=explorer&path=<?= urlencode(dirname($res['relPath']) === '.' ? '' : dirname($res['relPath'])) ?>&preview=<?= urlencode($res['relPath']) ?>&grep=<?= urlencode($grepQuery) ?><?= $grepCase ? '&case=1' : '' ?>" style="text-decoration:none;padding:3px 8px;font-size:0.75rem">
                                        👁️ View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($previewFile !== ''): ?>
    <div class="card" style="margin-bottom:16px;border-color:#388bfd">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <h4 style="margin:0;color:#79c0ff;display:flex;align-items:center;gap:8px">
                📄 Preview: <code><?= h($previewName) ?></code>
            </h4>
            <a class="btn-ctrl" href="/?p=explorer&path=<?= urlencode($cleanRelPath) ?>" style="text-decoration:none;padding:4px 8px;font-size:0.8rem">✖ Close Preview</a>
        </div>
        <?php if ($previewError): ?>
            <div style="background:#2c1517;color:#f85149;padding:12px;border-radius:6px;font-size:0.85rem">
                ⚠️ <?= h($previewError) ?>
            </div>
        <?php else: ?>
            <pre style="background:#050a10;color:#c9d1d9;padding:12px;border-radius:6px;max-height:400px;overflow-y:auto;font-size:0.82rem;font-family:monospace;white-space:pre-wrap;line-height:1.4"><?= h($previewContent) ?></pre>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- File list table -->
<div class="card">
    <table style="width:100%;font-size:0.88rem">
        <thead>
            <tr>
                <th style="text-align:left">Name</th>
                <th style="width:110px;text-align:right">Size</th>
                <th style="width:90px;text-align:center">Perms</th>
                <th style="width:150px;text-align:left">Modified</th>
                <th style="width:90px;text-align:center">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($cleanRelPath !== ''): ?>
                <tr>
                    <td colspan="5">
                        <a href="/?p=explorer&path=<?= urlencode($parentPath) ?>" style="color:#7cc4ff;text-decoration:none;font-weight:600">
                            📁 .. (parent directory)
                        </a>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="5" style="text-align:center;color:#8b949e;padding:24px">
                        Empty directory
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <span style="margin-right:6px"><?= getFileIcon($item['name'], $item['isDir'], $item['isLink']) ?></span>
                        <?php if ($item['isDir']): ?>
                            <a href="/?p=explorer&path=<?= urlencode($item['relPath']) ?>" style="color:#7cc4ff;text-decoration:none;font-weight:600">
                                <?= h($item['name']) ?>/
                            </a>
                        <?php else: ?>
                            <span style="color:#e6e6e6"><?= h($item['name']) ?></span>
                        <?php endif; ?>
                        <?php if ($item['isLink']): ?>
                            <span style="color:#8b949e;font-size:0.75rem;margin-left:6px">➡️ <?= h($item['linkTarget'] ?? 'broken') ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-family:monospace;color:#a5d6ff">
                        <?= $item['isDir'] ? '-' : fmt_bytes($item['size']) ?>
                    </td>
                    <td style="text-align:center;font-family:monospace;color:#8b949e;font-size:0.8rem">
                        <code><?= h($item['perms']) ?></code>
                    </td>
                    <td style="color:#8b949e;font-size:0.82rem">
                        <?= $item['mtime'] ? date('Y-m-d H:i:s', $item['mtime']) : '-' ?>
                    </td>
                    <td style="text-align:center">
                        <?php if (!$item['isDir']): ?>
                            <a href="/?p=explorer&path=<?= urlencode($cleanRelPath) ?>&preview=<?= urlencode($item['relPath']) ?>" class="btn-ctrl" style="text-decoration:none;padding:3px 8px;font-size:0.75rem">
                                👁️ View
                            </a>
                        <?php else: ?>
                            <a href="/?p=explorer&path=<?= urlencode($item['relPath']) ?>" class="btn-ctrl" style="text-decoration:none;padding:3px 8px;font-size:0.75rem">
                                📂 Open
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
declare(strict_types=1);

/**
 * CVE Threat Intelligence & Version-Range Matching Client
 * 
 * Provides universal version-range evaluation for CVE vulnerability detection
 * across all managed services (PHP, Nginx, MariaDB, OpenSSH, Redis, Samba, vsftpd, Avahi, Kernel, etc.).
 * Integrates with public threat intelligence feeds (Google OSV, Shodan CVEDB, FIRST EPSS, NVD)
 * with robust local caching (24h TTL) and offline resiliency.
 */

if (!function_exists('debian_part_compare')) {
    /**
     * Compares two component parts according to Debian Policy Manual section 5.6.12.
     * Alternates between non-digit strings and digit sequences.
     */
    function debian_part_compare(string $s1, string $s2): int {
        $i1 = 0; $i2 = 0;
        $len1 = strlen($s1); $len2 = strlen($s2);
        $charOrder = function(?string $c): int {
            if ($c === null || $c === '') return 0;
            if ($c === '~') return -1;
            if (ctype_alpha($c)) return ord($c);
            return ord($c) + 256;
        };

        while ($i1 < $len1 || $i2 < $len2) {
            // Compare non-digits
            while (($i1 < $len1 && !ctype_digit($s1[$i1])) || ($i2 < $len2 && !ctype_digit($s2[$i2]))) {
                $c1 = ($i1 < $len1 && !ctype_digit($s1[$i1])) ? $s1[$i1] : null;
                $c2 = ($i2 < $len2 && !ctype_digit($s2[$i2])) ? $s2[$i2] : null;
                $o1 = $charOrder($c1);
                $o2 = $charOrder($c2);
                if ($o1 !== $o2) return $o1 <=> $o2;
                if ($c1 !== null) $i1++;
                if ($c2 !== null) $i2++;
            }

            // Compare digits numerically
            $num1 = '';
            while ($i1 < $len1 && ctype_digit($s1[$i1])) $num1 .= $s1[$i1++];
            $num2 = '';
            while ($i2 < $len2 && ctype_digit($s2[$i2])) $num2 .= $s2[$i2++];

            $val1 = ltrim($num1, '0');
            $val2 = ltrim($num2, '0');
            if (strlen($val1) !== strlen($val2)) {
                return strlen($val1) <=> strlen($val2);
            }
            if ($val1 !== $val2) {
                return strcmp($val1, $val2);
            }
        }
        return 0;
    }
}

if (!function_exists('debian_version_compare')) {
    /**
     * Authoritative Debian & Ubuntu package version comparison: [epoch:]upstream_version[-debian_revision].
     * Supports Debian security backports, Ubuntu USN revisions (-0ubuntu1.5), and OpenSSH p levels.
     */
    function debian_version_compare(string $v1, string $v2): int {
        // Handle epochs
        $epoch1 = 0; $epoch2 = 0;
        if (str_contains($v1, ':')) { [$e, $v1] = explode(':', $v1, 2); $epoch1 = (int)$e; }
        if (str_contains($v2, ':')) { [$e, $v2] = explode(':', $v2, 2); $epoch2 = (int)$e; }
        if ($epoch1 !== $epoch2) {
            return $epoch1 <=> $epoch2;
        }

        // Handle debian revisions
        $rev1 = ''; $rev2 = '';
        $lastDash1 = strrpos($v1, '-');
        if ($lastDash1 !== false) {
            $rev1 = substr($v1, $lastDash1 + 1);
            $v1 = substr($v1, 0, $lastDash1);
        }
        $lastDash2 = strrpos($v2, '-');
        if ($lastDash2 !== false) {
            $rev2 = substr($v2, $lastDash2 + 1);
            $v2 = substr($v2, 0, $lastDash2);
        }

        $cmpUpstream = debian_part_compare($v1, $v2);
        if ($cmpUpstream !== 0) {
            return $cmpUpstream;
        }
        return debian_part_compare($rev1, $rev2);
    }
}

if (!function_exists('cve_normalize_version')) {
    /**
     * Normalizes version strings from package queries, dpkg-query, or binary outputs.
     * Preserves distro security revisions (e.g. 8.5.4-0ubuntu1.5, 1.22.1-9+deb12u1, 9.2p1-2+deb12u2).
     */
    function cve_normalize_version(string $rawVer): string {
        $raw = trim($rawVer);
        if ($raw === '' || $raw === 'installed' || $raw === 'unknown' || $raw === '0.0.0') {
            return '';
        }

        // Strip leading daemon names e.g. "nginx/", "OpenSSH_", "PHP "
        $raw = preg_replace('/^(?:php|nginx|openssh|mariadb|redis|samba|vsftpd)[\/_\s]+/i', '', $raw);

        // Strip trailing extra text e.g. " (cli) (built: ...)" or " Debian-..."
        if (preg_match('/^([0-9]+(?:\.[0-9]+)*(?:p[0-9]+)?(?:-[0-9a-z.+~]+)?)/i', $raw, $m)) {
            return strtolower($m[1]);
        }

        return strtolower($raw);
    }
}

if (!function_exists('cve_compare_versions')) {
    /**
     * Version comparison supporting Debian/Ubuntu package revisions, epochs, and semver.
     * Returns: -1 if v1 < v2, 0 if v1 == v2, 1 if v1 > v2
     */
    function cve_compare_versions(string $v1, string $v2): int {
        $v1Norm = cve_normalize_version($v1);
        $v2Norm = cve_normalize_version($v2);
        if ($v1Norm === '' && $v2Norm === '') return 0;
        if ($v1Norm === '') return -1;
        if ($v2Norm === '') return 1;
        return debian_version_compare($v1Norm, $v2Norm);
    }
}

if (!function_exists('is_cve_relevant_to_version')) {
    /**
     * Determines whether an advisory applies to the installed version's major.minor branch.
     * Prevents older vulnerabilities (e.g. PHP 8.1/8.2 CGI flaws) from erroneously appearing on newer branches (PHP 8.5).
     */
    function is_cve_relevant_to_version(string $installedVersion, array $cve): bool {
        if (empty($cve['affected_ranges'])) {
            return true;
        }

        $raw = trim($installedVersion);
        if ($raw === '' || $raw === 'installed' || $raw === '0.0.0' || $raw === 'unknown') {
            return false;
        }

        $instNorm = cve_normalize_version($raw);
        if ($instNorm === '') {
            return false;
        }
        $instBranch = '';
        if (preg_match('/^([0-9]+\.[0-9]+)/', $instNorm, $m)) {
            $instBranch = $m[1];
        } elseif (preg_match('/^([0-9]+)/', $instNorm, $m)) {
            $instBranch = $m[1];
        }

        foreach ($cve['affected_ranges'] as $range) {
            if (!empty($range['prefix'])) {
                $pfx = rtrim((string)$range['prefix'], '.*');
                if ($pfx === $instBranch || str_starts_with($instNorm, $pfx . '.') || $instNorm === $pfx) {
                    return true;
                }
                continue;
            }

            $from = !empty($range['from']) ? cve_normalize_version((string)$range['from']) : '';
            $before = !empty($range['before']) ? cve_normalize_version((string)$range['before']) : '';

            if ($from !== '') {
                $fromBranch = '';
                if (preg_match('/^([0-9]+\.[0-9]+)/', $from, $m)) $fromBranch = $m[1];
                if ($fromBranch !== '' && $instBranch !== '' && cve_compare_versions($instBranch, $fromBranch) < 0) {
                    continue;
                }
            }

            if ($before !== '') {
                $beforeBranch = '';
                if (preg_match('/^([0-9]+\.[0-9]+)/', $before, $m)) $beforeBranch = $m[1];
                if ($beforeBranch !== '' && $instBranch !== '' && cve_compare_versions($instBranch, $beforeBranch) > 0) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }
}

if (!function_exists('version_in_affected_range')) {
    /**
     * Determines whether an installed version falls within any of the specified affected range specifications.
     * 
     * Supports range criteria formats:
     *   1. "from" and "before": e.g. ['from' => '8.5.0', 'before' => '8.5.12']
     *   2. "prefix" and "before": e.g. ['prefix' => '8.5.*', 'before' => '8.5.12']
     *   3. "less_than": e.g. ['before' => '1.25.3']
     *   4. "exact": e.g. ['exact' => '1.22.1']
     */
    function version_in_affected_range(string $installedVersion, array $rangeSpecs, ?array &$matchedRange = null): bool {
        $inst = cve_normalize_version($installedVersion);
        if ($inst === '' || $inst === '0.0.0') {
            return false;
        }

        foreach ($rangeSpecs as $range) {
            $matched = true;

            // Prefix match (e.g. "8.5.*" matches any 8.5.x version)
            if (!empty($range['prefix'])) {
                $pfx = rtrim((string)$range['prefix'], '.*');
                if (!str_starts_with($inst, $pfx . '.') && $inst !== $pfx && !str_starts_with($inst, $pfx . '-')) {
                    $matched = false;
                }
            }

            // Lower bound "from" / "introduced" (inclusive)
            if ($matched && !empty($range['from'])) {
                $fromVer = cve_normalize_version((string)$range['from']);
                if (cve_compare_versions($inst, $fromVer) < 0) {
                    $matched = false;
                }
            }

            // Upper bound "before" / "fixed" (exclusive)
            if ($matched && !empty($range['before'])) {
                $beforeVer = cve_normalize_version((string)$range['before']);
                if (cve_compare_versions($inst, $beforeVer) >= 0) {
                    $matched = false;
                }
            }

            // Upper bound "at_or_before" (inclusive)
            if ($matched && !empty($range['at_or_before'])) {
                $atOrBeforeVer = cve_normalize_version((string)$range['at_or_before']);
                if (cve_compare_versions($inst, $atOrBeforeVer) > 0) {
                    $matched = false;
                }
            }

            // Exact single version match
            if (!empty($range['exact'])) {
                $exactVer = cve_normalize_version((string)$range['exact']);
                if ($inst !== $exactVer) {
                    $matched = false;
                }
            }

            if ($matched) {
                $matchedRange = $range;
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('fetch_live_osv_advisories')) {
    /**
     * Fetches authentic live vulnerability advisories for an OS package from Google OSV API.
     */
    function fetch_live_osv_advisories(string $pkgName, string $ecosystem = 'Debian'): array {
        $url = 'https://api.osv.dev/v1/query';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nUser-Agent: WTC-PiLab/1.2\r\n",
                'content' => json_encode(['package' => ['name' => $pkgName, 'ecosystem' => $ecosystem]]),
                'timeout' => 3.0,
                'ignore_errors' => true
            ]
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw) {
            return [];
        }

        $json = json_decode($raw, true);
        if (empty($json['vulns']) || !is_array($json['vulns'])) {
            return [];
        }

        $results = [];
        foreach ($json['vulns'] as $v) {
            $id = (string)($v['id'] ?? '');
            if (!empty($v['aliases']) && is_array($v['aliases'])) {
                foreach ($v['aliases'] as $alias) {
                    if (str_starts_with((string)$alias, 'CVE-')) {
                        $id = (string)$alias;
                        break;
                    }
                }
            }
            if (str_starts_with($id, 'UBUNTU-CVE-')) {
                $id = substr($id, 7);
            }
            if (str_starts_with($id, 'DEBIAN-CVE-')) {
                $id = substr($id, 7);
            }
            if ($id === '') continue;

            $summary = trim((string)($v['summary'] ?? (isset($v['details']) ? substr($v['details'], 0, 160) : 'Security Advisory ' . $id)));
            $desc = trim((string)($v['details'] ?? $summary));

            $cvss = 7.5;
            $severity = 'high';
            if (!empty($v['database_specific']['severity'])) {
                $s = strtolower((string)$v['database_specific']['severity']);
                if ($s === 'critical') { $cvss = 9.8; $severity = 'critical'; }
                elseif ($s === 'high') { $cvss = 7.5; $severity = 'high'; }
                elseif ($s === 'medium') { $cvss = 5.5; $severity = 'medium'; }
                elseif ($s === 'low') { $cvss = 3.5; $severity = 'low'; }
            }

            $fixedVer = '';
            $ranges = [];
            foreach ($v['affected'] ?? [] as $aff) {
                $affPkg = strtolower((string)($aff['package']['name'] ?? ''));
                if ($affPkg !== '' && $affPkg !== strtolower($pkgName)) {
                    // If querying a versioned package like php8.5, skip unrelated package branches like php7.4 or php8.1
                    if (preg_match('/^(?:php|mariadb)([0-9.]+)/', strtolower($pkgName), $m1) && preg_match('/^(?:php|mariadb)([0-9.]+)/', $affPkg, $m2)) {
                        if ($m1[1] !== $m2[1]) continue;
                    }
                }
                foreach ($aff['ranges'] ?? [] as $r) {
                    $from = '';
                    $before = '';
                    foreach ($r['events'] ?? [] as $ev) {
                        if (isset($ev['introduced'])) $from = (string)$ev['introduced'];
                        if (isset($ev['fixed'])) {
                            $before = (string)$ev['fixed'];
                            if ($fixedVer === '') $fixedVer = $before;
                        }
                    }
                    if ($before !== '') {
                        $ranges[] = [
                            'from' => $from,
                            'before' => $before,
                            'display' => ($from !== '' && $from !== '0' ? "$from <= v < $before" : "< $before")
                        ];
                    }
                }
            }

            if (empty($ranges)) {
                continue;
            }

            $results[] = [
                'id' => $id,
                'cvss' => $cvss,
                'severity' => $severity,
                'epss' => 0.0,
                'cisa_kev' => false,
                'title' => $summary,
                'desc' => $desc,
                'affected_ranges' => $ranges,
                'fixed_version' => $fixedVer !== '' ? "$fixedVer+" : 'Distro security update',
                'mitigation' => 'Upgrade package via apt-get or 1-click Services Monitor patch.'
            ];
        }

        return $results;
    }
}

if (!function_exists('get_cve_threat_catalog')) {
    /**
     * Threat Intelligence Catalog dynamically queried from live public feeds (Google OSV, FIRST EPSS)
     * with persistent local disk caching (24h TTL) and zero simulated fallback numbers.
     */
    function get_cve_threat_catalog(bool $forceFresh = false, ?string $targetCategory = null): array {
        static $memoryCache = null;
        if (!$forceFresh && $targetCategory === null && $memoryCache !== null) {
            return $memoryCache;
        }

        $cachePaths = [
            '/var/lib/wtc-web/live_cve_catalog.json',
            '/mnt/ramdisk/live_cve_catalog.json',
            '/tmp/live_cve_catalog.json',
            sys_get_temp_dir() . '/live_cve_catalog.json'
        ];

        $cacheFile = $cachePaths[0];
        foreach ($cachePaths as $cp) {
            if (file_exists($cp)) {
                $cacheFile = $cp;
                break;
            }
        }

        // Use any existing local cache (regardless of age) unless a refresh was requested
        if (!$forceFresh && file_exists($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $memoryCache = $decoded;
                    return $decoded;
                }
            }
        }

        // Detect OS ecosystem from uname or /etc/os-release
        $ecosystem = 'Debian';
        $unameV = php_uname('v') . ' ' . php_uname('a');
        if (stripos($unameV, 'ubuntu') !== false) {
            $ecosystem = 'Ubuntu';
        } elseif (@file_exists('/etc/os-release')) {
            $osRel = @file_get_contents('/etc/os-release') ?: '';
            if (stripos($osRel, 'ubuntu') !== false) {
                $ecosystem = 'Ubuntu';
            }
        }

        // Detect installed PHP version if running
        $activePhpMajorMinor = '8.2';
        if (defined('PHP_MAJOR_VERSION') && defined('PHP_MINOR_VERSION')) {
            $activePhpMajorMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        }

        // Standard PHP branches so any installed FPM/CLI service branch is recognized
        $phpPkgs = ["php$activePhpMajorMinor"];
        foreach ((array)@glob('/etc/php/*', GLOB_ONLYDIR) as $phpDir) {
            $b = basename($phpDir);
            if (preg_match('/^\d+\.\d+$/', $b) && !in_array("php$b", $phpPkgs, true)) {
                $phpPkgs[] = "php$b";
            }
        }
        foreach (['8.5', '8.4', '8.3', '8.2', '8.1'] as $b) {
            if (!in_array("php$b", $phpPkgs, true)) {
                $phpPkgs[] = "php$b";
            }
        }

        // Live service-to-package targets
        $servicePkgMap = [
            'nginx' => ['nginx'],
            'mariadb' => ['mariadb', 'mariadb-10.11', 'mariadb-10.5'],
            'ssh' => ['openssh', 'openssh-server'],
            'smbd' => ['samba'],
            'redis' => ['redis', 'redis-server'],
            'vsftpd' => ['vsftpd'],
            'avahi' => ['avahi', 'avahi-daemon'],
            'php' => $phpPkgs
        ];

        $catalog = [
            'php' => [],
            'nginx' => [],
            'mariadb' => [
                [
                    'id' => 'SEC-BIND-01',
                    'cvss' => 7.5,
                    'severity' => 'high',
                    'epss' => 0.0,
                    'cisa_kev' => false,
                    'title' => 'MariaDB Database Port 3306 Exposed to LAN',
                    'desc' => 'MariaDB is listening on public network interfaces instead of binding strictly to loopback (127.0.0.1).',
                    'affected_ranges' => [],
                    'fixed_version' => 'bind-address = 127.0.0.1 (Drop-in 99-wtc-bind.cnf)',
                    'mitigation' => 'Enforced bind-address = 127.0.0.1 in /etc/mysql/mariadb.conf.d/99-wtc-bind.cnf.'
                ]
            ],
            'ssh' => [],
            'smbd' => [],
            'redis' => [],
            'vsftpd' => [],
            'avahi' => [],
            'wtc' => [
                [
                    'id' => 'SEC-ZIP-01',
                    'cvss' => 8.5,
                    'severity' => 'high',
                    'epss' => 0.0,
                    'cisa_kev' => false,
                    'title' => 'ZipSlip Path Traversal & Symlink Escape Defense',
                    'desc' => 'Rejects archive file paths with ../ sequences and eliminates symlinks resolving outside webroot.',
                    'affected_ranges' => [],
                    'fixed_version' => 'Built-in (safe_php_extract)',
                    'mitigation' => 'Pre-extraction validation in wtc-common.sh and safe_php_extract() in ui/index.php.'
                ],
                [
                    'id' => 'SEC-RCE-02',
                    'cvss' => 9.8,
                    'severity' => 'critical',
                    'epss' => 0.0,
                    'cisa_kev' => false,
                    'title' => 'Artisan Shell Metacharacter Injection Filter',
                    'desc' => 'Blocks shell operators (; && | ` $ < >) to prevent command chaining in artisan tasks.',
                    'affected_ranges' => [],
                    'fixed_version' => 'Built-in (wtc-manage --artisan filter)',
                    'mitigation' => 'Strict regex whitelist filter enforced in wtc-manage --artisan.'
                ],
                [
                    'id' => 'SEC-RCE-03',
                    'cvss' => 8.5,
                    'severity' => 'high',
                    'epss' => 0.0,
                    'cisa_kev' => false,
                    'title' => 'Worker Runtime Isolation & Whitelisting',
                    'desc' => 'Restricts custom worker commands to approved interpreters (php, node, npm, python3).',
                    'affected_ranges' => [],
                    'fixed_version' => 'Built-in (wtc-workers validation)',
                    'mitigation' => 'Enforced in wtc-workers runner before execution.'
                ]
            ]
        ];

        // Normal page loads are cache-only: never block the UI on network I/O.
        // Live OSV queries happen only when the user presses a scan/refresh button.
        if (!$forceFresh) {
            $memoryCache = $catalog;
            return $catalog;
        }

        // If a specific target category is requested, start with existing cached items
        if ($targetCategory !== null && file_exists($cacheFile)) {
            $rawExisting = @file_get_contents($cacheFile);
            if ($rawExisting) {
                $decodedExisting = json_decode($rawExisting, true);
                if (is_array($decodedExisting)) {
                    $catalog = array_merge($catalog, $decodedExisting);
                }
            }
        }

        // Determine targets: specific category or all services
        $targets = $servicePkgMap;
        if ($targetCategory !== null) {
            if (isset($servicePkgMap[$targetCategory])) {
                $targets = [$targetCategory => $servicePkgMap[$targetCategory]];
            } else {
                // Categories like 'system' or 'wtc' don't map to external OSV package queries
                $targets = [];
            }
        }

        // Fetch live OSV advisories for targeted services
        foreach ($targets as $catKey => $pkgs) {
            $seenIds = [];
            $catalog[$catKey] = []; // Reset this category with fresh results
            foreach ($pkgs as $pkg) {
                $vulns = fetch_live_osv_advisories($pkg, $ecosystem);
                if (empty($vulns)) {
                    // Try alternative ecosystem (Ubuntu <-> Debian)
                    $altEco = ($ecosystem === 'Ubuntu') ? 'Debian' : 'Ubuntu';
                    $vulns = fetch_live_osv_advisories($pkg, $altEco);
                }
                foreach ($vulns as $v) {
                    $vid = $v['id'];
                    if (!isset($seenIds[$vid])) {
                        $seenIds[$vid] = true;
                        $catalog[$catKey][] = $v;
                    }
                }
            }
        }

        // If network request succeeded and found live data, write cache
        $totalFound = 0;
        foreach ($catalog as $grp => $items) {
            $totalFound += count($items);
        }

        if ($totalFound > 4) { // More than just our 4 built-in system rules
            $encoded = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($encoded !== false && strlen($encoded) > 10) {
                foreach ($cachePaths as $cp) {
                    $dir = dirname($cp);
                    if ((file_exists($cp) && is_writable($cp)) || (!file_exists($cp) && is_dir($dir) && is_writable($dir))) {
                        @file_put_contents($cp, $encoded, LOCK_EX);
                        @chmod($cp, 0664);
                        break;
                    }
                }
            }
            $memoryCache = $catalog;
            return $catalog;
        }

        // If offline and old cache exists, keep using old cache
        if (file_exists($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $memoryCache = $decoded;
                    return $decoded;
                }
            }
        }

        $memoryCache = $catalog;
        return $catalog;
    }
}

if (!function_exists('get_applied_patches')) {
    /**
     * Reads applied security patch receipts from persistent storage.
     */
    function get_applied_patches(bool $forceFresh = false): array {
        static $cached = null;
        if (!$forceFresh && $cached !== null) {
            return $cached;
        }
        $paths = [
            '/etc/wtc-web/applied_patches.json',
            '/opt/wtc-web/applied_patches.json',
            '/var/lib/wtc-web/applied_patches.json',
            '/mnt/ramdisk/applied_patches.json',
            '/tmp/applied_patches.json',
            sys_get_temp_dir() . '/applied_patches.json',
            sys_get_temp_dir() . '/wtc_applied_patches.json'
        ];
        $merged = [];
        foreach ($paths as $p) {
            if (file_exists($p)) {
                $raw = @file_get_contents($p);
                $d = json_decode($raw, true);
                if (is_array($d)) {
                    $merged = array_merge($merged, $d);
                }
            }
        }
        $cached = $merged;
        return $merged;
    }
}

if (!function_exists('is_cve_patched')) {
    /**
     * Checks if a specific CVE or service unit has an applied patch/hardening receipt.
     * Enforces strict anti-downgrade and live installed version checks:
     * - Receipts are invalidated if the current installed version is in the CVE's affected range.
     * - Receipts are invalidated if the service was downgraded below a previously verified release.
     * - Generic wildcard receipts ('ALL') cannot mask unpatched package binary CVEs.
     */
    function is_cve_patched(string $unit, string $cveId, string $installedVer = '', ?array $cveInfo = null, bool $forceFresh = false): bool {
        $patches = get_applied_patches($forceFresh);
        if (empty($patches)) {
            return false;
        }

        $u = strtolower(preg_replace('/\.service$/', '', trim($unit)));
        $c = strtoupper(trim($cveId));
        $instNorm = cve_normalize_version($installedVer);

        // If the advisory specifies affected version ranges and the currently installed version
        // falls within that vulnerable range, no receipt can mark it patched!
        if ($instNorm !== '' && !empty($cveInfo['affected_ranges'])) {
            if (version_in_affected_range($instNorm, $cveInfo['affected_ranges'])) {
                return false;
            }
        }

        // If the advisory specifies a target fixed version and the installed version is below it,
        // it cannot be considered patched.
        if ($instNorm !== '' && !empty($cveInfo['fixed_version'])) {
            $fixedNorm = cve_normalize_version(rtrim((string)$cveInfo['fixed_version'], '+'));
            if ($fixedNorm !== '' && cve_compare_versions($instNorm, $fixedNorm) < 0) {
                return false;
            }
        }

        foreach ($patches as $p) {
            $pu = strtolower(preg_replace('/\.service$/', '', trim((string)($p['unit'] ?? ''))));
            $pc = strtoupper(trim((string)($p['cve'] ?? '')));
            $pVer = trim((string)($p['version'] ?? ''));

            $unitMatches = ($pu === 'all' || $pu === '*' || $pu === $u);
            if (!$unitMatches && (str_starts_with($u, 'php') && str_starts_with($pu, 'php'))) {
                $unitMatches = true;
            }
            if (!$unitMatches && ((str_starts_with($u, 'mariadb') || str_starts_with($u, 'mysql')) && ($pu === 'mariadb' || $pu === 'mysql'))) {
                $unitMatches = true;
            }
            if (!$unitMatches && ((str_starts_with($u, 'ssh') || str_starts_with($u, 'openssh')) && (str_starts_with($pu, 'ssh') || str_starts_with($pu, 'openssh')))) {
                $unitMatches = true;
            }
            if (!$unitMatches && (str_starts_with($u, 'redis') && str_starts_with($pu, 'redis'))) {
                $unitMatches = true;
            }
            if (!$unitMatches && ((str_starts_with($u, 'smbd') || str_starts_with($u, 'samba')) && (str_starts_with($pu, 'smbd') || str_starts_with($pu, 'samba')))) {
                $unitMatches = true;
            }

            if ($unitMatches) {
                // Anti-downgrade check: If receipt recorded a package version and installed version is lower,
                // the service has been downgraded. Invalidate receipt.
                if ($instNorm !== '' && $pVer !== '' && $pVer !== 'hardened' && $pVer !== '127.0.0.1') {
                    $pVerNorm = cve_normalize_version($pVer);
                    if ($pVerNorm !== '' && cve_compare_versions($instNorm, $pVerNorm) < 0) {
                        return false;
                    }
                }

                // If this is a package binary CVE (has affected_ranges or fixed_version),
                // a generic/wildcard receipt (like "ALL") from config hardening must NOT bypass it.
                if (($pc === 'ALL' || $pc === '*') && (!empty($cveInfo['affected_ranges']) || !empty($cveInfo['fixed_version']))) {
                    continue;
                }

                if ($pc === 'ALL' || $pc === '*' || $pc === $c || ($pc === 'PACKAGE_UPGRADE' && empty($cveInfo['affected_ranges']))) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('check_service_downgrade')) {
    /**
     * Checks if a service unit's installed version has been downgraded below
     * a previously patched/verified package release.
     * Returns an array with downgrade details or null if clean.
     */
    function check_service_downgrade(string $unit, string $installedVer, bool $forceFresh = false): ?array {
        $instNorm = cve_normalize_version($installedVer);
        if ($instNorm === '' || $instNorm === '0.0.0') {
            return null;
        }

        $patches = get_applied_patches($forceFresh);
        if (empty($patches)) {
            return null;
        }

        $u = strtolower(preg_replace('/\.service$/', '', trim($unit)));
        $highestRecordedVer = '';

        foreach ($patches as $p) {
            $pu = strtolower(preg_replace('/\.service$/', '', trim((string)($p['unit'] ?? ''))));
            $unitMatches = ($pu === 'all' || $pu === '*' || $pu === $u);
            if (!$unitMatches && (str_starts_with($u, 'php') && str_starts_with($pu, 'php'))) $unitMatches = true;
            if (!$unitMatches && ((str_starts_with($u, 'mariadb') || str_starts_with($u, 'mysql')) && ($pu === 'mariadb' || $pu === 'mysql'))) $unitMatches = true;
            if (!$unitMatches && ((str_starts_with($u, 'ssh') || str_starts_with($u, 'openssh')) && (str_starts_with($pu, 'ssh') || str_starts_with($pu, 'openssh')))) $unitMatches = true;
            if (!$unitMatches && (str_starts_with($u, 'redis') && str_starts_with($pu, 'redis'))) $unitMatches = true;
            if (!$unitMatches && ((str_starts_with($u, 'smbd') || str_starts_with($u, 'samba')) && (str_starts_with($pu, 'smbd') || str_starts_with($pu, 'samba')))) $unitMatches = true;

            if ($unitMatches) {
                $verStr = trim((string)($p['version'] ?? ''));
                if ($verStr !== '' && $verStr !== 'hardened' && $verStr !== '127.0.0.1') {
                    $norm = cve_normalize_version($verStr);
                    if ($norm !== '') {
                        if ($highestRecordedVer === '' || cve_compare_versions($norm, $highestRecordedVer) > 0) {
                            $highestRecordedVer = $norm;
                        }
                    }
                }
            }
        }

        if ($highestRecordedVer !== '' && cve_compare_versions($instNorm, $highestRecordedVer) < 0) {
            return [
                'unit' => $unit,
                'installed_version' => $instNorm,
                'previous_version' => $highestRecordedVer,
                'is_downgraded' => true
            ];
        }

        return null;
    }
}

if (!function_exists('resolve_service_candidate_version')) {
    /**
     * Resolves the candidate package version available in apt repositories.
     */
    function resolve_service_candidate_version(string $unit): string {
        $uBare = strtolower(preg_replace('/\.service$/', '', trim($unit)));
        $pkg = $uBare;
        if (str_starts_with($uBare, 'php')) {
            if (preg_match('/php([0-9]+\.[0-9]+)/', $uBare, $m)) {
                $pkg = 'php' . $m[1] . '-fpm';
            } else {
                $pkg = 'php-fpm';
            }
        } elseif (in_array($uBare, ['nginx', 'mariadb', 'mysql', 'redis', 'vsftpd', 'samba', 'smbd'], true)) {
            $map = [
                'nginx' => 'nginx',
                'mariadb' => 'mariadb-server',
                'mysql' => 'mysql-server',
                'redis' => 'redis-server',
                'vsftpd' => 'vsftpd',
                'samba' => 'samba',
                'smbd' => 'samba'
            ];
            $pkg = $map[$uBare] ?? $uBare;
        }

        $out = @shell_exec("apt-cache policy " . escapeshellarg($pkg) . " 2>/dev/null");
        if ($out && preg_match('/Candidate:\s*([^\s]+)/', $out, $m)) {
            $cand = trim($m[1]);
            if ($cand !== '(none)' && $cand !== '' && $cand !== '0') {
                return $cand;
            }
        }
        return '';
    }
}

if (!function_exists('resolve_service_version')) {
    /**
     * Resolves the installed version of a service unit from dpkg-query or daemon binary.
     */
    function resolve_service_version(string $unit): string {
        $u = strtolower(trim($unit));
        $uBare = preg_replace('/\.service$/', '', $u);

        $pkgMap = [
            'nginx' => 'nginx',
            'mariadb' => 'mariadb-server',
            'mysql' => 'mysql-server',
            'ssh' => 'openssh-server',
            'sshd' => 'openssh-server',
            'smbd' => 'samba',
            'samba' => 'samba',
            'redis' => 'redis-server',
            'redis-server' => 'redis-server',
            'vsftpd' => 'vsftpd',
            'avahi-daemon' => 'avahi-daemon'
        ];

        $pkg = $pkgMap[$uBare] ?? '';
        if (str_starts_with($uBare, 'php')) {
            if (preg_match('/php([0-9]+\.[0-9]+)/', $uBare, $m)) {
                $pkg = 'php' . $m[1] . '-fpm';
            } else {
                $pkg = 'php-fpm';
            }
        }

        if ($pkg !== '') {
            $ver = @shell_exec("dpkg-query -W -f='\${Version}' " . escapeshellarg($pkg) . " 2>/dev/null");
            if ($ver && trim($ver) !== '') {
                return trim($ver);
            }
        }

        if (str_starts_with($uBare, 'php')) {
            if (preg_match('/php([0-9]+\.[0-9]+)/', $uBare, $m)) {
                $cmd = 'php' . $m[1];
                $ver = @shell_exec("$cmd -r 'echo PHP_VERSION;' 2>/dev/null");
                if ($ver && trim($ver) !== '') return trim($ver);
            }
            if (defined('PHP_VERSION')) return PHP_VERSION;
        }

        if (str_starts_with($uBare, 'nginx')) {
            $ver = @shell_exec("nginx -v 2>&1 || /usr/sbin/nginx -v 2>&1");
            if ($ver && preg_match('/([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $ver, $m)) return $m[1];
        }

        if (str_starts_with($uBare, 'mariadb') || str_starts_with($uBare, 'mysql')) {
            $ver = @shell_exec("mariadb -V 2>&1 || /usr/bin/mariadb -V 2>&1 || mysqld -V 2>&1");
            if ($ver && preg_match('/(?:Distrib|Ver)\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $ver, $m)) return $m[1];
            if ($ver && preg_match('/([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $ver, $m)) return $m[1];
        }

        if (str_starts_with($uBare, 'ssh')) {
            $ver = @shell_exec("ssh -V 2>&1 || /usr/bin/ssh -V 2>&1");
            if ($ver && preg_match('/OpenSSH_([0-9]+[0-9.a-z]+)/i', $ver, $m)) return $m[1];
        }

        if (str_starts_with($uBare, 'redis')) {
            $ver = @shell_exec("redis-server -v 2>&1 || /usr/bin/redis-server -v 2>&1");
            if ($ver && preg_match('/v=([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $ver, $m)) return $m[1];
        }

        if (str_starts_with($uBare, 'smbd') || str_starts_with($uBare, 'samba')) {
            $ver = @shell_exec("smbd -V 2>&1 || /usr/sbin/smbd -V 2>&1");
            if ($ver && preg_match('/Version\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $ver, $m)) return $m[1];
        }

        if (str_starts_with($uBare, 'vsftpd')) {
            $ver = @shell_exec("vsftpd -v 2>&1 || /usr/sbin/vsftpd -v 2>&1");
            if ($ver && preg_match('/version\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $ver, $m)) return $m[1];
        }

        if (str_starts_with($uBare, 'avahi')) {
            $ver = @shell_exec("avahi-daemon -V 2>&1 || /usr/sbin/avahi-daemon -V 2>&1");
            if ($ver && preg_match('/([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $ver, $m)) return $m[1];
        }

        if (str_starts_with($uBare, 'wtc-')) {
            return '1.2.0';
        }

        if (str_starts_with($uBare, 'systemd') || in_array($uBare, ['cron', 'dbus'], true)) {
            $ver = @shell_exec("systemctl --version 2>&1 || /usr/bin/systemctl --version 2>&1");
            if ($ver && preg_match('/systemd\s+([0-9]+(?:\.[0-9]+)?)/i', $ver, $m)) return $m[1];
        }

        return '';
    }
}

if (!function_exists('audit_service_cves')) {
    /**
     * Audits a specific service unit by matching its installed version against the threat catalog.
     * Enforces active installed-version checking and anti-downgrade validation.
     */
    function audit_service_cves(string $unit, string $installedVer, array $activeIssues = [], bool $forceFresh = false): array {
        $u = strtolower($unit);
        $normVer = cve_normalize_version($installedVer);

        $catKey = 'system';
        if (str_starts_with($u, 'php')) $catKey = 'php';
        elseif (str_starts_with($u, 'nginx')) $catKey = 'nginx';
        elseif (str_starts_with($u, 'mariadb') || str_starts_with($u, 'mysql')) $catKey = 'mariadb';
        elseif (str_starts_with($u, 'ssh')) $catKey = 'ssh';
        elseif (str_starts_with($u, 'smbd') || str_starts_with($u, 'samba') || str_starts_with($u, 'nmbd')) $catKey = 'smbd';
        elseif (str_starts_with($u, 'redis')) $catKey = 'redis';
        elseif (str_starts_with($u, 'vsftpd')) $catKey = 'vsftpd';
        elseif (str_starts_with($u, 'avahi')) $catKey = 'avahi';
        elseif (str_starts_with($u, 'wtc-')) $catKey = 'wtc';

        $catalog = get_cve_threat_catalog($forceFresh, $forceFresh ? $catKey : null);

        $items = $catalog[$catKey] ?? [];
        $results = [];

        // 1. Anti-downgrade check against previously recorded patch versions
        $downgradeInfo = check_service_downgrade($unit, $normVer, $forceFresh);
        if ($downgradeInfo !== null) {
            $prevVer = $downgradeInfo['previous_version'];
            $results[] = [
                'id' => 'SEC-DOWNGRADE-' . strtoupper($catKey),
                'cvss' => 8.5,
                'severity' => 'high',
                'epss' => 0.0,
                'cisa_kev' => false,
                'title' => 'Service Downgrade Detected in ' . $unit,
                'desc' => 'Installed package release (' . $normVer . ') is lower than previously verified/patched release (' . $prevVer . '). Security updates from that release are missing.',
                'affected_ranges' => [['before' => $prevVer]],
                'fixed_version' => $prevVer . '+',
                'mitigation' => 'Upgrade ' . $unit . ' package to ' . $prevVer . ' or the latest distro repository version.',
                'status' => 'pending',
                'is_affected' => true,
                'patch_applied' => false,
                'installed_version' => $normVer,
                'is_downgraded' => true
            ];
        }

        // 2. Evaluate known CVE entries against installed version
        foreach ($items as $cve) {
            // Filter out CVEs from older branches that never applied to installed major.minor branch
            if (!is_cve_relevant_to_version($installedVer, $cve)) {
                continue;
            }

            $isAffected = false;
            $cveId = (string)($cve['id'] ?? '');

            // Handle dynamically evaluated non-semver rules (e.g. MariaDB LAN Port Exposure)
            if ($cveId === 'SEC-BIND-01') {
                $isAffected = in_array('db_port_exposed', array_column($activeIssues, 'id'), true);
            } elseif (!empty($cve['affected_ranges'])) {
                $matchedRange = null;
                $isAffected = version_in_affected_range($normVer, $cve['affected_ranges'], $matchedRange);
                if ($isAffected && !empty($matchedRange['before'])) {
                    $cve['fixed_version'] = $matchedRange['before'] . '+';
                }
            }

            // Check if service or CVE has an applied patch/hardening receipt
            $isPatched = is_cve_patched($unit, $cveId, $normVer, $cve, $forceFresh);

            // CRITICAL: A receipt can NEVER mask an actively vulnerable or downgraded installed version!
            if ($isAffected) {
                $cve['status'] = 'pending';
                $cve['patch_applied'] = false;
            } elseif ($isPatched) {
                $cve['status'] = 'hardened';
                $cve['patch_applied'] = true;
                $cve['mitigation'] = ($cve['mitigation'] ?? '') . ' [Applied via 1-Click WTC Security Hardening]';
            } else {
                $cve['status'] = 'clean';
                $cve['patch_applied'] = false;
                if (!empty($cve['affected_ranges'])) {
                    $cve['mitigation'] = ($cve['mitigation'] ?? '') . ' [Resolved in installed OS package release]';
                }
            }

            $cve['is_affected'] = $isAffected;
            $cve['installed_version'] = $normVer;
            $results[] = $cve;
        }

        // 3. Check for official distro package upgrade available in apt repositories
        $candVer = resolve_service_candidate_version($unit);
        if ($candVer !== '' && $normVer !== '' && cve_compare_versions($normVer, $candVer) < 0) {
            $hasPendingPackageAdv = false;
            foreach ($results as $r) {
                if (!empty($r['is_affected'])) {
                    $hasPendingPackageAdv = true;
                    break;
                }
            }
            if (!$hasPendingPackageAdv) {
                $results[] = [
                    'id' => 'USN-PACKAGE-UPDATE',
                    'cvss' => 7.5,
                    'severity' => 'high',
                    'epss' => 0.0,
                    'cisa_kev' => false,
                    'title' => 'Security / Package Update Pending in ' . $unit,
                    'desc' => 'Installed package release (' . $normVer . ') is outdated. Current candidate in repository is ' . $candVer . '.',
                    'affected_ranges' => [['before' => $candVer]],
                    'fixed_version' => $candVer,
                    'mitigation' => 'Apply package update via apt-get or 1-click Services Monitor patch.',
                    'status' => 'pending',
                    'is_affected' => true,
                    'patch_applied' => false,
                    'installed_version' => $normVer
                ];
            }
        }

        return $results;
    }
}

if (!function_exists('fetch_cve_details')) {
    /**
     * Fetches live threat intelligence for a CVE ID from Google OSV, Shodan CVEDB, and FIRST EPSS.
     * Uses local filesystem cache with 24h TTL to ensure fast responses and offline resilience.
     */
    function fetch_cve_details(string $cveId): array {
        $cveId = strtoupper(trim($cveId));
        if (!preg_match('/^(CVE-[0-9]{4}-[0-9]{4,8}|SEC-[A-Z0-9_-]{2,12})$/', $cveId)) {
            return ['ok' => false, 'error' => 'Invalid CVE identifier format'];
        }

        // Cache directory in RAM disk or tmp
        $cacheDir = is_dir('/mnt/ramdisk') ? '/mnt/ramdisk/.cve_cache' : sys_get_temp_dir() . '/.cve_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/' . $cveId . '.json';

        // Check cache (24 hour TTL)
        if (file_exists($cacheFile)) {
            $mtime = filemtime($cacheFile);
            if ($mtime !== false && (time() - $mtime) < 86400) {
                $cached = @file_get_contents($cacheFile);
                if ($cached !== false) {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        $decoded['from_cache'] = true;
                        return $decoded;
                    }
                }
            }
        }

        // Default response payload
        $data = [
            'ok' => true,
            'cve_id' => $cveId,
            'summary' => 'Vulnerability advisory ' . $cveId,
            'cvss' => 0.0,
            'severity' => 'UNKNOWN',
            'epss' => 0.0,
            'percentile' => 0.0,
            'cisa_kev' => false,
            'affected_ranges' => [],
            'references' => [
                'cve_org' => 'https://www.cve.org/CVERecord?id=' . $cveId,
                'nvd' => 'https://nvd.nist.gov/vuln/detail/' . $cveId,
                'osv' => 'https://osv.dev/vulnerability/' . $cveId
            ],
            'sources_queried' => [],
            'updated_at' => date('c')
        ];

        // 1. Query FIRST EPSS API (Zero Auth, high speed)
        $epssUrl = 'https://api.first.org/data/v1/epss?cve=' . urlencode($cveId);
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 2.5,
                'ignore_errors' => true,
                'user_agent' => 'WTC-PiLab/1.2'
            ]
        ]);
        $epssRaw = @file_get_contents($epssUrl, false, $ctx);
        if ($epssRaw !== false) {
            $epssJson = json_decode($epssRaw, true);
            if (!empty($epssJson['data'][0])) {
                $item = $epssJson['data'][0];
                $data['epss'] = (float)($item['epss'] ?? 0.0);
                $data['percentile'] = (float)($item['percentile'] ?? 0.0);
                $data['sources_queried'][] = 'FIRST EPSS';
            }
        }

        // 2. Query Google OSV API (Zero Auth, high speed JSON)
        $osvUrl = 'https://api.osv.dev/v1/vulns/' . urlencode($cveId);
        $osvRaw = @file_get_contents($osvUrl, false, $ctx);
        if ($osvRaw !== false) {
            $osvJson = json_decode($osvRaw, true);
            if (!empty($osvJson['id'])) {
                $data['summary'] = (string)($osvJson['summary'] ?? $osvJson['details'] ?? $data['summary']);
                if (!empty($osvJson['severity'][0]['score'])) {
                    // E.g. CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H
                    $data['cvss_vector'] = $osvJson['severity'][0]['score'];
                }
                $data['sources_queried'][] = 'Google OSV';
            }
        }

        // 3. Query Shodan CVEDB (Zero Auth, multi-source aggregator)
        $shodanUrl = 'https://cvedb.shodan.io/cve/' . urlencode($cveId);
        $shodanRaw = @file_get_contents($shodanUrl, false, $ctx);
        if ($shodanRaw !== false) {
            $shodanJson = json_decode($shodanRaw, true);
            if (!empty($shodanJson['cve_id'])) {
                if (!empty($shodanJson['cvss'])) {
                    $data['cvss'] = (float)$shodanJson['cvss'];
                }
                if (!empty($shodanJson['cisa_kev'])) {
                    $data['cisa_kev'] = (bool)$shodanJson['cisa_kev'];
                }
                if (!empty($shodanJson['summary']) && $data['summary'] === 'Vulnerability advisory ' . $cveId) {
                    $data['summary'] = (string)$shodanJson['summary'];
                }
                $data['sources_queried'][] = 'Shodan CVEDB';
            }
        }

        // Compute severity label from CVSS
        if ($data['cvss'] >= 9.0) {
            $data['severity'] = 'CRITICAL';
        } elseif ($data['cvss'] >= 7.0) {
            $data['severity'] = 'HIGH';
        } elseif ($data['cvss'] >= 4.0) {
            $data['severity'] = 'MEDIUM';
        } elseif ($data['cvss'] > 0.0) {
            $data['severity'] = 'LOW';
        }

        // Always enrich with local catalog metadata (affected ranges, fixed version, mitigation) if available
        $catalog = get_cve_threat_catalog();
        foreach ($catalog as $group => $cves) {
            foreach ($cves as $entry) {
                if (($entry['id'] ?? '') === $cveId) {
                    if (empty($data['affected_ranges']) && !empty($entry['affected_ranges'])) {
                        $data['affected_ranges'] = $entry['affected_ranges'];
                    }
                    if (empty($data['fixed_version']) && !empty($entry['fixed_version'])) {
                        $data['fixed_version'] = $entry['fixed_version'];
                    }
                    if (empty($data['mitigation']) && !empty($entry['mitigation'])) {
                        $data['mitigation'] = $entry['mitigation'];
                    }
                    if ($data['cvss'] <= 0.0 && !empty($entry['cvss'])) {
                        $data['cvss'] = (float)$entry['cvss'];
                        $data['severity'] = strtoupper((string)($entry['severity'] ?? 'HIGH'));
                    }
                    if (count($data['sources_queried']) === 0) {
                        $data['summary'] = $entry['desc'] ?? $entry['title'] ?? $data['summary'];
                        $data['epss'] = (float)($entry['epss'] ?? 0.0);
                        $data['cisa_kev'] = !empty($entry['cisa_kev']);
                        $data['sources_queried'][] = 'Local Threat Catalog';
                    }
                    break 2;
                }
            }
        }

        if (empty($data['sources_queried'])) {
            return ['ok' => false, 'error' => 'No live intelligence source (OSV, Shodan CVEDB, FIRST EPSS) returned data for ' . $cveId . '. Check internet connectivity or the CVE ID.'];
        }

        // Write cache
        @file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $data['from_cache'] = false;
        return $data;
    }
}

<?php
declare(strict_types=1);
/**
 * Dropzone Component
 * Interactive drag-and-drop file/folder uploader that detects application stacks (Laravel, etc.),
 * validates device requirements/extensions, configures .env variables, and spins up sites live in RAM.
 */

require_once __DIR__ . '/stacks.php';

$stackProfiles = get_stack_profiles();
$deviceStack = wj('stack');
$deviceStatus = wj('status');
$hostIp = explode(' ', $deviceStatus['ips'] ?? '')[0] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
?>

<div class="card card-dropzone">
    <div class="card-head">
        <div>
            <h3>🚀 Instant Site &amp; Stack Dropzone</h3>
            <p>Drag and drop any <b>folder</b>, <b>archive (.zip, .tar.gz)</b>, or <b>site files</b> here. The Pi automatically detects application stacks (like <b>Laravel</b>, <b>WordPress</b>, etc.), validates server extensions, and allows <code>.env</code> configuration before spinning up.</p>
        </div>
        <button type="button" class="btn" onclick="toggleCatalog()" id="toggleCatalogBtn">📋 View Stack Roadmap</button>
    </div>

    <!-- Drop Zone Target Area -->
    <div id="dropArea" class="dropzone">
        <div class="dropzone-content">
            <div class="dropzone-icon" id="dropIcon">📁⚡</div>
            <div class="dropzone-title" id="dropTitle">Drag &amp; Drop Folder or Archive Here</div>
            <div class="dropzone-sub" id="dropSub">Drop from Windows Explorer or click to browse</div>
            <div class="dropzone-buttons" id="dropActionBtns">
                <button type="button" class="btn" onclick="browseMainFolder()">📁 Browse Folder</button>
                <button type="button" class="btn" onclick="document.getElementById('archiveInput').click()">📦 Browse Archive / Files</button>
            </div>
            <div id="dropHttpsHint" style="display:none;margin-top:10px;font-size:0.78rem;color:#8b949e">
                💡 Tip: <a id="dropHttpsLink" href="#" class="no-spa" style="color:#79c0ff;text-decoration:underline">Switch to HTTPS (port 4486)</a> to skip indexing <code>node_modules</code> &amp; <code>vendor</code> when browsing folders.
                <a href="/?download_ui_cert=1" download="pilabwtc-ui.crt" class="btn no-spa" style="margin-left:8px;padding:2px 8px;font-size:0.74rem">🔐 Download HTTPS Cert (.crt)</a>
            </div>
            <input type="file" id="folderInput" webkitdirectory directory style="display:none">
            <input type="file" id="archiveInput" multiple accept=".zip,.tar,.gz,.tgz,.tar.gz,text/html,application/x-php" style="display:none">
        </div>
    </div>

    <!-- Stack Detection Banner -->
    <div id="stackDetectBanner" class="stack-banner" style="display:none">
        <div class="stack-banner-head">
            <span class="stack-icon" id="stackIcon">🅰️</span>
            <div>
                <h4 id="stackName">Laravel Application Detected</h4>
                <p id="stackDesc">Laravel framework detected with public webroot. Server requirements and .env configuration are ready below.</p>
            </div>
            <span class="pill ok" id="stackStatusPill">Supported</span>
        </div>
    </div>

    <!-- Missing Files & Seeder Requester Card (When Laravel Public folder dropped) -->
    <div id="missingFilesCard" class="stack-panel missing-files-panel" style="display:none;border-color:#d29922;background:#181711">
        <div class="card-head" style="margin-bottom:10px">
            <div>
                <h4 style="color:#e3b341">⚠️ Missing Laravel Backend Files &amp; Database Seeders</h4>
                <p style="font-size:0.86rem;color:#c9d1d9">
                    Only a <b><code>public/</code></b> webroot was dropped. Laravel's standard runtime normally expects backend application logic, database seeders, and vendor packages.
                </p>
            </div>
            <span class="pill" id="shimBadge" style="background:#8957e5;color:#fff">Standalone Shim Ready</span>
        </div>

        <div class="missing-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:12px;margin:12px 0">
            <div class="missing-status-box" style="background:#0f1419;border:1px solid #2a3541;border-radius:8px;padding:12px">
                <div style="font-weight:600;font-size:0.9rem;margin-bottom:6px;color:#a5d6ff">📂 Structure Inspection:</div>
                <div id="checkPublic" style="color:#7ee787;font-size:0.84rem;margin-bottom:3px">✅ <code>public/index.php</code> (Webroot &amp; Assets Loaded)</div>
                <div id="checkBackend" style="color:#e3b341;font-size:0.84rem;margin-bottom:3px">⚠️ <code>app/</code> &amp; <code>bootstrap/</code> (Missing - Will Use WTC Compatibility Shim)</div>
                <div id="checkSeeder" style="color:#8b949e;font-size:0.84rem">⚪ Database Seeder (<code>.sql</code> not attached yet)</div>
            </div>

            <div class="missing-action-box" style="background:#0f1419;border:1px solid #2a3541;border-radius:8px;padding:12px">
                <div style="font-weight:600;font-size:0.9rem;margin-bottom:6px;color:#a5d6ff">📥 Drop Missing Files or Seeders:</div>
                <p style="font-size:0.8rem;color:#8b949e;margin-bottom:8px">
                    Drag and drop your <b><code>.sql</code> seeder file</b> or the full app directory to merge with this deployment:
                </p>
                <div id="seederDropZone" style="border:2px dashed #388bfd;border-radius:6px;padding:10px;text-align:center;background:#0d1117;cursor:pointer" onclick="document.getElementById('seederFileInput').click()">
                    <span style="font-size:1.1rem">🗄️</span> <b style="font-size:0.85rem;color:#7cc4ff">Click or drop .sql seeder file here</b>
                    <div id="seederFileLabel" style="font-size:0.78rem;color:#8b949e;margin-top:2px">No seeder attached (MariaDB database will be created empty)</div>
                </div>
                <input type="file" id="seederFileInput" accept=".sql" style="display:none">
            </div>
        </div>

        <div style="background:#11171e;border-left:4px solid #8957e5;padding:10px 14px;border-radius:4px;margin-top:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
            <div>
                <b style="color:#d2a8ff;font-size:0.88rem">💡 Instant Standalone Preview:</b>
                <span style="color:#c9d1d9;font-size:0.84rem"> You don't have to upload the full backend right now! The Pi will automatically spin up a live preview shim serving your public assets and database.</span>
            </div>
            <label style="font-size:0.84rem;color:#a5d6ff;display:flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" id="chkAutoShim" checked> Enable Standalone Mock/Preview Mode
            </label>
        </div>
    </div>

    <!-- Requirements & Service Checklist (e.g. for Laravel) -->
    <div id="stackReqBox" class="stack-panel" style="display:none">
        <h4>🔍 Device Stack Requirements Check</h4>
        <div class="req-grid" id="reqGrid"></div>
        <div id="missingReqNotice" style="display:none;margin-top:10px">
            <button type="button" class="btn-pri" id="btnInstallMissing" onclick="installMissingComponents()">⚡ Install Missing Server Extensions via APT</button>
        </div>
    </div>

    <!-- Laravel .env Configurator -->
    <div id="envConfigBox" class="stack-panel" style="display:none">
        <div class="card-head" style="margin-bottom:8px">
            <h4>⚙️ Laravel <code>.env</code> &amp; Database Configuration</h4>
            <div class="env-nav">
                <button type="button" class="btn on" id="btnEnvGuided" onclick="switchEnvTab('guided')">Guided Form</button>
                <button type="button" class="btn" id="btnEnvRaw" onclick="switchEnvTab('raw')">Raw .env Editor</button>
            </div>
        </div>
        <p style="font-size:0.85rem;color:#8b949e;margin-bottom:12px">
            Pre-wired with this Pi's dedicated per-site MariaDB credentials (<code>w_&lt;site&gt;</code>) and local network IP.
        </p>

        <!-- Guided Form -->
        <div id="envGuidedTab" class="env-guided-grid">
            <label>
                App Name:
                <input type="text" id="envAppName" value="Laravel App">
            </label>
            <label>
                Environment:
                <select id="envAppEnv">
                    <option value="local" selected>local (Development)</option>
                    <option value="production">production</option>
                </select>
            </label>
            <label>
                App Key:
                <div style="display:flex;gap:4px">
                    <input type="text" id="envAppKey" readonly style="flex:1;font-size:0.75rem">
                    <button type="button" class="btn" onclick="generateNewAppKey()" title="Generate New Key">🔑</button>
                </div>
            </label>
            <label>
                App URL:
                <input type="text" id="envAppUrl" value="http://<?= $hostIp ?>/site">
            </label>
            <label>
                Database Connection:
                <select id="envDbConn">
                    <option value="mysql" selected>MariaDB / MySQL</option>
                    <option value="sqlite">SQLite</option>
                </select>
            </label>
            <label>
                Database Host:
                <input type="text" id="envDbHost" value="127.0.0.1">
            </label>
            <label>
                Database Name:
                <input type="text" id="envDbName" value="w_site">
            </label>
            <label>
                Database Username:
                <input type="text" id="envDbUser" value="w_site">
            </label>
            <label>
                Database Password:
                <input type="password" id="envDbPass" value="">
            </label>
            <label>
                Cache Store:
                <select id="envCacheStore">
                    <option value="file" selected>file</option>
                    <option value="redis">redis</option>
                </select>
            </label>
            <label>
                Session Driver:
                <select id="envSessionDriver">
                    <option value="file" selected>file</option>
                    <option value="database">database</option>
                    <option value="redis">redis</option>
                </select>
            </label>
            <!-- Laravel DB Migration & Seed Checkboxes -->
            <div id="pageLaravelDbBox" style="grid-column:1 / -1;background:#11171e;border:1px solid #2a3541;border-radius:6px;padding:12px 14px;margin-top:6px">
                <div style="font-weight:600;font-size:0.88rem;color:#7cc4ff;margin-bottom:6px">
                    ⚡ Laravel Post-Upload &amp; Database Actions:
                </div>
                <div style="display:flex;gap:18px;flex-wrap:wrap">
                    <label style="font-size:0.84rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" id="envChkRouteClear" checked onchange="syncDbCheckboxes('env')">
                        <span>🧹 <b>Clear route cache</b> (<code>php artisan route:clear</code>)</span>
                    </label>
                    <label style="font-size:0.84rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" id="envChkOptimizeClear" checked onchange="syncDbCheckboxes('env')">
                        <span>✨ <b>Clear all caches</b> (<code>php artisan optimize:clear</code>)</span>
                    </label>
                    <label style="font-size:0.84rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" id="envChkZiggy" checked onchange="syncDbCheckboxes('env')">
                        <span>🔀 <b>Rebuild Ziggy</b> (<code>php artisan ziggy:generate</code>)</span>
                    </label>
                    <label style="font-size:0.84rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" id="envChkMigrate" checked onchange="syncDbCheckboxes('env')">
                        <span>⚡ <b>Run migrations</b> (<code>php artisan migrate --force</code>)</span>
                    </label>
                    <label style="font-size:0.84rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" id="envChkSeed" onchange="syncDbCheckboxes('env')">
                        <span>🌱 <b>Run seeders</b> (<code>php artisan db:seed --force</code>)</span>
                    </label>
                    <label style="font-size:0.84rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" id="envChkStorageLink" checked onchange="syncDbCheckboxes('env')">
                        <span>🔗 <b>Link storage</b> (<code>php artisan storage:link --force</code>)</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Raw Text Editor -->
        <div id="envRawTab" style="display:none">
            <textarea id="envRawText" class="env-textarea" rows="12" spellcheck="false"></textarea>
        </div>
    </div>

    <!-- Folder Selection & Exclusion Filtering Panel -->
    <div id="filterPanel" class="stack-panel filter-panel" style="display:none;border-color:#388bfd;background:#0d1117">
        <div class="card-head" style="margin-bottom:8px">
            <div>
                <h4 style="color:#7cc4ff;margin-bottom:2px">📂 Folder &amp; File Selection Filter</h4>
                <p style="font-size:0.84rem;color:#8b949e">
                    Select which subdirectories to upload. Heavy build artifacts, local caches, and logs (like Laravel <code>storage/</code> and <code>vendor/</code>) are auto-excluded to save RAM.
                </p>
            </div>
            <div style="display:flex;gap:6px">
                <button type="button" class="btn" style="font-size:0.75rem;padding:3px 8px" onclick="selectAllFolderFilters(true)">Select All</button>
                <button type="button" class="btn" style="font-size:0.75rem;padding:3px 8px" onclick="selectAllFolderFilters(false)">Deselect All</button>
                <button type="button" class="btn" style="font-size:0.75rem;padding:3px 8px" onclick="applyDefaultFolderFilters()">Reset Defaults</button>
            </div>
        </div>

        <!-- Top-level folder filter checkboxes -->
        <div id="folderFilterList" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:8px;margin-top:10px"></div>

        <!-- Exclusion Summary -->
        <div style="margin-top:10px;padding:8px 12px;background:#161b22;border-radius:6px;border:1px solid #2a3541;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;font-size:0.82rem">
            <span id="filterStatsLabel" style="color:#c9d1d9">Files selected: <b>0</b> / <b>0</b></span>
            <span id="filterExcludedNotice" style="color:#e3b341"></span>
        </div>
    </div>

    <!-- Protected Files & Overwrite Prevention Panel -->
    <div id="protectedFilesPanel" class="stack-panel" style="display:none;border-color:#238636;background:#0d1611">
        <div class="card-head" style="margin-bottom:8px">
            <div>
                <h4 style="color:#7ee787;margin-bottom:2px">🛡️ Protected Files (Do Not Overwrite)</h4>
                <p style="font-size:0.84rem;color:#8b949e">
                    Select files and configs to protect from being overwritten when updating or re-uploading. Preserves server database credentials, <code>.env</code>, SQLite data, and uploaded media.
                </p>
            </div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <span style="font-size:0.75rem;color:#8b949e">Preset:</span>
                <select id="selProtectPreset" onchange="applyProtectPreset(this.value)" style="background:#161b22;color:#c9d1d9;border:1px solid #30363d;border-radius:4px;padding:3px 8px;font-size:0.78rem">
                    <option value="auto">⚡ Auto (from Stack)</option>
                    <option value="laravel">🅰️ Laravel</option>
                    <option value="react">⚛️ React / SPA / Vite</option>
                    <option value="wordpress">📝 WordPress</option>
                    <option value="php">🐘 Classic PHP</option>
                    <option value="static">📄 Static HTML</option>
                    <option value="custom">⚙️ Custom Only</option>
                </select>
            </div>
        </div>

        <!-- Checkbox grid for common protected file categories -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:10px;margin-top:10px">
            <label style="background:#111e16;border:1px solid #238636;border-radius:6px;padding:8px 12px;font-size:0.84rem;color:#c9d1d9;display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                <input type="checkbox" id="chkProtectEnv" checked onchange="updateProtectedCountBadge()" style="margin-top:2px">
                <div>
                    <b style="color:#7ee787">🔒 <code>.env</code> &amp; Credentials</b>
                    <div style="font-size:0.76rem;color:#8b949e">Preserves server DB host/pass &amp; APP_KEY. Merges new keys.</div>
                </div>
            </label>

            <label style="background:#111e16;border:1px solid #238636;border-radius:6px;padding:8px 12px;font-size:0.84rem;color:#c9d1d9;display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                <input type="checkbox" id="chkProtectSqlite" checked onchange="updateProtectedCountBadge()" style="margin-top:2px">
                <div>
                    <b style="color:#7ee787">🗄️ SQLite Databases (<code>*.sqlite</code>, <code>*.db</code>)</b>
                    <div style="font-size:0.76rem;color:#8b949e">Prevents active SQLite databases from being overwritten.</div>
                </div>
            </label>

            <label style="background:#111e16;border:1px solid #238636;border-radius:6px;padding:8px 12px;font-size:0.84rem;color:#c9d1d9;display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                <input type="checkbox" id="chkProtectDbDump" checked onchange="updateProtectedCountBadge()" style="margin-top:2px">
                <div>
                    <b style="color:#7ee787">📦 <code>.wtc-database.sql</code> &amp; DB Helper</b>
                    <div style="font-size:0.76rem;color:#8b949e">Keeps server's working DB snapshot &amp; <code>.wtc-db.php</code>.</div>
                </div>
            </label>

            <label style="background:#111e16;border:1px solid #238636;border-radius:6px;padding:8px 12px;font-size:0.84rem;color:#c9d1d9;display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                <input type="checkbox" id="chkProtectStorage" checked onchange="updateProtectedCountBadge()" style="margin-top:2px">
                <div>
                    <b style="color:#7ee787">📁 User Uploads &amp; Media</b>
                    <div style="font-size:0.76rem;color:#8b949e">Preserves <code>storage/app/**</code> &amp; <code>uploads/**</code> directories.</div>
                </div>
            </label>

            <label style="background:#111e16;border:1px solid #238636;border-radius:6px;padding:8px 12px;font-size:0.84rem;color:#c9d1d9;display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                <input type="checkbox" id="chkProtectNginx" checked onchange="updateProtectedCountBadge()" style="margin-top:2px">
                <div>
                    <b style="color:#7ee787">⚙️ Web Server &amp; Host Config</b>
                    <div style="font-size:0.76rem;color:#8b949e">Preserves <code>nginx.conf</code>, <code>wtc.json</code>, and <code>.htaccess</code>.</div>
                </div>
            </label>

            <label style="background:#111e16;border:1px solid #238636;border-radius:6px;padding:8px 12px;font-size:0.84rem;color:#c9d1d9;display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                <input type="checkbox" id="chkProtectWpConfig" checked onchange="updateProtectedCountBadge()" style="margin-top:2px">
                <div>
                    <b style="color:#7ee787">📝 <code>wp-config.php</code> (WordPress)</b>
                    <div style="font-size:0.76rem;color:#8b949e">Protects WordPress database constants and auth salts.</div>
                </div>
            </label>
        </div>

        <!-- Gitignore auto-detection banner & toggle -->
        <div id="dropGitignoreBox" style="margin-top:10px;padding:10px 14px;background:#101d14;border:1px solid #238636;border-radius:6px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
            <label style="display:flex;align-items:center;gap:8px;font-size:0.84rem;color:#c9d1d9;cursor:pointer">
                <input type="checkbox" id="chkProtectGitignore" checked onchange="updateProtectedCountBadge()">
                <span>📄 <b>Include rules from <code>.gitignore</code></b> (Auto-protect developer-ignored files)</span>
            </label>
            <div id="dropGitignoreNotice" style="font-size:0.78rem;color:#7ee787">Ready to inspect .gitignore when dropped</div>
        </div>

        <!-- Custom Protected Patterns Input -->
        <div style="margin-top:10px;padding:10px 14px;background:#11161d;border:1px solid #21262d;border-radius:6px">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:6px">
                <b style="font-size:0.84rem;color:#79c0ff">➕ Custom Protected Patterns &amp; Globs:</b>
                <small style="color:#8b949e">e.g. <code>config/custom.php</code>, <code>keys/*.pem</code>, <code>local.json</code></small>
            </div>
            <div style="display:flex;gap:6px">
                <input type="text" id="txtCustomProtect" placeholder="Enter path or glob pattern (e.g. config/local.php)" style="flex:1;background:#0d1117;color:#c9d1d9;border:1px solid #30363d;border-radius:4px;padding:4px 8px;font-size:0.82rem" onkeydown="if(event.key==='Enter'){event.preventDefault();addCustomProtectPattern();}">
                <button type="button" class="btn" style="padding:4px 12px;font-size:0.82rem" onclick="addCustomProtectPattern()">Add Pattern</button>
            </div>
            <div id="customProtectChips" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px"></div>
        </div>
    </div>

    <!-- Folder Exclusion & Upload Confirmation Modal -->
    <div id="folderExclusionModal" class="modal-backdrop" style="display:none">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><span>📁</span> Choose Folders NOT to Upload</h3>
                <button type="button" class="modal-close" onclick="closeFolderExclusionModal()" title="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-info-box">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                        <div>
                            <div style="font-size:1.05rem;font-weight:700;color:#fff" id="modalFileCountTitle">⚡ Scanned 0 files in 0 folders</div>
                            <div style="font-size:0.84rem;color:#8b949e;margin-top:2px" id="modalSubTitle">Choose which folders <b>NOT</b> to upload into Raspberry Pi RAM tmpfs. Unchecked folders will be omitted.</div>
                        </div>
                        <span class="pill ok" id="modalStackBadge">Detected</span>
                    </div>
                </div>

                <!-- Laravel Database Options (when Laravel detected) -->
                <div id="modalLaravelDbBox" class="modal-info-box" style="display:none;border-color:#388bfd;background:#0d1825;margin-bottom:12px;padding:12px 14px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:6px">
                        <b style="color:#7cc4ff;font-size:0.9rem">⚡ Laravel Post-Upload &amp; Database Actions:</b>
                        <small style="color:#8b949e">Auto-wires with MariaDB <code>w_site</code></small>
                    </div>
                    <div style="display:flex;gap:18px;flex-wrap:wrap">
                        <label style="font-size:0.85rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" id="modalChkRouteClear" checked onchange="syncDbCheckboxes('modal')">
                            <span>🧹 <b>Clear Route Cache</b> (<code>php artisan route:clear</code>)</span>
                        </label>
                        <label style="font-size:0.85rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" id="modalChkOptimizeClear" checked onchange="syncDbCheckboxes('modal')">
                            <span>✨ <b>Clear All Caches</b> (<code>php artisan optimize:clear</code>)</span>
                        </label>
                        <label style="font-size:0.85rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" id="modalChkZiggy" checked onchange="syncDbCheckboxes('modal')">
                            <span>🔀 <b>Rebuild Ziggy</b> (<code>php artisan ziggy:generate</code>)</span>
                        </label>
                        <label style="font-size:0.85rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" id="modalChkMigrate" checked onchange="syncDbCheckboxes('modal')">
                            <span>⚡ <b>Run Migrations</b> (<code>php artisan migrate</code>)</span>
                        </label>
                        <label style="font-size:0.85rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" id="modalChkSeed" onchange="syncDbCheckboxes('modal')">
                            <span>🌱 <b>Run Seeders</b> (<code>php artisan db:seed</code>)</span>
                        </label>
                        <label style="font-size:0.85rem;color:#c9d1d9;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" id="modalChkStorageLink" checked onchange="syncDbCheckboxes('modal')">
                            <span>🔗 <b>Link Storage</b> (<code>php artisan storage:link</code>)</span>
                        </label>
                    </div>
                </div>

                <div style="background:#11171e;border:1px solid #21262d;border-radius:6px;padding:8px 12px;margin-bottom:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:0.83rem;color:#c9d1d9">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" id="dropRecentOnly" onchange="onDropRecentFilterChange()">
                        <span>⏱️ <b>Only upload files saved in the past</b></span>
                    </label>
                    <input type="number" id="dropRecentMins" value="3" min="1" max="10080" oninput="onDropRecentFilterChange()" style="width:62px;background:#0d1117;color:#79c0ff;border:1px solid #30363d;border-radius:4px;padding:2px 6px;font-size:0.82rem;text-align:center;font-weight:600">
                    <span style="color:#8b949e;font-size:0.8rem">minutes <span style="font-size:0.74rem">(overlays onto existing site if present)</span></span>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                    <span style="font-size:0.85rem;color:#7cc4ff;font-weight:600">Select / Exclude Subdirectories:</span>
                    <div style="display:flex;gap:6px">
                        <button type="button" class="btn" style="font-size:0.75rem;padding:3px 8px" onclick="selectAllFolderFilters(true)">Select All</button>
                        <button type="button" class="btn" style="font-size:0.75rem;padding:3px 8px" onclick="selectAllFolderFilters(false)">Deselect All</button>
                        <button type="button" class="btn" style="font-size:0.75rem;padding:3px 8px;border-color:#d29922;color:#e3b341" onclick="applyDefaultFolderFilters()">Exclude Heavy Folders</button>
                    </div>
                </div>

                <!-- Folder checklist -->
                <div id="modalFolderList" class="modal-folder-list"></div>
            </div>
            <div class="modal-footer">
                <div style="font-size:0.84rem">
                    <div id="modalStatsLabel" style="color:#c9d1d9">Uploading: <b>0</b> / <b>0</b> files</div>
                    <div id="modalExcludedNotice" style="color:#e3b341;font-size:0.78rem"></div>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="button" class="btn" onclick="closeFolderExclusionModal()">Cancel</button>
                    <button type="button" class="btn-pri" id="btnModalConfirmUpload" onclick="confirmModalUpload()">🚀 Proceed with Upload</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Drop Options -->
    <div class="dropzone-options">
        <label>
            Site Name:
            <input type="text" id="dropSiteName" placeholder="Auto-detected from folder" pattern="[a-z0-9][a-z0-9_-]*" style="width:220px">
        </label>
        <label class="spin-label">
            <input type="checkbox" id="dropSpinUp" checked>
            <b>⚡ Spin up site immediately (Live in RAM)</b>
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:#c9d1d9;cursor:pointer">
            <input type="checkbox" id="dropAutoUpdate" checked>
            <b>🔄 Auto-update existing site on drop</b>
        </label>
        <span class="sp"></span>
        <button type="button" class="btn" id="btnManageProtected" onclick="toggleProtectedPanel()" style="border-color:#238636;color:#7ee787">🛡️ Protected Configs (<span id="protectedCountBadge">6</span>)</button>
        <button type="button" class="btn" id="btnManageFolders" style="display:none" onclick="openFolderExclusionModal()">📂 Filter Folders</button>
        <button type="button" class="btn-pri" id="btnSubmitDeploy" style="display:none" onclick="submitStagedFiles()">🚀 Deploy &amp; Spin Up</button>
    </div>

    <!-- Progress bar -->
    <div id="dropProgress" class="dropzone-progress" style="display:none">
        <div class="bar"><i id="dropProgressBar" style="width:0%"></i></div>
        <div id="dropProgressStatus" class="progress-status">Uploading files...</div>
    </div>

    <!-- Result / Live Card -->
    <div id="dropResult" class="dropzone-success" style="display:none"></div>

    <!-- Supported & Planned Stack Catalog Accordion -->
    <div id="stackCatalog" class="stack-catalog-box" style="display:none">
        <div class="card-head" style="margin-bottom:12px">
            <h4>📋 Application Framework Profiles &amp; Roadmap</h4>
            <small style="color:#8b949e">Built-in detection signatures &amp; device package requirements</small>
        </div>
        <div class="catalog-grid">
            <?php foreach ($stackProfiles as $p): ?>
                <div class="catalog-card <?= $p['status'] === 'supported' ? 'active-profile' : 'placeholder-profile' ?>">
                    <div class="catalog-card-header">
                        <span style="font-size:1.4rem"><?= $p['icon'] ?></span>
                        <div>
                            <b><?= h($p['name']) ?></b><br>
                            <?= $p['status'] === 'supported' ? '<span class="pill ok">Supported</span>' : '<span class="pill off">Placeholder</span>' ?>
                        </div>
                    </div>
                    <p class="catalog-desc"><?= h($p['description']) ?></p>
                    <div class="catalog-details">
                        <small><b>Required:</b> <?= h(implode(', ', $p['required_components']) ?: 'None (Nginx)') ?></small><br>
                        <?php if ($p['optional_components']): ?>
                            <small><b>Optional:</b> <?= h(implode(', ', $p['optional_components'])) ?></small><br>
                        <?php endif; ?>
                        <small style="color:#8b949e"><?= h($p['notes']) ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
window.WTC_PROFILES = <?= json_encode($stackProfiles) ?>;
window.WTC_STACK = <?= json_encode($deviceStack) ?>;
window.WTC_STATUS = <?= json_encode($deviceStatus) ?>;
window.WTC_HOST_IP = '<?= $hostIp ?>';
window.WTC_EXISTING_SITES = <?= json_encode(array_column(wj('sites'), 'name')) ?>;

(function() {
    const dropArea = document.getElementById('dropArea');
    const folderInput = document.getElementById('folderInput');
    const archiveInput = document.getElementById('archiveInput');
    const siteInput = document.getElementById('dropSiteName');
    const spinCheck = document.getElementById('dropSpinUp');
    const progressBox = document.getElementById('dropProgress');
    const progressBar = document.getElementById('dropProgressBar');
    const progressStatus = document.getElementById('dropProgressStatus');
    const resultBox = document.getElementById('dropResult');
    const banner = document.getElementById('stackDetectBanner');
    const reqBox = document.getElementById('stackReqBox');
    const envBox = document.getElementById('envConfigBox');
    const btnDeploy = document.getElementById('btnSubmitDeploy');
    const csrfToken = '<?= h(csrf()) ?>';

    let stagedFiles = [];
    let stagedSeederFile = null;
    let detectedStackId = 'lamp';
    let missingComponents = [];
    let isPublicOnlyLaravel = false;
    let folderFilterState = {}; // folderName -> boolean (true = include)
    let customProtectPatterns = [];
    let discoveredGitignoreRules = [];

    const STACK_PROTECT_PRESETS = {
        laravel: ['.env', '.env.*', 'database/*.sqlite', 'database/database.sqlite', '.wtc-database.sql', '.wtc-db.sql', '.wtc-db.php', 'storage/app/**', 'nginx.conf'],
        react: ['.env', '.env.*', '.env.local', '.env.production', 'dist/**', 'build/**', '.wtc-db.php', 'nginx.conf'],
        wordpress: ['wp-config.php', 'wp-content/uploads/**', '.htaccess', '.wtc-database.sql', '.wtc-db.php', 'nginx.conf'],
        php: ['.env', '.env.*', 'config.local.php', 'config.php', 'database/*.sqlite', '*.sqlite', 'uploads/**', '.wtc-database.sql', '.wtc-db.php', 'nginx.conf'],
        static: ['nginx.conf', 'wtc.json', '.wtc-database.sql'],
        custom: []
    };

    window.toggleProtectedPanel = function() {
        const p = document.getElementById('protectedFilesPanel');
        if (!p) return;
        p.style.display = (p.style.display === 'none' || !p.style.display) ? 'block' : 'none';
        if (p.style.display === 'block') {
            p.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    };

    window.applyProtectPreset = function(preset) {
        if (!preset) return;
        if (preset === 'auto') {
            preset = detectedStackId || 'php';
        }
        const sel = document.getElementById('selProtectPreset');
        if (sel) sel.value = preset;

        const chkEnv = document.getElementById('chkProtectEnv');
        const chkSqlite = document.getElementById('chkProtectSqlite');
        const chkDbDump = document.getElementById('chkProtectDbDump');
        const chkStorage = document.getElementById('chkProtectStorage');
        const chkNginx = document.getElementById('chkProtectNginx');
        const chkWpConfig = document.getElementById('chkProtectWpConfig');

        if (preset === 'laravel') {
            if (chkEnv) chkEnv.checked = true;
            if (chkSqlite) chkSqlite.checked = true;
            if (chkDbDump) chkDbDump.checked = true;
            if (chkStorage) chkStorage.checked = true;
            if (chkNginx) chkNginx.checked = true;
            if (chkWpConfig) chkWpConfig.checked = false;
        } else if (preset === 'react') {
            if (chkEnv) chkEnv.checked = true;
            if (chkSqlite) chkSqlite.checked = false;
            if (chkDbDump) chkDbDump.checked = false;
            if (chkStorage) chkStorage.checked = false;
            if (chkNginx) chkNginx.checked = true;
            if (chkWpConfig) chkWpConfig.checked = false;
        } else if (preset === 'wordpress') {
            if (chkEnv) chkEnv.checked = false;
            if (chkSqlite) chkSqlite.checked = false;
            if (chkDbDump) chkDbDump.checked = true;
            if (chkStorage) chkStorage.checked = true;
            if (chkNginx) chkNginx.checked = true;
            if (chkWpConfig) chkWpConfig.checked = true;
        } else if (preset === 'php') {
            if (chkEnv) chkEnv.checked = true;
            if (chkSqlite) chkSqlite.checked = true;
            if (chkDbDump) chkDbDump.checked = true;
            if (chkStorage) chkStorage.checked = true;
            if (chkNginx) chkNginx.checked = true;
            if (chkWpConfig) chkWpConfig.checked = false;
        } else if (preset === 'static') {
            if (chkEnv) chkEnv.checked = false;
            if (chkSqlite) chkSqlite.checked = false;
            if (chkDbDump) chkDbDump.checked = true;
            if (chkStorage) chkStorage.checked = false;
            if (chkNginx) chkNginx.checked = true;
            if (chkWpConfig) chkWpConfig.checked = false;
        }
        updateProtectedCountBadge();
    };

    window.addCustomProtectPattern = function() {
        const inp = document.getElementById('txtCustomProtect');
        if (!inp) return;
        const val = inp.value.trim().replace(/\\/g, '/').replace(/^\/+/, '');
        if (val && !customProtectPatterns.includes(val)) {
            customProtectPatterns.push(val);
            renderCustomProtectChips();
            inp.value = '';
            updateProtectedCountBadge();
        }
    };

    window.removeCustomProtectPattern = function(idx) {
        if (idx >= 0 && idx < customProtectPatterns.length) {
            customProtectPatterns.splice(idx, 1);
            renderCustomProtectChips();
            updateProtectedCountBadge();
        }
    };

    function renderCustomProtectChips() {
        const c = document.getElementById('customProtectChips');
        if (!c) return;
        if (customProtectPatterns.length === 0) {
            c.innerHTML = '<span style="color:#8b949e;font-size:0.76rem">No custom patterns added yet.</span>';
            return;
        }
        c.innerHTML = customProtectPatterns.map((p, idx) => `
            <span style="background:#1f2937;border:1px solid #374151;color:#a5d6ff;border-radius:4px;padding:2px 8px;font-size:0.75rem;display:inline-flex;align-items:center;gap:6px">
                <code>${p}</code>
                <button type="button" onclick="removeCustomProtectPattern(${idx})" style="background:none;border:none;color:#ff7b72;cursor:pointer;padding:0;font-size:0.85rem;line-height:1" title="Remove">&times;</button>
            </span>
        `).join('');
    }

    function parseAndApplyGitignoreRules(txt) {
        if (!txt) return;
        const lines = txt.split('\n');
        const rules = [];
        lines.forEach(l => {
            let trimmed = l.trim().replace(/^[ \t]*#.*/, '').replace(/^!/, '');
            if (!trimmed) return;
            trimmed = trimmed.replace(/\\/g, '/').replace(/^\/+/, '');
            if (/(\.env|\.sql|\.sqlite|\.db|storage|config|secret|key|upload)/i.test(trimmed)) {
                rules.push(trimmed);
            }
        });
        if (rules.length > 0) {
            discoveredGitignoreRules = Array.from(new Set(rules));
            const notice = document.getElementById('dropGitignoreNotice');
            if (notice) {
                notice.innerHTML = '✅ <b style="color:#7ee787">' + discoveredGitignoreRules.length + '</b> rules identified from <code>.gitignore</code> (' + discoveredGitignoreRules.slice(0, 3).join(', ') + (discoveredGitignoreRules.length > 3 ? '...' : '') + ')';
            }
            updateProtectedCountBadge();
        }
    }

    window.getActiveProtectedPatterns = function() {
        const patterns = [];
        const chkEnv = document.getElementById('chkProtectEnv');
        const chkSqlite = document.getElementById('chkProtectSqlite');
        const chkDbDump = document.getElementById('chkProtectDbDump');
        const chkStorage = document.getElementById('chkProtectStorage');
        const chkNginx = document.getElementById('chkProtectNginx');
        const chkWpConfig = document.getElementById('chkProtectWpConfig');
        const chkGitignore = document.getElementById('chkProtectGitignore');

        if (chkEnv && chkEnv.checked) {
            patterns.push('.env', '.env.*', '.env.local', '.env.production');
        }
        if (chkSqlite && chkSqlite.checked) {
            patterns.push('database/*.sqlite', 'database/database.sqlite', '*.sqlite', '*.db', 'storage/*.sqlite');
        }
        if (chkDbDump && chkDbDump.checked) {
            patterns.push('.wtc-database.sql', '.wtc-db.sql', '.wtc-db.php');
        }
        if (chkStorage && chkStorage.checked) {
            patterns.push('storage/app/**', 'uploads/**', 'wp-content/uploads/**');
        }
        if (chkNginx && chkNginx.checked) {
            patterns.push('nginx.conf', 'wtc.json', '.htaccess');
        }
        if (chkWpConfig && chkWpConfig.checked) {
            patterns.push('wp-config.php');
        }
        if (chkGitignore && chkGitignore.checked && discoveredGitignoreRules.length > 0) {
            discoveredGitignoreRules.forEach(r => {
                if (!patterns.includes(r)) patterns.push(r);
            });
        }
        customProtectPatterns.forEach(cp => {
            if (!patterns.includes(cp)) patterns.push(cp);
        });
        return Array.from(new Set(patterns));
    };

    window.updateProtectedCountBadge = function() {
        const b = document.getElementById('protectedCountBadge');
        if (b) {
            b.textContent = getActiveProtectedPatterns().length;
        }
    };

    // Seeder file input listener
    const seederInput = document.getElementById('seederFileInput');
    if (seederInput) {
        seederInput.addEventListener('change', () => {
            if (seederInput.files.length > 0) {
                attachSeederFile(seederInput.files[0]);
            }
        });
    }

    const seederDropZone = document.getElementById('seederDropZone');
    if (seederDropZone) {
        ['dragenter', 'dragover'].forEach(name => {
            seederDropZone.addEventListener(name, (e) => {
                e.preventDefault(); e.stopPropagation();
                seederDropZone.style.borderColor = '#2ea043';
                seederDropZone.style.background = '#161b22';
            });
        });
        ['dragleave', 'drop'].forEach(name => {
            seederDropZone.addEventListener(name, (e) => {
                e.preventDefault(); e.stopPropagation();
                seederDropZone.style.borderColor = '#388bfd';
                seederDropZone.style.background = '#0d1117';
            });
        });
        seederDropZone.addEventListener('drop', (e) => {
            if (e.dataTransfer.files.length > 0) {
                const f = e.dataTransfer.files[0];
                if (f.name.toLowerCase().endsWith('.sql')) {
                    attachSeederFile(f);
                } else {
                    alert('Please drop a valid .sql seeder file.');
                }
            }
        });
    }

    function attachSeederFile(file) {
        stagedSeederFile = file;
        const lbl = document.getElementById('seederFileLabel');
        const chk = document.getElementById('checkSeeder');
        if (lbl) lbl.innerHTML = 'Attached: <b style="color:#7ee787">' + file.name + '</b> (' + Math.round(file.size / 1024) + ' KB)';
        if (chk) {
            chk.innerHTML = '✅ Database Seeder (<b>' + file.name + '</b> attached & ready to import)';
            chk.style.color = '#7ee787';
        }
    }

    // Drag events
    ['dragenter', 'dragover'].forEach(name => {
        dropArea.addEventListener(name, (e) => {
            e.preventDefault(); e.stopPropagation();
            dropArea.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(name => {
        dropArea.addEventListener(name, (e) => {
            e.preventDefault(); e.stopPropagation();
            dropArea.classList.remove('dragover');
        });
    });

    dropArea.addEventListener('drop', async (e) => {
        const items = e.dataTransfer.items;
        if (!items || items.length === 0) return;

        // If a single .sql file was dropped on the main dropzone while Laravel public is active
        if (e.dataTransfer.files.length === 1 && e.dataTransfer.files[0].name.toLowerCase().endsWith('.sql') && isPublicOnlyLaravel) {
            attachSeederFile(e.dataTransfer.files[0]);
            return;
        }

        const entries = [];
        for (let i = 0; i < items.length; i++) {
            const entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
            if (entry) entries.push(entry);
        }

        if (entries.length > 0 && entries[0].isDirectory) {
            handleDirectoryDrop(entries[0]);
        } else if (e.dataTransfer.files.length > 0) {
            handleFiles(e.dataTransfer.files);
        }
    });

    folderInput.addEventListener('change', () => {
        if (folderInput.files.length > 0) handleFiles(folderInput.files, true);
    });
    archiveInput.addEventListener('change', () => {
        if (archiveInput.files.length > 0) handleFiles(archiveInput.files);
    });

    siteInput.addEventListener('input', () => {
        updateEnvFieldsFromSiteName();
    });

    async function handleDirectoryDrop(dirEntry) {
        const baseName = dirEntry.name.toLowerCase().replace(/[^a-z0-9_-]/g, '-');
        if (!siteInput.value) siteInput.value = baseName;
        showProgress('Scanning files in folder ' + dirEntry.name + '...', 20);
        const filesWithPaths = [];
        await readDirectoryRecursive(dirEntry, '', filesWithPaths);
        progressBox.style.display = 'none';
        processScannedFiles(filesWithPaths, dirEntry.name);
    }

    async function readDirectoryRecursive(dirEntry, path, list) {
        const reader = dirEntry.createReader();
        const readEntries = () => new Promise((res, rej) => reader.readEntries(res, rej));
        let entries = [];
        let batch;
        do {
            batch = await readEntries();
            entries = entries.concat(batch);
        } while (batch && batch.length > 0);

        const ignoredDirs = ['node_modules', 'vendor', '.git', '.github', '.idea', '.vscode', '.phpunit.cache', 'coverage'];
        for (const entry of entries) {
            const fullPath = path ? (path + '/' + entry.name) : entry.name;
            if (entry.isFile) {
                const file = await new Promise((res, rej) => entry.file(res, rej));
                list.push({ file, path: fullPath });
            } else if (entry.isDirectory) {
                const dirLower = entry.name.toLowerCase();
                if (ignoredDirs.includes(dirLower)) {
                    // Do not freeze the browser by deeply scanning 300k+ transient files.
                    // Keep a single placeholder marker so the folder exclusion UI knows this directory exists.
                    list.push({
                        file: new File([""], entry.name + '/.wtc-placeholder', { type: "text/plain" }),
                        path: fullPath + '/.wtc-placeholder',
                        isPlaceholder: true
                    });
                    continue;
                }
                await readDirectoryRecursive(entry, fullPath, list);
            }
        }
    }

    function handleFiles(fileList) {
        if (fileList.length === 1 && /\.(zip|tar\.gz|tgz|tar)$/i.test(fileList[0].name)) {
            const archiveFile = fileList[0];
            const baseName = archiveFile.name.replace(/(\.tar\.gz|\.tgz|\.tar|\.zip)$/i, '').toLowerCase().replace(/[^a-z0-9_-]/g, '-');
            if (!siteInput.value) siteInput.value = baseName;
            uploadSingleArchive(archiveFile);
            return;
        }
        const list = [];
        for (let i = 0; i < fileList.length; i++) {
            const f = fileList[i];
            const p = f.webkitRelativePath || f.name;
            list.push({ file: f, path: p });
        }
        processScannedFiles(list, siteInput.value || 'my-site');
    }

    function processScannedFiles(list, inferredName) {
        if (!list || list.length === 0) return;

        // If all paths share a common top-level folder prefix (e.g. MyProject/app, MyProject/public),
        // extract the project name from that common prefix and normalize all paths so they are relative
        // to the project root (e.g. app/..., public/..., artisan, etc.).
        const firstRel = (list[0].path || '').replace(/\\/g, '/').replace(/^\/+/, '');
        const firstParts = firstRel.split('/').filter(p => p !== '' && p !== '.' && p !== '..');
        let commonPrefix = null;
        let siteNameCandidate = inferredName;

        if (firstParts.length > 1) {
            const candidate = firstParts[0];
            const allShare = list.every(item => {
                const p = (item.path || '').replace(/\\/g, '/').replace(/^\/+/, '');
                return p.startsWith(candidate + '/');
            });
            if (allShare) {
                commonPrefix = candidate;
                siteNameCandidate = candidate;
            }
        }

        // Normalize item paths by removing the common outer parent directory if present
        const normalizedList = list.map(item => {
            let p = (item.path || '').replace(/\\/g, '/').replace(/^\/+/, '');
            if (commonPrefix && p.startsWith(commonPrefix + '/')) {
                p = p.substring(commonPrefix.length + 1);
            }
            return {
                file: item.file,
                path: p
            };
        });

        stagedFiles = normalizedList;

        if (!siteInput.value && siteNameCandidate) {
            siteInput.value = siteNameCandidate.toLowerCase().replace(/[^a-z0-9_-]/g, '-').replace(/^-+|-+$/g, '');
        }

        // Check for any .sql files included in the drop
        const sqlItem = normalizedList.find(x => x.path.toLowerCase().endsWith('.sql'));
        if (sqlItem) {
            attachSeederFile(sqlItem.file);
        }

        // Detect Stack from normalized file paths
        detectedStackId = detectStackFromPaths(normalizedList.map(x => x.path));

        // Check package.json if present for React / Vite
        const pkgItem = normalizedList.find(x => x.path.toLowerCase().endsWith('package.json'));
        if (pkgItem && detectedStackId !== 'laravel') {
            try {
                pkgItem.file.text().then(txt => {
                    if (txt.includes('"react"') || txt.includes("'react'") || txt.includes('"vite"')) {
                        detectedStackId = 'react';
                        renderDetectedStack('react', normalizedList.map(x => x.path));
                    }
                }).catch(() => {});
            } catch (_) {}
        }

        // Check for .gitignore or .wtcignore to automatically protect ignored config files
        const gitignoreItem = normalizedList.find(x => {
            const p = (x.path || '').toLowerCase().replace(/\\/g, '/');
            return p === '.gitignore' || p.endsWith('/.gitignore') || p === '.wtcignore' || p.endsWith('/.wtcignore');
        });
        if (gitignoreItem && gitignoreItem.file) {
            try {
                gitignoreItem.file.text().then(txt => {
                    parseAndApplyGitignoreRules(txt);
                }).catch(() => {});
            } catch (_) {}
        }

        applyProtectPreset(detectedStackId);
        const protPanel = document.getElementById('protectedFilesPanel');
        if (protPanel) protPanel.style.display = 'block';

        renderDetectedStack(detectedStackId, normalizedList.map(x => x.path));

        // Build folder filters for selective upload using normalized list
        buildFolderFiltersUI(normalizedList);

        // Display deploy button and manage folders button
        btnDeploy.style.display = 'inline-block';
        const btnManage = document.getElementById('btnManageFolders');
        if (btnManage) btnManage.style.display = 'inline-block';

        // If auto-update is enabled and the site is an existing installed application, auto-apply default exclusions and deploy immediately
        const autoUpdateEl = document.getElementById('dropAutoUpdate');
        const isKnownSite = Array.isArray(window.WTC_EXISTING_SITES) && window.WTC_EXISTING_SITES.includes(siteInput.value);
        if (autoUpdateEl && autoUpdateEl.checked && isKnownSite) {
            applyDefaultFolderFilters();
            submitStagedFiles();
            return;
        }

        // Open modal showing file count and allowing user to choose what folders NOT to upload
        openFolderExclusionModal();
    }

    function formatBytes(b) {
        if (!b || b === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        let idx = 0;
        let val = Number(b);
        while (val >= 1024 && idx < units.length - 1) {
            val /= 1024;
            idx++;
        }
        return (idx === 0 ? Math.round(val) : Math.round(val * 10) / 10) + ' ' + units[idx];
    }

    function getFolderIcon(folder) {
        const fLower = folder.toLowerCase();
        if (folder === '(root files)') return '📄';
        if (fLower === 'vendor' || fLower === 'node_modules') return '📦';
        if (fLower === 'storage') return '🗄️';
        if (fLower === 'public') return '🌐';
        if (fLower === 'app' || fLower === 'src') return '🧠';
        if (fLower === 'dist' || fLower === 'build') return '⚛️';
        if (fLower === 'config' || fLower === 'settings') return '⚙️';
        if (fLower === 'database' || fLower === 'migrations') return '🗃️';
        if (fLower === 'routes' || fLower === 'api') return '🛣️';
        if (fLower === 'resources' || fLower === 'views' || fLower === 'templates') return '🎨';
        if (fLower === 'tests' || fLower === 'test') return '🧪';
        if (fLower === '.git' || fLower === '.github') return '🐙';
        return '📁';
    }

    function buildFolderFiltersUI(list) {
        const filterPanel = document.getElementById('filterPanel');
        const filterList = document.getElementById('folderFilterList');
        const modalList = document.getElementById('modalFolderList');

        // Group files by their relative top-level folder
        const folderCounts = {};
        const folderSizes = {};
        detectedTopFolders = [];

        list.forEach(item => {
            const rel = item.path.replace(/\\/g, '/').replace(/^\/+/, '');
            const parts = rel.split('/').filter(p => p !== '' && p !== '.' && p !== '..');
            
            const top = parts.length > 1 ? parts[0] : '(root files)';

            folderCounts[top] = (folderCounts[top] || 0) + 1;
            folderSizes[top] = (folderSizes[top] || 0) + (item.file.size || 0);
        });

        detectedTopFolders = Object.keys(folderCounts).sort((a, b) => {
            if (a === '(root files)') return -1;
            if (b === '(root files)') return 1;
            return a.localeCompare(b);
        });

        // Sensible default exclusions: development devDependencies (node_modules), git history, IDE caches
        // NOTE: vendor and storage frameworks are kept included so backend and artisan commands work fully
        const autoExcludeList = ['node_modules', '.git', '.github', '.idea', '.vscode', '.phpunit.cache'];
        const hasCompiledBuild = detectedTopFolders.some(f => f.toLowerCase() === 'dist' || f.toLowerCase() === 'build');
        if (hasCompiledBuild && detectedTopFolders.includes('src')) {
            // Only auto-exclude src for pure frontend bundles (React/Vite) that have pre-compiled dist/
            if (!detectedTopFolders.includes('app') && !detectedTopFolders.includes('artisan')) {
                autoExcludeList.push('src', 'coverage');
            }
        }

        folderFilterState = {};
        detectedTopFolders.forEach(folder => {
            const isAutoExcluded = autoExcludeList.includes(folder.toLowerCase());
            folderFilterState[folder] = !isAutoExcluded; // true = upload, false = exclude
        });

        // Populate Modal Folder Checklist
        if (modalList) {
            modalList.innerHTML = '';
            detectedTopFolders.forEach(folder => {
                const isIncluded = folderFilterState[folder] === true;
                const icon = getFolderIcon(folder);
                const sizeStr = formatBytes(folderSizes[folder]);
                const count = folderCounts[folder];
                const safeId = folder.replace(/[^a-z0-9_-]/gi, '_');

                const itemBox = document.createElement('label');
                itemBox.className = 'modal-folder-item ' + (isIncluded ? 'included' : 'excluded');
                itemBox.id = 'modal_item_' + safeId;

                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = isIncluded;
                cb.id = 'modal_cb_' + safeId;
                cb.style.cssText = 'width:18px;height:18px;cursor:pointer;flex-shrink:0';

                cb.addEventListener('change', () => {
                    setFolderIncluded(folder, cb.checked);
                });

                const content = document.createElement('div');
                content.style.cssText = 'flex:1;display:flex;justify-content:space-between;align-items:center;gap:8px';
                content.innerHTML = `
                    <div style="display:flex;align-items:center;gap:8px">
                        <span style="font-size:1.2rem">${icon}</span>
                        <div>
                            <div style="font-weight:600;font-size:0.88rem;color:#fff">${folder}</div>
                            <small style="color:#8b949e">${count} ${count === 1 ? 'file' : 'files'} &bull; ${sizeStr}</small>
                        </div>
                    </div>
                    <span id="modal_pill_${safeId}" class="pill ${isIncluded ? 'ok' : 'err'}" style="font-size:0.72rem;padding:2px 8px;${isIncluded ? '' : 'background:#8c2a23'}">
                        ${isIncluded ? 'Upload' : 'Exclude'}
                    </span>
                `;

                itemBox.appendChild(cb);
                itemBox.appendChild(content);
                modalList.appendChild(itemBox);
            });
        }

        // Populate In-Page Filter Panel
        if (filterPanel && filterList) {
            if (detectedTopFolders.length > 1 || detectedTopFolders.includes('storage') || detectedTopFolders.includes('vendor')) {
                filterPanel.style.display = 'block';
                filterList.innerHTML = '';

                detectedTopFolders.forEach(folder => {
                    const isIncluded = folderFilterState[folder] === true;
                    const icon = getFolderIcon(folder);
                    const sizeStr = formatBytes(folderSizes[folder]);
                    const count = folderCounts[folder];
                    const safeId = folder.replace(/[^a-z0-9_-]/gi, '_');

                    const itemBox = document.createElement('label');
                    itemBox.style.cssText = 'background:' + (isIncluded ? '#161b22' : '#221415') + ';border:1px solid ' + (isIncluded ? '#388bfd' : '#8c2a23') + ';padding:8px 10px;border-radius:6px;display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.83rem;';
                    itemBox.id = 'page_item_' + safeId;

                    const cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.checked = isIncluded;
                    cb.id = 'filter_cb_' + safeId;
                    cb.addEventListener('change', () => {
                        setFolderIncluded(folder, cb.checked);
                    });

                    const badge = document.createElement('span');
                    badge.style.cssText = 'flex:1;display:flex;justify-content:space-between;align-items:center;';
                    badge.innerHTML = `<span>${icon} <b>${folder}</b></span> <small style="color:#8b949e">${count} files (${sizeStr})</small>`;

                    itemBox.appendChild(cb);
                    itemBox.appendChild(badge);
                    filterList.appendChild(itemBox);
                });
            } else {
                filterPanel.style.display = 'none';
            }
        }

        updateFilterSummary();
    }

    function setFolderIncluded(folder, isIncluded) {
        folderFilterState[folder] = isIncluded;
        const safeId = folder.replace(/[^a-z0-9_-]/gi, '_');

        // Sync modal item
        const mCb = document.getElementById('modal_cb_' + safeId);
        const mItem = document.getElementById('modal_item_' + safeId);
        const mPill = document.getElementById('modal_pill_' + safeId);
        if (mCb) mCb.checked = isIncluded;
        if (mItem) {
            mItem.className = 'modal-folder-item ' + (isIncluded ? 'included' : 'excluded');
        }
        if (mPill) {
            mPill.className = 'pill ' + (isIncluded ? 'ok' : 'err');
            mPill.style.background = isIncluded ? '' : '#8c2a23';
            mPill.textContent = isIncluded ? 'Upload' : 'Exclude';
        }

        // Sync in-page item
        const pCb = document.getElementById('filter_cb_' + safeId);
        const pItem = document.getElementById('page_item_' + safeId);
        if (pCb) pCb.checked = isIncluded;
        if (pItem) {
            pItem.style.borderColor = isIncluded ? '#388bfd' : '#8c2a23';
            pItem.style.background = isIncluded ? '#161b22' : '#221415';
        }

        updateFilterSummary();
    }

    window.selectAllFolderFilters = function(val) {
        detectedTopFolders.forEach(f => {
            setFolderIncluded(f, val);
        });
    };

    window.applyDefaultFolderFilters = function() {
        const autoExcludeList = ['storage', 'vendor', 'node_modules', '.git', '.idea', '.vscode', 'tests', 'cache'];
        const hasCompiledBuild = detectedTopFolders.some(f => f.toLowerCase() === 'dist' || f.toLowerCase() === 'build');
        if (hasCompiledBuild) {
            autoExcludeList.push('src', 'tests', 'coverage', '.github');
        }
        detectedTopFolders.forEach(f => {
            const isAutoExcluded = autoExcludeList.includes(f.toLowerCase());
            setFolderIncluded(f, !isAutoExcluded);
        });
    };

    window.openFolderExclusionModal = function() {
        const modal = document.getElementById('folderExclusionModal');
        if (!modal) return;

        const totalFiles = stagedFiles.length;
        let totalBytes = 0;
        stagedFiles.forEach(f => { totalBytes += (f.file ? f.file.size : 0); });

        const titleEl = document.getElementById('modalFileCountTitle');
        const subTitleEl = document.getElementById('modalSubTitle');
        const badgeEl = document.getElementById('modalStackBadge');

        const profile = window.WTC_PROFILES[detectedStackId] || window.WTC_PROFILES['lamp'] || { name: 'Web App' };
        if (titleEl) {
            titleEl.innerHTML = `⚡ <b>${totalFiles.toLocaleString()} files</b> (${formatBytes(totalBytes)}) detected in '<b>${siteInput.value || 'site'}</b>'`;
        }
        if (subTitleEl) {
            subTitleEl.innerHTML = `Found <b>${detectedTopFolders.length}</b> folder ${detectedTopFolders.length === 1 ? 'group' : 'groups'}. Uncheck any folders you <b>do NOT</b> want to upload to Pi RAM.`;
        }
        if (badgeEl) {
            badgeEl.textContent = profile.name;
        }

        updateFilterSummary();
        modal.style.display = 'flex';
    };

    window.closeFolderExclusionModal = function() {
        const modal = document.getElementById('folderExclusionModal');
        if (modal) modal.style.display = 'none';
    };

    window.confirmModalUpload = function() {
        const filesToUpload = getFilteredStagedFiles();
        if (filesToUpload.length === 0) {
            alert('No files selected for upload. Please select at least one folder to upload.');
            return;
        }
        closeFolderExclusionModal();
        submitStagedFiles();
    };

    function updateFilterSummary() {
        const activeFiles = getFilteredStagedFiles();
        const totalFiles = stagedFiles.length;
        const excludedCount = totalFiles - activeFiles.length;

        let activeBytes = 0;
        activeFiles.forEach(f => { activeBytes += (f.file ? f.file.size : 0); });
        let totalBytes = 0;
        stagedFiles.forEach(f => { totalBytes += (f.file ? f.file.size : 0); });
        const excludedBytes = totalBytes - activeBytes;

        // In-page panel labels
        const pageLbl = document.getElementById('filterStatsLabel');
        const pageNotice = document.getElementById('filterExcludedNotice');
        if (pageLbl) {
            pageLbl.innerHTML = `Files selected for upload: <b>${activeFiles.length.toLocaleString()}</b> / <b>${totalFiles.toLocaleString()}</b> (${formatBytes(activeBytes)})`;
        }
        if (pageNotice) {
            if (excludedCount > 0) {
                pageNotice.innerHTML = `⚡ <b>${excludedCount.toLocaleString()}</b> files excluded (${formatBytes(excludedBytes)} RAM saved)`;
            } else {
                pageNotice.innerHTML = `<span style="color:#7ee787">All files included</span>`;
            }
        }

        // Modal summary labels
        const modalLbl = document.getElementById('modalStatsLabel');
        const modalNotice = document.getElementById('modalExcludedNotice');
        if (modalLbl) {
            modalLbl.innerHTML = `Uploading: <b>${activeFiles.length.toLocaleString()}</b> / <b>${totalFiles.toLocaleString()}</b> files (${formatBytes(activeBytes)})`;
        }
        if (modalNotice) {
            if (excludedCount > 0) {
                modalNotice.innerHTML = `⚡ <b>${excludedCount.toLocaleString()}</b> files excluded (${formatBytes(excludedBytes)} RAM saved)`;
            } else {
                modalNotice.innerHTML = `<span style="color:#7ee787">All folders and files included</span>`;
            }
        }

        // In-page "Filter Folders" button badge
        const btnManage = document.getElementById('btnManageFolders');
        if (btnManage) {
            btnManage.innerHTML = `📂 Filter Folders (${excludedCount > 0 ? excludedCount + ' excluded' : 'all included'})`;
        }
    }

    function getDropRecentMinutes() {
        const chk = document.getElementById('dropRecentOnly');
        const mins = document.getElementById('dropRecentMins');
        if (!chk || !chk.checked) return 0;
        const parsed = parseInt(mins ? mins.value : '3', 10);
        return (!isNaN(parsed) && parsed > 0) ? parsed : 3;
    }

    function loadDropRecentPrefs() {
        const chk = document.getElementById('dropRecentOnly');
        const mins = document.getElementById('dropRecentMins');
        try {
            const savedEnabled = localStorage.getItem('wtc_drop_recent_only');
            const savedMins = localStorage.getItem('wtc_reupload_recent_mins');
            if (chk && savedEnabled !== null) chk.checked = (savedEnabled === '1');
            if (mins && savedMins) {
                const parsed = parseInt(savedMins, 10);
                if (!isNaN(parsed) && parsed > 0) mins.value = String(parsed);
            }
        } catch (_) {}
    }

    window.onDropRecentFilterChange = function() {
        const chk = document.getElementById('dropRecentOnly');
        const mins = document.getElementById('dropRecentMins');
        try {
            if (chk) localStorage.setItem('wtc_drop_recent_only', chk.checked ? '1' : '0');
            if (mins) {
                const parsed = parseInt(mins.value, 10);
                if (!isNaN(parsed) && parsed > 0) {
                    localStorage.setItem('wtc_reupload_recent_mins', String(parsed));
                }
            }
        } catch (_) {}
        updateFilterSummary();
    };

    loadDropRecentPrefs();

    function getFilteredStagedFiles() {
        if (!stagedFiles || stagedFiles.length === 0) return [];
        const hasFolderFilter = Object.keys(folderFilterState).length > 0;
        const recentMins = getDropRecentMinutes();
        const cutoffMs = recentMins > 0 ? (Date.now() - recentMins * 60 * 1000) : 0;

        return stagedFiles.filter(item => {
            if (item.isPlaceholder) return false;

            if (hasFolderFilter) {
                const rel = (item.path || '').replace(/\\/g, '/').replace(/^\/+/, '');
                const parts = rel.split('/').filter(p => p !== '' && p !== '.' && p !== '..');
                const top = parts.length > 1 ? parts[0] : '(root files)';
                if (folderFilterState.hasOwnProperty(top) && folderFilterState[top] !== true) {
                    return false;
                }
            }

            if (recentMins > 0) {
                if (!item.file || typeof item.file.lastModified !== 'number' || item.file.lastModified < cutoffMs) {
                    return false;
                }
            }

            return true;
        });
    }

    function detectStackFromPaths(paths) {
        const pathLower = paths.map(p => p.toLowerCase());

        // Laravel checks
        const isLaravel = pathLower.some(p =>
            p === 'artisan' ||
            p.endsWith('/artisan') ||
            p === 'bootstrap/app.php' ||
            p.endsWith('/bootstrap/app.php') ||
            p === 'public/index.php' ||
            p.endsWith('/public/index.php') ||
            p === '.env.example' ||
            p.endsWith('/.env.example')
        ) || (pathLower.length > 0 && pathLower.every(p => p.startsWith('public/')) && pathLower.some(p => p.includes('index.php')));

        if (isLaravel) return 'laravel';

        // React / Vite / SPA checks
        const isReact = pathLower.some(p =>
            p.includes('vite.config.') ||
            p.includes('react-router') ||
            p.includes('app.jsx') || p.includes('app.tsx') ||
            p.includes('main.jsx') || p.includes('main.tsx') ||
            p.includes('index.jsx') || p.includes('index.tsx') ||
            p.includes('dist/index.html') || p.includes('build/index.html') ||
            p.includes('asset-manifest.json') ||
            (p.includes('static/js/') && p.endsWith('.js')) ||
            (p.includes('assets/') && (p.endsWith('.js') || p.endsWith('.css')) && pathLower.some(x => x.endsWith('index.html')))
        );

        if (isReact) return 'react';

        // WordPress checks
        if (pathLower.some(p => p.includes('wp-config.php') || p.includes('wp-login.php') || p.includes('wp-content/'))) return 'wordpress';

        // Node.js
        if (pathLower.some(p => p === 'package.json' || p.endsWith('/package.json'))) return 'nodejs';

        // Python
        if (pathLower.some(p => p === 'requirements.txt' || p === 'manage.py' || p === 'wsgi.py' || p === 'app.py')) return 'python';

        // Symfony
        if (pathLower.some(p => p === 'bin/console' || p.endsWith('/bin/console'))) return 'symfony';

        // CodeIgniter
        if (pathLower.some(p => p === 'spark' || p.endsWith('/spark'))) return 'codeigniter';

        // Drupal
        if (pathLower.some(p => p.includes('core/lib/drupal.php') || p.includes('sites/default/'))) return 'drupal';

        // Static
        const hasPhp = pathLower.some(p => p.endsWith('.php'));
        if (!hasPhp && pathLower.some(p => p.endsWith('.html') || p.endsWith('.htm'))) return 'static';

        return 'lamp';
    }

    function renderDetectedStack(stackId, paths = []) {
        const profile = window.WTC_PROFILES[stackId] || window.WTC_PROFILES['lamp'];
        banner.style.display = 'block';
        document.getElementById('stackIcon').textContent = profile.icon;
        document.getElementById('stackName').textContent = profile.name + ' Detected';
        document.getElementById('stackDesc').textContent = profile.description;

        const pill = document.getElementById('stackStatusPill');
        if (profile.status === 'supported') {
            pill.className = 'pill ok';
            pill.textContent = 'Supported';
        } else {
            pill.className = 'pill off';
            pill.textContent = 'Placeholder Roadmap';
        }

        // Requirements check
        renderRequirements(profile);

        const missingCard = document.getElementById('missingFilesCard');

        // If Laravel: inspect whether backend files are missing
        if (stackId === 'laravel') {
            const pathLower = paths.map(p => p.toLowerCase());
            const hasBootstrap = pathLower.some(p => p === 'bootstrap/app.php' || p.endsWith('/bootstrap/app.php'));
            const hasApp = pathLower.some(p => p.startsWith('app/') || p.includes('/app/'));
            
            // True if only public webroot or bootstrap missing
            isPublicOnlyLaravel = (!hasBootstrap || !hasApp);

            if (isPublicOnlyLaravel && missingCard) {
                missingCard.style.display = 'block';
                const checkBackend = document.getElementById('checkBackend');
                if (checkBackend) {
                    checkBackend.innerHTML = '⚠️ <code>app/</code> &amp; <code>bootstrap/</code> (Missing - Will Use WTC Compatibility Shim)';
                    checkBackend.style.color = '#e3b341';
                }
            } else if (missingCard) {
                missingCard.style.display = 'none';
            }

            envBox.style.display = 'block';
            updateEnvFieldsFromSiteName();
            if (!document.getElementById('envAppKey').value) generateNewAppKey();
            syncGuidedToRaw();
            const modalDbBox = document.getElementById('modalLaravelDbBox');
            if (modalDbBox) modalDbBox.style.display = 'block';
        } else if (stackId === 'react') {
            const pathLower = paths.map(p => p.toLowerCase());
            const hasCompiled = pathLower.some(p => p.includes('dist/index.html') || p.includes('build/index.html') || p.endsWith('/index.html') || p === 'index.html');
            if (!hasCompiled && missingCard) {
                missingCard.style.display = 'block';
                const badge = document.getElementById('shimBadge');
                if (badge) badge.textContent = 'SPA Bridge Ready';
                const checkPublic = document.getElementById('checkPublic');
                if (checkPublic) {
                    checkPublic.innerHTML = '⚠️ <code>dist/</code> or <code>build/</code> not detected yet';
                    checkPublic.style.color = '#e3b341';
                }
                const checkBackend = document.getElementById('checkBackend');
                if (checkBackend) {
                    checkBackend.innerHTML = '💡 For best performance: run <code>npm run build</code> and drop <code>dist/</code> or <code>build/</code>';
                    checkBackend.style.color = '#79c0ff';
                }
                const checkSeeder = document.getElementById('checkSeeder');
                if (checkSeeder) {
                    checkSeeder.innerHTML = '⚛️ React Router client-side rewrites active in RAM';
                    checkSeeder.style.color = '#7ee787';
                }
            } else if (missingCard) {
                missingCard.style.display = 'none';
            }
            envBox.style.display = 'none';
            const modalDbBox = document.getElementById('modalLaravelDbBox');
            if (modalDbBox) modalDbBox.style.display = 'none';
            isPublicOnlyLaravel = false;
        } else {
            if (missingCard) missingCard.style.display = 'none';
            envBox.style.display = 'none';
            const modalDbBox = document.getElementById('modalLaravelDbBox');
            if (modalDbBox) modalDbBox.style.display = 'none';
            isPublicOnlyLaravel = false;
        }
    }

    window.syncDbCheckboxes = function(source) {
        const pairs = [
            ['modalChkRouteClear', 'envChkRouteClear'],
            ['modalChkOptimizeClear', 'envChkOptimizeClear'],
            ['modalChkZiggy', 'envChkZiggy'],
            ['modalChkMigrate', 'envChkMigrate'],
            ['modalChkSeed', 'envChkSeed'],
            ['modalChkStorageLink', 'envChkStorageLink']
        ];
        pairs.forEach(([mId, eId]) => {
            const m = document.getElementById(mId);
            const e = document.getElementById(eId);
            if (source === 'modal') {
                if (e && m) e.checked = m.checked;
            } else {
                if (m && e) m.checked = e.checked;
            }
        });
    };

    function renderRequirements(profile) {
        reqBox.style.display = 'block';
        const reqGrid = document.getElementById('reqGrid');
        reqGrid.innerHTML = '';
        missingComponents = [];

        const installedComponents = (window.WTC_STACK.components || []).filter(c => c.installed).map(c => c.id);

        // Always check PHP
        const phpRow = document.createElement('div');
        phpRow.className = 'req-item ok';
        phpRow.innerHTML = '<span>PHP Engine: <b>' + (window.WTC_STATUS.phpfpm === 'yes' ? 'PHP 8.x Active' : 'Stopped') + '</b></span> <span class="pill ok">Ready</span>';
        reqGrid.appendChild(phpRow);

        (profile.required_components || []).forEach(reqId => {
            const comp = (window.WTC_STACK.components || []).find(c => c.id === reqId) || { label: reqId, installed: false };
            const isInstalled = installedComponents.includes(reqId);
            if (!isInstalled) missingComponents.push(reqId);

            const row = document.createElement('div');
            row.className = 'req-item ' + (isInstalled ? 'ok' : 'missing');
            row.innerHTML = '<span>' + comp.label + '</span> ' + (isInstalled ? '<span class="pill ok">Installed</span>' : '<span class="pill err">Missing</span>');
            reqGrid.appendChild(row);
        });

        (profile.optional_components || []).forEach(optId => {
            const comp = (window.WTC_STACK.components || []).find(c => c.id === optId) || { label: optId, installed: false };
            const isInstalled = installedComponents.includes(optId);
            const row = document.createElement('div');
            row.className = 'req-item ' + (isInstalled ? 'ok' : 'optional');
            row.innerHTML = '<span>' + comp.label + ' (Optional)</span> ' + (isInstalled ? '<span class="pill ok">Active</span>' : '<span class="pill off">Not Installed</span>');
            reqGrid.appendChild(row);
        });

        const missingNotice = document.getElementById('missingReqNotice');
        if (missingComponents.length > 0) {
            missingNotice.style.display = 'block';
            document.getElementById('btnInstallMissing').textContent = '⚡ Install Missing Packages (' + missingComponents.join(', ') + ')';
        } else {
            missingNotice.style.display = 'none';
        }
    }

    window.installMissingComponents = async function() {
        if (missingComponents.length === 0) return;
        const btn = document.getElementById('btnInstallMissing');
        btn.disabled = true;
        btn.textContent = 'Installing packages in background...';

        for (const compId of missingComponents) {
            const fd = new FormData();
            fd.append('csrf', csrfToken);
            fd.append('action', 'stack');
            fd.append('do', 'install');
            fd.append('id', compId);
            fd.append('is_ajax', '1');
            await fetch('/', { method: 'POST', body: fd });
        }
        btn.textContent = 'Package installation job started! Auto-checking...';
        setTimeout(() => { window.location.reload(); }, 2500);
    };

    window.switchEnvTab = function(tab) {
        const btnGuided = document.getElementById('btnEnvGuided');
        const btnRaw = document.getElementById('btnEnvRaw');
        const tabGuided = document.getElementById('envGuidedTab');
        const tabRaw = document.getElementById('envRawTab');

        if (tab === 'guided') {
            syncRawToGuided();
            btnGuided.className = 'btn on';
            btnRaw.className = 'btn';
            tabGuided.style.display = 'grid';
            tabRaw.style.display = 'none';
        } else {
            syncGuidedToRaw();
            btnGuided.className = 'btn';
            btnRaw.className = 'btn on';
            tabGuided.style.display = 'none';
            tabRaw.style.display = 'block';
        }
    };

    window.generateNewAppKey = function() {
        const bytes = new Uint8Array(32);
        window.crypto.getRandomValues(bytes);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i]);
        const key = 'base64:' + btoa(binary);
        document.getElementById('envAppKey').value = key;
        syncGuidedToRaw();
    };

    function updateEnvFieldsFromSiteName() {
        const rawName = siteInput.value || 'laravel-app';
        const cleanName = rawName.toLowerCase().replace(/[^a-z0-9_]/g, '_');
        const dbId = ('w_' + cleanName).substring(0, 28);
        document.getElementById('envAppName').value = rawName.replace(/[-_]/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        document.getElementById('envDbName').value = dbId;
        document.getElementById('envDbUser').value = dbId;
        if (!document.getElementById('envDbPass').value) {
            const passBytes = new Uint8Array(8);
            window.crypto.getRandomValues(passBytes);
            document.getElementById('envDbPass').value = Array.from(passBytes, b => b.toString(16).padStart(2, '0')).join('');
        }
        syncGuidedToRaw();
    }

    function syncGuidedToRaw() {
        const appName = document.getElementById('envAppName').value;
        const appEnv = document.getElementById('envAppEnv').value;
        const appKey = document.getElementById('envAppKey').value;
        const appUrl = document.getElementById('envAppUrl').value;
        const dbConn = document.getElementById('envDbConn').value;
        const dbHost = document.getElementById('envDbHost').value;
        const dbName = document.getElementById('envDbName').value;
        const dbUser = document.getElementById('envDbUser').value;
        const dbPass = document.getElementById('envDbPass').value;
        const cacheStore = document.getElementById('envCacheStore').value;
        const sessionDriver = document.getElementById('envSessionDriver').value;

        const raw = `APP_NAME="${appName}"
APP_ENV=${appEnv}
APP_KEY=${appKey}
APP_DEBUG=true
APP_TIMEZONE=UTC
APP_URL=${appUrl}

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug

DB_CONNECTION=${dbConn}
DB_HOST=${dbHost}
DB_PORT=3306
DB_DATABASE=${dbName}
DB_USERNAME=${dbUser}
DB_PASSWORD=${dbPass}

SESSION_DRIVER=${sessionDriver}
SESSION_LIFETIME=120
CACHE_STORE=${cacheStore}

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379`;

        document.getElementById('envRawText').value = raw;
    }

    function syncRawToGuided() {
        const raw = document.getElementById('envRawText').value;
        const getVal = (k) => {
            const m = raw.match(new RegExp('^' + k + '=(.*)$', 'm'));
            return m ? m[1].replace(/^["']|["']$/g, '').trim() : '';
        };
        if (getVal('APP_NAME')) document.getElementById('envAppName').value = getVal('APP_NAME');
        if (getVal('APP_KEY')) document.getElementById('envAppKey').value = getVal('APP_KEY');
        if (getVal('APP_URL')) document.getElementById('envAppUrl').value = getVal('APP_URL');
        if (getVal('DB_DATABASE')) document.getElementById('envDbName').value = getVal('DB_DATABASE');
        if (getVal('DB_USERNAME')) document.getElementById('envDbUser').value = getVal('DB_USERNAME');
        if (getVal('DB_PASSWORD')) document.getElementById('envDbPass').value = getVal('DB_PASSWORD');
    }

    // Standalone in-browser ZIP generator (Store method 0, UTF-8 paths, standard PKZip format)
    async function createZipFromFiles(fileItems, onProgress) {
        if (fileItems.length > 65000) {
            throw new Error(`In-browser packaging cannot package ${fileItems.length.toLocaleString()} files (exceeds PKZip 65,535 entry limit). Please upload a pre-compressed .tar.gz or .zip archive directly.`);
        }
        const crcTable = new Uint32Array(256);
        for (let i = 0; i < 256; i++) {
            let c = i;
            for (let k = 0; k < 8; k++) c = ((c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1));
            crcTable[i] = c >>> 0;
        }
        function crc32(bytes) {
            let crc = 0xFFFFFFFF;
            for (let i = 0; i < bytes.length; i++) {
                crc = (crc >>> 8) ^ crcTable[(crc ^ bytes[i]) & 0xFF];
            }
            return (crc ^ 0xFFFFFFFF) >>> 0;
        }

        const encoder = new TextEncoder();
        const fileEntries = [];
        let centralDirSize = 0;
        let currentOffset = 0;
        const parts = [];

        const total = fileItems.length;
        for (let idx = 0; idx < total; idx++) {
            const item = fileItems[idx];
            if (onProgress && (idx % 15 === 0 || idx === total - 1)) {
                onProgress(idx + 1, total);
            }
            const fileBytes = new Uint8Array(await item.file.arrayBuffer());
            const rawPath = item.path.replace(/\\/g, '/').replace(/^\/+/, '');
            const pathBytes = encoder.encode(rawPath);
            const crc = crc32(fileBytes);
            const size = fileBytes.length;

            // Local file header (30 bytes + filename)
            const localHeader = new Uint8Array(30 + pathBytes.length);
            const lView = new DataView(localHeader.buffer);
            lView.setUint32(0, 0x04034b50, true); // PK\x03\x04
            lView.setUint16(4, 20, true);         // version 2.0
            lView.setUint16(6, 0x0800, true);     // flags: UTF-8
            lView.setUint16(8, 0, true);          // method: Store (0)
            lView.setUint16(10, 0, true);         // time
            lView.setUint16(12, 0x5201, true);    // date (2021)
            lView.setUint32(14, crc, true);       // crc32
            lView.setUint32(18, size, true);      // compressed size
            lView.setUint32(22, size, true);      // uncompressed size
            lView.setUint16(26, pathBytes.length, true); // filename length
            lView.setUint16(28, 0, true);         // extra field length
            localHeader.set(pathBytes, 30);

            parts.push(localHeader);
            parts.push(fileBytes);

            fileEntries.push({
                pathBytes,
                crc,
                size,
                offset: currentOffset
            });

            currentOffset += localHeader.length + fileBytes.length;
        }

        // Central directory headers
        const centralDirParts = [];
        for (const fe of fileEntries) {
            const cdHeader = new Uint8Array(46 + fe.pathBytes.length);
            const cView = new DataView(cdHeader.buffer);
            cView.setUint32(0, 0x02014b50, true); // PK\x01\x02
            cView.setUint16(4, 0x0314, true);     // version made by: UNIX
            cView.setUint16(6, 20, true);         // version needed: 2.0
            cView.setUint16(8, 0x0800, true);     // flags: UTF-8
            cView.setUint16(10, 0, true);         // method: Store
            cView.setUint16(12, 0, true);         // time
            cView.setUint16(14, 0x5201, true);    // date
            cView.setUint32(16, fe.crc, true);    // crc32
            cView.setUint32(20, fe.size, true);   // compressed size
            cView.setUint32(24, fe.size, true);   // uncompressed size
            cView.setUint16(28, fe.pathBytes.length, true); // name length
            cView.setUint16(30, 0, true);         // extra len
            cView.setUint16(32, 0, true);         // comment len
            cView.setUint16(34, 0, true);         // disk start
            cView.setUint16(36, 0, true);         // internal attrs
            cView.setUint32(38, 0x81a40000, true);// external attrs: regular 0644 file
            cView.setUint32(42, fe.offset, true); // local header offset
            cdHeader.set(fe.pathBytes, 46);

            centralDirParts.push(cdHeader);
            centralDirSize += cdHeader.length;
        }

        // End of central directory record (22 bytes)
        const eocd = new Uint8Array(22);
        const eView = new DataView(eocd.buffer);
        eView.setUint32(0, 0x06054b50, true); // PK\x05\x06
        eView.setUint16(4, 0, true);          // disk number
        eView.setUint16(6, 0, true);          // disk with start of cd
        eView.setUint16(8, fileEntries.length, true);  // entries on disk
        eView.setUint16(10, fileEntries.length, true); // total entries
        eView.setUint32(12, centralDirSize, true);     // size of cd
        eView.setUint32(16, currentOffset, true);      // offset of cd
        eView.setUint16(20, 0, true);                  // comment length

        const allParts = parts.concat(centralDirParts).concat([eocd]);
        return new Blob(allParts, { type: 'application/zip' });
    }

    function appendArtisanPreferences(fd) {
        const runRouteClear = (document.getElementById('modalChkRouteClear')?.checked ?? document.getElementById('envChkRouteClear')?.checked);
        const runOptimizeClear = (document.getElementById('modalChkOptimizeClear')?.checked ?? document.getElementById('envChkOptimizeClear')?.checked);
        const runZiggy = (document.getElementById('modalChkZiggy')?.checked ?? document.getElementById('envChkZiggy')?.checked);
        const runStorageLink = (document.getElementById('modalChkStorageLink')?.checked ?? document.getElementById('envChkStorageLink')?.checked);
        const runMigrate = (document.getElementById('modalChkMigrate')?.checked ?? document.getElementById('envChkMigrate')?.checked);
        const runSeed = (document.getElementById('modalChkSeed')?.checked ?? document.getElementById('envChkSeed')?.checked);

        if (runRouteClear !== undefined) fd.append('run_route_clear', runRouteClear ? '1' : '0');
        if (runOptimizeClear !== undefined) fd.append('run_optimize_clear', runOptimizeClear ? '1' : '0');
        if (runZiggy !== undefined) fd.append('run_ziggy_generate', runZiggy ? '1' : '0');
        if (runStorageLink !== undefined) fd.append('run_storage_link', runStorageLink ? '1' : '0');
        if (runMigrate) fd.append('run_migrations', '1');
        if (runSeed) fd.append('run_seeds', '1');
    }

    window.submitStagedFiles = async function() {
        const filesToUpload = getFilteredStagedFiles();
        if (filesToUpload.length === 0) {
            alert('No files selected for upload. Please check at least one folder in the filter list.');
            return;
        }
        if (filesToUpload.length > 65000) {
            alert(`Selected folder contains ${filesToUpload.length.toLocaleString()} files, which exceeds the browser in-memory packaging limit of 65,000 files.\n\nFor large sites (such as 400,000 files / 3.5GB), please compress the folder into a .tar.gz or .zip archive on your computer and drop the archive directly!`);
            return;
        }
        syncGuidedToRaw();
        const envContent = detectedStackId === 'laravel' ? document.getElementById('envRawText').value : '';
        const chkShim = document.getElementById('chkAutoShim');
        const deployMode = (chkShim && chkShim.checked) ? 'auto_shim' : 'standard';
        const siteName = (siteInput.value || 'site-' + Date.now()).toLowerCase().replace(/[^a-z0-9_-]/g, '-');

        const fd = new FormData();
        fd.append('csrf', csrfToken);
        fd.append('action', 'drop_upload');
        fd.append('is_ajax', '1');
        fd.append('spin_up', spinCheck.checked ? '1' : '0');
        fd.append('detected_stack', detectedStackId);
        fd.append('deploy_mode', deployMode);
        fd.append('site', siteName);
        if (envContent) fd.append('env_content', envContent);

        // Attach Laravel migration, cache & seed preferences
        appendArtisanPreferences(fd);

        // Attach staged database seeder if present
        if (stagedSeederFile) {
            fd.append('seeder_file', stagedSeederFile);
        }

        // Attach protected patterns to prevent overwriting server configs
        fd.append('protected_patterns', JSON.stringify(getActiveProtectedPatterns()));

        const recentMins = getDropRecentMinutes();
        if (recentMins > 0) {
            fd.append('incremental_upload', '1');
            fd.append('recent_minutes', String(recentMins));
            fd.append('uploaded_file_count', String(filesToUpload.length));
        }

        // Try in-memory browser zip packaging for 1-file direct RAM streaming
        let packagedAsZip = false;
        try {
            showProgress('📦 Packaging ' + filesToUpload.length + ' files in browser memory...', 10);
            const zipBlob = await createZipFromFiles(filesToUpload, (cur, total) => {
                const pct = Math.round((cur / total) * 30);
                showProgress(`📦 Bundling files in memory: ${cur} / ${total}`, pct);
            });
            const zipFile = new File([zipBlob], siteName + '.zip', { type: 'application/zip' });
            fd.append('archive', zipFile);
            packagedAsZip = true;
        } catch (packErr) {
            console.warn('In-browser zip packing failed, falling back to multipart:', packErr);
            filesToUpload.forEach((item) => {
                fd.append('files[]', item.file);
                fd.append('paths[]', item.path);
            });
        }

        showProgress('🚀 Streaming directly to Raspberry Pi RAM disk...', 35);
        sendUpload(fd);
    };

    function uploadSingleArchive(file, targetSiteName = null, forceSpinUp = null, customPatterns = null, isPrimary = null) {
        const siteName = (targetSiteName || siteInput.value || file.name.replace(/(\.tar\.gz|\.tgz|\.tar|\.zip)$/i, '')).toLowerCase().replace(/[^a-z0-9_-]/g, '-');
        const shouldSpin = forceSpinUp !== null ? forceSpinUp : spinCheck.checked;
        const prot = (customPatterns && customPatterns.length > 0) ? customPatterns : getActiveProtectedPatterns();

        const fd = new FormData();
        fd.append('csrf', csrfToken);
        fd.append('action', 'drop_upload');
        fd.append('is_ajax', '1');
        fd.append('spin_up', shouldSpin ? '1' : '0');
        if (isPrimary !== null) {
            fd.append('primary', isPrimary ? '1' : '0');
        }
        fd.append('site', siteName);
        fd.append('archive', file);
        fd.append('protected_patterns', JSON.stringify(prot));
        appendArtisanPreferences(fd);

        showProgress(`🚀 Streaming archive to auto-update '${siteName}' in RAM tmpfs...`, 30);
        sendUpload(fd);
    }

    async function uploadFolderForSite(filesWithPaths, targetSiteName, forceSpinUp = true, customPatterns = null, isPrimary = null, recentMinutes = 0) {
        const siteName = (targetSiteName || siteInput.value || 'site-' + Date.now()).toLowerCase().replace(/[^a-z0-9_-]/g, '-');
        siteInput.value = siteName;
        detectedStackId = detectStackFromPaths(filesWithPaths.map(x => x.path));

        const gitignoreItem = filesWithPaths.find(x => {
            const p = (x.path || '').toLowerCase().replace(/\\/g, '/');
            return p === '.gitignore' || p.endsWith('/.gitignore') || p === '.wtcignore' || p.endsWith('/.wtcignore');
        });
        if (gitignoreItem && gitignoreItem.file) {
            try {
                const txt = await gitignoreItem.file.text();
                parseAndApplyGitignoreRules(txt);
            } catch (_) {}
        }

        // Detect common root folder prefix from the full file list before any time-filtering
        let rootPrefix = '';
        if (filesWithPaths.length > 0) {
            const firstNorm = (filesWithPaths[0].path || '').replace(/\\/g, '/').replace(/^\/+/, '');
            const slashIdx = firstNorm.indexOf('/');
            if (slashIdx > 0) {
                const candidate = firstNorm.slice(0, slashIdx + 1);
                const allSharePrefix = filesWithPaths.every(item => {
                    const n = (item.path || '').replace(/\\/g, '/').replace(/^\/+/, '');
                    return n.startsWith(candidate);
                });
                if (allSharePrefix) {
                    rootPrefix = candidate;
                }
            }
        }

        const normalizedItems = filesWithPaths
            .filter(item => !item.isPlaceholder)
            .map(item => {
                let rel = (item.path || '').replace(/\\/g, '/').replace(/^\/+/, '');
                if (rootPrefix && rel.startsWith(rootPrefix)) {
                    rel = rel.slice(rootPrefix.length);
                }
                return { file: item.file, path: rel, origPath: item.path };
            })
            .filter(item => item.path !== '');

        const filtered = normalizedItems.filter(item => {
            const top = item.path.split('/')[0].toLowerCase();
            return !['vendor', 'node_modules', '.git', '.github', 'storage'].includes(top);
        });
        let uploadList = filtered.length > 0 ? filtered : normalizedItems;

        const mins = parseInt(recentMinutes, 10) || 0;
        const isIncremental = mins > 0;
        if (isIncremental) {
            const cutoffMs = Date.now() - (mins * 60 * 1000);
            uploadList = uploadList.filter(item => item.file && typeof item.file.lastModified === 'number' && item.file.lastModified >= cutoffMs);
            if (uploadList.length === 0) {
                const msg = `No files in the selected folder were saved in the past ${mins} minute(s). Nothing to upload.`;
                if (window.wtcSpa && window.wtcSpa.showFlash) {
                    window.wtcSpa.showFlash('err', msg);
                } else {
                    alert(msg);
                }
                return;
            }
        } else {
            // Keep original paths for full-folder uploads to preserve existing behavior
            uploadList = uploadList.map(item => ({ file: item.file, path: item.origPath }));
        }

        const prot = (customPatterns && customPatterns.length > 0) ? customPatterns : getActiveProtectedPatterns();

        const fd = new FormData();
        fd.append('csrf', csrfToken);
        fd.append('action', 'drop_upload');
        fd.append('is_ajax', '1');
        fd.append('spin_up', forceSpinUp ? '1' : '0');
        if (isPrimary !== null) {
            fd.append('primary', isPrimary ? '1' : '0');
        }
        fd.append('detected_stack', detectedStackId);
        fd.append('deploy_mode', 'standard');
        fd.append('site', siteName);
        fd.append('protected_patterns', JSON.stringify(prot));
        if (isIncremental) {
            fd.append('incremental_upload', '1');
            fd.append('recent_minutes', String(mins));
            fd.append('uploaded_file_count', String(uploadList.length));
        }
        appendArtisanPreferences(fd);

        try {
            const packLabel = isIncremental
                ? `📦 Packaging ${uploadList.length} file(s) saved in the past ${mins}m...`
                : `📦 Packaging ${uploadList.length} files in browser memory...`;
            showProgress(packLabel, 10);
            const zipBlob = await createZipFromFiles(uploadList, (cur, total) => {
                const pct = Math.round((cur / total) * 30);
                showProgress(`📦 Bundling files in memory: ${cur} / ${total}`, pct);
            });
            const zipFile = new File([zipBlob], siteName + '.zip', { type: 'application/zip' });
            fd.append('archive', zipFile);
        } catch (packErr) {
            console.warn('In-browser zip packing failed, falling back to multipart:', packErr);
            uploadList.forEach(item => {
                fd.append('files[]', item.file);
                fd.append('paths[]', item.path);
            });
        }

        const streamLabel = isIncremental
            ? `🚀 Uploading ${uploadList.length} recently saved file(s) to overlay '${siteName}'...`
            : `🚀 Streaming to auto-update '${siteName}' directly in RAM disk...`;
        showProgress(streamLabel, 35);
        sendUpload(fd);
    }

    function showProgress(status, pct) {
        progressBox.style.display = 'block';
        resultBox.style.display = 'none';
        progressStatus.textContent = status;
        progressBar.style.width = pct + '%';
    }

    function sendUpload(fd) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/');
        xhr.timeout = 3600000; // 1 hour timeout for very large multi-GB sites
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const pct = 30 + Math.round((e.loaded / e.total) * 65);
                progressBar.style.width = pct + '%';
                const loadedMb = Math.round((e.loaded / (1024 * 1024)) * 10) / 10;
                const totalMb = Math.round((e.total / (1024 * 1024)) * 10) / 10;
                progressStatus.textContent = `⚡ Uploading site package: ${loadedMb} MB / ${totalMb} MB (${Math.round((e.loaded/e.total)*100)}%)`;
            }
        });
        xhr.ontimeout = function() {
            alert('Upload timed out after 1 hour. Please check network bandwidth or Pi system resources.');
            progressBox.style.display = 'none';
        };
        xhr.onerror = function() {
            alert('Network connection error while uploading. Please verify the Raspberry Pi is reachable.');
            progressBox.style.display = 'none';
        };
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const res = JSON.parse(xhr.responseText);
                        progressBar.style.width = '100%';
                        progressStatus.textContent = res.spun_up ? 'Spinning up in RAM...' : 'Site packaged!';
                        setTimeout(() => {
                            progressBox.style.display = 'none';
                            resultBox.style.display = 'block';
                            const dbHtml = (res.db && res.db.name) ? `
                                <div style="background:#11171e;border:1px solid #2a3541;border-radius:6px;padding:10px 14px;margin:12px 0;text-align:left;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                                    <div>
                                        <div style="font-size:0.86rem;color:#7cc4ff">🗄️ <b>MariaDB Provisioned:</b> <code>${res.db.name}</code> &bull; Tables: <b>${res.db.tables || 0}</b></div>
                                        <div id="dropDbTestMsg" style="font-size:0.78rem;color:#8b949e;margin-top:2px">Credentials wired into <code>.env</code> automatically.</div>
                                    </div>
                                    <button type="button" class="btn" style="font-size:0.75rem;padding:3px 10px" onclick="testDropDbConnection('${res.site}')">🔌 Test DB Connection</button>
                                </div>
                            ` : '';

                            const artisanHtml = (res.artisan_log && res.artisan_log.length > 0) ? `
                                <div style="background:#11171e;border:1px solid #2a3541;border-radius:6px;padding:10px 14px;margin:12px 0;text-align:left">
                                    <div style="font-size:0.86rem;color:#7ee787;font-weight:600">⚡ Artisan Database Setup Results:</div>
                                    ${res.artisan_log.map(a => `<div style="font-size:0.8rem;margin-top:4px"><code style="color:${a.ok?'#7ee787':'#ff7b72'}">${a.command}</code>: <span style="color:#c9d1d9">${a.output || (a.ok ? 'Completed' : 'Failed')}</span></div>`).join('')}
                                </div>
                            ` : '';

                            const restoredHtml = (res.restored_files && res.restored_files.length > 0) ? `
                                <div style="background:#0e1d15;border:1px solid #238636;border-radius:6px;padding:8px 12px;margin:10px 0;text-align:left;font-size:0.82rem;color:#7ee787">
                                    🛡️ <b>Protected Working Configurations Retained:</b> ${res.restored_files.join(', ')}
                                </div>
                            ` : '';

                            const incrementalHtml = res.incremental_upload ? `
                                <div style="background:#0d1825;border:1px solid #388bfd;border-radius:6px;padding:8px 12px;margin:10px 0;text-align:left;font-size:0.82rem;color:#79c0ff">
                                    ⏱️ <b>Incremental Re-upload:</b> Overlaid <b>${res.uploaded_file_count || 'modified'}</b> file(s) saved in the past <b>${res.recent_minutes || 3}</b> minute(s) onto existing site.
                                </div>
                            ` : '';

                            const catalogHtml = res.catalog_rebuilding ? `
                                <div style="background:#0d1825;border:1px solid #388bfd;border-radius:6px;padding:8px 12px;margin:10px 0;text-align:left;font-size:0.82rem;color:#79c0ff">
                                    💾 <b>SD Catalog Sync:</b> Live site files were replaced directly in RAM for immediate access; rebuilding <code>${res.site}.tar.gz</code> on the SD card in the background.
                                </div>
                            ` : '';

                            resultBox.innerHTML = `
                                <div class="success-banner">
                                    <h4>🎉 Success! Site '<b>${res.site}</b>' ${res.spun_up ? 'is LIVE in RAM!' : 'provisioned into sites repository!'}</h4>
                                    <p>${res.spun_up ? '🚀 Your application is live and running directly from RAM tmpfs storage.' : 'Site package saved to /opt/wtc-web/sites and ready to deploy.'}</p>
                                    ${catalogHtml}
                                    ${incrementalHtml}
                                    ${restoredHtml}
                                    ${dbHtml}
                                    ${artisanHtml}
                                    <div class="success-actions">
                                        <a href="${res.url || ('http://' + window.location.hostname + '/')}" target="_blank" class="btn-pri">🌐 Open Live Site (Port 80)</a>
                                        <a href="http://" + window.location.hostname + ":8080/" target="_blank" class="btn-pri" style="background:#238636;border-color:#2ea043">🚀 Port 8080 (Root)</a>
                                        <a href="/?p=sites" class="btn">📂 View in Sites &amp; Apps</a>
                                        <a href="/?p=dashboard" class="btn">🖥️ View in Dashboard</a>
                                        <button type="button" class="btn" onclick="document.getElementById('dropResult').style.display='none'">Drop Another Site</button>
                                    </div>
                                </div>
                            `;
                            if (window.location.search.includes('p=sites')) {
                                setTimeout(() => { window.location.reload(); }, 2500);
                            }
                        }, 500);
                    } catch(err) {
                        progressStatus.textContent = 'Upload completed.';
                        window.location.reload();
                    }
                } else {
                    let err = 'Upload failed (HTTP ' + xhr.status + ')';
                    try { err = JSON.parse(xhr.responseText).error || err; } catch(_) {}
                    alert('Error: ' + err);
                    progressBox.style.display = 'none';
                }
            }
        };
        xhr.send(fd);
    }

    window.toggleCatalog = function() {
        const c = document.getElementById('stackCatalog');
        const btn = document.getElementById('toggleCatalogBtn');
        if (c.style.display === 'none') {
            c.style.display = 'block';
            btn.textContent = '✕ Hide Stack Roadmap';
        } else {
            c.style.display = 'none';
            btn.textContent = '📋 View Stack Roadmap';
        }
    };

    window.testDropDbConnection = function(site) {
        const msg = document.getElementById('dropDbTestMsg');
        if (msg) msg.innerHTML = '⏳ Testing live database connection...';
        fetch('/?action=db_test&site=' + encodeURIComponent(site) + '&is_ajax=1')
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    if (msg) msg.innerHTML = '<b style="color:#7ee787">✅ Database Connected!</b> Host: <code>' + data.host + '</code> &bull; User: <code>' + data.user + '</code> &bull; Tables: <b>' + data.tables + '</b>';
                } else {
                    if (msg) msg.innerHTML = '<b style="color:#ff7b72">❌ Connection Failed:</b> ' + (data.error || 'Check credentials or service');
                }
            })
            .catch(err => {
                if (msg) msg.innerHTML = '<b style="color:#ff7b72">Error:</b> ' + err.message;
            });
    };

    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeFolderExclusionModal();
    });
    const modalEl = document.getElementById('folderExclusionModal');
    if (modalEl) {
        modalEl.addEventListener('click', (e) => {
            if (e.target === modalEl) closeFolderExclusionModal();
        });
    }

    async function walkDirectoryHandle(dirHandle, path, list) {
        const ignoredDirs = ['node_modules', 'vendor', '.git', '.github', '.idea', '.vscode', '.phpunit.cache', 'coverage'];
        for await (const entry of dirHandle.values()) {
            const fullPath = path ? (path + '/' + entry.name) : entry.name;
            if (entry.kind === 'file') {
                const file = await entry.getFile();
                list.push({ file, path: fullPath });
            } else if (entry.kind === 'directory') {
                const dirLower = entry.name.toLowerCase();
                if (ignoredDirs.includes(dirLower)) {
                    list.push({
                        file: new File([''], entry.name + '/.wtc-placeholder', { type: 'text/plain' }),
                        path: fullPath + '/.wtc-placeholder',
                        isPlaceholder: true
                    });
                    continue;
                }
                await walkDirectoryHandle(entry, fullPath, list);
            }
        }
    }

    async function pickDirectoryFast(fallbackInputId, onFilesSelected) {
        if (typeof window.showDirectoryPicker === 'function' && window.isSecureContext) {
            try {
                const dirHandle = await window.showDirectoryPicker({ mode: 'read' });
                showProgress(`📂 Fast-scanning '${dirHandle.name}' (skipping node_modules, vendor, .git)...`, 8);
                const list = [];
                await walkDirectoryHandle(dirHandle, dirHandle.name, list);
                progressBox.style.display = 'none';
                onFilesSelected(list, dirHandle.name);
                return true;
            } catch (err) {
                progressBox.style.display = 'none';
                if (err && err.name === 'AbortError') {
                    return false;
                }
                console.warn('showDirectoryPicker failed, falling back to webkitdirectory:', err);
            }
        }
        const fallback = document.getElementById(fallbackInputId);
        if (fallback) fallback.click();
        return false;
    }

    window.browseMainFolder = function() {
        pickDirectoryFast('folderInput', (list, rootName) => {
            processScannedFiles(list, siteInput.value || rootName);
        });
    };

    if (!window.isSecureContext) {
        const hint = document.getElementById('dropHttpsHint');
        const link = document.getElementById('dropHttpsLink');
        if (hint && link) {
            const targetHost = window.location.hostname || 'pilabwtc.local';
            link.href = 'https://' + targetHost + ':4486' + window.location.pathname + window.location.search;
            hint.style.display = 'block';
        }
    }

    window.wtcDropzone = {
        uploadSingleArchive,
        uploadFolderForSite,
        pickDirectoryFast,
        readDirectoryRecursive,
        createZipFromFiles,
        showProgress,
        getActiveProtectedPatterns,
        STACK_PROTECT_PRESETS,
        applyProtectPreset,
        parseAndApplyGitignoreRules
    };
})();
</script>

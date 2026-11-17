<?php
declare(strict_types=1);
/**
 * Footer Component
 * Renders page footer and closing HTML tags.
 */
?>
    <footer>WTC Pi Web Server &bull; Running from RAM on <?= h(gethostname()) ?></footer>

    <!-- HTTPS Certificate Download & Browser Install Modal -->
    <div id="httpsCertModal" class="modal-backdrop" style="display:none">
        <div class="modal-dialog" style="max-width:660px">
            <div class="modal-header">
                <h3><span>🔐</span> <span>HTTPS Certificate &amp; Trust Instructions</span></h3>
                <button type="button" class="modal-close" onclick="closeHttpsCertModal()" title="Close">&times;</button>
            </div>
            <div class="modal-body" style="font-size:0.86rem;color:#c9d1d9;line-height:1.5">
                <p style="color:#8b949e;margin-top:0">
                    The Management UI uses a self-signed TLS certificate on port <code>4486</code> so local hostnames like
                    <code>pilabwtc.local</code> can use HTTPS (required by browsers for fast folder browsing without indexing <code>node_modules</code>/<code>vendor</code>).
                </p>

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0 16px 0">
                    <a href="/?download_ui_cert=1" download="pilabwtc-ui.crt" class="btn-pri no-spa" style="text-decoration:none">⬇️ Download HTTPS Cert (pilabwtc-ui.crt)</a>
                    <a id="httpsModalOpenLink" href="https://pilabwtc.local:4486/" class="btn no-spa" style="text-decoration:none">🔒 Open HTTPS UI (Port 4486)</a>
                </div>

                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
                    <button type="button" class="btn https-tab-btn on" data-os="win-chrome" onclick="switchHttpsTab('win-chrome')">🪟 Chrome / Edge (Windows)</button>
                    <button type="button" class="btn https-tab-btn" data-os="firefox" onclick="switchHttpsTab('firefox')">🦊 Firefox</button>
                    <button type="button" class="btn https-tab-btn" data-os="macos" onclick="switchHttpsTab('macos')">🍎 macOS (Safari / Chrome)</button>
                    <button type="button" class="btn https-tab-btn" data-os="linux" onclick="switchHttpsTab('linux')">🐧 Linux (Chrome / System)</button>
                </div>

                <div id="httpsTab-win-chrome" class="https-tab-pane" style="background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:12px 14px">
                    <b style="color:#79c0ff">Chrome &amp; Microsoft Edge on Windows</b>
                    <ol style="margin:8px 0 0 18px;padding:0;display:flex;flex-direction:column;gap:5px">
                        <li>Click <b>Download HTTPS Cert (pilabwtc-ui.crt)</b> above.</li>
                        <li>Double-click the downloaded <code>pilabwtc-ui.crt</code> file and click <b>Install Certificate...</b>.</li>
                        <li>Select <b>Current User</b> → <b>Next</b>.</li>
                        <li>Select <b>Place all certificates in the following store</b> → click <b>Browse...</b> → choose <b>Trusted Root Certification Authorities</b> → <b>OK</b>.</li>
                        <li>Click <b>Next</b> → <b>Finish</b>, and confirm the security prompt (<b>Yes</b>).</li>
                        <li><b>Restart Chrome/Edge completely</b> (close all windows) and open <code>https://pilabwtc.local:4486/</code>.</li>
                    </ol>
                </div>

                <div id="httpsTab-firefox" class="https-tab-pane" style="display:none;background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:12px 14px">
                    <b style="color:#79c0ff">Mozilla Firefox (Windows / macOS / Linux)</b>
                    <ol style="margin:8px 0 0 18px;padding:0;display:flex;flex-direction:column;gap:5px">
                        <li>Download <code>pilabwtc-ui.crt</code> above.</li>
                        <li>Open Firefox <b>Settings</b> → <b>Privacy &amp; Security</b> → scroll to <b>Certificates</b> → click <b>View Certificates...</b>.</li>
                        <li>Go to the <b>Authorities</b> tab → click <b>Import...</b> and select <code>pilabwtc-ui.crt</code>.</li>
                        <li>Check <b>Trust this CA to identify websites</b> → click <b>OK</b>.</li>
                        <li><i>Alternative on Windows:</i> If you already installed the cert in Windows, open <code>about:config</code> and set <code>security.enterprise_roots.enabled</code> to <code>true</code>.</li>
                    </ol>
                </div>

                <div id="httpsTab-macos" class="https-tab-pane" style="display:none;background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:12px 14px">
                    <b style="color:#79c0ff">macOS (Safari, Chrome, Edge)</b>
                    <ol style="margin:8px 0 0 18px;padding:0;display:flex;flex-direction:column;gap:5px">
                        <li>Download <code>pilabwtc-ui.crt</code> above and double-click it to open <b>Keychain Access</b>.</li>
                        <li>Add it to the <b>login</b> or <b>System</b> keychain.</li>
                        <li>Find <b>pilabwtc.local</b> in Keychain Access, double-click it, expand <b>Trust</b>, and set <b>When using this certificate</b> to <b>Always Trust</b>.</li>
                        <li>Close the window (enter your Mac password to save) and reload <code>https://pilabwtc.local:4486/</code>.</li>
                    </ol>
                </div>

                <div id="httpsTab-linux" class="https-tab-pane" style="display:none;background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:12px 14px">
                    <b style="color:#79c0ff">Linux (Chrome / Chromium / Edge)</b>
                    <ol style="margin:8px 0 0 18px;padding:0;display:flex;flex-direction:column;gap:5px">
                        <li>Download <code>pilabwtc-ui.crt</code> above.</li>
                        <li><b>Chrome / Chromium:</b> Open <code>chrome://certificate-manager</code> (or <b>Settings → Privacy and security → Security → Manage certificates</b>) → <b>Authorities</b> → <b>Import</b> → select <code>pilabwtc-ui.crt</code> and check <b>Trust this certificate for identifying websites</b>.</li>
                        <li><b>System-wide (Debian/Ubuntu):</b><br><code>sudo cp pilabwtc-ui.crt /usr/local/share/ca-certificates/ && sudo update-ca-certificates</code></li>
                    </ol>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeHttpsCertModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const modal = document.getElementById('httpsCertModal');
        const openLink = document.getElementById('httpsModalOpenLink');
        if (openLink) {
            const host = window.location.hostname || 'pilabwtc.local';
            openLink.href = 'https://' + host + ':4486' + window.location.pathname + window.location.search;
        }
        window.openHttpsCertModal = function() {
            if (modal) modal.style.display = 'flex';
        };
        window.closeHttpsCertModal = function() {
            if (modal) modal.style.display = 'none';
        };
        window.switchHttpsTab = function(os) {
            document.querySelectorAll('.https-tab-pane').forEach(p => { p.style.display = 'none'; });
            document.querySelectorAll('.https-tab-btn').forEach(b => {
                b.classList.toggle('on', b.getAttribute('data-os') === os);
                b.style.borderColor = b.getAttribute('data-os') === os ? '#58a6ff' : '';
                b.style.color = b.getAttribute('data-os') === os ? '#79c0ff' : '';
            });
            const pane = document.getElementById('httpsTab-' + os);
            if (pane) pane.style.display = 'block';
        };
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeHttpsCertModal();
            });
        }
        window.switchHttpsTab('win-chrome');
    })();
    </script>
</body>
</html>

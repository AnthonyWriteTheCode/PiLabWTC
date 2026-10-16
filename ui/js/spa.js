/**
 * WTC Pi Management UI - Vanilla Single Page Application (SPA) Engine
 * Zero external libraries (No jQuery, React, Vue, or Axios).
 * Handles:
 *  - Client-side pushState / popstate tab navigation
 *  - Form submission interception via fetch()
 *  - Dynamic action loading modal for long-running backend commands (swapping sites, migrations, restarts)
 *  - Live progress bar & dynamic toast flash notifications
 *  - Responsive navigation mobile drawer toggle
 */

(() => {
    'use strict';

    // State tracker
    let isNavigating = false;
    let loadingTimer = null;
    let loadingStartTime = 0;

    // Command action descriptions for the working modal
    const ACTION_MESSAGES = {
        'activate': {
            title: '⚡ Swapping Active Site',
            sub: 'Unpacking site archive into high-speed RAM drive and testing web routing...',
            icon: '⚡'
        },
        'build': {
            title: '🔨 Starting Background Build',
            sub: 'Spawning npm install && npm run build runner inside RAM drive...',
            icon: '🔨'
        },
        'rollback': {
            title: '⏪ Rolling Back Application',
            sub: 'Restoring previous version from SD backup archive into RAM...',
            icon: '⏪'
        },
        'delete': {
            title: '🗑️ Deleting Site Package',
            sub: 'Removing site archive and cached metadata from storage...',
            icon: '🗑️'
        },
        'create': {
            title: '✨ Creating Site Skeleton',
            sub: 'Initializing starter site structure in storage...',
            icon: '✨'
        },
        'reload_ui': {
            title: '🔄 Reloading UI from SD Card',
            sub: 'Copying updated Management UI into volatile RAM drive and refreshing...',
            icon: '🔄'
        },
        'rescan_sites': {
            title: '🔍 Rescanning Site Packages',
            sub: 'Deep inspecting site archives to detect frameworks and refresh metadata...',
            icon: '🔍'
        },
        'rescan_stack': {
            title: '🔄 Scanning System Packages',
            sub: 'Querying Debian dpkg cache for installed runtime extensions...',
            icon: '🔄'
        },
        'save_state': {
            title: '💾 Saving RAM Site State',
            sub: 'Snapshotting live RAM file changes back into persistent SD storage...',
            icon: '💾'
        },
        'artisan': {
            title: '⚡ Executing Artisan Commands',
            sub: 'Running Laravel migrations and seeders against MariaDB...',
            icon: '⚡'
        },
        'device': {
            title: '⚙️ Executing System Command',
            sub: 'Sending privileged hardware / daemon signal to Raspberry Pi...',
            icon: '⚙️'
        },
        'nginx_save': {
            title: '🌐 Saving Nginx Configuration',
            sub: 'Writing Nginx vhost config, testing syntax, and reloading service...',
            icon: '🌐'
        },
        'nginx_ctl': {
            title: '🌐 Managing Nginx Service',
            sub: 'Sending service reload/restart command to Nginx daemon...',
            icon: '🌐'
        },
        'nginx_reset': {
            title: '🔄 Resetting Nginx Configuration',
            sub: 'Regenerating default stack configuration for active application...',
            icon: '🔄'
        },
        'workers': {
            title: '👷 Managing Background Workers',
            sub: 'Controlling queue:work and schedule:work daemon processes...',
            icon: '👷'
        },
        'stack': {
            title: '📦 Managing Server Package',
            sub: 'Executing apt background package management...',
            icon: '📦'
        },
        'setting': {
            title: '⚙️ Applying Server Settings',
            sub: 'Updating system environment variables and persistence settings...',
            icon: '⚙️'
        },
        'transfer': {
            title: '🔄 Updating Transfer Service',
            sub: 'Configuring Samba file share / FTP server daemon...',
            icon: '🔄'
        },
        'firewall': {
            title: '🛡️ Updating Firewall Rules',
            sub: 'Configuring UFW rules and port filtering on the system...',
            icon: '🛡️'
        }
    };

    /**
     * Show or update the Top Micro Progress Bar
     */
    function setProgressBar(show) {
        let bar = document.getElementById('spaProgressBar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'spaProgressBar';
            bar.className = 'spa-progress-bar';
            document.body.prepend(bar);
        }
        if (show) {
            bar.classList.add('active');
        } else {
            bar.classList.remove('active');
            bar.classList.add('done');
            setTimeout(() => {
                bar.classList.remove('done');
            }, 300);
        }
    }

    /**
     * Show the Interactive Working / Long-Running Action Modal
     */
    function showLoadingModal(action, customText) {
        let modal = document.getElementById('spaLoadingModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'spaLoadingModal';
            modal.className = 'spa-loading-modal-backdrop';
            modal.innerHTML = [
                '<div class="spa-loading-modal-dialog">',
                '    <div class="spa-modal-spinner-wrap">',
                '        <div class="spa-modal-spinner"></div>',
                '        <span id="spaModalIcon" class="spa-modal-icon">⚡</span>',
                '    </div>',
                '    <h3 id="spaModalTitle" class="spa-modal-title">Working in Background...</h3>',
                '    <p id="spaModalSub" class="spa-modal-sub">Please wait while the server completes this action.</p>',
                '    <div class="spa-modal-status-bar">',
                '        <span class="spa-pulse-dot"></span>',
                '        <span id="spaModalElapsed">Elapsed: 0.0s</span>',
                '    </div>',
                '</div>'
            ].join('\n');
            document.body.appendChild(modal);
        }

        const info = ACTION_MESSAGES[action] || {
            title: customText || 'Executing Command...',
            sub: 'Please wait while the server completes this action in RAM.',
            icon: '⚙️'
        };

        const titleEl = document.getElementById('spaModalTitle');
        const subEl = document.getElementById('spaModalSub');
        const iconEl = document.getElementById('spaModalIcon');
        const elapsedEl = document.getElementById('spaModalElapsed');

        if (titleEl) titleEl.textContent = info.title;
        if (subEl) subEl.textContent = info.sub;
        if (iconEl) iconEl.textContent = info.icon;

        loadingStartTime = Date.now();
        if (elapsedEl) elapsedEl.textContent = 'Elapsed: 0.0s';

        clearInterval(loadingTimer);
        loadingTimer = setInterval(() => {
            if (elapsedEl) {
                const sec = ((Date.now() - loadingStartTime) / 1000).toFixed(1);
                elapsedEl.textContent = 'Elapsed: ' + sec + 's';
            }
        }, 100);

        modal.classList.add('visible');
    }

    /**
     * Hide the Action Loading Modal
     */
    function hideLoadingModal() {
        clearInterval(loadingTimer);
        const modal = document.getElementById('spaLoadingModal');
        if (modal) {
            modal.classList.remove('visible');
        }
    }

    /**
     * Show a dynamic Flash Toast Alert
     */
    function showFlash(type, message) {
        if (!message) return;
        const container = document.getElementById('spa-flash') || document.querySelector('.main-container');
        if (!container) return;

        // Remove old flash messages
        const oldFlashes = container.querySelectorAll('.flash');
        oldFlashes.forEach(f => f.remove());

        const flash = document.createElement('div');
        flash.className = 'flash ' + (type === 'err' ? 'err' : 'ok') + ' spa-fade-in';
        flash.textContent = message;

        if (container.id === 'spa-flash') {
            container.appendChild(flash);
        } else {
            container.prepend(flash);
        }

        // Auto-dismiss after 6 seconds
        setTimeout(() => {
            flash.style.opacity = '0';
            flash.style.transition = 'opacity 0.4s ease';
            setTimeout(() => flash.remove(), 400);
        }, 6000);
    }

    /**
     * Re-execute inline scripts within newly injected tab content
     */
    function executeScripts(container) {
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    /**
     * Map of known tab titles for fallback and fast tab title display
     */
    const TAB_TITLES = {
        'dashboard': 'WTC Pi - 🖥️ Device Dashboard',
        'sites':     'WTC Pi - 📁 Sites & Apps',
        'database':  'WTC Pi - 🗄️ Database',
        'explorer':  'WTC Pi - ⚡ RAM Drive',
        'nginx':     'WTC Pi - 🌐 Nginx Config',
        'state':     'WTC Pi - 💾 Save State',
        'transfer':  'WTC Pi - 🔄 File Transfer',
        'stack':     'WTC Pi - 📦 Stack Components',
        'settings':  'WTC Pi - ⚙️ Settings',
        'logs':      'WTC Pi - 📜 Logs'
    };

    /**
     * Update active class and document title in Navigation Header
     */
    function updateActiveNav(pageName) {
        const links = document.querySelectorAll('header nav a');
        let matchedTitle = '';
        links.forEach(a => {
            const pageAttr = a.getAttribute('data-page');
            const url = new URL(a.href, window.location.origin);
            const p = pageAttr || url.searchParams.get('p') || 'dashboard';
            if (p === pageName) {
                a.classList.add('on');
                matchedTitle = a.getAttribute('data-title') || a.textContent.trim();
            } else {
                a.classList.remove('on');
            }
        });
        return matchedTitle;
    }

    /**
     * Decode Base64 UTF-8 string safely in browser
     */
    function decodeBase64Utf8(b64) {
        try {
            const binStr = atob(b64);
            const bytes = Uint8Array.from(binStr, m => m.charCodeAt(0));
            return new TextDecoder().decode(bytes);
        } catch {
            return null;
        }
    }

    /**
     * Load a URL via SPA (GET request)
     */
    async function navigateTo(url, push = true) {
        if (isNavigating) return;
        isNavigating = true;
        setProgressBar(true);

        const targetUrl = new URL(url, window.location.origin);
        // Identify requested page key from query parameter
        const requestedPage = targetUrl.searchParams.get('p') || 'dashboard';

        // Pre-update header tabs and title immediately for instantaneous user feedback
        const navTitle = updateActiveNav(requestedPage);
        if (TAB_TITLES[requestedPage]) {
            document.title = TAB_TITLES[requestedPage];
        } else if (navTitle) {
            document.title = 'WTC Pi - ' + navTitle;
        }

        targetUrl.searchParams.set('spa', '1');

        try {
            const res = await fetch(targetUrl.toString(), {
                headers: {
                    'X-Requested-With': 'SPA',
                    'Accept': 'text/html, application/json'
                }
            });

            if (!res.ok) {
                // If SPA load fails, fallback to full browser navigation
                window.location.href = url;
                return;
            }

            // Extract page key from server response header or URL
            const pageKey = res.headers.get('X-WTC-Page') || requestedPage;

            // Extract Unicode-safe page title
            const b64Title = res.headers.get('X-WTC-Title-B64');
            let decodedTitle = b64Title ? decodeBase64Utf8(b64Title) : null;
            if (!decodedTitle) {
                decodedTitle = TAB_TITLES[pageKey] || res.headers.get('X-WTC-Title');
            }
            if (decodedTitle) {
                document.title = decodedTitle;
            }

            const html = await res.text();
            const contentContainer = document.getElementById('spa-content');
            if (contentContainer) {
                contentContainer.innerHTML = html;
                contentContainer.className = 'tab-container tab-' + pageKey + ' spa-fade-in';
                executeScripts(contentContainer);
            }

            // Ensure active nav state aligns with final loaded pageKey
            updateActiveNav(pageKey);

            if (push) {
                const cleanUrl = new URL(url, window.location.origin);
                cleanUrl.searchParams.delete('spa');
                window.history.pushState({ page: pageKey, url: cleanUrl.toString() }, '', cleanUrl.toString());
            }

            // Close mobile menu if open
            closeMobileNav();

            // Scroll smoothly to top of main content
            window.scrollTo({ top: 0, behavior: 'smooth' });

        } catch (err) {
            console.error('SPA Navigation error:', err);
            window.location.href = url;
        } finally {
            isNavigating = false;
            setProgressBar(false);
        }
    }

    /**
     * Handle Form Submission via AJAX/SPA
     */
    async function submitFormSpa(form) {
        const actionInput = form.querySelector('input[name="action"]');
        const action = (actionInput ? actionInput.value : '') || form.dataset.action || '';

        // If it's a file upload form with a file chosen, let standard upload or dropzone handle it
        const fileInput = form.querySelector('input[type="file"]');
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            showLoadingModal('upload', 'Uploading site package archive...');
            return; // Allow standard form POST so multipart streaming is untouched
        }

        // Show working loading modal for active operations
        showLoadingModal(action);
        setProgressBar(true);

        const formData = new FormData(form);
        const actionUrl = form.getAttribute('action') || window.location.pathname + window.location.search || '/';

        try {
            const res = await fetch(actionUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'SPA',
                    'Accept': 'application/json'
                },
                body: formData
            });

            const data = await res.json().catch(() => null);

            hideLoadingModal();
            setProgressBar(false);

            if (data && data.flash) {
                showFlash(data.flash.type, data.flash.msg);
            }

            // Determine destination page to refresh
            let nextUrl = window.location.href;
            if (data && data.redirect) {
                nextUrl = data.redirect;
            } else if (data && data.page) {
                nextUrl = '/?p=' + encodeURIComponent(data.page);
            }

            // Reload new tab content seamlessly
            await navigateTo(nextUrl, true);

        } catch (err) {
            console.error('SPA form submission failed:', err);
            hideLoadingModal();
            setProgressBar(false);
            showFlash('err', 'Action failed: ' + (err.message || 'Network communication error'));
        }
    }

    /**
     * Mobile Navigation Drawer Toggle
     */
    function toggleMobileNav() {
        const nav = document.querySelector('header nav');
        const burger = document.getElementById('mobileNavToggle');
        if (nav) {
            nav.classList.toggle('open');
            if (burger) {
                burger.setAttribute('aria-expanded', nav.classList.contains('open') ? 'true' : 'false');
            }
        }
    }

    function closeMobileNav() {
        const nav = document.querySelector('header nav');
        if (nav && nav.classList.contains('open')) {
            nav.classList.remove('open');
        }
    }

    /**
     * Event Listeners & Global Delegations
     */
    function initSpa() {
        // 1. Intercept Link Clicks for SPA
        document.addEventListener('click', (e) => {
            // Check if click is on a link or inside a link
            const link = e.target.closest('a');
            if (!link) return;

            // Ignore external links, downloads, new tabs, or explicit opt-outs
            const href = link.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
            if (link.target === '_blank' || link.hasAttribute('download') || link.classList.contains('no-spa')) return;

            const url = new URL(link.href, window.location.origin);
            if (url.origin !== window.location.origin) return;

            // Only intercept management UI links (/?p=... or /)
            if (url.pathname === '/' || url.pathname === '/index.php') {
                e.preventDefault();
                navigateTo(url.toString(), true);
            }
        });

        // 2. Intercept Form Submissions for SPA
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (form && form.method && form.method.toLowerCase() === 'post') {
                // If form has no-spa class or explicit target, skip
                if (form.classList.contains('no-spa') || form.target === '_blank') return;

                // If form is inside Dropzone uploader which handles its own JS upload, skip
                if (form.id === 'dropzoneUploadForm') return;

                // Prevent default full page reload and submit via SPA
                e.preventDefault();
                submitFormSpa(form);
            }
        });

        // 3. Browser Back / Forward Button Handling
        window.addEventListener('popstate', (e) => {
            navigateTo(window.location.href, false);
        });

        // 4. Mobile Hamburger Menu Button
        const burger = document.getElementById('mobileNavToggle');
        if (burger) {
            burger.addEventListener('click', toggleMobileNav);
        }

        // Close mobile nav on click outside
        document.addEventListener('click', (e) => {
            const header = document.querySelector('header');
            if (header && !header.contains(e.target)) {
                closeMobileNav();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSpa);
    } else {
        initSpa();
    }

    // Expose helpers globally if needed by tab scripts
    window.wtcSpa = {
        navigateTo,
        showLoadingModal,
        hideLoadingModal,
        showFlash
    };

})();

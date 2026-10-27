/**
 * WTC Pi - Unified Dashboard Live Engine (dash-live.js)
 * Single-worker friendly: One lightweight poll loop (3s) for the entire dashboard.
 * Eliminates concurrent backend request queues on PHP-FPM wtc-ui pool.
 * Resilient fallback: Automatically recovers CPU telemetry from /?action=sysinfo if needed.
 */
(() => {
    'use strict';

    // Shared utility helpers
    const DashUtil = {
        escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        fmtBytes(b) {
            const bytes = Number(b) || 0;
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let val = bytes;
            let uIdx = 0;
            while (val >= 1024 && uIdx < units.length - 1) {
                val /= 1024;
                uIdx++;
            }
            return (uIdx === 0 ? val.toFixed(0) : val.toFixed(1)) + ' ' + units[uIdx];
        },

        renderSparkline(canvas, data, strokeColor, fillColor = null) {
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            if (!ctx) return;

            const rect = canvas.getBoundingClientRect();
            const w = rect.width || canvas.width || 120;
            const h = rect.height || canvas.height || 36;
            if (canvas.width !== w || canvas.height !== h) {
                canvas.width = w;
                canvas.height = h;
            }

            ctx.clearRect(0, 0, w, h);
            if (!data || data.length < 2) return;

            const min = 0;
            const max = Math.max(100, ...data);
            const range = (max - min) || 1;
            const stepX = (w - 2) / (data.length - 1);

            ctx.beginPath();
            data.forEach((val, i) => {
                const x = 1 + i * stepX;
                const clamped = Math.max(0, Math.min(max, val));
                const y = (h - 2) - ((clamped - min) / range * (h - 4));
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });

            ctx.strokeStyle = strokeColor;
            ctx.lineWidth = 1.8;
            ctx.lineJoin = 'round';
            ctx.lineCap = 'round';
            ctx.stroke();

            if (fillColor) {
                ctx.lineTo(w - 1, h);
                ctx.lineTo(1, h);
                ctx.closePath();
                ctx.fillStyle = fillColor;
                ctx.fill();
            }
        }
    };

    window.DashUtil = DashUtil;

    // History ring buffer kept in sessionStorage for snappy SPA navigation
    const HISTORY_KEY = 'wtc_dash_history';
    function loadHistory() {
        try {
            const raw = sessionStorage.getItem(HISTORY_KEY);
            if (raw) return JSON.parse(raw);
        } catch (_) {}
        return { cpu: [], temp: [], rx: [], tx: [] };
    }

    function saveHistory(hist) {
        try {
            sessionStorage.setItem(HISTORY_KEY, JSON.stringify({
                cpu: (hist.cpu || []).slice(-30),
                temp: (hist.temp || []).slice(-30),
                rx: (hist.rx || []).slice(-30),
                tx: (hist.tx || []).slice(-30)
            }));
        } catch (_) {}
    }

    const stateHistory = loadHistory();

    // Central state manager
    const subscribers = new Map();
    let pollTimer = null;
    let isFetching = false;
    let consecutiveErrors = 0;
    let lastData = null;

    const dashLive = {
        subscribe(key, fn) {
            subscribers.set(key, fn);
            // Ensure timer is actively ticking
            if (!pollTimer) {
                this.start();
            }
            if (lastData) {
                try { fn(lastData); } catch (e) { console.error('dashLive initial subscriber error:', e); }
            } else {
                // Immediate initial fetch
                this.poll(true);
            }
        },

        unsubscribe(key) {
            subscribers.delete(key);
        },

        getLastData() {
            return lastData;
        },

        getHistory() {
            return stateHistory;
        },

        async poll(manual = false) {
            // Guard: Only poll if dashboard elements exist in DOM
            const dashRoot = document.getElementById('dashRoot') ||
                             document.querySelector('.dash-kpis') ||
                             document.querySelector('.hero-device') ||
                             document.getElementById('dashDeviceRibbon');

            if (!dashRoot && !manual) {
                // Skip tick, but DO NOT destroy pollTimer
                return;
            }

            // Pause if document is hidden, unless manual refresh requested
            if (document.hidden && !manual) return;

            // Avoid overlapping requests on single-worker PHP pool
            if (isFetching && !manual) return;
            isFetching = true;

            const pulseEl = document.getElementById('dashLivePulse');
            if (pulseEl) pulseEl.style.opacity = '0.3';

            try {
                let data = null;

                // 1. Primary unified endpoint fetch
                try {
                    const res = await fetch('/?action=dash_live&_=' + Date.now(), {
                        headers: { 'Accept': 'application/json' }
                    });
                    if (res.ok) {
                        data = await res.json();
                    }
                } catch (e) {
                    console.warn('dash_live primary fetch failed:', e);
                }

                // 2. Resilient fallback: If dash_live didn't return valid CPU telemetry, query /?action=sysinfo
                if (!data || !data.cpu || typeof data.cpu.total === 'undefined') {
                    try {
                        const sysRes = await fetch('/?action=sysinfo&_=' + Date.now(), {
                            headers: { 'Accept': 'application/json' }
                        });
                        if (sysRes.ok) {
                            const sysData = await sysRes.json();
                            if (sysData && typeof sysData.total !== 'undefined') {
                                if (!data) data = { status: {} };
                                data.cpu = sysData;
                            }
                        }
                    } catch (sysErr) {
                        console.warn('sysinfo fallback fetch error:', sysErr);
                    }
                }

                if (!data) throw new Error('No dashboard data available');

                consecutiveErrors = 0;
                lastData = data;

                // Record history
                const cpuTot = Number(data.cpu?.total ?? 0);
                const tempNum = parseFloat(data.status?.temp ?? '0') || 0;
                stateHistory.cpu.push(cpuTot);
                stateHistory.temp.push(tempNum);
                if (stateHistory.cpu.length > 30) stateHistory.cpu.shift();
                if (stateHistory.temp.length > 30) stateHistory.temp.shift();
                saveHistory(stateHistory);

                // Fan out to all registered subscribers
                subscribers.forEach((fn, key) => {
                    try {
                        fn(data);
                    } catch (err) {
                        console.error('dashLive error in subscriber:', key, err);
                    }
                });

            } catch (err) {
                consecutiveErrors++;
                console.warn('dashLive poll error (attempt ' + consecutiveErrors + '):', err);
            } finally {
                isFetching = false;
                if (pulseEl) pulseEl.style.opacity = '1';
            }
        },

        start() {
            if (pollTimer) clearInterval(pollTimer);
            this.poll(true);
            // Steady 3-second cycle
            pollTimer = setInterval(() => {
                dashLive.poll();
            }, 3000);
        },

        stop() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }
    };

    window.dashLive = dashLive;

    // Visibility change handler
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && (document.getElementById('dashRoot') || document.querySelector('.dash-kpis'))) {
            dashLive.poll(true);
        }
    });

    // Auto-start immediately
    dashLive.start();
})();

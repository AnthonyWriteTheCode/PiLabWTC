/**
 * WTC Pi - Zero-Library Stack Logs Viewer & Formatter
 * Pure vanilla JavaScript + DOM construction (zero external libraries).
 *
 * Supports structured parsing and formatting for:
 *  - Laravel / Monolog logs (including collapsible multi-line stack traces & JSON context)
 *  - Nginx error logs (with extracted client/server/request/upstream metadata)
 *  - Nginx access logs (with HTTP method, status color pills, bytes, and UA details)
 *  - PHP-FPM runtime logs
 *  - WTC Platform / Workers / Build / APT job logs
 *  - JSON-lines logs
 */
(() => {
    'use strict';

    const FORMAT_LABELS = {
        'laravel': 'Laravel / Monolog',
        'nginx_error': 'Nginx Error',
        'nginx_access': 'Nginx Access',
        'php_fpm': 'PHP-FPM',
        'wtc': 'WTC Platform',
        'json': 'JSON Lines',
        'plain': 'Plain Text'
    };

    const LEVEL_ORDER = {
        'error': 1,
        'warn': 2,
        'ok': 3,
        'info': 4,
        'debug': 5
    };

    function normalizeLevel(rawLevel) {
        const s = String(rawLevel || '').toUpperCase().trim();
        if (!s) return { bucket: 'info', label: 'INFO' };
        if (['EMERGENCY', 'EMERG', 'ALERT', 'CRITICAL', 'CRIT', 'ERROR', 'ERR', 'FATAL', 'PARSE', 'EXCEPTION'].includes(s)) {
            return { bucket: 'error', label: s };
        }
        if (['WARNING', 'WARN', 'DEPRECATED'].includes(s)) {
            return { bucket: 'warn', label: s };
        }
        if (['SUCCESS', 'OK', 'PASSED', 'DONE'].includes(s)) {
            return { bucket: 'ok', label: s };
        }
        if (['DEBUG', 'TRACE'].includes(s)) {
            return { bucket: 'debug', label: s };
        }
        return { bucket: 'info', label: s };
    }

    function inferTextLevel(text) {
        const t = String(text || '');
        if (/(\bERROR\b|\bFATAL\b|\bCRITICAL\b|\bEMERGENCY\b|\bEXCEPTION\b|Uncaught\s+\w+|SyntaxError|SQLSTATE\[|Security alert:|^\s*E:\s+)/i.test(t)) {
            return { bucket: 'error', label: 'ERROR' };
        }
        if (/(\bWARNING\b|\bWARN\b|\bDEPRECATED\b|^\s*W:\s+)/i.test(t)) {
            return { bucket: 'warn', label: 'WARN' };
        }
        if (/(\bSUCCESS\b|\bPASSED\b|Successfully\b|built in\s+\d+|Syntax is ok|test is successful)/i.test(t)) {
            return { bucket: 'ok', label: 'OK' };
        }
        if (/(\bDEBUG\b)/i.test(t)) {
            return { bucket: 'debug', label: 'DEBUG' };
        }
        return { bucket: 'info', label: 'INFO' };
    }

    function fmtBytes(bytes) {
        const n = Number(bytes);
        if (!Number.isFinite(n) || n <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        let val = n;
        let idx = 0;
        while (val >= 1024 && idx < units.length - 1) {
            val /= 1024;
            idx++;
        }
        return (idx === 0 ? Math.round(val) : val.toFixed(1)) + ' ' + units[idx];
    }

    function fmtTimestamp(epochSec) {
        const n = Number(epochSec);
        if (!Number.isFinite(n) || n <= 0) return '-';
        try {
            return new Date(n * 1000).toLocaleString();
        } catch {
            return '-';
        }
    }

    function extractTrailingJson(text) {
        const trimmed = String(text || '').trimEnd();
        if (!trimmed) return { message: text, json: null };
        const lastChar = trimmed[trimmed.length - 1];
        if (lastChar !== '}' && lastChar !== ']') {
            return { message: text, json: null };
        }
        const openChar = lastChar === '}' ? '{' : '[';
        let depth = 0;
        let inStr = false;
        let escape = false;
        let startIdx = -1;

        for (let i = trimmed.length - 1; i >= 0; i--) {
            const ch = trimmed[i];
            if (escape) {
                escape = false;
                continue;
            }
            if (ch === '\\' && inStr) {
                escape = true;
                continue;
            }
            if (ch === '"') {
                inStr = !inStr;
                continue;
            }
            if (inStr) continue;
            if (ch === lastChar) {
                depth++;
            } else if (ch === openChar) {
                depth--;
                if (depth === 0) {
                    startIdx = i;
                    break;
                }
            }
        }

        if (startIdx <= 0) return { message: text, json: null };
        const candidate = trimmed.slice(startIdx);
        // Require at least key-value look so plain brackets aren't misparsed
        if (candidate.length < 2) return { message: text, json: null };
        try {
            const parsed = JSON.parse(candidate);
            if (parsed && typeof parsed === 'object' && Object.keys(parsed).length > 0) {
                return {
                    message: trimmed.slice(0, startIdx).trimEnd(),
                    json: parsed
                };
            }
        } catch {
            // Not valid JSON
        }
        return { message: text, json: null };
    }

    function parseNginxErrorMeta(body) {
        // Nginx error messages append ", client: ..., server: ..., request: "...", host: "...""
        const meta = [];
        let mainMsg = body;
        const keys = ['client', 'server', 'request', 'upstream', 'host', 'referrer', 'subrequest'];
        const rx = new RegExp(',\\s*(' + keys.join('|') + '):\\s*("(?:[^"\\\\]|\\\\.)*"|[^,]+)', 'g');
        let firstIdx = -1;
        let m;
        while ((m = rx.exec(body)) !== null) {
            if (firstIdx === -1) firstIdx = m.index;
            let val = m[2].trim();
            if (val.startsWith('"') && val.endsWith('"')) {
                val = val.slice(1, -1);
            }
            meta.push({ key: m[1], value: val });
        }
        if (firstIdx !== -1) {
            mainMsg = body.slice(0, firstIdx).trim();
        }
        return { message: mainMsg, meta };
    }

    function detectFormatFromSample(lines, formatHint) {
        if (formatHint && formatHint !== 'auto' && FORMAT_LABELS[formatHint]) {
            return formatHint;
        }
        const scores = { laravel: 0, nginx_error: 0, nginx_access: 0, php_fpm: 0, wtc: 0, json: 0 };
        const sample = lines.slice(0, 40);
        for (const line of sample) {
            const t = line.trim();
            if (!t) continue;
            if (/^\[\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*\]\s+[a-zA-Z0-9_.-]+\.[A-Z]+:/.test(t)) {
                scores.laravel += 3;
            } else if (/^\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2}\s+\[[a-z]+\]\s+\d+#\d+:/.test(t)) {
                scores.nginx_error += 3;
            } else if (/^\S+\s+\S+\s+\S+\s+\[[^\]]+\]\s+"[A-Z]+\s+[^"]+"\s+\d{3}\s+(\d+|-)/.test(t)) {
                scores.nginx_access += 3;
            } else if (/^\[\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2}(?:\s+\S+)?\]\s+[A-Z]+:/.test(t)) {
                scores.php_fpm += 3;
            } else if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+\[[^\]]+\]/.test(t)) {
                scores.wtc += 2;
            } else if (t.startsWith('{') && t.endsWith('}')) {
                try {
                    const obj = JSON.parse(t);
                    if (obj && typeof obj === 'object') scores.json += 2;
                } catch { /* ignore */ }
            }
        }
        let best = 'plain';
        let bestScore = 0;
        for (const [k, v] of Object.entries(scores)) {
            if (v > bestScore) {
                bestScore = v;
                best = k;
            }
        }
        return best;
    }

    function parseLogs(rawText, formatHint = 'auto') {
        const text = String(rawText || '').replace(/\r\n/g, '\n');
        if (!text.trim()) {
            return {
                detectedFormat: formatHint !== 'auto' ? formatHint : 'plain',
                entries: [],
                counts: { total: 0, error: 0, warn: 0, info: 0, debug: 0, ok: 0 }
            };
        }

        const lines = text.split('\n');
        // Trim single trailing empty newline
        if (lines.length && lines[lines.length - 1] === '') lines.pop();

        const detectedFormat = detectFormatFromSample(lines, formatHint);
        const entries = [];

        const rxLaravel = /^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\]\s+([a-zA-Z0-9_.-]+)\.([A-Z]+):\s*(.*)$/;
        const rxNginxErr = /^(\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2})\s+\[([a-z]+)\]\s+(\d+#\d+:(?:\s*\*\d+)?)\s*(.*)$/;
        const rxNginxAcc = /^(\S+)\s+\S+\s+(\S+)\s+\[([^\]]+)\]\s+"([A-Z]+)\s+([^"\s]+)(?:\s+([^"]+))?"\s+(\d{3})\s+(\d+|-)(?:\s+"([^"]*)"\s+"([^"]*)")?(.*)$/;
        const rxPhpFpm = /^\[(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2}(?:\s+\S+)?)\]\s+([A-Z]+):\s*(.*)$/;
        const rxWtc = /^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+\[([^\]]+)\]\s*(.*)$/;
        const rxSection = /^===\s+(.+?)\s+===$/;

        let currentLaravelEntry = null;

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            if (line === '' && currentLaravelEntry) {
                currentLaravelEntry.traceLines.push('');
                continue;
            }
            if (line.trim() === '') continue;

            // 1. Laravel / Monolog
            let m = line.match(rxLaravel);
            if (m) {
                const lvl = normalizeLevel(m[3]);
                const extracted = extractTrailingJson(m[4]);
                const entry = {
                    lineNum: i + 1,
                    timestamp: m[1].replace('T', ' '),
                    level: lvl.bucket,
                    levelLabel: lvl.label,
                    tag: m[2],
                    message: extracted.message || m[4],
                    json: extracted.json,
                    meta: [],
                    traceLines: [],
                    raw: line,
                    kind: 'laravel'
                };
                entries.push(entry);
                currentLaravelEntry = entry;
                continue;
            }

            // Stack trace continuation lines for Laravel / PHP exceptions
            if (currentLaravelEntry && (
                /^\s*\[stacktrace\]\s*$/i.test(line) ||
                /^\s*Stack trace:\s*$/i.test(line) ||
                /^\s*#\d+\s+/.test(line) ||
                /^\s*thrown in\s+/.test(line) ||
                /^\s*\[previous exception\]/i.test(line) ||
                /^\s{2,}/.test(line) ||
                /^\s*"?\}\s*$/.test(line)
            )) {
                currentLaravelEntry.traceLines.push(line);
                currentLaravelEntry.raw += '\n' + line;
                continue;
            }
            currentLaravelEntry = null;

            // 2. Nginx Error
            m = line.match(rxNginxErr);
            if (m) {
                const lvl = normalizeLevel(m[2]);
                const parsed = parseNginxErrorMeta(m[4]);
                entries.push({
                    lineNum: i + 1,
                    timestamp: m[1].replace(/\//g, '-'),
                    level: lvl.bucket,
                    levelLabel: lvl.label,
                    tag: m[3].replace(/:\s*$/, ''),
                    message: parsed.message,
                    json: null,
                    meta: parsed.meta,
                    traceLines: [],
                    raw: line,
                    kind: 'nginx_error'
                });
                continue;
            }

            // 3. Nginx Access
            m = line.match(rxNginxAcc);
            if (m) {
                const status = parseInt(m[7], 10);
                let bucket = 'ok';
                if (status >= 500) bucket = 'error';
                else if (status >= 400) bucket = 'warn';
                else if (status >= 300) bucket = 'info';

                const meta = [
                    { key: 'ip', value: m[1] },
                    { key: 'status', value: String(status) },
                    { key: 'bytes', value: m[8] === '-' ? '0 B' : fmtBytes(parseInt(m[8], 10)) }
                ];
                if (m[2] && m[2] !== '-') meta.push({ key: 'user', value: m[2] });
                if (m[9] && m[9] !== '-') meta.push({ key: 'referer', value: m[9] });
                if (m[10] && m[10] !== '-') meta.push({ key: 'ua', value: m[10] });

                entries.push({
                    lineNum: i + 1,
                    timestamp: m[3],
                    level: bucket,
                    levelLabel: m[4] + ' ' + status,
                    tag: m[1],
                    httpMethod: m[4],
                    httpStatus: status,
                    message: m[5] + (m[6] ? ' (' + m[6] + ')' : ''),
                    json: null,
                    meta,
                    traceLines: [],
                    raw: line,
                    kind: 'nginx_access'
                });
                continue;
            }

            // 4. PHP-FPM
            m = line.match(rxPhpFpm);
            if (m) {
                const lvl = normalizeLevel(m[2]);
                let msg = m[3];
                let tag = 'php-fpm';
                const poolMatch = msg.match(/^\[(pool\s+[^\]]+)\]\s*(.*)$/);
                if (poolMatch) {
                    tag = poolMatch[1];
                    msg = poolMatch[2];
                }
                entries.push({
                    lineNum: i + 1,
                    timestamp: m[1],
                    level: lvl.bucket,
                    levelLabel: lvl.label,
                    tag,
                    message: msg,
                    json: null,
                    meta: [],
                    traceLines: [],
                    raw: line,
                    kind: 'php_fpm'
                });
                continue;
            }

            // 5. WTC Log
            m = line.match(rxWtc);
            if (m) {
                let msg = m[3];
                let lvl = inferTextLevel(msg);
                const prefixMatch = msg.match(/^(ERROR|WARNING|WARN|NOTICE|INFO|DEBUG):\s*(.*)$/i);
                if (prefixMatch) {
                    lvl = normalizeLevel(prefixMatch[1]);
                    msg = prefixMatch[2];
                }
                entries.push({
                    lineNum: i + 1,
                    timestamp: m[1],
                    level: lvl.bucket,
                    levelLabel: lvl.label,
                    tag: m[2],
                    message: msg,
                    json: null,
                    meta: [],
                    traceLines: [],
                    raw: line,
                    kind: 'wtc'
                });
                continue;
            }

            // 6. JSON line
            const trimmed = line.trim();
            if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
                try {
                    const obj = JSON.parse(trimmed);
                    if (obj && typeof obj === 'object') {
                        const ts = obj.timestamp || obj.time || obj.datetime || obj['@timestamp'] || '';
                        const rawLvl = obj.level_name || obj.level || obj.severity || '';
                        const lvl = rawLvl ? normalizeLevel(rawLvl) : inferTextLevel(obj.message || obj.msg || trimmed);
                        const msg = obj.message || obj.msg || obj.error || JSON.stringify(obj);
                        const extra = {};
                        for (const [k, v] of Object.entries(obj)) {
                            if (!['timestamp', 'time', 'datetime', '@timestamp', 'level_name', 'level', 'severity', 'message', 'msg'].includes(k)) {
                                extra[k] = v;
                            }
                        }
                        entries.push({
                            lineNum: i + 1,
                            timestamp: String(ts),
                            level: lvl.bucket,
                            levelLabel: lvl.label,
                            tag: obj.channel || obj.service || obj.source || 'json',
                            message: String(msg),
                            json: Object.keys(extra).length ? extra : null,
                            meta: [],
                            traceLines: [],
                            raw: line,
                            kind: 'json'
                        });
                        continue;
                    }
                } catch { /* fallback to plain line */ }
            }

            // 7. Section header (=== build for ... ===)
            m = line.match(rxSection);
            if (m) {
                entries.push({
                    lineNum: i + 1,
                    timestamp: '',
                    level: 'ok',
                    levelLabel: 'JOB',
                    tag: 'section',
                    message: m[1],
                    json: null,
                    meta: [],
                    traceLines: [],
                    raw: line,
                    kind: 'section'
                });
                continue;
            }

            // 8. Generic line
            const lvl = inferTextLevel(line);
            entries.push({
                lineNum: i + 1,
                timestamp: '',
                level: lvl.bucket,
                levelLabel: lvl.label,
                tag: '',
                message: line,
                json: null,
                meta: [],
                traceLines: [],
                raw: line,
                kind: 'plain'
            });
        }

        const counts = { total: entries.length, error: 0, warn: 0, info: 0, debug: 0, ok: 0 };
        for (const e of entries) {
            if (counts[e.level] !== undefined) counts[e.level]++;
        }

        return { detectedFormat, entries, counts };
    }

    function el(tag, className, textContent) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (textContent !== undefined && textContent !== null) node.textContent = textContent;
        return node;
    }

    function buildTraceLineNode(traceLine) {
        const row = el('div', 'lv-trace-line');
        // Highlight stack frame (#0 /path/file.php(123): ...) safely using text nodes
        const m = traceLine.match(/^(\s*#\d+\s+)([^\s:(]+(?:\(\d+\)|:\d+)?)(.*)$/);
        if (m) {
            row.appendChild(el('span', 'lv-trace-idx', m[1]));
            row.appendChild(el('span', 'lv-trace-file', m[2]));
            row.appendChild(el('span', 'lv-trace-call', m[3]));
        } else {
            row.textContent = traceLine;
        }
        return row;
    }

    function buildEntryRow(entry) {
        const row = el('div', 'lv-entry lv-lvl-' + entry.level + (entry.kind === 'section' ? ' lv-entry-section' : ''));

        const head = el('div', 'lv-entry-head');

        // Line number
        head.appendChild(el('span', 'lv-lnum', String(entry.lineNum)));

        // Timestamp
        if (entry.timestamp) {
            head.appendChild(el('span', 'lv-ts', entry.timestamp));
        }

        // Level / HTTP Method+Status badge
        if (entry.kind === 'nginx_access') {
            const methodPill = el('span', 'lv-badge lv-method-' + (entry.httpMethod || 'GET').toLowerCase(), entry.httpMethod || 'GET');
            head.appendChild(methodPill);
            const st = entry.httpStatus || 200;
            const stClass = st >= 500 ? 'error' : (st >= 400 ? 'warn' : (st >= 300 ? 'info' : 'ok'));
            head.appendChild(el('span', 'lv-badge lv-badge-' + stClass, String(st)));
        } else {
            head.appendChild(el('span', 'lv-badge lv-badge-' + entry.level, entry.levelLabel));
        }

        // Tag / Channel / IP
        if (entry.tag) {
            head.appendChild(el('span', 'lv-tag', entry.tag));
        }

        // Message
        const msgEl = el('span', 'lv-msg', entry.message);
        head.appendChild(msgEl);

        // Quick action toggles if trace, json, or meta exists
        const hasTrace = entry.traceLines && entry.traceLines.length > 0;
        const hasJson = !!entry.json;
        const hasMeta = entry.meta && entry.meta.length > 0;

        let detailsBox = null;
        if (hasTrace || hasJson || hasMeta) {
            const actions = el('span', 'lv-entry-toggles');
            detailsBox = el('div', 'lv-entry-details');
            detailsBox.style.display = 'none';

            if (hasMeta) {
                const metaWrap = el('div', 'lv-meta-chips');
                for (const kv of entry.meta) {
                    const chip = el('span', 'lv-meta-chip');
                    chip.appendChild(el('span', 'lv-meta-k', kv.key + ':'));
                    chip.appendChild(el('span', 'lv-meta-v', kv.value));
                    metaWrap.appendChild(chip);
                }
                detailsBox.appendChild(metaWrap);
            }

            if (hasJson) {
                const pre = el('pre', 'lv-json-block', JSON.stringify(entry.json, null, 2));
                detailsBox.appendChild(pre);
            }

            if (hasTrace) {
                const traceWrap = el('div', 'lv-trace-block');
                for (const tl of entry.traceLines) {
                    traceWrap.appendChild(buildTraceLineNode(tl));
                }
                detailsBox.appendChild(traceWrap);
            }

            const btnLabel = hasTrace
                ? ('▶ Stack trace (' + entry.traceLines.length + ' lines)')
                : (hasJson ? '▶ JSON context' : '▶ Details (' + entry.meta.length + ')');
            const toggleBtn = el('button', 'lv-toggle-btn', btnLabel);
            toggleBtn.type = 'button';
            toggleBtn.addEventListener('click', () => {
                const open = detailsBox.style.display !== 'none';
                detailsBox.style.display = open ? 'none' : 'block';
                toggleBtn.textContent = (open ? '▶ ' : '▼ ') + btnLabel.slice(2);
                row.classList.toggle('lv-expanded', !open);
            });
            actions.appendChild(toggleBtn);
            head.appendChild(actions);
        }

        row.appendChild(head);
        if (detailsBox) {
            row.appendChild(detailsBox);
        }
        return row;
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="wtc-csrf"]');
        if (meta && meta.content) return meta.content;
        const input = document.querySelector('input[name="csrf"]');
        return input ? input.value : '';
    }

    /**
     * Render formatted log entries + interactive filter bar for static/inline text
     */
    function formatInline(container, rawText, options = {}) {
        if (!container) return null;
        const state = {
            rawText: String(rawText || ''),
            formatHint: options.formatHint || 'auto',
            levelFilter: 'all',
            search: '',
            newestFirst: options.newestFirst !== undefined ? !!options.newestFirst : false,
            viewMode: options.viewMode || 'formatted'
        };

        container.innerHTML = '';
        const root = el('div', 'lv-root lv-inline');

        // Toolbar
        const toolbar = el('div', 'lv-toolbar');
        const filterGroup = el('div', 'lv-filter-pills');
        const searchInput = el('input', 'lv-search');
        searchInput.type = 'text';
        searchInput.placeholder = 'Filter log entries...';

        const rightControls = el('div', 'lv-right-controls');
        const orderBtn = el('button', 'btn lv-btn', state.newestFirst ? '⬇ Newest First' : '⬆ Oldest First');
        orderBtn.type = 'button';
        const modeBtn = el('button', 'btn lv-btn', state.viewMode === 'formatted' ? '📄 Raw' : '✨ Formatted');
        modeBtn.type = 'button';
        const copyBtn = el('button', 'btn lv-btn', '📋 Copy');
        copyBtn.type = 'button';

        rightControls.appendChild(orderBtn);
        rightControls.appendChild(modeBtn);
        rightControls.appendChild(copyBtn);

        toolbar.appendChild(filterGroup);
        toolbar.appendChild(searchInput);
        toolbar.appendChild(rightControls);

        const listBox = el('div', 'lv-list');
        if (options.maxHeight) {
            listBox.style.maxHeight = options.maxHeight;
        }
        const rawPre = el('pre', 'lv-raw-pre');
        rawPre.style.display = 'none';
        if (options.maxHeight) {
            rawPre.style.maxHeight = options.maxHeight;
        }

        root.appendChild(toolbar);
        root.appendChild(listBox);
        root.appendChild(rawPre);
        container.appendChild(root);

        function render() {
            const parsed = parseLogs(state.rawText, state.formatHint);

            // Rebuild level pills
            filterGroup.innerHTML = '';
            const pills = [
                { key: 'all', label: 'All', count: parsed.counts.total },
                { key: 'error', label: 'Errors', count: parsed.counts.error },
                { key: 'warn', label: 'Warnings', count: parsed.counts.warn },
                { key: 'info', label: 'Info', count: parsed.counts.info + parsed.counts.ok },
                { key: 'debug', label: 'Debug', count: parsed.counts.debug }
            ];
            for (const p of pills) {
                if (p.key !== 'all' && p.count === 0) continue;
                const btn = el('button', 'lv-pill lv-pill-' + p.key + (state.levelFilter === p.key ? ' active' : ''));
                btn.type = 'button';
                btn.appendChild(document.createTextNode(p.label + ' '));
                btn.appendChild(el('span', 'lv-pill-cnt', String(p.count)));
                btn.addEventListener('click', () => {
                    state.levelFilter = p.key;
                    render();
                });
                filterGroup.appendChild(btn);
            }

            if (state.viewMode === 'raw') {
                listBox.style.display = 'none';
                rawPre.style.display = 'block';
                rawPre.textContent = state.rawText || '(Empty log)';
                return;
            }

            rawPre.style.display = 'none';
            listBox.style.display = 'block';
            listBox.innerHTML = '';

            const q = state.search.trim().toLowerCase();
            let filtered = parsed.entries.filter(e => {
                if (state.levelFilter === 'info') {
                    if (e.level !== 'info' && e.level !== 'ok') return false;
                } else if (state.levelFilter !== 'all' && e.level !== state.levelFilter) {
                    return false;
                }
                if (q && !e.raw.toLowerCase().includes(q) && !e.message.toLowerCase().includes(q) && !e.tag.toLowerCase().includes(q)) {
                    return false;
                }
                return true;
            });

            if (state.newestFirst) {
                filtered = filtered.slice().reverse();
            }

            if (filtered.length === 0) {
                listBox.appendChild(el('div', 'lv-empty', state.rawText.trim() ? 'No log entries match the active filters.' : 'Log file is empty.'));
                return;
            }

            const frag = document.createDocumentFragment();
            for (const entry of filtered) {
                frag.appendChild(buildEntryRow(entry));
            }
            listBox.appendChild(frag);
        }

        searchInput.addEventListener('input', (e) => {
            state.search = e.target.value;
            render();
        });
        orderBtn.addEventListener('click', () => {
            state.newestFirst = !state.newestFirst;
            orderBtn.textContent = state.newestFirst ? '⬇ Newest First' : '⬆ Oldest First';
            render();
        });
        modeBtn.addEventListener('click', () => {
            state.viewMode = state.viewMode === 'formatted' ? 'raw' : 'formatted';
            modeBtn.textContent = state.viewMode === 'formatted' ? '📄 Raw' : '✨ Formatted';
            render();
        });
        copyBtn.addEventListener('click', () => {
            if (navigator.clipboard && state.rawText) {
                navigator.clipboard.writeText(state.rawText).then(() => {
                    copyBtn.textContent = '✓ Copied';
                    setTimeout(() => { copyBtn.textContent = '📋 Copy'; }, 1500);
                });
            }
        });

        render();
        return {
            update(newText, newHint) {
                state.rawText = String(newText || '');
                if (newHint) state.formatHint = newHint;
                render();
            }
        };
    }

    /**
     * Mount a full-featured Stack Logs Viewer with source discovery, live tail, download, clear
     */
    function mount(container, options = {}) {
        if (!container) return null;

        const state = {
            sources: [],
            currentSource: options.source || '',
            siteHint: options.site || '',
            pathHint: options.path || '',
            lines: Number(options.lines) || 200,
            rawText: '',
            meta: null,
            levelFilter: 'all',
            search: '',
            newestFirst: options.newestFirst !== undefined ? !!options.newestFirst : true,
            viewMode: 'formatted',
            liveTail: false,
            liveTimer: null,
            destroyed: false,
            loading: false
        };

        container.innerHTML = '';
        const root = el('div', 'lv-root');

        // Top Bar: Source Selector + Lines + Controls
        const topBar = el('div', 'lv-topbar');
        const srcWrap = el('div', 'lv-src-wrap');
        const srcLabel = el('label', 'lv-src-label', '📚 Stack / Log Source:');
        const srcSelect = el('select', 'lv-src-select');
        srcWrap.appendChild(srcLabel);
        srcWrap.appendChild(srcSelect);

        const ctrlWrap = el('div', 'lv-ctrl-wrap');

        const lineSelect = el('select', 'lv-line-select');
        for (const n of [50, 100, 200, 500, 1000]) {
            const opt = el('option', '', n + ' lines');
            opt.value = String(n);
            if (n === state.lines) opt.selected = true;
            lineSelect.appendChild(opt);
        }

        const liveBtn = el('button', 'btn lv-btn', '▶ Live Tail');
        liveBtn.type = 'button';
        liveBtn.title = 'Auto-refresh log every 3 seconds';

        const refreshBtn = el('button', 'btn lv-btn', '🔄 Refresh');
        refreshBtn.type = 'button';

        const copyBtn = el('button', 'btn lv-btn', '📋 Copy');
        copyBtn.type = 'button';

        const dlBtn = el('button', 'btn lv-btn', '⬇️ Download');
        dlBtn.type = 'button';

        const clearBtn = el('button', 'btn lv-btn danger', '🗑️ Clear');
        clearBtn.type = 'button';
        clearBtn.style.display = 'none';

        ctrlWrap.appendChild(lineSelect);
        ctrlWrap.appendChild(liveBtn);
        ctrlWrap.appendChild(refreshBtn);
        ctrlWrap.appendChild(copyBtn);
        ctrlWrap.appendChild(dlBtn);
        ctrlWrap.appendChild(clearBtn);

        topBar.appendChild(srcWrap);
        topBar.appendChild(ctrlWrap);

        // Info Strip
        const infoStrip = el('div', 'lv-infostrip');

        // Filter Toolbar
        const toolbar = el('div', 'lv-toolbar');
        const filterGroup = el('div', 'lv-filter-pills');
        const searchInput = el('input', 'lv-search');
        searchInput.type = 'text';
        searchInput.placeholder = 'Search messages, paths, IPs, stack traces...';
        const rightControls = el('div', 'lv-right-controls');
        const orderBtn = el('button', 'btn lv-btn', state.newestFirst ? '⬇ Newest First' : '⬆ Oldest First');
        orderBtn.type = 'button';
        const modeBtn = el('button', 'btn lv-btn', '📄 Raw');
        modeBtn.type = 'button';
        rightControls.appendChild(orderBtn);
        rightControls.appendChild(modeBtn);

        toolbar.appendChild(filterGroup);
        toolbar.appendChild(searchInput);
        toolbar.appendChild(rightControls);

        // Viewer Body
        const listBox = el('div', 'lv-list');
        if (options.maxHeight) listBox.style.maxHeight = options.maxHeight;
        const rawPre = el('pre', 'lv-raw-pre');
        rawPre.style.display = 'none';
        if (options.maxHeight) rawPre.style.maxHeight = options.maxHeight;

        root.appendChild(topBar);
        root.appendChild(infoStrip);
        root.appendChild(toolbar);
        root.appendChild(listBox);
        root.appendChild(rawPre);
        container.appendChild(root);

        function stopLiveTail() {
            state.liveTail = false;
            if (state.liveTimer) {
                clearInterval(state.liveTimer);
                state.liveTimer = null;
            }
            liveBtn.classList.remove('lv-live-active');
            liveBtn.textContent = '▶ Live Tail';
        }

        function startLiveTail() {
            stopLiveTail();
            state.liveTail = true;
            liveBtn.classList.add('lv-live-active');
            liveBtn.textContent = '⏸ Live (3s)';
            state.liveTimer = setInterval(() => {
                if (state.destroyed || !document.body.contains(container)) {
                    stopLiveTail();
                    return;
                }
                loadLogContent(true);
            }, 3000);
        }

        function renderEntries() {
            const fmtHint = (state.meta && state.meta.format) ? state.meta.format : 'auto';
            const parsed = parseLogs(state.rawText, fmtHint);

            // Update info strip
            infoStrip.innerHTML = '';
            const leftInfo = el('div', 'lv-info-left');
            if (state.meta) {
                leftInfo.appendChild(el('code', 'lv-path-code', state.meta.path || state.currentSource));
                const fmtLabel = FORMAT_LABELS[parsed.detectedFormat] || parsed.detectedFormat;
                leftInfo.appendChild(el('span', 'pill lv-fmt-pill', fmtLabel));
                leftInfo.appendChild(el('span', 'lv-info-stat', 'Size: ' + fmtBytes(state.meta.size || 0)));
                if (state.meta.mtime) {
                    leftInfo.appendChild(el('span', 'lv-info-stat', 'Updated: ' + fmtTimestamp(state.meta.mtime)));
                }
            }
            const rightInfo = el('div', 'lv-info-right');
            rightInfo.appendChild(el('span', 'lv-info-stat', parsed.counts.total + ' entries'));
            infoStrip.appendChild(leftInfo);
            infoStrip.appendChild(rightInfo);

            // Rebuild level pills
            filterGroup.innerHTML = '';
            const pills = [
                { key: 'all', label: 'All', count: parsed.counts.total },
                { key: 'error', label: 'Errors', count: parsed.counts.error },
                { key: 'warn', label: 'Warnings', count: parsed.counts.warn },
                { key: 'info', label: 'Info', count: parsed.counts.info + parsed.counts.ok },
                { key: 'debug', label: 'Debug', count: parsed.counts.debug }
            ];
            for (const p of pills) {
                const btn = el('button', 'lv-pill lv-pill-' + p.key + (state.levelFilter === p.key ? ' active' : ''));
                btn.type = 'button';
                btn.appendChild(document.createTextNode(p.label + ' '));
                btn.appendChild(el('span', 'lv-pill-cnt', String(p.count)));
                btn.addEventListener('click', () => {
                    state.levelFilter = p.key;
                    renderEntries();
                });
                filterGroup.appendChild(btn);
            }

            if (state.viewMode === 'raw') {
                listBox.style.display = 'none';
                rawPre.style.display = 'block';
                rawPre.textContent = state.rawText || '(Log source is empty or has no entries yet)';
                return;
            }

            rawPre.style.display = 'none';
            listBox.style.display = 'block';
            listBox.innerHTML = '';

            const q = state.search.trim().toLowerCase();
            let filtered = parsed.entries.filter(e => {
                if (state.levelFilter === 'info') {
                    if (e.level !== 'info' && e.level !== 'ok') return false;
                } else if (state.levelFilter !== 'all' && e.level !== state.levelFilter) {
                    return false;
                }
                if (q && !e.raw.toLowerCase().includes(q) && !e.message.toLowerCase().includes(q) && !e.tag.toLowerCase().includes(q)) {
                    return false;
                }
                return true;
            });

            if (state.newestFirst) {
                filtered = filtered.slice().reverse();
            }

            if (filtered.length === 0) {
                const emptyMsg = !state.rawText.trim()
                    ? 'No log entries yet for this source.'
                    : 'No log entries match your current filter.';
                listBox.appendChild(el('div', 'lv-empty', emptyMsg));
                return;
            }

            const frag = document.createDocumentFragment();
            for (const entry of filtered) {
                frag.appendChild(buildEntryRow(entry));
            }
            listBox.appendChild(frag);
        }

        async function loadLogContent(silent = false) {
            if (!state.currentSource || state.destroyed) return;
            if (!silent) {
                listBox.innerHTML = '';
                listBox.appendChild(el('div', 'lv-loading', 'Loading & formatting stack logs...'));
            }
            state.loading = true;
            try {
                const url = '/?action=stack_logs_read&source=' + encodeURIComponent(state.currentSource) + '&lines=' + encodeURIComponent(state.lines);
                const res = await fetch(url);
                const data = await res.json();
                if (state.destroyed) return;
                if (!data || !data.ok) {
                    state.rawText = '';
                    state.meta = { path: state.currentSource, size: 0, mtime: 0, format: 'plain' };
                    listBox.innerHTML = '';
                    listBox.appendChild(el('div', 'lv-error', (data && data.error) ? data.error : 'Unable to read log source.'));
                    clearBtn.style.display = 'none';
                    return;
                }
                state.rawText = data.content || '';
                state.meta = data;
                clearBtn.style.display = data.clearable ? 'inline-flex' : 'none';
                renderEntries();
            } catch (err) {
                if (!silent && !state.destroyed) {
                    listBox.innerHTML = '';
                    listBox.appendChild(el('div', 'lv-error', 'Failed to fetch log: ' + err.message));
                }
            } finally {
                state.loading = false;
            }
        }

        async function loadSources() {
            listBox.innerHTML = '';
            listBox.appendChild(el('div', 'lv-loading', 'Discovering stack & RAM drive log sources...'));
            try {
                let url = '/?action=stack_logs_list';
                if (state.siteHint) url += '&site=' + encodeURIComponent(state.siteHint);
                if (state.pathHint) url += '&path=' + encodeURIComponent(state.pathHint);

                const res = await fetch(url);
                const data = await res.json();
                if (state.destroyed) return;

                const sources = Array.isArray(data.sources) ? data.sources : [];
                state.sources = sources;

                srcSelect.innerHTML = '';
                const groups = new Map();
                for (const s of sources) {
                    const cat = s.category || 'Logs';
                    if (!groups.has(cat)) groups.set(cat, []);
                    groups.get(cat).push(s);
                }

                for (const [cat, items] of groups.entries()) {
                    const og = document.createElement('optgroup');
                    og.label = cat;
                    for (const item of items) {
                        const opt = document.createElement('option');
                        opt.value = item.id;
                        const sizeTag = item.exists ? (' (' + fmtBytes(item.size) + ')') : ' (empty/not created)';
                        opt.textContent = item.label + sizeTag;
                        og.appendChild(opt);
                    }
                    srcSelect.appendChild(og);
                }

                // Pick initial source
                let chosen = state.currentSource;
                const hasId = (id) => sources.some(s => s.id === id);
                if (!chosen || !hasId(chosen)) {
                    if (data.recommended && hasId(data.recommended)) {
                        chosen = data.recommended;
                    } else if (sources.length > 0) {
                        const existing = sources.find(s => s.exists && s.size > 0);
                        chosen = existing ? existing.id : sources[0].id;
                    }
                }
                state.currentSource = chosen || '';
                if (state.currentSource) {
                    srcSelect.value = state.currentSource;
                    await loadLogContent(false);
                } else {
                    listBox.innerHTML = '';
                    listBox.appendChild(el('div', 'lv-empty', 'No stack log sources discovered yet.'));
                }
            } catch (err) {
                if (!state.destroyed) {
                    listBox.innerHTML = '';
                    listBox.appendChild(el('div', 'lv-error', 'Failed to discover stack logs: ' + err.message));
                }
            }
        }

        // Event bindings
        srcSelect.addEventListener('change', () => {
            state.currentSource = srcSelect.value;
            if (typeof options.onSourceChange === 'function') {
                options.onSourceChange(state.currentSource);
            }
            loadLogContent(false);
        });
        lineSelect.addEventListener('change', () => {
            state.lines = Number(lineSelect.value) || 200;
            loadLogContent(false);
        });
        searchInput.addEventListener('input', (e) => {
            state.search = e.target.value;
            renderEntries();
        });
        orderBtn.addEventListener('click', () => {
            state.newestFirst = !state.newestFirst;
            orderBtn.textContent = state.newestFirst ? '⬇ Newest First' : '⬆ Oldest First';
            renderEntries();
        });
        modeBtn.addEventListener('click', () => {
            state.viewMode = state.viewMode === 'formatted' ? 'raw' : 'formatted';
            modeBtn.textContent = state.viewMode === 'formatted' ? '📄 Raw' : '✨ Formatted';
            renderEntries();
        });
        liveBtn.addEventListener('click', () => {
            if (state.liveTail) stopLiveTail();
            else startLiveTail();
        });
        refreshBtn.addEventListener('click', () => loadLogContent(false));
        copyBtn.addEventListener('click', () => {
            if (navigator.clipboard && state.rawText) {
                navigator.clipboard.writeText(state.rawText).then(() => {
                    copyBtn.textContent = '✓ Copied';
                    setTimeout(() => { copyBtn.textContent = '📋 Copy'; }, 1500);
                });
            }
        });
        dlBtn.addEventListener('click', () => {
            const blob = new Blob([state.rawText || ''], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            const cleanName = (state.currentSource || 'stack').replace(/[^a-zA-Z0-9_.-]+/g, '_');
            a.href = url;
            a.download = cleanName + '.log';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
        clearBtn.addEventListener('click', async () => {
            if (!state.currentSource) return;
            const label = (state.meta && state.meta.label) ? state.meta.label : state.currentSource;
            if (!confirm('Clear/truncate log "' + label + '"?')) return;
            try {
                const fd = new FormData();
                fd.append('csrf', getCsrfToken());
                fd.append('action', 'stack_logs_clear');
                fd.append('source', state.currentSource);
                fd.append('is_ajax', '1');
                const res = await fetch('/', { method: 'POST', body: fd });
                const data = await res.json();
                if (data && data.ok) {
                    await loadLogContent(false);
                } else {
                    alert((data && data.error) ? data.error : 'Failed to clear log');
                }
            } catch (err) {
                alert('Error clearing log: ' + err.message);
            }
        });

        loadSources();

        return {
            reload() { return loadLogContent(false); },
            getCurrentSource() { return state.currentSource; },
            destroy() {
                state.destroyed = true;
                stopLiveTail();
            }
        };
    }

    /**
     * Global Modal Wrapper for Viewing Stack Logs from any tab (RAM Drive, Sites, Stack Manager)
     */
    let activeModalViewer = null;
    let activeModalKeyHandler = null;

    function closeModal() {
        const modal = document.getElementById('wtcStackLogsModal');
        if (activeModalViewer) {
            activeModalViewer.destroy();
            activeModalViewer = null;
        }
        if (activeModalKeyHandler) {
            document.removeEventListener('keydown', activeModalKeyHandler);
            activeModalKeyHandler = null;
        }
        if (modal) {
            modal.style.display = 'none';
        }
    }

    function openModal(options = {}) {
        let modal = document.getElementById('wtcStackLogsModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'wtcStackLogsModal';
            modal.className = 'modal-backdrop';
            modal.style.display = 'none';

            const dialog = el('div', 'modal-dialog lv-modal-dialog');
            const header = el('div', 'modal-header');
            const h3 = el('h3', '');
            h3.id = 'wtcStackLogsModalTitle';
            h3.textContent = '📜 Stack Logs Viewer';

            const headActions = el('div', 'lv-modal-head-actions');
            const fullLink = el('a', 'btn lv-btn', '↗ Full Logs Page');
            fullLink.id = 'wtcStackLogsFullLink';
            fullLink.href = '/?p=logs';

            const closeBtn = el('button', 'modal-close', '×');
            closeBtn.type = 'button';
            closeBtn.title = 'Close';
            closeBtn.addEventListener('click', closeModal);

            headActions.appendChild(fullLink);
            headActions.appendChild(closeBtn);
            header.appendChild(h3);
            header.appendChild(headActions);

            const body = el('div', 'modal-body lv-modal-body');
            body.id = 'wtcStackLogsModalBody';

            dialog.appendChild(header);
            dialog.appendChild(body);
            modal.appendChild(dialog);

            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });
            document.body.appendChild(modal);
        }

        if (activeModalViewer) {
            activeModalViewer.destroy();
            activeModalViewer = null;
        }

        const titleEl = document.getElementById('wtcStackLogsModalTitle');
        const fullLinkEl = document.getElementById('wtcStackLogsFullLink');
        const bodyEl = document.getElementById('wtcStackLogsModalBody');

        if (titleEl) {
            titleEl.textContent = options.title || (options.site ? ('📜 Stack Logs — ' + options.site) : '📜 Stack & RAM Drive Logs Viewer');
        }

        const updateFullLink = (srcId) => {
            if (!fullLinkEl) return;
            let href = '/?p=logs';
            if (srcId) href += '&source=' + encodeURIComponent(srcId);
            else if (options.site) href += '&site=' + encodeURIComponent(options.site);
            fullLinkEl.href = href;
        };
        updateFullLink(options.source || '');

        if (fullLinkEl) {
            fullLinkEl.onclick = () => closeModal();
        }

        modal.style.display = 'flex';
        activeModalKeyHandler = (e) => {
            if (e.key === 'Escape') closeModal();
        };
        document.addEventListener('keydown', activeModalKeyHandler);

        activeModalViewer = mount(bodyEl, {
            source: options.source || '',
            site: options.site || '',
            path: options.path || '',
            lines: options.lines || 200,
            maxHeight: '58vh',
            onSourceChange: updateFullLink
        });
    }

    window.WtcLogViewer = {
        parseLogs,
        formatInline,
        mount,
        openModal,
        closeModal
    };
})();


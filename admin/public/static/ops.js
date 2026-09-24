/**
 * GatewayPush 运维页（P5 + 2.0 序4/序5 + 序7）—— 角色状态 / 日志尾读 / 密钥轮换 /
 * 队列深度 / 错误聚合 / 配置查看 / 限流命中。
 *
 * 三条纪律（与 session.js / push.js 同口径）：
 * 1. **零定时器** —— 「重新探测」是显式按钮，不是轮询；误用 setTimeout 会被
 *    tests/Frontend/ops_render_check.js 抓到。
 * 2. **零 innerHTML** —— 全部节点用 el() + textContent 组装（XSS 纪律）。
 * 3. **前端不编词** —— 状态文案/样式 tone 由后端下发；本文件只做「取数 + 摊开」。
 */
(function () {
    'use strict';

    var cfgNode = document.getElementById('ops-page-config');
    if (!cfgNode) { return; }
    var cfg = JSON.parse(cfgNode.textContent || '{}');

    /* ---------- 基础设施 ---------- */

    function $(id) { return document.getElementById(id); }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }

    function setNote(id, tone, text) {
        var node = $(id);
        if (!node) { return; }
        node.className = 'note' + (tone ? ' ' + tone : '');
        node.textContent = text;
    }

    function getJson(url, cb) {
        fetch(url, { method: 'GET', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) { cb(data); })
            .catch(function (e) { cb({ code: -1, msg: String(e) }); });
    }

    function bind(id, fn) {
        var node = $(id);
        if (node) { node.addEventListener('click', fn); }
    }

    function mark(ok, unknown) {
        if (unknown) { return el('span', 'muted', '—'); }
        var s = el('span', ok ? 'ok' : 'bad', ok ? '是' : '否');
        return s;
    }

    /* ---------- 区块 1：角色状态 ---------- */

    function loadRoles() {
        setNote('roles-status', 'info', '探测中…');
        getJson(cfg.roles_url, function (res) {
            var data = res.data || {};
            if (res.code !== 0) {
                setNote('roles-status', 'bad', '探测失败：' + (res.msg || '未知错误'));
                return;
            }

            var body = $('tb-roles');
            if (body) {
                while (body.firstChild) { body.removeChild(body.firstChild); }
                (data.roles || []).forEach(function (r) {
                    var tr = document.createElement('tr');
                    tr.appendChild(el('th', null, r.role));
                    tr.appendChild(el('td', null)).appendChild(mark(r.enabled === true, r.enabled === null));
                    tr.appendChild(el('td', null)).appendChild(mark(r.listening === true, r.listening === null));
                    tr.appendChild(el('td', null, r.listen_count === 0 ? '—' : String(r.listen_count)));
                    tr.appendChild(el('td', null)).appendChild(mark(r.health_ok === true, r.health_ok === null));
                    body.appendChild(tr);
                });
            }

            var problems = $('roles-problems');
            if (problems) {
                while (problems.firstChild) { problems.removeChild(problems.firstChild); }
                (data.problems || []).forEach(function (p) {
                    problems.appendChild(el('li', null, p));
                });
            }

            setNote('roles-status', data.problems && data.problems.length ? 'warn' : 'ok',
                data.problems && data.problems.length
                    ? '发现 ' + data.problems.length + ' 个不一致，见下方清单'
                    : '三源一致，未发现异常');

            renderEnv(data.env);
        });
    }

    /* ---------- 版本 / 环境（2.0 §2.2，随 roles 下发，无独立端点） ---------- */

    function renderEnv(env) {
        var status = $('roles-env-status');
        var body = $('tb-roles-env');
        var notes = $('roles-env-notes');
        if (!body) { return; }

        while (body.firstChild) { body.removeChild(body.firstChild); }
        if (notes) { while (notes.firstChild) { notes.removeChild(notes.firstChild); } }

        if (!env || !env.items) {
            if (status) { status.textContent = ''; }
            return;
        }

        env.items.forEach(function (item) {
            var tr = document.createElement('tr');
            tr.appendChild(el('th', null, item.key));
            var td = el('td');
            if (item.configured) {
                td.appendChild(el('span', 'mono', item.value));
            } else {
                td.appendChild(el('span', 'muted', '—'));
            }
            tr.appendChild(td);
            tr.appendChild(el('td', 'muted', item.source || ''));
            body.appendChild(tr);
        });

        if (notes) {
            (env.notes || []).forEach(function (n) {
                notes.appendChild(el('div', 'hint', n));
            });
        }
        if (status) {
            setNote('roles-env-status', 'ok', '版本 / 环境已加载');
        }
    }

    /* ---------- 区块 2：日志尾读 ---------- */

    function loadLog() {
        var role = $('log-role') ? $('log-role').value : '';
        var date = $('log-date') ? $('log-date').value.trim() : '';
        var lines = parseInt($('log-lines') ? $('log-lines').value : '200', 10) || 200;
        var keyword = $('log-keyword') ? $('log-keyword').value : '';

        if (!date) {
            date = new Date().toISOString().slice(0, 10); // 仅默认值；形态校验在服务端
        }

        var qs = '?role=' + encodeURIComponent(role)
            + '&date=' + encodeURIComponent(date)
            + '&lines=' + encodeURIComponent(String(lines));
        if (keyword) { qs += '&keyword=' + encodeURIComponent(keyword); }

        setNote('log-status', 'info', '读取中…');
        getJson(cfg.logs_url + qs, function (res) {
            var data = res.data || {};
            if (res.code !== 0) {
                setNote('log-status', 'bad', '读取失败：' + (res.msg || '未知错误'));
                return;
            }
            if (data.not_found) {
                setNote('log-status', 'warn', '该文件今天还没有日志（' + date + ' / ' + role + '）');
                return;
            }
            if (!data.ok) {
                setNote('log-status', 'bad', data.hint || '读取失败');
                return;
            }

            var out = $('log-output');
            if (out) {
                out.textContent = (data.lines || []).join('\n');
                if (data.truncated) {
                    out.textContent = '（⚠ 扫描已达上限，以下不保证是全部命中）\n' + out.textContent;
                }
            }
            setNote('log-status', 'ok', '命中 ' + (data.matched || 0) + ' 行，展示最后 ' + (data.lines || []).length + ' 行');
        });
    }

    /* ---------- 区块 3：密钥轮换引导 ---------- */

    function loadRotation() {
        setNote('rotation-status', 'info', '生成中…');
        getJson(cfg.rotation_url, function (res) {
            var data = res.data || {};
            if (res.code !== 0) {
                setNote('rotation-status', 'bad', '生成失败：' + (res.msg || '未知错误'));
                return;
            }

            var body = $('tb-secrets');
            if (body) {
                while (body.firstChild) { body.removeChild(body.firstChild); }
                (data.secrets || []).forEach(function (s) {
                    var tr = document.createElement('tr');
                    tr.appendChild(el('th', null, s.name));
                    tr.appendChild(el('td', null, s.scope));
                    var td = el('td');
                    if (s.configured) {
                        td.appendChild(el('span', 'mono', s.masked));
                    } else {
                        td.appendChild(el('span', 'muted', '未配置'));
                    }
                    tr.appendChild(td);
                    body.appendChild(tr);
                });
            }

            var steps = $('rotation-steps');
            if (steps) {
                while (steps.firstChild) { steps.removeChild(steps.firstChild); }
                (data.steps || []).forEach(function (st) {
                    var li = el('li');
                    li.appendChild(el('strong', null, st.title));
                    li.appendChild(el('div', 'hint', st.detail));
                    steps.appendChild(li);
                });
            }

            setNote('rotation-status', 'ok', '清单已生成（只读展示，本页不会执行任何轮换）');
        });
    }

    /* ---------- 区块 4：队列深度（2.0 序4） ---------- */

    function loadQueues() {
        setNote('queues-status', 'info', '探测中…');
        var trunc = $('queues-truncated');
        if (trunc) { trunc.textContent = ''; }
        getJson(cfg.queues_url, function (res) {
            var data = res.data || {};
            if (res.code !== 0) {
                setNote('queues-status', 'bad', '探测失败：' + (res.msg || '未知错误'));
                return;
            }

            var body = $('tb-queues');
            if (body) {
                while (body.firstChild) { body.removeChild(body.firstChild); }
                (data.rows || []).forEach(function (r) {
                    var tr = document.createElement('tr');
                    tr.appendChild(el('th', null, r.name));
                    tr.appendChild(el('td', null, r.label));
                    tr.appendChild(el('td', r.level === 'bad' ? 'bad' : null, String(r.depth)));
                    tr.appendChild(el('td', 'muted', String(r.threshold)));
                    tr.appendChild(el('td', r.level === 'bad' ? 'bad' : 'ok',
                        r.level === 'bad' ? '超阈值' : '正常'));
                    body.appendChild(tr);
                });
            }

            if (trunc) {
                trunc.textContent = data.truncated
                    ? '（⚠ SCAN 已达上限，离线/回执数字不保证是全量）'
                    : '';
            }

            var bad = (data.rows || []).filter(function (r) { return r.level === 'bad'; }).length;
            setNote('queues-status', bad > 0 ? 'warn' : 'ok',
                bad > 0
                    ? bad + ' 条队列超阈值，见下方标红行'
                    : '全部队列在阈值内');
        });
    }

    /* ---------- 区块 5：错误聚合（2.0 序4） ---------- */

    function loadErrors() {
        var date = $('err-date') ? $('err-date').value.trim() : '';
        var lines = parseInt($('err-lines') ? $('err-lines').value : '20', 10) || 20;
        if (!date) {
            date = new Date().toISOString().slice(0, 10); // 仅默认值；形态校验在服务端
        }

        var qs = '?date=' + encodeURIComponent(date)
            + '&lines=' + encodeURIComponent(String(lines));

        setNote('errors-status', 'info', '读取中…');
        getJson(cfg.errors_url + qs, function (res) {
            var data = res.data || {};
            if (res.code !== 0) {
                setNote('errors-status', 'bad', '读取失败：' + (res.msg || '未知错误'));
                return;
            }

            var body = $('tb-errors');
            if (body) {
                while (body.firstChild) { body.removeChild(body.firstChild); }
                (data.roles || []).forEach(function (r) {
                    var tr = document.createElement('tr');
                    tr.appendChild(el('th', null, r.role));
                    if (r.not_found) {
                        tr.appendChild(el('td', 'muted', '—'));
                        tr.appendChild(el('td', 'muted', '该文件今天还没有日志'));
                    } else if (!r.ok) {
                        tr.appendChild(el('td', 'bad', '0'));
                        tr.appendChild(el('td', 'bad', r.hint || '读取失败'));
                    } else {
                        tr.appendChild(el('td', r.count > 0 ? 'bad' : null, String(r.count)));
                        var td = el('td');
                        var pre = el('pre', 'mono', (r.lines || []).join('\n'));
                        td.appendChild(pre);
                        if (r.truncated) {
                            td.appendChild(el('div', 'hint', '（扫描已达上限，非精确总量）'));
                        }
                        tr.appendChild(td);
                    }
                    body.appendChild(tr);
                });
            }

            setNote('errors-status', data.total > 0 ? 'warn' : 'ok',
                '业务角色 error 合计 ' + (data.total || 0) + ' 条（窗口内，'
                + (data.date || date) + '）');
        });
    }

    /* ---------- 区块 6：配置查看（2.0 序5，脱敏） ---------- */

    function loadConfig() {
        setNote('config-status', 'info', '读取中…');
        getJson(cfg.config_url, function (res) {
            var data = res.data || {};
            if (res.code !== 0) {
                setNote('config-status', 'bad', '读取失败：' + (res.msg || '未知错误'));
                return;
            }

            var notes = $('config-notes');
            if (notes) {
                while (notes.firstChild) { notes.removeChild(notes.firstChild); }
                (data.notes || []).forEach(function (n) {
                    notes.appendChild(el('div', 'hint', n));
                });
            }

            var body = $('tb-config');
            if (body) {
                while (body.firstChild) { body.removeChild(body.firstChild); }
                (data.groups || []).forEach(function (g) {
                    (g.items || []).forEach(function (item, idx) {
                        var tr = document.createElement('tr');
                        tr.appendChild(el('th', null, idx === 0 ? g.name : ''));
                        tr.appendChild(el('td', 'mono', item.key));
                        var td = el('td');
                        if (item.secret && !item.configured) {
                            td.appendChild(el('span', 'muted', '未配置'));
                        } else if (item.masked) {
                            td.appendChild(el('span', 'mono', item.value || '—'));
                        } else {
                            td.appendChild(el('span', item.configured ? null : 'muted',
                                item.configured ? item.value : '（未设置，走代码默认）'));
                        }
                        tr.appendChild(td);
                        body.appendChild(tr);
                    });
                });
            }

            var when = data.mtime_text ? ('（.env mtime ' + data.mtime_text + '）') : '';
            setNote('config-status', 'ok', '白名单键已加载' + when);
        });
    }

    /* ---------- 区块 7：限流命中（2.0 序7） ---------- */

    function loadRate() {
        setNote('rate-status', 'info', '探测中…');
        var trunc = $('rate-truncated');
        if (trunc) { trunc.textContent = ''; }
        getJson(cfg.rate_url, function (res) {
            var data = res.data || {};
            if (res.code !== 0) {
                setNote('rate-status', 'bad', '探测失败：' + (res.msg || '未知错误'));
                return;
            }

            var notes = $('rate-notes');
            if (notes) {
                while (notes.firstChild) { notes.removeChild(notes.firstChild); }
                (data.notes || []).forEach(function (n) {
                    notes.appendChild(el('div', 'hint', n));
                });
            }

            var dims = $('tb-rate-dims');
            if (dims) {
                while (dims.firstChild) { dims.removeChild(dims.firstChild); }
                (data.dims || []).forEach(function (d) {
                    var tr = document.createElement('tr');
                    tr.appendChild(el('th', null, d.label || d.dim));
                    tr.appendChild(el('td', null, String(d.buckets)));
                    tr.appendChild(el('td', d.low_tokens > 0 ? 'bad' : null, String(d.low_tokens)));
                    tr.appendChild(el('td', 'muted', d.low_tokens > 0 ? '有桶令牌偏低' : '正常'));
                    bodyAppend(dims, tr);
                });
            }

            var buckets = $('tb-rate-buckets');
            if (buckets) {
                while (buckets.firstChild) { buckets.removeChild(buckets.firstChild); }
                (data.buckets || []).forEach(function (b) {
                    if (b.level === 'ok') { return; } // 只列被限过的（bad/warn）
                    var tr = document.createElement('tr');
                    tr.appendChild(el('th', null, b.dim));
                    tr.appendChild(el('td', 'mono', b.fingerprint));
                    tr.appendChild(el('td', b.level === 'bad' ? 'bad' : null, String(b.tokens)));
                    tr.appendChild(el('td', b.level === 'bad' ? 'bad' : 'warn',
                        b.level === 'bad' ? '令牌耗尽' : '令牌偏低'));
                    bodyAppend(buckets, tr);
                });
            }

            var api = $('tb-rate-api');
            if (api) {
                while (api.firstChild) { api.removeChild(api.firstChild); }
                (data.api_windows || []).forEach(function (w) {
                    var tr = document.createElement('tr');
                    tr.appendChild(el('th', null, w.minute_text));
                    tr.appendChild(el('td', 'mono', w.fingerprint));
                    tr.appendChild(el('td', 'bad', String(w.hits)));
                    bodyAppend(api, tr);
                });
            }

            if (trunc) {
                trunc.textContent = data.truncated
                    ? '（⚠ SCAN 已达上限，桶 / 窗口数字不保证是全量）'
                    : '';
            }

            var hit = data.hit_today || 0;
            var low = (data.buckets || []).filter(function (b) { return b.level !== 'ok'; }).length;
            setNote('rate-status', hit > 0 || low > 0 ? 'warn' : 'ok',
                '当日 rate_limit_hit = ' + hit
                + ' · 低令牌桶 ' + low + ' 个'
                + ' · 当日 hit 计数跨天归零属正常');
        });
    }

    function bodyAppend(tbody, tr) {
        if (tbody) { tbody.appendChild(tr); }
    }

    /* ---------- 绑定（零定时器） ---------- */

    bind('btn-roles-refresh', loadRoles);
    bind('btn-log-load', loadLog);
    bind('btn-rotation-load', loadRotation);
    bind('btn-queues-refresh', loadQueues);
    bind('btn-errors-load', loadErrors);
    bind('btn-config-load', loadConfig);
    bind('btn-rate-refresh', loadRate);

    // 首屏自动取数：只取角色状态与队列（轻量、是本页的核心问题）；
    // 日志 / 轮换 / 错误 / 配置 / 限流由用户显式触发
    if (cfg.perms && cfg.perms.roles) {
        loadRoles();
    }
    if (cfg.perms && cfg.perms.queues) {
        loadQueues();
    }
})();

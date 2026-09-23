/**
 * GatewayPush 运维页（P5）—— 角色状态 / 日志尾读 / 密钥轮换引导。
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
        });
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

    /* ---------- 绑定（零定时器） ---------- */

    bind('btn-roles-refresh', loadRoles);
    bind('btn-log-load', loadLog);
    bind('btn-rotation-load', loadRotation);

    // 首屏自动取数：只取角色状态（轻量、是本页的核心问题）；日志与轮换由用户显式触发
    if (cfg.perms && cfg.perms.roles) {
        loadRoles();
    }
})();

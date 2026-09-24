/**
 * GatewayPush 行为日志页 —— GET /api/audit/logs。
 *
 * 渲染纪律（与 session.js / metrics.js / trace.js 同口径）：
 * - 前端不编词：403 / 空列表 / 请求失败都如实展示；
 * - textContent only（防 XSS）；零定时器；打开时拉一次，其余由筛选/翻页触发。
 */
(function () {
    'use strict';

    var cfgNode = document.getElementById('audit-page-config');
    var cfg = {};
    try {
        cfg = JSON.parse(cfgNode ? cfgNode.textContent : '{}') || {};
    } catch (e) {
        cfg = {};
    }

    var page = 1;

    function $(id) { return document.getElementById(id); }

    function el(tag, className, text) {
        var n = document.createElement(tag);
        if (className) { n.className = className; }
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        return n;
    }

    function setStatus(msg) {
        var n = $('audit-status');
        if (n) { n.textContent = msg || ''; }
    }

    function tbody() {
        var host = $('tbl-audit');
        if (!host) { return null; }
        if (!host.__tbody) {
            var t = host.querySelector('tbody');
            if (!t) {
                t = document.createElement('tbody');
                host.appendChild(t);
            }
            host.__tbody = t;
        }
        return host.__tbody;
    }

    function clearBody() {
        var t = tbody();
        if (t) { t.textContent = ''; }
    }

    function buildUrl() {
        var base = cfg.list_url || '/api/audit/logs';
        var qs = [];
        function add(k, v) {
            if (v !== '' && v !== null && v !== undefined) {
                qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
            }
        }
        add('action', $('sel-action') ? $('sel-action').value : '');
        add('result', $('sel-result') ? $('sel-result').value : '');
        add('admin_id', $('inp-admin') && $('inp-admin').value !== '' ? $('inp-admin').value : '');
        add('from', $('inp-from') ? $('inp-from').value.trim() : '');
        add('to', $('inp-to') ? $('inp-to').value.trim() : '');
        add('size', $('sel-size') ? $('sel-size').value : (cfg.page_size_default || 20));
        add('page', String(page));
        return base + (qs.length ? ('?' + qs.join('&')) : '');
    }

    function renderMsg(msg) {
        clearBody();
        var t = tbody();
        if (!t) { return; }
        var tr = document.createElement('tr');
        var td = el('td', null, msg);
        td.colSpan = 9;
        tr.appendChild(td);
        t.appendChild(tr);
    }

    function renderHead() {
        var t = tbody();
        if (!t) { return; }
        var head = document.createElement('tr');
        ['ID', '时间', '管理员', '动作', '目标类型', '目标', '结果', '码', '参数'].forEach(function (title) {
            head.appendChild(el('th', null, title));
        });
        t.appendChild(head);
    }

    function renderRows(rows) {
        clearBody();
        var t = tbody();
        if (!t) { return; }
        renderHead();
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.appendChild(el('td', null, row.id));
            tr.appendChild(el('td', null, row.created_at));
            tr.appendChild(el('td', null, row.admin_name + ' (#' + row.admin_id + ')'));
            tr.appendChild(el('td', null, row.action));
            tr.appendChild(el('td', null, row.target_type || '—'));
            tr.appendChild(el('td', null, row.target || '—'));
            var tdResult = document.createElement('td');
            tdResult.appendChild(el('span', 'tag ' + (row.result === 'ok' ? 'ok' : 'failed'), row.result));
            tr.appendChild(tdResult);
            tr.appendChild(el('td', null, row.code));
            tr.appendChild(el('td', 'params', row.params || '—'));
            t.appendChild(tr);
        });
    }

    function updatePager(data) {
        var total = Number(data.total) || 0;
        var size = Number(data.size) || 20;
        var maxPage = Math.max(1, Math.ceil(total / size));
        var info = $('page-info');
        if (info) {
            info.textContent = '第 ' + page + ' / ' + maxPage + ' 页 · 共 ' + total + ' 条';
        }
        var prev = $('btn-prev');
        var next = $('btn-next');
        if (prev) { prev.disabled = page <= 1; }
        if (next) { next.disabled = page >= maxPage; }
    }

    function load() {
        if (!cfg.perms || cfg.perms.list !== true) {
            return;
        }
        setStatus('加载中…');
        fetch(buildUrl()).then(function (r) {
            return r.json().then(function (j) {
                if (r.status === 403) {
                    setStatus('403：当前账号无该端点权限');
                    renderMsg('403：当前账号无该端点权限');
                    return;
                }
                if (j && j.code === 0) {
                    var data = j.data || {};
                    var rows = Array.isArray(data.rows) ? data.rows : [];
                    if (rows.length === 0) {
                        renderMsg('无匹配记录');
                    } else {
                        renderRows(rows);
                    }
                    page = Number(data.page) || page;
                    updatePager(data);
                    setStatus('已加载 ' + rows.length + ' 条（页内）');
                    return;
                }
                var msg = (j && j.msg) ? ('业务码 ' + j.code + '：' + j.msg) : ('HTTP ' + r.status);
                setStatus(msg);
                renderMsg(msg);
            });
        }).catch(function (e) {
            var msg = '请求失败：' + (e && e.message ? e.message : e);
            setStatus(msg);
            renderMsg(msg);
        });
    }

    var btnSearch = $('btn-search');
    if (btnSearch) {
        btnSearch.addEventListener('click', function () {
            page = 1;
            load();
        });
    }
    var btnPrev = $('btn-prev');
    if (btnPrev) {
        btnPrev.addEventListener('click', function () {
            if (page > 1) {
                page -= 1;
                load();
            }
        });
    }
    var btnNext = $('btn-next');
    if (btnNext) {
        btnNext.addEventListener('click', function () {
            page += 1;
            load();
        });
    }

    // 打开时拉一次（仅当有列表权限）
    if (cfg.perms && cfg.perms.list === true) {
        load();
    }
}());

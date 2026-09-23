/**
 * GatewayPush uid 一站式排查页（2.0 §3.2）。
 *
 * 输入 uid 后**并行**调既有 4 个只读端点，各区块独立展示成败 ——
 * 一个端点权限缺失 / 报错，不影响其余区块（「没做到就说没做到」）。
 *
 * 渲染纪律（与 session.js / ops.js / metrics.js 同口径）：
 * - 前端不编词：端点 403 / 报错 / 空结果都如实落进对应区块；
 * - textContent only（防 XSS）；零定时器；不自动取数（等用户点「排查」）。
 */
(function () {
    'use strict';

    var cfgNode = document.getElementById('trace-page-config');
    var cfg = {};
    try {
        cfg = JSON.parse(cfgNode ? cfgNode.textContent : '{}') || {};
    } catch (e) {
        cfg = {};
    }

    var VALID_ID = /^[A-Za-z0-9_\-.:@#]{1,64}$/;  // 与后端 validId 的宽松口径对齐（客户端预检，非边界）

    function $(id) { return document.getElementById(id); }

    function el(tag, className, text) {
        var n = document.createElement(tag);
        if (className) { n.className = className; }
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        return n;
    }

    function setStatus(msg) {
        var n = $('trace-status');
        if (n) { n.textContent = msg || ''; }
    }

    /** 渲染「键值对」小表（sections 复用） */
    function renderKv(tbodyId, pairs) {
        var tbody = $(tbodyId) ? $(tbodyId).querySelector('tbody') : null;
        if (!tbody) { return; }
        tbody.textContent = '';
        pairs.forEach(function (pair) {
            var tr = document.createElement('tr');
            tr.appendChild(el('th', null, pair[0]));
            tr.appendChild(el('td', null, pair[1]));  // 第二个参数是 className，不是内容
            tbody.appendChild(tr);
        });
    }

    /** 渲染单行消息（空结果 / 错误 / 无权限共用一种形态，文案如实） */
    function renderMsg(tbodyId, msg) {
        var tbody = $(tbodyId) ? $(tbodyId).querySelector('tbody') : null;
        if (!tbody) { return; }
        tbody.textContent = '';
        var tr = document.createElement('tr');
        var td = el('td', null, msg);
        td.colSpan = 2;
        tr.appendChild(td);
        tbody.appendChild(tr);
    }

    /** 统一 GET：403 / 网络错 / 非 0 业务码都归一为 {ok, payload|msg} */
    function getJson(url) {
        return fetch(url).then(function (r) {
            return r.json().then(function (j) {
                if (r.status === 403) {
                    return { ok: false, msg: '403：当前账号无该端点权限' };
                }
                if (j && j.code === 0) {
                    return { ok: true, payload: j.data };
                }
                return { ok: false, msg: (j && j.msg) ? ('业务码 ' + j.code + '：' + j.msg) : ('HTTP ' + r.status) };
            });
        }).catch(function (e) {
            return { ok: false, msg: '请求失败：' + (e && e.message ? e.message : e) };
        });
    }

    /* ---------------- 各区块渲染 ---------------- */

    function renderSessions(payload) {
        var items = payload && Array.isArray(payload.items) ? payload.items : [];
        if (items.length === 0) {
            renderMsg('tbl-sessions', (payload && payload.hint) || '无会话');
            return;
        }
        var tbody = $('tbl-sessions').querySelector('tbody');
        tbody.textContent = '';
        var head = document.createElement('tr');
        ['状态', 'client_id', '协议', 'device_id', '来源 IP', '连接于', '最后活跃'].forEach(function (t) {
            head.appendChild(el('th', null, t));
        });
        tbody.appendChild(head);
        items.forEach(function (row) {
            var tr = document.createElement('tr');
            var tdState = el('td');
            tdState.appendChild(el('span', 'tag ' + (row.state === 'online' ? 'on' : 'off'), row.state));
            tr.appendChild(tdState);
            tr.appendChild(el('td', null, row.client_id));
            tr.appendChild(el('td', null, row.protocol));
            tr.appendChild(el('td', null, row.device_id || '—'));
            tr.appendChild(el('td', null, row.client_ip ? (row.client_ip + ':' + row.client_port) : '—'));
            tr.appendChild(el('td', null, row.connect_at > 0 ? new Date(row.connect_at * 1000).toLocaleString() : '—'));
            tr.appendChild(el('td', null, row.last_active > 0 ? new Date(row.last_active * 1000).toLocaleString() : '—'));
            tbody.appendChild(tr);
        });
    }

    function renderDevice(payload) {
        var items = payload && Array.isArray(payload.items) ? payload.items : [];
        if (items.length === 0) {
            renderMsg('tbl-device', (payload && payload.hint) || '该 device_id 当前未绑定任何 clientId');
            return;
        }
        var pairs = items.map(function (row) {
            return [row.client_id, row.protocol + ' · ' + row.state + (row.uid ? ' · uid=' + row.uid : '')];
        });
        renderKv('tbl-device', pairs);
    }

    function renderOffline(payload) {
        if (!payload) {
            renderMsg('tbl-offline', '无数据');
            return;
        }
        var len = typeof payload.len === 'number' ? payload.len : 0;
        var pairs = [
            ['队列长度', String(len)],
            ['分页', '第 ' + payload.page + ' / ' + (payload.pages || 1) + ' 页（每页 ' + payload.size + '）'],
            ['最早一条（index 0，重连后按序投递）', Array.isArray(payload.items) && payload.items.length > 0 ? payload.items[0] : '（空队列）'],
        ];
        renderKv('tbl-offline', pairs);
    }

    function renderSubs(payload) {
        // 端点返回 {uid, topics, topic, subscribers} —— 带 uid 查询时只填 uid 半边
        var topics = payload && Array.isArray(payload.topics) ? payload.topics : null;
        if (!topics || topics.length === 0) {
            renderMsg('tbl-subs', '该 uid 无任何订阅');
            return;
        }
        renderKv('tbl-subs', [['订阅数', String(topics.length)], ['主题', topics.join(', ')]]);
    }

    /* ---------------- 排查主流程（并行 4 请求） ---------------- */

    function trace() {
        var uid = ($('inp-uid').value || '').trim();
        var deviceId = ($('inp-device').value || '').trim();

        if (uid === '') {
            setStatus('uid 必填。');
            $('inp-uid').focus();
            return;
        }
        if (!VALID_ID.test(uid)) {
            setStatus('uid 含非法字符（允许字母数字与 _-.:@#）。');
            return;
        }
        if (deviceId !== '' && !VALID_ID.test(deviceId)) {
            setStatus('device_id 含非法字符。');
            return;
        }

        var perms = cfg.perms || {};
        setStatus('排查中…');

        // 4 路并行；各自独立落区块，互不拖累
        var jobs = [];

        jobs.push((perms.byUid === true
            ? getJson(cfg.by_uid_url + '/' + encodeURIComponent(uid))
            : Promise.resolve({ ok: false, msg: '无 by-uid 端点权限' })
        ).then(function (r) {
            if (!r.ok) { renderMsg('tbl-sessions', r.msg); return; }
            renderSessions(r.payload);
        }));

        jobs.push((perms.offline === true
            ? getJson(cfg.offline_url + '/' + encodeURIComponent(uid))
            : Promise.resolve({ ok: false, msg: '无 offline 端点权限' })
        ).then(function (r) {
            if (!r.ok) { renderMsg('tbl-offline', r.msg); return; }
            renderOffline(r.payload);
        }));

        jobs.push((perms.subs === true
            ? getJson(cfg.subscriptions_url + '?uid=' + encodeURIComponent(uid))
            : Promise.resolve({ ok: false, msg: '无 subscriptions 端点权限' })
        ).then(function (r) {
            if (!r.ok) { renderMsg('tbl-subs', r.msg); return; }
            renderSubs(r.payload);
        }));

        if (deviceId !== '') {
            $('sec-device').hidden = false;
            renderMsg('tbl-device', '查询中…');
            jobs.push((perms.byDevice === true
                ? getJson(cfg.by_device_url + '/' + encodeURIComponent(deviceId))
                : Promise.resolve({ ok: false, msg: '无 by-device 端点权限' })
            ).then(function (r) {
                if (!r.ok) { renderMsg('tbl-device', r.msg); return; }
                renderDevice(r.payload);
            }));
        } else {
            $('sec-device').hidden = true;
        }

        Promise.all(jobs).then(function () {
            setStatus('排查完成：' + uid);
        });
    }

    /* ---------------- 首屏绑定 ---------------- */

    $('btn-trace').addEventListener('click', trace);
    $('inp-uid').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { trace(); }
    });
    $('inp-device').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { trace(); }
    });
})();

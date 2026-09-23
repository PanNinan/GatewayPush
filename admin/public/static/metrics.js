/**
 * GatewayPush 指标趋势页（2.0 §1.1）。
 *
 * 数据源：GET /api/metrics/range?minutes=&points=（降采样 + 差分速率）与
 *        GET /api/metrics/latest（当前值）。全部只读。
 *
 * 渲染纪律（与 session.js / ops.js 同口径）：
 * - 前端**不编词**：空数据 / 权限不足都如实展示，不伪装成「正常」；
 * - **textContent only**：任何服务端回传值走 textContent，绝不 innerHTML（防 XSS）；
 * - **零定时器**：手动刷新 + 范围切换，不做自动轮询（看实时去 dashboard 页）；
 * - 折线是**手写 SVG**（零依赖）：三组曲线各一张图，x=时间 y=值。
 */
(function () {
    'use strict';

    var cfgNode = document.getElementById('metrics-page-config');
    var cfg = {};
    try {
        cfg = JSON.parse(cfgNode ? cfgNode.textContent : '{}') || {};
    } catch (e) {
        cfg = {};
    }

    function $(id) { return document.getElementById(id); }

    function el(tag, className, text) {
        var n = document.createElement(tag);
        if (className) { n.className = className; }
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        return n;
    }

    function fmtTime(ts) {
        var d = new Date(ts * 1000);
        var p = function (x) { return (x < 10 ? '0' : '') + x; };
        return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    function fmtNum(v) {
        if (v === null || v === undefined) { return '—'; }
        var n = Number(v);
        if (!isFinite(n)) { return '—'; }
        return n >= 100 ? String(Math.round(n)) : String(Math.round(n * 100) / 100);
    }

    /** 统一取数（GET），失败时在状态栏如实展示错误 */
    function getJson(url, onDone) {
        fetch(url).then(function (r) { return r.json(); }).then(function (j) {
            onDone(j);
        }).catch(function (e) {
            setStatus('请求失败：' + (e && e.message ? e.message : e));
        });
    }

    function setStatus(msg) {
        var n = $('metrics-status');
        if (n) { n.textContent = msg || ''; }
    }

    /* ---------------- SVG 折线图（零依赖） ---------------- */

    var SERIES_COLORS = ['#2b6cb0', '#2f9e44', '#c0392b', '#b0852b', '#7048a8'];

    /**
     * 画一张折线图。series: [{ name, color, points: [{x, y}] }]，y 为 null 的点断线。
     * y 轴按全图最大值归一；x 轴按时间范围线性。
     */
    function drawChart(containerId, series, xFrom, xTo, unit) {
        var host = $(containerId);
        if (!host) { return; }
        host.textContent = '';

        var W = 1000, H = 220, PAD_L = 46, PAD_R = 12, PAD_T = 10, PAD_B = 22;
        var svgNS = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);

        // 全图 y 上限：各序列非 null 值的最大值；全空则用 1（画平线）
        var yMax = 1;
        series.forEach(function (s) {
            s.points.forEach(function (p) {
                if (p.y !== null && isFinite(p.y) && p.y > yMax) { yMax = p.y; }
            });
        });
        yMax = yMax * 1.15;

        function xPix(x) { return PAD_L + (x - xFrom) / Math.max(1, xTo - xFrom) * (W - PAD_L - PAD_R); }
        function yPix(y) { return PAD_T + (1 - y / yMax) * (H - PAD_T - PAD_B); }

        // 网格：y 轴 4 条 + 数值标签
        for (var g = 0; g <= 4; g++) {
            var gy = yMax * g / 4;
            var line = document.createElementNS(svgNS, 'line');
            line.setAttribute('x1', PAD_L); line.setAttribute('x2', W - PAD_R);
            line.setAttribute('y1', yPix(gy)); line.setAttribute('y2', yPix(gy));
            line.setAttribute('stroke', '#eef1f6');
            svg.appendChild(line);
            var lbl = document.createElementNS(svgNS, 'text');
            lbl.setAttribute('x', PAD_L - 6); lbl.setAttribute('y', yPix(gy) + 4);
            lbl.setAttribute('text-anchor', 'end'); lbl.setAttribute('font-size', '11');
            lbl.setAttribute('fill', '#68758a');
            lbl.textContent = fmtNum(gy);
            svg.appendChild(lbl);
        }

        // x 轴时间标签（首 / 中 / 尾）
        [xFrom, (xFrom + xTo) / 2, xTo].forEach(function (t) {
            var tl = document.createElementNS(svgNS, 'text');
            tl.setAttribute('x', xPix(t)); tl.setAttribute('y', H - 6);
            tl.setAttribute('text-anchor', 'middle'); tl.setAttribute('font-size', '11');
            tl.setAttribute('fill', '#68758a');
            tl.textContent = fmtTime(t);
            svg.appendChild(tl);
        });

        // 各序列折线（null 点断线）
        series.forEach(function (s) {
            var d = '';
            var penDown = false;
            s.points.forEach(function (p) {
                if (p.y === null || !isFinite(p.y)) { penDown = false; return; }
                d += (penDown ? ' L' : ' M') + xPix(p.x).toFixed(1) + ' ' + yPix(p.y).toFixed(1);
                penDown = true;
            });
            if (d !== '') {
                var path = document.createElementNS(svgNS, 'path');
                path.setAttribute('d', d);
                path.setAttribute('fill', 'none');
                path.setAttribute('stroke', s.color);
                path.setAttribute('stroke-width', '1.6');
                svg.appendChild(path);
            }
        });

        var box = el('div', 'chart');
        box.appendChild(svg);
        var legend = el('div', 'legend');
        series.forEach(function (s) {
            var item = el('span');
            var sw = el('span', 'sw');
            sw.style.background = s.color;
            item.appendChild(sw);
            item.appendChild(el('span', null, s.name + (unit ? '（' + unit + '）' : '')));
            legend.appendChild(item);
        });
        box.appendChild(legend);
        host.appendChild(box);
    }

    /** rows → 各图序列。rates 缺键 / 首点（无差分）一律 null 断线，绝不补 0。 */
    function renderCharts(rows, xFrom, xTo) {
        function rateSeries(key, name, color) {
            return {
                name: name, color: color,
                points: rows.map(function (r) {
                    return { x: r.sampled_at, y: r.rates && r.rates[key] !== undefined ? r.rates[key] : null };
                }),
            };
        }
        function gaugeSeries(key, name, color) {
            return {
                name: name, color: color,
                points: rows.map(function (r) { return { x: r.sampled_at, y: r[key] }; }),
            };
        }

        drawChart('chart-conn', [
            gaugeSeries('conn_total', '总在线', SERIES_COLORS[0]),
            gaugeSeries('conn_ws', 'WS', SERIES_COLORS[1]),
            gaugeSeries('conn_udp', 'UDP', SERIES_COLORS[2]),
        ], xFrom, xTo, '条');

        drawChart('chart-msg', [
            rateSeries('msg_in', 'msg_in', SERIES_COLORS[0]),
            rateSeries('msg_out', 'msg_out', SERIES_COLORS[1]),
        ], xFrom, xTo, '条/秒');

        drawChart('chart-fail', [
            rateSeries('msg_fail', 'msg_fail', SERIES_COLORS[2]),
            rateSeries('action_fail', 'action_fail', SERIES_COLORS[3]),
            rateSeries('rate_limit_hit', '限流命中', SERIES_COLORS[4]),
        ], xFrom, xTo, '次/秒');
    }

    /* ---------------- 当前值表 ---------------- */

    function renderLatest(row) {
        var tbody = $('tbl-latest') ? $('tbl-latest').querySelector('tbody') : null;
        if (!tbody) { return; }
        tbody.textContent = '';

        if (!row) {
            var tr0 = document.createElement('tr');
            var td0 = el('td', null, '暂无采样数据（采样进程尚未运行，或数据刚被清理）');
            td0.colSpan = 2;
            tr0.appendChild(td0);
            tbody.appendChild(tr0);
            return;
        }

        var c = row.counters || {};
        var q = row.queues || {};
        var rows = [
            ['采样时刻', fmtTime(row.sampled_at)],
            ['在线连接', 'WS ' + row.conn_ws + ' · UDP ' + row.conn_udp + ' · 总 ' + row.conn_total],
            ['消息累计', 'in ' + fmtNum(c.msg_in) + ' · out ' + fmtNum(c.msg_out) + ' · fail ' + fmtNum(c.msg_fail)],
            ['动作累计', 'ok ' + fmtNum(c.action_ok) + ' · fail ' + fmtNum(c.action_fail)],
            ['限流累计', 'hit ' + fmtNum(c.rate_limit_hit)],
            ['队列深度', Object.keys(q).map(function (k) { return k + ': ' + q[k]; }).join(' · ') || '—'],
        ];
        rows.forEach(function (pair) {
            var tr = document.createElement('tr');
            tr.appendChild(el('th', null, pair[0]));
            tr.appendChild(el('td', null, pair[1]));  // 第二个参数是 className，不是内容
            tbody.appendChild(tr);
        });
    }

    /* ---------------- 刷新主流程 ---------------- */

    function refresh() {
        var minutes = parseInt(($('sel-range') && $('sel-range').value) || '60', 10);
        var points = Math.min(240, cfg.points_max || 720);
        setStatus('加载中…');

        var wantLatest = (cfg.perms && cfg.perms.latest) === true;
        var wantRange = (cfg.perms && cfg.perms.range) === true;

        if (wantLatest) {
            getJson(cfg.latest_url + '?_=' + Date.now(), function (j) {
                renderLatest(j && j.data ? j.data.row : null);
            });
        }

        if (!wantRange) { setStatus(''); return; }

        getJson(cfg.range_url + '?minutes=' + minutes + '&points=' + points + '&_=' + Date.now(), function (j) {
            var data = j && j.data ? j.data : {};
            var rows = Array.isArray(data.rows) ? data.rows : [];

            // 空 dataset：如实提示，绝不画一张「全是 0」的假图
            var hint = $('empty-hint');
            if (hint) { hint.hidden = rows.length > 0; }
            if (rows.length === 0) {
                ['chart-conn', 'chart-msg', 'chart-fail'].forEach(function (id) {
                    var host = $(id);
                    if (host) { host.textContent = ''; }
                });
                setStatus('范围内 0 个采样点');
                return;
            }

            renderCharts(rows, data.from || rows[0].sampled_at, data.to || rows[rows.length - 1].sampled_at);
            setStatus(rows.length + ' 个采样点');
        });
    }

    /* ---------------- 首屏绑定 ---------------- */

    $('btn-refresh').addEventListener('click', refresh);
    $('sel-range').addEventListener('change', refresh);
    refresh();  // 首屏自动取一次（与 dashboard 页同语义；本页无定时器，不轮询）
})();

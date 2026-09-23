/**
 * GatewayPush 后台 · 健康总览页前端
 *
 * 由 webman 静态中间件直接提供（/static/dashboard.js），无权限校验。
 * ⚠ 不得写入任何密钥或内网凭据。
 *
 * ## 设计要点（四条，逐条对应「轮询方案为什么能站得住」）
 *
 * 1. **单 tick 分发**：整页只有两个定时器（快 / 慢），各自拉**一个**端点，
 *    再分发给所有卡片。严禁「每个卡片一个请求」—— 那是轮询方案最常见的自毁方式。
 * 2. **setTimeout 链式调用，不用 setInterval**：请求慢于间隔时，setInterval 会让
 *    请求层层堆积（每个都在飞），链式调用天然保证「上一次结束才排下一次」。
 * 3. **失败指数退避**：连续失败时把间隔翻倍（封顶 60s）。否则服务端一挂，
 *    多个标签页会以最高频率持续锤它，把「服务端慢」放大成「服务端死」。
 * 4. **页面隐藏即停**：`visibilitychange` 隐藏时清掉定时器并**作废在途请求**，
 *    可见时立刻重拉。标签页常被遗忘在后台，这条能把长期空转的请求量降到 0。
 *
 * ## 关于「在途请求作废」（最容易写出死循环的地方）
 *
 * 链式 setTimeout 本身不会并发，但 `visibilitychange` 恢复时会**立刻**开新循环，
 * 于是旧循环可能还有一个请求在飞。若不作废，两个循环会各自续排 → 请求量翻倍且
 * 持续累积。做法是给每个循环一个自增序号：响应回来时序号已变则判定为陈旧，
 * **直接返回且不续排**（续排由取代它的新循环负责）。
 *
 * 反过来，若「陈旧就返回」却仍由旧循环续排，会出现两个定时器并存；
 * 若「陈旧也续排」则会翻倍。两种写法都是错的，此处是唯一正确解。
 *
 * ## 为什么趋势只能在浏览器端累积
 *
 * 主项目没有历史指标存储（Redis 只有当前 `metrics:gauge` 与当日累计
 * `metrics:counter:{Ymd}`，`/stats` 也只回当前快照）。做服务端历史需要新增
 * 采样表 + 采样进程，属后续阶段。因此本页趋势是**环形缓冲**，刷新即清零，
 * UI 上必须如实标注「本次会话」。
 *
 * ## XSS 纪律
 *
 * 服务端返回的**任何字符串**都必须经 `textContent` / `createTextNode` 落地，
 * 一律不得拼进 `innerHTML`。SVG 只用数值属性，故可安全用 `setAttribute`。
 */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';
    var MAX_BACKOFF_MS = 60000;
    var ROLLOVER_NOTICE_MS = 60000;

    var cfg = JSON.parse(document.getElementById('dashboard-config').textContent);

    var state = {
        prevCounter: null,
        prevTs: 0,
        history: [],
        transportAlert: null,
        rolloverAt: 0,
        liveFails: 0,
        slowFails: 0,
        liveTimer: null,
        slowTimer: null,
        liveSeq: 0,
        slowSeq: 0
    };

    // ------------------------------------------------------------------
    // DOM 小工具（全部经 textContent，避免 HTML 注入）
    // ------------------------------------------------------------------

    function $(id) { return document.getElementById(id); }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }

    function svgEl(name, attrs) {
        var node = document.createElementNS(SVG_NS, name);
        Object.keys(attrs).forEach(function (key) { node.setAttribute(key, String(attrs[key])); });
        return node;
    }

    function clear(node) { while (node && node.firstChild) { node.removeChild(node.firstChild); } }

    function setText(id, text) {
        var node = $(id);
        if (node) { node.textContent = String(text); }
    }

    /** 设置「数值 + 单位小字」的卡片主值 */
    function setValue(id, text, unit) {
        var node = $(id);
        if (!node) { return; }
        clear(node);
        node.appendChild(document.createTextNode(String(text)));
        if (unit) { node.appendChild(el('small', null, unit)); }
    }

    /** 往 tbody 里放一行「空态」提示 */
    function setRowEmpty(bodyId, message) {
        var body = $(bodyId);
        if (!body) { return; }
        clear(body);
        var tr = el('tr');
        var td = el('td', 'empty', message);
        td.colSpan = 99;
        tr.appendChild(td);
        body.appendChild(tr);
    }

    // ------------------------------------------------------------------
    // 格式化
    // ------------------------------------------------------------------

    function num(value) {
        var n = Number(value);
        return Number.isFinite(n) ? n : 0;
    }

    function fmtInt(value) { return Math.round(num(value)).toLocaleString('zh-CN'); }

    function fmtFixed(value, digits) {
        var n = Number(value);
        return Number.isFinite(n) ? n.toFixed(digits === undefined ? 2 : digits) : '—';
    }

    /** 比率：null（无样本）显示为 —，与 0.00% 严格区分 */
    function fmtPct(value) {
        return value === null || value === undefined ? '—' : fmtFixed(value, 2) + '%';
    }

    function fmtBytes(value) {
        var n = num(value);
        if (n <= 0) { return '—'; }
        if (n < 1024) { return n + ' B'; }
        if (n < 1048576) { return (n / 1024).toFixed(1) + ' KB'; }
        return (n / 1048576).toFixed(1) + ' MB';
    }

    /** 距今秒数；负数表示时间戳不可信 */
    function fmtAge(secs) {
        var n = Number(secs);
        if (!Number.isFinite(n) || n < 0) { return '—'; }
        if (n < 60) { return n + 's'; }
        if (n < 3600) { return (n / 60).toFixed(1) + 'm'; }
        return (n / 3600).toFixed(1) + 'h';
    }

    function fmtClock(ts) {
        var n = num(ts);
        if (n <= 0) { return '—'; }
        var d = new Date(n * 1000);
        var p = function (v) { return v < 10 ? '0' + v : String(v); };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
            + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    function fmtRate(value) {
        return value === null || value === undefined ? '—' : fmtFixed(value, 1) + ' /s';
    }

    function tag(level, text) { return el('span', 'tag ' + (level || 'mute'), text); }

    // ------------------------------------------------------------------
    // 环形缓冲与速率
    // ------------------------------------------------------------------

    /**
     * 由相邻两次 counter 快照算瞬时速率。
     *
     * **负增量 = 日切**：counter 键按日期分桶（metrics:counter:{Ymd}），跨零点后
     * 新键从 0 开始，于是 `cur - prev` 为负。此时窗口失效，必须清空历史而不是
     * 把负速率画进图里（会得到一条诡异的负值折线）。
     */
    function trackRates(counter, ts) {
        var prev = state.prevCounter;
        var prevTs = state.prevTs;

        state.prevCounter = counter;
        state.prevTs = ts;

        if (!prev || prevTs <= 0 || ts <= prevTs) {
            return { msg: null, push: null, rollover: false };
        }

        var dMsg = num(counter.msg_in) - num(prev.msg_in);
        var dPush = num(counter.push_in) - num(prev.push_in);

        if (dMsg < 0 || dPush < 0) {
            return { msg: null, push: null, rollover: true };
        }

        var dt = ts - prevTs;
        return { msg: dMsg / dt, push: dPush / dt, rollover: false };
    }

    function pushHistory(point) {
        state.history.push(point);
        while (state.history.length > cfg.history_points) { state.history.shift(); }
    }

    function series(key) {
        return state.history
            .map(function (p) { return p[key]; })
            .filter(function (v) { return Number.isFinite(v); });
    }

    // ------------------------------------------------------------------
    // 自绘 SVG 折线图（零依赖）
    // ------------------------------------------------------------------

    function sparkline(containerId, values) {
        var box = $(containerId);
        if (!box) { return; }
        clear(box);

        if (values.length < 2) {
            box.appendChild(el('div', 'empty', '采样中…（速率需 ≥ 2 次采样）'));
            return;
        }

        var w = 300;
        var h = 88;
        var pad = 4;
        var min = values[0];
        var max = values[0];
        values.forEach(function (v) {
            if (v < min) { min = v; }
            if (v > max) { max = v; }
        });
        // 全平时把量程拉开，否则除零；单点值落在中线也更易读
        if (min === max) { min -= 1; max += 1; }

        var stepX = (w - pad * 2) / (values.length - 1);
        var span = max - min;
        var points = values.map(function (v, i) {
            var x = pad + i * stepX;
            var y = pad + (h - pad * 2) * (1 - (v - min) / span);
            return x.toFixed(2) + ',' + y.toFixed(2);
        }).join(' ');

        var lastX = (pad + stepX * (values.length - 1)).toFixed(2);
        var baseline = (h - pad).toFixed(2);
        var area = pad + ',' + baseline + ' ' + points + ' ' + lastX + ',' + baseline;

        var svg = svgEl('svg', { viewBox: '0 0 ' + w + ' ' + h, preserveAspectRatio: 'none' });
        svg.appendChild(svgEl('line', { class: 'axis', x1: pad, y1: baseline, x2: w - pad, y2: baseline }));
        svg.appendChild(svgEl('polygon', { class: 'area', points: area }));
        svg.appendChild(svgEl('polyline', {
            class: 'line',
            points: points,
            // 非等比缩放（preserveAspectRatio=none）会把描边也拉伸，这条保持线宽恒定
            'vector-effect': 'non-scaling-stroke'
        }));

        box.appendChild(svg);
    }

    // ------------------------------------------------------------------
    // 渲染：头部 / 卡片
    // ------------------------------------------------------------------

    function markPill(id, ok) {
        var pill = $(id);
        if (!pill) { return; }
        var dot = pill.querySelector('.dot');
        if (dot) { dot.className = 'dot ' + (ok ? 'ok' : 'bad'); }
    }

    function renderCards(redis, derived) {
        var gauge = redis.gauge || {};
        var procs = derived.processes || [];
        var alive = procs.filter(function (p) { return p.alive; }).length;

        setValue('c-online', fmtInt(redis.online));
        setText('c-online-sub', 'conn_total ' + fmtInt(gauge.conn_total)
            + ' · ws ' + fmtInt(gauge.conn_ws)
            + ' · udp ' + fmtInt(gauge.conn_udp));

        setValue('c-latency', fmtFixed(redis.latency_ms, 2), 'ms');

        setValue('c-procs', alive);
        setText('c-procs-sub', '共 ' + procs.length + ' 个 PID'
            + (procs.length - alive > 0 ? ' · 残留 ' + (procs.length - alive) : ''));

        setValue('c-counter', Object.keys(redis.counter || {}).length, 'counter');
        setValue('c-gauge', Object.keys(gauge).length, 'gauge');
    }

    // ------------------------------------------------------------------
    // 渲染：派生率
    // ------------------------------------------------------------------

    function renderRatios(ratios) {
        if (!ratios || !ratios.length) {
            setRowEmpty('tb-ratios',
                '无派生数据 —— Redis 不可用，或主项目 MONITOR_ENABLE=false。');
            return;
        }

        var body = $('tb-ratios');
        clear(body);

        ratios.forEach(function (r) {
            var tr = el('tr');
            tr.appendChild(el('td', null, r.label));

            var tdValue = el('td', 'num');
            tdValue.appendChild(r.value === null ? tag('mute', '—') : el('b', null, fmtPct(r.value)));
            tr.appendChild(tdValue);

            var tdLevel = el('td');
            if (r.kind === 'share') {
                tdLevel.appendChild(tag('mute', '占比'));
            } else if (r.level === 'bad') {
                tdLevel.appendChild(tag('bad', '严重'));
            } else if (r.level === 'warn') {
                tdLevel.appendChild(tag('warn', '预警'));
            } else {
                tdLevel.appendChild(tag('ok', '正常'));
            }
            tr.appendChild(tdLevel);

            tr.appendChild(el('td', 'mono', r.formula));
            tr.appendChild(el('td', 'num', fmtInt(r.num) + ' / ' + fmtInt(r.den)));
            body.appendChild(tr);
        });
    }

    // ------------------------------------------------------------------
    // 渲染：队列
    // ------------------------------------------------------------------

    function renderQueues(queues, warnDepth) {
        var body = $('tb-queues');
        var bars = $('q-bars');
        if (!body || !bars) { return; }

        clear(body);
        clear(bars);

        var names = Object.keys(queues || {});
        if (!names.length) {
            bars.appendChild(el('div', 'empty',
                '读不到队列键 —— 队列为空时 Redis 会自动删除空列表，属正常；'
                + '若长期为空请核对 DB / PREFIX。'));
            return;
        }

        var maxDepth = 1;
        names.forEach(function (n) { if (queues[n] > maxDepth) { maxDepth = queues[n]; } });

        names.forEach(function (name) {
            var depth = num(queues[name]);
            var isBad = depth >= warnDepth;

            var row = el('div', 'bar');
            var head = el('div', 'bar-h');
            head.appendChild(el('span', 'mono', name));
            head.appendChild(el('b', null, fmtInt(depth)));
            row.appendChild(head);

            var track = el('div', 'bar-track');
            var fill = el('div', 'bar-fill' + (isBad ? ' bad' : (depth > 0 ? ' warn' : '')));
            // 非零但极小也要看得见，故给 2% 下限
            fill.style.width = (depth > 0 ? Math.max(2, Math.round(depth / maxDepth * 100)) : 0) + '%';
            track.appendChild(fill);
            row.appendChild(track);
            bars.appendChild(row);

            var tr = el('tr');
            tr.appendChild(el('td', 'mono', name));
            tr.appendChild(el('td', 'num', fmtInt(depth)));
            var tdState = el('td');
            if (depth === 0) {
                tdState.appendChild(tag('ok', '空'));
            } else if (isBad) {
                tdState.appendChild(tag('bad', '积压超阈值'));
            } else {
                tdState.appendChild(tag('warn', '有待处理'));
            }
            tr.appendChild(tdState);
            body.appendChild(tr);
        });
    }

    // ------------------------------------------------------------------
    // 渲染：进程表
    // ------------------------------------------------------------------

    function fmtJobs(tasks) {
        var names = Object.keys(tasks || {});
        if (!names.length) { return document.createTextNode('—'); }

        var frag = document.createDocumentFragment();
        names.forEach(function (name) {
            var job = tasks[name];
            var span = el('span');
            span.appendChild(el('b', null, name));
            span.appendChild(document.createTextNode(' ' + fmtInt(job.count) + '次'));
            if (num(job.skip) > 0) { span.appendChild(document.createTextNode(' 跳过' + fmtInt(job.skip))); }
            if (num(job.fail) > 0) { span.appendChild(document.createTextNode(' 失败' + fmtInt(job.fail))); }
            span.appendChild(document.createTextNode(' ' + fmtFixed(job.last_cost, 4) + 's'));
            if (job.running) { span.appendChild(document.createTextNode(' 运行中')); }
            frag.appendChild(span);
        });
        return frag;
    }

    function renderProcesses(processes) {
        if (!processes || !processes.length) {
            setRowEmpty('tb-procs',
                '无进程指标 —— metrics:gauge 里没有任何 pid_at:* 字段。'
                + '主项目 MONITOR_ENABLE=false 时采集整条短路，或 DB/PREFIX 配错'
                + '（配错不报错，只读到空数据）。');
            return;
        }

        var body = $('tb-procs');
        clear(body);

        processes.forEach(function (p) {
            var tr = el('tr');
            tr.appendChild(el('td', 'mono', p.pid));
            tr.appendChild(el('td', null, p.role));
            tr.appendChild(el('td', 'num', p.worker_id < 0 ? '—' : p.worker_id));
            tr.appendChild(el('td', 'num', fmtBytes(p.memory_bytes)));
            tr.appendChild(el('td', 'num', fmtAge(p.age_secs)));

            var tdState = el('td');
            tdState.appendChild(p.alive ? tag('ok', '存活') : tag('bad', '已退出（残留）'));
            tr.appendChild(tdState);

            var tdJobs = el('td');
            tdJobs.appendChild(el('div', 'jobs')).appendChild(fmtJobs(p.tasks));
            tr.appendChild(tdJobs);

            // 残留行的整行淡出，与状态标签双重表达（不单靠颜色）
            if (!p.alive) { tr.style.opacity = '0.55'; }
            body.appendChild(tr);
        });
    }

    // ------------------------------------------------------------------
    // 渲染：gauge / counter
    // ------------------------------------------------------------------

    function renderKeyValueTable(bodyId, map, emptyText) {
        var entries = Object.keys(map || {}).sort();
        if (!entries.length) {
            setRowEmpty(bodyId, emptyText);
            return;
        }

        var body = $(bodyId);
        clear(body);

        entries.forEach(function (key) {
            var tr = el('tr');
            tr.appendChild(el('td', 'mono', key));
            tr.appendChild(el('td', 'num', String(map[key])));
            body.appendChild(tr);
        });
    }

    // ------------------------------------------------------------------
    // 渲染：告警
    // ------------------------------------------------------------------

    function renderAlerts(serverAlerts) {
        var box = $('alerts');
        if (!box) { return; }
        clear(box);

        var all = [];
        if (state.transportAlert) { all.push(state.transportAlert); }
        (serverAlerts || []).forEach(function (a) { all.push(a); });

        // 日切提示（info 级）：只在刚发生时显示一小段时间
        if (state.rolloverAt && Date.now() - state.rolloverAt < ROLLOVER_NOTICE_MS) {
            all.push({
                level: 'info',
                title: '检测到跨零点日切',
                detail: 'counter 出现负增量（metrics:counter:{Ymd} 按日分桶，新的一天从 0 开始），'
                    + '趋势历史已重置。这是正常现象，不是故障。'
            });
        }

        all.forEach(function (a) {
            var cls = a.level === 'bad' ? 'bad' : (a.level === 'info' ? 'info' : 'warn');
            var div = el('div', 'note ' + cls);
            div.appendChild(el('strong', null, (a.title || '告警') + '：'));
            div.appendChild(document.createTextNode(a.detail || ''));
            box.appendChild(div);
        });
    }

    // ------------------------------------------------------------------
    // 渲染：慢 tick 专属（自检 / DB / 主项目 API）
    // ------------------------------------------------------------------

    function renderSelfCheck(selfCheck) {
        var checks = (selfCheck && selfCheck.checks) || [];
        var note = $('selfcheck-note');
        if (note) { clear(note); }

        if (!checks.length) {
            setRowEmpty('tb-selfcheck', '自检未采集（Redis 不可用）。');
            return;
        }

        var body = $('tb-selfcheck');
        clear(body);

        checks.forEach(function (c) {
            var ok = !!c.exists && c.type === c.expect;
            var tr = el('tr');

            tr.appendChild(el('td', 'mono', c.key));
            tr.appendChild(el('td')).appendChild(c.exists ? tag('ok', '是') : tag('bad', '否'));

            var tdType = el('td', 'mono');
            tdType.appendChild(document.createTextNode(String(c.type)));
            if (c.exists && c.type !== c.expect) {
                tdType.appendChild(document.createTextNode(' '));
                tdType.appendChild(tag('bad', '≠ ' + c.expect));
            }
            tr.appendChild(tdType);

            var ttl = Number(c.ttl);
            tr.appendChild(el('td', 'num', ttl === -1 ? '永久' : (ttl === -2 ? '—' : String(ttl))));
            tr.appendChild(el('td', 'mono', c.expect));
            tr.appendChild(el('td', null, c.hint));

            if (!ok) { tr.style.opacity = '0.7'; }
            body.appendChild(tr);
        });

        if (note && !selfCheck.ok) {
            var div = el('div', 'note warn');
            div.appendChild(el('strong', null, '键自检未通过：'));
            div.appendChild(document.createTextNode(selfCheck.hint || ''));
            note.appendChild(div);
        }
    }

    function renderApi(api) {
        setText('api-url', (api.url || '') + '/stats · 密钥 ' + (api.has_secret ? '已配置' : '回落 AUTH_SECRET'));
        markPill('pill-api', !!api.ok);

        var note = $('api-note');
        clear(note);

        if (!api.ok) {
            var div = el('div', 'note bad');
            div.appendChild(el('strong', null, '主项目 API 不可达：'));
            div.appendChild(document.createTextNode(
                'HTTP ' + num(api.status) + '，业务码 ' + num(api.code) + '：' + (api.msg || '')
            ));
            if (!api.has_secret) {
                div.appendChild(el('br'));
                div.appendChild(document.createTextNode(
                    '未配置 ADMIN_API_SECRET，由服务端回退 AUTH_SECRET。'
                ));
            }
            note.appendChild(div);
        } else if (!api.stats || !Object.keys(api.stats).length) {
            note.appendChild(el('div', 'empty', '/stats 无数据（可能返回非 JSON，或该实例未开放统计）。'));
        }

        renderKeyValueTable('tb-apistats', api.stats || {}, '无数据。');
    }

    // ------------------------------------------------------------------
    // 主渲染入口（快 / 慢 tick 共用）
    // ------------------------------------------------------------------

    function renderRedisAndDerived(ts, redis, derived) {
        markPill('pill-redis', !!redis.ok);
        setText('meta-ts', '采集于 ' + fmtClock(ts));
        setText('meta-report', num(redis.report_at) > 0 ? fmtClock(redis.report_at) : '—');

        renderCards(redis, derived);
        renderRatios(derived.ratios);
        renderQueues(redis.queues || {}, cfg.queue_warn_depth);
        renderProcesses(derived.processes);
        renderKeyValueTable('tb-gauge', redis.gauge,
            '无数据 —— 主项目 MONITOR_ENABLE=false 时采集整条短路。');
        renderKeyValueTable('tb-counter', redis.counter,
            '无数据 —— 今日尚无指标写入，或 DB / PREFIX 配错。');
        renderAlerts(derived.alerts);
    }

    function renderCharts() {
        var online = series('online');
        var msg = series('msg');
        var push = series('push');

        sparkline('chart-online', online);
        sparkline('chart-msg', msg);
        sparkline('chart-push', push);

        setText('t-online', online.length ? fmtInt(online[online.length - 1]) : '—');
        setText('t-msg', msg.length ? fmtRate(msg[msg.length - 1]) : '—');
        setText('t-push', push.length ? fmtRate(push[push.length - 1]) : '—');
    }

    // ------------------------------------------------------------------
    // 取数
    // ------------------------------------------------------------------

    function backoff(baseMs, fails) {
        if (fails <= 0) { return baseMs; }
        return Math.min(baseMs * Math.pow(2, fails), MAX_BACKOFF_MS);
    }

    /**
     * 拉一个 JSON 端点。
     *
     * 返回值统一为 `{stale: bool, data?: object}`：
     * - `stale: true` → 该响应属于一个已被取代的循环，调用方必须**直接返回且不续排**
     *   （续排交由取代它的新循环负责），否则会出现两个定时器并存。
     * - 陈旧时**不抛异常**，因为它是正常竞态而非错误。
     *
     * @returns {Promise<{stale: boolean, data?: object}>}
     */
    function fetchJson(url, seqKey) {
        state[seqKey] += 1;
        var seq = state[seqKey];

        return fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (res) {
            // 401/403 必须显式转成可读文案：AdminAuth 对 JSON 请求回真实状态码，
            // 但 fetch 不会把非 2xx 当异常，不拦就会走到 res.json() 解析错误页而报
            // 「Unexpected token <」这类无从下手的错。
            if (res.status === 401) {
                throw new Error('登录已失效（HTTP 401），请刷新页面重新登录');
            }
            if (res.status === 403) {
                throw new Error('无权限（HTTP 403）：当前账号缺少该 API 的权限点，请联系管理员');
            }
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            return res.json();
        }).then(function (body) {
            if (seq !== state[seqKey]) { return { stale: true }; }
            if (!body || Number(body.code) !== 0) {
                throw new Error((body && body.msg) ? String(body.msg) : '业务码非 0');
            }
            return { stale: false, data: body.data };
        }).catch(function (err) {
            if (seq !== state[seqKey]) { return { stale: true }; }
            throw err;
        });
    }

    // ---- 快 tick ----

    function scheduleLive(delayMs) {
        state.liveTimer = window.setTimeout(runLive, delayMs);
    }

    function runLive() {
        if (document.hidden) { return; }

        fetchJson(cfg.live_url, 'liveSeq').then(function (res) {
            if (res.stale) { return; }

            state.liveFails = 0;
            state.transportAlert = null;

            var ts = num(res.data.ts);
            var redis = res.data.redis || {};
            var derived = res.data.derived || {};

            renderRedisAndDerived(ts, redis, derived);

            var counter = redis.counter || {};
            var rates = trackRates(counter, ts);

            if (rates.rollover) {
                // 窗口失效：清历史，并以本次作为新窗口的起点
                state.history = [];
                state.rolloverAt = Date.now();
                state.prevCounter = counter;
                state.prevTs = ts;
                renderAlerts(derived.alerts);
            } else if (rates.msg !== null) {
                pushHistory({ online: num(redis.online), msg: rates.msg, push: rates.push });
            } else {
                // 首个采样点：只记录在线数，速率待下一个点（故置 NaN，被 series() 过滤掉）
                pushHistory({ online: num(redis.online), msg: NaN, push: NaN });
            }

            renderCharts();
            scheduleLive(cfg.live_interval_ms);
        }).catch(function (err) {
            state.liveFails += 1;
            state.transportAlert = {
                level: 'bad',
                title: '快 tick 取数失败',
                detail: String(err && err.message ? err.message : err)
                    + '（第 ' + state.liveFails + ' 次连续失败，下次重试间隔 '
                    + Math.round(backoff(cfg.live_interval_ms, state.liveFails) / 1000) + 's）'
            };
            renderAlerts([]);
            scheduleLive(backoff(cfg.live_interval_ms, state.liveFails));
        });
    }

    // ---- 慢 tick ----

    function scheduleSlow(delayMs) {
        state.slowTimer = window.setTimeout(runSlow, delayMs);
    }

    function runSlow() {
        if (document.hidden) { return; }

        fetchJson(cfg.summary_url, 'slowSeq').then(function (res) {
            if (res.stale) { return; }

            state.slowFails = 0;

            var redis = res.data.redis || {};
            renderRedisAndDerived(num(res.data.ts), redis, res.data.derived || {});
            renderSelfCheck(redis.self_check);
            setValue('c-dbsize', fmtInt(redis.db_size), 'DB ' + num(redis.db) + ' · ' + (redis.prefix || ''));
            renderApi(res.data.api || {});

            scheduleSlow(cfg.slow_interval_ms);
        }).catch(function (err) {
            state.slowFails += 1;
            markPill('pill-api', false);

            var note = $('api-note');
            clear(note);
            note.appendChild(el('div', 'note bad',
                '慢 tick 失败（第 ' + state.slowFails + ' 次连续失败）：'
                + String(err && err.message ? err.message : err)));

            scheduleSlow(backoff(cfg.slow_interval_ms, state.slowFails));
        });
    }

    // ------------------------------------------------------------------
    // 可见性：隐藏即停（并作废在途请求），可见即重拉
    // ------------------------------------------------------------------

    function stopAll() {
        window.clearTimeout(state.liveTimer);
        window.clearTimeout(state.slowTimer);
        state.liveTimer = null;
        state.slowTimer = null;
        // 作废在途请求：旧循环的响应回来时序号已变 → 判定陈旧 → 不渲染、不续排
        state.liveSeq += 1;
        state.slowSeq += 1;
    }

    function startAll() {
        stopAll();
        runLive();
        runSlow();
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopAll();
        } else {
            startAll();
        }
    });

    // 首屏：先把静态配置显示出来，避免「—」久留造成「卡住了」的错觉
    setText('meta-live', Math.round(cfg.live_interval_ms / 1000));
    setText('meta-slow', Math.round(cfg.slow_interval_ms / 1000));
    setText('hint-points', cfg.history_points);
    setText('q-threshold', fmtInt(cfg.queue_warn_depth));
    setText('p-stale', cfg.gauge_stale_secs);

    startAll();
}());

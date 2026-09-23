/**
 * GatewayPush 后台 · 会话查询页前端
 *
 * 由 webman 静态中间件直接提供（/static/session.js），无权限校验。
 * ⚠ 不得写入任何密钥或内网凭据。
 *
 * ## 与 dashboard.js 的关键差别：**本页没有任何定时器**
 *
 * 健康总览看的是「此刻的状态」，不看就没意义，故设计了快 / 慢两条轮询；
 * 会话列表是**检索型**视图 —— 用户填条件 / 翻页 / 点开某一条才去看。
 * 静止时反复拉全量列表既无收益，又会把 Redis 读放大到与实际使用无关的频次。
 *
 * 因此本页的请求全部由**交互触发**：打开时一次（列表），其余来自
 * 查询 / 翻页 / 反查 / 抽屉 / 撤销名单。本脚本内**不存在任何定时器调用** ——
 * 这条由 `tests/Unit/SessionContractTest.php` 静态断言守住（新增定时器会让门禁变红）。
 *
 * ## 四条硬不变量（对应设计文档 P2 验收）
 *
 * 1. **只读**：本脚本只发 GET，从不构造写请求；
 * 2. **无 N+1**：列表一次请求取一页（后端按 `size` 做一批 HGETALL），
 *    前端不得为了补字段而逐行再发请求；
 * 3. **SCAN 有界且必须显式回显**：`scope=retained|all` 的响应带 `scan.{scanned,truncated}`，
 *    `truncated=true` 时必须**明确警示** —— 静默截断会让运维把「扫到的一半」当全量；
 * 4. **空态 ≠ 配错**：`total=0` 时用后端给的 `skeleton_ok` / `hint` 区分
 *    「确实没人连」与「DB / PREFIX 配错」，两者必须给出不同的提示。
 *
 * ## 状态归约：`online` 只看集合成员资格
 *
 * `state=online` 由后端按 `online:clients` 成员资格判定（`stateOf()`），
 * 前端**不得**改用 `offline_at` 是否为空来判断 —— 那是粘性字段（主项目
 * `Session::bind()` 不清理、`markOffline()` 只补写），重连后的 UDP 连接会长期带着它。
 * 本页把 `offline_at` 如实展示为「最近一次断开时间」，仅此而已。
 *
 * ## 「已撤销」为什么不在本页
 *
 * 撤销名单的键是 `auth:revoked:{sha256(token) 前 32 位}`，服务端不存 Token 原文，
 * 会话里也没有指纹字段 ⇒ 无法由 clientId / uid 反推。故本页**不为会话显示撤销标志**
 * （宁缺勿假），只在「Token 撤销名单」区块列出指纹本身供人工核对。
 *
 * ## XSS 纪律
 *
 * 服务端返回的**任何字符串**（uid / device_id / clientId / 离线消息体 / hint）
 * 都必须经 `textContent` / `createTextNode` 落地，一律不得拼进 `innerHTML`。
 */
(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('session-config').textContent);

    var state = {
        scope: firstOf(cfg.scopes, 'online'),
        protocol: '',
        uid: '',
        size: num(cfg.page_size) || 20,
        page: 1,
        pages: 0,
        total: 0,
        clientId: '',
        // 抽屉里当前会话所属 uid（离线队列按 uid 取数），由详情响应回填
        drawerUid: '',
        offlinePage: 1,
        offlinePages: 0,
        // 请求序号：快速切换条件 / 抽屉时作废旧响应，避免「后发先到」把新结果覆盖掉
        listSeq: 0,
        lookupSeq: 0,
        detailSeq: 0,
        offlineSeq: 0,
        subsSeq: 0,
        revokeSeq: 0
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

    function clear(node) { while (node && node.firstChild) { node.removeChild(node.firstChild); } }

    function setText(id, text) {
        var node = $(id);
        if (node) { node.textContent = String(text); }
    }

    /** 往 tbody 里放一行「空态」提示 */
    function setRowEmpty(bodyId, message) {
        var body = $(bodyId);
        if (!body) { return; }
        clear(body);
        var td = el('td', 'empty', message);
        td.colSpan = 99;
        var tr = el('tr');
        tr.appendChild(td);
        body.appendChild(tr);
    }

    /** 把容器内容整体替换为一条提示条 */
    function setNote(containerId, kind, text) {
        var box = $(containerId);
        if (!box) { return; }
        clear(box);
        box.appendChild(el('div', 'note ' + kind, text));
    }

    function setChips(containerId, values, emptyText) {
        var box = $(containerId);
        if (!box) { return; }
        clear(box);
        if (!values || values.length === 0) {
            box.appendChild(el('span', 'hint', emptyText));
            return;
        }
        values.forEach(function (v) { box.appendChild(el('span', 'chip mono', v)); });
    }

    function tag(level, text) { return el('span', 'tag ' + (level || 'mute'), text); }

    function msgOf(err) { return String(err && err.message ? err.message : err); }

    // ------------------------------------------------------------------
    // 格式化
    // ------------------------------------------------------------------

    function num(value) {
        var n = Number(value);
        return Number.isFinite(n) ? n : 0;
    }

    function fmtInt(value) { return Math.round(num(value)).toLocaleString('zh-CN'); }

    /** 秒数：null / undefined 显示 —（「没有这个时间」与「0 秒前」是两件事） */
    function fmtAge(secs) {
        if (secs === null || secs === undefined) { return '—'; }
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

    /** 会话状态 → 展示标签（后端 `stateOf()` 的取值域） */
    function stateTag(value) {
        if (value === 'online') { return tag('ok', '在线'); }
        if (value === 'retained') { return tag('warn', '已断开·保留'); }
        return tag('mute', '已回收');
    }

    function scopeLabel(value) {
        if (value === 'retained') { return '仅保留'; }
        if (value === 'all') { return '在线 + 保留'; }
        return '仅在线';
    }

    /**
     * 范围展示文案。
     *
     * ⚠ 反查端点（`byIndex()`）把 `scope` 复用成了标识串（`uid:1001` / `device:xxx`），
     * 直接过 `scopeLabel()` 会显示成「仅在线」，与事实不符 —— 故只对三个合法范围值做翻译，
     * 其余原样展示。
     */
    function scopeDisplay(value) {
        if (value === 'online' || value === 'retained' || value === 'all') { return scopeLabel(value); }
        return value ? String(value) : '—';
    }

    function fmtAddr(row) {
        var ip = row.client_ip || '';
        var port = row.client_port || '';
        if (ip === '' && port === '') { return '—'; }
        return ip + (port === '' ? '' : ':' + port);
    }

    function firstOf(list, fallback) {
        return (list && list.length) ? list[0] : fallback;
    }

    // ------------------------------------------------------------------
    // 请求
    // ------------------------------------------------------------------

    /**
     * 取数并解开 `{code,msg,data}` 信封。
     *
     * 三件必须分开处理的事：
     *   - **非 JSON 响应**（反代拦截 / 登录页 HTML）→ 不能直接 `.json()` 抛原生解析错，
     *     否则界面上只会写「Unexpected token <」，看不出是登录态失效；
     *   - **HTTP 非 2xx**（`ApiReply::fail()` 会同时给出 HTTP 状态与业务码）→
     *     错误对象上带 `status` / `code` / `data`，抽屉靠 `data.found===false` 区分
     *     「会话已回收」与「真的取数失败」；
     *   - **业务码非 0**：正常来自 HTTP 2xx 体（本项目的 ok 恒为 code 0，属兜底）。
     */
    function fetchJson(url) {
        return fetch(url, {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().then(
                function (body) { return { status: res.status, ok: res.ok, body: body }; },
                function () { return { status: res.status, ok: res.ok, body: null }; }
            );
        }).then(function (r) {
            if (r.body === null) {
                throw new Error('HTTP ' + r.status + '：响应不是 JSON（可能被反代或登录页拦截）');
            }
            if (!r.ok) {
                var httpErr = new Error('HTTP ' + r.status + '，业务码 ' + r.body.code + '：' + (r.body.msg || ''));
                httpErr.status = r.status;
                httpErr.code = r.body.code;
                httpErr.data = r.body.data;
                throw httpErr;
            }
            if (num(r.body.code) !== 0) {
                throw new Error('业务码 ' + r.body.code + '：' + (r.body.msg || ''));
            }
            return r.body.data;
        });
    }

    // ------------------------------------------------------------------
    // 列表
    // ------------------------------------------------------------------

    function listUrl() {
        var q = ['scope=' + encodeURIComponent(state.scope)];
        if (state.protocol) { q.push('protocol=' + encodeURIComponent(state.protocol)); }
        if (state.uid) { q.push('uid=' + encodeURIComponent(state.uid)); }
        q.push('page=' + state.page);
        q.push('size=' + state.size);
        return cfg.list_url + '?' + q.join('&');
    }

    function loadList() {
        var seq = ++state.listSeq;
        setNote('list-status', 'info', '正在取数…');
        return fetchJson(listUrl()).then(function (data) {
            if (seq !== state.listSeq) { return; }
            renderList(data || {});
        }).catch(function (err) {
            if (seq !== state.listSeq) { return; }
            setNote('list-status', 'bad', '列表取数失败：' + msgOf(err));
            setRowEmpty('tb-sessions', '列表不可用 —— 详见上方提示。');
            setText('pager-info', '—');
        });
    }

    function renderList(data) {
        state.total = num(data.total);
        state.pages = num(data.pages);
        state.page = num(data.page) || 1;
        // 服务端会夹取 size（上限 SIZE_MAX），回填以免下拉与真实请求不一致
        state.size = num(data.size) || state.size;
        syncFormFromState();

        renderCollection('list-status', 'tb-sessions', data);
        renderPager();
        // 页码 / 每页数可能被服务端夹取，且翻页后应可分享 —— 状态落回 URL
        syncUrl();
    }

    function renderPager() {
        var prev = $('btn-prev');
        var next = $('btn-next');
        if (prev) { prev.disabled = state.page <= 1; }
        if (next) { next.disabled = state.pages <= 1 || state.page >= state.pages; }
        setText('pager-info', state.total === 0
            ? '无记录'
            : '第 ' + state.page + ' / ' + Math.max(1, state.pages) + ' 页 · 共 '
                + fmtInt(state.total) + ' 条 · 每页 ' + state.size);
    }

    /**
     * 集合类响应的公共渲染（列表与两个反查端点共用）。
     *
     * ★ 截断回显与空态区分都在这里，**不允许任何一条被绕过** ——
     * 反查端点也走同一函数，故三处口径必然一致。
     */
    function renderCollection(statusId, tbodyId, data) {
        var items = data.items || [];
        var box = $(statusId);
        if (box) {
            clear(box);
            var line = el('div', 'status-line');
            line.appendChild(el('span', 'chip', '范围 ' + scopeDisplay(data.scope)));
            line.appendChild(el('span', 'chip', '匹配 ' + fmtInt(data.total)));
            line.appendChild(el('span', 'chip', '在线 ' + fmtInt(data.online_total)));
            line.appendChild(el('span', 'chip', '本页 ' + fmtInt(items.length)));
            box.appendChild(line);

            if (data.scan) {
                var scanLine = el('div', 'status-line');
                scanLine.appendChild(el('span', 'chip',
                    'SCAN 已扫描 ' + fmtInt(data.scan.scanned) + ' 键 · 命中 '
                    + fmtInt(data.scan.keys) + ' 个 session 键'));
                box.appendChild(scanLine);

                // ★ 截断必须显式回显：静默截断会让运维把「扫到的一半」当成全量
                if (data.scan.truncated) {
                    box.appendChild(el('div', 'note warn',
                        '结果已截断：SCAN 触达上限后停止，本次列表不完整。'
                        + '会话总数可能大于「匹配」值 —— 请缩小范围（按 uid / 协议）或调大'
                        + ' session.scan_max_keys 后重试。'));
                }
            }

            if (items.length === 0) {
                // ★ 空态 ≠ 配错：skeleton_ok=false 说明是键空间/连接配置问题，不是「没人连」
                if (data.skeleton_ok === false) {
                    box.appendChild(el('div', 'note bad',
                        '列表为空，且骨架自检未通过 —— 这不是「没有会话」，请先核对 DB / PREFIX。'
                        + (data.hint ? ' 自检提示：' + data.hint : '')));
                } else {
                    box.appendChild(el('div', 'note info',
                        '当前范围内没有会话。'
                        + (data.hint ? ' ' + data.hint : '')
                        + (data.scope === 'online'
                            ? ' 若确认应有客户端在线，请把范围切到「在线 + 保留」核对：'
                                + '保留会话不在 online:clients 内，需要 SCAN 才看得到。'
                            : '')));
                }
            }
        }

        renderRows(tbodyId, items);
    }

    function renderRows(tbodyId, items) {
        var body = $(tbodyId);
        if (!body) { return; }
        clear(body);

        if (!items || items.length === 0) {
            setRowEmpty(tbodyId, '无记录。');
            return;
        }

        items.forEach(function (row) {
            body.appendChild(buildRow(row));
        });
    }

    function buildRow(row) {
        var tr = el('tr');

        var tdState = el('td');
        tdState.appendChild(stateTag(row.state));
        tr.appendChild(tdState);

        tr.appendChild(el('td', 'mono', row.client_id || '—'));
        tr.appendChild(el('td', null, row.uid || '—'));
        tr.appendChild(el('td', null, row.device_id || '—'));

        var tdProtocol = el('td');
        if (row.protocol) { tdProtocol.appendChild(el('span', 'chip', row.protocol)); } else { tdProtocol.textContent = '—'; }
        tr.appendChild(tdProtocol);

        tr.appendChild(el('td', 'mono', fmtAddr(row)));
        tr.appendChild(el('td', 'mono', row.gateway || '—'));
        tr.appendChild(el('td', 'num', fmtAge(row.connect_secs)));
        tr.appendChild(el('td', 'num', fmtAge(row.idle_secs)));
        tr.appendChild(el('td', 'num', fmtAge(row.offline_secs)));

        var tdAction = el('td');
        if (row.client_id) {
            var button = el('button', 'btn mini', '详情');
            button.type = 'button';
            button.addEventListener('click', function () { openDrawer(row.client_id); });
            tdAction.appendChild(button);
        } else {
            tdAction.textContent = '—';
        }
        tr.appendChild(tdAction);

        return tr;
    }

    // ------------------------------------------------------------------
    // 抽屉详情
    // ------------------------------------------------------------------

    /** 会话 Hash 的展示顺序：固定下来，避免依赖 Redis 返回的字段顺序（不稳定） */
    var SESSION_FIELDS = [
        'client_id', 'uid', 'device_id', 'protocol',
        'client_ip', 'client_port', 'gateway',
        'connect_at', 'last_active', 'offline_at'
    ];

    function openDrawer(clientId) {
        if (!clientId) { return Promise.resolve(); }
        state.clientId = clientId;
        state.offlinePage = 1;
        var drawer = $('drawer');
        if (drawer) { drawer.className = 'drawer open'; }
        setText('drawer-title', clientId);
        syncUrl();
        return loadDetail();
    }

    function closeDrawer() {
        state.clientId = '';
        state.drawerUid = '';
        var drawer = $('drawer');
        if (drawer) { drawer.className = 'drawer'; }
        syncUrl();
    }

    function loadDetail() {
        var clientId = state.clientId;
        if (!clientId) { return Promise.resolve(); }
        var seq = ++state.detailSeq;
        setNote('drawer-status', 'info', '正在取数…');

        // `offline_size` 必须显式带上：详情响应里的 `offline_queue` 首页由服务端的
        // `pagingFor()` 兜默认值（取自 `session.page_size`），而抽屉里翻页走的是
        // `session.offline_page_size`。两者若不显式对齐，会出现「第 1 页 50 条、第 2 页 20 条」
        // 这种页码错位。
        var url = cfg.detail_base + encodeURIComponent(clientId)
            + '?offline_size=' + num(cfg.offline_page_size);

        return fetchJson(url).then(function (data) {
            if (seq !== state.detailSeq) { return; }
            renderDetail(data || {});
        }).catch(function (err) {
            if (seq !== state.detailSeq) { return; }
            // ★ 区分「已回收」与「取数失败」：前者是正常语义（HTTP 404 + code 4004），
            //   后端在 data 里回了完整的 found=false 结构，照常渲染而不是报错。
            if (err.status === 404 && err.data && err.data.found === false) {
                renderDetail(err.data);
                var box = $('drawer-status');
                if (box) {
                    box.appendChild(el('div', 'note warn',
                        '该 clientId 的会话键已不存在：连接断开后已被回收（unbind），或从未建连。'
                        + '这不是取数故障 —— 列表里的「已断开·保留」才表示键仍在。'));
                }
                return;
            }
            setNote('drawer-status', 'bad', '详情取数失败：' + msgOf(err));
        });
    }

    function renderDetail(data) {
        var box = $('drawer-status');
        if (box) {
            clear(box);
            box.appendChild(buildDetailStatus(data));
        }

        renderDetailFields(data);

        var session = data.session || {};
        state.drawerUid = session.uid || '';
        var subs = data.subscriptions || [];
        setText('drawer-subs-hint', subs.length === 0
            ? '无订阅（subscribe:uid:{uid} 为空）'
            : '共 ' + fmtInt(subs.length) + ' 个主题');
        setChips('drawer-subs', subs, state.drawerUid === '' ? '该会话没有 uid，无法取订阅' : '无订阅');

        if (state.drawerUid === '') {
            setNote('offline-status', 'info', '该会话没有 uid，无法定位离线队列（push:offline:{uid}）。');
            setRowEmpty('tb-offline', '不可用 —— 缺少 uid。');
            setText('offline-page-info', '—');
            setOfflineButtons(0, 0);
        } else {
            // ★ 详情响应**已带离线队列首页**（服务端在同一次请求里取好），此处直接渲染。
            //   早期版本在这里又发了一次 `/api/sessions/offline/{uid}` —— 同一份数据取两遍，
            //   属无意义往返（也是「无 N+1 不变量」要防的形态）。只有点翻页才需要再请求。
            renderOffline(data.offline_queue || { len: 0, items: [], page: 1, size: num(cfg.offline_page_size), pages: 0 });
        }
    }

    /** 详情状态块（状态标签 + 各项时长 + 由后端下发的状态说明） */
    function buildDetailStatus(data) {
        var wrap = el('div', 'status-wrap');

        var line = el('div', 'status-line');
        var tagNode = el('span', 'chip');
        tagNode.appendChild(stateTag(data.state));
        line.appendChild(tagNode);
        line.appendChild(el('span', 'chip', data.found ? '会话键存在' : '会话键不存在'));
        line.appendChild(el('span', 'chip', '心跳 ' + fmtAge(data.heartbeat_age_secs)));
        line.appendChild(el('span', 'chip', '连接时长 ' + fmtAge(data.connect_secs)));
        line.appendChild(el('span', 'chip', '空闲 ' + fmtAge(data.idle_secs)));
        line.appendChild(el('span', 'chip', '最后断开 ' + fmtAge(data.offline_secs)));
        wrap.appendChild(line);

        // 状态说明由后端下发（STATE_NOTES），前端不再各自编词
        if (data.note) { wrap.appendChild(el('div', 'hint', data.note)); }

        return wrap;
    }

    function renderDetailFields(data) {
        var body = $('tb-drawer-fields');
        if (!body) { return; }
        clear(body);

        var session = data.session || {};
        var auth = data.auth || {};

        var rows = [];
        SESSION_FIELDS.forEach(function (field) {
            var value = session[field];
            rows.push([field, (value === undefined || value === '') ? '—' : String(value)]);
        });
        rows.push(['heartbeat（独立键 heartbeat:{clientId}）', data.heartbeat === null || data.heartbeat === undefined ? '—' : fmtClock(data.heartbeat)]);
        rows.push(['connect_secs（派生）', fmtAge(data.connect_secs)]);
        rows.push(['idle_secs（派生）', fmtAge(data.idle_secs)]);
        rows.push(['offline_secs（派生）', fmtAge(data.offline_secs)]);
        rows.push(['auth:bind:{uid}（首绑胜出）', auth.bind === undefined || auth.bind === null || auth.bind === '' ? '—' : String(auth.bind)]);
        rows.push(['绑定设备与会话 device_id 一致', auth.device_bound ? '是' : '否']);

        rows.forEach(function (pair) {
            var tr = el('tr');
            tr.appendChild(el('td', null, pair[0]));
            tr.appendChild(el('td', 'mono', pair[1]));
            body.appendChild(tr);
        });

        if (auth.note) {
            var noteRow = el('tr');
            var noteCell = el('td', 'empty', auth.note);
            noteCell.colSpan = 2;
            noteRow.appendChild(noteCell);
            body.appendChild(noteRow);
        }
    }

    // ---- 抽屉内的离线队列分页 ----

    function loadOffline(page) {
        var uid = state.drawerUid;
        if (!uid) { return Promise.resolve(); }
        var seq = ++state.offlineSeq;
        setNote('offline-status', 'info', '正在取数…');

        var url = cfg.offline_base + encodeURIComponent(uid)
            + '?page=' + page + '&size=' + num(cfg.offline_page_size);

        return fetchJson(url).then(function (data) {
            if (seq !== state.offlineSeq) { return; }
            renderOffline(data || {});
        }).catch(function (err) {
            if (seq !== state.offlineSeq) { return; }
            setNote('offline-status', 'bad', '离线队列取数失败：' + msgOf(err));
            setRowEmpty('tb-offline', '不可用 —— 详见上方提示。');
            setText('offline-page-info', '—');
            setOfflineButtons(0, 0);
        });
    }

    function renderOffline(data) {
        var len = num(data.len);
        state.offlinePage = num(data.page) || 1;
        state.offlinePages = num(data.pages);

        setNote('offline-status', 'info', len === 0
            ? '离线队列为空 —— 该 uid 当前没有待投递消息。'
            : '共 ' + fmtInt(len) + ' 条待投递消息（index 0 为最早一条，重连后按序投递）。');

        var body = $('tb-offline');
        var items = data.items || [];
        if (body) {
            clear(body);
            if (items.length === 0) {
                setRowEmpty('tb-offline', len === 0 ? '无待投递消息。' : '本页无内容（页码越界？）。');
            } else {
                var base = (state.offlinePage - 1) * num(data.size);
                items.forEach(function (message, i) {
                    var tr = el('tr');
                    // 序号用**队列全局下标**，不是页内下标 —— 否则翻页后会出现两行 #0
                    tr.appendChild(el('td', 'num', base + i));
                    tr.appendChild(el('td', 'msg', String(message)));
                    body.appendChild(tr);
                });
            }
        }

        setText('offline-page-info', len === 0
            ? '无待投递消息'
            : '第 ' + state.offlinePage + ' / ' + Math.max(1, state.offlinePages) + ' 页 · 共 ' + fmtInt(len) + ' 条');
        setOfflineButtons(state.offlinePage, state.offlinePages);
    }

    function setOfflineButtons(page, pages) {
        var prev = $('btn-offline-prev');
        var next = $('btn-offline-next');
        if (prev) { prev.disabled = page <= 1; }
        if (next) { next.disabled = pages <= 1 || page >= pages; }
    }

    // ------------------------------------------------------------------
    // 反查 / 订阅 / 撤销名单
    // ------------------------------------------------------------------

    function lookup(base, value, label) {
        if (!validId(value)) {
            setNote('lookup-status', 'bad', label + ' 不能为空、不得超过 '
                + cfg.id_max_len + ' 字符或含控制字符。');
            setRowEmpty('tb-lookup', '未发起查询。');
            return Promise.resolve();
        }
        var seq = ++state.lookupSeq;
        setNote('lookup-status', 'info', '正在取数…');

        return fetchJson(base + encodeURIComponent(value)).then(function (data) {
            if (seq !== state.lookupSeq) { return; }
            renderCollection('lookup-status', 'tb-lookup', data || {});
        }).catch(function (err) {
            if (seq !== state.lookupSeq) { return; }
            setNote('lookup-status', 'bad', '反查失败：' + msgOf(err));
            setRowEmpty('tb-lookup', '不可用 —— 详见上方提示。');
        });
    }

    function loadSubscriptions() {
        var uid = $('s-uid') ? $('s-uid').value.trim() : '';
        var topic = $('s-topic') ? $('s-topic').value.trim() : '';
        if (uid === '' && topic === '') {
            setNote('subs-status', 'bad', 'uid 与 topic 至少填一个。');
            setRowEmpty('tb-subs', '未发起查询。');
            return Promise.resolve();
        }
        if ((uid !== '' && !validId(uid)) || (topic !== '' && !validId(topic))) {
            setNote('subs-status', 'bad', 'uid / topic 不得超过 ' + cfg.id_max_len + ' 字符或含控制字符。');
            setRowEmpty('tb-subs', '未发起查询。');
            return Promise.resolve();
        }

        var seq = ++state.subsSeq;
        setNote('subs-status', 'info', '正在取数…');

        var url = cfg.subs_url + '?uid=' + encodeURIComponent(uid) + '&topic=' + encodeURIComponent(topic);
        return fetchJson(url).then(function (data) {
            if (seq !== state.subsSeq) { return; }
            renderSubscriptions(data || {}, uid, topic);
        }).catch(function (err) {
            if (seq !== state.subsSeq) { return; }
            setNote('subs-status', 'bad', '订阅查询失败：' + msgOf(err));
            setRowEmpty('tb-subs', '不可用 —— 详见上方提示。');
        });
    }

    function renderSubscriptions(data, uid, topic) {
        var topics = data.topics || [];
        var subscribers = data.subscribers || [];

        var box = $('subs-status');
        if (box) {
            clear(box);
            var line = el('div', 'status-line');
            if (uid !== '') { line.appendChild(el('span', 'chip', 'uid ' + uid + ' 订阅 ' + fmtInt(topics.length) + ' 个主题')); }
            if (topic !== '') { line.appendChild(el('span', 'chip', 'topic ' + topic + ' 有 ' + fmtInt(subscribers.length) + ' 个订阅者')); }
            box.appendChild(line);
        }

        var body = $('tb-subs');
        if (!body) { return; }
        clear(body);

        var rows = [];
        if (uid !== '') {
            rows.push(['uid → topics', uid, topics.length, topics.length ? topics.join(', ') : '—']);
        }
        if (topic !== '') {
            rows.push(['topic → subscribers', topic, subscribers.length, subscribers.length ? subscribers.join(', ') : '—']);
        }
        if (rows.length === 0) {
            setRowEmpty('tb-subs', '未发起查询。');
            return;
        }
        rows.forEach(function (r) {
            var tr = el('tr');
            tr.appendChild(el('td', null, r[0]));
            tr.appendChild(el('td', 'mono', r[1]));
            tr.appendChild(el('td', 'num', fmtInt(r[2])));
            tr.appendChild(el('td', 'mono', r[3]));
            body.appendChild(tr);
        });
    }

    function loadRevoked() {
        var seq = ++state.revokeSeq;
        setNote('revoke-status', 'info', '正在取数…');

        return fetchJson(cfg.revoked_url).then(function (data) {
            if (seq !== state.revokeSeq) { return; }
            renderRevoked(data || {});
        }).catch(function (err) {
            if (seq !== state.revokeSeq) { return; }
            setNote('revoke-status', 'bad', '撤销名单取数失败：' + msgOf(err));
            setRowEmpty('tb-revoked', '不可用 —— 详见上方提示。');
        });
    }

    function renderRevoked(data) {
        var items = data.items || [];
        var box = $('revoke-status');
        if (box) {
            clear(box);
            var line = el('div', 'status-line');
            line.appendChild(el('span', 'chip', '名单条目 ' + fmtInt(items.length)));
            line.appendChild(el('span', 'chip', 'SCAN 已扫描 ' + fmtInt(data.scanned) + ' 键'));
            box.appendChild(line);
            if (data.truncated) {
                box.appendChild(el('div', 'note warn',
                    '结果已截断：SCAN 触达上限后停止，名单不完整 —— 未列出的指纹不代表未被撤销。'));
            }
            // 说明文案由后端下发（REVOKE_NOTE），避免前端各自编词
            if (cfg.revoke_note) { box.appendChild(el('div', 'hint', cfg.revoke_note)); }
        }

        var body = $('tb-revoked');
        if (!body) { return; }
        clear(body);

        if (items.length === 0) {
            setRowEmpty('tb-revoked', '名单为空 —— 当前没有被撤销的 Token（或 Redis 不可用）。');
            return;
        }

        items.forEach(function (item) {
            var ttlText;
            if (item.permanent) { ttlText = '永久'; }
            else if (num(item.ttl) > 0) { ttlText = fmtInt(item.ttl) + 's'; }
            else { ttlText = '—'; }

            var tr = el('tr');
            tr.appendChild(el('td', 'mono', item.fingerprint || '—'));
            tr.appendChild(el('td', 'num', ttlText));
            var td = el('td');
            td.appendChild(item.permanent ? tag('bad', '永久撤销') : tag('warn', '限期撤销'));
            tr.appendChild(td);
            body.appendChild(tr);
        });
    }

    // ------------------------------------------------------------------
    // 表单 / URL 状态
    // ------------------------------------------------------------------

    /** 入参校验：与 `SessionInspector::validId()` 同口径（非空、限长、无控制字符） */
    function validId(value) {
        if (typeof value !== 'string' || value === '' || utf8Len(value) > cfg.id_max_len) { return false; }
        return !/[\u0000-\u001f\u007f]/.test(value);
    }

    /**
     * UTF-8 字节长度。
     *
     * ⚠ 必须按**字节**而不是 `String.length` 判长：后端 `SessionInspector::validId()`
     * 用的是 PHP 的 `strlen()`（字节数），一个中文 uid 每个字符占 3 字节 ——
     * 若前端按字符数判，64 个汉字的 uid（192 字节）会被前端放行、后端回 400，
     * 表现为「点了查询却报参数非法」，而前端认为自己校验过了。
     */
    function utf8Len(value) {
        var n = 0;
        for (var i = 0; i < value.length; i++) {
            var code = value.charCodeAt(i);
            if (code < 0x80) { n += 1; }
            else if (code < 0x800) { n += 2; }
            else if (code >= 0xd800 && code <= 0xdbff) { n += 4; i++; }
            else { n += 3; }
        }
        return n;
    }

    function readForm() {
        var scope = $('f-scope') ? $('f-scope').value : '';
        var protocol = $('f-protocol') ? $('f-protocol').value : '';
        var uid = $('f-uid') ? $('f-uid').value.trim() : '';
        var size = $('f-size') ? $('f-size').value : '';

        if (uid !== '' && !validId(uid)) {
            setNote('form-note', 'bad', 'uid 不得超过 ' + cfg.id_max_len + ' 字节（UTF-8）或含控制字符 —— 已保持上一次的查询条件。');
            return false;
        }

        state.scope = (cfg.scopes.indexOf(scope) >= 0) ? scope : state.scope;
        state.protocol = (cfg.protocols.indexOf(protocol) >= 0) ? protocol : '';
        state.uid = uid;
        state.size = chooseSize(size);
        clear($('form-note'));
        return true;
    }

    function chooseSize(value) {
        var n = num(value);
        return (cfg.size_options.indexOf(n) >= 0) ? n : (num(cfg.page_size) || 20);
    }

    function syncFormFromState() {
        if ($('f-scope')) { $('f-scope').value = state.scope; }
        if ($('f-protocol')) { $('f-protocol').value = state.protocol; }
        if ($('f-uid')) { $('f-uid').value = state.uid; }
        if ($('f-size')) { $('f-size').value = String(state.size); }
    }

    function resetForm() {
        state.scope = firstOf(cfg.scopes, 'online');
        state.protocol = '';
        state.uid = '';
        state.size = num(cfg.page_size) || 20;
        state.page = 1;
        syncFormFromState();
        clear($('form-note'));
        closeDrawer();
        return loadList();
    }

    function parseQuery() {
        var out = {};
        var search = (window.location && window.location.search) ? String(window.location.search) : '';
        if (search.charAt(0) === '?') { search = search.slice(1); }
        if (search === '') { return out; }
        search.split('&').forEach(function (pair) {
            if (pair === '') { return; }
            var i = pair.indexOf('=');
            var key = i < 0 ? pair : pair.slice(0, i);
            var value = i < 0 ? '' : pair.slice(i + 1);
            out[decodeURIComponent(key)] = decodeURIComponent(value.replace(/\+/g, ' '));
        });
        return out;
    }

    /**
     * 从 URL 还原视图状态（刷新 / 复制链接 / 后退都能回到同一条会话）。
     *
     * 每个值都**先校验再采纳** —— 手改过的链接不应能把页面带进非法状态
     * （例如 `size=99999` 或超长 uid），否则请求会被后端拒绝、页面看起来「莫名报错」。
     */
    function restoreFromUrl() {
        var q = parseQuery();

        if (typeof q.scope === 'string' && cfg.scopes.indexOf(q.scope) >= 0) { state.scope = q.scope; }
        if (typeof q.protocol === 'string' && cfg.protocols.indexOf(q.protocol) >= 0) { state.protocol = q.protocol; }
        if (typeof q.uid === 'string' && validId(q.uid)) { state.uid = q.uid; }
        var page = num(q.page);
        if (page >= 1) { state.page = Math.floor(page); }
        if (typeof q.size === 'string') { state.size = chooseSize(q.size); }
        if (typeof q.client === 'string' && validId(q.client)) { state.clientId = q.client; }
    }

    function syncUrl() {
        if (!window.history || typeof window.history.replaceState !== 'function') { return; }
        var parts = ['scope=' + encodeURIComponent(state.scope)];
        if (state.protocol) { parts.push('protocol=' + encodeURIComponent(state.protocol)); }
        if (state.uid) { parts.push('uid=' + encodeURIComponent(state.uid)); }
        parts.push('page=' + state.page);
        parts.push('size=' + state.size);
        if (state.clientId) { parts.push('client=' + encodeURIComponent(state.clientId)); }
        window.history.replaceState(null, '', '?' + parts.join('&'));
    }

    // ------------------------------------------------------------------
    // 事件绑定与启动
    // ------------------------------------------------------------------

    function bind(id, handler) {
        var node = $(id);
        if (node) { node.addEventListener('click', handler); }
    }

    bind('btn-query', function () {
        if (!readForm()) { return; }
        state.page = 1;
        loadList();
    });

    bind('btn-reload', function () { loadList(); });
    bind('btn-reset', function () { resetForm(); });

    bind('btn-prev', function () {
        if (state.page <= 1) { return; }
        state.page -= 1;
        loadList();
    });

    bind('btn-next', function () {
        if (state.pages <= 1 || state.page >= state.pages) { return; }
        state.page += 1;
        loadList();
    });

    bind('btn-lookup-uid', function () {
        lookup(cfg.by_uid_base, $('l-uid') ? $('l-uid').value.trim() : '', 'uid');
    });

    bind('btn-lookup-device', function () {
        lookup(cfg.by_device_base, $('l-device') ? $('l-device').value.trim() : '', 'deviceId');
    });

    bind('btn-query-subs', function () { loadSubscriptions(); });
    bind('btn-load-revoked', function () { loadRevoked(); });

    /* ==================================================================
     | P4 运维操作
     |
     | 与上面所有区块的三点不同，改动前务必读完：
     |
     | 1. **这是写操作**。前四个区块全是 GET，这里四个按钮全是 POST，
     |    且它们改变的是**别人**的连接状态 —— 不可撤销。
     | 2. **不概括成败**。HTTP 恒为 200，成败看 `outcome.state`
     |    （`failed` 时 HTTP 仍是 200，只看状态码会把失败当成功）。
     |    回执一律摊开，并原样显示后端下发的 `caveats`。
     | 3. **没做到就说没做到**。「强制下线」没给 Token 时只执行了 kick，
     |    服务端回 `partial=true` —— 这里原样展示，不假装完成了「禁止重连」。
     |
     | ⚠ 零定时器：运维动作是一次性点击，不做任何轮询 / 自动刷新。
     |   与 /actions 调试页（有界退避补查）不同 —— 这里不存在「稍后再看」的场景。
     ================================================================== */

    /** 读输入框（去空白）；节点不存在时返回空串 —— 便于假 DOM 场景逐项断言 */
    function opsVal(id) {
        var node = $(id);
        return node && typeof node.value === 'string' ? node.value.trim() : '';
    }

    /**
     * 提交一个运维动作并渲染回执。
     *
     * ⚠ **HTTP 恒为 200，成败看 `outcome.state`** ——
     *   与 `/push`、`/action` 同口径：动作执行后的业务失败走 `200 + 业务码`，
     *   只看 HTTP 状态码会把「执行失败」当成成功。
     *
     * @param {string} action 仅用于展示与选说明文案
     * @param {string} url    端点（来自 cfg）
     * @param {Object} body   请求体
     */
    function runOps(action, url, body) {
        setNote('ops-status', 'info', '正在执行 ' + action + ' …');
        setRowEmpty('tb-ops-result', '执行中…');

        postJson(url, body).then(function (data) {
            renderOpsResult(action, data || {});
        }).catch(function (err) {
            setNote('ops-status', 'bad', '调用失败：' + msgOf(err));
            setRowEmpty('tb-ops-result', '未取得回执 —— 详见上方提示。');
        });
    }

    /** POST 并解信封（与 `fetchJson` 同口径，仅方法与 body 不同） */
    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        }).then(function (res) {
            return res.json().then(
                function (b) { return { status: res.status, ok: res.ok, body: b }; },
                function () { return { status: res.status, ok: res.ok, body: null }; }
            );
        }).then(function (r) {
            if (r.body === null) {
                throw new Error('HTTP ' + r.status + '：响应不是 JSON（可能被反代或登录页拦截）');
            }
            if (!r.ok) {
                var e = new Error('HTTP ' + r.status + '，业务码 ' + r.body.code + '：' + (r.body.msg || ''));
                e.status = r.status;
                e.code = r.body.code;
                e.data = r.body.data;
                throw e;
            }
            if (num(r.body.code) !== 0) {
                throw new Error('业务码 ' + r.body.code + '：' + (r.body.msg || ''));
            }
            return r.body.data;
        });
    }

    /**
     * 渲染运维动作回执。
     *
     * 三条纪律：
     *   1. **不概括成败** —— 摊开 `state` / `http` / `code` / `msg`；
     *   2. **原样显示后端下发的 `caveats`**（该动作「不做什么」），前端一个字都不编；
     *   3. `partial` 为 true 时**明确说「只做到一半」**。
     */
    function renderOpsResult(action, data) {
        var out = data.outcome || {};
        var done = out.state === 'done';
        var failed = out.state === 'failed';

        setNote(
            'ops-status',
            done ? 'ok' : (failed ? 'bad' : 'warn'),
            done
                ? action + ' 已执行（注意下方「不做什么」）'
                : action + ' 未成功：state=' + (out.state || '—') + ' / HTTP ' + (out.http || '—')
                  + ' / 业务码 ' + (out.code || '—')
        );

        var rows = [
            ['动作', action],
            ['state', out.state || '—'],
            ['HTTP', out.http === undefined || out.http === null ? '—' : String(out.http)],
            ['业务码', out.code === undefined || out.code === null ? '—' : String(out.code)],
            ['msg', out.msg || '—'],
            ['request_id', out.request_id || '—'],
            ['审计', data.audit_ok === false ? '★ 未落库（动作已生效，仅留痕缺失）' : '已落库']
        ];
        if (data.fingerprint) { rows.push(['指纹', data.fingerprint]); }
        if (data.all_ok !== undefined) { rows.push(['两步全成', data.all_ok ? '是' : '否']); }
        if (data.partial) { rows.push(['★ 只做到一半', '是']); }
        // 业务回执里的关键数字（purge_offline 的 result.purged）—— 丢了多少条必须可见，
        // 否则「清空了什么」只能去翻审计。其余动作的 result 字段维持既有摊开展示口径。
        if (out.result && typeof out.result === 'object' && out.result.purged !== undefined) {
            rows.push(['丢弃条数', String(out.result.purged)]);
        }

        var body = $('tb-ops-result');
        if (body) {
            clear(body);
            for (var i = 0; i < rows.length; i++) {
                var tr = el('tr');
                tr.appendChild(el('th', null, rows[i][0]));
                // ⚠ 第二个参数是 className，不是内容 —— 漏掉它会把「值」当成样式名写掉，
                //   页面上就只剩标签没有值（push.js 已踩过一次，同口径修正）。
                tr.appendChild(el('td', null, String(rows[i][1])));
                body.appendChild(tr);
            }
        }

        // 说明区：caveats（该动作不做什么）+ 组合说明 + partial 说明，全部来自 cfg
        var box = $('ops-caveats');
        if (box) {
            clear(box);
            if (data.caveats) {
                box.appendChild(el('div', 'note warn', data.caveats));
            }
            if (action === 'force-offline' && cfg.ops_force_note) {
                box.appendChild(el('div', 'note info', cfg.ops_force_note));
            }
            if (data.partial && cfg.ops_no_token_note) {
                box.appendChild(el('div', 'note bad', cfg.ops_no_token_note));
            }
        }
    }

    /** 渲染区块顶部的固定说明（三条「不做什么」+ 组合顺序） */
    function renderOpsNotes() {
        var box = $('ops-notes');
        if (!box) { return; }
        clear(box);

        var caveats = cfg.ops_caveats || {};
        var order = ['kick', 'revoke', 'unbind'];
        for (var i = 0; i < order.length; i++) {
            if (!caveats[order[i]]) { continue; }
            var wrap = el('div', 'note warn');
            wrap.appendChild(el('b', null, order[i] + '：'));
            wrap.appendChild(el('span', null, ' ' + caveats[order[i]]));
            box.appendChild(wrap);
        }
        if (cfg.ops_force_note) {
            box.appendChild(el('div', 'note info', cfg.ops_force_note));
        }
    }

    /**
     * 权限显隐（**只是渲染期** —— 真正的边界在 AdminAuth + wa_rules）。
     *
     * 五项里**任一**为真就显示操作区（只读角色五项全 false → 显示无权限说明）。
     */
    function applyOpsPerms() {
        var p = cfg.perms || {};
        var any = p.ops_kick === true || p.ops_revoke === true
            || p.ops_unbind === true || p.ops_force === true || p.ops_purge === true;

        var sec = $('sec-ops');
        var ro = $('ops-readonly');
        if (sec) { sec.hidden = !any; }
        if (ro) { ro.hidden = any; }

        setBtnHidden('btn-ops-kick', p.ops_kick !== true);
        setBtnHidden('btn-ops-unbind', p.ops_unbind !== true);
        setBtnHidden('btn-ops-revoke', p.ops_revoke !== true);
        setBtnHidden('btn-ops-force', p.ops_force !== true);
        setBtnHidden('btn-ops-purge', p.ops_purge !== true);
    }

    function setBtnHidden(id, hidden) {
        var node = $(id);
        if (node) { node.hidden = hidden === true; }
    }

    bind('btn-ops-kick', function () {
        var cid = opsVal('ops-client-id');
        var uid = opsVal('ops-uid');
        if (cid === '' && uid === '') {
            setNote('ops-status', 'bad', 'client_id 与 uid 至少填一个。');
            return;
        }
        var body = {};
        if (cid !== '') { body.client_id = cid; }
        if (uid !== '') { body.uid = uid; }
        var reason = opsVal('ops-reason');
        if (reason !== '') { body.reason = reason; }
        runOps('kick', cfg.ops_kick_url, body);
    });

    bind('btn-ops-unbind', function () {
        var uid = opsVal('ops-uid');
        if (uid === '') {
            setNote('ops-status', 'bad', '解绑设备需要 uid。');
            return;
        }
        runOps('unbind', cfg.ops_unbind_url, { uid: uid });
    });

    bind('btn-ops-revoke', function () {
        var token = opsVal('ops-token');
        if (token === '') {
            setNote('ops-status', 'bad', '撤销需要 Token 明文。服务端不保存明文，会话里取不到它 —— 只能人工提供。');
            return;
        }
        runOps('revoke', cfg.ops_revoke_url, { token: token });
    });

    bind('btn-ops-force', function () {
        var cid = opsVal('ops-client-id');
        var uid = opsVal('ops-uid');
        if (cid === '' && uid === '') {
            setNote('ops-status', 'bad', 'client_id 与 uid 至少填一个。');
            return;
        }
        var body = {};
        if (cid !== '') { body.client_id = cid; }
        if (uid !== '') { body.uid = uid; }
        var token = opsVal('ops-token');
        // 没给 token 也允许提交：那时只执行 kick，服务端回 partial=true。
        // 刻意**不**在这里拦 —— 拦了用户会以为「强制下线做不了」，
        // 而实际是「禁止重连那一半做不了」，如实呈现由服务端 + 本文件的 partial 展示负责。
        if (token !== '') { body.token = token; }
        var reason = opsVal('ops-reason');
        if (reason !== '') { body.reason = reason; }
        runOps('force-offline', cfg.ops_force_url, body);
    });

    bind('btn-ops-purge', function () {
        var uid = opsVal('ops-uid');
        if (uid === '') {
            setNote('ops-status', 'bad', '清空离线队列需要 uid（client_id 会被忽略）。');
            return;
        }
        if (!window.confirm('确认清空 uid=' + uid + ' 的离线队列？未补投的离线消息将被丢弃且不可恢复。')) {
            return;
        }
        runOps('purge-offline', cfg.ops_purge_url, { uid: uid });
    });

    bind('btn-drawer-close', function () { closeDrawer(); });
    bind('drawer-mask', function () { closeDrawer(); });

    bind('btn-offline-prev', function () {
        if (state.offlinePage <= 1) { return; }
        loadOffline(state.offlinePage - 1);
    });

    bind('btn-offline-next', function () {
        if (state.offlinePages <= 1 || state.offlinePage >= state.offlinePages) { return; }
        loadOffline(state.offlinePage + 1);
    });

    // P4 运维区块：说明 + 权限显隐（**不自动取数**，等用户点击）
    renderOpsNotes();
    applyOpsPerms();

    // 首屏：先回填静态配置，再按 URL 还原状态并取一次列表（**唯一的一次自动取数**）
    setText('f-idmax', cfg.id_max_len);
    restoreFromUrl();
    syncFormFromState();

    loadList();

    // URL 里带了 client → 直接把抽屉打开（刷新 / 分享链接的场景）
    if (state.clientId) {
        openDrawer(state.clientId);
    }
}());

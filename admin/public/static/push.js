/**
 * GatewayPush 后台 · 推送管理页前端
 *
 * 由 webman 静态中间件直接提供（/static/push.js），无权限校验。
 * ⚠ 不得写入任何密钥或内网凭据 —— 所有签名都在服务端完成（本页只做「转签代理」的调用方）。
 *
 * ## 与 session.js 的关键差别：**本页有写操作**
 *
 * `/sessions` 是纯只读页，故 `SessionContractTest` 断言它「只发 GET」。
 * 本页相反 —— 它有 4 个写端点（发起推送 / 模板保存 / 模板删除）。但**只写后台自己的 MySQL
 * 与主项目 HTTP API**：绝不直连 Redis 改推送系统状态（否则绕过主项目的校验、限流与指标）。
 *
 * ## 仍然**没有定时器**
 *
 * 推送历史是检索型视图，静止时无需刷新。本脚本内不存在任何
 * `setTimeout` / `setInterval` / `setImmediate` / `requestAnimationFrame` ——
 * 由 `tests/Unit/PushContractTest.php` 静态断言守住。
 * （需要「等待」的是 `/actions` 的补查，那里用的是有界退避，属另一个页面。）
 *
 * ## 五条硬不变量
 *
 * 1. **载荷字节口径与业务进程同源**：服务端的判据是
 *    `strlen(Message::encode($payload))`，即**重新紧凑编码后**的字节数，而不是用户粘贴的原文。
 *    故本脚本先 `JSON.parse` 再 `JSON.stringify` 才计数（见 `encodePayload()`）——
 *    直接量原文会把一个 3KB 的格式化载荷报成 6KB 并误拦。
 * 2. **`code=0` 只代表已入队**：回执区一律同时展示 HTTP 状态、业务码与后端下发的三条说明，
 *    界面文案上不出现「已发送 / 已投递」。
 * 3. **筛选条件不得被静默丢弃**：`target` 若非法（超长 / 含控制字符），
 *    服务端 `PushRepository::appliedFilters()` 会**丢掉它**，界面却照常显示「查询结果」——
 *    用户会以为条件生效了。故前端预校验并拒绝提交（`readHistoryForm()`）。
 * 4. **空载荷可用，但格式错误必须报**：载荷不是合法 JSON **对象**时不允许提交，
 *    因为服务端 `Pusher::decodePayload()` 同样会拒（数组与标量都不收）。
 * 5. **XSS**：服务端返回的一切字符串（目标值、载荷原文、备注、错误信息）只能经
 *    `textContent` / `createTextNode` 落地，**一律不得拼进 `innerHTML`**。
 *
 * ## 权限的显示与执行是两件事
 *
 * `cfg.perms` 只用来**显隐控件**（降噪 + 防误触）。真正的边界是服务端
 * `config/route.php` 上每条路由的 `AdminAuth`。故本脚本不做任何「因为没权限所以不发请求」
 * 之类的安全假设 —— 该发就发，让服务端去拒。
 */
(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('push-config').textContent);

    var state = {
        page: 1,
        pages: 0,
        total: 0,
        size: num(cfg.page_size) || 20,
        templates: [],
        editingId: 0,
        historySeq: 0,
        tplSeq: 0,
        busy: false
    };

    // ------------------------------------------------------------------
    // DOM 原语
    // ------------------------------------------------------------------

    function $(id) { return document.getElementById(id); }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.appendChild(document.createTextNode(String(text))); }
        return node;
    }

    function clear(node) {
        while (node && node.firstChild) { node.removeChild(node.firstChild); }
    }

    function setText(id, text) {
        var node = $(id);
        if (node) { node.textContent = String(text); }
    }

    function hide(id) {
        var node = $(id);
        if (node) { node.hidden = true; }
    }

    function show(id) {
        var node = $(id);
        if (node) { node.hidden = false; }
    }

    function val(id) {
        var node = $(id);
        return node ? String(node.value) : '';
    }

    function setVal(id, value) {
        var node = $(id);
        if (node) { node.value = String(value); }
    }

    /** 表格空态：一行跨满所有列。`cols` 必须与视图表头列数一致（否则会出现错位的一格）。 */
    function setRowEmpty(bodyId, message, cols) {
        var body = $(bodyId);
        if (!body) { return; }
        clear(body);
        var tr = el('tr');
        var td = el('td', 'empty', message);
        td.setAttribute('colspan', String(cols));
        tr.appendChild(td);
        body.appendChild(tr);
    }

    function setNote(containerId, kind, text) {
        var box = $(containerId);
        if (!box) { return; }
        clear(box);
        if (text) { box.appendChild(el('div', 'note ' + (kind || 'info'), text)); }
    }

    function tag(level, text) { return el('span', 'tag ' + (level || 'mute'), text); }

    function num(value) {
        var n = Number(value);
        return isFinite(n) ? n : 0;
    }

    function fmtInt(value) { return Math.round(num(value)).toLocaleString('zh-CN'); }

    function msgOf(err) { return String(err && err.message ? err.message : err); }

    /** UTF-8 字节数（与 PHP `strlen()` 同口径；中文计 3 字节） */
    function utf8Len(value) {
        var s = String(value == null ? '' : value);
        if (typeof TextEncoder === 'function') { return new TextEncoder().encode(s).length; }
        // 兜底（老浏览器）：手算 UTF-8 长度。仍比 charCodeAt 计数正确。
        var n = 0;
        for (var i = 0; i < s.length; i++) {
            var c = s.charCodeAt(i);
            if (c < 0x80) { n += 1; }
            else if (c < 0x800) { n += 2; }
            else if (c >= 0xD800 && c <= 0xDBFF) { n += 4; i++; }
            else { n += 3; }
        }
        return n;
    }

    // ------------------------------------------------------------------
    // 信封
    // ------------------------------------------------------------------

    /**
     * 取数 / 提交并解开 `{code,msg,data}` 信封。
     *
     * 与 session.js 的差别：**失败时把 `body.data` 也挂到 error 上**。
     * 本页的失败响应里 `data` 承载关键信息（`errors` 列表、`http_status`/`code`/`upstream_msg`
     * 三元组、`recorded`/`audit_ok`），丢掉它就只能显示一句笼统的 msg。
     *
     * 四种情况必须分开处理：
     *   - 非 JSON 响应（反代/登录页）→ 不能直接 `.json()` 抛原生解析错；
     *   - HTTP 非 2xx → 带 status/code/data 抛出；
     *   - HTTP 2xx 但业务码非 0 → 兜底（本项目 ok 恒为 code 0）；
     *   - 正常 → 返回 `data`。
     */
    function requestJson(url, method, body) {
        var options = {
            method: method,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        };
        if (body !== undefined && body !== null) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        return fetch(url, options).then(function (res) {
            return res.json().then(
                function (b) { return { status: res.status, ok: res.ok, body: b }; },
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
                httpErr.msg = r.body.msg || '';
                throw httpErr;
            }
            if (num(r.body.code) !== 0) {
                throw new Error('业务码 ' + r.body.code + '：' + (r.body.msg || ''));
            }
            return r.body.data;
        });
    }

    function getJson(url) { return requestJson(url, 'GET', null); }

    // ------------------------------------------------------------------
    // 说明文案（后端下发，本脚本既不编词也不选样式）
    // ------------------------------------------------------------------

    /**
     * `notes` 是 `[{key,tone,text}]`，tone 由后端给 —— 前端**不得**按 key 自行决定
     * 用 info 还是 warn（那等于把「哪条严重」这个判断复制到前端，后端改 tone 时前端不跟）。
     */
    function renderNotes(containerId, notes) {
        var box = $(containerId);
        if (!box) { return; }
        clear(box);
        var list = notes || [];
        for (var i = 0; i < list.length; i++) {
            var n = list[i] || {};
            box.appendChild(el('div', 'note ' + (n.tone || 'info'), n.text || ''));
        }
    }

    // ------------------------------------------------------------------
    // 权限 → 控件显隐（**不是**权限边界，见文件头）
    // ------------------------------------------------------------------

    function applyPerms() {
        var p = cfg.perms || {};
        if (!p.can_create) { hide('sec-create'); }
        if (!p.can_template_save) { hide('sec-template-editor'); }
        if (!p.can_create && !p.can_template_save) { show('push-readonly'); }
    }

    // ------------------------------------------------------------------
    // 枚举下拉
    // ------------------------------------------------------------------

    /**
     * 用后端下发的取值 + 标签填充下拉。
     *
     * `labels` 是 `{'': '...', 'drop': '...'}`（键可能是空串 —— 空串代表「服务端默认」，
     * 是一个**合法取值**而不是「未选」，故必须如实出现为一个 option）。
     *
     * ⚠ 本函数**不设**默认选中项，初值由「首屏」段显式逐项 `setVal` 给出（见文件末尾）——
     * 因为四个下拉的默认值语义并不同：`f/t-target-type` 取第一个取值，
     * `f/t-offline-mode` 取空串（= 服务端默认），`h-target-type` 取空串（= 不限）。
     * 「默认 = 第一个 option」这种通则在这里是**碰巧**成立，写进来反而会掩盖逐项的意图。
     * 漏设的表现：`f-target-type` 为 `''` 时主项目会把 `target_type` **兜底回落成 `uid`**
     * （主项目 `Push::TARGET_*`），于是「看起来选了 device」的推送实际发给了 uid，全程无报错。
     * `tests/Frontend/push_render_check.js` P01 已把这两项初值钉住。
     */
    function fillSelect(id, values, labels, prefixText) {
        var node = $(id);
        if (!node) { return; }
        clear(node);
        if (prefixText) {
            var first = el('option', null, prefixText);
            first.value = '';
            node.appendChild(first);
        }
        for (var i = 0; i < values.length; i++) {
            var v = values[i];
            var opt = el('option', null, labels[v] !== undefined ? labels[v] : v);
            opt.value = v;
            node.appendChild(opt);
        }
    }

    function fillCreateEnums() {
        fillSelect('f-target-type', cfg.target_types, cfg.target_labels, null);
        fillSelect('f-offline-mode', cfg.offline_modes, cfg.offline_labels, null);
        fillSelect('t-target-type', cfg.target_types, cfg.target_labels, null);
        fillSelect('t-offline-mode', cfg.offline_modes, cfg.offline_labels, null);
        // 历史筛选的 target_type 是「全部 + 三值」
        fillSelect('h-target-type', cfg.target_types, cfg.target_labels, '全部');
    }

    // ------------------------------------------------------------------
    // 载荷字节（与业务进程同口径）
    // ------------------------------------------------------------------

    /**
     * 把 textarea 的原文转成「服务端实际会看到的载荷」并计字节。
     *
     * 关键：**重新紧凑编码**。用户粘贴的格式化 JSON 里的空格与换行不计入服务端判据
     * （业务进程拿到的已是解码后的结构，`Message::encode()` 会重新编码）。
     * 直接量原文会把 3KB 的载荷报成 6KB 并误拦。
     *
     * @returns {{ok: boolean, text: string, bytes: number, reason: string}}
     *   `ok=false` 时 `reason` 是给用户看的说明（不是给程序判别的码）。
     */
    function encodePayload(raw) {
        var trimmed = String(raw == null ? '' : raw).trim();
        if (trimmed === '') {
            // 空载荷是合法的（服务端 `decodePayload('')` → `[]`），字节数按服务端的 `[]` 计
            return { ok: true, text: '[]', bytes: 2, reason: '' };
        }

        var parsed;
        try {
            parsed = JSON.parse(trimmed);
        } catch (e) {
            return { ok: false, text: '', bytes: utf8Len(trimmed), reason: '不是合法 JSON：' + msgOf(e) };
        }

        // 服务端只收 JSON **对象**（`decodePayload()` 对数组与标量返回 ok=false）
        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            return {
                ok: false,
                text: '',
                bytes: utf8Len(trimmed),
                reason: '载荷必须是 JSON 对象（顶层不能是数组、字符串、数字或 null）'
            };
        }

        var encoded = JSON.stringify(parsed);
        return { ok: true, text: encoded, bytes: utf8Len(encoded), reason: '' };
    }

    /** 刷新字节计数显示，并返回 `encodePayload` 的结果（调用方据此决定是否放行）。 */
    function refreshBytes(inputId, outId, maxId) {
        var info = encodePayload(val(inputId));
        var out = $(outId);
        if (out) {
            out.textContent = fmtInt(info.bytes);
            out.className = info.bytes > num(cfg.payload_max) ? 'bytes-over' : '';
        }
        if (maxId) { setText(maxId, fmtInt(cfg.payload_max)); }
        return info;
    }

    // ------------------------------------------------------------------
    // 一、发起推送
    // ------------------------------------------------------------------

    /**
     * 表单校验（与 `Pusher::validatePush()` 同口径）。
     *
     * 这里只做「能明确判定的形态问题」；**服务端仍是唯一权威**，
     * 且主项目可能在后台之后进一步拒（那类失败按 `502 + 5020` 呈现）。
     *
     * @returns {string} 空串 = 通过；否则为拒绝原因
     */
    function readCreateForm() {
        var targetType = val('f-target-type');
        if (cfg.target_types.indexOf(targetType) < 0) {
            return '目标类型非法：' + targetType;
        }

        var target = val('f-target').trim();
        if (target === '') {
            return '目标值必填';
        }
        if (utf8Len(target) > num(cfg.id_max_len)) {
            return '目标值超过 ' + cfg.id_max_len + ' 字节（UTF-8，与后端同口径）';
        }
        if (/[\u0000-\u001F\u007F]/.test(target)) {
            return '目标值含控制字符';
        }

        var msgId = val('f-msg-id').trim();
        if (utf8Len(msgId) > num(cfg.msg_id_max_len)) {
            return 'msg_id 超过 ' + cfg.msg_id_max_len + ' 字节';
        }

        var info = encodePayload(val('f-payload'));
        if (!info.ok) { return info.reason; }
        if (info.bytes > num(cfg.payload_max)) {
            return '载荷 ' + info.bytes + ' 字节，超过上限 ' + cfg.payload_max + ' 字节'
                + '（服务端同样会拒，且若绕过本页提交，业务进程会**静默丢弃**）';
        }

        return '';
    }

    function buildCreateBody() {
        return {
            target_type: val('f-target-type'),
            target: val('f-target').trim(),
            // 发**原文**而不是解析后的对象：服务端 `decodePayload()` 两种形态都收，
            // 发原文能让它在 JSON 非法时报出精确位置（虽然本页已预校验，但双击提交等竞态仍可能到那一层）。
            payload: val('f-payload').trim(),
            msg_id: val('f-msg-id').trim(),
            offline_mode: val('f-offline-mode')
        };
    }

    function send() {
        if (state.busy) { return; }
        var problem = readCreateForm();
        if (problem !== '') {
            setNote('push-status', 'bad', problem);
            hide('push-result-wrap');
            return;
        }

        state.busy = true;
        var btn = $('btn-push-send');
        if (btn) { btn.disabled = true; }
        setNote('push-status', 'info', '正在提交…');

        requestJson(cfg.create_url, 'POST', buildCreateBody()).then(
            function (data) { renderPushResult(data || {}, true); },
            function (err) {
                renderPushResult(err && err.data ? err.data : {}, false);
                var detail = err && err.data && err.data.errors && err.data.errors.length
                    ? '：' + err.data.errors.join('；')
                    : '';
                setNote('push-status', 'bad', msgOf(err) + detail);
            }
        ).then(function () {
            state.busy = false;
            if (btn) { btn.disabled = false; }
        });
    }

    /**
     * 回执渲染。
     *
     * ⚠ 这里**刻意不用「成功 / 失败」两个字概括整件事**：
     * `accepted` 只说明「已入队」，随后仍可能被去重、被 payload 上限静默丢弃。
     * 故一律把服务端三元组（HTTP / 业务码 / 上游 msg）与后端下发的说明一并摊开。
     */
    function renderPushResult(data, ok) {
        var body = $('tb-push-result');
        if (!body) { return; }

        if (ok) {
            setNote('push-status', 'info', '已提交。请结合下方「服务端回执」与说明判读，不要只看 HTTP 状态。');
        }

        clear(body);
        var rows = [
            ['request_id', data.request_id],
            ['是否受理', data.accepted === true ? '是（已入队）' : '否'],
            ['目标类型', (data.target_type || '') + (data.target_label ? '（' + data.target_label + '）' : '')],
            ['目标值', data.target],
            ['msg_id', (data.msg_id || '') + (data.msg_id_generated ? '（后台生成）' : '（调用方提供）')],
            ['离线策略', (data.offline_mode === '' ? '(服务端默认)' : data.offline_mode)
                + (data.offline_label ? '（' + data.offline_label + '）' : '')],
            ['载荷字节', fmtInt(data.payload_bytes) + ' / 上限 ' + fmtInt(data.payload_max)],
            ['上游 HTTP', fmtInt(data.http_status)],
            ['上游业务码', fmtInt(data.code)],
            ['上游 msg', data.upstream_msg || '—'],
            ['受理记录已落库', data.recorded === true ? '是' : ('否' + (data.record_error ? '（' + data.record_error + '）' : ''))],
            ['审计已落库', data.audit_ok === true ? '是' : '否（动作已生效，仅留痕失败）']
        ];

        for (var i = 0; i < rows.length; i++) {
            var tr = el('tr');
            var value = rows[i][1];
            tr.appendChild(el('th', null, rows[i][0]));
            // ⚠ 第二个入参是 className，不能省 —— 少了它，值会被当成 class 名写掉，
            //   单元格内容全空（页面上回执只剩标签），且不会有任何报错。
            tr.appendChild(el('td', null, value === null || value === undefined ? '—' : String(value)));
            body.appendChild(tr);
        }

        // 三条语义说明由后端下发；同时给出「去 Dashboard 看投递指标」的出口
        var notes = data.notes || [];
        for (var j = 0; j < notes.length; j++) {
            var tr2 = el('tr');
            tr2.appendChild(el('th', null, '说明'));
            tr2.appendChild(el('td', null, String(notes[j])));
            body.appendChild(tr2);
        }
        if (data.dashboard_url) {
            var tr3 = el('tr');
            tr3.appendChild(el('th', null, '投递指标'));
            var td3 = el('td');
            var a = el('a', null, '去主项目 Dashboard 看投递指标 ↗');
            a.href = String(data.dashboard_url);
            a.target = '_blank';
            a.rel = 'noopener';
            td3.appendChild(a);
            tr3.appendChild(td3);
            body.appendChild(tr3);
        }

        show('push-result-wrap');
    }

    function resetCreate() {
        setVal('f-target', '');
        setVal('f-msg-id', '');
        setVal('f-payload', '');
        setText('f-payload-bytes', '0');
        setNote('push-status', '', '');
        hide('push-result-wrap');
    }

    // ------------------------------------------------------------------
    // 二、推送历史
    // ------------------------------------------------------------------

    /**
     * 读筛选表单并做**预校验**。
     *
     * ⚠ 这一步不可省：`target` 超长/含控制字符、或 `msg_id` 超长时，
     * 服务端 `PushRepository::appliedFilters()` 会**静默丢弃该条件**，
     * 界面照常显示「查询结果」—— 用户会以为筛选生效了，而实际是全量。
     * 那种「界面撒谎」比直接报错严重得多，故此处宁可拒绝提交。
     *
     * @returns {string} 空串 = 通过；否则为拒绝原因
     */
    function readHistoryForm() {
        var target = val('h-target').trim();
        if (target !== '') {
            if (utf8Len(target) > num(cfg.id_max_len)) {
                return '目标值筛选项超过 ' + cfg.id_max_len + ' 字节 —— 服务端会静默丢弃该条件，故拒绝提交';
            }
            if (/[\u0000-\u001F\u007F]/.test(target)) {
                return '目标值筛选项含控制字符 —— 服务端会静默丢弃该条件，故拒绝提交';
            }
        }

        if (utf8Len(val('h-msg-id').trim()) > num(cfg.msg_id_max_len)) {
            return 'msg_id 筛选项超过 ' + cfg.msg_id_max_len + ' 字节 —— 服务端会静默丢弃该条件，故拒绝提交';
        }

        var from = val('h-from');
        var to = val('h-to');
        if (from !== '' && to !== '' && from > to) {
            return '开始日期晚于结束日期';
        }

        return '';
    }

    function historyUrl() {
        var q = ['page=' + state.page, 'size=' + state.size];
        var map = {
            target_type: val('h-target-type'),
            status: val('h-status'),
            target: val('h-target').trim(),
            msg_id: val('h-msg-id').trim(),
            from: val('h-from'),
            to: val('h-to')
        };
        for (var k in map) {
            if (Object.prototype.hasOwnProperty.call(map, k) && map[k] !== '') {
                q.push(k + '=' + encodeURIComponent(map[k]));
            }
        }
        return cfg.history_url + '?' + q.join('&');
    }

    function loadHistory() {
        var seq = ++state.historySeq;
        setNote('history-status', 'info', '正在取数…');
        return getJson(historyUrl()).then(function (data) {
            if (seq !== state.historySeq) { return; }
            renderHistory(data || {});
        }, function (err) {
            if (seq !== state.historySeq) { return; }
            setNote('history-status', 'bad', msgOf(err));
            setRowEmpty('tb-history', '取数失败', 11);
            setText('history-pager-info', '—');
        });
    }

    function renderHistory(data) {
        var items = data.items || [];
        state.page = num(data.page) || 1;
        state.pages = num(data.pages) || 0;
        state.total = num(data.total) || 0;

        setNote('history-status', '', '');
        renderNotes('history-note', data.record_note ? [{ tone: 'info', text: data.record_note }] : []);

        var s = data.summary || {};
        var line = $('history-summary');
        if (line) {
            clear(line);
            line.appendChild(el('span', 'tag mute', '受理记录总计 ' + fmtInt(s.total)));
            line.appendChild(el('span', 'tag ok', '已受理 ' + fmtInt(s.accepted)));
            line.appendChild(el('span', 'tag bad', '未受理 ' + fmtInt(s.rejected)));
            line.appendChild(el('span', 'tag warn', '今日 ' + fmtInt(s.today)));
        }

        var body = $('tb-history');
        if (!body) { return; }
        clear(body);

        if (items.length === 0) {
            setRowEmpty('tb-history', '没有匹配的受理记录', 11);
            setText('history-pager-info', '0 / 0');
            return;
        }

        for (var i = 0; i < items.length; i++) {
            body.appendChild(buildHistoryRow(items[i] || {}));
        }

        setText('history-pager-info', '第 ' + state.page + ' / ' + (state.pages || 1) + ' 页 · 共 ' + fmtInt(state.total) + ' 条');
    }

    function buildHistoryRow(row) {
        var tr = el('tr');
        tr.appendChild(el('td', null, row.created_at || '—'));
        var tdStatus = el('td');
        tdStatus.appendChild(tag(row.status === 'accepted' ? 'ok' : 'bad', row.status === 'accepted' ? '已受理' : '未受理'));
        tr.appendChild(tdStatus);
        tr.appendChild(el('td', null, row.target_type || '—'));
        tr.appendChild(el('td', 'msg', row.target || '—'));
        tr.appendChild(el('td', 'num', fmtInt(row.payload_bytes)));
        tr.appendChild(el('td', null, row.offline_mode === '' ? '(服务端默认)' : (row.offline_mode || '—')));
        tr.appendChild(el('td', 'msg', row.msg_id || '—'));
        tr.appendChild(el('td', 'num', fmtInt(row.http_status)));
        tr.appendChild(el('td', 'num', fmtInt(row.code)));
        tr.appendChild(el('td', 'msg', row.request_id || '—'));
        // 载荷原文：不解析（DB 里的 JSON 可能被人工改坏，解析失败静默变 {} 会撒谎）
        tr.appendChild(el('td', 'msg', row.payload === null || row.payload === undefined ? '—' : String(row.payload)));
        return tr;
    }

    function resetHistoryForm() {
        setVal('h-target-type', '');
        setVal('h-status', '');
        setVal('h-target', '');
        setVal('h-msg-id', '');
        setVal('h-from', '');
        setVal('h-to', '');
        setVal('h-size', state.size);
        state.page = 1;
    }

    // ------------------------------------------------------------------
    // 三、模板管理
    // ------------------------------------------------------------------

    function loadTemplates() {
        var seq = ++state.tplSeq;
        setNote('tpl-status', 'info', '正在取数…');
        return getJson(cfg.templates_url).then(function (data) {
            if (seq !== state.tplSeq) { return; }
            renderTemplates(data || {});
        }, function (err) {
            if (seq !== state.tplSeq) { return; }
            setNote('tpl-status', 'bad', msgOf(err));
            setRowEmpty('tb-templates', '取数失败', 8);
        });
    }

    function renderTemplates(data) {
        setNote('tpl-status', '', '');
        renderNotes('tpl-note', data.note ? [{ tone: 'info', text: data.note }] : []);

        state.templates = data.items || [];

        var body = $('tb-templates');
        if (body) {
            clear(body);
            if (state.templates.length === 0) {
                setRowEmpty('tb-templates', '还没有模板', 8);
            } else {
                for (var i = 0; i < state.templates.length; i++) {
                    body.appendChild(buildTemplateRow(state.templates[i] || {}));
                }
            }
        }

        // 「从模板载入」下拉同步
        var pick = $('f-template-pick');
        if (pick) {
            clear(pick);
            var ph = el('option', null, state.templates.length ? '— 选择一个模板 —' : '— 暂无模板 —');
            ph.value = '';
            pick.appendChild(ph);
            for (var j = 0; j < state.templates.length; j++) {
                var t = state.templates[j] || {};
                var opt = el('option', null, String(t.name || ('#' + t.id)));
                opt.value = String(t.id);
                pick.appendChild(opt);
            }
        }
    }

    function buildTemplateRow(t) {
        var tr = el('tr');
        tr.appendChild(el('td', null, t.name || '—'));
        tr.appendChild(el('td', null, t.target_label || t.target_type || '—'));
        tr.appendChild(el('td', null, t.offline_mode === '' ? '(服务端默认)' : (t.offline_label || t.offline_mode || '—')));
        tr.appendChild(el('td', 'num', fmtInt(t.payload_bytes)));
        tr.appendChild(el('td', 'msg', t.payload_raw || '—'));
        tr.appendChild(el('td', null, t.remark || '—'));
        tr.appendChild(el('td', null, t.updated_at || t.created_at || '—'));

        var td = el('td');
        var p = cfg.perms || {};

        if (p.can_template_save) {
            var btnEdit = el('button', 'btn mini', '编辑');
            btnEdit.type = 'button';
            btnEdit.addEventListener('click', function () { editTemplate(t); });
            td.appendChild(btnEdit);
        }
        if (p.can_template_delete) {
            var btnDel = el('button', 'btn mini danger', '删除');
            btnDel.type = 'button';
            btnDel.addEventListener('click', function () { deleteTemplate(t); });
            td.appendChild(btnDel);
        }
        if (!p.can_template_save && !p.can_template_delete) {
            td.appendChild(el('span', 'hint', '只读'));
        }
        tr.appendChild(td);
        return tr;
    }

    function editTemplate(t) {
        setVal('t-id', t.id);
        setVal('t-name', t.name || '');
        setVal('t-target-type', t.target_type || '');
        setVal('t-offline-mode', t.offline_mode === undefined ? '' : t.offline_mode);
        // 用 payload_raw 而不是重新 stringify：前者是 DB 原文，字节数与服务端一致
        setVal('t-payload', t.payload_raw || '');
        setVal('t-remark', t.remark || '');
        state.editingId = num(t.id);
        setText('tpl-editor-title', '编辑模板 #' + state.editingId + '（' + (t.name || '') + '）');
        refreshBytes('t-payload', 't-payload-bytes', 't-payload-max');
        setNote('tpl-status', 'info', '已载入模板，修改后点「保存模板」覆盖。');
    }

    function resetEditor() {
        setVal('t-id', '');
        setVal('t-name', '');
        setVal('t-payload', '');
        setVal('t-remark', '');
        state.editingId = 0;
        setText('tpl-editor-title', '新建模板');
        if (cfg.target_types.length) { setVal('t-target-type', cfg.target_types[0]); }
        setVal('t-offline-mode', '');
        refreshBytes('t-payload', 't-payload-bytes', 't-payload-max');
    }

    function saveTemplate() {
        if (state.busy) { return; }

        var name = val('t-name').trim();
        if (name === '') { setNote('tpl-status', 'bad', '模板名必填'); return; }
        if (utf8Len(name) > num(cfg.template_name_max_len)) {
            setNote('tpl-status', 'bad', '模板名超过 ' + cfg.template_name_max_len + ' 字节');
            return;
        }
        if (utf8Len(val('t-remark').trim()) > num(cfg.template_remark_max_len)) {
            setNote('tpl-status', 'bad', '备注超过 ' + cfg.template_remark_max_len + ' 字节');
            return;
        }

        var info = encodePayload(val('t-payload'));
        if (!info.ok) { setNote('tpl-status', 'bad', info.reason); return; }
        if (info.bytes > num(cfg.payload_max)) {
            setNote('tpl-status', 'bad', '载荷 ' + info.bytes + ' 字节，超过上限 ' + cfg.payload_max + ' 字节');
            return;
        }

        var body = {
            name: name,
            target_type: val('t-target-type'),
            offline_mode: val('t-offline-mode'),
            payload: val('t-payload').trim(),
            remark: val('t-remark').trim()
        };
        if (state.editingId > 0) { body.id = state.editingId; }

        state.busy = true;
        var btn = $('btn-tpl-save');
        if (btn) { btn.disabled = true; }
        setNote('tpl-status', 'info', '正在保存…');

        requestJson(cfg.templates_url, 'POST', body).then(
            function (data) {
                var d = data || {};
                resetEditor();
                // ⚠ 成功提示必须放在 `loadTemplates()` **之后**：
                //   `loadTemplates()` 会先把状态栏写成「正在取数…」，而 `renderTemplates()`
                //   又会在渲染完成时清空状态栏 —— 先写提示等于立刻被覆盖，
                //   用户**永远看不到保存成功的确认**（渲染校验抓到过，属真实缺陷）。
                return loadTemplates().then(function () {
                    setNote('tpl-status', 'info',
                        (d.is_edit ? '已更新模板 #' : '已新建模板 #') + d.id
                        + (d.audit_ok === false ? '（审计落库失败，动作已生效，请检查后台日志）' : ''));
                });
            },
            function (err) {
                var detail = err && err.data && err.data.errors && err.data.errors.length
                    ? '：' + err.data.errors.join('；')
                    : '';
                setNote('tpl-status', 'bad', msgOf(err) + detail);
            }
        ).then(function () {
            state.busy = false;
            if (btn) { btn.disabled = false; }
        });
    }

    function deleteTemplate(t) {
        if (state.busy) { return; }
        // 硬删除且不可撤销，故必须二次确认。用 confirm 而不是「再点一次」：
        // 后者在列表里极易被误当成「点了没反应」而连点两下。
        if (typeof window.confirm === 'function'
            && !window.confirm('确认删除模板「' + (t.name || '') + '」？此操作不可撤销。')) {
            return;
        }

        state.busy = true;
        setNote('tpl-status', 'info', '正在删除…');

        requestJson(cfg.template_delete_base + num(t.id), 'DELETE', null).then(
            function (data) {
                var d = data || {};
                // 若删的正是编辑区里那条，把编辑区复位（否则「保存」会打到已删除的 id 上）
                if (state.editingId === num(t.id)) { resetEditor(); }
                // 同 `saveTemplate()`：提示必须写在 `loadTemplates()` 之后，否则会被
                //「正在取数…」与渲染时的清空先后覆盖掉（用户看不到任何确认）。
                return loadTemplates().then(function () {
                    setNote('tpl-status', 'info',
                        '已删除模板「' + (d.name || '') + '」'
                        + (d.audit_ok === false ? '（审计落库失败，动作已生效，请检查后台日志）' : ''));
                });
            },
            function (err) { setNote('tpl-status', 'bad', msgOf(err)); }
        ).then(function () { state.busy = false; });
    }

    function loadTemplateIntoCreate() {
        var id = num(val('f-template-pick'));
        if (id <= 0) { setNote('push-status', 'info', '请先在上方选择一个模板。'); return; }

        var found = null;
        for (var i = 0; i < state.templates.length; i++) {
            if (num(state.templates[i].id) === id) { found = state.templates[i]; break; }
        }
        if (!found) { setNote('push-status', 'bad', '模板已不在当前列表中，请重新取数。'); return; }

        setVal('f-target-type', found.target_type || '');
        setVal('f-offline-mode', found.offline_mode === undefined ? '' : found.offline_mode);
        setVal('f-payload', found.payload_raw || '');
        // ⚠ 刻意**不**动目标值与 msg_id：模板不保存这两项（服务端亦如此，见 notes.template）
        refreshBytes('f-payload', 'f-payload-bytes', 'f-payload-max');
        setNote('push-status', 'info', '已载入模板「' + (found.name || '') + '」的目标类型 / 载荷 / 离线策略；目标值与 msg_id 需自行填写。');
    }

    // ------------------------------------------------------------------
    // 事件绑定与启动
    // ------------------------------------------------------------------

    function bind(id, handler) {
        var node = $(id);
        if (node) { node.addEventListener('click', handler); }
    }

    function bindInput(id, handler) {
        var node = $(id);
        if (node) { node.addEventListener('input', handler); }
    }

    bind('btn-push-send', function () { send(); });
    bind('btn-push-reset', function () { resetCreate(); });
    bind('btn-template-load', function () { loadTemplateIntoCreate(); });

    bind('btn-history-query', function () {
        var problem = readHistoryForm();
        if (problem !== '') {
            setNote('history-status', 'bad', problem);
            return;
        }
        state.page = 1;
        loadHistory();
    });
    bind('btn-history-reload', function () { loadHistory(); });
    bind('btn-history-reset', function () { resetHistoryForm(); loadHistory(); });

    bind('btn-history-prev', function () {
        if (state.page <= 1) { return; }
        state.page -= 1;
        loadHistory();
    });
    bind('btn-history-next', function () {
        if (state.pages <= 1 || state.page >= state.pages) { return; }
        state.page += 1;
        loadHistory();
    });

    bind('btn-tpl-save', function () { saveTemplate(); });
    bind('btn-tpl-new', function () { resetEditor(); });
    bind('btn-tpl-cancel', function () { resetEditor(); });

    // 字节计数：输入即刷新（无定时器，纯事件驱动）
    bindInput('f-payload', function () { refreshBytes('f-payload', 'f-payload-bytes', 'f-payload-max'); });
    bindInput('t-payload', function () { refreshBytes('t-payload', 't-payload-bytes', 't-payload-max'); });

    // ---- 首屏 ----

    applyPerms();

    renderNotes('push-notes', cfg.notes.global);
    renderNotes('history-note', cfg.notes.history);
    renderNotes('tpl-note', cfg.notes.templates);

    fillCreateEnums();
    setVal('f-target-type', cfg.target_types.length ? cfg.target_types[0] : '');
    setVal('f-offline-mode', '');
    setVal('t-target-type', cfg.target_types.length ? cfg.target_types[0] : '');
    setVal('t-offline-mode', '');
    setVal('h-size', state.size);
    setVal('t-id', '');

    // 静态提示（与后端常量同源）
    setText('f-msg-id-hint', '留空则由后台自动生成一个 ' + cfg.msg_id_len + ' 位 hex；'
        + '手填上限 ' + cfg.msg_id_max_len + ' 字节。'
        + '⚠ 服务端只在**提供了 msg_id** 时启用幂等 —— 留空等于放弃这层保护，故后台会替你补一个。');
    setText('t-name-max', cfg.template_name_max_len);
    setText('t-remark-max', cfg.template_remark_max_len);
    refreshBytes('f-payload', 'f-payload-bytes', 'f-payload-max');
    refreshBytes('t-payload', 't-payload-bytes', 't-payload-max');

    // 唯一两次自动取数（打开时各一次），其余全部由交互触发
    loadHistory();
    loadTemplates();
}());

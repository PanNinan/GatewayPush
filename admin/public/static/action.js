/**
 * GatewayPush 后台 · 动作调试页前端
 *
 * 由 webman 静态中间件直接提供（/static/action.js），无权限校验。
 * ⚠ 不得写入任何密钥或内网凭据 —— 签名在服务端完成。
 *
 * ## 本脚本为什么**允许** setTimeout（而 session.js / push.js 一个都不许）
 *
 * 三页的定时器政策不同，是刻意的、有理由的，不是疏漏：
 *
 * | 页面 | 在等什么 | 定时器政策 |
 * |---|---|---|
 * | `/dashboard` | 持续观察「此刻的状态」 | 快 / 慢两条**周期**轮询（`setInterval` 族） |
 * | `/sessions`、`/push` | 不等，交互即取数 | **一个都不许** |
 * | `/actions`（本页） | **一次性**等某条动作的回执 | 只许 `setTimeout` **递归**退避；**不许** `setInterval` |
 *
 * 判据：补查在「取到终态」或「次数用尽」时**必然停止**。
 * 若改用 `setInterval`，就变成「无论有没有结果都持续打主项目 API」——
 * 在对方是生产环境的前提下，这是必须避免的行为。
 *
 * ⚠ 「停止」有两层意思，**缺一个都是假停止**：会话号（`poll.seq`）让已排队的回调不再生效，
 * `window.clearTimeout` 让在途的那一个定时器**不再触发**。只做前者时，
 * 用户点了「手动补查」后一秒仍会多打一次请求 —— 结果被挡住，请求照发。
 *
 * 两条不变量由 `tests/Unit/ActionContractTest.php` 静态钉住（不是靠注释约束）：
 *   ① 脚本内不得出现 `setInterval` / `setImmediate` / `requestAnimationFrame`；
 *   ② 退避总时长必须 < 回执保留窗口 —— 否则最后几次补查注定落在窗口外，
 *      只会在界面上稳定产出「已超出补查窗口」的噪声。
 *
 * ## 三条语义陷阱（界面必须如实呈现，不得用「成功 / 失败」概括）
 *
 * 1. **`failed` 的 HTTP 可能仍是 200** —— 只看 HTTP 会把失败当成功；
 * 2. **`pending` 不是失败** —— 超窗只是还没算完，`202` 亦然；
 * 3. **`expired` 有两义** —— 服务端对 `404 + 4004` 刻意不区分「仍在执行」与
 *    「已过 `ACTION_RESULT_TTL` 被回收」，本页同样不替用户猜。
 * 故回执区一律摊开 `state` / `http` / `code` / `msg` / `note`，并附后端下发的解释。
 *
 * ## 第四条陷阱：**「补查能不能重试」≠「动作能不能重发」**（2026-09-23 修）
 *
 * 回执里的「重发」行读 `data.resend`（后端下发的 `safe` / `unsafe` / `unknown` 三值），
 * **不读** `data.retryable` —— 后者只回答「补查能不能重试」（补查是纯读，只对 `pending` 为真）。
 * 两者在 `rejected` 上正好相反：它从未入队，补查无从谈起，但重发绝对安全。
 * 先前用 `retryable` 渲染该行时，最该重发的状态被显示成「不要重发」，
 * 而不该重发的 `done` 反而拿到了「重发安全」的措辞 —— 界面在**两个方向上都撒谎**。
 * 现在这一行的短标签 / 色调 / 解释全部由 `ActionOutcome::RESEND*` 下发，本脚本不编词。
 *
 * ## 其余纪律
 *
 * - **`uid` 必填**：当前 HTTP 开放的 6 个动作声明里 `auth` 均为 `true`，
 *   缺 uid 会被服务端回 `401 + 4003`。故本页按 `requires_uid` 预校验 ——
 *   不是「UI 想不想填」，而是「不填必然失败」。
 * - **XSS**：服务端返回的一切字符串只能经 `textContent` / `createTextNode` 落地，
 *   一律不得拼进 `innerHTML`（`result` 里可能出现任意业务数据）。
 */
(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('action-config').textContent);

    /**
     * 补查会话号：任何一次新的调用 / 手动补查都会 +1，使旧的定时链自然失效。
     *
     * `handle` 是当前在途 `setTimeout` 的句柄 —— 见 {@see stopPoll()} 为何两者都要。
     */
    var poll = { seq: 0, attempt: 0, handle: null };

    /** 动作元信息索引：name → {requires_uid, description, params_hint} */
    var META = {};
    (function indexActions() {
        var list = cfg.actions || [];
        for (var i = 0; i < list.length; i++) {
            var a = list[i] || {};
            META[a.name] = a;
        }
    }());

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

    function clear(node) { while (node && node.firstChild) { node.removeChild(node.firstChild); } }

    function setText(id, text) {
        var node = $(id);
        if (node) { node.textContent = String(text); }
    }

    function hide(id) { var n = $(id); if (n) { n.hidden = true; } }
    function show(id) { var n = $(id); if (n) { n.hidden = false; } }

    function val(id) { var n = $(id); return n ? String(n.value) : ''; }
    function setVal(id, value) { var n = $(id); if (n) { n.value = String(value); } }

    function setNote(containerId, kind, text) {
        var box = $(containerId);
        if (!box) { return; }
        clear(box);
        if (text) { box.appendChild(el('div', 'note ' + (kind || 'info'), text)); }
    }

    function tag(level, text) { return el('span', 'tag ' + (level || 'mute'), text); }

    function num(value) { var n = Number(value); return isFinite(n) ? n : 0; }

    function fmtInt(value) { return Math.round(num(value)).toLocaleString('zh-CN'); }

    function msgOf(err) { return String(err && err.message ? err.message : err); }

    /** UTF-8 字节数（与 PHP `strlen()` 同口径；中文计 3 字节） */
    function utf8Len(value) {
        var s = String(value == null ? '' : value);
        if (typeof TextEncoder === 'function') { return new TextEncoder().encode(s).length; }
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

    /** 把 `result` 渲染成一行文本：对象/数组走 JSON，标量直接转字符串。 */
    function jsonOf(value) {
        if (value === null || value === undefined) { return '—'; }
        if (typeof value === 'object') {
            try { return JSON.stringify(value); } catch (e) { return '[无法序列化]'; }
        }
        return String(value);
    }

    // ------------------------------------------------------------------
    // 信封
    // ------------------------------------------------------------------

    /**
     * 与 push.js 同一实现：失败时把 `body.data` 挂到 error 上。
     *
     * ⚠ 本页对 `data` 尤其依赖：`invoke` 被拒时（502 + 5020）`data` 里带着
     * `state` / `http` / `code` / `msg`，没有它就只剩一句笼统的「未入队」。
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
    // 说明文案
    // ------------------------------------------------------------------

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
    // 动作下拉
    // ------------------------------------------------------------------

    /**
     * 填充动作下拉，并**显式选中首项**。
     *
     * ⚠ 最后那行 `node.value = names[0]` 不是多余的。浏览器确实会自动把 `<select>`
     * 的 `value` 设成第一个 `<option>`，但：
     *   ① 这不是可靠契约（假 DOM 宿主、SSR 预渲染、后续若改成 `multiple` 都不成立）；
     *   ② 漏掉它的表现是**首屏即静默不可用** —— `val('a-action')` 为 `''`，
     *      {@see validate()} 判「动作不在 HTTP 开放清单内」，用户点「调用」永远只得到
     *      一句拒绝、**一个请求都不发**，而控制台里没有任何报错可以追。
     *
     * 2026-09-23 实测踩过：`tests/Frontend/action_render_check.js` 的 A01 把这条钉住了。
     * 同页 `push.js` 采用另一种等价写法（首屏逐项 `setVal`）—— 那边四个下拉的默认值语义
     * 并不相同，通则不成立，故没有收进 `fillSelect()`；本页只有一个下拉，写在这里最不易漏。
     */
    function fillActions() {
        var node = $('a-action');
        if (!node) { return; }
        clear(node);
        var names = cfg.names || [];
        for (var i = 0; i < names.length; i++) {
            var opt = el('option', null, names[i]);
            opt.value = names[i];
            node.appendChild(opt);
        }
        if (names.length > 0) { node.value = String(names[0]); }
        syncActionMeta();
    }

    /** 切换动作时同步「说明 / 参数提示 / uid 是否必填」——全部取自后端下发的元信息。 */
    function syncActionMeta() {
        var name = val('a-action');
        var meta = META[name] || null;

        setNote('a-desc', 'info', meta ? (name + ' —— ' + meta.description) : '');
        setText('a-hint', meta ? meta.params_hint : '—');
        setText('a-uid-flag', meta && meta.requires_uid ? '必填（服务端 auth=true）' : '可选');
    }

    // ------------------------------------------------------------------
    // 校验
    // ------------------------------------------------------------------

    /**
     * 与 `app\controller\api\ActionController::invoke()` 同口径的前置校验。
     *
     * 服务端仍是唯一权威；这里只是把「必然失败」的提交提前拦下，省一次往返 + 一条审计噪音。
     *
     * @returns {string} 空串 = 通过；否则为拒绝原因
     */
    function validate(action, uid, deviceId, paramsRaw) {
        if ((cfg.names || []).indexOf(action) < 0) {
            return '动作不在 HTTP 开放清单内：' + action
                + '（清单外一律回落空串，服务端会以 400 拒绝；session 是 http=false，不在清单里）';
        }

        var meta = META[action] || null;
        if (meta && meta.requires_uid && uid === '') {
            return '动作 ' + action + ' 要求 uid —— 服务端声明 auth=true，缺 uid 会被回 401 + 4003，不会进入队列';
        }

        if (uid !== '') {
            if (utf8Len(uid) > num(cfg.id_max_len)) { return 'uid 超过 ' + cfg.id_max_len + ' 字节（UTF-8）'; }
            if (/[\u0000-\u001F\u007F]/.test(uid)) { return 'uid 含控制字符'; }
        }
        if (deviceId !== '') {
            if (utf8Len(deviceId) > num(cfg.id_max_len)) { return 'device_id 超过 ' + cfg.id_max_len + ' 字节（UTF-8）'; }
            if (/[\u0000-\u001F\u007F]/.test(deviceId)) { return 'device_id 含控制字符'; }
        }

        var trimmed = String(paramsRaw == null ? '' : paramsRaw).trim();
        if (trimmed !== '') {
            var parsed;
            try {
                parsed = JSON.parse(trimmed);
            } catch (e) {
                return 'params 不是合法 JSON：' + msgOf(e);
            }
            if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
                return 'params 必须是 JSON 对象（顶层不能是数组、字符串、数字或 null）';
            }
        }

        return '';
    }

    // ------------------------------------------------------------------
    // 调用
    // ------------------------------------------------------------------

    function invoke() {
        var action = val('a-action');
        var uid = val('a-uid').trim();
        var deviceId = val('a-device-id').trim();
        var paramsRaw = val('a-params').trim();

        var problem = validate(action, uid, deviceId, paramsRaw);
        if (problem !== '') {
            setNote('invoke-status', 'bad', problem);
            hide('invoke-result-wrap');
            return;
        }

        // 新的调用 = 作废上一条补查链（否则旧链会继续往新回执上写）
        stopPoll();
        setText('poll-count', '—');
        setNote('poll-status', '', '');

        var body = { action: action };
        if (uid !== '') { body.uid = uid; }
        if (deviceId !== '') { body.device_id = deviceId; }
        if (paramsRaw !== '') { body.params = paramsRaw; }

        var btn = $('btn-invoke');
        if (btn) { btn.disabled = true; }
        setNote('invoke-status', 'info', '正在调用…');

        requestJson(cfg.invoke_url, 'POST', body).then(
            function (data) {
                var d = data || {};
                renderOutcome('invoke', d);
                maybeStartPoll(d);
            },
            function (err) {
                // 502 + 5020（未入队）的 data 里带着完整回执，照常摊开
                if (err && err.data) {
                    renderOutcome('invoke', err.data);
                    // 被拒 / 连不上都是**终态**，没有回执可补查
                }
                setNote('invoke-status', 'bad', msgOf(err));
            }
        ).then(function () {
            if (btn) { btn.disabled = false; }
        });
    }

    // ------------------------------------------------------------------
    // 回执渲染
    // ------------------------------------------------------------------

    /**
     * @param {string} scope 'invoke' 或 'lookup'，决定写到哪一组 DOM
     * @param {object} data  服务端归一的回执（`ActionOutcome::of()` 的输出 + 附加字段）
     */
    function renderOutcome(scope, data) {
        var bodyId = scope === 'invoke' ? 'tb-invoke-result' : 'tb-lookup-result';
        var wrapId = scope === 'invoke' ? 'invoke-result-wrap' : 'lookup-result-wrap';
        var body = $(bodyId);
        if (!body) { return; }

        var state = String(data.state || '');
        var tone = String(data.tone || 'mute');
        var label = String(data.label || state || '（未知状态）');

        // `note` 由后端随响应下发；`outcome_notes` 是同一批文案的按状态索引，作兜底。
        var note = data.note ? String(data.note) : '';
        if (note === '' && cfg.outcome_notes && cfg.outcome_notes[state]) {
            note = String(cfg.outcome_notes[state]);
        }

        clear(body);

        // 首行是状态标签本身（最需要一眼看到的字段）
        var trHead = el('tr');
        trHead.appendChild(el('th', null, 'state'));
        var tdHead = el('td');
        tdHead.appendChild(tag(tone, label + '（' + state + '）'));
        trHead.appendChild(tdHead);
        body.appendChild(trHead);

        var rows = [
            ['HTTP', fmtInt(data.http)],
            ['业务码', fmtInt(data.code)],
            ['msg', data.msg || '—'],
            ['request_id', data.request_id || '—']
        ];
        for (var i = 0; i < rows.length; i++) {
            var tr = el('tr');
            tr.appendChild(el('th', null, rows[i][0]));
            tr.appendChild(el('td', null, String(rows[i][1])));
            body.appendChild(tr);
        }

        // 「能不能再发一次」是**独立于状态说明**的一条结论，也是本页最大的操作风险：
        // 调试页的用户习惯是「没看到结果就再点一次」，而 report 会重复计数、notify 会重复推送。
        //
        // ⚠ 判据是 `data.resend`（safe / unsafe / unknown），**不是** `data.retryable` ——
        //   后者回答的是「补查能不能重试」，两者在 `rejected` 上正好相反
        //   （`retryable=false`，但重发绝对安全）。用错就会把「最该重发的状态」显示成「不要重发」。
        //   文案（短标签 / 色调 / 解释）全部由后端下发，本脚本一个字都不编。
        var trResend = el('tr');
        trResend.appendChild(el('th', null, '重发'));
        var tdResend = el('td');
        var resendLabel = data.resend_label ? String(data.resend_label) : '';
        var resendNote = data.resend_note ? String(data.resend_note) : '';
        if (resendLabel !== '') {
            tdResend.appendChild(tag(String(data.resend_tone || 'mute'), resendLabel));
        }
        if (resendNote !== '') {
            if (resendLabel !== '') { tdResend.appendChild(document.createTextNode(' ')); }
            tdResend.appendChild(document.createTextNode(resendNote));
        }
        if (resendLabel === '' && resendNote === '') {
            // 后端没下发就不替它下结论 —— 给出的是「我不知道」，而不是一个猜测的安全/不安全
            tdResend.appendChild(document.createTextNode(
                '—（后端未下发重发判据；在拿到明确结论前，不要重复点击「调用」）'));
        }
        trResend.appendChild(tdResend);
        body.appendChild(trResend);

        var trResult = el('tr');
        trResult.appendChild(el('th', null, 'result'));
        trResult.appendChild(el('td', 'msg', jsonOf(data.result)));
        body.appendChild(trResult);

        if (note !== '') {
            var trNote = el('tr');
            trNote.appendChild(el('th', null, '说明'));
            trNote.appendChild(el('td', null, note));
            body.appendChild(trNote);
        }

        show(wrapId);

        // request_id 回填到补查输入框（用户可据此手动再查，或复制出去）
        var rid = String(data.request_id || '');
        if (scope === 'invoke' && rid !== '') {
            setVal('invoke-request-id', rid);
        }
    }

    /**
     * 「如果还是 pending 就启动有界退避」—— **只允许在初次调用之后调用一次**。
     *
     * ⚠ 这一处必须与 {@see renderOutcome} 分开，否则会形成**无限退避**：
     *   补查响应若仍是 pending，`renderOutcome` 会被再次调用；
     *   若退避的启动逻辑写在 `renderOutcome` 里，它就会 `startPoll()` 重置
     *   `poll.attempt` 与 `poll.seq`，于是
     *     ① 次数永远到不了上限（`poll.attempt` 每次归零）；
     *     ② 旧的定时链因 `seq` 失配而立即返回，新的链从 delays[0] 重新开始。
     *   表现为「退避延迟恒为第一个值、永不停止」—— 请求量翻几倍且只有 1000ms 间隔。
     */
    function maybeStartPoll(data) {
        var state = String((data || {}).state || '');
        var rid = String((data || {}).request_id || '');
        if (state !== 'pending') { return; }
        if (rid === '') {
            setNote('poll-status', 'warn',
                '状态是 pending 但响应里没有 request_id，无法自动补查 —— 请到主项目日志里核对。');
            return;
        }
        startPoll(rid);
    }

    // ------------------------------------------------------------------
    // 有界退避补查（本页唯一的 setTimeout）
    // ------------------------------------------------------------------

    /** 退避总时长（由后端下发的序列求和，前端不自行编数） */
    function totalPollMs() {
        var delays = cfg.poll_delays_ms || [];
        var sum = 0;
        for (var i = 0; i < delays.length; i++) { sum += num(delays[i]); }
        return sum;
    }

    /**
     * 作废当前补查链：会话号 +1（所有已排队的回调都会直接返回），
     * **并真的把在途定时器取消掉**。
     *
     * ⚠ 两者都要，缺一个就有可见缺陷：
     *   - 只靠 `seq` 时那条定时器**仍会触发**（回调第一行返回而已）。
     *     表现是「用户点了手动补查，一秒后又莫名多打一次请求」——
     *     结果被 `seq` 挡住了，但**请求确实发出去了**，在对方是生产环境时这是要付钱的；
     *   - 只靠 `clearTimeout` 时，已进入回调队列的旧链会带着过期的 attempt 计数继续跑。
     */
    function stopPoll() {
        poll.seq += 1;
        poll.attempt = 0;
        if (poll.handle !== null) {
            window.clearTimeout(poll.handle);
            poll.handle = null;
        }
    }

    function startPoll(requestId) {
        var seq = ++poll.seq;
        poll.attempt = 0;
        scheduleNext(requestId, seq);
    }

    function scheduleNext(requestId, seq) {
        if (seq !== poll.seq) { return; }

        var delays = cfg.poll_delays_ms || [];
        if (poll.attempt >= delays.length) {
            setText('poll-count', '补查次数已用尽（' + delays.length + ' 次）');
            setNote('poll-status', 'warn',
                '补查次数用尽（总等待 ' + totalPollMs() + ' ms），仍未取到终态。'
                + '回执只保留 ' + cfg.result_ttl + ' s，超出后服务端不再保留 —— 本页不会无限重试。'
                + '如需核对，请到主项目日志按 request_id 检索。');
            return;
        }

        var delay = num(delays[poll.attempt]);
        setText('poll-count', '第 ' + (poll.attempt + 1) + ' / ' + delays.length
            + ' 次补查计划于 ' + delay + ' ms 后发起（总等待上限 ' + totalPollMs() + ' ms）');

        poll.handle = window.setTimeout(function () {
            if (seq !== poll.seq) { return; }
            // 本回调已被执行 ⇒ 句柄失效，清掉以免 stopPoll() 去取消一个已经烧掉的定时器
            poll.handle = null;
            poll.attempt += 1;

            getJson(cfg.result_base + encodeURIComponent(requestId)).then(
                function (data) {
                    if (seq !== poll.seq) { return; }
                    var d = data || {};
                    renderOutcome('invoke', d);
                    if (String(d.state) === 'pending') {
                        scheduleNext(requestId, seq);
                    } else {
                        setText('poll-count', '已取到终态，停止补查（共 ' + poll.attempt + ' 次）');
                        setNote('poll-status', '', '');
                    }
                },
                function (err) {
                    if (seq !== poll.seq) { return; }
                    // 网络抖动可继续（仍受次数上限约束）；但要把失败如实说出来
                    setNote('poll-status', 'bad', '补查失败（第 ' + poll.attempt + ' 次）：' + msgOf(err));
                    scheduleNext(requestId, seq);
                }
            );
        }, delay);
    }

    /** 手动补查一次：先作废自动链，再立刻查一次（不启动退避）。 */
    function pollOnce() {
        var rid = val('invoke-request-id').trim();
        if (rid === '') {
            setNote('poll-status', 'bad', '还没有 request_id —— 先调用一次动作。');
            return;
        }
        stopPoll();
        setText('poll-count', '已停止自动补查（手动模式）');
        setNote('poll-status', 'info', '正在补查…');

        getJson(cfg.result_base + encodeURIComponent(rid)).then(
            function (data) {
                var d = data || {};
                renderOutcome('invoke', d);
                setNote('poll-status', String(d.state) === 'pending' ? 'warn' : 'info',
                    String(d.state) === 'pending'
                        ? '仍是 pending：服务端尚未算完。可再次手动补查，或重新发起调用。'
                        : '已取到回执。');
            },
            function (err) { setNote('poll-status', 'bad', msgOf(err)); }
        );
    }

    // ------------------------------------------------------------------
    // 按 request_id 补查（独立区块）
    // ------------------------------------------------------------------

    /**
     * 由 cfg 里的**带定界符**的正则字面量构造 RegExp。
     *
     * 后端下发的是 PHP 正则字面量（`/^[A-Za-z0-9_-]{1,64}$/`，真源
     * `ActionReply::REQUEST_ID_PATTERN`），直接把整串喂给 `new RegExp()` 会让
     * 两侧的 `/` 变成模式的一部分而**恒不匹配**。故显式剥离定界符与修饰符。
     *
     * 这样做的意义：前端**不复刻**这条正则 —— 服务端放宽/收紧时前端自动跟随。
     * 若在此处硬编码，服务端把 `{1,64}` 改成 `{1,128}` 后，前端会把合法 id 判成非法，
     * 让「补查」这个纯读操作无谓失败。
     *
     * @returns {RegExp|null} 解析失败返回 null（调用方退回「仅判空」的宽松策略）
     */
    function requestIdRe() {
        var body = String(cfg.request_id_pattern || '');
        var m = body.match(/^\/(.+)\/([gimsuy]*)$/);
        if (!m) { return null; }
        try {
            return new RegExp(m[1], m[2]);
        } catch (e) {
            return null;
        }
    }

    function lookup() {
        var rid = val('q-request-id').trim();
        if (rid === '') { setNote('lookup-status', 'bad', '请填写 request_id'); return; }

        var re = requestIdRe();
        if (re && !re.test(rid)) {
            setNote('lookup-status', 'bad',
                'request_id 形态非法。服务端口径：' + String(cfg.request_id_pattern)
                + ' —— 它比「16 位 hex」宽得多，别的调用方生成的 id 也合法，不要按 hex 收紧。');
            return;
        }

        setNote('lookup-status', 'info', '正在补查…');
        getJson(cfg.result_base + encodeURIComponent(rid)).then(
            function (data) {
                var d = data || {};
                renderOutcome('lookup', d);
                setNote('lookup-status', String(d.state) === 'expired' ? 'warn' : 'info',
                    String(d.state) === 'expired'
                        ? '未命中（expired）：服务端对该响应刻意不区分「仍在执行」与「已回收」，本页同样不替你猜。'
                        : '已取到回执。');
            },
            function (err) { setNote('lookup-status', 'bad', msgOf(err)); }
        );
    }

    // ------------------------------------------------------------------
    // 权限 → 控件显隐（**不是**权限边界）
    // ------------------------------------------------------------------

    function applyPerms() {
        var p = cfg.perms || {};
        if (!p.can_invoke) { hide('sec-invoke'); }
        if (!p.can_result) { hide('sec-lookup'); }
        if (!p.can_invoke && !p.can_result) { show('action-readonly'); }
    }

    // ------------------------------------------------------------------
    // 事件绑定与启动
    // ------------------------------------------------------------------

    function bind(id, handler) {
        var node = $(id);
        if (node) { node.addEventListener('click', handler); }
    }

    bind('btn-invoke', function () { invoke(); });
    bind('btn-invoke-reset', function () {
        stopPoll();
        setVal('a-uid', '');
        setVal('a-device-id', '');
        setVal('a-params', '');
        setNote('invoke-status', '', '');
        setNote('poll-status', '', '');
        setText('poll-count', '—');
        hide('invoke-result-wrap');
    });

    bind('btn-poll', function () { pollOnce(); });
    bind('btn-lookup', function () { lookup(); });
    bind('btn-lookup-reset', function () {
        setVal('q-request-id', '');
        setNote('lookup-status', '', '');
        hide('lookup-result-wrap');
    });

    var actionSelect = $('a-action');
    if (actionSelect) { actionSelect.addEventListener('change', function () { syncActionMeta(); }); }

    // ---- 首屏 ----

    applyPerms();
    renderNotes('action-notes', cfg.notes);

    fillActions();

    setText('a-id-max', cfg.id_max_len);
    setText('a-wait-ms', fmtInt(cfg.wait_ms));
    setText('a-result-ttl', fmtInt(cfg.result_ttl));
    setText('a-poll-total', fmtInt(totalPollMs()));

    // 本页打开时**不**自动调用任何动作（那会在生产连接上执行行为），
    // 也不自动补查 —— 全部由用户点击触发。
}());

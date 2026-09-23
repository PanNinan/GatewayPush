/**
 * GatewayPush 后台 · 动作调试页**渲染**校验（真跑 admin/public/static/action.js）
 *
 * ## 与 ActionContractTest 的分工
 *
 * `tests/Unit/ActionContractTest.php` 做**静态契约**（DOM id / cfg 键 / 定时器政策的文本锚点 /
 * 路由动词 / 写路径不碰 Redis / RBAC），跑在 PHP 侧。
 * 本文件做**运行期行为**：把 `action.js` 的 IIFE 整体真跑一遍，只替换
 * `document` / `window` / `fetch` 三个自由变量，再驱动交互并断言 DOM 与请求序列。
 *
 * ## 本文件最重要的一段：**有界退避真的会被推进、且真的会停**
 *
 * `/actions` 是全项目唯一允许 `setTimeout` 的「按需」页（`/dashboard` 是周期轮询，
 * `/sessions` 与 `/push` 一个定时器都不许有）。故契约测试只能证明「文本上看起来有界」，
 * 真正的证据必须来自这里 —— 假 `window.setTimeout` 只**入队**不执行，
 * 由本脚本按序列推进，于是可以逐项断言：
 *
 *   ① 延迟序列与后端下发的 `poll_delays_ms` **逐项相等**（不是前端自己编的节奏）；
 *   ② 取到终态后**不再有定时器**（链真的断了）；
 *   ③ 次数用尽后**不再有定时器**，并如实报「补查次数用尽」而不是无限重试。
 *
 * ⚠ 这三条正是为了抓「退避被重启」这类回归：若 `maybeStartPoll` 与 `renderOutcome`
 *   耦合在一起，补查响应仍是 pending 时会重置计数与会话号，
 *   表现为**延迟恒为第一个值且永不停止** —— 功能上「还能用」，但请求量翻几倍。
 *
 * ## 第二件本文件要负责的事：**回执里的「重发是否安全」必须两个方向都对**
 *
 * A04（done）/ A06（rejected）/ A07（transport）三条措辞断言是有意写成对立面的：
 * 这三态的 `retryable` 都是 `false`，但结论分别是「不要重发」「重发安全」「待确认」。
 * 任何用单个布尔值渲染该行的实现都**必然在其中一边说谎**（2026-09-23 就是这样：
 * 「未入队」这个最该重发的状态被显示成「不要重发」）。
 * A15 再从静态侧补一刀：去注释后的代码里不许再出现 `retryable`。
 *
 * 长文案（状态说明、重发解释）本文件只验「后端下发什么就显示什么」（用 `-BODY-NOTE`
 * 一类标记串）；**真正的措辞**由 PHP 侧 `ActionOutcomeTest` 对常量原文断言 ——
 * 两侧各测自己能看见的东西，比在一侧镜像另一侧的字串更不容易漂移。
 *
 * 运行：
 *     node admin/tests/Frontend/action_render_check.js
 * 环境变量 `ACTION_JS` / `ACTION_VIEW` 可替换被测文件路径（供变异测试）。
 * 退出码 0 = 全绿，1 = 有断言失败或脚本自身异常。
 */

'use strict';

const path = require('path');
const H = require(path.join(__dirname, 'lib', 'fake_env.js'));

const ROOT = path.join(__dirname, '..', '..');
const { code, view } = H.loadFiles('ACTION', 'action', ROOT);

const META = H.viewMeta(view);
const { check, checkRe, checkHas, checkNotHas, report } = H.createReporter();

/* ==================================================================
 * 配置（结构对齐 app/controller/ActionController.php 的注入）
 * ================================================================== */

const ACTIONS = [
  { name: 'echo', description: '原样回显，用于联通性验证与压测', requires_uid: true, params_hint: '无 schema —— 任意键值原样透传' },
  { name: 'report', description: '数据上报：按主题累加计数', requires_uid: true, params_hint: 'topic（必填）、count（1~10000）' },
  { name: 'subscribe', description: '订阅主题', requires_uid: true, params_hint: 'topic（必填）' },
  { name: 'unsubscribe', description: '取消订阅主题', requires_uid: true, params_hint: 'topic（必填）' },
  { name: 'topics', description: '查询本人已订阅的主题列表', requires_uid: true, params_hint: '无参数' },
  { name: 'notify', description: '请求服务端向本人推送一条消息', requires_uid: true, params_hint: 'value、msg_id、offline_mode' },
];

const CONFIG = {
  invoke_url: '/api/action',
  result_base: '/api/action/',
  actions: ACTIONS,
  names: ACTIONS.map((a) => a.name),
  wait_ms: 6000,
  result_ttl: 60,
  request_id_pattern: '/^[A-Za-z0-9_-]{1,64}$/',
  id_max_len: 128,
  poll_delays_ms: [1000, 2000, 4000, 8000, 8000],
  notes: [
    { key: 'scope', tone: 'info', text: 'SCOPE-NOTE' },
    { key: 'uid_semantics', tone: 'warn', text: 'UID-SEMANTICS-NOTE' },
    { key: 'uid_required', tone: 'info', text: 'UID-REQUIRED-NOTE' },
    { key: 'pending', tone: 'info', text: 'PENDING-NOTE' },
    { key: 'result_ttl', tone: 'info', text: 'RESULT-TTL-NOTE' },
  ],
  outcome_notes: {
    done: 'DONE-NOTE',
    failed: 'FAILED-NOTE',
    pending: 'PENDING-NOTE',
    rejected: 'REJECTED-NOTE',
    expired: 'EXPIRED-NOTE',
    transport: 'TRANSPORT-NOTE',
  },
  perms: { can_invoke: true, can_result: true },
};

function configWith(perms) {
  const c = JSON.parse(JSON.stringify(CONFIG));
  c.perms = Object.assign({}, CONFIG.perms, perms || {});
  return c;
}

/**
 * 服务端归一的回执（ActionOutcome::of() 的输出 + 附加字段）。
 *
 * ⚠ 这里刻意**同时**给出两套「重发 / 重试」判据，且它们在不同的状态上相反：
 *   - `retryable`：**补查**能不能重试 → 真源 `ActionOutcome::of()`，只对 `pending` 为真；
 *   - `resend` / `resend_label` / `resend_tone` / `resend_note`：**动作**能不能再发一次。
 *
 * 用 `retryable` 渲染「重发」行是本页已修掉的真缺陷（见 A04/A06/A07 的三条断言）——
 * 那三条断言的存在意义就是：**把 `retryable` 换回去会让它们立刻变红**。
 *
 * `state` / `retryable` / `note` / `audit_ok` 等长文案字段用标记串（`-BODY-NOTE`），
 * 只为证明「后端下发什么就显示什么」；而 `resend` 三件套用**真实值**，
 * 因为它承载的是「界面到底告诉用户能不能重发」这条可扫读结论 ——
 * 那三个短标签的最终真源在 PHP 侧（`ActionOutcome::RESEND_NOTES` 由
 * `ActionOutcomeTest` 钉住关键措辞），两侧各测自己能看见的东西。
 */
function outcome(state, over) {
  const base = {
    done: { code: 0, http: 200, label: '执行完成', tone: 'ok', result: { ok: true }, retryable: false },
    failed: { code: 4007, http: 200, label: '执行失败', tone: 'bad', result: {}, retryable: false },
    pending: { code: 0, http: 202, label: '超窗未完成', tone: 'warn', result: {}, retryable: true },
    rejected: { code: 4006, http: 400, label: '未入队', tone: 'bad', result: {}, retryable: false },
    expired: { code: 4004, http: 404, label: '补查未命中', tone: 'warn', result: {}, retryable: false },
    transport: { code: 10002, http: 0, label: '未取到响应', tone: 'bad', result: {}, retryable: false },
  }[state];

  // state → 重发判定（镜像 ActionOutcome::RESEND / resendLabel() / resendTone()）
  const resend = {
    done: ['unsafe', '不要重发', 'bad'],
    failed: ['unsafe', '不要重发', 'bad'],
    pending: ['unsafe', '不要重发', 'bad'],
    rejected: ['safe', '重发安全', 'ok'],
    expired: ['unknown', '待确认', 'warn'],
    transport: ['unknown', '待确认', 'warn'],
  }[state];

  return Object.assign({
    state,
    code: base.code,
    http: base.http,
    msg: state + '-msg',
    request_id: 'r-' + state,
    result: base.result,
    retryable: base.retryable,
    note: state.toUpperCase() + '-BODY-NOTE',
    resend: resend[0],
    resend_label: resend[1],
    resend_tone: resend[2],
    resend_note: state.toUpperCase() + '-RESEND-NOTE',
    label: base.label,
    tone: base.tone,
    audit_ok: true,
    wait_ms: CONFIG.wait_ms,
    result_ttl: CONFIG.result_ttl,
    notes: ['BODY-NOTE-1'],
  }, over || {});
}

function ok(data) { return { code: 0, msg: 'ok', data }; }
function fail(code, msg, data) { return { code, msg, data }; }

/* ==================================================================
 * 场景
 * ================================================================== */

async function main() {
  if (!H.assertCompiles(code, 'action.js')) { return report('语法编译失败'); }
  check('A00 视图里声明了配置注入点 #action-config', META.ids.indexOf('action-config') >= 0, true);

  /* ---------------- A01 首屏：零请求、零定时器、动作下拉 ---------------- */

  const built = H.createEnv({ meta: META, config: CONFIG, pathname: '/actions' });
  const env = built.env;
  await H.boot(code, built, 'action-config', CONFIG);

  check('A01 首屏不发任何请求（不会自动在真实客户端上执行动作）', env.requestLog.length, 0);
  check('A01 首屏不得有任何定时器', env.rec.timerCalls, []);
  check('A01 动作下拉 6 项（HTTP 开放的全部）', env.el('a-action').childNodes.length, 6);
  checkNotHas('A01 下拉里没有 session（http=false）', env.text('a-action'), 'session');
  check('A01 默认选中第一个动作 echo', env.el('a-action').value, 'echo');
  checkHas('A01 动作说明来自后端', env.text('a-desc'), '原样回显');
  checkHas('A01 参数提示来自后端', env.text('a-hint'), '任意键值原样透传');
  checkHas('A01 uid 必填标记（auth=true）', env.text('a-uid-flag'), '必填');
  check('A01 五条说明全部渲染', env.countClass('action-notes', '.note'), 5);
  check('A01 其中 1 条是 warn（tone 由后端决定）', env.countClass('action-notes', '.note.warn'), 1);
  checkHas('A01 静态提示含同步等待窗', env.text('a-wait-ms'), '6,000');
  checkHas('A01 静态提示含回执保留', env.text('a-result-ttl'), '60');
  checkHas('A01 静态提示含退避总时长（由 cfg 求和）', env.text('a-poll-total'), '23,000');

  // 切换动作 → 说明 / 提示 / uid 标记同步
  env.setSelect('a-action', 'topics');
  checkHas('A02 切换动作更新说明', env.text('a-desc'), '查询本人已订阅的主题列表');
  checkHas('A02 切换动作更新参数提示', env.text('a-hint'), '无参数');

  /* ---------------- A03 调用前的拒绝路径：不得发请求 ---------------- */

  function reset() {
    env.put('a-uid', '');
    env.put('a-device-id', '');
    env.put('a-params', '');
  }

  env.setSelect('a-action', 'echo');
  const before = env.requestLog.length;

  reset();
  env.click('btn-invoke');
  await H.flush();
  checkHas('A03 缺 uid → 拒绝并指明 4003', env.text('invoke-status'), '4003');
  check('A03 缺 uid → 不发请求', env.requestLog.length, before);
  check('A03 拒绝时回执区保持隐藏', env.isHidden('invoke-result-wrap'), true);

  reset();
  env.put('a-uid', 'uid-1');
  env.put('a-params', '{bad');
  env.click('btn-invoke');
  await H.flush();
  checkHas('A03 params 非法 JSON → 拒绝', env.text('invoke-status'), '不是合法 JSON');
  check('A03 params 非法 → 不发请求', env.requestLog.length, before);

  reset();
  env.put('a-uid', 'uid-1');
  env.put('a-params', '[1,2]');
  env.click('btn-invoke');
  await H.flush();
  checkHas('A03 params 顶层是数组 → 拒绝', env.text('invoke-status'), '顶层不能是数组');
  check('A03 params 数组 → 不发请求', env.requestLog.length, before);

  reset();
  env.put('a-uid', 'x'.repeat(129));
  env.click('btn-invoke');
  await H.flush();
  checkHas('A03 uid 超 128 字节 → 拒绝', env.text('invoke-status'), '128 字节');
  check('A03 uid 超长 → 不发请求', env.requestLog.length, before);

  /* ---------------- A04 成功调用：POST body 与回执 ---------------- */

  reset();
  env.put('a-uid', 'uid-1001');
  env.put('a-params', '{"topic":"demo"}');
  env.click('btn-invoke');
  await H.flush();

  const req = env.requestLog[env.requestLog.length - 1];
  check('A04 调用是 POST', req.method, 'POST');
  check('A04 打到 cfg.invoke_url', req.url, CONFIG.invoke_url);
  const body = JSON.parse(req.body);
  check('A04 body.action', body.action, 'echo');
  check('A04 body.uid', body.uid, 'uid-1001');
  check('A04 body.params 发原文（保留服务端的精确报错）', body.params, '{"topic":"demo"}');
  check('A04 空的 device_id 不塞进请求体', Object.prototype.hasOwnProperty.call(body, 'device_id'), false);

  await env.respond(CONFIG.invoke_url, ok(outcome('done')));

  check('A04 终态（done）后回执区可见', env.isHidden('invoke-result-wrap'), false);
  checkHas('A04 回执展示 state 与中文标签', env.text('tb-invoke-result'), '执行完成（done）');
  checkHas('A04 回执含 request_id', env.text('tb-invoke-result'), 'r-done');
  checkHas('A04 回执含 result（JSON 化）', env.text('tb-invoke-result'), '{"ok":true}');
  checkHas('A04 回执含后端下发的 note', env.text('tb-invoke-result'), 'DONE-BODY-NOTE');
  // ⚠ 本条是 2026-09-23 那处真缺陷的回归护栏：done 与 rejected 的 `retryable` 都是 false，
  //   但措辞必须完全相反。任何用 `retryable` 单布尔渲染该行的实现，都会在其中一边说错话。
  checkHas('A04 重发判定：done 明确「不要重发」', env.text('tb-invoke-result'), '不要重发');
  checkHas('A04 重发解释来自后端（原样透传，前端不编词）', env.text('tb-invoke-result'), 'DONE-RESEND-NOTE');
  check('A04 request_id 回填到补查框', env.el('invoke-request-id').value, 'r-done');
  check('A04 终态不得启动任何定时器', env.rec.timerCalls, []);
  check('A04 状态栏提示已取到回执', env.text('poll-count'), '—');

  /* ---------------- A05 failed：HTTP 200 但 state=failed ---------------- */

  reset();
  env.put('a-uid', 'uid-1001');
  env.click('btn-invoke');
  await H.flush();
  await env.respond(CONFIG.invoke_url, ok(outcome('failed')));
  checkHas('A05 执行失败被如实呈现（HTTP 虽是 200）', env.text('tb-invoke-result'), '执行失败（failed）');
  checkHas('A05 回执里的 HTTP 仍显示 200', env.text('tb-invoke-result'), '200');
  checkHas('A05 失败状态的说明来自后端', env.text('tb-invoke-result'), 'FAILED-BODY-NOTE');
  check('A05 failed 是终态，不起退避', env.rec.timerCalls, []);

  /* ---------------- A06 rejected：502 + 5020 ---------------- */

  reset();
  env.put('a-uid', 'uid-1001');
  env.click('btn-invoke');
  await H.flush();
  await env.respond(CONFIG.invoke_url, fail(5020, '动作未入队：未知动作', outcome('rejected')), { status: 502 });
  checkHas('A06 未入队 → 状态栏报错', env.text('invoke-status'), '502');
  checkHas('A06 未入队 → 回执仍摊开', env.text('tb-invoke-result'), '未入队（rejected）');
  // ★ 最该重发的状态：动作从未入队。这里必须与 A04 的「不要重发」明显对立 ——
  //   两者的 `retryable` 都是 false，区别只能来自后端下发的 `resend`。
  checkHas('A06 未入队 → 明示重发安全', env.text('tb-invoke-result'), '重发安全');
  checkHas('A06 重发解释来自后端（原样透传）', env.text('tb-invoke-result'), 'REJECTED-RESEND-NOTE');
  check('A06 未入队是终态，不起退避', env.rec.timerCalls, []);

  /* ---------------- A07 transport：连不上 ---------------- */

  reset();
  env.put('a-uid', 'uid-1001');
  env.click('btn-invoke');
  await H.flush();
  await env.respond(CONFIG.invoke_url, fail(5020, '动作未入队：未取到响应', outcome('transport')), { status: 502 });
  // ⚠ 完整措辞「不要自动重发」的真源在 PHP 侧（`ActionOutcome::RESEND_NOTES[transport]`，
  //   由 `ActionOutcomeTest` 钉住）。本文件能看见的只有后端下发的短标签与解释原文 ——
  //   这里断言的是**判定方向**（绝不能是「重发安全」）与原样透传。
  checkHas('A07 连不上 → 重发判定为「待确认」（而非「安全」）', env.text('tb-invoke-result'), '待确认');
  checkHas('A07 重发解释来自后端（原样透传）', env.text('tb-invoke-result'), 'TRANSPORT-RESEND-NOTE');
  checkNotHas('A07 连不上 → 绝不可显示为「重发安全」', env.text('tb-invoke-result'), '重发安全');
  check('A07 transport 不起退避', env.rec.timerCalls, []);

  /* ---------------- A08 pending：有界退避的完整推进 ---------------- */

  const builtP = H.createEnv({ meta: META, config: CONFIG, pathname: '/actions' });
  const envP = builtP.env;
  await H.boot(code, builtP, 'action-config', CONFIG);
  envP.put('a-uid', 'uid-1001');
  envP.click('btn-invoke');
  await H.flush();

  await envP.respond(CONFIG.invoke_url, ok(outcome('pending')), { status: 202 });
  check('A08 202/pending 是**成功**响应（不是失败）', envP.el('invoke-result-wrap').hidden, false);
  checkHas('A08 回执标注超窗未完成', envP.text('tb-invoke-result'), '超窗未完成（pending）');
  checkHas('A08 note 明示「不是失败」', envP.text('tb-invoke-result'), 'PENDING-BODY-NOTE');
  check('A08 启动退避：恰好 1 个在途定时器', envP.rec.timers.length, 1);
  check('A08 第一个延迟取自 cfg.poll_delays_ms[0]', envP.nextDelayMs(), CONFIG.poll_delays_ms[0]);

  // 逐项推进退避，每次都让补查继续返回 pending —— 用来证明「不会重启、终会耗尽」
  const delays = [];
  const pollUrls = [];
  let guard = 0;
  while (envP.hasTimers() && guard < 20) {
    guard += 1;
    delays.push(envP.nextDelayMs());
    await envP.runNextTimer();
    const url = envP.pendingUrls()[envP.pendingUrls().length - 1];
    pollUrls.push(url);
    await envP.respond(url, ok(outcome('pending')));
  }

  check('A08 退避延迟序列与后端下发的完全一致', delays, CONFIG.poll_delays_ms);
  check('A08 补查次数 = 序列长度（不多不少）', pollUrls.length, CONFIG.poll_delays_ms.length);
  check('A08 补查打的是 result_base + request_id', pollUrls[0], CONFIG.result_base + 'r-pending');
  checkRe('A08 补查全部是 GET', envP.requestLog.filter((r) => r.url.indexOf(CONFIG.result_base) === 0)
    .map((r) => r.method).join(','), /^GET(,GET)*$/);
  check('A08 次数用尽后**不再**有定时器（链真的断了）', envP.rec.timers.length, 0);
  check('A08 定时器总调用次数 = 序列长度', envP.rec.timerCalls.length, CONFIG.poll_delays_ms.length);
  checkHas('A08 如实报告次数用尽', envP.text('poll-status'), '补查次数用尽');
  checkHas('A08 报告里给出总等待与窗口', envP.text('poll-status'), '总等待');
  checkHas('A08 计数文案给出已用次数', envP.text('poll-count'), '5 次');

  /* ---------------- A09 pending 中途取到终态：链必须停 ---------------- */

  const builtE = H.createEnv({ meta: META, config: CONFIG, pathname: '/actions' });
  const envE = builtE.env;
  await H.boot(code, builtE, 'action-config', CONFIG);
  envE.put('a-uid', 'uid-1001');
  envE.click('btn-invoke');
  await H.flush();
  await envE.respond(CONFIG.invoke_url, ok(outcome('pending')), { status: 202 });

  const delaysE = [];
  await envE.runNextTimer();
  delaysE.push(1000);
  await envE.respond(CONFIG.result_base + 'r-pending', ok(outcome('pending')));
  await envE.runNextTimer();
  delaysE.push(2000);
  await envE.respond(CONFIG.result_base + 'r-pending', ok(outcome('done')));

  check('A09 取到终态前的延迟序列', delaysE, [1000, 2000]);
  check('A09 取到终态后链立即停止（无残留定时器）', envE.rec.timers.length, 0);
  check('A09 定时器总次数停在 2', envE.rec.timerCalls.length, 2);
  checkHas('A09 计数文案说明已取到终态', envE.text('poll-count'), '已取到终态，停止补查');
  checkHas('A09 回执已更新为终态', envE.text('tb-invoke-result'), '执行完成（done）');

  /* ---------------- A10 手动补查：一次即停，不起退避 ---------------- */

  const builtM = H.createEnv({ meta: META, config: CONFIG, pathname: '/actions' });
  const envM = builtM.env;
  await H.boot(code, builtM, 'action-config', CONFIG);
  envM.put('a-uid', 'uid-1001');
  envM.click('btn-invoke');
  await H.flush();
  await envM.respond(CONFIG.invoke_url, ok(outcome('pending')), { status: 202 });
  check('A10 自动退避已排队 1 个定时器', envM.rec.timers.length, 1);

  envM.click('btn-poll');
  await H.flush();
  check('A10 手动补查作废了自动链（定时器被清空）', envM.rec.timers.length, 0);
  // ★ 「作废」必须是真的取消，不能只靠会话号把回调挡在门口 ——
  //   只挡不取消时，请求**仍然会发出去**（只是结果被丢弃），
  //   在对方是生产环境时这是要付钱的。见 fake_env 模块头第 2 点。
  check('A10 作废走的是 clearTimeout，而非仅靠 poll.seq 挡回调', envM.rec.clearCalls.length, 1);
  checkHas('A10 计数文案说明已切手动', envM.text('poll-count'), '手动模式');
  check('A10 手动补查只发一次请求', envM.requestLog.filter((r) => r.url.indexOf(CONFIG.result_base) === 0).length, 1);

  await envM.respond(CONFIG.result_base + 'r-pending', ok(outcome('pending')));
  checkHas('A10 仍是 pending 时如实提示，而不是继续轮询', envM.text('poll-status'), '仍是 pending');
  check('A10 手动补查不产生新定时器', envM.rec.timerCalls.length, 1);

  /* ---------------- A11 按 request_id 补查 ---------------- */

  const beforeLookup = envM.requestLog.length;
  envM.put('q-request-id', 'bad id!');
  envM.click('btn-lookup');
  await H.flush();
  checkHas('A11 形态非法 → 拒绝，并回显服务端口径', envM.text('lookup-status'), '形态非法');
  checkHas('A11 拒绝文案里带上 cfg 的正则（不复刻）', envM.text('lookup-status'), CONFIG.request_id_pattern);
  check('A11 形态非法 → 不发请求', envM.requestLog.length, beforeLookup);

  envM.put('q-request-id', 'A_b-9');
  envM.click('btn-lookup');
  await H.flush();
  check('A11 合法 id → GET', envM.requestLog[envM.requestLog.length - 1].method + ' ' + envM.requestLog[envM.requestLog.length - 1].url,
    'GET ' + CONFIG.result_base + 'A_b-9');

  // 后端对补查**恒返回 200**（未命中 = state=expired，刻意不用 404 以免打断前端轮询）
  await envM.respond(CONFIG.result_base + 'A_b-9', ok(outcome('expired')));
  check('A11 expired 时回执区可见', envM.isHidden('lookup-result-wrap'), false);
  checkHas('A11 回执标注补查未命中', envM.text('tb-lookup-result'), '补查未命中（expired）');
  checkHas('A11 明确不替用户猜「仍在执行 / 已回收」', envM.text('lookup-status'), '刻意不区分');
  checkHas('A11 补查不落审计（audited=false 由后端给，界面不宣称已审计）',
    envM.text('tb-lookup-result'), 'EXPIRED-BODY-NOTE');
  check('A11 独立补查区不起任何定时器', envM.rec.timerCalls.length, 1);

  /* ---------------- A12 权限 ---------------- */

  const builtRO = H.createEnv({ meta: META, config: configWith({ can_invoke: false, can_result: false }), pathname: '/actions' });
  await H.boot(code, builtRO, 'action-config', configWith({ can_invoke: false, can_result: false }));
  check('A12 无 invoke 权限 → 调用区隐藏', builtRO.env.isHidden('sec-invoke'), true);
  check('A12 无 result 权限 → 补查区隐藏', builtRO.env.isHidden('sec-lookup'), true);
  check('A12 两者皆无 → 显示说明块', builtRO.env.isHidden('action-readonly'), false);

  const builtHalf = H.createEnv({ meta: META, config: configWith({ can_invoke: false }), pathname: '/actions' });
  await H.boot(code, builtHalf, 'action-config', configWith({ can_invoke: false }));
  check('A12 只有补查权限时：调用区隐藏、补查区可见', [
    builtHalf.env.isHidden('sec-invoke'), builtHalf.env.isHidden('sec-lookup'), builtHalf.env.isHidden('action-readonly'),
  ], [true, false, true]);

  /* ---------------- A13 XSS 纪律 ---------------- */

  const EVIL = '"><img src=x onerror=alert(1)>';
  const builtX = H.createEnv({ meta: META, config: CONFIG, pathname: '/actions' });
  const envX = builtX.env;
  await H.boot(code, builtX, 'action-config', CONFIG);
  envX.put('a-uid', EVIL);
  // ⚠ 必须用 JSON.stringify 拼，不能用字符串拼接：EVIL 以 `"` 开头，
  //   手拼会得到 `{"t":""<img …` —— **非法 JSON**，前端因此拒发，
  //   下面三条断言全部对着一个空页面通过（本用例正是这样静默空转过一次）。
  envX.put('a-params', JSON.stringify({ t: EVIL }));
  envX.click('btn-invoke');
  await H.flush();
  check('A13 恶意串场景确实发出了请求（否则下面三条全是空断言）', envX.requestLog.length, 1);
  await envX.respond(CONFIG.invoke_url, ok(outcome('done', {
    result: { echo: EVIL, nested: [EVIL] },
    msg: EVIL,
    action: EVIL,
    params_hint: EVIL,
  })));

  check('A13 全程零 innerHTML 赋值', envX.rec.innerHTMLWrites, []);
  check('A13 未创建任何 img/script/iframe 元素（恶意标签不被解析）',
    envX.rec.elementTags.filter((t) => ['img', 'script', 'iframe', 'object', 'embed'].indexOf(t) >= 0), []);
  checkHas('A13 恶意串原样落在回执里（走 textContent 而非被吞掉）', envX.text('tb-invoke-result'), EVIL);

  /* ---------------- A14 运行期自检 ---------------- */

  check('A14 各场景的定时器总数（本页只允许有界退避）', [
    env.rec.timerCalls.length, envP.rec.timerCalls.length, envE.rec.timerCalls.length,
  ], [0, CONFIG.poll_delays_ms.length, 2]);
  check('A14 每处 respond / click 都命中了预期的请求与按钮',
    env.rec.missingResponses.concat(env.rec.missingClicks,
      envP.rec.missingResponses, envP.rec.missingClicks,
      envE.rec.missingResponses, envE.rec.missingClicks,
      envM.rec.missingResponses, envM.rec.missingClicks,
      envX.rec.missingResponses, envX.rec.missingClicks), []);

  /* ---------------- A15 静态回归护栏：判据不许退回 `retryable` ---------------- */

  // 去注释后再断言：`retryable` 在注释里作为「不要这么做」的反例被提及是**应该的**，
  // 但代码里一次都不许出现 —— 一旦有人把它接回渲染，A04/A06/A07 的措辞断言会同时变红。
  const bare = code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
  checkNotHas('A15 代码里不得出现 retryable（补查可重试 ≠ 重发安全）', bare, 'retryable');
  checkHas('A15 重发判定由后端下发的 resend_note 承载', bare, 'data.resend_note');
  checkHas('A15 重发徽标的色调也由后端下发', bare, 'data.resend_tone');
  checkNotHas('A15 代码里不得出现 clearInterval（本页只有有界退避）', bare, 'clearInterval');
}

main().then(() => {
  process.exit(report(null) ? 1 : 0);
}).catch((e) => {
  const stack = (e && e.stack ? e.stack : String(e)).split('\n').slice(0, 3).join(' | ');
  process.exit(report(stack) ? 1 : 0);
});

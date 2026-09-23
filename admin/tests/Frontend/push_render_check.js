/**
 * GatewayPush 后台 · 推送管理页**渲染**校验（真跑 admin/public/static/push.js）
 *
 * ## 与 PushContractTest 的分工
 *
 * `tests/Unit/PushContractTest.php` 做**静态契约**（DOM id / cfg 键 / 无定时器 / 无 innerHTML /
 * 路由动词 / 写路径不碰 Redis / RBAC），跑在 PHP 侧、看得见文本。
 * 本文件做**运行期行为**：把 `push.js` 的 IIFE **整体真跑一遍**，只把三个自由变量
 * `document` / `window` / `fetch` 换成受控替身，再由测试驱动交互、
 * 断言真实产出的 DOM 树与请求序列。
 *
 * 两者互补：契约测试防「改名漂移」，本文件防「逻辑写错但名字都对」。
 *
 * ## 环境由 `lib/fake_env.js` 提供（P3 起的共用约定）
 *
 * 假 DOM 的构造、`hidden` 按视图初始化、`confirm` 可注入、`setTimeout` 记账 —— 都在那里。
 * 本文件只写断言。
 *
 * ## 本文件覆盖的**关键差异点**（相对 P2 的 session_render_check）
 *
 * 1. **载荷字节口径**：必须按「紧凑重新编码后」的字节数计，而不是 textarea 原文 ——
 *    否则一个格式化的多行 JSON 会被误拦（它的原文比服务端判据大得多）。
 * 2. **写请求**：`POST /api/push` 的 body 结构、502 的失败呈现。
 * 3. **筛选条件不得被静默丢弃**：非法 `target` 必须在**前端**被拒（服务端会悄悄丢掉它）。
 * 4. **权限 → 控件显隐**：只读角色的页面形态。
 * 5. **仍然零定时器**：与 `/sessions` 同级约束。
 *
 * 运行：
 *     node admin/tests/Frontend/push_render_check.js
 * 环境变量 `PUSH_JS` / `PUSH_VIEW` 可替换被测文件路径（供变异测试）。
 * 退出码 0 = 全绿，1 = 有断言失败或脚本自身异常。
 */

'use strict';

const path = require('path');
const H = require(path.join(__dirname, 'lib', 'fake_env.js'));

const ROOT = path.join(__dirname, '..', '..');
const { code, view } = H.loadFiles('PUSH', 'push', ROOT);

const META = H.viewMeta(view);
const { check, checkRe, checkHas, checkNotHas, report } = H.createReporter();

/* ==================================================================
 * 配置与假响应体（结构对齐后端真实返回）
 * ================================================================== */

const CONFIG = {
  create_url: '/api/push',
  history_url: '/api/push/history',
  templates_url: '/api/push/templates',
  template_delete_base: '/api/push/templates/',
  target_types: ['uid', 'device', 'client'],
  target_labels: { uid: '按用户 —— 该 uid 名下的全部在线连接', device: '按设备', client: '按连接' },
  offline_modes: ['', 'drop', 'queue'],
  offline_labels: { '': '服务端默认（本机为 queue）', drop: '丢弃', queue: '离线缓存' },
  msg_id_len: 16,
  msg_id_max_len: 64,
  payload_max: 4096,
  template_name_max_len: 64,
  template_remark_max_len: 255,
  size_options: [20, 50, 100],
  size_max: 100,
  page_size: 20,
  statuses: ['accepted', 'rejected'],
  id_max_len: 128,
  notes: {
    global: [
      { key: 'no_topic', tone: 'warn', text: 'NO-TOPIC-NOTE' },
      { key: 'accepted', tone: 'info', text: 'ACCEPTED-NOTE' },
      { key: 'dedup', tone: 'info', text: 'DEDUP-NOTE' },
      { key: 'payload_drop', tone: 'warn', text: 'PAYLOAD-DROP-NOTE' },
    ],
    history: [{ key: 'record', tone: 'info', text: 'RECORD-NOTE' }],
    templates: [{ key: 'template', tone: 'info', text: 'TEMPLATE-NOTE' }],
  },
  perms: { can_create: true, can_template_save: true, can_template_delete: true },
  dashboard_url: 'http://127.0.0.1:8291',
};

/** 深拷贝配置并覆盖权限（用于只读角色的场景） */
function configWith(perms) {
  const c = JSON.parse(JSON.stringify(CONFIG));
  c.perms = Object.assign({}, CONFIG.perms, perms || {});
  return c;
}

function historyPayload(over) {
  const items = (over && over.items) || [{
    id: 11,
    request_id: 'a1b2c3d4e5f60718',
    target_type: 'uid',
    target: 'uid-1001',
    payload: '{"hello":"world"}',
    payload_bytes: 17,
    msg_id: '00112233445566aa',
    offline_mode: 'queue',
    http_status: 200,
    code: 0,
    msg: 'accepted',
    status: 'accepted',
    operator_id: 1,
    created_at: '2026-09-23 10:11:12',
    target_label: '按用户 —— 该 uid 名下的全部在线连接',
    offline_label: '离线缓存',
  }];
  return {
    code: 0,
    msg: 'ok',
    data: {
      items,
      total: (over && over.total) !== undefined ? over.total : items.length,
      page: (over && over.page) || 1,
      size: (over && over.size) || 20,
      pages: (over && over.pages) !== undefined ? over.pages : (items.length ? 1 : 0),
      filters: {},
      summary: { total: 7, accepted: 6, rejected: 1, today: 2 },
      statuses: CONFIG.statuses,
      size_max: 100,
      record_note: 'RECORD-BODY-NOTE',
    },
  };
}

function templatePayload(over) {
  const items = (over && over.items) || [{
    id: 3,
    name: 'TPL-A',
    target_type: 'device',
    target_label: '按设备',
    payload: { a: 1 },
    payload_raw: '{"a":1}',
    payload_bytes: 7,
    offline_mode: 'drop',
    offline_label: '丢弃',
    remark: 'REMARK-A',
    created_by: 1,
    created_at: '2026-09-20 09:00:00',
    updated_at: '2026-09-21 09:00:00',
  }];
  return {
    code: 0,
    msg: 'ok',
    data: {
      items,
      total: items.length,
      list_max: 200,
      note: 'TEMPLATE-BODY-NOTE',
    },
  };
}

/* ==================================================================
 * 场景
 * ================================================================== */

async function main() {
  if (!H.assertCompiles(code, 'push.js')) { return report('语法编译失败'); }
  check('P00 视图里声明了配置注入点 #push-config', META.ids.indexOf('push-config') >= 0, true);

  /* ---------------- P01 首屏：两次按需取数、零定时器 ---------------- */

  const built = H.createEnv({
    meta: META,
    config: CONFIG,
    pathname: '/push',
    initValues: { 'h-size': CONFIG.page_size },
  });
  const env = built.env;

  await H.boot(code, built, 'push-config', CONFIG);

  check('P01 首屏恰好发两次请求（历史 + 模板）', env.requestLog.length, 2);
  checkRe('P01 第一次是历史（GET）', env.requestLog[0].method + ' ' + env.requestLog[0].url,
    /^GET \/api\/push\/history\?page=1&size=20$/);
  check('P01 第二次是模板列表（GET）', env.requestLog[1].method + ' ' + env.requestLog[1].url,
    'GET /api/push/templates');
  check('P01 首屏不得有任何定时器', env.rec.timerCalls, []);

  // 表单初值
  check('P01 目标类型默认取第一个（uid）', env.el('f-target-type').value, 'uid');
  check('P01 离线策略默认取空串（= 服务端默认，是合法取值而非未选）', env.el('f-offline-mode').value, '');
  checkHas('P01 msg_id 提示含自动生成长度 16', env.text('f-msg-id-hint'), '16');
  checkHas('P01 msg_id 提示含手填上限 64', env.text('f-msg-id-hint'), '64');
  check('P01 枚举下拉：目标类型 3 项', env.el('f-target-type').childNodes.length, 3);
  check('P01 枚举下拉：离线策略 3 项（含空串）', env.el('f-offline-mode').childNodes.length, 3);
  check('P01 历史筛选目标类型 4 项（全部 + 3）', env.el('h-target-type').childNodes.length, 4);

  // 说明文案：后端下发，且 tone 由后端给
  check('P01 全局说明 4 条', env.countClass('push-notes', '.note'), 4);
  check('P01 其中 2 条是 warn（tone 由后端决定）', env.countClass('push-notes', '.note.warn'), 2);
  check('P01 历史说明 1 条', env.countClass('history-note', '.note'), 1);
  check('P01 模板说明 1 条', env.countClass('tpl-note', '.note'), 1);
  checkHas('P01 全局说明用的是后端文本', env.text('push-notes'), 'PAYLOAD-DROP-NOTE');
  checkHas('P01 历史说明用的是后端文本', env.text('history-note'), 'RECORD-NOTE');

  // 字节计数初值
  check('P01 空载荷按服务端的 [] 计 2 字节', env.text('f-payload-bytes'), '2');
  check('P01 载荷上限按 zh-CN 千分位显示', env.text('f-payload-max'), '4,096');

  /* ---------------- P02 载荷字节：必须是「紧凑重新编码」口径 ---------------- */

  // 原文含换行与缩进；服务端判据是 Message::encode 后的紧凑 JSON
  //   {"a":"中文"} → { " a " : " 中文 " } = 1+3+1+1+6+1+1 = 14 字节
  env.setValue('f-payload', '{\n    "a": "中文"\n}');
  check('P02 格式化 JSON 的字节数按紧凑编码计（不是原文）', env.text('f-payload-bytes'), '14');
  checkNotHas('P02 不为超限（紧凑后才 14 字节）', env.text('f-payload-bytes'), 'bytes-over');

  // 超限：字符数远小于上限，但字节数超（中文 3 字节）
  env.setValue('f-payload', '{"a":"' + '中'.repeat(1400) + '"}');
  check('P02 超限时计数元素被打上 bytes-over', env.el('f-payload-bytes').className, 'bytes-over');

  // 非法 JSON：计数退回原文长度，并给出原因
  env.setValue('f-payload', '{not json');
  check('P02 非法 JSON 时计数退回原文字节数', env.text('f-payload-bytes'), '9');
  check('P02 数组载荷会刷新失败（提交时另测）', env.el('f-payload-bytes').className, '');

  /* ---------------- P03 提交前的拒绝路径：不得发请求 ---------------- */

  function freshForm() {
    env.put('f-target', '');
    env.put('f-msg-id', '');
    env.put('f-payload', '');
  }

  const before = env.requestLog.length;

  freshForm();
  env.setValue('f-payload', '{"a":1}');
  env.click('btn-push-send');
  await H.flush();
  checkHas('P03 目标值为空 → 拒绝并提示必填', env.text('push-status'), '目标值必填');
  check('P03 目标值为空 → 不发请求', env.requestLog.length, before);
  check('P03 拒绝时回执区保持隐藏', env.isHidden('push-result-wrap'), true);

  freshForm();
  env.put('f-target', 'uid-1001');
  env.setValue('f-payload', '{not json');
  env.click('btn-push-send');
  await H.flush();
  checkHas('P03 非法 JSON → 拒绝并说明原因', env.text('push-status'), '不是合法 JSON');
  check('P03 非法 JSON → 不发请求', env.requestLog.length, before);

  freshForm();
  env.put('f-target', 'uid-1001');
  env.setValue('f-payload', '[1,2,3]');
  env.click('btn-push-send');
  await H.flush();
  checkHas('P03 顶层是数组 → 拒绝', env.text('push-status'), '顶层不能是数组');
  check('P03 数组载荷 → 不发请求', env.requestLog.length, before);

  freshForm();
  env.put('f-target', 'x'.repeat(129));
  env.setValue('f-payload', '{"a":1}');
  env.click('btn-push-send');
  await H.flush();
  checkHas('P03 目标值超 128 字节 → 拒绝', env.text('push-status'), '128 字节');
  check('P03 目标值超长 → 不发请求', env.requestLog.length, before);

  /* ---------------- P04 提交成功：POST body 与回执渲染 ---------------- */

  freshForm();
  env.put('f-target', 'uid-1001');
  env.put('f-msg-id', '');
  env.setValue('f-payload', '{"hello":"world"}');
  env.click('btn-push-send');
  await H.flush();

  check('P04 提交是 POST', env.requestLog[env.requestLog.length - 1].method, 'POST');
  check('P04 提交打到 cfg.create_url', env.requestLog[env.requestLog.length - 1].url, CONFIG.create_url);

  const body = JSON.parse(env.requestLog[env.requestLog.length - 1].body);
  check('P04 body.target_type', body.target_type, 'uid');
  check('P04 body.target', body.target, 'uid-1001');
  check('P04 body.offline_mode 为空串（= 服务端默认）', body.offline_mode, '');
  check('P04 body.msg_id 为空串（由后台补齐）', body.msg_id, '');
  check('P04 body.payload 发的是原文而非解析后的对象（保留服务端的精确报错能力）',
    typeof body.payload, 'string');
  check('P04 body.payload 可被解析回对象', JSON.parse(body.payload).hello, 'world');

  await env.respond(CONFIG.create_url, {
    code: 0,
    msg: 'ok',
    data: {
      request_id: 'req-0001',
      accepted: true,
      target_type: 'uid',
      target: 'uid-1001',
      target_label: '按用户 —— 该 uid 名下的全部在线连接',
      msg_id: 'abcdefabcdefabcd',
      msg_id_generated: true,
      offline_mode: 'queue',
      offline_label: '离线缓存',
      payload_bytes: 17,
      payload_max: 4096,
      http_status: 200,
      code: 0,
      upstream_msg: '',
      recorded: true,
      record_error: '',
      audit_ok: true,
      notes: ['ACCEPTED-BODY-NOTE', 'DEDUP-BODY-NOTE', 'PAYLOAD-DROP-BODY-NOTE'],
      dashboard_url: 'http://127.0.0.1:8291',
    },
  });

  check('P04 受理后回执区可见', env.isHidden('push-result-wrap'), false);
  checkHas('P04 回执含 request_id', env.text('tb-push-result'), 'req-0001');
  checkHas('P04 回执标明「已入队」而非「已投递」', env.text('tb-push-result'), '已入队');
  checkHas('P04 回执含后端下发的说明（不由前端编词）', env.text('tb-push-result'), 'PAYLOAD-DROP-BODY-NOTE');
  checkHas('P04 回执给出主项目 Dashboard 出口', env.text('tb-push-result'), '投递指标');
  checkHas('P04 状态栏提示不要只看 HTTP 状态', env.text('push-status'), '不要只看 HTTP 状态');

  /* ---------------- P05 未受理：502 + 5020 的呈现 ---------------- */

  env.click('btn-push-send');
  await H.flush();
  await env.respond(CONFIG.create_url, {
    code: 5020,
    msg: '主项目未受理：invalid sign',
    data: {
      request_id: 'req-0002',
      accepted: false,
      target_type: 'uid',
      target: 'uid-1001',
      msg_id: 'ffffffffffffffff',
      msg_id_generated: false,
      offline_mode: '',
      payload_bytes: 17,
      payload_max: 4096,
      http_status: 401,
      code: 4003,
      upstream_msg: 'invalid sign',
      recorded: true,
      record_error: '',
      audit_ok: true,
      notes: [],
      dashboard_url: '',
    },
  }, { status: 502 });

  checkHas('P05 未受理 → 状态栏显示 HTTP 与业务码', env.text('push-status'), '502');
  checkHas('P05 未受理 → 仍摊开上游三元组', env.text('tb-push-result'), 'invalid sign');
  checkHas('P05 未受理 → 回执里 accepted 为否', env.text('tb-push-result'), '否');

  /* ---------------- P06 历史：渲染、汇总、空态、分页 ---------------- */

  await env.respond(env.pendingUrls().find((u) => u.indexOf('/api/push/history') === 0), historyPayload());
  check('P06 历史 1 行', env.rows('tb-history').length, 1);
  checkHas('P06 历史行含 target', env.text('tb-history'), 'uid-1001');
  checkHas('P06 历史行含 msg_id', env.text('tb-history'), '00112233445566aa');
  checkHas('P06 历史行含载荷原文', env.text('tb-history'), '{"hello":"world"}');
  checkHas('P06 已受理显示为「已受理」标签', env.text('tb-history'), '已受理');
  checkHas('P06 汇总显示今日条数', env.text('history-summary'), '今日 2');
  checkHas('P06 分页信息含总条数', env.text('history-pager-info'), '共 1 条');
  checkHas('P06 历史说明用响应体里的 record_note（覆盖首屏的 cfg 文案）',
    env.text('history-note'), 'RECORD-BODY-NOTE');

  // 分页：下一页
  const beforePage = env.requestLog.length;
  env.click('btn-history-next');
  await H.flush();
  checkRe('P06 只有多页时「下一页」才生效（pages=1 时不动）',
    env.requestLog.length === beforePage ? 'no-request' : 'request', /no-request/);

  // 空态：pages=1 时「下一页」不发请求，故用「查询」强制取数一次
  env.click('btn-history-query');
  await H.flush();
  await env.respond(env.pendingUrls().find((u) => u.indexOf('/api/push/history') === 0),
    historyPayload({ items: [], total: 0, page: 1, pages: 0 }));
  check('P06 空历史渲染一行空态', env.rows('tb-history').length, 1);
  checkHas('P06 空态文案', env.text('tb-history'), '没有匹配的受理记录');
  check('P06 空态行跨 11 列（与表头一致）', env.cells('tb-history', 0)[0].getAttribute('colspan'), '11');

  /* ---------------- P07 非法筛选：前端必须拒，因为服务端会静默丢弃 ---------------- */

  const beforeReject = env.requestLog.length;
  env.put('h-target', 'x'.repeat(129));
  env.click('btn-history-query');
  await H.flush();
  checkHas('P07 非法 target 被前端拒绝并说明「服务端会静默丢弃」',
    env.text('history-status'), '静默丢弃');
  check('P07 非法筛选 → 不发请求', env.requestLog.length, beforeReject);

  env.put('h-target', '');
  env.put('h-msg-id', 'y'.repeat(65));
  env.click('btn-history-query');
  await H.flush();
  checkHas('P07 非法 msg_id 同样被拒', env.text('history-status'), 'msg_id 筛选项超过 64 字节');
  check('P07 非法 msg_id → 不发请求', env.requestLog.length, beforeReject);

  env.put('h-msg-id', '');
  env.put('h-from', '2026-09-23');
  env.put('h-to', '2026-09-01');
  env.click('btn-history-query');
  await H.flush();
  checkHas('P07 起止日期倒置被拒', env.text('history-status'), '开始日期晚于结束日期');
  check('P07 日期倒置 → 不发请求', env.requestLog.length, beforeReject);

  // 合法筛选 → 请求里带上全部条件
  env.put('h-from', '');
  env.put('h-to', '');
  env.put('h-target', 'uid-1001');
  env.put('h-status', 'accepted');
  // ⚠ 用 put 而不是 setSelect：本页的 `h-target-type` **没有** change 监听
  //   （它是纯查询条件，切换后不自动取数）。setSelect 会触发 change 并因此被
  //   harness 记为「未接上的事件」，那是诊断信息而非缺陷 —— 见最后一条自检断言。
  env.put('h-target-type', 'uid');
  env.click('btn-history-query');
  await H.flush();
  const histUrl = env.pendingUrls().find((u) => u.indexOf('/api/push/history') === 0) || '';
  checkHas('P07 合法筛选带上 target', histUrl, 'target=uid-1001');
  checkHas('P07 合法筛选带上 status', histUrl, 'status=accepted');
  checkHas('P07 合法筛选带上 target_type', histUrl, 'target_type=uid');
  checkHas('P07 合法筛选带上 page=1（回到首页）', histUrl, 'page=1');
  await env.respond(histUrl, historyPayload());

  /* ---------------- P08 模板：渲染、编辑载入、删除确认、载入到创建表单 ---------------- */

  await env.respond(CONFIG.templates_url, templatePayload());
  check('P08 模板 1 行', env.rows('tb-templates').length, 1);
  checkHas('P08 模板行含名称', env.text('tb-templates'), 'TPL-A');
  checkHas('P08 模板行含备注', env.text('tb-templates'), 'REMARK-A');
  checkHas('P08 模板说明用响应体里的 note', env.text('tpl-note'), 'TEMPLATE-BODY-NOTE');
  check('P08 「从模板载入」下拉 = 占位 + 1 个模板', env.el('f-template-pick').childNodes.length, 2);

  // 编辑：点第一行的第一个按钮（编辑）
  env.clickRowButton('tb-templates', 0, 0);
  check('P08 编辑载入名称', env.el('t-name').value, 'TPL-A');
  check('P08 编辑载入目标类型', env.el('t-target-type').value, 'device');
  check('P08 编辑载入离线策略', env.el('t-offline-mode').value, 'drop');
  check('P08 编辑载入载荷原文', env.el('t-payload').value, '{"a":1}');
  check('P08 编辑载入备注', env.el('t-remark').value, 'REMARK-A');
  check('P08 编辑态记录 id', env.el('t-id').value, '3');
  checkHas('P08 编辑态标题含模板名', env.text('tpl-editor-title'), 'TPL-A');

  // 删除：confirm 返回 false（harness 默认）→ 不得发请求
  const beforeDel = env.requestLog.length;
  env.clickRowButton('tb-templates', 0, 1);
  await H.flush();
  check('P08 confirm 拒绝时弹出了确认框', env.rec.confirmCalls.length, 1);
  checkHas('P08 确认文案含模板名与「不可撤销」', env.rec.confirmCalls[0], '不可撤销');
  check('P08 confirm 拒绝 → 不发 DELETE', env.requestLog.length, beforeDel);

  // 从模板载入到创建表单：**不得**动目标值与 msg_id
  env.put('f-target', 'uid-keep-me');
  env.put('f-msg-id', 'keep-me-too');
  env.put('f-template-pick', '3');
  env.click('btn-template-load');
  await H.flush();
  check('P08 模板载入目标类型', env.el('f-target-type').value, 'device');
  check('P08 模板载入离线策略', env.el('f-offline-mode').value, 'drop');
  checkHas('P08 模板载入载荷', env.el('f-payload').value, '"a":1');
  check('P08 模板载入**不覆盖**目标值', env.el('f-target').value, 'uid-keep-me');
  check('P08 模板载入**不覆盖** msg_id', env.el('f-msg-id').value, 'keep-me-too');
  checkHas('P08 载入后提示目标值需自填', env.text('push-status'), '目标值与 msg_id 需自行填写');

  // 保存模板：名称重复的前置检查由服务端做，前端只做形态校验
  env.put('t-name', '');
  env.click('btn-tpl-save');
  await H.flush();
  checkHas('P08 模板名为空 → 前端拒绝', env.text('tpl-status'), '模板名必填');
  check('P08 模板名为空 → 不发请求', env.requestLog.length, beforeDel);

  env.put('t-name', 'TPL-A');
  env.put('t-payload', '{bad');
  env.click('btn-tpl-save');
  await H.flush();
  checkHas('P08 模板载荷非法 JSON → 前端拒绝', env.text('tpl-status'), '不是合法 JSON');
  check('P08 模板载荷非法 → 不发请求', env.requestLog.length, beforeDel);

  env.put('t-payload', '{"a":1}');
  env.click('btn-tpl-save');
  await H.flush();
  const tplBody = JSON.parse(env.requestLog[env.requestLog.length - 1].body);
  check('P08 保存模板是 POST 到 templates_url',
    env.requestLog[env.requestLog.length - 1].method + ' ' + env.requestLog[env.requestLog.length - 1].url,
    'POST ' + CONFIG.templates_url);
  check('P08 编辑时带上 id', tplBody.id, 3);
  check('P08 保存 body.name', tplBody.name, 'TPL-A');
  check('P08 保存 body.target_type', tplBody.target_type, 'device');
  await env.respond(CONFIG.templates_url, { code: 0, msg: 'ok', data: { id: 3, is_edit: true, audit_ok: true, note: 'N' } });
  // ⚠ 保存成功后会**重新取一次模板列表**，成功提示写在取数完成之后 ——
  //   故必须先响应这次 GET，提示才可见（见 push.js 里 saveTemplate 的注释：
  //   提示若写在 loadTemplates 之前会被「正在取数…」与渲染时的清空先后覆盖掉）。
  await env.respond(CONFIG.templates_url, templatePayload());
  checkHas('P08 保存成功提示已更新（且未被「正在取数…」覆盖）', env.text('tpl-status'), '已更新模板 #3');
  check('P08 保存后编辑区复位为新建态', env.el('t-id').value, '');

  /* ---------------- P08b 删除：确认后发 DELETE + 后续取数 ---------------- */

  const builtD = H.createEnv({
    meta: META, config: CONFIG, pathname: '/push', confirm: true, initValues: { 'h-size': 20 },
  });
  const envD = builtD.env;
  await H.boot(code, builtD, 'push-config', CONFIG);
  await envD.respond(envD.pendingUrls().find((u) => u.indexOf('/api/push/history') === 0), historyPayload());
  await envD.respond(CONFIG.templates_url, templatePayload());

  envD.clickRowButton('tb-templates', 0, 1);
  await H.flush();
  const delReq = envD.requestLog[envD.requestLog.length - 1];
  check('P08b 确认后发 DELETE', delReq.method, 'DELETE');
  check('P08b DELETE 的 id 走路径参数（不是请求体）', delReq.url, CONFIG.template_delete_base + '3');
  check('P08b DELETE 不带请求体', delReq.body, null);

  await envD.respond(CONFIG.template_delete_base + '3',
    { code: 0, msg: 'ok', data: { id: 3, name: 'TPL-A', deleted: true, audit_ok: true } });
  await envD.respond(CONFIG.templates_url, templatePayload({ items: [] }));
  checkHas('P08b 删除成功提示（且未被「正在取数…」覆盖）', envD.text('tpl-status'), '已删除模板「TPL-A」');
  checkHas('P08b 删除后列表变空态', envD.text('tb-templates'), '还没有模板');

  /* ---------------- P09 XSS 纪律 ---------------- */

  const EVIL = '"><img src=x onerror=alert(1)>';
  const built2 = H.createEnv({ meta: META, config: CONFIG, pathname: '/push', initValues: { 'h-size': 20 } });
  const env2 = built2.env;
  await H.boot(code, built2, 'push-config', CONFIG);
  await env2.respond(env2.pendingUrls().find((u) => u.indexOf('/api/push/history') === 0),
    historyPayload({
      items: [{
        id: 1, request_id: EVIL, target_type: EVIL, target: EVIL,
        payload: EVIL, payload_bytes: 1, msg_id: EVIL, offline_mode: EVIL,
        http_status: 200, code: 0, msg: EVIL, status: EVIL,
        operator_id: 1, created_at: EVIL, target_label: EVIL, offline_label: EVIL,
      }],
    }));
  await env2.respond(CONFIG.templates_url, templatePayload({
    items: [{
      id: 9, name: EVIL, target_type: EVIL, target_label: EVIL,
      payload: null, payload_raw: EVIL, payload_bytes: 1,
      offline_mode: EVIL, offline_label: EVIL, remark: EVIL,
      created_by: 1, created_at: EVIL, updated_at: EVIL,
    }],
  }));

  check('P09 全程零 innerHTML 赋值', env2.rec.innerHTMLWrites, []);
  check('P09 未创建任何 img/script/iframe 元素（恶意标签不被解析）',
    env2.rec.elementTags.filter((t) => ['img', 'script', 'iframe', 'object', 'embed'].indexOf(t) >= 0), []);
  checkHas('P09 恶意串原样落在历史表里（走 textContent 而非被吞掉）', env2.text('tb-history'), EVIL);
  checkHas('P09 恶意串原样落在模板表里', env2.text('tb-templates'), EVIL);

  // 恶意串作为目标值提交时，应被当作普通文本传进请求体，而不是被解析
  env2.put('f-target', EVIL);
  env2.setValue('f-payload', '{"a":1}');
  env2.click('btn-push-send');
  await H.flush();
  const evilBody = JSON.parse(env2.requestLog[env2.requestLog.length - 1].body);
  check('P09 恶意串作为目标值进请求体而非被转义加工', evilBody.target, EVIL);

  /* ---------------- P10 只读角色：无写控件 ---------------- */

  const RO = configWith({ can_create: false, can_template_save: false, can_template_delete: false });
  const built3 = H.createEnv({ meta: META, config: RO, pathname: '/push', initValues: { 'h-size': 20 } });
  const env3 = built3.env;
  await H.boot(code, built3, 'push-config', RO);

  check('P10 只读角色：发起推送区隐藏', env3.isHidden('sec-create'), true);
  check('P10 只读角色：模板编辑区隐藏', env3.isHidden('sec-template-editor'), true);
  check('P10 只读角色：显示「为什么按钮不见了」的说明', env3.isHidden('push-readonly'), false);
  await env3.respond(env3.pendingUrls().find((u) => u.indexOf('/api/push/history') === 0), historyPayload());
  await env3.respond(CONFIG.templates_url, templatePayload());
  check('P10 只读角色：模板行内只有「只读」没有按钮', env3.text('tb-templates').indexOf('删除'), -1);
  checkHas('P10 只读角色：模板行显示只读字样', env3.text('tb-templates'), '只读');
  check('P10 只读角色：仍然取数与可读', env3.rows('tb-history').length, 1);

  /* ---------------- P11 运行期自检 ---------------- */

  check('P11 各场景全程零定时器',
    env.rec.timerCalls.concat(env2.rec.timerCalls, env3.rec.timerCalls, envD.rec.timerCalls), []);
  check('P11 每处 respond / click 都命中了预期的请求与按钮',
    env.rec.missingResponses.concat(env.rec.missingClicks,
      env2.rec.missingResponses, env2.rec.missingClicks,
      env3.rec.missingResponses, env3.rec.missingClicks,
      envD.rec.missingResponses, envD.rec.missingClicks), []);
}

main().then(() => {
  process.exit(report(null) ? 1 : 0);
}).catch((e) => {
  const stack = (e && e.stack ? e.stack : String(e)).split('\n').slice(0, 3).join(' | ');
  process.exit(report(stack) ? 1 : 0);
});

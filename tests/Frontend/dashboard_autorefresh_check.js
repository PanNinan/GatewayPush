/**
 * 面板自动刷新逻辑校验（resources/dashboard/index.html 内联 JS）
 *
 * 覆盖范围：`DASHBOARD_REFRESH` 的语义契约 —— 0 = 不自动刷新，非默认值
 * 必须真正生效（这两点在修复前均为失效状态，见 .workbuddy/memory 记录）。
 *
 * 做法：从页面模板提取内联 script，
 *   1) 整体通过 `new Function` 做语法编译校验；
 *   2) 截出 autoInterval / clearAuto / setAuto 三个函数，注入假 DOM 与
 *      假定时器（以活跃计数跟踪 setInterval/clearInterval）后跑断言。
 * 全流程不依赖浏览器与 jsdom，也不需要服务端在跑。
 *
 * 运行：
 *     node tests/Frontend/dashboard_autorefresh_check.js
 * 退出码 0 = 全绿，1 = 有断言失败或脚本自身异常。
 *
 * 边界：PHPUnit 套件只扫描 tests/Unit 与 client/tests/Unit，本文件不在其中，
 * 不会与 `composer test` 冲突，需单独执行。
 */

'use strict';

const fs = require('fs');
const path = require('path');

// 相对脚本定位模板，避免绑定本机绝对路径
const PAGE = path.join(__dirname, '..', '..', 'resources', 'dashboard', 'index.html');
if (!fs.existsSync(PAGE)) {
  console.error('FAIL 页面模板不存在: ' + PAGE);
  process.exit(1);
}
const html = fs.readFileSync(PAGE, 'utf8');

/* ---------------- 1. 语法校验 ---------------- */

const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
if (scripts.length !== 1) {
  console.error(`FAIL 期望 1 个内联 script，实际 ${scripts.length}`);
  process.exit(1);
}
const code = scripts[0];

try {
  new Function(code);
  console.log('PASS 语法编译');
} catch (e) {
  console.error('FAIL 语法错误: ' + e.message);
  process.exit(1);
}

/* ---------------- 2. 提取三个函数 ---------------- */

const funcStart = code.indexOf('function autoInterval');
const funcEnd = code.indexOf("$('btn-refresh')");
if (funcStart < 0 || funcEnd < 0 || funcEnd <= funcStart) {
  console.error('FAIL 无法定位 autoInterval / setAuto 代码段');
  process.exit(1);
}

// 向前吃掉函数上方的块注释，保持可读
const segment = code.slice(code.lastIndexOf('/**', funcStart), funcEnd);

/* ---------------- 3. 注入假环境并跑场景 ---------------- */

const driver = `
  var els = { 'btn-auto': { className: 'on', disabled: false, title: '' } };
  var last = null, autoTimer = null, autoOn = true, autoIv = 0;
  var ivLog = [], liveCount = 0;
  var $ = function (id) { return els[id]; };
  function num(v) { var n = Number(v); return isFinite(n) ? n : 0; }
  var refresh = function () {};
  var setInterval = function (fn, ms) { ivLog.push(ms); liveCount++; return liveCount; };
  var clearInterval = function () { if (liveCount > 0) { liveCount--; } };

  function reset(sec) {
    last = (sec === null) ? null : { meta: { refresh: sec } };
    autoTimer = null; autoIv = 0; ivLog.length = 0; liveCount = 0;
    els['btn-auto'].className = 'on';
    els['btn-auto'].disabled = false;
    els['btn-auto'].title = '';
    setAuto(true);
  }
  // 复刻 refresh() 成功回调中的同步判断
  function afterFetch(sec) {
    last = { meta: { refresh: sec } };
    if ((autoOn ? autoInterval() : 0) !== autoIv) { setAuto(autoOn); }
  }
  function snap() {
    return {
      iv: autoIv,
      live: liveCount,
      last: ivLog.length ? ivLog[ivLog.length - 1] : null,
      disabled: els['btn-auto'].disabled,
      cls: els['btn-auto'].className,
      title: els['btn-auto'].title
    };
  }
  return {
    boot: function (sec) { reset(sec); return snap(); },
    fetched: function (sec) { afterFetch(sec); return snap(); },
    pause: function () { setAuto(false); return snap(); },
    resume: function () { setAuto(true); return snap(); }
  };
`;

const api = new Function(segment + driver)();

const results = [];
function check(name, actual, expected) {
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  results.push({ name, ok: a === e, actual: a, expected: e });
}

/* T1 首轮（last 为空）：只能按默认 5s 兜底 */
const t1 = api.boot(null);
check('T1 首轮无 meta → 默认 5s', [t1.iv, t1.live, t1.last], [5000, 1, 5000]);

/* T2 配置 10s：首轮 5s，拿到 meta 后必须重建为 10s（原实现永远停在 5s） */
const t2a = api.boot(null);
const t2b = api.fetched(10);
check('T2 DASHBOARD_REFRESH=10 → 重建为 10s', [t2b.iv, t2b.live, t2b.last], [10000, 1, 10000]);

/* T3 配置 0：必须停掉定时器 + 按钮置灰（原实现被 || 5 吞掉，仍在 5s 轮询） */
const t3a = api.boot(null);
const t3b = api.fetched(0);
check('T3 DASHBOARD_REFRESH=0 → 停止并置灰',
  [t3b.iv, t3b.live, t3b.disabled, t3b.cls === ''],
  [0, 0, true, true]);

/* T4 配置 5s 与默认一致：不得重建定时器（防刷新周期被无限推迟） */
const t4a = api.boot(null);
const t4b = api.fetched(5);
check('T4 间隔未变 → 不重建', [t4b.iv, t4b.live, t4b.last], [5000, 1, 5000]);

/* T5 手动暂停 / 恢复 */
const t5a = api.boot(5);
const t5b = api.pause();
const t5c = api.resume();
check('T5 暂停清定时器、恢复 5s',
  [t5b.iv, t5b.live, t5b.cls, t5c.live, t5c.last, t5c.cls],
  [0, 0, '', 1, 5000, 'on']);

/* T6 负数等非法值同样按禁用处理 */
const t6 = api.boot(null);
const t6b = api.fetched(-1);
check('T6 非法负值 → 禁用', [t6b.iv, t6b.disabled], [0, true]);

/* ---------------- 4. 输出 ---------------- */

let failed = 0;
for (const r of results) {
  console.log(`${r.ok ? 'PASS' : 'FAIL'} ${r.name}` + (r.ok ? '' : `  实际=${r.actual} 期望=${r.expected}`));
  if (!r.ok) { failed++; }
}
console.log(failed === 0
  ? `\n全绿：${results.length}/${results.length}`
  : `\n失败 ${failed}/${results.length}`);
process.exit(failed === 0 ? 0 : 1);

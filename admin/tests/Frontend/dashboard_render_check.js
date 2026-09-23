/**
 * GatewayPush 后台 · 健康总览页**渲染**校验（真跑 admin/public/static/dashboard.js）
 *
 * ## 与 DashboardContractTest 的分工
 *
 * `tests/Unit/DashboardContractTest.php` 做的是**静态契约**（JS 引用的 DOM id 在视图里存在、
 * cfg 键由控制器注入、没有 innerHTML、恰好两个 window.setTimeout...），跑在 PHP 侧、看得见文本。
 * 本文件做的是**运行期行为**：把 `dashboard.js` 的 IIFE **整体真跑一遍**，只把三个自由变量
 * `document` / `window` / `fetch` 换成受控替身，再由测试驱动各个 tick、断言真实产出的 DOM 树。
 *
 * 两者互补：契约测试防「改名漂移」，本文件防「逻辑写错但名字都对」。
 *
 * ## 做法
 *
 * 1. 从视图 HTML 里正则抓出**全部 `id="..."`**，按此构建假 DOM —— 假 DOM 天然与真视图同构，
 *    视图删了某个 id，本文件会立刻以「渲染到 null」暴露出来。
 * 2. `new Function('document','window','fetch', code)` 注入替身并执行（不是截函数片段）。
 * 3. 假 `fetch` 把每次调用登记为一条 pending，测试用 `respond(url, body)` 决定何时、回什么。
 *    于是轮询节奏完全由测试掌控，不需要真等 5s。
 * 4. 假 `window.setTimeout` **不执行回调**，只登记（带 `fn.name` 区分 runLive / runSlow），
 *    测试用 `fire('runLive')` 手动推进一跳。既验证了「链式 setTimeout」的排期值（退避倍数），
 *    也验证了「不会自动续跑」（定时器计数就是断言对象）。
 *
 * 断言覆盖：卡片取值、派生率 null≠0.00%、队列条宽与档位、进程表格式化与残留淡出、
 * gauge/counter 排序、慢 tick 专属段（自检 / DB / 主项目 API）、
 * 速率环形缓冲与**日切清空**、自绘 SVG 的 viewBox/点位/非等比描边、
 * 失败退避（5s→10s→20s）、403 可读化、**visibilitychange 陈旧响应作废**（防双定时器）、
 * 以及 XSS 纪律（恶意串只进 textContent，零 innerHTML 赋值、零 img/script 元素被创建）。
 *
 * 全流程不依赖浏览器、jsdom 与服务端，无需任何角色在线。
 *
 * 运行：
 *     node admin/tests/Frontend/dashboard_render_check.js
 *     # 或（admin 目录下）composer test:frontend
 * 退出码 0 = 全绿，1 = 有断言失败或脚本自身异常。
 *
 * 环境变量 `DASHBOARD_JS` / `DASHBOARD_VIEW` 可替换被测文件路径，
 * 供**变异测试**（故意注入回归，确认本脚本抓得到）使用，平时不需要设置。
 *
 * 边界：不在 PHPUnit 套件内（套件只扫 admin/tests/Unit），不会与 `composer test` 冲突。
 */

'use strict';

const fs = require('fs');
const path = require('path');

const JS_FILE = process.env.DASHBOARD_JS
  || path.join(__dirname, '..', '..', 'public', 'static', 'dashboard.js');
const VIEW_FILE = process.env.DASHBOARD_VIEW
  || path.join(__dirname, '..', '..', 'app', 'view', 'dashboard', 'index.html');

if (!fs.existsSync(JS_FILE)) {
  console.error('FAIL 前端脚本不存在: ' + JS_FILE);
  process.exit(1);
}
if (!fs.existsSync(VIEW_FILE)) {
  console.error('FAIL 视图模板不存在: ' + VIEW_FILE);
  process.exit(1);
}

const CODE = fs.readFileSync(JS_FILE, 'utf8');
const VIEW = fs.readFileSync(VIEW_FILE, 'utf8');

/* ==================================================================
 * 0. 语法编译校验（最先，报错信息最直观）
 * ================================================================== */

try {
  new Function(CODE);
  console.log('PASS 语法编译 dashboard.js');
} catch (e) {
  console.error('FAIL 语法错误: ' + e.message);
  process.exit(1);
}

/* ==================================================================
 * 1. 断言工具
 * ================================================================== */

const results = [];

function check(name, actual, expected) {
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  results.push({ name, ok: a === e, got: a, want: e });
}

function checkRe(name, actual, re) {
  const ok = typeof actual === 'string' && re.test(actual);
  results.push({ name, ok, got: JSON.stringify(actual), want: String(re) });
}

function checkTrue(name, cond, detail) {
  results.push({ name, ok: !!cond, got: String(detail === undefined ? cond : detail), want: 'truthy' });
}

/* ==================================================================
 * 2. 假 DOM / 假 window / 假 fetch
 * ================================================================== */

/** 视图里声明的全部 id：假 DOM 与被测视图同构，视图删 id 会在此暴露 */
const VIEW_IDS = [...VIEW.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);

/**
 * 各元素在视图里的**初始文案**（取紧跟标签的第一个文本片段）。
 * 让假 DOM 的起始状态与真视图一致，于是「未被写入」与「被写成 —」能区分开 ——
 * 否则任何留白都会被误判为「渲染正常」。
 */
const VIEW_INITIAL = {};
[...VIEW.matchAll(/\sid="([^"]+)"[^>]*>([^<]*)</g)].forEach((m) => { VIEW_INITIAL[m[1]] = m[2]; });

/** 只能由 createElementNS 产出的标签（记在 svgTags，不进 elementTags） */
const SVG_TAGS = ['svg', 'line', 'polyline', 'polygon', 'path', 'circle', 'text', 'g', 'rect'];

/** 取节点文本（元素递归拼接子节点，文本节点取自身） */
function rawText(node) {
  if (!node) { return ''; }
  const t = node.textContent;
  return typeof t === 'string' ? t : '';
}

function createEnv(config) {
  const rec = {
    elementTags: [], // createElement 过的标签 → XSS 断言：不该出现 img/script
    svgTags: [],
    innerHTMLWrites: [],
    clearedTimers: [],
    setIntervalCalls: [], // 非空即违反「链式 setTimeout」约束
    // 诊断用：被测代码未能按预期发起请求 / 排定时器时记账，而不是让校验脚本抛异常
    // （抛异常会中断后续全部断言，掩盖真正的失败原因）
    missingResponses: [],
    missingFires: [],
  };

  /* ---- 节点 ---- */

  function textNode(text) {
    return { nodeType: 3, textContent: String(text), parentNode: null };
  }

  function matchesClass(node, sel) {
    if (sel.charAt(0) !== '.') { return false; }
    const want = sel.slice(1);
    return (node.className || '').split(/\s+/).indexOf(want) >= 0;
  }

  function walk(root, visit) {
    const stack = root.childNodes.slice();
    while (stack.length) {
      const cur = stack.shift();
      if (cur.nodeType !== 1) { continue; }
      visit(cur);
      stack.push(...cur.childNodes);
    }
  }

  function makeElement(tag, ns) {
    const node = {
      nodeType: 1,
      tagName: tag,
      namespaceURI: ns || null,
      attrs: {},
      style: {},
      className: '',
      colSpan: 1,
      childNodes: [],
      parentNode: null,
      get firstChild() { return this.childNodes.length ? this.childNodes[0] : null; },
      setAttribute(k, v) { this.attrs[k] = String(v); },
      getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; },
      appendChild(child) {
        if (child && child.nodeType === 11) {
          const move = child.childNodes.slice();
          child.childNodes.length = 0;
          move.forEach((c) => this.appendChild(c));
          return child;
        }
        this.childNodes.push(child);
        if (child) { child.parentNode = this; }
        return child;
      },
      removeChild(child) {
        const i = this.childNodes.indexOf(child);
        if (i >= 0) {
          this.childNodes.splice(i, 1);
          child.parentNode = null;
        }
        return child;
      },
      querySelector(sel) {
        let hit = null;
        walk(this, (n) => { if (hit === null && matchesClass(n, sel)) { hit = n; } });
        return hit;
      },
      querySelectorAll(sel) {
        const out = [];
        walk(this, (n) => { if (matchesClass(n, sel)) { out.push(n); } });
        return out;
      },
    };

    // textContent：setter 用单个文本节点替换全部子节点；getter 递归拼接（对齐真实 DOM）
    Object.defineProperty(node, 'textContent', {
      get() { return this.childNodes.map(rawText).join(''); },
      set(v) {
        this.childNodes.length = 0;
        const s = String(v);
        if (s !== '') { this.childNodes.push(textNode(s)); }
      },
    });

    // 被测代码一旦赋值 innerHTML 即被记账（XSS 纪律的硬断言）
    Object.defineProperty(node, 'innerHTML', {
      get() { return ''; },
      set(v) { rec.innerHTMLWrites.push(String(v)); },
    });

    return node;
  }

  const byId = {};
  VIEW_IDS.forEach((id) => {
    const node = makeElement('div', null);
    if (VIEW_INITIAL[id]) { node.textContent = VIEW_INITIAL[id]; }
    byId[id] = node;
  });

  // 视图里两个 pill 各自内嵌 <span class="dot">；markPill 依赖它
  ['pill-redis', 'pill-api'].forEach((id) => {
    if (byId[id]) { byId[id].appendChild(makeElement('span', null)).className = 'dot'; }
  });

  const listeners = {};
  const timers = [];
  let timerId = 0;
  let hidden = false;

  const documentStub = {
    get hidden() { return hidden; },
    getElementById(id) {
      return Object.prototype.hasOwnProperty.call(byId, id) ? byId[id] : null;
    },
    createElement(tag) {
      rec.elementTags.push(tag);
      return makeElement(tag, null);
    },
    createElementNS(ns, tag) {
      rec.svgTags.push(tag);
      return makeElement(tag, ns);
    },
    createTextNode(t) { return textNode(t); },
    createDocumentFragment() {
      return {
        nodeType: 11,
        childNodes: [],
        appendChild(child) { this.childNodes.push(child); if (child) { child.parentNode = this; } return child; },
        removeChild(child) { const i = this.childNodes.indexOf(child); if (i >= 0) { this.childNodes.splice(i, 1); } return child; },
      };
    },
    addEventListener(type, fn) {
      (listeners[type] || (listeners[type] = [])).push(fn);
    },
  };

  const windowStub = {
    setTimeout(fn, ms) {
      const id = ++timerId;
      timers.push({ id, fn, ms: Number(ms), name: (fn && fn.name) || '' });
      return id;
    },
    clearTimeout(id) {
      rec.clearedTimers.push(id);
      const i = timers.findIndex((t) => t.id === id);
      if (i >= 0) { timers.splice(i, 1); }
    },
    // 故意「记账但不工作」：链式 setTimeout 是本页的设计约束（setInterval 会让慢请求层层堆积），
    // 这里让误用能被断言抓到，而不是让整个校验静默失效。
    setInterval(fn, ms) { rec.setIntervalCalls.push(Number(ms)); return 0; },
    clearInterval() {},
  };

  const pending = [];
  const fetchLog = [];

  function fetchStub(url) {
    fetchLog.push(url);
    return new Promise((resolve, reject) => {
      pending.push({ url, resolve, reject });
    });
  }

  /* ---- 驱动 API ---- */

  const env = {
    config,
    rec,
    timers,
    fetchLog,
    get pendingCount() { return pending.length; },

    text(id) {
      const n = byId[id];
      return n ? rawText(n) : '';
    },
    el(id) { return byId[id] || null; },
    rows(id) {
      const body = byId[id];
      return body ? body.childNodes.slice() : [];
    },
    dotClass(id) {
      const pill = byId[id];
      const dot = pill ? pill.querySelector('.dot') : null;
      return dot ? dot.className : null;
    },
    /** 某容器内第一个指定标签的元素 */
    tag(rootId, tagName) {
      const root = byId[rootId];
      if (!root) { return null; }
      let hit = null;
      walk(root, (n) => { if (hit === null && n.tagName === tagName) { hit = n; } });
      return hit;
    },
    /** 某容器内某 class 的元素计数 */
    countClass(rootId, sel) {
      const root = byId[rootId];
      return root ? root.querySelectorAll(sel).length : -1;
    },
    timersNamed(name) { return timers.filter((t) => t.name === name); },
    timerDelays(name) { return timers.filter((t) => t.name === name).map((t) => t.ms); },

    /** 推进一跳：取出指定名称的定时器并执行其回调（返回是否命中） */
    fire(name) {
      const i = timers.findIndex((t) => t.name === name);
      if (i < 0) {
        rec.missingFires.push(name);
        return false;
      }
      const t = timers.splice(i, 1)[0];
      t.fn();
      return true;
    },

    setHidden(v) {
      if (hidden === v) { return; }
      hidden = v;
      (listeners.visibilitychange || []).forEach((fn) => fn());
    },

    /** 响应某个端点的请求；无对应在途请求时只记账、不抛（保证后续断言仍能跑完） */
    respond(url, body, opts) {
      const o = opts || {};
      const idx = pending.findIndex((p) => p.url === url);
      if (idx < 0) {
        rec.missingResponses.push(url);
        return flush();
      }
      const p = pending.splice(idx, 1)[0];
      if (o.httpError) {
        p.resolve({ status: o.httpError, ok: false, json: () => Promise.resolve({}) });
      } else {
        const status = o.status || 200;
        p.resolve({ status, ok: status >= 200 && status < 300, json: () => Promise.resolve(body) });
      }
      return flush();
    },

    /** 拒绝网络层（模拟断连） */
    reject(url, message) {
      const idx = pending.findIndex((p) => p.url === url);
      if (idx < 0) {
        rec.missingResponses.push(url);
        return flush();
      }
      pending.splice(idx, 1)[0].reject(new Error(message));
      return flush();
    },
  };

  return { env, documentStub, windowStub, fetchStub };
}

/** 排空微任务：setImmediate 是宏任务，保证前面所有 .then/.catch 都已跑完 */
function flush() {
  return new Promise((resolve) => setImmediate(resolve));
}

const LIVE_URL = '/api/monitor/live';
const SUMMARY_URL = '/api/monitor/summary';

const CONFIG = {
  live_url: LIVE_URL,
  summary_url: SUMMARY_URL,
  live_interval_ms: 5000,
  slow_interval_ms: 30000,
  history_points: 3,
  queue_warn_depth: 100,
  gauge_stale_secs: 10,
  dashboard_url: 'http://127.0.0.1:8291',
};

function boot(config) {
  const cfg = config || CONFIG;
  const built = createEnv(cfg);
  const configNode = built.documentStub.getElementById('dashboard-config');
  configNode.textContent = JSON.stringify(cfg);
  const run = new Function('document', 'window', 'fetch', CODE);
  run(built.documentStub, built.windowStub, built.fetchStub);
  return built.env;
}

/* ==================================================================
 * 3. 场景主流程
 * ================================================================== */

const CLOCK_RE = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;

function baseRedis(extra) {
  return Object.assign({
    ok: true,
    latency_ms: 0.42,
    online: 3,
    db: 9,
    prefix: 'gw:',
    report_at: 1799999995,
    gauge: { conn_total: 5, conn_ws: 2, conn_udp: 3, 'pid_at:101': 1799999995 },
    counter: { msg_in: 100, push_in: 40, msg_fail: 1 },
    // 第 3 条深度**恰好等于**阈值：项目约定「到点即算」，故必须判为积压
    // （若判据写成 depth > warnDepth，这一条就会漏报 —— 专门钉住方向）
    queues: { 'queue:udp:out': 0, 'queue:push:in': 50, 'queue:action:in': 100 },
  }, extra || {});
}

const RATIOS_OK = [
  {
    key: 'msg_fail_rate', label: '消息处理失败率', value: 1.0, num: 1, den: 100,
    level: 'ok', kind: 'error', formula: 'msg_fail / msg_in',
  },
  {
    key: 'auth_fail_rate', label: '鉴权失败率', value: null, num: 0, den: 0,
    level: 'ok', kind: 'error', formula: 'auth_fail / msg_in',
  },
];

const PROCS_OK = [
  {
    pid: 101, role: 'business', worker_id: 0, memory_bytes: 1048576,
    age_secs: 5, alive: true,
    tasks: { 'log-cleanup': { count: 2, skip: 0, fail: 0, last_cost: 0.0012, running: false } },
  },
  {
    pid: 102, role: 'udp', worker_id: -1, memory_bytes: 2048,
    age_secs: 900, alive: false, tasks: {},
  },
];

function livePayload(ts, counter, redisExtra, derivedExtra) {
  return {
    code: 0,
    msg: 'ok',
    data: {
      ts,
      redis: baseRedis(Object.assign({ counter }, redisExtra || {})),
      derived: Object.assign({
        ratios: RATIOS_OK,
        processes: PROCS_OK,
        alerts: [{ level: 'warn', title: '队列积压', detail: 'queue:action:in=100（恰达阈值）' }],
      }, derivedExtra || {}),
    },
  };
}

function summaryPayload(ts, redisExtra, api, derivedExtra) {
  return {
    code: 0,
    msg: 'ok',
    data: {
      ts,
      redis: baseRedis(Object.assign({ db_size: 12, self_check: null }, redisExtra || {})),
      api,
      derived: Object.assign({ ratios: RATIOS_OK, processes: PROCS_OK, alerts: [] }, derivedExtra || {}),
    },
  };
}

async function main() {
  const env = boot();

  /* ---------------- S1 启动态 ---------------- */

  check('S1 启动后无定时器（链式等待首次响应）', env.timers.length, 0);
  check('S1 启动即发起 live + summary 两个请求', env.fetchLog, [LIVE_URL, SUMMARY_URL]);
  check('S1 首屏静态配置由 cfg 填充（不等首个响应）',
    [env.text('meta-live'), env.text('meta-slow'), env.text('hint-points'), env.text('q-threshold'), env.text('p-stale')],
    ['5', '30', '3', '100', '10']);
  check('S1 启动时 pill 未着色', [env.dotClass('pill-redis'), env.dotClass('pill-api')], ['dot', 'dot']);

  /* ---------------- S2 快 tick 首次渲染 ---------------- */

  await env.respond(LIVE_URL, livePayload(1800000000, { msg_in: 100, push_in: 40, msg_fail: 1 }));

  check('S2 pill-redis 转为 ok', env.dotClass('pill-redis'), 'dot ok');
  checkRe('S2 meta-ts 为时钟格式', env.text('meta-ts'), /^采集于 \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
  checkRe('S2 meta-report 为时钟格式', env.text('meta-report'), CLOCK_RE);
  check('S2 在线连接数', env.text('c-online'), '3');
  check('S2 在线副标题含 conn_total/ws/udp', env.text('c-online-sub'), 'conn_total 5 · ws 2 · udp 3');
  check('S2 Redis 延迟带单位', env.text('c-latency'), '0.42ms');
  check('S2 活跃进程数与残留数', [env.text('c-procs'), env.text('c-procs-sub')], ['1', '共 2 个 PID · 残留 1']);
  check('S2 counter/gauge 字段计数', [env.text('c-counter'), env.text('c-gauge')], ['3counter', '4gauge']);
  check('S2 快 tick 不接管慢 tick 专属字段（仍是视图初始占位）', env.text('c-dbsize'), '—');
  check('S2 快 tick 排下一次于 live_interval_ms', env.timerDelays('runLive'), [5000]);
  check('S2 快 tick 不产生慢 tick 定时器', env.timerDelays('runSlow'), []);

  // 派生率表
  const ratioRows = env.rows('tb-ratios');
  check('S2 派生率行数', ratioRows.length, 2);
  check('S2 有样本的比率渲染为百分比', ratioRows[0] ? rawText(ratioRows[0].childNodes[1]) : null, '1.00%');
  check('S2 有样本的比率档位为「正常」',
    ratioRows[0] ? rawText(ratioRows[0].childNodes[2]) : null, '正常');
  check('S2 无样本(den=0)渲染为 — 而非 0.00%',
    ratioRows[1] ? rawText(ratioRows[1].childNodes[1]) : null, '—');
  // 只比文本不够：null 与 0.00% 的分野必须体现在**结构**上（静默 tag vs 加粗数值）
  check('S2 无样本单元格用静默 tag 呈现（非数值样式）',
    ratioRows[1] ? [ratioRows[1].childNodes[1].childNodes[0].tagName,
      ratioRows[1].childNodes[1].childNodes[0].className] : null,
    ['span', 'tag mute']);
  check('S2 有样本单元格用加粗数值呈现',
    ratioRows[0] ? [ratioRows[0].childNodes[1].childNodes[0].tagName,
      ratioRows[0].childNodes[1].childNodes[0].className] : null,
    ['b', '']);
  check('S2 无样本档位为「占比/静默」标签',
    ratioRows[1] ? rawText(ratioRows[1].childNodes[2]) : null, '正常');
  check('S2 比率行含算式与分子分母',
    ratioRows[0] ? rawText(ratioRows[0].childNodes[3]) : null, 'msg_fail / msg_in');
  check('S2 比率行分子分母列',
    ratioRows[0] ? rawText(ratioRows[0].childNodes[4]) : null, '1 / 100');

  // 队列
  check('S2 队列表行数', env.rows('tb-queues').length, 3);
  check('S2 队列条形图数量', env.countClass('q-bars', '.bar'), 3);
  check('S2 队列 0 深度标「空」',
    rawText(env.rows('tb-queues')[0].childNodes[2]), '空');
  check('S2 队列深度恰等于阈值时即判积压（到点即算）',
    rawText(env.rows('tb-queues')[2].childNodes[2]), '积压超阈值');
  check('S2 队列有条目标「有待处理」',
    rawText(env.rows('tb-queues')[1].childNodes[2]), '有待处理');
  check('S2 条宽按 maxDepth 归一（0 / 50% / 100%）',
    env.rows('q-bars').map((bar) => bar.childNodes[1].childNodes[0].style.width),
    ['0%', '50%', '100%']);

  // 进程表
  check('S2 进程表行数', env.rows('tb-procs').length, 2);
  check('S2 存活进程无淡出', env.rows('tb-procs')[0].style.opacity, undefined);
  check('S2 已退出进程整行淡出（不单靠颜色）', env.rows('tb-procs')[1].style.opacity, '0.55');
  check('S2 worker_id 为负时显示 —', rawText(env.rows('tb-procs')[1].childNodes[2]), '—');
  check('S2 内存 1 MiB 显示为 1.0 MB', rawText(env.rows('tb-procs')[0].childNodes[3]), '1.0 MB');
  check('S2 内存 2 KiB 显示为 2.0 KB', rawText(env.rows('tb-procs')[1].childNodes[3]), '2.0 KB');
  check('S2 存活进程状态标签', rawText(env.rows('tb-procs')[0].childNodes[5]), '存活');
  check('S2 存活状态标签用 ok 样式',
    env.rows('tb-procs')[0].childNodes[5].childNodes[0].className, 'tag ok');
  check('S2 已退出进程状态标签', rawText(env.rows('tb-procs')[1].childNodes[5]), '已退出（残留）');
  check('S2 已退出状态标签用 bad 样式',
    env.rows('tb-procs')[1].childNodes[5].childNodes[0].className, 'tag bad');
  check('S2 定时任务摘要', rawText(env.rows('tb-procs')[0].childNodes[6]), 'log-cleanup 2次 0.0012s');

  // gauge / counter 排序
  check('S2 gauge 表按字段名排序',
    env.rows('tb-gauge').map((tr) => rawText(tr.childNodes[0])),
    ['conn_total', 'conn_udp', 'conn_ws', 'pid_at:101']);
  check('S2 counter 表按指标名排序',
    env.rows('tb-counter').map((tr) => rawText(tr.childNodes[0])),
    ['msg_fail', 'msg_in', 'push_in']);

  // 告警（服务端 1 条）
  check('S2 服务端告警渲染', env.countClass('alerts', '.note'), 1);
  check('S2 告警级别映射为 warn 样式', env.rows('alerts')[0].className, 'note warn');
  check('S2 告警文案以标题冒号开头', rawText(env.rows('alerts')[0].childNodes[0]), '队列积压：');

  /* ---------------- S3 趋势首点（速率样本不足） ---------------- */

  check('S3 首个采样点速率显示 —（需 ≥2 点）', [env.text('t-msg'), env.text('t-push')], ['—', '—']);
  check('S3 图表显示采样中占位', rawText(env.el('chart-msg')), '采样中…（速率需 ≥ 2 次采样）');
  check('S3 在线数图表此时也只有 1 点 → 采样中', rawText(env.el('chart-online')), '采样中…（速率需 ≥ 2 次采样）');

  /* ---------------- S4 慢 tick 渲染 ---------------- */

  const selfCheck = {
    ok: false,
    hint: '缺少 2 个骨架键',
    checks: [
      { key: 'metrics:gauge', exists: true, type: 'hash', expect: 'hash', ttl: -1, hint: '进程指标' },
      { key: 'push:offline:u1', exists: false, type: 'none', expect: 'list', ttl: -2, hint: '离线缓存' },
      { key: 'queue:udp:in', exists: true, type: 'list', expect: 'zset', ttl: -1, hint: '类型不符示例' },
    ],
  };

  await env.respond(SUMMARY_URL, summaryPayload(1800000050, { self_check: selfCheck }, {
    ok: true, url: 'http://127.0.0.1:8291', has_secret: true,
    status: 200, code: 0, msg: 'ok', stats: { uptime_secs: 123, conn_total: '5' },
  }));

  check('S4 慢 tick 排期慢间隔', env.timerDelays('runSlow'), [30000]);
  check('S4 pill-api 转为 ok', env.dotClass('pill-api'), 'dot ok');
  check('S4 DB 键总数与实例信息', env.text('c-dbsize'), '12DB 9 · gw:');
  check('S4 api-url 提示密钥来源', env.text('api-url'),
    'http://127.0.0.1:8291/stats · 密钥 已配置');
  check('S4 API 正常时不产生错误提示', env.el('api-note').childNodes.length, 0);
  check('S4 /stats 表格按字段排序',
    env.rows('tb-apistats').map((tr) => rawText(tr.childNodes[0])), ['conn_total', 'uptime_secs']);
  check('S4 自检表行数', env.rows('tb-selfcheck').length, 3);
  check('S4 自检存在项标「是」', rawText(env.rows('tb-selfcheck')[0].childNodes[1]), '是');
  check('S4 自检缺失项标「否」', rawText(env.rows('tb-selfcheck')[1].childNodes[1]), '否');
  check('S4 TTL=-1 显示「永久」', rawText(env.rows('tb-selfcheck')[0].childNodes[3]), '永久');
  check('S4 TTL=-2 显示「—」', rawText(env.rows('tb-selfcheck')[1].childNodes[3]), '—');
  check('S4 类型与期望不符时标出差异',
    rawText(env.rows('tb-selfcheck')[2].childNodes[2]), 'list ≠ zset');
  check('S4 未通过行整行淡出', env.rows('tb-selfcheck')[1].style.opacity, '0.7');
  check('S4 通过行不淡出', env.rows('tb-selfcheck')[0].style.opacity, undefined);
  check('S4 自检未通过时给出汇总提示', env.countClass('selfcheck-note', '.note'), 1);
  check('S4 汇总提示文案', rawText(env.el('selfcheck-note').childNodes[0].childNodes[0]), '键自检未通过：');
  check('S4 快 tick 的定时器未受慢 tick 影响（互不干扰）', env.timerDelays('runLive'), [5000]);

  /* ---------------- S5 速率累积与环形缓冲 ---------------- */

  env.fire('runLive');
  await env.respond(LIVE_URL, livePayload(1800000005, { msg_in: 110, push_in: 60 }));
  check('S5 第 2 点起速率可算', env.text('t-msg'), '2.0 /s');
  check('S5 推送速率', env.text('t-push'), '4.0 /s');

  env.fire('runLive');
  await env.respond(LIVE_URL, livePayload(1800000010, { msg_in: 130, push_in: 100 }));
  check('S5 第 3 点速率', env.text('t-msg'), '4.0 /s');
  checkRe('S5 达到 2 点后绘出 SVG（在线数）', env.tag('chart-online', 'svg') ? 'svg' : '', /.+/);

  env.fire('runLive');
  await env.respond(LIVE_URL, livePayload(1800000015, { msg_in: 160, push_in: 160 }));
  check('S5 第 4 点速率', env.text('t-msg'), '6.0 /s');
  check('S5 推送速率', env.text('t-push'), '12.0 /s');

  const svg = env.tag('chart-msg', 'svg');
  const poly = env.tag('chart-msg', 'polyline');
  check('S5 环形缓冲按 history_points=3 裁剪（3 次 push 后仍 3 点）',
    poly ? poly.getAttribute('points').trim().split(/\s+/).length : -1, 3);
  check('S5 SVG viewBox 固定 300x88', svg ? svg.getAttribute('viewBox') : null, '0 0 300 88');
  check('S5 SVG 非等比缩放', svg ? svg.getAttribute('preserveAspectRatio') : null, 'none');
  check('S5 折线描边不随缩放拉伸',
    poly ? poly.getAttribute('vector-effect') : null, 'non-scaling-stroke');
  check('S5 含面积多边形', env.tag('chart-msg', 'polygon') !== null, true);
  check('S5 含基线', env.tag('chart-msg', 'line') !== null, true);
  check('S5 面积与折线共用同一批坐标（区域贴合折线）', (() => {
    const area = env.tag('chart-msg', 'polygon');
    if (!area || !poly) { return '缺失 polygon 或 polyline'; }
    return area.getAttribute('points').indexOf(poly.getAttribute('points')) > 0;
  })(), true);

  /* ---------------- S6 日切检测：负增量 → 清历史 ---------------- */

  env.fire('runLive');
  await env.respond(LIVE_URL, livePayload(1800000020, { msg_in: 5, push_in: 2 }));

  check('S6 负增量触发日切告警', env.countClass('alerts', '.note') >= 2, true);
  const rolloverNote = env
    .rows('alerts')
    .map((n) => rawText(n))
    .filter((t) => t.indexOf('检测到跨零点日切') === 0);
  check('S6 日切告警以 info 级呈现', rolloverNote.length, 1);
  check('S6 日切后趋势清空（不画负速率）', rawText(env.el('chart-msg')), '采样中…（速率需 ≥ 2 次采样）');
  check('S6 日切后速率归位为 —', [env.text('t-msg'), env.text('t-push')], ['—', '—']);

  /* ---------------- S7 失败退避与可读化 ---------------- */

  env.fire('runLive');
  await env.respond(LIVE_URL, { code: 5000, msg: '队列积压', data: {} });
  check('S7 业务码非 0 视为失败并报出连续次数',
    env.rows('alerts').map((n) => rawText(n)).some((t) => t.indexOf('快 tick 取数失败：队列积压') === 0), true);
  check('S7 第 1 次失败的退避间隔 = 2×base（10s）', env.timerDelays('runLive'), [10000]);
  check('S7 失败提示写明下次重试间隔',
    env.rows('alerts').map((n) => rawText(n)).some((t) => t.includes('下次重试间隔 10s')), true);

  env.fire('runLive');
  await env.respond(LIVE_URL, { code: 5000, msg: '队列积压', data: {} });
  check('S7 第 2 次失败的退避间隔 = 4×base（20s）', env.timerDelays('runLive'), [20000]);
  check('S7 连续失败计数累加',
    env.rows('alerts').map((n) => rawText(n)).some((t) => t.includes('第 2 次连续失败')), true);

  env.fire('runLive');
  await env.respond(LIVE_URL, null, { httpError: 403 });
  check('S7 HTTP 403 转成可读文案（而非 JSON 解析报错）',
    env.rows('alerts').map((n) => rawText(n))
      .some((t) => t.includes('无权限（HTTP 403）：当前账号缺少该 API 的权限点，请联系管理员')), true);
  check('S7 第 3 次失败的退避间隔 = 8×base（40s）', env.timerDelays('runLive'), [40000]);

  env.fire('runLive');
  await env.respond(LIVE_URL, livePayload(1800000030, { msg_in: 200, push_in: 200 }));
  check('S7 一旦成功即清零失败计数', env.timerDelays('runLive'), [5000]);
  check('S7 成功后清除传输层告警',
    env.rows('alerts').map((n) => rawText(n)).some((t) => t.indexOf('快 tick 取数失败') === 0), false);
  check('S7 成功后恢复渲染', env.text('c-online'), '3');

  env.fire('runLive');
  await env.reject(LIVE_URL, 'Failed to fetch');
  check('S7 网络层异常同样进入退避',
    env.rows('alerts').map((n) => rawText(n)).some((t) => t.indexOf('快 tick 取数失败：Failed to fetch') === 0), true);
  check('S7 网络层失败退避 = 10s', env.timerDelays('runLive'), [10000]);

  /* ---------------- S8 慢 tick 失败分支 ---------------- */

  env.fire('runSlow');
  await env.reject(SUMMARY_URL, 'socket hang up');
  check('S8 慢 tick 失败写入 api-note',
    rawText(env.el('api-note')).indexOf('慢 tick 失败（第 1 次连续失败）：socket hang up') === 0, true);
  check('S8 慢 tick 失败时 pill-api 转红', env.dotClass('pill-api'), 'dot bad');
  check('S8 慢 tick 退避 = 2×slow（60s）', env.timerDelays('runSlow'), [60000]);

  env.fire('runSlow');
  // 注意：本跳**不**注入 self_check，用于覆盖「慢 tick 拿不到自检数据」的空态分支
  await env.respond(SUMMARY_URL, summaryPayload(1800000060, {}, {
    ok: false, url: 'http://127.0.0.1:8291', has_secret: false,
    status: 503, code: 5030, msg: '队列积压',
  }, { ratios: [], processes: [] }));
  check('S8 慢 tick 成功后恢复排期', env.timerDelays('runSlow'), [30000]);
  check('S8 主项目 API 不可达给出 HTTP + 业务码', rawText(env.el('api-note')).indexOf(
    '主项目 API 不可达：HTTP 503，业务码 5030：队列积压') === 0, true);
  check('S8 未配置密钥时提示回退 AUTH_SECRET',
    rawText(env.el('api-note')).includes('未配置 ADMIN_API_SECRET，由服务端回退 AUTH_SECRET。'), true);
  check('S8 API 不可达时 pill-api 转红', env.dotClass('pill-api'), 'dot bad');
  check('S8 自检缺失时给空态提示',
    rawText(env.rows('tb-selfcheck')[0].childNodes[0]), '自检未采集（Redis 不可用）。');
  check('S8 派生率空时给空态提示而非留白',
    rawText(env.rows('tb-ratios')[0].childNodes[0]),
    '无派生数据 —— Redis 不可用，或主项目 MONITOR_ENABLE=false。');

  /* ---------------- S9 可见性：隐藏即停（响应不得复活轮询） ---------------- */

  // 关键不变量：`stopAll()` 里的序号自增看似冗余（`fetchJson` 自己也会自增），
  // 但它真正防的是**响应在隐藏期间落地**这条路径 —— 若此时不作废，响应会一路走到续排分支，
  // 把后台标签页「复活」成继续轮询，与「隐藏即停」的承诺直接冲突。
  // 这一跳专门钉住它，且只在隐藏期间不发起新请求时才可能暴露。
  checkTrue('S9 隐藏前存在 live 定时器', env.timersNamed('runLive').length === 1,
    env.timersNamed('runLive').length);

  const tsHidden = env.text('meta-ts');
  env.fire('runLive'); // 在途请求
  check('S9 在途请求已发出', env.pendingCount, 1);

  env.setHidden(true);
  check('S9 隐藏后定时器全部清空', env.timers.length, 0);

  await env.respond(LIVE_URL, livePayload(1800000200, { msg_in: 7, push_in: 7 }));
  check('S9 隐藏期间落地的响应不渲染', env.text('meta-ts'), tsHidden);
  check('S9 隐藏期间落地的响应不续排（否则后台复活轮询）', env.timers.length, 0);

  env.setHidden(false);
  check('S9 恢复时立刻重拉 live + summary', env.fetchLog.slice(-2), [LIVE_URL, SUMMARY_URL]);
  check('S9 恢复后进入等待响应态（无定时器）', env.timers.length, 0);

  await env.respond(LIVE_URL, livePayload(1800000100, { msg_in: 1, push_in: 1 }));
  check('S9 恢复后首跳正常渲染', env.text('meta-ts') !== tsHidden, true);
  check('S9 恢复后首跳正常续排', env.timerDelays('runLive'), [5000]);
  await env.respond(SUMMARY_URL, summaryPayload(1800000100, { self_check: selfCheck }, {
    ok: true, url: 'http://127.0.0.1:8291', has_secret: true, status: 200, code: 0, msg: 'ok', stats: {},
  }));

  /* ---------------- S9b 恢复后：隐藏前的旧响应必须作废（防双定时器） ---------------- */

  const tsStale = env.text('meta-ts');
  const alertsStale = rawText(env.el('alerts'));

  env.fire('runLive'); // 隐藏前的在途请求（旧循环）
  env.setHidden(true);
  env.setHidden(false); // 立刻恢复：startAll 另起一路新循环

  // 旧循环的响应此刻才回来 —— 必须判为陈旧：不渲染、不续排、不动告警区
  await env.respond(LIVE_URL, livePayload(1700000000, { msg_in: 9999, push_in: 9999 }));
  check('S9b 旧响应不渲染（meta-ts 未变）', env.text('meta-ts'), tsStale);
  check('S9b 旧响应不续排（否则双定时器并存）', env.timersNamed('runLive').length, 0);
  // 用「前后快照一致」而非「不存在某条告警」—— 前几跳的失败告警本就合法驻留
  check('S9b 旧响应不改动告警区（既不新增也不清除）', rawText(env.el('alerts')), alertsStale);

  // 新循环的响应正常生效
  await env.respond(LIVE_URL, livePayload(1800000300, { msg_in: 3, push_in: 3 }));
  check('S9b 新循环响应正常渲染', env.text('meta-ts') !== tsStale, true);
  check('S9b 新循环响应正常续排（且只有一路）', env.timerDelays('runLive'), [5000]);

  await env.respond(SUMMARY_URL, summaryPayload(1800000300, { self_check: selfCheck }, {
    ok: true, url: 'http://127.0.0.1:8291', has_secret: true, status: 200, code: 0, msg: 'ok', stats: {},
  }));

  /* ---------------- S10 数值格式化边界（独立环境） ---------------- */

  const env2 = boot();
  await env2.respond(LIVE_URL, {
    code: 0,
    msg: 'ok',
    data: {
      ts: 1800001000,
      redis: {
        ok: true, latency_ms: 1, online: 1, db: 9, prefix: 'gw:', report_at: 0,
        gauge: { 'pid_at:11': 1, 'pid_at:12': 1, 'pid_at:13': 1, 'pid_at:14': 1, 'pid_at:15': 1 },
        counter: {}, queues: {},
      },
      derived: {
        ratios: [],
        processes: [
          {
            pid: 11, role: 'business', worker_id: 0, memory_bytes: 500,
            age_secs: 30, alive: true,
            tasks: { cleanup: { count: 1, skip: 3, fail: 2, last_cost: 0.5, running: true } },
          },
          { pid: 12, role: 'gateway', worker_id: 1, memory_bytes: 2048, age_secs: 120, alive: true, tasks: {} },
          { pid: 13, role: 'udp', worker_id: -1, memory_bytes: 1572864, age_secs: 7200, alive: false, tasks: {} },
          { pid: 14, role: 'api', worker_id: 2, memory_bytes: 0, age_secs: -1, alive: false, tasks: {} },
        ],
        alerts: [],
      },
    },
  });

  const p = env2.rows('tb-procs');
  check('S10 进程表 4 行', p.length, 4);
  check('S10 内存 500 B', rawText(p[0].childNodes[3]), '500 B');
  check('S10 内存 2.0 KB', rawText(p[1].childNodes[3]), '2.0 KB');
  check('S10 内存 1.5 MB', rawText(p[2].childNodes[3]), '1.5 MB');
  check('S10 内存 0 显示 —', rawText(p[3].childNodes[3]), '—');
  check('S10 距今 30s', rawText(p[0].childNodes[4]), '30s');
  check('S10 距今 2.0m', rawText(p[1].childNodes[4]), '2.0m');
  check('S10 距今 2.0h', rawText(p[2].childNodes[4]), '2.0h');
  check('S10 时间戳不可信（负数）显示 —', rawText(p[3].childNodes[4]), '—');
  check('S10 任务摘要含跳过/失败/运行中',
    rawText(p[0].childNodes[6]), 'cleanup 1次 跳过3 失败2 0.5000s 运行中');
  check('S10 无任务显示 —', rawText(p[1].childNodes[6]), '—');
  check('S10 活跃进程计数 2 / 共 4 / 残留 2',
    [env2.text('c-procs'), env2.text('c-procs-sub')], ['2', '共 4 个 PID · 残留 2']);
  check('S10 report_at=0 显示 —', env2.text('meta-report'), '—');
  check('S10 空 counter / gauge 计数为 0',
    [env2.text('c-counter'), env2.text('c-gauge')], ['0counter', '5gauge']);
  check('S10 gauge 空值不适用，counter 空则给空态',
    rawText(env2.rows('tb-counter')[0].childNodes[0]),
    '无数据 —— 今日尚无指标写入，或 DB / PREFIX 配错。');
  check('S10 队列为空时给「属正常」提示',
    rawText(env2.el('q-bars')), '读不到队列键 —— 队列为空时 Redis 会自动删除空列表，属正常；'
      + '若长期为空请核对 DB / PREFIX。');

  /* ---------------- S11 XSS 纪律（恶意串只能进 textContent） ---------------- */

  const env3 = boot();
  const EVIL = '<img src=x onerror=alert(1)>';
  await env3.respond(LIVE_URL, {
    code: 0,
    msg: 'ok',
    data: {
      ts: 1800002000,
      redis: {
        ok: true, latency_ms: 1, online: 1, db: 9, prefix: 'gw:', report_at: 1,
        gauge: {}, counter: {},
        queues: { ['queue:' + EVIL]: 5 },
      },
      derived: {
        ratios: [{
          key: 'k', label: EVIL, value: 2, num: 2, den: 100,
          level: 'ok', kind: 'error', formula: EVIL,
        }],
        processes: [{ pid: 1, role: EVIL, worker_id: 0, memory_bytes: 1, age_secs: 1, alive: true, tasks: {} }],
        alerts: [{ level: 'warn', title: EVIL, detail: EVIL }],
      },
    },
  });

  check('S11 全程零 innerHTML 赋值', env3.rec.innerHTMLWrites, []);
  check('S11 未被创建任何 img/script/iframe 元素（恶意标签不被解析）',
    env3.rec.elementTags.filter((t) => ['img', 'script', 'iframe', 'object', 'embed', 'link', 'style'].indexOf(t) >= 0),
    []);
  checkTrue('S11 恶意串原样落在告警文本里（走 textContent 而非消失）',
    rawText(env3.el('alerts')).includes(EVIL), rawText(env3.el('alerts')));
  checkTrue('S11 恶意串原样落在队列表里',
    rawText(env3.el('tb-queues')).includes(EVIL), rawText(env3.el('tb-queues')));
  checkTrue('S11 恶意串原样落在派生率表里',
    rawText(env3.el('tb-ratios')).includes(EVIL), rawText(env3.el('tb-ratios')));
  checkTrue('S11 恶意串原样落在进程表里',
    rawText(env3.el('tb-procs')).includes(EVIL), rawText(env3.el('tb-procs')));
  check('S11 SVG 仅由 createElementNS 产出',
    env3.rec.svgTags.every((t) => SVG_TAGS.indexOf(t) >= 0), true);

  /* ---------------- S12 运行期自检：定时器命名可辨识 + 未误用 setInterval ---------------- */

  check('S12 全程未使用 setInterval（链式 setTimeout 是设计约束）',
    env.rec.setIntervalCalls.concat(env2.rec.setIntervalCalls, env3.rec.setIntervalCalls), []);
  // 被测代码若不再按预期发起请求 / 排定时器，这里会以普通 FAIL 形式报出，
  // 而不是让脚本中途抛异常掩盖真正的失败点
  check('S12 每处 fire/respond 都命中了预期的定时器与在途请求',
    env.rec.missingFires.concat(env.rec.missingResponses,
      env2.rec.missingFires, env2.rec.missingResponses,
      env3.rec.missingFires, env3.rec.missingResponses), []);
  checkTrue('S12 定时器回调可辨识（runLive/runSlow 为具名函数声明）',
    env.timersNamed('runLive').length + env.timersNamed('runSlow').length > 0
      || (env.timers.length === 0),
    'timers=' + JSON.stringify(env.timers.map((t) => t.name)));
}

/* ==================================================================
 * 4. 输出
 * ================================================================== */

function report(fatal) {
  const failed = results.filter((r) => !r.ok);
  results.forEach((r, i) => {
    const no = String(i + 1).padStart(3, '0');
    if (r.ok) {
      console.log('PASS ' + no + ' ' + r.name);
    } else {
      console.log('FAIL ' + no + ' ' + r.name);
      console.log('         got  = ' + r.got);
      console.log('         want = ' + r.want);
    }
  });
  console.log('');
  if (fatal) {
    console.log('⚠ 校验中断：' + fatal);
  }
  console.log(`共 ${results.length} 项，PASS ${results.length - failed.length}，FAIL ${failed.length}`);
  return failed.length + (fatal ? 1 : 0);
}

main().then(() => {
  process.exit(report(null) ? 1 : 0);
}).catch((e) => {
  // 中途异常也必须把**已跑完**的断言打出来 —— 否则一处崩溃会掩盖其余全部结论
  const stack = (e && e.stack ? e.stack : String(e)).split('\n').slice(0, 3).join(' | ');
  process.exit(report(stack) ? 1 : 0);
});

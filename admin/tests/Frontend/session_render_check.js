/**
 * GatewayPush 后台 · 会话查询页**渲染**校验（真跑 admin/public/static/session.js）
 *
 * ## 与 SessionContractTest 的分工
 *
 * `tests/Unit/SessionContractTest.php` 做的是**静态契约**（JS 引用的 DOM id 在视图里存在、
 * cfg 键由控制器注入、无定时器、无 innerHTML、端点都有 GET 路由、
 * 读取链路无 Redis 写命令……），跑在 PHP 侧、看得见文本。
 * 本文件做的是**运行期行为**：把 `session.js` 的 IIFE **整体真跑一遍**，
 * 只把三个自由变量 `document` / `window` / `fetch` 换成受控替身，再由测试驱动交互、
 * 断言真实产出的 DOM 树与请求序列。
 *
 * 两者互补：契约测试防「改名漂移」，本文件防「逻辑写错但名字都对」。
 *
 * ## 做法
 *
 * 1. 从视图 HTML 里正则抓出**全部 `id="..."`**，按此构建假 DOM —— 假 DOM 天然与真视图同构，
 *    视图删了某个 id，本文件会立刻以「渲染到 null」暴露出来。
 * 2. `new Function('document','window','fetch', code)` 注入替身并执行（不是截函数片段）。
 * 3. 假 `fetch` 把每次调用登记为一条 pending，测试用 `respond(url, body)` 决定何时、回什么。
 * 4. 假 `window.location` / `window.history` 记录 `replaceState` 的每次 URL ——
 *    抽屉「URL 携带 clientId」这条约定因此可断言。
 * 5. 假 `window.setTimeout/setInterval` **只记账不执行**：本页承诺无定时器，
 *    一旦有人加回来，`rec.timerCalls` 会非空并直接断言失败。
 *
 * 断言覆盖：列表渲染（含状态标签 / 时长格式化 / 分页）、**粘性 offline_at 不得改变在线判定**、
 * SCAN 截断显式回显、空态区分「没人连」与「DB/PREFIX 配错」、翻页、抽屉（打开 / 字段 / 订阅 /
 * 离线队列首页复用而不重复请求 / URL 同步）、抽屉 404 与「取数失败」的区分、
 * 离线队列翻页的全局下标、反查（含 `scope` 复用成 `uid:1001` 的展示）、订阅双向、
 * 撤销名单（永久 / 限期 / 截断 / 说明文案）、
 * **UTF-8 字节长度校验**（中文 uid 不得被前端误放行）、URL 还原与非法参数拒绝、
 * 无定时器、以及 XSS 纪律（恶意串只进 textContent，零 innerHTML、零 img/script 元素被创建）。
 *
 * 全流程不依赖浏览器、jsdom 与服务端，无需任何角色在线。
 *
 * 运行：
 *     node admin/tests/Frontend/session_render_check.js
 *     # 或（admin 目录下）composer test:frontend
 * 退出码 0 = 全绿，1 = 有断言失败或脚本自身异常。
 *
 * 环境变量 `SESSION_JS` / `SESSION_VIEW` 可替换被测文件路径，
 * 供**变异测试**（故意注入回归，确认本脚本抓得到）使用，平时不需要设置。
 *
 * 边界：不在 PHPUnit 套件内（套件只扫 admin/tests/Unit），不会与 `composer test` 冲突。
 */

'use strict';

const fs = require('fs');
const path = require('path');

const JS_FILE = process.env.SESSION_JS
  || path.join(__dirname, '..', '..', 'public', 'static', 'session.js');
const VIEW_FILE = process.env.SESSION_VIEW
  || path.join(__dirname, '..', '..', 'app', 'view', 'session', 'index.html');

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
  console.log('PASS 语法编译 session.js');
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

function checkHas(name, haystack, needle) {
  const ok = typeof haystack === 'string' && haystack.indexOf(needle) >= 0;
  results.push({ name, ok, got: JSON.stringify(haystack), want: '包含 ' + JSON.stringify(needle) });
}

/* ==================================================================
 * 2. 假 DOM / 假 window / 假 fetch
 * ================================================================== */

/** 视图里声明的全部 id：假 DOM 与被测视图同构，视图删 id 会在此暴露 */
const VIEW_IDS = [...VIEW.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);

/**
 * 视图里各元素声明的 class。
 *
 * ⚠ 必须解析，不能一律给空串：视图里 `class="drawer"` 的初始态是「关闭」，
 * 被测代码只用 `className = 'drawer open'` 表达打开；若替身初始为空串，
 * 「初始关闭」这条断言就会以 `'' !== 'drawer'` 假失败（已踩过）。
 */
const VIEW_CLASS = {};
[...VIEW.matchAll(/<[a-zA-Z][^>]*>/g)].forEach((m) => {
  const tag = m[0];
  const id = /\sid="([^"]+)"/.exec(tag);
  if (!id) { return; }
  const cls = /\sclass="([^"]+)"/.exec(tag);
  if (cls) { VIEW_CLASS[id[1]] = cls[1]; }
});

/** 只能由 createElementNS 产出的标签（记在 svgTags，不进 elementTags） */
const SVG_TAGS = ['svg', 'line', 'polyline', 'polygon', 'path', 'circle', 'text', 'g', 'rect'];

function rawText(node) {
  if (!node) { return ''; }
  const t = node.textContent;
  return typeof t === 'string' ? t : '';
}

function createEnv(config, search) {
  const rec = {
    elementTags: [],
    svgTags: [],
    innerHTMLWrites: [],
    timerCalls: [],        // 非空即违反「本页无定时器」约束
    // 诊断用：被测代码未能按预期发起请求 / 点击未命中按钮时记账，而不是让校验脚本抛异常
    missingResponses: [],
    missingClicks: [],
  };

  /* ---- 节点 ---- */

  function textNode(text) {
    return { nodeType: 3, textContent: String(text), parentNode: null };
  }

  /**
   * 简易 class 选择器匹配。
   *
   * 支持**复合**选择器（`.note.warn`），因为本页大量用 `note warn` / `note info` /
   * `note bad` 三态来表达语义；只支持单 class 的话，`countClass('x', '.note.warn')`
   * 会恒返回 0，于是「有没有警示条」这类断言会静默失效（已踩过）。
   */
  function matchesClass(node, sel) {
    if (sel.charAt(0) !== '.') { return false; }
    const want = sel.slice(1).split('.').filter((s) => s !== '');
    const have = String(node.className || '').split(/\s+/);
    return want.length > 0 && want.every((w) => have.indexOf(w) >= 0);
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
      disabled: false,
      value: '',
      type: '',
      listeners: {},
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
      addEventListener(type, fn) {
        (this.listeners[type] || (this.listeners[type] = [])).push(fn);
      },
      /** 触发点击；没有绑定任何 handler 时记账（诊断「按钮没接上」） */
      click() {
        const fns = this.listeners.click || [];
        if (!fns.length) { rec.missingClicks.push(this.id || this.tagName); }
        fns.forEach((fn) => fn({ target: this }));
      },
      insertBefore(child, ref) {
        if (!ref) { return this.appendChild(child); }
        const i = this.childNodes.indexOf(ref);
        if (i < 0) { return this.appendChild(child); }
        this.childNodes.splice(i, 0, child);
        if (child) { child.parentNode = this; }
        return child;
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
    node.id = id;
    // 与视图同构：class 也照抄，否则「初始关闭态」这类断言会假失败
    node.className = VIEW_CLASS[id] || '';
    byId[id] = node;
  });
  // 表单控件在真实浏览器里是「值 = 当前选项」，替身给上初值，便于断言「未填 = 空」
  if (byId['f-scope']) { byId['f-scope'].value = config.scopes[0]; }
  if (byId['f-protocol']) { byId['f-protocol'].value = ''; }
  if (byId['f-uid']) { byId['f-uid'].value = ''; }
  if (byId['f-size']) { byId['f-size'].value = String(config.page_size); }

  const listeners = {};
  const urlHistory = [];

  const documentStub = {
    hidden: false,
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
    location: { search: search || '', pathname: '/sessions' },
    history: {
      replaceState(state, title, url) { urlHistory.push(String(url)); },
    },
    // 故意「记账但不工作」：本页承诺**没有定时器**，误用必须被断言抓到，
    // 而不是让整个校验静默失效。
    setTimeout(fn, ms) { rec.timerCalls.push(['setTimeout', Number(ms)]); return 0; },
    setInterval(fn, ms) { rec.timerCalls.push(['setInterval', Number(ms)]); return 0; },
    clearTimeout() {},
    clearInterval() {},
    requestAnimationFrame(fn) { rec.timerCalls.push(['requestAnimationFrame', 0]); return 0; },
  };

  const pending = [];
  const fetchLog = [];

  function fetchStub(url) {
    fetchLog.push(url);
    return new Promise((resolve, reject) => {
      pending.push({ url, resolve, reject });
    });
  }

  const env = {
    config,
    rec,
    fetchLog,
    urlHistory,
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
    /** 某容器内某 class 的元素计数 */
    countClass(rootId, sel) {
      const root = byId[rootId];
      return root ? root.querySelectorAll(sel).length : -1;
    },
    /** 某行某列里的元素（td 的文本或按钮） */
    cells(bodyId, rowIndex) {
      const row = this.rows(bodyId)[rowIndex];
      return row ? row.childNodes.slice() : [];
    },
    /** 点击某行最后一列里的按钮 */
    clickRowAction(bodyId, rowIndex) {
      const cells = this.cells(bodyId, rowIndex);
      const last = cells[cells.length - 1];
      const button = last ? last.childNodes[0] : null;
      if (!button) {
        rec.missingClicks.push(bodyId + '[' + rowIndex + '].action');
        return;
      }
      button.click();
    },

    setValue(id, v) {
      const n = byId[id];
      if (n) { n.value = String(v); }
    },
    setSelect(id, v) { this.setValue(id, v); },
    click(id) {
      const n = byId[id];
      if (!n) {
        rec.missingClicks.push(id);
        return;
      }
      n.click();
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
      const status = o.status || 200;
      p.resolve({ status, ok: status >= 200 && status < 300, json: () => Promise.resolve(body) });
      return flush();
    },

    /** 响应非 JSON（模拟被反代 / 登录页拦截） */
    respondHtml(url, status) {
      const idx = pending.findIndex((p) => p.url === url);
      if (idx < 0) {
        rec.missingResponses.push(url);
        return flush();
      }
      const p = pending.splice(idx, 1)[0];
      p.resolve({
        status: status || 200,
        ok: (status || 200) < 300,
        json: () => Promise.reject(new SyntaxError('Unexpected token <')),
      });
      return flush();
    },

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

/* ==================================================================
 * 3. 配置与假响应体（结构对齐后端真实返回）
 * ================================================================== */

const CONFIG = {
  list_url: '/api/sessions',
  detail_base: '/api/session/',
  by_uid_base: '/api/sessions/by-uid/',
  by_device_base: '/api/sessions/by-device/',
  offline_base: '/api/sessions/offline/',
  subs_url: '/api/sessions/subscriptions',
  revoked_url: '/api/auth/revoked',
  scopes: ['online', 'retained', 'all'],
  protocols: ['ws', 'udp'],
  size_options: [20, 50, 100],
  size_max: 100,
  id_max_len: 128,
  page_size: 20,
  offline_page_size: 20,
  revoke_note: '撤销名单以 Token 指纹（sha256 前 32 位）为键，服务端不存 Token 原文。',
  dashboard_url: 'http://127.0.0.1:8291',
};

function boot(config, search) {
  const cfg = config || CONFIG;
  const built = createEnv(cfg, search);
  const configNode = built.documentStub.getElementById('session-config');
  configNode.textContent = JSON.stringify(cfg);
  const run = new Function('document', 'window', 'fetch', CODE);
  run(built.documentStub, built.windowStub, built.fetchStub);
  return built.env;
}

const LIST_URL = CONFIG.list_url;

/** 与 `SessionInspector::rowOf()` 同形 */
function rowOf(clientId, extra) {
  return Object.assign({
    client_id: clientId,
    uid: '1001',
    device_id: 'dev-1',
    protocol: 'ws',
    client_ip: '127.0.0.1',
    client_port: '5000',
    gateway: 'gateway:8282',
    connect_at: 1800000000,
    last_active: 1799999995,
    offline_at: 0,
    state: 'online',
    connect_secs: 3600,
    idle_secs: 5,
    offline_secs: null,
  }, extra || {});
}

function listPayload(extra) {
  return {
    code: 0,
    msg: 'ok',
    data: Object.assign({
      items: [],
      total: 0,
      page: 1,
      size: 20,
      pages: 0,
      scope: 'online',
      online_total: 0,
      scan: null,
      skeleton_ok: true,
      hint: '',
    }, extra || {}),
  };
}

function detailData(extra) {
  return Object.assign({
    found: true,
    state: 'online',
    session: sessionHash('ws-abc'),
    heartbeat: 1800000000,
    heartbeat_age_secs: 5,
    connect_secs: 3600,
    idle_secs: 5,
    offline_secs: 100,
    subscriptions: ['topic:a', 'topic:b'],
    offline_queue: { uid: '1001', len: 2, items: ['{"a":1}', '{"b":2}'], page: 1, size: 20, pages: 1 },
    auth: { bind: 'dev-1', device_bound: true, note: '撤销状态不可由会话反推。' },
    note: '在 online:clients 内，连接当前有效。',
  }, extra || {});
}

/** `session:{clientId}` Hash 的字段面（值与 `Session::bind()` 写入的一致） */
function sessionHash(clientId) {
  return {
    client_id: clientId,
    uid: '1001',
    device_id: 'dev-1',
    protocol: 'ws',
    client_ip: '127.0.0.1',
    client_port: '5000',
    gateway: 'gateway:8282',
    connect_at: '1800000000',
    last_active: '1799999995',
    // ★ 刻意留着 offline_at：粘性字段，真实环境里重连后的会话就是带着它的
    offline_at: '1799999900',
  };
}

function detailPayload(extra) {
  return { code: 0, msg: 'ok', data: detailData(extra) };
}

async function main() {
  /* ---------------- S1 启动：只一次列表请求，且无定时器 ---------------- */

  const env = boot();

  check('S1 启动只发一次列表请求（无轮询）',
    env.fetchLog, [LIST_URL + '?scope=online&page=1&size=20']);
  check('S1 未使用任何定时器', env.rec.timerCalls, []);
  check('S1 长度上限回填到视图', env.text('f-idmax'), '128');
  check('S1 抽屉初始为关闭态', env.el('drawer').className, 'drawer');
  check('S1 启动后无待响应之外的挂起请求', env.pendingCount, 1);

  /* ---------------- S2 列表渲染 ---------------- */

  // 三行刻意覆盖三种「最后断开」形态：
  //   ① 在线且从未断开（无 offline_at）      → 最后断开 —
  //   ② 在线但**带着** offline_at（粘性反例）→ 仍判在线，最后断开如实展示
  //   ③ 已断开但保留                        → 状态为保留
  await env.respond(LIST_URL + '?scope=online&page=1&size=20', listPayload({
    items: [
      rowOf('ws-abc'),
      rowOf('ws-sticky', { offline_at: 1799999900, offline_secs: 100 }),
      rowOf('udp:127.0.0.1:5000', {
        state: 'retained', protocol: 'udp', offline_at: 1799999000, offline_secs: 1000,
        idle_secs: 1000, connect_secs: 7200,
      }),
    ],
    total: 25,
    pages: 2,
    online_total: 7,
  }));

  check('S2 列表行数', env.rows('tb-sessions').length, 3);
  check('S2 在线行状态标签文本', rawText(env.cells('tb-sessions', 0)[0]), '在线');
  check('S2 在线行状态标签样式', env.cells('tb-sessions', 0)[0].childNodes[0].className, 'tag ok');
  check('S2 ★ 带 offline_at 的在线行仍判在线（粘性字段不得当作离线判据）',
    rawText(env.cells('tb-sessions', 1)[0]), '在线');
  check('S2 带 offline_at 的行如实展示「最后断开」（100s → 1.7m）',
    rawText(env.cells('tb-sessions', 1)[9]), '1.7m');
  check('S2 从未断开的在线行最后断开显示 —', rawText(env.cells('tb-sessions', 0)[9]), '—');
  check('S2 保留行状态标签文本', rawText(env.cells('tb-sessions', 2)[0]), '已断开·保留');
  check('S2 保留行状态标签样式', env.cells('tb-sessions', 2)[0].childNodes[0].className, 'tag warn');
  check('S2 保留行最后断开格式化（1000s → 16.7m）', rawText(env.cells('tb-sessions', 2)[9]), '16.7m');
  check('S2 clientId 列', rawText(env.cells('tb-sessions', 2)[1]), 'udp:127.0.0.1:5000');
  check('S2 协议列以 chip 呈现', env.cells('tb-sessions', 2)[4].childNodes[0].className, 'chip');
  check('S2 客户端地址列拼接 ip:port', rawText(env.cells('tb-sessions', 0)[5]), '127.0.0.1:5000');
  check('S2 连接时长格式化（3600s → 1.0h）', rawText(env.cells('tb-sessions', 0)[7]), '1.0h');
  check('S2 空闲格式化（5s）', rawText(env.cells('tb-sessions', 0)[8]), '5s');
  check('S2 详情按钮文案', rawText(env.cells('tb-sessions', 0)[10]), '详情');

  check('S2 分页信息', env.text('pager-info'), '第 1 / 2 页 · 共 25 条 · 每页 20');
  check('S2 首页时上一页禁用', env.el('btn-prev').disabled, true);
  check('S2 首页时下一页可用', env.el('btn-next').disabled, false);
  check('S2 URL 已同步状态', env.urlHistory[env.urlHistory.length - 1], '?scope=online&page=1&size=20');
  checkHas('S2 状态行含在线总数', env.text('list-status'), '在线 7');

  /* ---------------- S3 SCAN 截断必须显式回显 ---------------- */

  env.click('btn-reload');
  await env.respond(LIST_URL + '?scope=online&page=1&size=20', listPayload({
    items: [rowOf('ws-abc')],
    total: 1,
    pages: 1,
    online_total: 1,
    scan: { scanned: 2000, truncated: true, keys: 1500 },
  }));

  check('S3 截断时出现警示条', env.countClass('list-status', '.note.warn'), 1);
  checkHas('S3 警示条写明「已截断」', env.text('list-status'), '结果已截断');
  checkHas('S3 状态行回显 SCAN 键数', env.text('list-status'), 'SCAN 已扫描 2,000 键 · 命中 1,500 个 session 键');

  env.click('btn-reload');
  await env.respond(LIST_URL + '?scope=online&page=1&size=20', listPayload({
    items: [rowOf('ws-abc')],
    total: 1,
    pages: 1,
    online_total: 1,
    scan: { scanned: 12, truncated: false, keys: 12 },
  }));

  check('S3 未截断时不产生警示条', env.countClass('list-status', '.note.warn'), 0);

  /* ---------------- S4 空态：确实没人连 ---------------- */

  env.click('btn-reload');
  await env.respond(LIST_URL + '?scope=online&page=1&size=20', listPayload());

  check('S4 空列表给出提示条', env.countClass('list-status', '.note'), 1);
  check('S4 空态用 info（不是 bad）', env.countClass('list-status', '.note.info'), 1);
  checkHas('S4 提示语写明「没有会话」', env.text('list-status'), '当前范围内没有会话');
  checkHas('S4 提示语引导切到「在线 + 保留」', env.text('list-status'), '在线 + 保留');
  check('S4 分页信息为无记录', env.text('pager-info'), '无记录');
  check('S4 表体给出空态行', rawText(env.rows('tb-sessions')[0].childNodes[0]), '无记录。');

  /* ---------------- S5 空态：DB / PREFIX 配错 ---------------- */

  env.click('btn-reload');
  await env.respond(LIST_URL + '?scope=online&page=1&size=20', listPayload({
    skeleton_ok: false,
    hint: 'expected key absent',
  }));

  check('S5 配错时用 bad 提示', env.countClass('list-status', '.note.bad'), 1);
  checkHas('S5 文案明确区分「不是没有会话」', env.text('list-status'), '这不是「没有会话」');
  checkHas('S5 文案要求核对 DB / PREFIX', env.text('list-status'), 'DB / PREFIX');
  checkHas('S5 附带自检提示', env.text('list-status'), 'expected key absent');

  /* ---------------- S6 翻页 ---------------- */

  env.click('btn-reload');
  await env.respond(LIST_URL + '?scope=online&page=1&size=20', listPayload({
    items: [rowOf('ws-abc')], total: 25, pages: 2, online_total: 7,
  }));

  env.click('btn-next');
  check('S6 下一页请求带 page=2', env.fetchLog[env.fetchLog.length - 1], LIST_URL + '?scope=online&page=2&size=20');
  await env.respond(LIST_URL + '?scope=online&page=2&size=20', listPayload({
    items: [rowOf('ws-def')], total: 25, page: 2, pages: 2, online_total: 7,
  }));

  check('S6 第 2 页分页信息', env.text('pager-info'), '第 2 / 2 页 · 共 25 条 · 每页 20');
  check('S6 末页时下一页禁用', env.el('btn-next').disabled, true);
  check('S6 末页时上一页可用', env.el('btn-prev').disabled, false);
  check('S6 翻页后 URL 同步', env.urlHistory[env.urlHistory.length - 1], '?scope=online&page=2&size=20');

  /* ---------------- S7 抽屉：打开 / 渲染 / URL / 不重复取离线队列 ---------------- */

  const requestsBefore = env.fetchLog.length;
  env.clickRowAction('tb-sessions', 0);

  check('S7 点击详情后只新增 1 个请求', env.fetchLog.length - requestsBefore, 1);
  check('S7 详情请求带 offline_size（与抽屉翻页同口径）',
    env.fetchLog[env.fetchLog.length - 1], '/api/session/ws-def?offline_size=20');
  check('S7 抽屉打开（className 含 open）', env.el('drawer').className, 'drawer open');
  check('S7 标题为 clientId', env.text('drawer-title'), 'ws-def');
  check('S7 URL 携带 clientId', env.urlHistory[env.urlHistory.length - 1], '?scope=online&page=2&size=20&client=ws-def');

  await env.respond('/api/session/ws-def?offline_size=20', detailPayload({
    session: sessionHash('ws-def'),
  }));

  check('S7 字段表首行为 client_id（取自响应体）', rawText(env.cells('tb-drawer-fields', 0)[1]), 'ws-def');
  checkHas('S7 状态行含会话状态', env.text('drawer-status'), '在线');
  checkHas('S7 状态行含后端下发的状态说明', env.text('drawer-status'), '连接当前有效');
  check('S7 字段表行数（10 个 Hash 字段 + 4 个派生 + 2 个 auth + 1 条说明）',
    env.rows('tb-drawer-fields').length, 17);
  check('S7 心跳以时钟呈现', /^\d{4}-\d{2}-\d{2}/.test(rawText(env.cells('tb-drawer-fields', 10)[1])), true);
  check('S7 订阅主题数', env.countClass('drawer-subs', '.chip'), 2);
  checkHas('S7 订阅区写明个数', env.text('drawer-subs-hint'), '共 2 个主题');
  check('S7 ★ 离线队列复用详情响应（不额外发请求）',
    env.fetchLog[env.fetchLog.length - 1], '/api/session/ws-def?offline_size=20');
  check('S7 离线队列行数', env.rows('tb-offline').length, 2);
  check('S7 离线队列全局下标从 0 起', rawText(env.cells('tb-offline', 0)[0]), '0');
  check('S7 离线队列消息体', rawText(env.cells('tb-offline', 1)[1]), '{"b":2}');
  checkHas('S7 离线队列汇总', env.text('offline-page-info'), '共 2 条');

  /* ---------------- S8 抽屉 404：区分「已回收」与「取数失败」 ---------------- */

  const detailUrl404 = '/api/session/ws-gone?offline_size=20';
  env.click('btn-drawer-close');
  check('S8 关闭后抽屉收起', env.el('drawer').className, 'drawer');
  check('S8 关闭后 URL 不再带 client', env.urlHistory[env.urlHistory.length - 1], '?scope=online&page=2&size=20');

  env.click('btn-reload');
  await env.respond(LIST_URL + '?scope=online&page=2&size=20', listPayload({
    items: [rowOf('ws-gone', { state: 'retained', offline_secs: 30, idle_secs: 30 })],
    total: 1, page: 1, pages: 1, online_total: 7,
  }));
  env.clickRowAction('tb-sessions', 0);

  await env.respond(detailUrl404, {
    code: 4004,
    msg: '会话不存在：该 clientId 的会话键已被回收，或从未建连',
    data: detailData({
      found: false,
      state: 'gone',
      session: {},
      heartbeat: null,
      heartbeat_age_secs: null,
      connect_secs: null,
      idle_secs: null,
      offline_secs: null,
      subscriptions: [],
      offline_queue: { uid: '', len: 0, items: [], page: 1, size: 20, pages: 0 },
      auth: { bind: null, device_bound: false, note: '撤销状态不可由会话反推。' },
      note: '会话键已不存在：连接断开后已被回收（unbind），或该 clientId 从未建连。',
    }),
  }, { status: 404 });

  check('S8 404 不显示为故障（无 bad 提示）', env.countClass('drawer-status', '.note.bad'), 0);
  check('S8 404 显示为 warn 说明', env.countClass('drawer-status', '.note.warn'), 1);
  checkHas('S8 文案点明「已被回收」', env.text('drawer-status'), '已被回收');
  checkHas('S8 文案强调「不是取数故障」', env.text('drawer-status'), '不是取数故障');
  checkHas('S8 仍渲染后端下发的 gone 状态说明', env.text('drawer-status'), 'unbind');
  checkHas('S8 无 uid 时离线队列给出提示', env.text('offline-status'), '没有 uid');
  check('S8 无 uid 时离线表为空态', rawText(env.rows('tb-offline')[0].childNodes[0]), '不可用 —— 缺少 uid。');

  /* ---------------- S9 离线队列翻页用全局下标 ---------------- */

  env.click('btn-drawer-close');
  env.click('btn-reload');
  await env.respond(LIST_URL + '?scope=online&page=1&size=20', listPayload({
    items: [rowOf('ws-abc')], total: 1, pages: 1, online_total: 1,
  }));
  env.clickRowAction('tb-sessions', 0);

  await env.respond('/api/session/ws-abc?offline_size=20', detailPayload({
    offline_queue: { uid: '1001', len: 45, items: ['m0', 'm1'], page: 1, size: 20, pages: 3 },
  }));

  check('S9 首页离线行数', env.rows('tb-offline').length, 2);
  checkHas('S9 首页分页信息', env.text('offline-page-info'), '第 1 / 3 页');

  env.click('btn-offline-next');
  check('S9 翻页请求离线端点',
    env.fetchLog[env.fetchLog.length - 1], '/api/sessions/offline/1001?page=2&size=20');
  await env.respond('/api/sessions/offline/1001?page=2&size=20', {
    code: 0,
    msg: 'ok',
    data: { uid: '1001', len: 45, items: ['m20', 'm21'], page: 2, size: 20, pages: 3 },
  });

  check('S9 ★ 翻页后下标接续（20 起，而不是从 0 重来）', rawText(env.cells('tb-offline', 0)[0]), '20');
  check('S9 末页前下一页可用', env.el('btn-offline-next').disabled, false);

  /* ---------------- S10 反查（含 scope 复用为 uid:xxx 的展示） ---------------- */

  env.setValue('l-uid', '1001');
  env.click('btn-lookup-uid');
  check('S10 反查 uid 的请求路径',
    env.fetchLog[env.fetchLog.length - 1], '/api/sessions/by-uid/1001');
  await env.respond('/api/sessions/by-uid/1001', listPayload({
    items: [rowOf('ws-abc'), rowOf('ws-old', { state: 'retained', offline_secs: 60 })],
    total: 2, pages: 1, scope: 'uid:1001', online_total: 7,
  }));

  check('S10 反查结果行数', env.rows('tb-lookup').length, 2);
  checkHas('S10 ★ 反查的 scope 原样展示（不得误译成「仅在线」）', env.text('lookup-status'), '范围 uid:1001');
  check('S10 反查结果里保留会话的标签', rawText(env.cells('tb-lookup', 1)[0]), '已断开·保留');

  env.setValue('l-device', 'dev-1');
  env.click('btn-lookup-device');
  check('S10 反查 device 的请求路径',
    env.fetchLog[env.fetchLog.length - 1], '/api/sessions/by-device/dev-1');
  await env.respond('/api/sessions/by-device/dev-1', listPayload({
    items: [rowOf('ws-abc')], total: 1, pages: 1, scope: 'device:dev-1', online_total: 7,
  }));
  check('S10 device 反查结果', env.rows('tb-lookup').length, 1);

  /* ---------------- S11 订阅双向 ---------------- */

  env.setValue('s-uid', '1001');
  env.setValue('s-topic', 'topic:a');
  env.click('btn-query-subs');
  check('S11 订阅请求带 uid 与 topic',
    env.fetchLog[env.fetchLog.length - 1], '/api/sessions/subscriptions?uid=1001&topic=topic%3Aa');
  await env.respond('/api/sessions/subscriptions?uid=1001&topic=topic%3Aa', {
    code: 0,
    msg: 'ok',
    data: { uid: '1001', topics: ['topic:a', 'topic:b'], topic: 'topic:a', subscribers: ['1001', '1002'] },
  });

  check('S11 双向各一行', env.rows('tb-subs').length, 2);
  check('S11 uid 方向明细', rawText(env.cells('tb-subs', 0)[3]), 'topic:a, topic:b');
  check('S11 topic 方向数量', rawText(env.cells('tb-subs', 1)[2]), '2');
  checkHas('S11 状态行含两个方向', env.text('subs-status'), '订阅者');

  /* ---------------- S12 撤销名单 ---------------- */

  env.click('btn-load-revoked');
  check('S12 撤销名单请求路径', env.fetchLog[env.fetchLog.length - 1], '/api/auth/revoked');
  await env.respond('/api/auth/revoked', {
    code: 0,
    msg: 'ok',
    data: {
      items: [
        { fingerprint: 'a1b2c3d4', ttl: -1, permanent: true },
        { fingerprint: 'e5f60718', ttl: 600, permanent: false },
      ],
      scanned: 2000,
      truncated: true,
    },
  });

  check('S12 名单行数', env.rows('tb-revoked').length, 2);
  check('S12 永久条目的 TTL 列', rawText(env.cells('tb-revoked', 0)[1]), '永久');
  check('S12 永久条目标签', rawText(env.cells('tb-revoked', 0)[2]), '永久撤销');
  check('S12 限期条目的 TTL 列', rawText(env.cells('tb-revoked', 1)[1]), '600s');
  check('S12 限时条目标签', rawText(env.cells('tb-revoked', 1)[2]), '限期撤销');
  check('S12 截断时给出警示', env.countClass('revoke-status', '.note.warn'), 1);
  checkHas('S12 警示明确「未列出的指纹不代表未被撤销」',
    env.text('revoke-status'), '未列出的指纹不代表未被撤销');
  checkHas('S12 展示后端下发的说明文案', env.text('revoke-status'), '不存 Token 原文');

  /* ---------------- S13 表单校验：UTF-8 字节而非字符 ---------------- */

  const before = env.fetchLog.length;
  // 43 个汉字 = 129 字节 > 128：按字符数只有 43，按字节数已越界 —— 后端用 strlen()（字节）
  env.setValue('f-uid', '汉'.repeat(43));
  env.click('btn-query');
  check('S13 超长（按字节）uid 不发请求', env.fetchLog.length, before);
  check('S13 超长 uid 给出表单提示', env.countClass('form-note', '.note.bad'), 1);
  checkHas('S13 提示写明「字节（UTF-8）」', env.text('form-note'), '字节（UTF-8）');

  env.setValue('f-uid', '中文uid');
  env.click('btn-query');
  check('S13 合法中文 uid 正常发起请求',
    env.fetchLog[env.fetchLog.length - 1],
    '/api/sessions?scope=online&uid=' + encodeURIComponent('中文uid') + '&page=1&size=20');
  check('S13 校验通过后清空表单提示', env.text('form-note'), '');
  await env.respond('/api/sessions?scope=online&uid=' + encodeURIComponent('中文uid') + '&page=1&size=20', listPayload({
    items: [rowOf('ws-abc')], total: 1, pages: 1, online_total: 1,
  }));

  env.click('btn-reset');
  check('S13 重置回默认范围与页大小',
    env.fetchLog[env.fetchLog.length - 1], LIST_URL + '?scope=online&page=1&size=20');
  await env.respond(LIST_URL + '?scope=online&page=1&size=20', listPayload({
    items: [rowOf('ws-abc')], total: 1, pages: 1, online_total: 1,
  }));

  /* ---------------- S14 请求失败的可读化 ---------------- */

  env.click('btn-reload');
  await env.reject(LIST_URL + '?scope=online&page=1&size=20', 'Failed to fetch');
  check('S14 网络异常给出 bad 提示', env.countClass('list-status', '.note.bad'), 1);
  checkHas('S14 提示带原始错误信息', env.text('list-status'), 'Failed to fetch');

  env.click('btn-reload');
  await env.respondHtml(LIST_URL + '?scope=online&page=1&size=20', 200);
  checkHas('S14 ★ 非 JSON 响应被转成可读文案（而不是 JSON 解析报错）',
    env.text('list-status'), '响应不是 JSON');

  /* ---------------- S15 URL 还原：刷新 / 分享链接 ---------------- */

  const env2 = boot(CONFIG, '?scope=all&page=3&size=50&client=udp%3A127.0.0.1%3A5000');
  check('S15 按 URL 还原列表请求',
    env2.fetchLog[0], '/api/sessions?scope=all&page=3&size=50');
  check('S15 按 URL 自动打开抽屉',
    env2.fetchLog[1], '/api/session/udp%3A127.0.0.1%3A5000?offline_size=20');
  check('S15 抽屉为打开态', env2.el('drawer').className, 'drawer open');
  check('S15 表单回填 scope', env2.el('f-scope').value, 'all');
  check('S15 表单回填 size', env2.el('f-size').value, '50');

  /* ---------------- S16 非法 URL 参数被拒 ---------------- */

  const env3 = boot(CONFIG, '?scope=hack&protocol=tcp&page=-1&size=99999&client=' + 'x'.repeat(200));
  check('S16 非法 scope 回落默认', env3.fetchLog[0], '/api/sessions?scope=online&page=1&size=20');
  check('S16 非法参数不触发抽屉', env3.fetchLog.length, 1);
  check('S16 抽屉保持关闭', env3.el('drawer').className, 'drawer');

  /* ---------------- S17 XSS 纪律 ---------------- */

  const env4 = boot();
  const EVIL = '<img src=x onerror=alert(1)>';
  await env4.respond(LIST_URL + '?scope=online&page=1&size=20', listPayload({
    items: [rowOf(EVIL, { uid: EVIL, device_id: EVIL, gateway: EVIL, protocol: EVIL })],
    total: 1, pages: 1, online_total: 1, hint: EVIL,
  }));
  env4.clickRowAction('tb-sessions', 0);
  await env4.respond('/api/session/' + encodeURIComponent(EVIL) + '?offline_size=20', detailPayload({
    session: { client_id: EVIL, uid: EVIL, device_id: EVIL, protocol: EVIL },
    subscriptions: [EVIL],
    offline_queue: { uid: EVIL, len: 1, items: [EVIL], page: 1, size: 20, pages: 1 },
    note: EVIL,
  }));

  check('S17 全程零 innerHTML 赋值', env4.rec.innerHTMLWrites, []);
  check('S17 未被创建任何 img/script/iframe 元素（恶意标签不被解析）',
    env4.rec.elementTags.filter((t) => ['img', 'script', 'iframe', 'object', 'embed', 'link', 'style'].indexOf(t) >= 0),
    []);
  checkHas('S17 恶意串原样落在列表里（走 textContent 而非消失）', env4.text('tb-sessions'), EVIL);
  checkHas('S17 恶意串原样落在抽屉字段里', env4.text('tb-drawer-fields'), EVIL);
  checkHas('S17 恶意串原样落在离线队列里', env4.text('tb-offline'), EVIL);
  checkHas('S17 恶意串原样落在订阅 chips 里', env4.text('drawer-subs'), EVIL);
  check('S17 未产出 SVG（本页无图表）', env4.rec.svgTags, []);

  /* ---------------- S18 运行期自检 ---------------- */

  check('S18 全程未使用任何定时器（不轮询是设计约束）',
    env.rec.timerCalls.concat(env2.rec.timerCalls, env3.rec.timerCalls, env4.rec.timerCalls), []);
  // 被测代码若不再按预期发起请求 / 绑定按钮，这里会以普通 FAIL 形式报出，
  // 而不是让脚本中途抛异常掩盖真正的失败点
  check('S18 每处 respond / click 都命中了预期的请求与按钮',
    env.rec.missingResponses.concat(env.rec.missingClicks,
      env2.rec.missingResponses, env2.rec.missingClicks,
      env3.rec.missingResponses, env3.rec.missingClicks,
      env4.rec.missingResponses, env4.rec.missingClicks), []);
  checkRe('S18 URL 历史全部为查询串（未整页跳转）',
    env.urlHistory.join(' '), /^(\?[^\s]* *)*$/);
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
  const stack = (e && e.stack ? e.stack : String(e)).split('\n').slice(0, 3).join(' | ');
  process.exit(report(stack) ? 1 : 0);
});

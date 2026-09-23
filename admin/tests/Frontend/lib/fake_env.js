/**
 * GatewayPush 后台 · P3 前端校验**共用**假 DOM / 假 fetch harness
 *
 * ## 为什么抽成共用模块（本项目此前没有这个模式）
 *
 * `dashboard_render_check.js` 与 `session_render_check.js` 各自内联了一份假 DOM 实现
 * （每个 ~320 行）。P3 一次要加两页（`/push` 与 `/actions`），再各内联一份就是
 * **三份**逐行相同的环境代码 —— 而这三份的差异只在「被测页面是谁」这一个参数上。
 * 故从 P3 起把环境抽到本文件，两个校验脚本各自只写「断言」。
 *
 * ⚠ 这是**新增**约定，不是回头统一：两个既有脚本**不动**（改动它们会把已验收的断言打散）。
 *
 * ## 与 PHPUnit 契约测试的分工
 *
 * `tests/Unit/*ContractTest.php` 做**静态契约**（DOM id 在视图里存在、cfg 键由控制器注入、
 * 无定时器、无 innerHTML、路由动词、RBAC……），跑在 PHP 侧、看得见文本。
 * 本模块支撑的是**运行期行为**：把被测脚本的 IIFE **整体真跑一遍**，
 * 只把三个自由变量 `document` / `window` / `fetch` 换成受控替身，再驱动交互、
 * 断言真实产出的 DOM 树与请求序列。
 *
 * ## 三条与 P3 直接相关的实现要点
 *
 * 1. **`hidden` 必须按视图初始化** —— P3 两页大量用 `hidden` 表达「无权限即隐藏」。
 *    若替身一律 `hidden=false`，「只读角色看不到写控件」这条断言会**恒真通过**。
 * 2. **`setTimeout` 必须真的可执行、`clearTimeout` 必须真的出队** —— `/actions` 的有界退避
 *    要靠它们推进；而 `/push` 要求它一次都不能出现。故 `timerCalls` 记账 + `timers`
 *    待执行队列分离：前者用于「不应有定时器」的断言，后者用于「按序列推进退避」的断言。
 *    ⚠ `clearTimeout` 刻意**不记进 `timerCalls`**（那个数组的语义是「本页创建了几个定时器」），
 *    它单独记在 `rec.clearCalls`。若 `clearTimeout` 只记不动手，
 *    「手动补查作废了自动链」这条断言就变成**恒真**（队列里那条永远还在）。
 * 3. **`window.confirm` 必须可注入** —— 模板删除有二次确认。
 *    默认返回 `false`（不确认），使「未确认就不该发请求」成为默认可见的行为。
 * 4. **`<select>` 的「自动选中首项」刻意不实现** —— 真浏览器会把 `value` 设成第一个
 *    `<option>`，但这是隐式行为（且视图里的节点在替身里一律是 `div`，无法按标签区分）。
 *    若替身替产品把这件做了，产品漏写 `node.value = names[0]` 就**永远不会被发现** ——
 *    而它漏写时的表现是「页面首屏静默不可用」。故由被测脚本显式设置默认值，
 *    并由断言钉住（`action_render_check.js` A01、`push_render_check.js` P01）。
 *    这条是**替身故意不如浏览器**的唯一一处，其余以「与浏览器同构」为准。
 *
 * @module tests/Frontend/lib/fake_env
 */

'use strict';

const fs = require('fs');

/* ==================================================================
 * 断言与报告
 * ================================================================== */

/**
 * 断言收集器。
 *
 * 刻意**不抛异常**：一条断言失败不应中断后续断言 —— 否则一次运行只能看到第一个问题，
 * 而前端校验的价值恰恰在于「一次把该看的地方都看一遍」。
 */
function createReporter() {
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

  function checkNotHas(name, haystack, needle) {
    const ok = typeof haystack === 'string' && haystack.indexOf(needle) < 0;
    results.push({ name, ok, got: JSON.stringify(haystack), want: '不含 ' + JSON.stringify(needle) });
  }

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
    if (fatal) { console.log('⚠ 校验中断：' + fatal); }
    console.log(`共 ${results.length} 项，PASS ${results.length - failed.length}，FAIL ${failed.length}`);
    return failed.length + (fatal ? 1 : 0);
  }

  return { results, check, checkRe, checkHas, checkNotHas, report };
}

/* ==================================================================
 * 文件装载与语法编译
 * ================================================================== */

/**
 * 读被测脚本与视图。
 *
 * 环境变量可替换路径，供**变异测试**（故意注入回归，确认校验抓得到）使用。
 *
 * @param {string} envPrefix  环境变量前缀，如 'PUSH' / 'ACTION'
 * @param {string} scriptName 公共名前缀，如 'push' / 'action'
 * @param {string} defaultDir admin 根目录（__dirname 的 ../..)
 */
function loadFiles(envPrefix, scriptName, rootDir) {
  const path = require('path');
  const scriptPath = process.env[envPrefix + '_JS']
    || path.join(rootDir, 'public', 'static', scriptName + '.js');
  const viewPath = process.env[envPrefix + '_VIEW']
    || path.join(rootDir, 'app', 'view', scriptName, 'index.html');

  if (!fs.existsSync(scriptPath)) {
    console.error('FAIL 前端脚本不存在: ' + scriptPath);
    process.exit(1);
  }
  if (!fs.existsSync(viewPath)) {
    console.error('FAIL 视图模板不存在: ' + viewPath);
    process.exit(1);
  }

  return {
    scriptPath,
    viewPath,
    code: fs.readFileSync(scriptPath, 'utf8'),
    view: fs.readFileSync(viewPath, 'utf8'),
  };
}

/** 语法编译校验（最先跑，报错信息最直观）。返回 false 表示不该继续。 */
function assertCompiles(code, label) {
  try {
    new Function(code);
    console.log('PASS 语法编译 ' + label);
    return true;
  } catch (e) {
    console.error('FAIL 语法错误: ' + e.message);
    return false;
  }
}

/* ==================================================================
 * 视图元信息（id / class / hidden）
 * ================================================================== */

/**
 * 从视图 HTML 提取每个 id 的 `class` 与**是否带 `hidden`**。
 *
 * ⚠ `hidden` 必须提取，不能一律给 false：P3 的「无权限即隐藏」全靠它，
 * 初始化错了会让相关断言恒真通过（见模块头）。
 *
 * ⚠ 只匹配「标签起始到该标签结束」范围内的属性，不跨标签 —— 否则
 * `<div id="a" class="x">…<div id="b" hidden>` 会把 a 也标成 hidden。
 */
function viewMeta(view) {
  const ids = [];
  const cls = {};
  const hidden = {};

  const tagRe = /<[a-zA-Z][^>]*>/g;
  let m;
  while ((m = tagRe.exec(view)) !== null) {
    const tag = m[0];
    const idm = /\sid="([^"]+)"/.exec(tag);
    if (!idm) { continue; }
    const id = idm[1];
    ids.push(id);
    const cm = /\sclass="([^"]+)"/.exec(tag);
    if (cm) { cls[id] = cm[1]; }
    // 裸 `hidden` 或 `hidden="hidden"` 都算
    hidden[id] = /\shidden(?=[\s/>])/.test(tag);
  }

  return { ids: [...new Set(ids)], cls, hidden };
}

/* ==================================================================
 * 假 DOM / 假 window / 假 fetch
 * ================================================================== */

function rawText(node) {
  if (!node) { return ''; }
  const t = node.textContent;
  return typeof t === 'string' ? t : '';
}

/**
 * @param {object} opts
 * @param {{ids: string[], cls: object, hidden: object}} opts.meta  视图元信息
 * @param {object} opts.config     注入 `#<page>-config` 的配置对象
 * @param {string} [opts.search]   初始 `location.search`
 * @param {string} [opts.pathname] 初始 `location.pathname`
 * @param {boolean} [opts.confirm] `window.confirm` 的固定返回值（默认 **false**）
 * @param {object} [opts.initValues] id → 初始 value（模拟浏览器里已选中的下拉项）
 */
function createEnv(opts) {
  const meta = opts.meta;
  const config = opts.config;

  const rec = {
    elementTags: [],
    svgTags: [],
    innerHTMLWrites: [],
    /** 所有定时器调用的记账（**不论是否被执行**）—— 「本页不得有定时器」的判据 */
    timerCalls: [],
    /** 待执行的 setTimeout 队列 —— 「按序列推进有界退避」的驱动源 */
    timers: [],
    /** `clearTimeout` 的记账 —— 「作废旧链」是否**真的**取消了定时器 */
    clearCalls: [],
    confirmCalls: [],
    missingResponses: [],
    missingClicks: [],
  };

  function textNode(text) {
    return { nodeType: 3, textContent: String(text), parentNode: null };
  }

  /** 支持复合选择器（`.note.warn`），否则 `countClass(root, '.note.warn')` 会恒为 0 */
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
      hidden: false,
      disabled: false,
      value: '',
      type: '',
      href: '',
      target: '',
      rel: '',
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
      /** 触发 click；没有绑定 handler 时记账（诊断「按钮没接上」） */
      click() {
        const fns = this.listeners.click || [];
        if (!fns.length) { rec.missingClicks.push(this.id || this.tagName); }
        fns.forEach((fn) => fn({ target: this }));
      },
      /** 触发 input（P3 的字节计数器就是靠它驱动的） */
      fireInput() {
        const fns = this.listeners.input || [];
        if (!fns.length) { rec.missingClicks.push((this.id || this.tagName) + ':input'); }
        fns.forEach((fn) => fn({ target: this }));
      },
      /** 触发 change（动作下拉切换） */
      fireChange() {
        const fns = this.listeners.change || [];
        if (!fns.length) { rec.missingClicks.push((this.id || this.tagName) + ':change'); }
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

    Object.defineProperty(node, 'textContent', {
      get() { return this.childNodes.map(rawText).join(''); },
      set(v) {
        this.childNodes.length = 0;
        const s = String(v);
        if (s !== '') { this.childNodes.push(textNode(s)); }
      },
    });

    Object.defineProperty(node, 'innerHTML', {
      get() { return ''; },
      set(v) { rec.innerHTMLWrites.push(String(v)); },
    });

    return node;
  }

  const byId = {};
  meta.ids.forEach((id) => {
    const node = makeElement('div', null);
    node.id = id;
    node.className = meta.cls[id] || '';
    // ⚠ 与视图同构：`hidden` 必须照抄，否则「无权限即隐藏」的断言会恒真通过
    node.hidden = meta.hidden[id] === true;
    byId[id] = node;
  });

  // 模拟浏览器里「下拉已选中某项」的初值
  const initValues = opts.initValues || {};
  Object.keys(initValues).forEach((id) => {
    if (byId[id]) { byId[id].value = String(initValues[id]); }
  });

  const documentStub = {
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
  };

  /** 定时器句柄自增源 —— 句柄必须**全局唯一**，否则「取消旧链」会误伤新链 */
  let timerSeq = 0;

  const windowStub = {
    location: { search: opts.search || '', pathname: opts.pathname || '/' },
    history: { replaceState() {}, pushState() {} },
    confirm(message) {
      rec.confirmCalls.push(String(message));
      return opts.confirm === true;
    },
    setTimeout(fn, ms) {
      const delay = Number(ms) || 0;
      const id = ++timerSeq;
      rec.timerCalls.push(['setTimeout', delay]);
      rec.timers.push({ id, fn, ms: delay });
      return id;
    },
    setInterval(fn, ms) {
      rec.timerCalls.push(['setInterval', Number(ms) || 0]);
      return 0;
    },
    /**
     * **真的**把定时器从待执行队列里摘掉（不是只记账）。
     *
     * 见模块头第 2 点：只记账不动手会让「作废旧链」的断言恒真。
     * 对已执行过的句柄调用时自然是 no-op（它早已不在队列里）——
     * 这正是浏览器 `clearTimeout` 的语义，不必额外判重。
     */
    clearTimeout(id) {
      rec.clearCalls.push(id);
      const i = rec.timers.findIndex((t) => t.id === id);
      if (i >= 0) { rec.timers.splice(i, 1); }
    },
    clearInterval() {},
    requestAnimationFrame(fn) {
      rec.timerCalls.push(['requestAnimationFrame', 0]);
      return 0;
    },
  };

  const pending = [];
  const fetchLog = [];
  const requestLog = [];

  function fetchStub(url, options) {
    fetchLog.push(url);
    requestLog.push({ url, method: (options && options.method) || 'GET', body: (options && options.body) || null });
    return new Promise((resolve, reject) => {
      pending.push({ url, resolve, reject });
    });
  }

  const env = {
    config,
    rec,
    fetchLog,
    requestLog,
    byId,

    get pendingCount() { return pending.length; },
    pendingUrls() { return pending.map((p) => p.url); },

    text(id) {
      const n = byId[id];
      return n ? rawText(n) : '';
    },
    el(id) { return byId[id] || null; },
    isHidden(id) { const n = byId[id]; return n ? n.hidden === true : null; },
    rows(id) {
      const body = byId[id];
      return body ? body.childNodes.slice() : [];
    },
    countClass(rootId, sel) {
      const root = byId[rootId];
      return root ? root.querySelectorAll(sel).length : -1;
    },
    cells(bodyId, rowIndex) {
      const row = this.rows(bodyId)[rowIndex];
      return row ? row.childNodes.slice() : [];
    },
    /** 点击某行最后一列里的第 n 个按钮（模板列表的「编辑」「删除」靠它） */
    clickRowButton(bodyId, rowIndex, buttonIndex) {
      const cells = this.cells(bodyId, rowIndex);
      const last = cells[cells.length - 1];
      const buttons = last ? last.childNodes.filter((c) => c.nodeType === 1 && c.tagName === 'button') : [];
      const button = buttons[buttonIndex || 0];
      if (!button) {
        rec.missingClicks.push(bodyId + '[' + rowIndex + '].btn[' + (buttonIndex || 0) + ']');
        return;
      }
      button.click();
    },

    setValue(id, v) {
      const n = byId[id];
      if (n) { n.value = String(v); n.fireInput(); }
    },
    /** 只改值不触发 input（用于准备表单状态） */
    put(id, v) {
      const n = byId[id];
      if (n) { n.value = String(v); }
    },
    setSelect(id, v) {
      const n = byId[id];
      if (n) { n.value = String(v); n.fireChange(); }
    },
    click(id) {
      const n = byId[id];
      if (!n) {
        rec.missingClicks.push(id);
        return;
      }
      n.click();
    },

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

    /** 响应「第一个在途请求」（退避补查时不知道确切 URL 的场合） */
    respondFirst(body, opts) {
      if (!pending.length) {
        rec.missingResponses.push('(any)');
        return flush();
      }
      const url = pending[0].url;
      return this.respond(url, body, opts);
    },

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

    /* ---- 定时器驱动（仅用于 `/actions` 的有界退避） ---- */

    hasTimers() { return rec.timers.length > 0; },
    nextDelayMs() { return rec.timers.length ? rec.timers[0].ms : null; },
    /** 执行队首的 setTimeout 回调，并排空微任务 */
    async runNextTimer() {
      if (!rec.timers.length) { return false; }
      const t = rec.timers.shift();
      t.fn();
      await flush();
      return true;
    },
  };

  return { env, documentStub, windowStub, fetchStub };
}

/** 排空微任务：setImmediate 是宏任务，保证前面所有 .then/.catch 都已跑完 */
function flush() {
  return new Promise((resolve) => setImmediate(resolve));
}

/* ==================================================================
 * 启动被测脚本
 * ================================================================== */

/**
 * 把配置塞进 `#<configId>` 的 textContent，然后用注入的替身**整体执行**被测脚本。
 *
 * `new Function('document','window','fetch', code)` 而不是截函数片段：
 * 后者会让「IIFE 顶层的启动逻辑」不被执行，而 P3 两页的首屏渲染恰好全在顶层。
 */
function boot(code, built, configId, config) {
  const node = built.documentStub.getElementById(configId);
  if (!node) {
    throw new Error('视图里没有 #' + configId + ' —— 配置注入点是硬依赖');
  }
  node.textContent = JSON.stringify(config);

  const run = new Function('document', 'window', 'fetch', code);
  run(built.documentStub, built.windowStub, built.fetchStub);

  return flush();
}

module.exports = {
  createReporter,
  loadFiles,
  assertCompiles,
  viewMeta,
  createEnv,
  boot,
  flush,
};

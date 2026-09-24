#!/usr/bin/env node
/**
 * 行为日志页（/audit）渲染校验。
 *
 * 机制与 metrics/trace 页同款：注入假 DOM + 假 fetch，真实加载 /static/audit.js，
 * 校验「取数契约 / 渲染契约 / XSS 纪律 / 零定时器 / 无权限不取数」。
 *
 * 用法：node tests/Frontend/audit_render_check.js   （退出码 0 = 全绿）
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const CODE = fs.readFileSync(path.join(ROOT, 'public', 'static', 'audit.js'), 'utf8');

let pass = 0;
const failures = [];

function check(name, actual, expected) {
  const ok = actual === expected;
  if (ok) { pass += 1; return; }
  failures.push(`FAIL ${name}\n  期望: ${JSON.stringify(expected)}\n  实际: ${JSON.stringify(actual)}`);
}

function checkHas(name, haystack, needle) {
  const ok = typeof haystack === 'string' && haystack.includes(needle);
  if (ok) { pass += 1; return; }
  failures.push(`FAIL ${name}\n  期望包含: ${JSON.stringify(needle)}\n  实际: ${JSON.stringify(String(haystack).slice(0, 300))}`);
}

function checkNo(name, haystack, needle) {
  const ok = typeof haystack === 'string' && !haystack.includes(needle);
  if (ok) { pass += 1; return; }
  failures.push(`FAIL ${name}\n  不得出现: ${JSON.stringify(needle)}`);
}

/* ---------------- 假 DOM（与 metrics_render_check 同款） ---------------- */

function makeNode(tag) {
  const node = {
    tagName: String(tag).toUpperCase(),
    nodeType: 1,
    attributes: {},
    listeners: {},
    childNodes: [],
    parentNode: null,
    className: '',
    hidden: false,
    value: '',
    disabled: false,
    style: {},
    __tbody: null,
  };

  Object.defineProperty(node, 'textContent', {
    get() {
      return node.childNodes.map(function collect(c) {
        if (c.nodeType === 3) { return c.textContent; }
        if (c.nodeType === 1) { return c.childNodes.map(collect).join(''); }
        return '';
      }).join('');
    },
    set(v) {
      node.childNodes = [{ nodeType: 3, textContent: String(v), parentNode: node }];
      if (String(v) === '') { node.__tbody = null; }
    },
  });

  node.setAttribute = function (k, v) { node.attributes[k] = String(v); };
  node.getAttribute = function (k) { return k in node.attributes ? node.attributes[k] : null; };
  node.appendChild = function (c) { c.parentNode = node; node.childNodes.push(c); return c; };
  node.addEventListener = function (ev, fn) {
    (node.listeners[ev] = node.listeners[ev] || []).push(fn);
  };
  node.querySelector = function (sel) {
    const want = String(sel).toUpperCase();
    for (const c of node.childNodes) {
      if (c.tagName === want) { return c; }
      if (c.querySelector) {
        const sub = c.querySelector(sel);
        if (sub) { return sub; }
      }
    }
    return null;
  };
  return node;
}

function boot(config) {
  const byId = {};
  const rec = { fetchUrls: [], timerCalls: [], missingClicks: [] };
  const pending = [];
  const responses = {};

  let flushing = false;
  function flush() {
    if (flushing) { return; }
    flushing = true;
    try {
      for (let i = pending.length - 1; i >= 0; i--) {
        const item = pending[i];
        const key = Object.keys(responses).find(function (k) { return item.url.indexOf(k) === 0; });
        if (key === undefined) { continue; }
        pending.splice(i, 1);
        const payload = responses[key];
        item.resolve({ status: payload.__status || 200, json: function () { return Promise.resolve(payload); } });
      }
    } finally {
      flushing = false;
    }
  }

  async function settle() {
    for (let i = 0; i < 8; i++) {
      flush();
      await new Promise(function (r) { setTimeout(r, 0); });
    }
  }

  const ids = ['audit-page-config', 'sel-action', 'sel-result', 'inp-admin', 'inp-from', 'inp-to',
    'sel-size', 'btn-search', 'btn-reset', 'audit-status', 'tbl-audit', 'btn-prev', 'btn-next', 'page-info'];
  ids.forEach(function (id) { byId[id] = makeNode(id.indexOf('sel-') === 0 ? 'select' : 'div'); });

  // 表挂 tbody
  const tbody = makeNode('tbody');
  byId['tbl-audit-tbody'] = tbody;
  byId['tbl-audit'].querySelector = function (sel) {
    if (String(sel).toUpperCase() === 'TBODY') { return tbody; }
    return null;
  };

  byId['sel-action'].value = '';
  byId['sel-result'].value = '';
  byId['sel-size'].value = '20';
  byId['inp-admin'].value = '';
  byId['inp-from'].value = '';
  byId['inp-to'].value = '';

  const documentStub = {
    getElementById(id) {
      if (!byId[id]) { byId[id] = makeNode('div'); }
      return byId[id];
    },
    createElement(tag) {
      const n = makeNode(tag);
      if (['tr', 'th', 'td', 'span', 'div'].indexOf(String(tag).toLowerCase()) >= 0) { n.colSpan = 1; }
      return n;
    },
    createElementNS(ns, tag) { return makeNode(tag); },
  };

  const windowStub = {
    setTimeout(fn, ms) { rec.timerCalls.push(['setTimeout', Number(ms)]); return 0; },
    setInterval(fn, ms) { rec.timerCalls.push(['setInterval', Number(ms)]); return 0; },
  };

  const fetchStub = function (url) {
    rec.fetchUrls.push(String(url));
    return new Promise(function (resolve) {
      pending.push({ url: String(url), resolve: resolve });
    });
  };

  byId['audit-page-config'].textContent = JSON.stringify(config);

  const run = new Function('document', 'window', 'fetch', CODE);
  run(documentStub, windowStub, fetchStub);

  return {
    byId, rec, tbody,
    async respond(pattern, payload) {
      responses[pattern] = payload;
      flush();
      await settle();
    },
    click(id) {
      const fns = byId[id] && byId[id].listeners && byId[id].listeners.click;
      if (!fns || !fns.length) { rec.missingClicks.push(id); return; }
      fns.forEach(function (f) { f(); });
      flush();
    },
    settle,
  };
}

/* ---------------- 夹具 ---------------- */

const BASE_CFG = {
  list_url: '/api/audit/logs',
  actions: ['push.create', 'ops.kick'],
  results: ['ok', 'failed'],
  page_size_options: [20, 50, 100],
  page_size_default: 20,
  perms: { list: true },
};

function okPayload(rows, page, size, total) {
  return {
    code: 0,
    msg: 'ok',
    data: { rows: rows, page: page || 1, size: size || 20, total: total === undefined ? rows.length : total },
  };
}

function row(id, extra) {
  return Object.assign({
    id: id,
    admin_id: 1,
    admin_name: 'admin',
    action: 'push.create',
    target_type: 'uid',
    target: 'u1',
    params: '{"uid":"u1"}',
    result: 'ok',
    code: 0,
    msg: '',
    created_at: '2026-09-24 12:00:00',
  }, extra || {});
}

async function main() {

  /* ---------------- S1 首屏自动取数 ---------------- */

  const env1 = boot(BASE_CFG);
  check('S1 首屏自动发列表请求', env1.rec.fetchUrls.length >= 1, true);
  checkHas('S1 首屏命中 list_url', env1.rec.fetchUrls[0] || '', '/api/audit/logs');
  checkHas('S1 首屏带默认分页 size=20', env1.rec.fetchUrls[0] || '', 'size=20');

  await env1.respond('/api/audit/logs', okPayload([row(1), row(2)], 1, 20, 45));
  checkHas('S1 表头含「动作」', env1.tbody.textContent, '动作');
  checkHas('S1 渲染管理员', env1.tbody.textContent, 'admin (#1)');
  checkHas('S1 渲染动作名', env1.tbody.textContent, 'push.create');
  checkHas('S1 分页信息', env1.byId['page-info'].textContent, '共 45 条');
  check('S1 首页上一页禁用', env1.byId['btn-prev'].disabled, true);
  check('S1 总页数 >1：下一页可点', env1.byId['btn-next'].disabled, false);

  /* ---------------- S2 空列表如实展示 ---------------- */

  const env2 = boot(BASE_CFG);
  await env2.respond('/api/audit/logs', okPayload([], 1, 20, 0));
  checkHas('S2 空列表文案', env2.tbody.textContent, '无匹配记录');
  check('S2 空列表：下一页禁用', env2.byId['btn-next'].disabled, true);

  /* ---------------- S3 403 如实展示 ---------------- */

  const env3 = boot(BASE_CFG);
  await env3.respond('/api/audit/logs', { __status: 403, code: 403, msg: 'forbidden' });
  checkHas('S3 403 状态栏', env3.byId['audit-status'].textContent, '403');
  checkHas('S3 403 表体', env3.tbody.textContent, '403');

  /* ---------------- S4 无权限：零请求 ---------------- */

  const env4 = boot(Object.assign({}, BASE_CFG, { perms: { list: false } }));
  await env4.settle();
  check('S4 无列表权限：零请求', env4.rec.fetchUrls.length, 0);

  /* ---------------- S5 筛选与翻页 ---------------- */

  const env5 = boot(BASE_CFG);
  await env5.respond('/api/audit/logs', okPayload([row(1)], 1, 20, 40));
  env5.byId['sel-action'].value = 'ops.kick';
  env5.byId['sel-result'].value = 'failed';
  env5.click('btn-search');
  check('S5 查询重置到第 1 页并带筛选', env5.rec.fetchUrls.some(function (u) {
    return u.indexOf('action=ops.kick') >= 0 && u.indexOf('result=failed') >= 0 && u.indexOf('page=1') >= 0;
  }), true);

  await env5.respond('/api/audit/logs', okPayload([row(9, { action: 'ops.kick', result: 'failed' })], 1, 20, 40));
  env5.click('btn-next');
  check('S5 下一页 page=2', env5.rec.fetchUrls.some(function (u) { return u.indexOf('page=2') >= 0; }), true);

  env5.byId['sel-action'].value = '';
  env5.byId['sel-result'].value = '';
  env5.byId['inp-admin'].value = '';
  env5.byId['inp-from'].value = '2026-01-01T00:00:00';
  env5.byId['inp-to'].value = '2026-01-02T00:00:00';
  env5.click('btn-search');
  check('S5 datetime-local 转 Y-m-d H:i:s 进查询串', env5.rec.fetchUrls.some(function (u) {
    return u.indexOf('from=2026-01-01%2000%3A00%3A00') >= 0
      && u.indexOf('to=2026-01-02%2000%3A00%3A00') >= 0;
  }), true);

  env5.byId['sel-action'].value = 'ops.kick';
  env5.byId['sel-result'].value = 'failed';
  env5.byId['inp-admin'].value = '9';
  env5.byId['inp-from'].value = '2026-01-01T00:00:00';
  env5.byId['inp-to'].value = '2026-01-02T00:00:00';
  env5.click('btn-reset');
  check('S5 重置清空筛选', env5.byId['sel-action'].value === ''
    && env5.byId['sel-result'].value === ''
    && env5.byId['inp-admin'].value === ''
    && env5.byId['inp-from'].value === ''
    && env5.byId['inp-to'].value === '', true);
  check('S5 重置回到第 1 页', env5.rec.fetchUrls.some(function (u) {
    return u.indexOf('page=1') >= 0 && u.indexOf('action=') < 0 && u.indexOf('result=') < 0;
  }), true);

  /* ---------------- S6 XSS：服务端回传走 textContent ---------------- */

  const env6 = boot(BASE_CFG);
  const evil = '<img src=x onerror=alert(1)>';
  await env6.respond('/api/audit/logs', okPayload([row(1, { target: evil, admin_name: evil })], 1, 20, 1));
  // textContent 赋值后 DOM 文本应原样保留标记串（未被解释为 HTML）
  checkHas('S6 XSS 载荷原样进文本（不被解释）', env6.tbody.textContent, evil);
  check('S6 ★ 源码无 innerHTML 赋值', /innerHTML\s*=/.test(CODE), false);

  /* ---------------- S7 纪律 ---------------- */

  check('S7 ★ 全程未使用任何定时器',
    env1.rec.timerCalls.length + env2.rec.timerCalls.length + env3.rec.timerCalls.length
    + env4.rec.timerCalls.length + env5.rec.timerCalls.length + env6.rec.timerCalls.length, 0);
  checkNo('S7 源码不使用 eval', CODE, 'eval(');
  checkNo('S7 源码不使用 document.write', CODE, 'document.write');
}

main().then(function () {
  console.log(`audit_render_check: ${pass} 项通过, ${failures.length} 项失败`);
  if (failures.length) {
    console.log(failures.join('\n'));
    process.exit(1);
  }
  process.exit(0);
}).catch(function (e) {
  console.log('FATAL', e);
  process.exit(2);
});

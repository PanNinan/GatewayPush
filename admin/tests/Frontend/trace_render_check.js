#!/usr/bin/env node
/**
 * uid 一站式排查页（/trace）渲染校验（2.0 §3.2）。
 *
 * 机制与 session/ops 页同款：注入假 DOM + 假 fetch，真实加载 /static/trace.js，
 * 校验「四路并行取数契约 / 空值拦截 / 各区块独立成败 / XSS 纪律 / 零定时器」。
 *
 * 用法：node tests/Frontend/trace_render_check.js   （退出码 0 = 全绿）
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const CODE = fs.readFileSync(path.join(ROOT, 'public', 'static', 'trace.js'), 'utf8');

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
    style: {},
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
    },
  });

  node.setAttribute = function (k, v) { node.attributes[k] = String(v); };
    node.focus = function () {};
  node.getAttribute = function (k) { return k in node.attributes ? node.attributes[k] : null; };
  node.appendChild = function (c) { c.parentNode = node; node.childNodes.push(c); return c; };
  node.addEventListener = function (ev, fn) {
    (node.listeners[ev] = node.listeners[ev] || []).push(fn);
  };
  node.querySelector = function (sel) {
    const want = String(sel).toUpperCase();
    for (const c of node.childNodes) {
      if (c.tagName === want) { return c; }
      const sub = c.querySelector ? c.querySelector(sel) : null;
      if (sub) { return sub; }
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
  const statusRef = {};

  let flushing = false;
  function flush() {
    if (flushing) { return; }
    flushing = true;
    try {
      // 只 resolve 已匹配的请求；未匹配的放回队列（respond 逐个注册，并行请求分多轮匹配）
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

  const ids = ['trace-page-config', 'inp-uid', 'inp-device', 'btn-trace', 'trace-status',
    'sec-sessions', 'sec-device', 'sec-offline', 'sec-subs',
    'tbl-sessions', 'tbl-device', 'tbl-offline', 'tbl-subs'];
  ids.forEach(function (id) { byId[id] = makeNode('div'); });
  statusRef.node = byId['trace-status'];

  // 各表挂 tbody
  ['tbl-sessions', 'tbl-device', 'tbl-offline', 'tbl-subs'].forEach(function (id) {
    const tbody = makeNode('tbody');
    byId[id + '-tbody'] = tbody;
    byId[id].querySelector = function () { return tbody; };
  });

  byId['inp-uid'].value = '';
  byId['inp-device'].value = '';

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

  byId['trace-page-config'].textContent = JSON.stringify(config);

  const run = new Function('document', 'window', 'fetch', CODE);
  run(documentStub, windowStub, fetchStub);

  return {
    byId, rec,
    async respond(pattern, payload) {
      responses[pattern] = payload;
      flush();
      await new Promise(function (r) { setTimeout(r, 0); });
    },
    click(id) {
      const fns = byId[id] && byId[id].listeners && byId[id].listeners.click;
      if (!fns || !fns.length) { rec.missingClicks.push(id); return; }
      fns.forEach(function (f) { f(); });
      flush();
    },
    keydown(id) {
      const fns = byId[id] && byId[id].listeners && byId[id].listeners.keydown;
      if (!fns || !fns.length) { return; }
      fns.forEach(function (f) { f({ key: 'Enter' }); });
      flush();
    },
  };
}

/* ---------------- 夹具 ---------------- */

const BASE_CFG = {
  by_uid_url: '/api/sessions/by-uid',
  by_device_url: '/api/sessions/by-device',
  offline_url: '/api/sessions/offline',
  subscriptions_url: '/api/sessions/subscriptions',
  perms: { byUid: true, byDevice: true, offline: true, subs: true },
};

const SESSION_PAYLOAD = {
  code: 0,
  data: {
    items: [{
      client_id: 'ws-1.2.3.4:5000', uid: 'u1', device_id: 'dev1', protocol: 'ws',
      client_ip: '1.2.3.4', client_port: '5000', gateway: 'g1',
      connect_at: 1790202000, last_active: 1790202060, offline_at: 0, state: 'online',
      connect_secs: 60, idle_secs: 0, offline_secs: null,
    }],
    total: 1, page: 1, size: 200, pages: 1, scope: 'uid:u1', online_total: 5,
    scan: null, skeleton_ok: true, hint: '',
  },
};

function setUid(env, v) { env.byId['inp-uid'].value = v; }

async function main() {

/* ---------------- S1 空值 / 非法拦截：不发请求 ---------------- */

const env1 = boot(BASE_CFG);
env1.click('btn-trace');
checkHas('S1 uid 为空：状态栏提示必填', env1.byId['trace-status'].textContent, '必填');
check('S1 uid 为空：零请求', env1.rec.fetchUrls.length, 0);

setUid(env1, 'bad uid!');  // 含空格与!
env1.click('btn-trace');
checkHas('S1 uid 非法字符：给出提示', env1.byId['trace-status'].textContent, '非法');
check('S1 uid 非法：零请求', env1.rec.fetchUrls.length, 0);

setUid(env1, 'ok-uid-1');
env1.byId['inp-device'].value = 'bad device!';
env1.click('btn-trace');
checkHas('S1 device_id 非法：给出提示', env1.byId['trace-status'].textContent, '非法');
check('S1 device_id 非法：零请求', env1.rec.fetchUrls.length, 0);

/* ---------------- S2 正常排查：四路并行 ---------------- */

const env2 = boot(BASE_CFG);
setUid(env2, 'u1');
env2.byId['inp-device'].value = 'dev1';
env2.click('btn-trace');

check('S2 并行发出 4 个请求', env2.rec.fetchUrls.length, 4);
check('S2 by-uid 用 encodeURIComponent 包裹 uid', env2.rec.fetchUrls.some(function (u) { return u === '/api/sessions/by-uid/u1'; }), true);
check('S2 subscriptions 带 uid 查询参数', env2.rec.fetchUrls.some(function (u) { return u === '/api/sessions/subscriptions?uid=u1'; }), true);
check('S2 by-device 用输入的 deviceId', env2.rec.fetchUrls.some(function (u) { return u === '/api/sessions/by-device/dev1'; }), true);

await env2.respond('/api/sessions/by-uid/', SESSION_PAYLOAD);
await env2.respond('/api/sessions/offline/', { code: 0, data: { uid: 'u1', len: 2, items: ['msg-a', 'msg-b'], page: 1, size: 20, pages: 1 } });
await env2.respond('/api/sessions/subscriptions?', { code: 0, data: { uid: 'u1', topics: ['topic.a'], topic: '', subscribers: [] } });
await env2.respond('/api/sessions/by-device/', { code: 0, data: { items: [SESSION_PAYLOAD.data.items[0]], total: 1, scope: 'device:dev1', hint: '' } });

checkHas('S2 会话区块渲染 client_id', env2.byId['tbl-sessions-tbody'].textContent, 'ws-1.2.3.4:5000');
checkHas('S2 会话区块渲染在线状态', env2.byId['tbl-sessions-tbody'].textContent, 'online');
checkHas('S2 离线队列展示真实长度', env2.byId['tbl-offline-tbody'].textContent, '2');
checkHas('S2 订阅区块展示主题', env2.byId['tbl-subs-tbody'].textContent, 'topic.a');
check('S2 有 device_id 输入：设备区块可见', env2.byId['sec-device'].hidden, false);
checkHas('S2 设备区块有结果', env2.byId['tbl-device-tbody'].textContent, 'ws-1.2.3.4:5000');
checkHas('S2 状态栏宣告完成', env2.byId['trace-status'].textContent, 'u1');

/* ---------------- S3 各区块独立成败（403 / 空 / 业务错互不拖累） ---------------- */

const env3 = boot(BASE_CFG);
setUid(env3, 'u2');
env3.click('btn-trace');

await env3.respond('/api/sessions/by-uid/', { __status: 403, code: 403, msg: 'forbidden' });
await env3.respond('/api/sessions/offline/', { code: 0, data: { uid: 'u2', len: 0, items: [], page: 1, size: 20, pages: 0 } });
await env3.respond('/api/sessions/subscriptions?', { code: 400, msg: 'bad' });

checkHas('S3 by-uid 403：区块如实写无权限', env3.byId['tbl-sessions-tbody'].textContent, '403');
checkHas('S3 offline 空队列：正常展示长度 0', env3.byId['tbl-offline-tbody'].textContent, '0');
checkHas('S3 subscriptions 业务错：如实展示', env3.byId['tbl-subs-tbody'].textContent, '400');
checkHas('S3 状态栏仍宣告完成（其余区块不被拖累）', env3.byId['trace-status'].textContent, 'u2');

/* ---------------- S4 Enter 键触发 ---------------- */

const env4 = boot(BASE_CFG);
setUid(env4, 'u3');
env4.keydown('inp-uid');
check('S4 Enter 触发排查（3 请求）', env4.rec.fetchUrls.length, 3);

/* ---------------- S5 纪律 ---------------- */

check('S5 ★ 全程未使用任何定时器', env1.rec.timerCalls.length + env2.rec.timerCalls.length + env3.rec.timerCalls.length + env4.rec.timerCalls.length, 0);
check('S5 ★ 源码无 innerHTML 赋值', /innerHTML\s*=/.test(CODE), false);
checkNo('S5 源码不使用 eval', CODE, 'eval(');

/* ---------------- 输出 ---------------- */

}

main().then(function () {
console.log(`trace_render_check: ${pass} 项通过, ${failures.length} 项失败`);
if (failures.length) {
  console.log(failures.join('\n'));
  process.exit(1);
}

}).catch(function (e) {
  console.log('FATAL', e);
  process.exit(2);
});

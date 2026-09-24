#!/usr/bin/env node
/**
 * 指标趋势页（/metrics）渲染校验（2.0 §1.1）。
 *
 * 机制与 session/ops 页同款：注入假 DOM + 假 fetch，真实加载 /static/metrics.js，
 * 校验「取数契约 / 渲染契约 / XSS 纪律 / 零定时器」。
 *
 * 用法：node tests/Frontend/metrics_render_check.js   （退出码 0 = 全绿）
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const CODE = fs.readFileSync(path.join(ROOT, 'public', 'static', 'metrics.js'), 'utf8');

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

/* ---------------- 假 DOM（最小可用集，与 ops_render_check 同款） ---------------- */

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
  const rec = { fetchUrls: [], timerCalls: [], missingResponses: [] };
  const pending = [];
  const responses = {};
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
        item.resolve({ status: 200, json: function () { return Promise.resolve(payload); } });
      }
    } finally {
      flushing = false;
    }
  }

  // 宏任务边界：resolve 之后 fetch→json→onDone 的微任务链全部跑完
  const settle = function () { return new Promise(function (r) { setTimeout(r, 0); }); };

  const ids = ['metrics-page-config', 'btn-refresh', 'sel-range', 'metrics-status',
    'tbl-latest', 'chart-conn', 'chart-msg', 'chart-fail', 'chart-push', 'empty-hint',
    'sec-latest', 'sec-charts', 'latest-readonly', 'range-readonly'];
  ids.forEach(function (id) { byId[id] = makeNode('div'); });

  const latestTbody = makeNode('tbody');
  byId['tbl-latest'].querySelector = function () { return latestTbody; };

  // select 替身：value 默认取 range_options[0]（与真浏览器「自动选首项」一致）
  const sel = makeNode('select');
  sel.value = String((config.range_options || [60])[0]);
  byId['sel-range'] = sel;

  const documentStub = {
    getElementById(id) {
      if (!byId[id]) { byId[id] = makeNode('div'); }
      return byId[id];
    },
    createElement(tag) { return makeNode(tag); },
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

  byId['metrics-page-config'].textContent = JSON.stringify(config);

  const run = new Function('document', 'window', 'fetch', CODE);
  run(documentStub, windowStub, fetchStub);

  return {
    byId, rec, latestTbody,
    async respond(pattern, payload) {
      responses[pattern] = payload;
      flush();
      await settle();
    },
    click(id) {
      const fns = byId[id] && byId[id].listeners && byId[id].listeners.click;
      if (!fns || !fns.length) { return; }
      fns.forEach(function (f) { f(); });
    },
    change(id) {
      const fns = byId[id] && byId[id].listeners && byId[id].listeners.change;
      if (!fns || !fns.length) { return; }
      fns.forEach(function (f) { f(); });
    },
    settle,
  };
}

/* ---------------- 夹具 ---------------- */

const BASE_CFG = {
  range_url: '/api/metrics/range',
  latest_url: '/api/metrics/latest',
  range_options: [30, 60, 180, 360, 1440],
  points_max: 720,
  sample_interval: 60,
  perms: { range: true, latest: true },
};

function rowAt(ts, countersExtra) {
  return {
    sampled_at: ts,
    conn_ws: 3, conn_udp: 1, conn_total: 4,
    queues: { 'queue:udp:in': 0, 'queue:udp:out': 0 },
    counters: Object.assign({
      msg_in: 100, msg_out: 80, msg_fail: 1, action_ok: 10, action_fail: 0, rate_limit_hit: 2,
      push_in: 50, push_out: 45, push_fail: 1, push_offline: 3, push_dedup: 1,
    }, countersExtra || {}),
    rates: {},
  };
}

async function main() {

  /* ---------------- S1 首屏自动取数 ---------------- */

  const env1 = boot(BASE_CFG);

  check('S1 首屏自动发 latest 请求', env1.rec.fetchUrls.some(function (u) { return u.indexOf('/api/metrics/latest') === 0; }), true);
  check('S1 首屏自动发 range 请求（minutes = range_options[0]）',
    env1.rec.fetchUrls.some(function (u) { return u.indexOf('/api/metrics/range?minutes=30') === 0; }), true);

  await env1.respond('/api/metrics/latest', { code: 0, data: { row: rowAt(1790202000) } });
  await env1.respond('/api/metrics/range', {
    code: 0,
    data: {
      from: 1790201940, to: 1790202000, points: 240,
      rows: [rowAt(1790201940), rowAt(1790201970, { msg_in: 160 }), rowAt(1790202000, { msg_in: 190 })],
      count: 3,
    },
  });

  checkHas('S1 当前值表含在线连接', env1.latestTbody.textContent, '在线连接');
  checkHas('S1 当前值表含队列深度', env1.latestTbody.textContent, 'queue:udp:in');
  checkHas('S1 状态栏显示采样点数', env1.byId['metrics-status'].textContent, '3 个采样点');
  check('S1 在线连接图有子节点（SVG + 图例）', env1.byId['chart-conn'].childNodes.length > 0, true);
  check('S1 消息速率图有子节点', env1.byId['chart-msg'].childNodes.length > 0, true);
  check('S1 异常限流图有子节点', env1.byId['chart-fail'].childNodes.length > 0, true);
  check('S1 推送送达图有子节点（2.0 §3.3）', env1.byId['chart-push'].childNodes.length > 0, true);
  checkHas('S1 当前值表含推送累计（in/out/fail/offline/dedup）',
    env1.latestTbody.textContent, '推送累计');

  /* ---------------- S2 空 dataset：不画假图 ---------------- */

  const env2 = boot(BASE_CFG);
  await env2.respond('/api/metrics/latest', { code: 0, data: { row: null } });
  await env2.respond('/api/metrics/range', { code: 0, data: { from: 0, to: 60, points: 240, rows: [], count: 0 } });

  check('S2 空数据：空数据提示可见', env2.byId['empty-hint'].hidden, false);
  check('S2 空数据：曲线容器无元素子节点（textContent 清空后至多剩空文本）', env2.byId['chart-conn'].childNodes.every(function (c) { return c.nodeType === 3; }), true);
  checkHas('S2 空数据：状态栏如实写 0 点', env2.byId['metrics-status'].textContent, '0 个采样点');

  /* ---------------- S3 手动刷新 + 范围切换 ---------------- */

  const env3 = boot(BASE_CFG);
  await env3.respond('/api/metrics/latest', { code: 0, data: { row: rowAt(1790202000) } });
  await env3.respond('/api/metrics/range', { code: 0, data: { from: 0, to: 60, points: 240, rows: [rowAt(30)], count: 1 } });

  env3.byId['sel-range'].value = '1440';
  env3.change('sel-range');
  await env3.settle();
  check('S3 切范围后以新 minutes 请求', env3.rec.fetchUrls.some(function (u) { return u.indexOf('/api/metrics/range?minutes=1440') === 0; }), true);

  const before = env3.rec.fetchUrls.length;
  env3.click('btn-refresh');
  await env3.settle();
  check('S3 点击刷新重新取数', env3.rec.fetchUrls.length > before, true);

  /* ---------------- S4 无权限变体：零请求 ---------------- */

  const env4 = boot(Object.assign({}, BASE_CFG, { perms: { range: false, latest: false } }));
  check('S4 无权限：不发任何取数请求', env4.rec.fetchUrls.length, 0);

  /* ---------------- S5 纪律 ---------------- */

  check('S5 ★ 全程未使用任何定时器（不轮询是本页设计）',
    env1.rec.timerCalls.length + env2.rec.timerCalls.length + env3.rec.timerCalls.length + env4.rec.timerCalls.length, 0);
  check('S5 ★ 源码无 innerHTML 赋值（textContent-only 防 XSS）', /innerHTML\s*=/.test(CODE), false);
  check('S5 源码无 eval', /\beval\(/.test(CODE), false);
}

main().then(function () {
  console.log(`metrics_render_check: ${pass} 项通过, ${failures.length} 项失败`);
  if (failures.length) {
    console.log(failures.join('\n'));
    process.exit(1);
  }
}).catch(function (e) {
  console.log('FATAL', e);
  process.exit(2);
});

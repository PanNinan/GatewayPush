/**
 * HTTP API 验签校验（Api 进程，默认 127.0.0.1:8290）
 *
 * 覆盖 HTTP 集成（Postman / 业务系统对接）实际会用到的八种请求形态：
 *   1) POST /push            带 body + 正确签名  -> 期望 200
 *   2) GET  /stats           空 body + 正确签名  -> 期望 200（空串参与签名）
 *   3) GET  /stats           错误签名            -> 期望 401
 *   4) GET  /health          无签名              -> 期望 200（免鉴权）
 *   5) POST /action          echo + 正确签名     -> 期望 200（同步等待动作回执）
 *   6) POST /action          session（未开放）   -> 期望 400 / 业务码 4006
 *   7) POST /action          错误签名            -> 期望 401
 *   8) GET  /action/{id}     不存在的 id         -> 期望 404
 *
 * 注意 6)：/action 的拒绝分两层 —— 入队前的失败给 HTTP 4xx（本条），
 * 动作执行后的业务失败给 **HTTP 200 + 响应体 code=4007 等**。详见
 * tests/Api/http_demo.php 与 src/Api/Bootstrap.php 的类注释。
 *
 * 注意 3) 与 7)：这两条断言的是「错误签名被拒绝」。当服务端处于**免签模式**
 * （API_SIGN_ENABLE=false 且监听回环地址）时签名根本不被校验，它们必然不成立。
 * 脚本会先探测模式（不带签名请求 /stats，200 即免签）并把这两项标记为 SKIP，
 * 而不是报 FAIL —— 否则本地调试环境会持续误报门禁红。
 *
 * 密钥从 .env 读取但**不打印**。签名规则与可直接导入的 Postman 集合见
 * postman/GatewayWorker.postman_collection.json。
 *
 * 前置条件：api 角色已启动（`bin/start.bat start api`，或随 all 一起起）；
 * 用例 5) 还需 business 角色（动作由业务进程执行）。
 *
 * 运行：
 *     node tests/Api/api_sign_check.js
 * 退出码 0 = 全绿（含 SKIP），1 = 有断言失败或前置条件缺失。
 *
 * 边界：PHPUnit 套件只扫描 tests/Unit 与 client/tests/Unit，本文件不在其中，
 * 不会与 `composer test` 冲突，需单独执行。
 */

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const http = require('http');

// 相对脚本定位 .env，避免绑定本机绝对路径
const ENV_PATH = path.join(__dirname, '..', '..', '.env');
if (!fs.existsSync(ENV_PATH)) {
  console.error('FAIL 未找到 .env: ' + ENV_PATH);
  process.exit(1);
}
const env = fs.readFileSync(ENV_PATH, 'utf8');

function get(key) {
  const m = env.match(new RegExp('^' + key + '=(.*)$', 'm'));
  if (!m) { return ''; }
  let v = m[1].trim();
  if (/^".*"$/.test(v) || /^'.*'$/.test(v)) { v = v.slice(1, -1); }
  return v;
}

const secret = get('API_SECRET') !== '' ? get('API_SECRET') : get('AUTH_SECRET');

function call(method, path, body, headers) {
  return new Promise((resolve) => {
    const h = Object.assign({}, headers);
    if (body !== null) {
      h['Content-Type'] = 'application/json';
      h['Content-Length'] = Buffer.byteLength(body);
    }
    const req = http.request({ host: '127.0.0.1', port: 8290, path: path, method: method, headers: h }, (res) => {
      let d = '';
      res.on('data', (c) => { d += c; });
      res.on('end', () => { resolve({ status: res.statusCode, body: d }); });
    });
    req.on('error', (e) => { resolve({ status: 0, body: 'ERR ' + e.message }); });
    if (body !== null) { req.write(body); }
    req.end();
  });
}

function mkSign(ts, body) {
  return crypto.createHmac('sha256', secret).update(ts + '|' + body).digest('hex');
}

(async () => {
  const ts = Math.floor(Date.now() / 1000).toString();
  const pushBody = JSON.stringify({
    target_type: 'uid',
    target: 'user-1',
    payload: { action: 'notify', from: 'postman', content: 'hello from postman' },
    msg_id: 'pm-' + ts
  });

  const cases = [];

  // 先探测服务端验签模式：不带签名请求 /stats。
  //   验签开启 → 必被拒（401 / 业务码 4001）
  //   免签模式 → 直接 200
  // 这决定了「错误签名应被拒绝」类断言是否适用。
  const probe = await call('GET', '/stats', null, {});
  if (probe.status === 0) {
    console.error('FAIL api 接口不可达（api 角色未启动？）：' + probe.body);
    process.exit(1);
  }
  const freeMode = probe.status === 200;
  if (freeMode) {
    console.log('模式提示：服务端处于免签模式（API_SIGN_ENABLE=false 且监听回环地址），');
    console.log('          第 3、7 项「错误签名应被拒绝」不适用，将标记为 SKIP。\n');
  }

  cases.push({
    name: '1) POST /push 正确签名',
    expect: '200',
    got: await call('POST', '/push', pushBody, { 'X-Timestamp': ts, 'X-Sign': mkSign(ts, pushBody) })
  });

  cases.push({
    name: '2) GET /stats 空 body 正确签名',
    expect: '200',
    got: await call('GET', '/stats', null, { 'X-Timestamp': ts, 'X-Sign': mkSign(ts, '') })
  });

  const bad = mkSign(ts, 'garbage');
  cases.push({
    name: '3) GET /stats 错误签名',
    expect: '401',
    skip: freeMode,
    skipWhy: '免签模式不校验签名',
    got: freeMode ? null : await call('GET', '/stats', null, { 'X-Timestamp': ts, 'X-Sign': bad })
  });

  cases.push({
    name: '4) GET /health 无签名',
    expect: '200',
    got: await call('GET', '/health', null, {})
  });

  // POST /action 为同步等待语义：服务端最长等待 API_ACTION_WAIT_MS 后回落 202。
  // 用例 5) 的动作应在本机上毫秒级完成，故这里无需额外超时设置。
  const actionBody = JSON.stringify({
    action: 'echo',
    uid: 'sign-check',
    params: { probe: 'api_sign_check' }
  });
  cases.push({
    name: '5) POST /action echo 正确签名',
    expect: '200',
    got: await call('POST', '/action', actionBody, { 'X-Timestamp': ts, 'X-Sign': mkSign(ts, actionBody) })
  });

  // session 依赖 clientId 语义，config/actions.php 中刻意未开放 HTTP 通道，
  // 因此应在「入队前」就被白名单拦下（HTTP 400 + 业务码 4006）
  const sessionBody = JSON.stringify({ action: 'session', uid: 'sign-check' });
  cases.push({
    name: '6) POST /action session 未开放 HTTP 通道',
    expect: '400',
    got: await call('POST', '/action', sessionBody, { 'X-Timestamp': ts, 'X-Sign': mkSign(ts, sessionBody) })
  });

  cases.push({
    name: '7) POST /action 错误签名',
    expect: '401',
    skip: freeMode,
    skipWhy: '免签模式不校验签名',
    got: freeMode ? null : await call('POST', '/action', actionBody, { 'X-Timestamp': ts, 'X-Sign': mkSign(ts, 'garbage') })
  });

  // GET /action/{id} 无请求体，签名基于空串；合法格式但必然不存在的 id 应回 404
  cases.push({
    name: '8) GET /action/{不存在} 正确签名',
    expect: '404',
    got: await call('GET', '/action/ffffffffffffffff', null, { 'X-Timestamp': ts, 'X-Sign': mkSign(ts, '') })
  });

  let fail = 0;
  let skipped = 0;
  for (const c of cases) {
    if (c.skip) {
      skipped++;
      console.log(`SKIP ${c.name}  期望 HTTP ${c.expect} / ${c.skipWhy}`);
      continue;
    }
    const ok = String(c.got.status) === c.expect;
    if (!ok) { fail++; }
    console.log(`${ok ? 'PASS' : 'FAIL'} ${c.name}  期望 HTTP ${c.expect} / 实际 ${c.got.status}`);
    console.log('     ' + c.got.body.slice(0, 160));
  }

  const checked = cases.length - skipped;
  if (fail === 0) {
    console.log(`\n全绿：${checked}/${checked}` + (skipped ? `（另 SKIP ${skipped} 项，免签模式）` : ''));
  } else {
    console.log(`\n失败 ${fail}/${checked}` + (skipped ? `，另 SKIP ${skipped} 项` : ''));
  }
  process.exit(fail === 0 ? 0 : 1);
})();

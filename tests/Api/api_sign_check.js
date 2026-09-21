/**
 * HTTP API 验签校验（Api 进程，默认 127.0.0.1:8290）
 *
 * 覆盖 HTTP 集成（Postman / 业务系统对接）实际会用到的四种请求形态：
 *   1) POST /push  带 body + 正确签名   -> 期望 200
 *   2) GET  /stats 空 body + 正确签名   -> 期望 200（空串参与签名）
 *   3) GET  /stats 错误签名             -> 期望 401
 *   4) GET  /health 无签名              -> 期望 200（免鉴权）
 *
 * 密钥从 .env 读取但**不打印**。签名规则与可直接导入的 Postman 集合见
 * postman/GatewayWorker.postman_collection.json。
 *
 * 前置条件：api 角色已启动（`bin/start.bat start api`，或随 all 一起起）。
 *
 * 运行：
 *     node tests/Api/api_sign_check.js
 * 退出码 0 = 全绿，1 = 有断言失败或前置条件缺失。
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
    got: await call('GET', '/stats', null, { 'X-Timestamp': ts, 'X-Sign': bad })
  });

  cases.push({
    name: '4) GET /health 无签名',
    expect: '200',
    got: await call('GET', '/health', null, {})
  });

  let fail = 0;
  for (const c of cases) {
    const ok = String(c.got.status) === c.expect;
    if (!ok) { fail++; }
    console.log(`${ok ? 'PASS' : 'FAIL'} ${c.name}  期望 HTTP ${c.expect} / 实际 ${c.got.status}`);
    console.log('     ' + c.got.body.slice(0, 160));
  }
  console.log(fail === 0 ? `\n全绿：${cases.length}/${cases.length}` : `\n失败 ${fail}/${cases.length}`);
  process.exit(fail === 0 ? 0 : 1);
})();

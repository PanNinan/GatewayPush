/**
 * GatewayPush 运维页前端渲染校验（P5 + 2.0 序4/序5）。
 *
 * 与 session_render_check.js 同一套机制：假 DOM + 假 window + 假 fetch，
 * 驱动 ops.js 走完全部交互路径。断言围绕四类「静默失效」：
 * 1. 取数挂了留白 / 不摊开 —— 三源探测的每个字段必须可见（含「—」的未知态）；
 * 2. 权限显隐 —— 无权限区块整体隐藏而不是渲染一片残骸；
 * 3. XSS —— 服务端下发的任何文本只走 textContent。
 * 4. 零定时器 —— 运维页没有轮询，「重新探测」必须是显式点击。
 * 5. 序4/序5：队列标红、错误聚合 not_found、配置脱敏摊开同样零 innerHTML。
 *
 * 运行：node tests/Frontend/ops_render_check.js（无需浏览器 / 服务端）
 */
'use strict';

const fs = require('fs');
const path = require('path');

const results = [];
let fatal = null;

function check(name, got, want) {
    const ok = got === want;
    results.push({ ok, name, got: JSON.stringify(got), want: JSON.stringify(want) });
}

function checkHas(name, got, want) {
    const ok = String(got).indexOf(want) >= 0;
    results.push({ ok, name, got: JSON.stringify(String(got)), want: JSON.stringify(want) });
}

const VIEW = path.join(__dirname, '..', '..', 'app', 'view', 'ops', 'index.html');
const SCRIPT = path.join(__dirname, '..', '..', 'public', 'static', 'ops.js');

function read(p) {
    try { return fs.readFileSync(p, 'utf8'); } catch (e) { return ''; }
}

const viewSrc = read(VIEW);
const scriptSrc = read(SCRIPT);
if (!viewSrc) { fatal = '视图 ops/index.html 不可读'; }
if (!scriptSrc) { fatal = 'static/ops.js 不可读'; }

const CODE = scriptSrc;

/* ---------------- 静态检查 ---------------- */

if (!fatal) {
    check('静态：ops.js 无 innerHTML 写入（XSS 纪律）', /\.innerHTML\s*=/.test(CODE), false);
    check('静态：ops.js 无定时器（重新探测是显式点击，不是轮询）',
        /\b(setTimeout|setInterval)\s*\(/.test(CODE), false);
    check('静态：视图只注入一份 config JSON',
        (viewSrc.match(/application\/json" id="ops-page-config/g) || []).length, 1);
}

/* ---------------- 假 DOM 环境（同 session_render_check.js 的机制，裁剪版） ---------------- */

const EVIL = '<img src=x onerror=alert(1)>';

function createEnv(config) {
    const byId = {};
    const rec = {
        timerCalls: [],
        fetchUrls: [],
        missingResponses: [],
        missingClicks: [],
    };
    // 挂起队列模型：先 boot（首屏自动取数会在 respond 之前发出）再 respond，
    // 故请求先记 pending，respond 时按前缀 flush。与 session_render_check.js 同机制。
    const pending = [];
    const responses = {};

    function flush() {
        const due = pending.splice(0, pending.length);
        due.forEach(function (item) {
            const key = Object.keys(responses).find(function (k) { return item.url.indexOf(k) === 0; });
            if (key === undefined) {
                rec.missingResponses.push(item.url);
                return;
            }
            const payload = responses[key];
            item.resolve({ json: function () { return Promise.resolve(payload); } });
        });
    }

    function makeEl(tag) {
        const node = {
            nodeType: 1, tagName: tag, className: '', childNodes: [], parentNode: null,
            hidden: false, value: '', disabled: false,
            listeners: {},
            textContent: '',
            appendChild(child) {
                if (child) {
                    this.childNodes.push(child);
                    child.parentNode = this;
                }
                return child;
            },
            removeChild(child) {
                const i = this.childNodes.indexOf(child);
                if (i >= 0) { this.childNodes.splice(i, 1); }
                return child;
            },
            addEventListener(type, fn) { (this.listeners[type] || (this.listeners[type] = [])).push(fn); },
        };
        Object.defineProperty(node, 'textContent', {
            get() {
                // 递归拼：行内嵌 span/th/td 的层级文本必须能汇总出来（S1 的表格行依赖它）
                return node.childNodes.map(function collect(c) {
                    if (c.nodeType === 3) { return c.textContent; }
                    if (c.nodeType === 1) {
                        return c.childNodes.map(collect).join('');
                    }
                    return '';
                }).join('');
            },
            set(v) {
                this.childNodes = [{ nodeType: 3, textContent: String(v), parentNode: this }];
            },
        });
        return node;
    }

    function container(id) {
        const node = makeEl('div');
        byId[id] = node;
        return node;
    }

    // 视图骨架的 DOM id（与 ops/index.html 对齐）
    ['roles-status', 'tb-roles', 'roles-problems', 'log-status', 'log-output',
        'rotation-status', 'tb-secrets', 'rotation-steps',
        'queues-status', 'tb-queues', 'queues-truncated',
        'errors-status', 'tb-errors', 'err-date', 'err-lines',
        'config-status', 'config-notes', 'tb-config',
        'btn-roles-refresh', 'btn-log-load', 'btn-rotation-load',
        'btn-queues-refresh', 'btn-errors-load', 'btn-config-load',
        'log-role', 'log-date', 'log-lines', 'log-keyword', 'ops-page-config',
        'sec-roles', 'sec-logs', 'sec-rotation',
        'sec-queues', 'sec-errors', 'sec-config'].forEach(container);

    byId['log-role'].value = 'api';
    byId['log-lines'].value = '200';
    byId['log-keyword'].value = '';
    byId['log-date'].value = '';
    byId['err-date'].value = '';
    byId['err-lines'].value = '20';

    const documentStub = {
        getElementById(id) { return byId[id] || null; },
        createElement(tag) { return makeEl(tag); },
        createTextNode(t) { return { nodeType: 3, textContent: String(t), parentNode: null }; },
        addEventListener() {},
    };

    const windowStub = {
        location: { search: '', pathname: '/ops' },
        history: { replaceState() {} },
        setTimeout(fn, ms) { rec.timerCalls.push(['setTimeout', Number(ms)]); return 0; },
        setInterval(fn, ms) { rec.timerCalls.push(['setInterval', Number(ms)]); return 0; },
        clearTimeout() {}, clearInterval() {},
        requestAnimationFrame() { rec.timerCalls.push(['requestAnimationFrame', 0]); return 0; },
    };

    const fetchStub = function (url) {
        rec.fetchUrls.push(String(url));
        return new Promise(function (resolve) {
            pending.push({ url: String(url), resolve: resolve });
        });
    };

    const env = {
        rec,
        config,
        respond(pattern, payload) {
            responses[pattern] = payload;
            flush();
        },
        click(id) {
            const n = byId[id];
            const fns = n && n.listeners && n.listeners.click;
            if (!fns || !fns.length) { rec.missingClicks.push(id); return; }
            fns.forEach(function (f) { f(); });
            flush(); // 点击触发的取数发生在 respond 之后 —— flush 挂起队列
        },
        text(id) { return byId[id] ? byId[id].textContent : ''; },
        rows(id) { return byId[id] ? byId[id].childNodes.slice() : []; },
        el(id) { return byId[id] || null; },
    };

    byId['ops-page-config'].textContent = JSON.stringify(config);

    const run = new Function('document', 'window', 'fetch', CODE);
    run(documentStub, windowStub, fetchStub);

    return env;
}

function rolesPayload(extra) {
    return {
        code: 0, msg: 'ok',
        data: Object.assign({
            roles: [], netstat_available: true, roles_cmd_available: true,
            health: { ok: true }, problems: [],
        }, extra || {}),
    };
}

const CONFIG = {
    logs_url: '/api/ops/logs',
    roles_url: '/api/ops/roles',
    rotation_url: '/api/ops/rotation',
    queues_url: '/api/ops/queues',
    errors_url: '/api/ops/errors',
    config_url: '/api/ops/config',
    log_roles: ['register', 'gateway', 'udp', 'business', 'api', 'dashboard', 'error'],
    tail_max: 500,
    error_default_lines: 20,
    perms: { logs: true, roles: true, rotation: true, queues: true, errors: true, config: true },
};

async function main() {
    if (fatal) { return; }

    /* ---------------- S1 三源摊开 ---------------- */

    const env = createEnv(CONFIG);
    env.respond('/api/ops/roles', rolesPayload({
        roles: [
            { role: 'register', enabled: true, listening: true, listen_count: 1, health_ok: null },
            { role: 'gateway', enabled: true, listening: true, listen_count: 2, health_ok: null },
            { role: 'business', enabled: true, listening: null, listen_count: 0, health_ok: null },
        ],
        problems: ['端口 8282（gateway）有 2 个监听 —— 疑似两套实例叠加（红线 ㊳：Windows 不拒绝重复 bind，表现为「e2e 随机失败」而非报错）'],
    }));
    await new Promise(function (r) { setTimeout(r, 0); });

    check('S1 首屏自动探测（roles 是本页核心问题）', env.rec.fetchUrls.length > 0, true);
    checkHas('S1 角色名可见', env.text('tb-roles'), 'gateway');
    checkHas('S1 疑似重复实例的监听行数摊开', env.text('tb-roles'), '2');
    checkHas('S1 business 无端口 → 未知态用 —（不假装说没监听）', env.text('tb-roles'), '—');
    checkHas('S1 ★ problems 原样展示（红线 ㊳ 提示不删不改写）', env.text('roles-problems'), '疑似两套实例叠加');
    checkHas('S1 汇总条给出问题计数', env.text('roles-status'), '1 个不一致');

    /* ---------------- S2 权限显隐 + 零定时器 ---------------- */

    const env2 = createEnv(Object.assign({}, CONFIG, {
        perms: { logs: false, roles: false, rotation: false, queues: false, errors: false, config: false },
    }));
    check('S2 无权限：全部区块都不再自动取数',
        env2.rec.fetchUrls.length, 0);

    check('S2 ★ 全程未使用任何定时器', env.rec.timerCalls.length, 0);

    /* ---------------- S3 轮换引导摊开 + XSS ---------------- */

    env.respond('/api/ops/rotation', {
        code: 0, msg: 'ok',
        data: {
            secrets: [
                { name: 'AUTH_SECRET', scope: 'WS / UDP', masked: 'ab12****ef34', configured: true },
                { name: 'API_SECRET / ADMIN_API_SECRET', scope: 'HTTP + 管理面', masked: '', configured: false },
            ],
            steps: [{ title: '1. 评估影响面', detail: EVIL }],
        },
    });
    env.click('btn-rotation-load');
    await new Promise(function (r) { setTimeout(r, 0); });

    checkHas('S3 密钥现状（前4后4）可见', env.text('tb-secrets'), 'ab12****ef34');
    checkHas('S3 未配置的密钥如实说「未配置」（不打成掩码制造假象）', env.text('tb-secrets'), '未配置');
    checkHas('S3 步骤清单渲染', env.text('rotation-steps'), '1. 评估影响面');
    check('S3 ★ detail 里的恶意串不产生任何元素（textContent 路径）',
        env.text('rotation-steps').indexOf('<img') >= 0, true);

    /* ---------------- S4 队列深度摊开（2.0 序4） ---------------- */

    env.respond('/api/ops/queues', {
        code: 0, msg: 'ok',
        data: {
            ok: true,
            truncated: true,
            offline_uids: 3,
            thresholds: { queue: 1000, action: 100 },
            rows: [
                { name: 'queue:udp:in', label: 'UDP 入站', kind: 'udp', depth: 1500, threshold: 1000, level: 'bad' },
                { name: 'queue:action:in', label: 'HTTP 动作入站', kind: 'action', depth: 10, threshold: 100, level: 'ok' },
                { name: 'push_offline', label: EVIL, kind: 'offline', depth: 0, threshold: 1000, level: 'ok' },
            ],
        },
    });
    // 首屏已自动打过 queues；再点一次刷新验证按钮路径
    env.click('btn-queues-refresh');
    await new Promise(function (r) { setTimeout(r, 0); });

    checkHas('S4 队列名与深度摊开', env.text('tb-queues'), 'queue:udp:in');
    checkHas('S4 超阈值行标红文案', env.text('tb-queues'), '超阈值');
    checkHas('S4 正常行文案', env.text('tb-queues'), '正常');
    checkHas('S4 汇总条提示超阈值计数', env.text('queues-status'), '超阈值');
    checkHas('S4 SCAN 截断如实上抛（不装全量）', env.text('queues-truncated'), '不保证是全量');
    check('S4 ★ label 恶意串不产生元素', env.text('tb-queues').indexOf('<img') >= 0, true);

    /* ---------------- S5 错误聚合摊开（2.0 序4） ---------------- */

    env.respond('/api/ops/errors', {
        code: 0, msg: 'ok',
        data: {
            date: '2026-09-24',
            keyword: '[ERROR]',
            lines: 20,
            total: 2,
            roles: [
                {
                    role: 'business', ok: true, not_found: false, count: 2, truncated: false,
                    lines: ['[ERROR] push backlog ' + EVIL], hint: '',
                },
                {
                    role: 'dashboard', ok: false, not_found: true, count: 0, truncated: false,
                    lines: [], hint: '日志文件不存在',
                },
                {
                    role: 'gateway', ok: false, not_found: false, count: 0, truncated: false,
                    lines: [], hint: '权限不足',
                },
            ],
        },
    });
    env.click('btn-errors-load');
    await new Promise(function (r) { setTimeout(r, 0); });

    checkHas('S5 error 计数摊开', env.text('tb-errors'), '2');
    checkHas('S5 ★ not_found 如实说「还没有日志」（不渲染成 0 条红字）',
        env.text('tb-errors'), '该文件今天还没有日志');
    checkHas('S5 读失败行带 hint', env.text('tb-errors'), '权限不足');
    checkHas('S5 汇总条给出业务角色合计', env.text('errors-status'), '合计 2');
    check('S5 ★ 原文恶意串不产生元素', env.text('tb-errors').indexOf('<img') >= 0, true);

    /* ---------------- S6 配置查看摊开（2.0 序5，脱敏） ---------------- */

    env.respond('/api/ops/config', {
        code: 0, msg: 'ok',
        data: {
            ok: true, hint: '', file: '/x/.env', mtime: 0, mtime_text: '2026-09-24 08:00:00',
            notes: ['配置无热重载：.env 改动只对新启动的进程生效'],
            groups: [
                {
                    name: 'Redis',
                    items: [
                        { key: 'REDIS_HOST', value: '127.0.0.1', configured: true, secret: false, masked: false },
                        { key: 'REDIS_PASSWORD', value: 'ab12****wxyz', configured: true, secret: true, masked: true },
                    ],
                },
                {
                    name: '密钥（脱敏）',
                    items: [
                        { key: 'API_SECRET', value: '', configured: false, secret: true, masked: true },
                    ],
                },
            ],
        },
    });
    env.click('btn-config-load');
    await new Promise(function (r) { setTimeout(r, 0); });

    checkHas('S6 非密钥键原样展示', env.text('tb-config'), '127.0.0.1');
    checkHas('S6 密钥前4后4', env.text('tb-config'), 'ab12****wxyz');
    checkHas('S6 未配置密钥如实说未配置', env.text('tb-config'), '未配置');
    checkHas('S6 备注（无热重载）摊开', env.text('config-notes'), '无热重载');
    checkHas('S6 汇总条带 mtime（改了未重启的线索）', env.text('config-status'), 'mtime 2026-09-24 08:00:00');
}

main().then(function () {
    const failed = results.filter(function (r) { return !r.ok; });
    results.forEach(function (r, i) {
        const no = String(i + 1).padStart(3, '0');
        console.log((r.ok ? 'PASS ' : 'FAIL ') + no + ' ' + r.name);
        if (!r.ok) {
            console.log('         got  = ' + r.got);
            console.log('         want = ' + r.want);
        }
    });
    console.log('');
    if (fatal) { console.log('⚠ 校验中断：' + fatal); }
    console.log('共 ' + results.length + ' 项，PASS ' + (results.length - failed.length) + '，FAIL ' + failed.length);
    process.exit(failed.length + (fatal ? 1 : 0) ? 1 : 0);
}).catch(function (e) {
    const stack = (e && e.stack ? e.stack : String(e)).split('\n').slice(0, 3).join(' | ');
    console.log('⚠ 校验中断：' + stack);
    process.exit(1);
});

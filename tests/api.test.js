'use strict';
// Testes de integração da API (OPCIONAIS — só para quem desenvolve; exigem Node 20+ na máquina de teste, NÃO no servidor).
// Requer o sistema instalado e rodando num banco de TESTE (nunca em produção: ele cria e apaga dados).
//   BASE_URL=http://IP-OU-HOST ADMIN_EMAIL=admin@x.com ADMIN_PASSWORD='senha' node --test tests/api.test.js
// Rode contra um banco recém-instalado (ou limpe a tabela rate_limits entre execuções).
const test = require('node:test');
const assert = require('node:assert/strict');

const BASE = process.env.BASE_URL || 'http://localhost';
const ADMIN = { email: process.env.ADMIN_EMAIL || 'admin@teste.com', password: process.env.ADMIN_PASSWORD || 'SenhaForte#2026' };
const RUN = Date.now().toString(36);

class Client {
  constructor() { this.cookie = ''; }
  async req(method, path, body, { csrf = true } = {}) {
    const headers = { 'Content-Type': 'application/json' };
    if (csrf) headers['X-Requested-With'] = 'rotina-web';
    if (this.cookie) headers.Cookie = this.cookie;
    const mapped = path.startsWith('/api/') ? '/api.php?r=' + path.slice(5).replace('?', '&') : path;
    const res = await fetch(BASE + mapped, { method, headers, body: body ? JSON.stringify(body) : undefined });
    const set = res.headers.get('set-cookie');
    if (set) this.cookie = set.split(';')[0];
    const buf = Buffer.from(await res.arrayBuffer());     // bytes brutos (res.text() descartaria o BOM)
    const text = buf.toString('utf8');
    let json = null;
    try { json = JSON.parse(text); } catch (_) { json = text; }
    return { status: res.status, body: json, headers: res.headers, raw: buf };
  }
  login(email, password) { return this.req('POST', '/api/auth/login', { email, password }); }
  sync(payload) { return this.req('POST', '/api/sync', payload); }
  boot() { return this.req('GET', '/api/bootstrap'); }
}

const today = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Campo_Grande' });
const mkTask = (o) => ({
  id: 'tsk-' + RUN + '-' + o.n, techEmail: o.tech, shift: 'MANHA', date: today, pppoe: o.pppoe,
  lote: o.lote || 'Lote Teste ' + RUN, priority: 'NORMAL', obs: '', ligou: false, naoAtende: false,
  resolvido: false, osCriada: false, actionId: 'NONE', handleTimeMin: 8, emExecucao: false, finalizado: false,
});

const adm = new Client(), tec1 = new Client(), tec2 = new Client();
const T1 = `tec1.${RUN}@teste.com`, T2 = `tec2.${RUN}@teste.com`;
const U1 = 'usr-t1' + RUN, U2 = 'usr-t2' + RUN;
const mkUser = (id, email, name) => ({ id, name, email, role: 'TEC', shift: 'MANHA', status: 'APROVADO', password: 'Senha12345' });

test('segurança básica: CSRF, login inválido e health', async () => {
  const c = new Client();
  assert.equal((await c.req('POST', '/api/auth/login', ADMIN, { csrf: false })).status, 403);
  assert.equal((await c.login(ADMIN.email, 'errada')).status, 401);
  assert.equal((await c.boot()).status, 401);
  const h = await fetch(BASE + '/api.php?r=healthz');
  assert.equal((await h.json()).status, 'ok');
  assert.equal(h.headers.get('x-content-type-options'), 'nosniff');
});

test('admin: login, bootstrap e cookie httpOnly', async () => {
  const r = await adm.login(ADMIN.email, ADMIN.password);
  assert.equal(r.status, 200);
  assert.ok(r.headers.get('set-cookie').toLowerCase().includes('httponly'));
  assert.ok(r.headers.get('set-cookie').toLowerCase().includes('samesite=strict'));
  const b = (await adm.boot()).body;
  assert.equal(b.user.role, 'ADM');
  assert.ok(b.actions.length >= 7);
  const st1 = (await adm.req('GET', '/api/stamp')).body.stamp;
  assert.match(st1, /^[0-9a-f]{32}$/);
  assert.equal((await adm.req('GET', '/api/stamp')).body.stamp, st1);   // sem mudanças: mesmo carimbo
  for (const l of ['Troca de equipamento', 'Ajuste de DBM', 'Necessário visita técnica ao local', 'Feito procedimento no equipamento', 'Outros', 'Sem sucesso no contato']) assert.ok(b.actions.some(a => a.label === l), 'procedimento ' + l);
  assert.ok(!b.actions.some(a => a.id === 'REBOOT_ONU'));
  assert.ok(!JSON.stringify(b.users).includes('password'));
});

test('admin cria técnicos (senha fraca é rejeitada)', async () => {
  const weak = await adm.sync({ users: { upsert: [{ ...mkUser(U1, T1, 'Tec Um'), password: '123' }] } });
  assert.equal(weak.status, 400);
  const ok = await adm.sync({ users: { upsert: [mkUser(U1, T1, 'Tec Um'), mkUser(U2, T2, 'Tec Dois')] } });
  assert.equal(ok.status, 200);
  assert.equal((await tec1.login(T1, 'Senha12345')).status, 200);
  assert.equal((await tec2.login(T2, 'Senha12345')).status, 200);
});

test('criação em lote + regra de PPPoE único em aberto (atomicidade)', async () => {
  const tasks = [1, 2, 3].map(n => mkTask({ n, tech: n === 3 ? T2 : T1, pppoe: `cli${n}.${RUN}@telecom` }));
  const r = await adm.sync({ tasks: { upsert: tasks } });
  assert.equal(r.status, 200);
  assert.equal(r.body.tasks.length, 3);
  assert.ok(r.body.tasks.every(t => t.version === 1));

  // duplicado em aberto + uma tarefa válida no mesmo envio => NADA deve ser gravado
  const dup = mkTask({ n: 4, tech: T1, pppoe: `CLI1.${RUN}@telecom` });       // mesmo PPPoE (case-insensitive)
  const good = mkTask({ n: 5, tech: T1, pppoe: `cli5.${RUN}@telecom` });
  const bad = await adm.sync({ tasks: { upsert: [good, dup] } });
  assert.equal(bad.status, 409);
  const ids = (await adm.boot()).body.tasks.map(t => t.id);
  assert.ok(!ids.includes(good.id), 'transação deveria ter sido revertida por inteiro');
});

test('atribuição única: flag single gravada na criação, devolvida pela API e imutável depois', async () => {
  const one = { ...mkTask({ n: 70, tech: T1, pppoe: `unica70.${RUN}@telecom`, lote: 'Avulso' }), single: true };
  const lot = mkTask({ n: 71, tech: T1, pppoe: `lote71.${RUN}@telecom` });
  const r = await adm.sync({ tasks: { upsert: [one, lot] } });
  assert.equal(r.status, 200);
  const byId = (id) => r.body.tasks.find(t => t.id === id);
  assert.equal(byId(one.id).single, true);
  assert.equal(byId(lot.id).single, false);
  // tentar virar "lote" depois de criada não muda nada
  const again = await adm.sync({ tasks: { upsert: [{ ...byId(one.id), single: false, obs: 'x' }] } });
  assert.equal(again.status, 200);
  assert.equal((await adm.boot()).body.tasks.find(t => t.id === one.id).single, true);
});

test('pendente de contato: exige observação, sai da fila, outro técnico assume e o ADM vê o ranking', async () => {
  const t = mkTask({ n: 72, tech: T1, pppoe: `pend72.${RUN}@telecom` });
  assert.equal((await adm.sync({ tasks: { upsert: [t] } })).status, 200);
  const mine = (await tec1.boot()).body.tasks.find(x => x.id === t.id);
  // sem observação, ou ainda "em atendimento", não deixa
  assert.equal((await tec1.sync({ tasks: { upsert: [{ ...mine, pendente: true, pendenteNota: '' }] } })).status, 400);
  assert.equal((await tec1.sync({ tasks: { upsert: [{ ...mine, pendente: true, pendenteNota: 'Liguei 3x', emExecucao: true }] } })).status, 400);
  const r = await tec1.sync({ tasks: { upsert: [{ ...mine, pendente: true, pendenteNota: 'Liguei 3x, tentar após 7h' }] } });
  assert.equal(r.status, 200);
  const left = r.body.tasks.find(x => x.id === t.id);
  assert.equal(left.pendente, true);
  assert.ok(left.pendenteDesde);
  // outro técnico enxerga e assume (vira dono, sai do pool, entra em atendimento)
  const seen = (await tec2.boot()).body.tasks.find(x => x.id === t.id);
  assert.equal(seen.pendente, true);
  assert.equal(seen.pendenteNota, 'Liguei 3x, tentar após 7h');
  const r2 = await tec2.sync({ tasks: { upsert: [{ ...seen, techEmail: T2, pendente: false, pendenteNota: '', emExecucao: false }] } });
  assert.equal(r2.status, 200);
  const took = r2.body.tasks.find(x => x.id === t.id);
  assert.equal(took.techEmail, T2);
  assert.equal(took.pendente, false);
  assert.equal(took.emExecucao, false);        // assumir só leva para a fila: não inicia sozinho
  assert.ok(!took.startedAt);                   // e não herda o início do atendimento anterior
  // quando estiver pronto, ele inicia: aí sim o atendimento começa (início novo)
  const r3 = await tec2.sync({ tasks: { upsert: [{ ...took, emExecucao: true }] } });
  assert.equal(r3.status, 200);
  assert.equal(r3.body.tasks.find(x => x.id === t.id).emExecucao, true);
  assert.ok(r3.body.tasks.find(x => x.id === t.id).startedAt);
  // ranking: só ADM
  const st = await adm.req('GET', '/api/pending/stats?days=30');
  assert.equal(st.status, 200);
  assert.ok(st.body.people.some(p => p.email === T1 && p.left >= 1));
  assert.ok(st.body.people.some(p => p.email === T2 && p.took >= 1));
  assert.equal((await tec1.req('GET', '/api/pending/stats')).status, 403);
});

test('análises (gráficos): ADM vê a equipe toda, técnico só os seus; e-mail e dados de acesso só para o ADM', async () => {
  const y = await adm.req('GET', '/api/analytics?range=YEAR');
  assert.equal(y.status, 200);
  assert.equal(y.body.series.length, 12);
  assert.equal(y.body.bucket, 'month');
  assert.ok(Array.isArray(y.body.techs) && Array.isArray(y.body.shifts));
  assert.equal(y.body.totals.finished, y.body.series.reduce((n, s) => n + s.finished, 0));
  const m = await tec1.req('GET', '/api/analytics?range=MONTH');
  assert.equal(m.status, 200);
  assert.equal(m.body.series.length, 30);
  assert.deepEqual(m.body.techs, []);                       // técnico não vê os colegas
  assert.equal((await adm.req('GET', '/api/analytics?range=XYZ')).body.series.length, 7);   // período inválido cai em semana
  // e-mail / último login só para o ADM
  const tm = await adm.req('GET', '/api/team');
  assert.ok(tm.body.team.length > 0 && tm.body.team.every(x => typeof x.email === 'string' && 'lastLoginAt' in x));
  assert.ok((await tec1.req('GET', '/api/team')).body.team.every(x => x.email === null && x.lastLoginAt === null));
  const id = tm.body.team[0].id;
  const pa = await adm.req('GET', '/api/profile?id=' + id);
  assert.equal(pa.status, 200);
  assert.ok(pa.body.admin && 'lastLoginAt' in pa.body.admin && 'pend30Left' in pa.body.admin);
  assert.equal((await tec1.req('GET', '/api/profile?id=' + id)).body.admin, null);
});

test('plano de fundo do login: informação pública, mas só o ADM envia, troca ou remove', async () => {
  const anon = new Client();
  const pub = await anon.req('GET', '/api/branding/login');          // a tela de login ainda não tem sessão
  assert.equal(pub.status, 200);
  assert.ok('kind' in pub.body && 'v' in pub.body);
  assert.equal((await tec1.req('GET', '/api/admin/branding')).status, 403);
  assert.equal((await tec1.req('POST', '/api/admin/branding/remove', {})).status, 403);
  assert.equal((await tec1.req('POST', '/api/admin/branding/finish', { id: 'a'.repeat(24), name: 'x' })).status, 403);
  assert.equal((await anon.req('POST', '/api/admin/branding/remove', {})).status, 401);
  const info = await adm.req('GET', '/api/admin/branding');
  assert.equal(info.status, 200);
  assert.equal(info.body.chunkBytes, 1048576);
  assert.ok(info.body.maxVideo >= 1048576);
  // fechar um envio que não existe, ou com identificador malicioso, é recusado
  assert.equal((await adm.req('POST', '/api/admin/branding/finish', { id: 'b'.repeat(24), name: 'x.mp4' })).status, 400);
  assert.equal((await adm.req('POST', '/api/admin/branding/finish', { id: '../../etc/passwd', name: 'x' })).status, 400);
  // remover sem nada enviado não dá erro
  assert.equal((await adm.req('POST', '/api/admin/branding/remove', {})).status, 200);
  assert.equal((await anon.req('GET', '/api/branding/login-file')).status, 404);
});

test('técnico atualiza a própria verificação; versão sobe; finalizar grava finished_at', async () => {
  const b = (await tec1.boot()).body;
  const t = b.tasks.find(x => x.id === `tsk-${RUN}-1`);
  const r = await tec1.sync({ tasks: { upsert: [{ ...t, ligou: true, obs: 'Cliente atendeu', actionId: 'PROCEDIMENTO_EQUIPAMENTO', emExecucao: true }] } });
  assert.equal(r.status, 200);
  assert.equal(r.body.tasks[0].version, 2);
  assert.ok(r.body.tasks[0].startedAt);

  const r2 = await tec1.sync({ tasks: { upsert: [{ ...r.body.tasks[0], finalizado: true, resolvido: true, emExecucao: false }] } });
  assert.equal(r2.status, 200);
  assert.ok(r2.body.tasks[0].finishedAt);
  assert.equal(r2.body.tasks[0].finalizado, true);
});

test('controle de concorrência: versão desatualizada => 409', async () => {
  const t = (await tec1.boot()).body.tasks.find(x => x.id === `tsk-${RUN}-2`);
  // admin muda no meio do caminho
  const a = await adm.sync({ tasks: { upsert: [{ ...t, priority: 'URGENTE' }] } });
  assert.equal(a.status, 200);
  // técnico ainda com a versão antiga
  const r = await tec1.sync({ tasks: { upsert: [{ ...t, ligou: true }] } });
  assert.equal(r.status, 409);
  assert.equal(r.body.code, 'CONFLICT');
});

test('permissões do técnico (servidor não confia no front)', async () => {
  const boot = (await tec1.boot()).body;
  const mine = boot.tasks.find(x => x.id === `tsk-${RUN}-2`);
  const others = boot.tasks.find(x => x.id === `tsk-${RUN}-3`);       // pertence ao tec2

  // não pode mudar prioridade/prazo da própria
  assert.equal((await tec1.sync({ tasks: { upsert: [{ ...mine, priority: 'NORMAL' }] } })).status, 403);
  // não pode editar tarefa alheia sem assumir
  assert.equal((await tec1.sync({ tasks: { upsert: [{ ...others, obs: 'invadindo' }] } })).status, 403);
  // não pode atribuir tarefa para outra pessoa
  assert.equal((await tec1.sync({ tasks: { upsert: [{ ...mine, techEmail: T2 }] } })).status, 403);
  // não cria nem exclui
  assert.equal((await tec1.sync({ tasks: { upsert: [mkTask({ n: 9, tech: T1, pppoe: `x9.${RUN}` })] } })).status, 403);
  assert.equal((await tec1.sync({ tasks: { delete: [mine.id] } })).status, 403);
  // não mexe em procedimentos, nem se promove
  assert.equal((await tec1.sync({ actions: { upsert: [{ id: 'HACK', label: 'x' }] } })).status, 403);
  const me = boot.users.find(u => u.id === U1);
  assert.equal((await tec1.sync({ users: { upsert: [{ ...me, role: 'ADM' }] } })).status, 403);
  // não vê usuários pendentes/bloqueados nem hashes
  assert.ok(!JSON.stringify(boot).includes('password_hash'));
});

test('takeover: técnico assume tarefa aberta de outro (e auditoria registra)', async () => {
  const others = (await tec1.boot()).body.tasks.find(x => x.id === `tsk-${RUN}-3`);
  const r = await tec1.sync({ tasks: { upsert: [{ ...others, techEmail: T1, obs: '[Assumido por Tec Um]' }] } });
  assert.equal(r.status, 200);
  assert.equal(r.body.tasks[0].techEmail, T1);
  const audit = (await adm.req('GET', `/api/audit?entity=verification&entityId=tsk-${RUN}-3`)).body.items;
  assert.ok(audit.some(a => a.event === 'TASK_TAKEOVER' && a.user_email === T1));
});

test('cadastro público => PENDENTE => aprovação pelo admin', async () => {
  const c = new Client(); const email = `novo.${RUN}@teste.com`;
  assert.equal((await c.req('POST', '/api/auth/register', { name: 'Novo Tec', email, password: 'abc', shift: 'TARDE' })).status, 400);
  assert.equal((await c.req('POST', '/api/auth/register', { name: 'Novo Tec', email, password: 'SenhaBoa123', shift: 'TARDE' })).status, 201);
  assert.equal((await c.req('POST', '/api/auth/register', { name: 'Novo Tec', email, password: 'SenhaBoa123' })).status, 409);
  const p = await c.login(email, 'SenhaBoa123');
  assert.equal(p.status, 403); assert.equal(p.body.code, 'PENDENTE');
  const u = (await adm.boot()).body.users.find(x => x.email === email);
  assert.equal(u.status, 'PENDENTE'); assert.equal(u.role, 'TEC');
  assert.equal((await adm.sync({ users: { upsert: [{ ...u, status: 'APROVADO' }] } })).status, 200);
  assert.equal((await c.login(email, 'SenhaBoa123')).status, 200);
});

test('sem bloqueio de conta: errar a senha várias vezes não trava o login correto', async () => {
  const c = new Client();
  for (let i = 0; i < 6; i++) assert.equal((await c.login(T2, 'errada' + i)).status, 401);
  assert.equal((await c.login(T2, 'Senha12345')).status, 200);        // correta continua entrando
});

test('regras de admin: não se rebaixa, não exclui a si, troca de senha invalida sessões antigas', async () => {
  const me = (await adm.boot()).body.user;
  assert.equal((await adm.sync({ users: { upsert: [{ ...me, role: 'TEC' }] } })).status, 400);
  assert.equal((await adm.sync({ users: { delete: [me.id] } })).status, 400);

  // tec1 troca a própria senha: sessão atual segue valida (cookie reemitido)
  const oldCookie = tec1.cookie;
  const t = (await tec1.boot()).body.users.find(u => u.id === U1);
  const r = await tec1.sync({ users: { upsert: [{ ...t, password: 'NovaSenha999' }] } });
  assert.equal(r.status, 200);
  assert.equal((await tec1.boot()).status, 200);
  // cookie antigo morreu
  const stale = new Client(); stale.cookie = oldCookie;
  assert.equal((await stale.boot()).status, 401);
});

test('exportação CSV (BOM, proteção contra fórmula) e exclusão de lote', async () => {
  const t = (await adm.boot()).body.tasks.find(x => x.id === `tsk-${RUN}-2`);
  await adm.sync({ tasks: { upsert: [{ ...t, obs: '=HYPERLINK("http://x")' }] } });
  const csv = await adm.req('GET', `/api/export/verifications.csv?from=${today}&to=${today}`);
  assert.deepEqual([...csv.raw.subarray(0, 3)], [0xEF, 0xBB, 0xBF]);   // BOM UTF-8 p/ Excel
  assert.ok(csv.body.includes(`"'=HYPERLINK`));
  assert.equal((await tec1.req('GET', '/api/export/verifications.csv')).status, 403);

  const ids = (await adm.boot()).body.tasks.filter(x => x.lote === 'Lote Teste ' + RUN).map(x => x.id);
  const del = await adm.sync({ tasks: { delete: ids } });
  assert.equal(del.status, 200);
  assert.equal((await adm.boot()).body.tasks.filter(x => x.lote === 'Lote Teste ' + RUN).length, 0);
});

test('procedimentos: exclusão lógica preserva histórico; "NONE" é protegido', async () => {
  assert.equal((await adm.sync({ actions: { upsert: [{ id: 'ACT_' + RUN, label: 'Novo Proc' }] } })).status, 200);
  assert.ok((await adm.boot()).body.actions.some(a => a.id === 'ACT_' + RUN));
  assert.equal((await adm.sync({ actions: { delete: ['ACT_' + RUN] } })).status, 200);
  assert.ok(!(await adm.boot()).body.actions.some(a => a.id === 'ACT_' + RUN));
  assert.equal((await adm.sync({ actions: { delete: ['NONE'] } })).status, 400);
});

test('anti-XSS: caracteres perigosos em PPPoE, lote, nome e procedimento são rejeitados', async () => {
  const ok = mkTask({ n: 70, tech: T1, pppoe: `xss70.${RUN}` });
  assert.equal((await adm.sync({ tasks: { upsert: [{ ...ok, pppoe: '<img src=x onerror=alert(1)>' }] } })).status, 400);
  assert.equal((await adm.sync({ tasks: { upsert: [{ ...ok, lote: "x');alert(1);//" }] } })).status, 400);
  assert.equal((await adm.sync({ tasks: { upsert: [{ ...ok, lote: 'Lote "aspas"' }] } })).status, 400);
  assert.equal((await adm.sync({ actions: { upsert: [{ id: 'ACT_X' + RUN, label: '<b>x</b>' }] } })).status, 400);
  const me = (await adm.boot()).body.user;
  assert.equal((await adm.sync({ users: { upsert: [{ ...me, name: '<script>x</script>' }] } })).status, 400);
  const reg = await new Client().req('POST', '/api/auth/register', { name: '<b>Hack</b>', email: `h.${RUN}@t.com`, password: 'SenhaBoa123' });
  assert.equal(reg.status, 400);
  // nomes legítimos (acentos, hífen, barra) continuam funcionando
  const fine = { ...ok, pppoe: `joão.silva-${RUN}@telecom`, lote: 'Lote 03/10 – Manhã (A)'.replace('–', '-') };
  assert.equal((await adm.sync({ tasks: { upsert: [fine] } })).status, 200);
  await adm.sync({ tasks: { delete: [fine.id] } });
});

// ======================= v1.3.0: PPPoE já verificados, classe ADICIONAL e auditoria =======================
test('PPPoE já verificado: checagem avisa, nova verificação vira ADICIONAL e o histórico cresce', async () => {
  const login = `repetido.${RUN}@telecom`;
  const base = mkTask({ n: 90, tech: T1, pppoe: login, lote: 'Lote Hist ' + RUN });

  // 1) primeira vez: classe NORMAL, ninguém tem histórico ainda
  let chk = await adm.req('POST', '/api/pppoe/check', { logins: [login, 'nunca.visto.' + RUN] });
  assert.equal(chk.status, 200);
  assert.deepEqual(Object.keys(chk.body.items), []);
  const c1 = await adm.sync({ tasks: { upsert: [base] } });
  assert.equal(c1.status, 200);
  assert.equal(c1.body.tasks[0].kind, 'NORMAL');
  assert.equal(c1.body.tasks[0].prevCount, 0);

  // 2) técnico trabalha e conclui (cada passo gera linhas de histórico)
  let t = c1.body.tasks[0];
  t = (await tec1.sync({ tasks: { upsert: [{ ...t, emExecucao: true }] } })).body.tasks[0];
  t = (await tec1.sync({ tasks: { upsert: [{ ...t, ligou: true, naoAtende: false }] } })).body.tasks[0];
  t = (await tec1.sync({ tasks: { upsert: [{ ...t, obs: 'Troquei o canal do Wi-Fi', actionId: 'TROCA_EQUIPAMENTO' }] } })).body.tasks[0];
  const done = await tec1.sync({ tasks: { upsert: [{ ...t, finalizado: true, emExecucao: false, resolvido: true }] } });
  assert.equal(done.status, 200);

  // 3) agora a checagem encontra o histórico (sem diferenciar maiúsculas) e o resultado anterior
  chk = await adm.req('POST', '/api/pppoe/check', { logins: [login.toUpperCase(), 'nunca.visto.' + RUN] });
  const hit = chk.body.items[login.toLowerCase()];
  assert.ok(hit, 'deveria achar o PPPoE já verificado');
  assert.equal(hit.total, 1);
  assert.equal(hit.last.resolvido, true);
  assert.equal(hit.last.tech, 'Tec Um');
  assert.equal(hit.open, null);
  assert.equal(Object.keys(chk.body.items).length, 1);

  // 4) técnico NÃO pode consultar essa checagem
  assert.equal((await tec1.req('POST', '/api/pppoe/check', { logins: [login] })).status, 403);

  // 5) reatribuir: servidor decide a classe (mesmo que o navegador mande NORMAL)
  const again = { ...base, id: 'tsk-' + RUN + '-91', kind: 'NORMAL', techEmail: T2, lote: 'Lote Hist 2 ' + RUN };
  const c2 = await adm.sync({ tasks: { upsert: [again] } });
  assert.equal(c2.status, 200);
  assert.equal(c2.body.tasks[0].kind, 'ADICIONAL');
  assert.equal(c2.body.tasks[0].prevCount, 1);
  assert.equal(c2.body.tasks[0].repeatOf, base.id);

  // 6) enquanto a adicional está aberta, a checagem mostra a verificação em aberto
  chk = await adm.req('POST', '/api/pppoe/check', { logins: [login] });
  assert.equal(chk.body.items[login.toLowerCase()].open.id, again.id);

  // 7) auditoria da primeira verificação: linha do tempo completa, em ordem, com autor
  const au = await adm.req('GET', '/api/verification/audit?id=' + base.id);
  assert.equal(au.status, 200);
  // a auditoria é UMA só por PPPoE: traz as duas verificações, cada linha marcada com o id da sua verificação (vid)
  const mine = au.body.events.filter(e => e.vid === base.id);
  const ev = mine.map(e => e.event);
  assert.deepEqual(ev, ['CRIADA', 'INICIADA', 'CHECKLIST', 'ACAO', 'OBS', 'CHECKLIST', 'CONCLUIDA']);
  assert.equal(mine[0].user_email, ADMIN.email);
  assert.equal(mine[6].user_email, T1);
  assert.equal(mine[6].details.resolvido, true);
  assert.ok(au.body.events.some(e => e.vid === again.id));      // a 2ª verificação aparece na mesma auditoria
  assert.deepEqual(au.body.rounds.map(r => [r.id, r.n, r.current]), [[base.id, 1, true], [again.id, 2, false]]);
  assert.equal(au.body.others.length, 1);                       // a verificação adicional
  assert.equal(au.body.others[0].id, again.id);
  assert.equal(au.body.others[0].kind, 'ADICIONAL');

  // 8) a adicional enxerga a anterior e o histórico mostra a classe na criação
  const au2 = await adm.req('GET', '/api/verification/audit?id=' + again.id);
  assert.equal(au2.body.events.find(e => e.vid === again.id && e.event === 'CRIADA').details.classe, 'ADICIONAL');
  assert.deepEqual(au2.body.rounds.map(r => [r.n, r.current]), [[1, false], [2, true]]);   // marca em que ponto estamos
  assert.equal(au2.body.others[0].resolvido, true);

  // 9) técnico não vê auditoria de verificação concluída de outro técnico; id inválido/inexistente
  assert.equal((await tec2.req('GET', '/api/verification/audit?id=' + base.id)).status, 200);   // comparativo: colegas podem consultar
  assert.equal((await adm.req('GET', '/api/verification/audit?id=nao-existe')).status, 404);
  assert.equal((await adm.req('GET', '/api/verification/audit?id=' + encodeURIComponent('x y'))).status, 400);

  // 10) CSV traz a classe
  const csv = await adm.req('GET', `/api/export/verifications.csv?from=${today}&to=${today}`);
  assert.ok(csv.raw.toString('utf8').includes('Adicional'));
});

test('reatribuição de verificação em aberto não duplica o PPPoE e registra o histórico', async () => {
  const login = `aberto.${RUN}@telecom`;
  const tk = mkTask({ n: 95, tech: T1, pppoe: login });
  const r = await adm.sync({ tasks: { upsert: [tk] } });
  assert.equal(r.status, 200);
  const moved = await adm.sync({ tasks: { upsert: [{ ...r.body.tasks[0], techEmail: T2, date: today, priority: 'ALTA' }] } });
  assert.equal(moved.status, 200);
  assert.equal(moved.body.tasks[0].kind, 'NORMAL');
  const au = await adm.req('GET', '/api/verification/audit?id=' + tk.id);
  assert.deepEqual(au.body.events.map(e => e.event), ['CRIADA', 'REATRIBUIDA', 'AJUSTE']);
  assert.equal(au.body.events[1].details.para, T2);
  // 'kind' não pode ser alterado depois de criada
  const hack = await adm.sync({ tasks: { upsert: [{ ...moved.body.tasks[0], kind: 'ADICIONAL' }] } });
  assert.equal(hack.status, 200);
  assert.equal((await adm.req('GET', '/api/verification/audit?id=' + tk.id)).body.verification.kind, 'NORMAL');
});

// ======================= v1.4.0: equipe online, perfil (bio) e indicadores =======================
test('equipe: batimento marca online, logout marca offline, técnico vê os colegas', async () => {
  const t1 = await tec1.req('GET', '/api/team');
  assert.equal(t1.status, 200);
  const me = t1.body.team.find(x => x.me);
  assert.ok(me && me.online, 'quem bate o ponto aparece online');
  assert.equal(me.name, 'Tec Um');
  const seenByOther = (await tec2.req('GET', '/api/team')).body.team;
  assert.ok(seenByOther.find(x => x.id === U1).online, 'o colega enxerga o outro online');
  assert.ok(!JSON.stringify(seenByOther).includes('@'), 'a lista da equipe não expõe e-mails');
  assert.equal((await new Client().req('GET', '/api/team')).status, 401);
});

test('perfil: bio própria, indicadores (TMA e taxa de sucesso) e privacidade', async () => {
  assert.equal((await tec1.req('POST', '/api/profile/bio', { bio: 'x'.repeat(501) })).status, 400);
  const ok = await tec1.req('POST', '/api/profile/bio', { bio: 'Foco em fibra óptica.\nGosto de café.' });
  assert.equal(ok.status, 200);

  const p = await tec2.req('GET', '/api/profile?id=' + U1);       // outro técnico visita o perfil
  assert.equal(p.status, 200);
  assert.equal(p.body.user.bio, 'Foco em fibra óptica.\nGosto de café.');
  assert.equal(p.body.user.email, null);                           // e-mail só o administrador vê
  assert.equal(p.body.user.me, false);
  assert.ok(p.body.stats.all.total >= 1);
  assert.ok(p.body.stats.all.successRate >= 0 && p.body.stats.all.successRate <= 100);
  assert.equal(p.body.daily.length, 14);
  const a = await adm.req('GET', '/api/profile?id=' + U1);
  assert.equal(a.body.user.email, T1);

  assert.equal((await tec2.req('GET', '/api/profile?id=' + 'nao-existe')).status, 404);
  assert.equal((await tec1.req('POST', '/api/profile/bio', { bio: '' })).body.bio, '');     // limpar
  assert.equal((await tec2.req('GET', '/api/profile?id=' + U1)).body.user.bio, '');

  // logout => offline imediatamente
  await tec1.req('POST', '/api/auth/logout');
  const after = (await tec2.req('GET', '/api/team')).body.team.find(x => x.id === U1);
  assert.equal(after.online, false);
});

test('perfil: concluídas e pendentes do técnico, com busca, período e acesso à auditoria', async () => {
  assert.equal((await tec1.login(T1, 'NovaSenha999')).status, 200);     // o teste anterior fez logout do tec1 (e a senha foi trocada acima)
  const mk = (n, extra = {}) => ({ ...mkTask({ n: 'pv' + n, tech: T2, pppoe: `pv${n}.${RUN}@telecom` }), ...extra });
  const [A, B, C] = [mk(1), mk(2), mk(3, { date: '2020-01-01' })];      // C: prazo antigo => atrasada
  assert.equal((await adm.sync({ tasks: { upsert: [A, B, C] } })).status, 200);

  // tec2 conclui A (com observação) e deixa B em atendimento; C continua aguardando
  const boot = (await tec2.boot()).body.tasks;
  const a0 = boot.find(t => t.id === A.id), b0 = boot.find(t => t.id === B.id);
  const ra = await tec2.sync({ tasks: { upsert: [{ ...a0, ligou: true, resolvido: true, obs: 'Troquei o conector SOLUCAOZ', actionId: 'PROCEDIMENTO_EQUIPAMENTO', emExecucao: true }] } });
  assert.equal(ra.status, 200);
  assert.equal((await tec2.sync({ tasks: { upsert: [{ ...ra.body.tasks[0], finalizado: true, emExecucao: false }] } })).status, 200);
  assert.equal((await tec2.sync({ tasks: { upsert: [{ ...b0, emExecucao: true }] } })).status, 200);

  // outro técnico (e o admin) consultam o perfil do tec2
  for (const viewer of [tec1, adm]) {
    const done = await viewer.req('GET', `/api/profile/verifications?id=${U2}&status=done`);
    assert.equal(done.status, 200);
    const it = done.body.items.find(x => x.id === A.id);
    assert.ok(it, 'a concluída deve aparecer');
    assert.equal(it.finalizado, true);
    assert.equal(it.resolvido, true);
    assert.ok(it.obs.includes('SOLUCAOZ'));                              // observação completa, igual à auditoria
    assert.ok(it.finishedAt);
    assert.ok(!done.body.items.some(x => x.id === B.id || x.id === C.id), 'pendentes não entram em concluídas');
    assert.ok(done.body.counts.done >= 1 && done.body.counts.pending >= 2 && done.body.counts.running >= 1 && done.body.counts.overdue >= 1);

    const pend = await viewer.req('GET', `/api/profile/verifications?id=${U2}&status=pending`);
    assert.equal(pend.status, 200);
    const ids = pend.body.items.map(x => x.id);
    assert.ok(ids.includes(B.id) && ids.includes(C.id) && !ids.includes(A.id));
    assert.ok(ids.indexOf(B.id) < ids.indexOf(C.id), 'em atendimento vem antes');
    assert.equal(pend.body.items.find(x => x.id === C.id).overdue, true);
    assert.equal(pend.body.items.find(x => x.id === B.id).emExecucao, true);
  }

  // busca (PPPoE e observação), caractere curinga tratado como texto e período
  assert.deepEqual((await tec1.req('GET', `/api/profile/verifications?id=${U2}&status=done&q=SOLUCAOZ`)).body.items.map(x => x.id), [A.id]);
  assert.equal((await tec1.req('GET', `/api/profile/verifications?id=${U2}&status=done&q=pv1.${RUN}`)).body.total, 1);
  assert.equal((await tec1.req('GET', `/api/profile/verifications?id=${U2}&status=done&q=%25`)).body.total, 0);
  assert.equal((await tec1.req('GET', `/api/profile/verifications?id=${U2}&status=done&from=2999-01-01`)).body.total, 0);
  assert.equal((await tec1.req('GET', `/api/profile/verifications?id=${U2}&status=pending&to=2020-12-31`)).body.items.length, 1);

  // filtros rápidos, contadores e tamanho de página
  const cnt = (await tec1.req('GET', `/api/profile/verifications?id=${U2}&status=pending`)).body.counts;
  assert.ok(cnt.resolved >= 1 && cnt.resolved + cnt.unresolved === cnt.done);
  assert.ok(cnt.running >= 1 && cnt.overdue >= 1 && cnt.running + cnt.overdue + cnt.waiting === cnt.pending);
  const q1 = async (st, r) => (await tec1.req('GET', `/api/profile/verifications?id=${U2}&status=${st}&result=${r}`)).body;
  assert.ok((await q1('done', 'resolved')).items.every(x => x.resolvido));
  assert.ok((await q1('done', 'unresolved')).items.every(x => !x.resolvido));
  assert.deepEqual((await q1('pending', 'running')).items.map(x => x.id), [B.id]);
  assert.ok((await q1('pending', 'overdue')).items.some(x => x.id === C.id));
  assert.ok((await q1('pending', 'overdue')).items.every(x => !x.emExecucao && x.overdue));
  assert.equal((await q1('done', 'valor-invalido')).total, cnt.done);   // valor desconhecido = sem filtro
  const pg = (await tec1.req('GET', `/api/profile/verifications?id=${U2}&status=done&limit=10&offset=0`)).body;
  assert.equal(pg.limit, 10); assert.ok(pg.items.length <= 10);
  assert.equal((await tec1.req('GET', `/api/profile/verifications?id=${U2}&status=done&limit=9999`)).body.limit, 50);   // teto

  // da lista direto para a auditoria (liberada entre colegas)
  const aud = await tec1.req('GET', `/api/verification/audit?id=${A.id}`);
  assert.equal(aud.status, 200);
  assert.ok(aud.body.events.some(e => e.event === 'CONCLUIDA'));

  // validações e sessão
  assert.equal((await tec1.req('GET', '/api/profile/verifications?id=../x')).status, 400);
  assert.equal((await tec1.req('GET', '/api/profile/verifications?id=nao-existe')).status, 404);
  assert.equal((await new Client().req('GET', `/api/profile/verifications?id=${U2}`)).status, 401);
  await tec1.req('POST', '/api/auth/logout');
});

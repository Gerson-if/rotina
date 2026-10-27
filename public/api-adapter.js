/* api-adapter.js
 * Liga a interface original (que usava localStorage) à API do servidor.
 * A UI continua mexendo em `state` normalmente; ao chamar saveStorage() este arquivo
 * calcula o que mudou e envia SÓ a diferença para POST /api/sync (uma transação).
 * O servidor é a fonte da verdade: valida, aplica permissões e devolve as versões novas.
 */
(function () {
  'use strict';

  // ---------- util ----------
  window.esc = function (s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  // '/api/auth/login?x=1'  ->  'api.php?r=auth/login&x=1'
  // URL RELATIVA ao endereço da página: funciona por IP (http://192.168.0.10/), em subpasta (/rotina/) ou domínio,
  // e não exige mod_rewrite nem configuração especial no Apache/Nginx.
  function apiUrl(path) {
    const m = String(path).replace(/^\/api\//, '').split('?');
    return 'api.php?r=' + m[0] + (m[1] ? '&' + m[1] : '');
  }

  // Toda requisição tem tempo limite: se o servidor/rede "pendurar", ela é abortada e tratada como falta de conexão
  // (o salvamento reenvia sozinho). Sem isso, uma única resposta que não volta deixava o sistema parado.
  const TIMEOUT_MS = { GET: 20000, POST: 45000 };
  async function api(method, path, body) {
    const ctl = typeof AbortController === 'function' ? new AbortController() : null;
    const tm = ctl ? setTimeout(() => ctl.abort(), TIMEOUT_MS[method] || 30000) : null;
    let res, data = null;
    try {
      res = await fetch(apiUrl(path), {
        method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'rotina-web' },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: ctl ? ctl.signal : undefined,
      });
      try { data = await res.json(); } catch (_) { data = {}; }
    } catch (_) {
      return { ok: false, status: 0, data: { error: 'Sem conexão com o servidor.' } };
    } finally {
      if (tm) clearTimeout(tm);
    }
    return { ok: res.ok, status: res.status, data };
  }

  // Disponível para o restante da interface (verificação de PPPoE já feitos, modal de auditoria...)
  window.rotinaApi = api;

  // ---------- snapshots (estado conhecido pelo servidor) ----------
  const snap = { tasks: new Map(), users: new Map(), actions: new Map() };

  const pickTask = (t) => ({
    id: t.id, techEmail: t.techEmail, shift: t.shift, date: t.date, pppoe: t.pppoe,
    lote: t.lote || 'Sem Lote Definido', priority: t.priority || 'NORMAL', obs: t.obs || '',
    ligou: !!t.ligou, naoAtende: !!t.naoAtende, resolvido: !!t.resolvido, osCriada: !!t.osCriada,
    actionId: t.actionId || 'NONE',
    handleTimeMin: Number.isInteger(t.handleTimeMin) ? t.handleTimeMin : 8,
    emExecucao: !!t.emExecucao, finalizado: !!t.finalizado,
    pendente: !!t.pendente, pendenteNota: t.pendenteNota || '',
    ...(t.single ? { single: true } : {}),             // lido pelo servidor só na criação: atribuição única
    ...(t.reopenOf ? { reopenOf: t.reopenOf } : {}),   // lido pelo servidor só na criação: nova verificação gerada por reabertura
    ...(t.origem ? { origem: t.origem } : {}),         // só para a auditoria (criação): planilha, cota, automática ou única
    ...(t.nota ? { nota: t.nota } : {}),               // só para a auditoria (reatribuição pelo ADM): nunca vai para as observações
  });
  const pickUser = (u) => ({ id: u.id, name: u.name, email: u.email, role: u.role, shift: u.shift, status: u.status });
  const pickAction = (a) => ({ id: a.id, label: a.label });
  const sig = (o) => JSON.stringify(o);

  function rebuildSnapshots() {
    snap.tasks = new Map(state.tasks.map(t => [t.id, sig(pickTask(t))]));
    snap.users = new Map(state.users.map(u => [u.id, sig(pickUser(u))]));
    snap.actions = new Map(state.actions.map(a => [a.id, sig(pickAction(a))]));
  }

  function computeDiff() {
    const d = {
      tasks: { upsert: [], delete: [] }, users: { upsert: [], delete: [] }, actions: { upsert: [], delete: [] },
      sent: { tasks: new Map(), users: new Map(), actions: new Map() },
    };
    const seen = { tasks: new Set(), users: new Set(), actions: new Set() };

    for (const t of state.tasks) {
      seen.tasks.add(t.id);
      const p = pickTask(t); const s = sig(p);
      if (snap.tasks.get(t.id) !== s) {
        d.tasks.upsert.push(t.version ? { ...p, version: t.version } : p);
        d.sent.tasks.set(t.id, s);
      }
    }
    for (const id of snap.tasks.keys()) if (!seen.tasks.has(id)) d.tasks.delete.push(id);

    for (const u of state.users) {
      seen.users.add(u.id);
      const p = pickUser(u); const s = sig(p);
      if (snap.users.get(u.id) !== s || u.password) {
        d.users.upsert.push(u.password ? { ...p, password: u.password } : p);
        d.sent.users.set(u.id, s);
      }
    }
    for (const id of snap.users.keys()) if (!seen.users.has(id)) d.users.delete.push(id);

    for (const a of state.actions) {
      seen.actions.add(a.id);
      const p = pickAction(a); const s = sig(p);
      if (snap.actions.get(a.id) !== s) { d.actions.upsert.push(p); d.sent.actions.set(a.id, s); }
    }
    for (const id of snap.actions.keys()) if (!seen.actions.has(id)) d.actions.delete.push(id);
    return d;
  }

  const isDirty = () => {
    const d = computeDiff();
    return ['tasks', 'users', 'actions'].some(k => d[k].upsert.length || d[k].delete.length);
  };

  // ---------- sincronização ----------
  let syncing = false, again = false, timer = null, retryTimer = null, retryN = 0, offlineWarned = false;

  function scheduleSync() {
    clearTimeout(timer);
    timer = setTimeout(runSync, 200);
  }

  async function runSync() {
    if (!state.currentUser) return;
    if (syncing) { again = true; return; }
    const d = computeDiff();
    const total = ['tasks', 'users', 'actions'].reduce((n, k) => n + d[k].upsert.length + d[k].delete.length, 0);
    if (!total) return;

    syncing = true;
    const payload = { tasks: d.tasks, users: d.users, actions: d.actions };
    let r;
    try { r = await api('POST', '/api/sync', payload); } finally { syncing = false; }

    if (r.ok) {
      retryN = 0; offlineWarned = false; clearTimeout(retryTimer);
      // grava no snapshot exatamente o que foi enviado (edições feitas durante o envio continuam pendentes)
      for (const k of ['tasks', 'users', 'actions']) {
        d.sent[k].forEach((s, id) => snap[k].set(id, s));
        d[k].delete.forEach(id => snap[k].delete(id));
      }
      // versões/carimbos canônicos devolvidos pelo servidor
      for (const row of r.data.tasks || []) {
        const obj = state.tasks.find(t => t.id === row.id);
        if (obj) {
          obj.version = row.version; obj.startedAt = row.startedAt;
          obj.finishedAt = row.finishedAt; obj.createdAt = row.createdAt;
          // a classe (NORMAL/ADICIONAL) é decidida pelo servidor
          obj.kind = row.kind; obj.prevCount = row.prevCount; obj.repeatOf = row.repeatOf; obj.single = !!row.single; obj.pendente = !!row.pendente; obj.pendenteNota = row.pendenteNota || ''; obj.pendenteDesde = row.pendenteDesde || null;
        }
      }
      try { if (window.anInvalidate) window.anInvalidate(); } catch (_) {}   // você mudou algo: os gráficos precisam refletir
      for (const u of state.users) delete u.password;
      if (d.users.upsert.length || d.users.delete.length) await refresh(true);   // e-mails/permissões podem ter mudado
      if (again || isDirty()) { again = false; scheduleSync(); }
      pulseTeam();
      return;
    }

    if (r.status === 0 || r.status === 503) {   // sem rede ou sistema em atualização: mantém pendente e tenta de novo
      if (!offlineWarned) {   // avisa uma vez por queda (antes repetia a cada 5 s)
        offlineWarned = true;
        showToast(r.status === 503 ? 'Sistema em atualização. Suas alterações serão reenviadas em instantes.'
          : 'Sem conexão. Suas alterações serão reenviadas automaticamente.', 'warning');
      }
      clearTimeout(retryTimer);
      retryTimer = setTimeout(runSync, Math.min(60000, 5000 * Math.pow(2, retryN++)));   // 5s, 10s, 20s, 40s, 60s...
      return;
    }
    if (r.status === 401) return sessionExpired();

    // Rejeitado pelo servidor (permissão, conflito, validação): mostra o motivo e volta ao estado real
    showToast(r.data.error || 'Não foi possível salvar.', r.status === 409 ? 'warning' : 'error');
    for (const u of state.users) delete u.password;
    await refresh(true);
  }

  // ---------- carga / atualização ----------
  function applyBootstrap(b) {
    state.users = b.users;
    state.tasks = b.tasks;
    state.actions = b.actions;
    state.currentUser = state.users.find(u => u.id === b.user.id) || b.user;
    state.today = b.today || state.today;          // 'hoje' no fuso do negócio
    if (b.historyDays) state.historyDays = b.historyDays;   // quantos dias de concluídas o painel carrega
    // diferença entre o relógio do servidor e o do aparelho (cronômetro dos cards usa o horário do servidor)
    if (b.now) {
      const sv = Date.parse(String(b.now).replace(' ', 'T') + 'Z');
      if (!isNaN(sv)) state.skewMs = sv - Date.now();
    }
    rebuildSnapshots();
  }

  async function refresh(rerender) {
    const r = await api('GET', '/api/bootstrap');
    if (r.status === 401) return sessionExpired();
    if (!r.ok) return false;
    applyBootstrap(r.data);
    if (rerender) renderAppScreen();
    return true;
  }

  function sessionExpired() {
    if (!state.currentUser) return;
    stopPolling(); lastStamp = null;
    state.currentUser = null; state.users = []; state.tasks = []; state.actions = [];
    snap.tasks.clear(); snap.users.clear(); snap.actions.clear();
    renderAppScreen();
    showToast('Sessão expirada. Entre novamente.', 'warning');
  }

  // ---------- atualização automática (vários usuários ao mesmo tempo) ----------
  let poll = null;
  const POLL_MS = 5000;   // a consulta de carimbo é minúscula; só baixa os dados quando algo mudou

  // "Ocupado" = a pessoa está digitando num formulário ou com uma janela aberta (redesenhar atrapalharia).
  // Campos de busca/filtro/data NÃO contam: a lista é redesenhada sem mexer neles, então a tela segue atualizando.
  function userIsBusy() {
    if (document.querySelector('[id^="modal-"]:not(.hidden)')) return true;
    const a = document.activeElement;
    if (!a || !/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return false;
    if (a.type === 'search' || a.type === 'date' || /search|filter|busca|filtro/i.test(a.id || '')) return false;
    return true;
  }

  let polling = false;
  async function pollOnce() {
    if (polling || !state.currentUser || document.hidden || syncing || userIsBusy() || isDirty()) return;
    polling = true;
    try { await pollInner(); } finally { polling = false; }
  }
  let lastStamp = null;   // carimbo do servidor da última carga: se não mudou, nem baixa os dados
  async function pollInner() {
    let stamp = null;
    const rs = await api('GET', '/api/stamp');
    if (rs.status === 401) return sessionExpired();
    if (rs.ok && rs.data && rs.data.stamp) {
      stamp = rs.data.stamp;
      if (stamp === lastStamp) return;           // nada mudou no servidor: consulta de poucos bytes e pronto
    }
    const usersSig = (us) => JSON.stringify(us.map(u => { const c = { ...u }; delete c.password; return c; }));
    const before = JSON.stringify([state.tasks.map(t => [t.id, t.version]), usersSig(state.users), state.actions.length]);
    const r = await api('GET', '/api/bootstrap');
    if (r.status === 401) return sessionExpired();
    if (!r.ok || syncing || userIsBusy() || isDirty()) return;   // não gravou o carimbo: tenta de novo no próximo ciclo (5 s)
    const after = JSON.stringify([r.data.tasks.map(t => [t.id, t.version]), usersSig(r.data.users), r.data.actions.length]);
    if (stamp) lastStamp = stamp;
    if (before === after) return;               // nada mudou: não redesenha a tela
    applyBootstrap(r.data);
    try { if (window.anInvalidate) window.anInvalidate(); } catch (_) {}   // dados mudaram: os gráficos buscam números novos
    renderAppScreen();
    pulseTeam();                                // o status da equipe acompanha a mudança na hora
  }
  function pulseTeam() { try { if (typeof window.teamPulse === 'function') window.teamPulse(); } catch (_) {} }
  function startPolling() { stopPolling(); poll = setInterval(pollOnce, POLL_MS); }
  function stopPolling() { clearInterval(poll); poll = null; }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) pollOnce(); });
  window.addEventListener('focus', () => pollOnce());
  window.addEventListener('online', () => { pollOnce(); runSync(); });
  window.addEventListener('beforeunload', (e) => { if (state.currentUser && (syncing || isDirty())) { e.preventDefault(); e.returnValue = ''; } });

  // ---------- substitui as funções que usavam localStorage ----------
  window.saveStorage = function () { scheduleSync(); };
  window.refreshFromServer = function (rerender) { return refresh(rerender !== false); };   // recarrega do servidor agora (depois de ações feitas direto no servidor)
  window.loadStorage = function () { /* dados vêm do servidor após o login */ };

  window.handleLogin = async function (e) {
    e.preventDefault();
    const email = document.getElementById('login-email').value.trim().toLowerCase();
    const password = document.getElementById('login-password').value;
    const r = await api('POST', '/api/auth/login', { email, password });
    if (!r.ok) {
      showToast(r.data.error || 'Falha no login.', r.data.code === 'PENDENTE' ? 'warning' : 'error');
      return;
    }
    document.getElementById('login-password').value = '';
    if (!(await refresh(false))) { showToast('Não foi possível carregar os dados.', 'error'); return; }
    showToast(`Bem-vindo, ${state.currentUser.name}!`, 'success');
    renderAppScreen();
    startPolling();
  };

  window.handleRegisterRequest = async function (e) {
    e.preventDefault();
    const body = {
      name: document.getElementById('reg-name').value.trim(),
      email: document.getElementById('reg-email').value.trim().toLowerCase(),
      password: document.getElementById('reg-password').value,
      shift: document.getElementById('reg-shift').value,
    };
    const r = await api('POST', '/api/auth/register', body);
    if (!r.ok) { showToast(r.data.error || 'Não foi possível enviar a solicitação.', 'error'); return; }
    showToast('Solicitação enviada com sucesso! Aguarde a aprovação.', 'success');
    document.getElementById('form-register').reset();
    switchLoginTab('LOGIN');
  };

  window.logout = async function () {
    if (isDirty()) { clearTimeout(timer); await runSync(); }      // não perde o que ainda não foi salvo
    stopPolling(); lastStamp = null;
    await api('POST', '/api/auth/logout');
    state.currentUser = null; state.users = []; state.tasks = []; state.actions = [];
    snap.tasks.clear(); snap.users.clear(); snap.actions.clear();
    renderAppScreen();
    showToast('Sessão encerrada.', 'info');
  };

  window.onload = async function () {
    ['task-date', 'tec-date-filter', 'batch-date'].forEach((id) => {
      const el = document.getElementById(id); if (el) el.value = todayStr;
    });
    if (await refresh(false)) startPolling();      // cookie de sessão válido => entra direto
    renderAppScreen();
  };
})();

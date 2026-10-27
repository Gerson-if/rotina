<?php
declare(strict_types=1);
namespace Rotina;

/** Rotas da API. Chamadas como  api.php?r=<rota>  (funciona igual em Apache e Nginx, sem mod_rewrite). */
final class Api
{
    private const NAME_UNSAFE = '/[<>"\'`\\\\]/';

    public static function handle(string $route, string $method): void
    {
        Http::headers();

        // Saúde: sem autenticação (usada por monitoramento e pelo atualizador)
        if ($route === 'healthz') { self::healthz(); return; }

        try {
            Auth::csrfGuard();
            switch ($route) {
                case 'auth/login':     self::needPost($method); self::login(); return;
                case 'auth/register':  self::needPost($method); self::register(); return;
                case 'auth/logout':    self::needPost($method); self::logout(); Http::json(['ok' => true]); return;
                case 'auth/me':        $u = Auth::require(); Http::json(['user' => Mappers::user($u)]); return;
                case 'bootstrap':      self::bootstrap($method); return;
                case 'stamp':          self::stamp(); return;
                case 'sync':           self::needPost($method); self::sync(); return;
                case 'export/verifications.csv': self::exportCsv(); return;
                case 'audit':          self::audit(); return;
                case 'team':           self::team(); return;
                case 'pending/stats':  self::pendingStats(); return;
                case 'analytics':      self::analytics(); return;
                // plano de fundo do login: info e arquivo são PÚBLICOS (a tela de login ainda não tem sessão)
                case 'branding/login':      Http::json(Branding::publicInfo()); return;
                case 'branding/login-file': Branding::serve(); return;
                case 'profile':        self::profile(); return;
                case 'profile/verifications': self::profileVerifications(); return;
                case 'profile/bio':    self::needPost($method); self::saveBio(); return;
                case 'profile/avatar': self::needPost($method); self::saveAvatar(); return;
                case 'avatar':         self::avatar(); return;
                case 'pppoe/check':    self::needPost($method); self::pppoeCheck(); return;
                case 'verification/audit': self::verificationAudit(); return;
                case 'verification/search': self::verificationSearch(); return;
                case 'admin/system':          self::admin(); Http::json(System::status()); return;
                case 'admin/redistribution':  $u = self::admin();
                                              if ($method === 'POST') {
                                                  $cfg = Redistribution::save(Http::body(10 * 1024));
                                                  Util::audit($u['id'], 'REDIST_CONFIG', 'system', null, $cfg, Util::clientIp());
                                              }
                                              Http::json(Redistribution::overview()); return;
                case 'admin/redistribution/suggest': self::needPost($method); self::admin(); $b = Http::body(10 * 1024);
                                              $n = (int) ($b['n'] ?? 0);
                                              if ($n < 1 || $n > 5000) throw new HttpError(400, 'Quantidade inválida (1 a 5000).', 'VALIDATION');
                                              Http::json(Redistribution::suggest($n, !empty($b['commit']))); return;
                case 'admin/redistribution/run': self::needPost($method); $u = self::admin(); $b = Http::body(10 * 1024);
                                              Http::json(Redistribution::runNow(!empty($b['dry']), $u['id'], Util::clientIp())); return;
                case 'admin/pending/assign':  self::needPost($method); $u = self::admin(); $b = Http::body(10 * 1024);
                                              $vid = is_string($b['id'] ?? null) ? $b['id'] : '';
                                              if ($vid === '') throw new HttpError(400, 'Verificação não informada.', 'VALIDATION');
                                              $tid = is_string($b['techId'] ?? null) && $b['techId'] !== '' ? $b['techId'] : null;
                                              Http::json(Redistribution::designate($vid, $tid, $u['id'], Util::clientIp())); return;
                case 'admin/db-test':         self::needPost($method); self::admin(); Http::json(System::dbTest()); return;
                case 'admin/backup':          self::needPost($method); $u = self::admin(); $i = System::createBackup();
                                              Util::audit($u['id'], 'BACKUP_MANUAL', 'backup', $i['name'], null, Util::clientIp()); Http::json($i, 201); return;
                case 'admin/backup/download': $u = self::admin(); Util::audit($u['id'], 'BACKUP_DOWNLOAD', 'backup', (string) ($_GET['f'] ?? ''), null, Util::clientIp());
                                              System::download((string) ($_GET['f'] ?? '')); return;
                case 'admin/backup/delete':   self::needPost($method); $u = self::admin(); $b = Http::body(10 * 1024);
                                              $r = System::deleteBackup((string) ($b['file'] ?? ''));
                                              Util::audit($u['id'], 'BACKUP_DELETE', 'backup', (string) $b['file'], null, Util::clientIp()); Http::json(['ok' => true] + $r); return;
                case 'admin/backup/prune':    self::needPost($method); $u = self::admin(); $b = Http::body(10 * 1024);
                                              $r = System::pruneBackups((int) ($b['keep'] ?? 0));
                                              Util::audit($u['id'], 'BACKUP_PRUNE', 'backup', null, ['keep' => (int) $b['keep'], 'deleted' => $r['deleted']], Util::clientIp()); Http::json(['ok' => true] + $r); return;
                case 'admin/backup/restore':  self::needPost($method); $u = self::admin(); $b = Http::body(10 * 1024);
                                              if (($b['confirm'] ?? '') !== 'RESTAURAR') throw new HttpError(400, 'Digite RESTAURAR para confirmar.');
                                              $log = System::restore((string) ($b['file'] ?? ''));
                                              Util::audit(null, 'BACKUP_RESTORE', 'backup', (string) $b['file'], ['by' => $u['email']], Util::clientIp());
                                              Http::json(['ok' => true, 'log' => $log]); return;
                case 'admin/update':          self::needPost($method); $u = self::admin();
                                              $r = System::update($_FILES['package'] ?? [], ($_POST['force'] ?? '') === '1');
                                              Util::audit($u['id'], 'SYSTEM_UPDATE', 'system', $r['version'], null, Util::clientIp());
                                              Http::json(['ok' => true] + $r); return;
                case 'admin/branding':        self::admin(); Http::json(Branding::adminInfo()); return;
                case 'admin/branding/chunk':  self::needPost($method); self::admin();
                                              Http::json(Branding::chunk((string) ($_GET['id'] ?? ''), (int) ($_GET['o'] ?? 0))); return;
                case 'admin/branding/finish': self::needPost($method); $u = self::admin(); $b = Http::body(10 * 1024);
                                              $r = Branding::finish((string) ($b['id'] ?? ''), (string) ($b['name'] ?? ''));
                                              Util::audit($u['id'], 'LOGIN_BG_SET', 'system', 'login_bg', ['kind' => $r['current']['kind'], 'size' => $r['current']['size']], Util::clientIp());
                                              Http::json(['ok' => true] + $r); return;
                case 'admin/branding/remove': self::needPost($method); $u = self::admin(); $r = Branding::remove();
                                              Util::audit($u['id'], 'LOGIN_BG_REMOVE', 'system', 'login_bg', null, Util::clientIp());
                                              Http::json(['ok' => true] + $r); return;
                case 'admin/rollback':        self::needPost($method); $u = self::admin(); $log = System::rollback();
                                              Util::audit($u['id'], 'SYSTEM_ROLLBACK', 'system', null, null, Util::clientIp());
                                              Http::json(['ok' => true, 'log' => $log]); return;
            }
            throw new HttpError(404, 'Rota não encontrada.');
        } catch (HttpError $e) {
            Http::fail($e->status, $e->getMessage(), $e->errCode !== 'ERROR' ? $e->errCode : null);
        } catch (\Throwable $e) {
            Util::log('api', $route . ': ' . get_class($e) . ': ' . $e->getMessage());
            Http::fail(500, 'Erro interno. Tente novamente.');
        }
    }

    private static function admin(): array
    {
        $u = Auth::require();
        Auth::requireRole($u, 'ADM');
        return $u;
    }

    private static function needPost(string $m): void
    {
        if ($m !== 'POST') throw new HttpError(405, 'Método não permitido.');
    }

    private static function healthz(): void
    {
        try {
            $r = Db::one('SELECT MAX(version) AS v, COUNT(*) AS n FROM schema_migrations');
            Http::json(['status' => 'ok', 'migrations' => (int) $r['n'], 'schema' => $r['v'], 'version' => Config::version()]);
        } catch (\Throwable $e) {
            Http::json(['status' => 'down'], 503);
        }
    }

    // ---------------------------------------------------------------- auth
    private static function login(): void
    {
        $b = Http::body(200 * 1024);
        $ip = Util::clientIp();
        Http::rateLimit("login:$ip", 30, 900, 'Muitas tentativas. Aguarde alguns minutos.');

        $email = is_string($b['email'] ?? null) ? strtolower(trim($b['email'])) : '';
        $pass = is_string($b['password'] ?? null) ? $b['password'] : '';
        if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '' || strlen($pass) > 200) {
            throw new HttpError(400, 'Credenciais inválidas.');
        }

        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        // Sempre executa um bcrypt (mesmo sem usuário): não vaza a existência do e-mail pelo tempo de resposta
        $ok = password_verify($pass, $user ? $user['password_hash'] : Util::DUMMY_HASH);

        // Sem bloqueio de conta por tentativas falhas: errar a senha nunca trava o usuário.
        // (Segue valendo só o limite por IP acima, contra rajadas automáticas.)
        if (!$user || !$ok) {
            Util::audit($user ? $user['id'] : null, 'LOGIN_FAIL', null, null, ['email' => $email], $ip);
            throw new HttpError(401, 'Credenciais inválidas. Verifique seu e-mail e senha.');
        }
        if ($user['status'] === 'PENDENTE') throw new HttpError(403, 'Cadastro em aprovação. Solicite ao administrador a liberação.', 'PENDENTE');
        if ($user['status'] !== 'APROVADO') throw new HttpError(403, 'Acesso bloqueado. Procure o administrador.', 'BLOQUEADO');

        Db::q('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$user['id']]);
        Auth::issue($user);
        Util::audit($user['id'], 'LOGIN', null, null, null, $ip);
        Http::json(['user' => Mappers::user($user)]);
    }

    private static function register(): void
    {
        $b = Http::body(200 * 1024);
        Http::rateLimit('register:' . Util::clientIp(), 10, 3600, 'Muitas solicitações de cadastro. Tente mais tarde.');

        $name = is_string($b['name'] ?? null) ? trim($b['name']) : '';
        $email = is_string($b['email'] ?? null) ? strtolower(trim($b['email'])) : '';
        $pass = is_string($b['password'] ?? null) ? $b['password'] : '';
        $shift = $b['shift'] ?? 'MANHA';

        if (Util::len($name) < 2 || Util::len($name) > 120) throw new HttpError(400, 'Informe seu nome (2 a 120 caracteres).');
        if (preg_match(self::NAME_UNSAFE, $name)) throw new HttpError(400, 'Nome contém caracteres não permitidos.');
        if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new HttpError(400, 'E-mail inválido.');
        if (strlen($pass) < 4) throw new HttpError(400, 'A senha precisa ter ao menos 4 caracteres.');
        if (strlen($pass) > 200) throw new HttpError(400, 'Senha longa demais.');
        if (!in_array($shift, ['MANHA', 'TARDE', 'NOITE', 'MADRUGADA'], true)) throw new HttpError(400, 'Turno inválido.');

        if (Db::val('SELECT id FROM users WHERE email = ?', [$email]) !== null) throw new HttpError(409, 'E-mail já cadastrado no sistema!');

        $id = Util::newId('usr');
        try {
            Db::q("INSERT INTO users (id, name, email, password_hash, role, shift, status) VALUES (?,?,?,?, 'TEC', ?, 'PENDENTE')",
                [$id, $name, $email, Util::hashPassword($pass), $shift]);
        } catch (\PDOException $e) {
            if (Db::errno($e) === 1062) throw new HttpError(409, 'E-mail já cadastrado no sistema!');
            throw $e;
        }
        Util::audit($id, 'REGISTER_REQUEST', 'user', $id, null, Util::clientIp());
        Http::json(['ok' => true], 201);
    }

    // ---------------------------------------------------------------- dados
    /**
     * Carimbo barato do estado visível (verificações, usuários, procedimentos). A atualização automática do painel
     * consulta só isto a cada ciclo e baixa o bootstrap completo apenas quando o carimbo muda.
     */
    private static function stamp(): void
    {
        Auth::require();
        // O cálculo do carimbo varre as verificações; com muitos usuários consultando a cada 5 s isso pesaria. Guarda o resultado por
        // alguns segundos: todos os navegadores passam a dividir UMA consulta pesada (a mudança aparece em até ~3 s a mais).
        $hit = Db::val("SELECT v FROM app_state WHERE k = 'stamp_cache'");
        if ($hit && preg_match('/^(\d+)\|([0-9a-f]{32})$/', (string) $hit, $m) && time() - (int) $m[1] < 3) {
            $stamp = $m[2];
        } else {
            $since = Db::today(-(int) Config::get('bootstrap_days'));
            $v = Db::one('SELECT COUNT(*) AS n, COALESCE(SUM(version), 0) AS s FROM verifications WHERE finalizado = 0 OR due_date >= ?', [$since]);
            $u = Db::one('SELECT COUNT(*) AS n, COALESCE(MAX(updated_at), 0) AS m FROM users');
            $a = Db::one('SELECT COUNT(*) AS n, COALESCE(SUM(deleted_at IS NULL), 0) AS live, COALESCE(SUM(CRC32(label)), 0) AS c FROM actions');
            $stamp = md5(json_encode([$v, $u, $a]));
            Db::q('INSERT INTO app_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['stamp_cache', time() . '|' . $stamp]);
        }
        // Redistribuição automática de pendências: só entra em ação se o ADM ligou e já passou o intervalo; roda DEPOIS da resposta
        $redist = Config::get('auto_jobs') && Redistribution::due();
        if ($redist) ob_start();
        Http::json(['stamp' => $stamp]);
        if ($redist) {
            ignore_user_abort(true);
            Http::finish();
            @set_time_limit(120);
            try { Redistribution::tick(); } catch (\Throwable $e) { Util::log('redist', $e->getMessage()); }
        }
    }

    private static function bootstrap(string $method): void
    {
        $user = Auth::require();
        $since = Db::today(-(int) Config::get('bootstrap_days'));
        $isAdm = $user['role'] === 'ADM';

        $tasks = Db::all('SELECT ' . Mappers::VERIF_SELECT . ' WHERE v.finalizado = 0 OR v.due_date >= ? ORDER BY v.created_at DESC, v.id', [$since]);
        $users = Db::all('SELECT id, name, email, role, shift, status, avatar_v FROM users ' . ($isAdm ? '' : "WHERE status = 'APROVADO' ") . 'ORDER BY name');
        $actions = Db::all('SELECT id, label FROM actions WHERE deleted_at IS NULL ORDER BY sort_order, label');

        ob_start();
        Http::json([
            'user' => Mappers::user($user),
            'users' => array_map([Mappers::class, 'user'], $users),
            'actions' => array_map([Mappers::class, 'action'], $actions),
            'tasks' => array_map([Mappers::class, 'verif'], $tasks),
            'today' => Db::today(),
            'now' => (string) Db::val('SELECT NOW()'),          // relógio do servidor: o cronômetro dos cards usa a diferença
            'historyDays' => (int) Config::get('bootstrap_days'),
        ]);

        // Rotinas agendadas "de carona": rodam DEPOIS de a resposta ter sido enviada ao usuário
        if (Config::get('auto_jobs')) {
            ignore_user_abort(true);
            Http::finish();
            @set_time_limit(300);
            try { Jobs::runDue(); } catch (\Throwable $e) { Util::log('jobs', $e->getMessage()); }
        } else {
            while (ob_get_level() > 0) ob_end_flush();
        }
    }

    private static function sync(): void
    {
        $user = Auth::require();
        $body = Http::body(10 * 1024 * 1024);
        $result = Sync::apply($user, $body, Util::clientIp());
        if (!empty($result['reissueSelf'])) {
            // trocou a própria senha: token_version mudou; emite sessão nova para não deslogar
            Auth::issue(Db::one('SELECT id, token_version FROM users WHERE id = ?', [$user['id']]));
        }
        Http::json($result);
    }

    // ---------------------------------------------------------------- equipe, presença e perfil
    public const ONLINE_SECONDS = 75;      // o navegador avisa a cada ~15 s; passou disso, está offline
    private const TMA_MAX_SECONDS = 8 * 3600; // atendimentos acima de 8 h (esquecidos abertos) não entram no TMA

    private static function logout(): void
    {
        try {
            $u = Auth::require();
            Db::q('UPDATE users SET last_seen_at = NULL WHERE id = ?', [$u['id']]);   // sai da lista de online na hora
        } catch (\Throwable $e) { /* sem sessão: nada a fazer */ }
        Auth::clear();
    }

    /** Batimento + lista da equipe: quem está online, em atendimento ou ocioso. Qualquer usuário logado. */
    private static function team(): void
    {
        $me = Auth::require();
        Db::q('UPDATE users SET last_seen_at = NOW() WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL 20 SECOND))', [$me['id']]);
        $isAdm = $me['role'] === 'ADM';
        $rows = Db::all("SELECT u.id, u.name, u.email, u.shift, u.bio, u.avatar_v, u.created_at, u.last_login_at,
                                TIMESTAMPDIFF(SECOND, u.last_seen_at, NOW()) AS seen_ago,
                                (SELECT COUNT(*) FROM verifications v WHERE v.tech_id = u.id AND v.finalizado = 0 AND v.pending_contact = 0) AS pending,
                                (SELECT COUNT(*) FROM verifications v WHERE v.tech_id = u.id AND v.finalizado = 0 AND v.em_execucao = 1) AS running,
                                (SELECT v.pppoe FROM verifications v WHERE v.tech_id = u.id AND v.finalizado = 0 AND v.em_execucao = 1 ORDER BY v.started_at DESC LIMIT 1) AS running_pppoe,
                                (SELECT COUNT(*) FROM verifications v WHERE v.tech_id = u.id AND v.finalizado = 1 AND DATE(v.finished_at) = CURDATE()) AS done_today
                           FROM users u WHERE u.role = 'TEC' AND u.status = 'APROVADO' ORDER BY u.name");
        $list = array_map(static function ($r) use ($me, $isAdm) {
            $ago = $r['seen_ago'] === null ? null : max(0, (int) $r['seen_ago']);
            return [
                'id' => $r['id'], 'name' => $r['name'], 'shift' => $r['shift'], 'bio' => $r['bio'] ?? '', 'avatarV' => (int) $r['avatar_v'],
                'online' => $ago !== null && $ago <= self::ONLINE_SECONDS, 'seenAgo' => $ago,
                'pending' => (int) $r['pending'], 'running' => (int) $r['running'], 'runningPppoe' => $r['running_pppoe'],
                'doneToday' => (int) $r['done_today'], 'me' => $r['id'] === $me['id'],
                // dados administrativos: só o ADM recebe
                'email' => $isAdm ? $r['email'] : null, 'lastLoginAt' => $isAdm ? $r['last_login_at'] : null,
            ];
        }, $rows);
        Http::json(['team' => $list]);
    }

    /** Estatísticas de um período (finished_at >= desde; null = tudo) */
    private static function techStats(string $techId, ?int $days): array
    {
        $where = 'tech_id = ? AND finalizado = 1' . ($days ? ' AND finished_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)' : '');
        $ok = 'started_at IS NOT NULL AND finished_at IS NOT NULL AND finished_at >= started_at AND TIMESTAMPDIFF(SECOND, started_at, finished_at) <= ' . self::TMA_MAX_SECONDS;
        $r = Db::one("SELECT COUNT(*) AS total, COALESCE(SUM(resolvido = 1), 0) AS resolved, COALESCE(SUM(os_criada = 1), 0) AS os,
                             COALESCE(SUM(nao_atende = 1 AND resolvido = 0), 0) AS no_answer,
                             AVG(CASE WHEN $ok THEN TIMESTAMPDIFF(SECOND, started_at, finished_at) END) AS tma,
                             SUM($ok) AS tma_n
                        FROM verifications WHERE $where", [$techId]);
        $total = (int) $r['total'];
        return [
            'total' => $total, 'resolved' => (int) $r['resolved'], 'os' => (int) $r['os'], 'noAnswer' => (int) $r['no_answer'],
            'successRate' => $total ? round((int) $r['resolved'] * 100 / $total, 1) : null,
            'tmaSeconds' => $r['tma'] === null ? null : (int) round((float) $r['tma']), 'tmaSamples' => (int) $r['tma_n'],
        ];
    }

    /** Perfil de um técnico: bio, presença e indicadores (TMA e taxa de sucesso). Qualquer usuário logado. */
    private static function profile(): void
    {
        $me = Auth::require();
        $id = (string) ($_GET['id'] ?? '');
        if (!preg_match(Util::ID_RE, $id)) throw new HttpError(400, 'Perfil inválido.');
        $u = Db::one("SELECT id, name, email, shift, bio, avatar_v, created_at, last_login_at, TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seen_ago
                        FROM users WHERE id = ? AND role = 'TEC' AND status = 'APROVADO'", [$id]);
        if (!$u) throw new HttpError(404, 'Técnico não encontrado.');
        $ago = $u['seen_ago'] === null ? null : max(0, (int) $u['seen_ago']);

        // últimos 14 dias (concluídas por dia)
        $days = [];
        foreach (Db::all('SELECT DATE(finished_at) AS d, COUNT(*) AS n FROM verifications
                           WHERE tech_id = ? AND finalizado = 1 AND finished_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                           GROUP BY DATE(finished_at)', [$id]) as $r) $days[$r['d']] = (int) $r['n'];
        $daily = [];
        for ($i = 13; $i >= 0; $i--) { $d = Db::today(-$i); $daily[] = ['date' => $d, 'n' => $days[$d] ?? 0]; }

        $running = Db::one('SELECT pppoe FROM verifications WHERE tech_id = ? AND finalizado = 0 AND em_execucao = 1 ORDER BY started_at DESC LIMIT 1', [$id]);
        $queue = Db::one('SELECT COUNT(*) AS n FROM verifications WHERE tech_id = ? AND finalizado = 0 AND pending_contact = 0', [$id]);   // fila a fazer (define "ocioso" x "online")
        $adminInfo = null;
        if ($me['role'] === 'ADM') {   // visão administrativa: acesso, fila atual e pendências dos últimos 30 dias
            $q = Db::one('SELECT COUNT(*) AS open_n, COALESCE(SUM(em_execucao = 1), 0) AS run_n, COALESCE(SUM(pending_contact = 1), 0) AS pend_n,
                                 COALESCE(SUM(due_date < CURDATE()), 0) AS late_n
                            FROM verifications WHERE tech_id = ? AND finalizado = 0', [$id]);
            $p = Db::one("SELECT COALESCE(SUM(event = 'PENDENTE'), 0) AS l, COALESCE(SUM(event = 'RETOMADA'), 0) AS t
                            FROM verification_events WHERE user_id = ? AND event IN ('PENDENTE', 'RETOMADA') AND created_at >= (NOW() - INTERVAL 30 DAY)", [$id]);
            $adminInfo = [
                'lastLoginAt' => $u['last_login_at'],
                'open' => (int) $q['open_n'], 'running' => (int) $q['run_n'], 'late' => (int) $q['late_n'], 'pendingLeft' => (int) $q['pend_n'],
                'pend30Left' => (int) $p['l'], 'pend30Took' => (int) $p['t'],
            ];
        }
        Http::json([
            'user' => [
                'id' => $u['id'], 'name' => $u['name'], 'shift' => $u['shift'], 'bio' => $u['bio'] ?? '', 'avatarV' => (int) $u['avatar_v'], 'memberSince' => substr((string) $u['created_at'], 0, 10),
                'email' => $me['role'] === 'ADM' ? $u['email'] : null,
                'online' => $ago !== null && $ago <= self::ONLINE_SECONDS, 'seenAgo' => $ago,
                'runningPppoe' => $running['pppoe'] ?? null, 'pending' => (int) $queue['n'], 'me' => $u['id'] === $me['id'],
            ],
            'stats' => ['d30' => self::techStats($id, 30), 'all' => self::techStats($id, null)],
            'daily' => $daily, 'tmaMaxHours' => self::TMA_MAX_SECONDS / 3600,
            'admin' => $adminInfo,
        ]);
    }

    /**
     * Verificações de um técnico para o perfil: CONCLUÍDAS (auditáveis) e PENDENTES (em aberto / em atendimento / atrasadas).
     * Qualquer usuário logado consulta (mesma transparência da auditoria entre colegas). Paginado, com busca e período.
     *   ?id=<tecnico>&status=done|pending&q=<pppoe ou obs>&from=AAAA-MM-DD&to=AAAA-MM-DD&offset=0&limit=20
     *   &result= (done: resolved|unresolved|os|noanswer · pending: running|overdue|waiting)
     */
    private static function profileVerifications(): void
    {
        Auth::require();
        $id = (string) ($_GET['id'] ?? '');
        if (!preg_match(Util::ID_RE, $id)) throw new HttpError(400, 'Perfil inválido.');
        if (!Db::one("SELECT id FROM users WHERE id = ? AND role = 'TEC' AND status = 'APROVADO'", [$id])) throw new HttpError(404, 'Técnico não encontrado.');

        $status = ($_GET['status'] ?? 'done') === 'pending' ? 'pending' : 'done';
        $q = trim((string) ($_GET['q'] ?? ''));
        if (Util::len($q) > 120) throw new HttpError(400, 'Busca longa demais.');
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $from = preg_match($re, (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '';
        $to   = preg_match($re, (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '';
        $offset = max(0, min(100000, (int) ($_GET['offset'] ?? 0)));
        $limit = max(10, min(50, (int) ($_GET['limit'] ?? 20)));
        $result = (string) ($_GET['result'] ?? '');     // filtro rápido (ver mapa abaixo)

        // contadores das abas (sempre do técnico inteiro, sem os filtros)
        $c = Db::one('SELECT COALESCE(SUM(finalizado = 1), 0) AS done,
                             COALESCE(SUM(finalizado = 1 AND resolvido = 1), 0) AS resolved,
                             COALESCE(SUM(finalizado = 1 AND resolvido = 0), 0) AS unresolved,
                             COALESCE(SUM(finalizado = 1 AND os_criada = 1), 0) AS os,
                             COALESCE(SUM(finalizado = 1 AND nao_atende = 1 AND resolvido = 0), 0) AS noanswer,
                             COALESCE(SUM(finalizado = 0), 0) AS pending,
                             COALESCE(SUM(finalizado = 0 AND em_execucao = 1), 0) AS running,
                             COALESCE(SUM(finalizado = 0 AND em_execucao = 0 AND due_date < CURDATE()), 0) AS overdue,
                             COALESCE(SUM(finalizado = 0 AND em_execucao = 0 AND due_date >= CURDATE()), 0) AS waiting
                        FROM verifications WHERE tech_id = ?', [$id]);

        $where = 'v.tech_id = ? AND v.finalizado = ' . ($status === 'done' ? '1' : '0');
        $params = [$id];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where .= ' AND (v.pppoe LIKE ? OR v.obs LIKE ?)';
            array_push($params, $like, $like);
        }
        // filtro rápido por resultado (concluídas) ou por situação (pendentes); valor desconhecido = sem filtro
        $quick = $status === 'done'
            ? ['resolved' => 'v.resolvido = 1', 'unresolved' => 'v.resolvido = 0', 'os' => 'v.os_criada = 1', 'noanswer' => '(v.nao_atende = 1 AND v.resolvido = 0)']
            : ['running' => 'v.em_execucao = 1', 'overdue' => '(v.em_execucao = 0 AND v.due_date < CURDATE())', 'waiting' => '(v.em_execucao = 0 AND v.due_date >= CURDATE())'];
        if (isset($quick[$result])) $where .= ' AND ' . $quick[$result];
        // período: pela data de conclusão (concluídas) ou pelo prazo (pendentes)
        $col = $status === 'done' ? 'DATE(v.finished_at)' : 'v.due_date';
        if ($from !== '') { $where .= " AND $col >= ?"; $params[] = $from; }
        if ($to !== '')   { $where .= " AND $col <= ?"; $params[] = $to; }

        $total = (int) Db::val('SELECT COUNT(*) FROM verifications v WHERE ' . $where, $params);
        $order = $status === 'done'
            ? 'COALESCE(v.finished_at, v.created_at) DESC, v.id DESC'
            : 'v.em_execucao DESC, v.due_date ASC, v.created_at ASC, v.id';
        $rows = Db::all('SELECT v.id, v.pppoe, v.due_date, v.created_at, v.started_at, v.finished_at, v.priority, v.shift, v.kind,
                                v.ligou, v.nao_atende, v.resolvido, v.os_criada, v.em_execucao, v.finalizado, v.obs, v.action_id,
                                bt.name AS batch_name, a.label AS action_label,
                                (v.finalizado = 0 AND v.due_date < CURDATE()) AS overdue,
                                (SELECT COUNT(*) FROM verification_events e WHERE e.verification_id = v.id) AS ev_count,
                                (SELECT COUNT(*) FROM verifications o WHERE o.pppoe = v.pppoe AND o.id <> v.id) AS others_count
                           FROM verifications v
                           JOIN batches bt ON bt.id = v.batch_id
                           LEFT JOIN actions a ON a.id = v.action_id
                          WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset, $params);

        $items = array_map(static fn($r) => [
            'id' => $r['id'], 'pppoe' => $r['pppoe'], 'lote' => $r['batch_name'], 'kind' => $r['kind'],
            'date' => $r['due_date'], 'createdAt' => $r['created_at'], 'startedAt' => $r['started_at'], 'finishedAt' => $r['finished_at'],
            'priority' => $r['priority'], 'shift' => $r['shift'],
            'finalizado' => (int) $r['finalizado'] === 1, 'emExecucao' => (int) $r['em_execucao'] === 1, 'overdue' => (int) $r['overdue'] === 1,
            'resolvido' => (int) $r['resolvido'] === 1, 'osCriada' => (int) $r['os_criada'] === 1,
            'naoAtende' => (int) $r['nao_atende'] === 1, 'ligou' => (int) $r['ligou'] === 1,
            'action' => $r['action_id'] === 'NONE' ? null : $r['action_label'],
            'obs' => Util::cut((string) ($r['obs'] ?? ''), 2000),
            'events' => (int) $r['ev_count'], 'othersCount' => (int) $r['others_count'],
        ], $rows);

        Http::json([
            'status' => $status, 'items' => $items, 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'hasMore' => $offset + count($items) < $total,
            'counts' => array_map('intval', $c),
        ]);
    }

    /** O técnico (ou administrador) edita a PRÓPRIA bio. */
    private static function saveBio(): void
    {
        $me = Auth::require();
        $b = Http::body(20 * 1024);
        $bio = is_string($b['bio'] ?? null) ? trim(str_replace("\r\n", "\n", $b['bio'])) : null;
        if ($bio === null) throw new HttpError(400, 'Bio inválida.');
        $bio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $bio) ?? '';
        if (Util::len($bio) > 500) throw new HttpError(400, 'A bio pode ter no máximo 500 caracteres.');
        Db::q('UPDATE users SET bio = ? WHERE id = ?', [$bio === '' ? null : $bio, $me['id']]);
        Util::audit($me['id'], 'USER_BIO', 'user', $me['id'], null, Util::clientIp());
        Http::json(['ok' => true, 'bio' => $bio]);
    }

    private const AVATAR_MAX_BYTES = 262144;   // 256 KB depois de redimensionada no navegador (normalmente ~20-40 KB)

    /** Cada usuário (técnico ou administrador) troca ou remove a PRÓPRIA foto. image = data URL (jpeg/png/webp) ou null p/ remover. */
    private static function saveAvatar(): void
    {
        $me = Auth::require();
        Http::rateLimit('avatar:' . $me['id'], 30, 3600, 'Muitas trocas de foto. Tente mais tarde.');
        $b = Http::body(600 * 1024);
        $img = $b['image'] ?? null;
        if ($img === null || $img === '') {
            Db::q('UPDATE users SET avatar = NULL, avatar_v = avatar_v + 1 WHERE id = ?', [$me['id']]);
            Util::audit($me['id'], 'USER_AVATAR_REMOVE', 'user', $me['id'], null, Util::clientIp());
            Http::json(['ok' => true, 'avatarV' => (int) Db::val('SELECT avatar_v FROM users WHERE id = ?', [$me['id']])]);
            return;
        }
        if (!is_string($img) || !preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=\r\n]+)$#', $img, $m)) {
            throw new HttpError(400, 'Imagem inválida. Use JPG, PNG ou WebP.');
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || $bin === '') throw new HttpError(400, 'Imagem inválida.');
        if (strlen($bin) > self::AVATAR_MAX_BYTES) throw new HttpError(413, 'Imagem grande demais (máx. 256 KB após o ajuste).');
        $info = @getimagesizefromstring($bin);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) throw new HttpError(400, 'O arquivo não é uma imagem válida.');
        if ($info[0] > 1024 || $info[1] > 1024) throw new HttpError(400, 'Imagem com dimensões grandes demais.');
        try {
            Db::q('UPDATE users SET avatar = UNHEX(?), avatar_v = avatar_v + 1 WHERE id = ?', [bin2hex($bin), $me['id']]);
        } catch (\Throwable $e) {
            // mostra o motivo real (ex.: coluna ausente = migration 006 não aplicada) em vez de "Erro interno"
            Util::log('avatar', get_class($e) . ': ' . $e->getMessage());
            throw new HttpError(500, 'Falha ao gravar a foto no banco: ' . mb_substr(Db::errmsg($e), 0, 160) . ' (se citar coluna "avatar", rode as migrations: php bin/rotina.php migrate)');
        }
        Util::audit($me['id'], 'USER_AVATAR', 'user', $me['id'], null, Util::clientIp());
        Http::json(['ok' => true, 'avatarV' => (int) Db::val('SELECT avatar_v FROM users WHERE id = ?', [$me['id']])]);
    }

    /** Entrega a foto (qualquer usuário logado). A versão (v) na URL permite cache longo no navegador. */
    private static function avatar(): void
    {
        Auth::require();
        $id = (string) ($_GET['id'] ?? '');
        if (!preg_match(Util::ID_RE, $id)) throw new HttpError(400, 'Usuário inválido.');
        $bin = Db::val("SELECT avatar FROM users WHERE id = ? AND status = 'APROVADO'", [$id]);
        if (!is_string($bin) || $bin === '') throw new HttpError(404, 'Sem foto.');
        $info = @getimagesizefromstring($bin);
        header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
        header('Cache-Control: private, max-age=604800');
        header('Content-Length: ' . strlen($bin));
        http_response_code(200);
        echo $bin;
    }

    // ---------------------------------------------------------------- PPPoE já verificados
    /**
     * ADM envia uma lista de logins (planilha) e recebe, só para os que já têm histórico:
     *  - a última verificação concluída (quem fez, quando, se resolveu, se abriu O.S., procedimento, observação);
     *  - quantas vezes já foi verificado;
     *  - se existe uma verificação EM ABERTO (nesse caso a atribuição move a que já existe, sem duplicar).
     */
    /** ADM: quem deixa e quem retoma verificações "pendentes de contato" (ranking dos últimos N dias) + situação atual. */
    private static function pendingStats(): void
    {
        self::admin();
        $days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
        $rows = Db::all("SELECT u.id, u.name, u.email,
                SUM(e.event = 'PENDENTE') AS left_n, SUM(e.event = 'RETOMADA') AS took_n
            FROM verification_events e JOIN users u ON u.id = e.user_id
            WHERE e.event IN ('PENDENTE', 'RETOMADA') AND e.created_at >= (NOW() - INTERVAL $days DAY)
            GROUP BY u.id, u.name, u.email ORDER BY left_n DESC, took_n DESC, u.name");
        $now = Db::one('SELECT COUNT(*) AS n, MIN(pending_since) AS oldest FROM verifications WHERE pending_contact = 1 AND finalizado = 0');
        Http::json([
            'days' => $days,
            'pending' => (int) ($now['n'] ?? 0),
            'oldest' => $now['oldest'] ?? null,
            'people' => array_map(static fn($r) => [
                'id' => $r['id'], 'name' => $r['name'], 'email' => $r['email'],
                'left' => (int) $r['left_n'], 'took' => (int) $r['took_n'],
            ], $rows),
        ]);
    }

    /**
     * Gráficos: séries agregadas NO BANCO (não dependem dos 120 dias que o navegador carrega, então "Ano" é real).
     *   ?range=WEEK (7 dias, por dia) | MONTH (30 dias, por dia) | YEAR (12 meses, por mês)
     * ADM recebe a equipe toda (+ por técnico e por turno); técnico recebe só os próprios números.
     */
    private static function analytics(): void
    {
        $me = Auth::require();
        $range = in_array($_GET['range'] ?? '', ['WEEK', 'MONTH', 'YEAR'], true) ? (string) $_GET['range'] : 'WEEK';
        $isAdm = $me['role'] === 'ADM';

        $keys = [];
        if ($range === 'YEAR') {
            $first = (new \DateTime('now', new \DateTimeZone(Config::timezone())))->modify('first day of this month');
            for ($i = 11; $i >= 0; $i--) $keys[] = (clone $first)->modify("-$i months")->format('Y-m');
            $from = $keys[0] . '-01';
            $keyExpr = "DATE_FORMAT(v.finished_at, '%Y-%m')";
        } else {
            $n = $range === 'MONTH' ? 30 : 7;
            for ($i = $n - 1; $i >= 0; $i--) $keys[] = Db::today(-$i);
            $from = $keys[0];
            $keyExpr = 'DATE(v.finished_at)';
        }
        $today = Db::today(0);
        $own = $isAdm ? '' : ' AND v.tech_id = ?';
        $args = $isAdm ? [$from] : [$from, $me['id']];
        // TMA só conta atendimentos com início/fim coerentes e até 8 h (os esquecidos abertos distorceriam)
        $ok = 'v.started_at IS NOT NULL AND v.finished_at >= v.started_at AND TIMESTAMPDIFF(SECOND, v.started_at, v.finished_at) <= ' . self::TMA_MAX_SECONDS;
        $agg = "COUNT(*) AS n, SUM(v.resolvido = 1 AND v.os_criada = 0) AS res, SUM(v.os_criada = 1) AS os,
                SUM(DATE(v.finished_at) <= v.due_date) AS ontime,
                AVG(CASE WHEN $ok THEN TIMESTAMPDIFF(SECOND, v.started_at, v.finished_at) END) AS tma, SUM($ok) AS tman";
        $base = "FROM verifications v WHERE v.finalizado = 1 AND v.finished_at >= ?$own";

        $byKey = [];
        foreach (Db::all("SELECT $keyExpr AS k, $agg $base GROUP BY k", $args) as $r) $byKey[$r['k']] = $r;
        $series = []; $tot = ['finished' => 0, 'resolved' => 0, 'os' => 0, 'onTime' => 0, 'tmaSum' => 0.0, 'tmaN' => 0];
        foreach ($keys as $k) {
            $r = $byKey[$k] ?? null;
            $n = $r ? (int) $r['n'] : 0; $res = $r ? (int) $r['res'] : 0; $os = $r ? (int) $r['os'] : 0;
            $on = $r ? (int) $r['ontime'] : 0; $tn = $r ? (int) $r['tman'] : 0;
            $tma = ($r && $r['tma'] !== null) ? (int) round((float) $r['tma']) : null;
            $series[] = ['key' => $k, 'finished' => $n, 'resolved' => $res, 'os' => $os, 'other' => max(0, $n - $res - $os), 'onTime' => $on, 'tmaSec' => $tma, 'tmaN' => $tn];
            $tot['finished'] += $n; $tot['resolved'] += $res; $tot['os'] += $os; $tot['onTime'] += $on;
            if ($tma !== null) { $tot['tmaSum'] += $tma * $tn; $tot['tmaN'] += $tn; }
        }

        $procs = [];
        foreach (Db::all("SELECT v.action_id AS a, COUNT(*) AS n $base GROUP BY v.action_id", $args) as $r) {
            if ($r['a'] !== 'NONE') $procs[] = ['id' => $r['a'], 'n' => (int) $r['n']];
        }

        $techs = []; $shifts = []; $assignedTotal = 0;
        if ($isAdm) {
            foreach (Db::all("SELECT u.id, u.name, $agg FROM verifications v JOIN users u ON u.id = v.tech_id
                               WHERE v.finalizado = 1 AND v.finished_at >= ? GROUP BY u.id, u.name ORDER BY n DESC, u.name", [$from]) as $r) {
                $n = (int) $r['n'];
                $techs[] = ['id' => $r['id'], 'name' => $r['name'], 'finished' => $n, 'resolved' => (int) $r['res'], 'os' => (int) $r['os'],
                            'other' => max(0, $n - (int) $r['res'] - (int) $r['os']), 'onTime' => (int) $r['ontime'],
                            'tmaSec' => $r['tma'] === null ? null : (int) round((float) $r['tma']), 'tmaN' => (int) $r['tman']];
            }
            $sh = [];
            foreach (Db::all('SELECT v.shift AS s, COUNT(*) AS n FROM verifications v WHERE v.due_date >= ? AND v.due_date <= ? GROUP BY v.shift', [$from, $today]) as $r) $sh[$r['s']]['assigned'] = (int) $r['n'];
            foreach (Db::all("SELECT v.shift AS s, COUNT(*) AS n $base GROUP BY v.shift", $args) as $r) $sh[$r['s']]['finished'] = (int) $r['n'];
            foreach (['MANHA', 'TARDE', 'NOITE', 'MADRUGADA', 'GERAL'] as $s) {
                if (!isset($sh[$s])) continue;
                $shifts[] = ['shift' => $s, 'assigned' => $sh[$s]['assigned'] ?? 0, 'finished' => $sh[$s]['finished'] ?? 0];
                $assignedTotal += $sh[$s]['assigned'] ?? 0;
            }
        }

        Http::json([
            'range' => $range, 'bucket' => $range === 'YEAR' ? 'month' : 'day', 'from' => $from, 'to' => $today,
            'series' => $series, 'procedures' => $procs, 'techs' => $techs, 'shifts' => $shifts,
            'totals' => [
                'finished' => $tot['finished'], 'resolved' => $tot['resolved'], 'os' => $tot['os'],
                'other' => max(0, $tot['finished'] - $tot['resolved'] - $tot['os']), 'onTime' => $tot['onTime'],
                'tmaSec' => $tot['tmaN'] ? (int) round($tot['tmaSum'] / $tot['tmaN']) : null, 'tmaN' => $tot['tmaN'],
                'assigned' => $assignedTotal,
            ],
        ]);
    }

    private static function pppoeCheck(): void
    {
        $user = Auth::require();
        Auth::requireRole($user, 'ADM');
        $b = Http::body(2 * 1024 * 1024);
        $in = $b['logins'] ?? null;
        if (!is_array($in) || count($in) > 20000) throw new HttpError(400, 'Envie a lista de logins (até 20.000).');
        $logins = [];
        foreach ($in as $l) {
            if (!is_string($l)) continue;
            $l = trim($l);
            if ($l !== '' && Util::len($l) <= 120) $logins[Util::lower($l)] = $l;
        }

        $items = [];
        foreach (array_chunk(array_values($logins), 1000) as $part) {
            $rows = Db::all('SELECT v.id, v.pppoe, v.finalizado, v.em_execucao, v.due_date, v.finished_at, v.created_at, v.resolvido, v.os_criada,
                                    v.nao_atende, v.ligou, v.action_id, v.obs, v.kind, u.name AS tech_name, bt.name AS batch_name, a.label AS action_label
                               FROM verifications v
                               JOIN users u ON u.id = v.tech_id
                               JOIN batches bt ON bt.id = v.batch_id
                               LEFT JOIN actions a ON a.id = v.action_id
                              WHERE v.pppoe IN (' . Db::marks($part) . ')
                              ORDER BY COALESCE(v.finished_at, v.created_at) DESC, v.created_at DESC', $part);
            foreach ($rows as $r) {
                $k = Util::lower($r['pppoe']);
                if (!isset($items[$k])) $items[$k] = ['login' => $r['pppoe'], 'total' => 0, 'open' => null, 'last' => null];
                if ((int) $r['finalizado'] === 0) {
                    $items[$k]['open'] = ['id' => $r['id'], 'tech' => $r['tech_name'], 'date' => $r['due_date'], 'lote' => $r['batch_name'],
                                          'running' => (int) $r['em_execucao'] === 1];
                    continue;
                }
                $items[$k]['total']++;
                if ($items[$k]['last'] === null) {          // 1ª linha = a concluída mais recente
                    $items[$k]['last'] = [
                        'id' => $r['id'], 'date' => $r['due_date'], 'finishedAt' => $r['finished_at'], 'tech' => $r['tech_name'], 'lote' => $r['batch_name'],
                        'resolvido' => (int) $r['resolvido'] === 1, 'osCriada' => (int) $r['os_criada'] === 1,
                        'naoAtende' => (int) $r['nao_atende'] === 1, 'ligou' => (int) $r['ligou'] === 1,
                        'action' => $r['action_id'] === 'NONE' ? null : $r['action_label'],
                        'obs' => Util::cut((string) ($r['obs'] ?? ''), 240), 'kind' => $r['kind'],
                    ];
                }
            }
        }
        Http::json(['items' => (object) $items, 'checked' => count($logins)]);
    }

    // ---------------------------------------------------------------- Auditoria de uma verificação
    /** Linha do tempo da verificação + as outras verificações do mesmo PPPoE (modal "Auditoria"). */
    private static function verificationAudit(): void
    {
        $user = Auth::require();
        $id = (string) ($_GET['id'] ?? '');
        if (!preg_match(Util::ID_RE, $id)) throw new HttpError(400, 'Verificação inválida.');

        $row = Db::one('SELECT ' . Mappers::VERIF_SELECT . ' WHERE v.id = ?', [$id]);
        if (!$row) throw new HttpError(404, 'Verificação não encontrada (talvez tenha sido excluída).');
        $v = Mappers::verif($row);
        // Todos os técnicos podem consultar a auditoria e as observações dos colegas (comparativo de soluções).

        // Auditoria ÚNICA por PPPoE: todos os acontecimentos de todas as verificações do mesmo login, em ordem cronológica.
        // Cada linha leva o id da verificação a que pertence ("vid"); a interface agrupa por rodada (1ª, 2ª, 3ª...).
        $events = Db::all('SELECT e.id, e.verification_id AS vid, e.event, e.details, e.created_at, u.name AS user_name, u.email AS user_email, u.role AS user_role
                             FROM verification_events e LEFT JOIN users u ON u.id = e.user_id
                            WHERE e.pppoe = ? ORDER BY e.created_at ASC, e.id ASC LIMIT 3000', [$v['pppoe']]);
        foreach ($events as &$e) {
            $e['id'] = (int) $e['id'];
            $e['details'] = $e['details'] !== null ? json_decode((string) $e['details'], true) : null;
        }
        unset($e);

        // Ordem cronológica REAL (data do acontecimento), não a ordem de gravação: o histórico retroativo da migration 004 foi
        // gravado em blocos (todas as criações, depois todos os inícios...), então ordenar por id numerava as rodadas errado.
        // Rodadas = cada verificação do PPPoE na ordem em que apareceu (a excluída continua aparecendo pelo histórico)
        $order = [];
        foreach ($events as $e) if (!in_array($e['vid'], $order, true)) $order[] = $e['vid'];
        if (!in_array($id, $order, true)) $order[] = $id;
        $rounds = [];
        foreach ($order as $i => $vid) $rounds[] = ['id' => $vid, 'n' => $i + 1, 'current' => $vid === $id];

        $prev = Db::all('SELECT v.id, v.finalizado, v.em_execucao, v.due_date, v.created_at, v.finished_at, v.resolvido, v.os_criada, v.nao_atende,
                                v.ligou, v.kind, v.obs, u.name AS tech_name, bt.name AS batch_name, a.label AS action_label, v.action_id
                           FROM verifications v
                           JOIN users u ON u.id = v.tech_id
                           JOIN batches bt ON bt.id = v.batch_id
                           LEFT JOIN actions a ON a.id = v.action_id
                          WHERE v.pppoe = ? AND v.id <> ?
                          ORDER BY COALESCE(v.finished_at, v.created_at) DESC, v.created_at DESC LIMIT 50', [$v['pppoe'], $id]);
        $others = array_map(static fn($r) => [
            'id' => $r['id'], 'finalizado' => (int) $r['finalizado'] === 1, 'emExecucao' => (int) $r['em_execucao'] === 1,
            'date' => $r['due_date'], 'createdAt' => $r['created_at'], 'finishedAt' => $r['finished_at'],
            'resolvido' => (int) $r['resolvido'] === 1, 'osCriada' => (int) $r['os_criada'] === 1,
            'naoAtende' => (int) $r['nao_atende'] === 1, 'ligou' => (int) $r['ligou'] === 1, 'kind' => $r['kind'],
            'tech' => $r['tech_name'], 'lote' => $r['batch_name'], 'action' => $r['action_id'] === 'NONE' ? null : $r['action_label'],
            'obs' => Util::cut((string) ($r['obs'] ?? ''), 2000),
        ], $prev);

        Http::json(['verification' => $v, 'events' => $events, 'rounds' => $rounds, 'others' => $others]);
    }

    /**
     * Consulta de soluções: verificações CONCLUÍDAS de toda a equipe, por login PPPoE ou por texto nas observações.
     * Serve para o técnico comparar como outros técnicos resolveram casos parecidos.
     */
    private static function verificationSearch(): void
    {
        $user = Auth::require();
        $q = trim((string) ($_GET['q'] ?? ''));
        if (Util::len($q) === 1) { Http::json(['items' => [], 'total' => 0, 'hint' => 'Digite ao menos 2 caracteres.']); return; }
        if (Util::len($q) > 120) throw new HttpError(400, 'Busca longa demais.');
        // Sem texto = navegar: lista as concluídas mais recentes da equipe (filtráveis por técnico).
        $where = 'v.finalizado = 1';
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where .= ' AND (v.pppoe LIKE ? OR v.obs LIKE ?)';
            $params = [$like, $like];
        }
        $techId = (string) ($_GET['tech'] ?? '');
        if ($techId !== '' && preg_match(Util::ID_RE, $techId)) { $where .= ' AND v.tech_id = ?'; $params[] = $techId; }
        if (($_GET['others'] ?? '') === '1') { $where .= ' AND v.tech_id <> ?'; $params[] = $user['id']; }
        if (($_GET['withObs'] ?? '') === '1') { $where .= " AND v.obs IS NOT NULL AND v.obs <> ''"; }
        $total = (int) Db::val('SELECT COUNT(*) FROM verifications v WHERE ' . $where, $params);
        $rows = Db::all('SELECT v.id, v.pppoe, v.finished_at, v.due_date, v.resolvido, v.os_criada, v.nao_atende, v.ligou, v.kind, v.obs, v.action_id,
                                u.name AS tech_name, u.email AS tech_email, bt.name AS batch_name, a.label AS action_label
                           FROM verifications v
                           JOIN users u ON u.id = v.tech_id
                           JOIN batches bt ON bt.id = v.batch_id
                           LEFT JOIN actions a ON a.id = v.action_id
                          WHERE ' . $where . '
                          ORDER BY COALESCE(v.finished_at, v.created_at) DESC LIMIT 60', $params);
        $items = array_map(static fn($r) => [
            'id' => $r['id'], 'pppoe' => $r['pppoe'], 'finishedAt' => $r['finished_at'], 'date' => $r['due_date'],
            'resolvido' => (int) $r['resolvido'] === 1, 'osCriada' => (int) $r['os_criada'] === 1,
            'naoAtende' => (int) $r['nao_atende'] === 1, 'ligou' => (int) $r['ligou'] === 1, 'kind' => $r['kind'],
            'tech' => $r['tech_name'], 'techEmail' => $r['tech_email'], 'lote' => $r['batch_name'],
            'action' => $r['action_id'] === 'NONE' ? null : $r['action_label'], 'obs' => (string) ($r['obs'] ?? ''),
        ], $rows);
        Http::json(['items' => $items, 'total' => $total]);
    }

    private static function exportCsv(): void
    {
        $user = Auth::require();
        Auth::requireRole($user, 'ADM');
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $from = preg_match($re, (string) ($_GET['from'] ?? '')) ? $_GET['from'] : Db::today(-30);
        $to = preg_match($re, (string) ($_GET['to'] ?? '')) ? $_GET['to'] : Db::today();
        $rows = Db::all('SELECT ' . Mappers::VERIF_SELECT . ' WHERE v.due_date BETWEEN ? AND ? ORDER BY v.due_date, v.pppoe', [$from, $to]);

        $esc = static function ($v): string {
            $s = $v === null ? '' : (string) $v;
            if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;     // anti injeção de fórmula (CSV/Excel)
            return '"' . str_replace('"', '""', $s) . '"';
        };
        $yn = static fn($b) => $b ? 'Sim' : 'Não';
        $lines = [implode(';', array_map($esc, ['PPPoE', 'Conjunto', 'Técnico', 'Turno', 'Prazo', 'Prioridade', 'Ligou', 'Não atende',
            'Resolvido', 'OS criada', 'Procedimento', 'Finalizado', 'Classe', 'Verificações anteriores', 'Observações']))];
        foreach ($rows as $row) {
            $r = Mappers::verif($row);
            $lines[] = implode(';', array_map($esc, [$r['pppoe'], $r['lote'], $r['techEmail'], $r['shift'], $r['date'], $r['priority'],
                $yn($r['ligou']), $yn($r['naoAtende']), $yn($r['resolvido']), $yn($r['osCriada']), $r['actionId'], $yn($r['finalizado']), $r['kind'] === 'REABERTA' ? 'Reaberta' : ($r['kind'] === 'ADICIONAL' ? 'Adicional' : 'Normal'), $r['prevCount'], $r['obs']]));
        }
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"verificacoes_{$from}_{$to}.csv\"");
        echo "\xEF\xBB\xBF" . implode("\r\n", $lines);                // BOM: o Excel abre com acentos corretos
    }

    private static function audit(): void
    {
        $user = Auth::require();
        Auth::requireRole($user, 'ADM');
        $limit = max(1, min((int) ($_GET['limit'] ?? 100) ?: 100, 500));
        $where = [];
        $vals = [];
        if (!empty($_GET['entity'])) { $where[] = 'a.entity = ?'; $vals[] = (string) $_GET['entity']; }
        if (!empty($_GET['entityId'])) { $where[] = 'a.entity_id = ?'; $vals[] = (string) $_GET['entityId']; }
        $rows = Db::all('SELECT a.id, a.event, a.entity, a.entity_id, a.details, a.ip, a.created_at, u.email AS user_email
                         FROM audit_log a LEFT JOIN users u ON u.id = a.user_id '
            . ($where ? 'WHERE ' . implode(' AND ', $where) . ' ' : '') . "ORDER BY a.id DESC LIMIT $limit", $vals);
        foreach ($rows as &$r) { if ($r['details'] !== null) $r['details'] = json_decode((string) $r['details'], true); }
        unset($r);
        Http::json(['items' => $rows]);
    }
}
